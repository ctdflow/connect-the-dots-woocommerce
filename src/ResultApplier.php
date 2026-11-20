<?php
/**
 * Writes a finished plan back onto the orders.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the route of every planned order as order meta, adds the note and sets the status when the
 * shop turned those on. A plan is applied once: a plan that was applied is skipped, and a short lock
 * keeps two requests (a page view and a scheduled action) from applying it at the same time. Within a
 * plan, an order is applied once. A new plan keeps _ctdflow_delivered_at when
 * CTD carried the delivery over or leaves the order out, and a delivered order keeps the route it was
 * delivered on. Only an order the planner put on the road again loses its old delivery.
 */
class ResultApplier {

	/**
	 * Order meta that describes the route of an order.
	 *
	 * @var string[]
	 */
	private array $routeKeys = array( '_ctdflow_plan_id', '_ctdflow_plan_date', '_ctdflow_vehicle', '_ctdflow_position', '_ctdflow_expected_at', '_ctdflow_applied_plan_id' );

	/**
	 * Seconds after which the lock of a plan that was not released is taken over.
	 *
	 * @var int
	 */
	private int $lockSeconds = 120;

	/**
	 * Creates the applier.
	 *
	 * @param Settings  $settings The plugin settings.
	 * @param PlanStore $store    Remembers which plan of a day was applied.
	 */
	public function __construct( private Settings $settings, private PlanStore $store = new PlanStore() ) {
	}

	/**
	 * Applies a succeeded plan, the JSON of GET /v1/woocommerce/plans/{id}. Other plans, and plans that
	 * a newer plan superseded, are ignored.
	 *
	 * @param array $plan The plan.
	 */
	public function apply( array $plan ): void {
		$plan_id = (string) ( $plan['plan_id'] ?? '' );
		$date    = (string) ( $plan['delivery_date'] ?? '' );

		// A superseded plan is never applied: it would overwrite the routes of the newer plan.
		if ( 'succeeded' !== ( $plan['status'] ?? '' ) || '' === $plan_id || ! empty( $plan['superseded_by'] ) || $this->wasApplied( $date, $plan_id ) ) {
			return;
		}

		$lock = new OptionLock( 'ctdflow_apply_lock_' . md5( $plan_id ), $this->lockSeconds );

		if ( ! $lock->acquire() ) {
			return;
		}

		try {
			if ( ! $this->wasApplied( $date, $plan_id ) ) {
				$this->applyRoutes( $plan, $plan_id, $date );
			}
		} finally {
			$lock->release();
		}
	}

	/**
	 * Whether this plan was the last plan applied to the day.
	 *
	 * @param string $date   Delivery date as Y-m-d.
	 * @param string $planId Plan id.
	 */
	private function wasApplied( string $date, string $planId ): bool {
		return '' !== $date && $this->store->applied( $date ) === $planId;
	}

	/**
	 * Writes the routes of the plan onto the orders and remembers that the plan was applied.
	 *
	 * @param array  $plan   The plan.
	 * @param string $planId Plan id.
	 * @param string $date   Delivery date as Y-m-d.
	 */
	private function applyRoutes( array $plan, string $planId, string $date ): void {
		$stop_ids = array();

		foreach ( (array) ( $plan['routes'] ?? array() ) as $route ) {
			$vehicle = (string) ( $route['vehicle'] ?? '' );

			foreach ( (array) ( $route['stops'] ?? array() ) as $stop ) {
				$stop_ids[] = (int) ( $stop['reference'] ?? 0 );
				$order      = wc_get_order( (int) ( $stop['reference'] ?? 0 ) );

				if ( $order instanceof \WC_Order ) {
					$this->applyStop( $order, $planId, $date, $vehicle, $stop );
				}
			}
		}

		foreach ( (array) ( $plan['unplanned'] ?? array() ) as $unplanned ) {
			$order = wc_get_order( (int) ( $unplanned['reference'] ?? 0 ) );

			if ( $order instanceof \WC_Order ) {
				$this->forgetSupersededRoute( $order, $planId, $date );
			}
		}

		$this->forgetDroppedOrders( $planId, $date, $stop_ids );

		if ( '' !== $date ) {
			$this->store->markApplied( $date, $planId );
		}
	}

	/**
	 * Removes the route of orders that an older plan of the same day placed and the new plan does not
	 * contain at all, for instance because the owner left them out when planning again.
	 *
	 * @param string $planId  Id of the new plan.
	 * @param string $date    Delivery date as Y-m-d.
	 * @param int[]  $stopIds Order ids that are a stop in the new plan.
	 */
	private function forgetDroppedOrders( string $planId, string $date, array $stopIds ): void {
		if ( '' === $date ) {
			return;
		}

		// meta_key and meta_value work in both order storages, a meta_query does not. Orders are
		// loaded one at a time because wc_get_orders ignores an include argument.
		$ids = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'limit'      => -1,
				'return'     => 'ids',
				'meta_key'   => '_ctdflow_plan_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $date, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		foreach ( $ids as $id ) {
			if ( in_array( (int) $id, $stopIds, true ) ) {
				continue;
			}

			$order = wc_get_order( (int) $id );

			if ( $order instanceof \WC_Order ) {
				$this->forgetSupersededRoute( $order, $planId, $date );
			}
		}
	}

	/**
	 * Writes one stop onto its order, unless this plan was applied to the order already.
	 *
	 * @param \WC_Order $order   The order.
	 * @param string    $planId  Plan id.
	 * @param string    $date    Delivery date as Y-m-d.
	 * @param string    $vehicle Name of the van.
	 * @param array     $stop    The stop of the plan.
	 */
	private function applyStop( \WC_Order $order, string $planId, string $date, string $vehicle, array $stop ): void {
		if ( $order->get_meta( '_ctdflow_applied_plan_id', true ) === $planId ) {
			return;
		}

		$expected = self::expectedAt( $stop );
		$position = (int) ( $stop['position'] ?? 0 );
		$moved    = (string) $order->get_meta( '_ctdflow_vehicle', true ) !== $vehicle
			|| (string) $order->get_meta( '_ctdflow_position', true ) !== (string) $position
			|| (string) $order->get_meta( '_ctdflow_expected_at', true ) !== $expected;

		$order->update_meta_data( '_ctdflow_plan_id', $planId );
		$order->update_meta_data( '_ctdflow_plan_date', $date );
		$order->update_meta_data( '_ctdflow_vehicle', $vehicle );
		$order->update_meta_data( '_ctdflow_position', $position );
		$order->update_meta_data( '_ctdflow_expected_at', $expected );
		$order->update_meta_data( '_ctdflow_applied_plan_id', $planId );

		// An order that is on the road again is no longer delivered, unless CTD carried its delivery over.
		if ( empty( $stop['delivered_at'] ) ) {
			$order->delete_meta_data( '_ctdflow_delivered_at' );
		}

		$order->save();

		// A plan of the day made again often keeps an order where it was. The note is only added when it changed.
		if ( $moved && $this->settings->addOrderNote() ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: name of the van, 2: expected arrival time as H:i. */
					__( 'Delivery route %1$s, expected arrival %2$s', 'connect-the-dots' ),
					$vehicle,
					self::localTime( $expected )
				)
			);
		}

		$status = $this->settings->statusAfterPlanning();

		if ( null !== $status && 'wc-' . $order->get_status() !== $status ) {
			$order->update_status( $status );
		}
	}

	/**
	 * Removes the route of an order that an older plan of the same day placed, so the order no longer
	 * shows a route that was replaced. A delivered order keeps the route it was delivered on.
	 *
	 * @param \WC_Order $order  The order.
	 * @param string    $planId Id of the newer plan.
	 * @param string    $date   Delivery date as Y-m-d.
	 */
	private function forgetSupersededRoute( \WC_Order $order, string $planId, string $date ): void {
		$old = (string) $order->get_meta( '_ctdflow_plan_id', true );

		if ( '' === $old || $old === $planId || (string) $order->get_meta( '_ctdflow_plan_date', true ) !== $date ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( '_ctdflow_delivered_at', true ) ) {
			return;
		}

		foreach ( $this->routeKeys as $key ) {
			$order->delete_meta_data( $key );
		}

		$order->save();
	}

	/**
	 * When the delivery at a stop is expected: the time the van starts the stop, after waiting for the
	 * time window, not the moment it arrives. Falls back on the arrival for a plan without it.
	 *
	 * @param array $stop A stop of the plan.
	 */
	public static function expectedAt( array $stop ): string {
		return (string) ( $stop['expected_at'] ?? $stop['arrival'] ?? '' );
	}

	/**
	 * The H:i of an ISO 8601 time, in the offset it was given in (the timezone of the plan).
	 *
	 * @param string $iso Time such as 2026-12-24T08:12:00+01:00.
	 */
	public static function localTime( string $iso ): string {
		try {
			return ( new \DateTimeImmutable( $iso ) )->format( 'H:i' );
		} catch ( \Exception $invalid ) {
			return '';
		}
	}
}
