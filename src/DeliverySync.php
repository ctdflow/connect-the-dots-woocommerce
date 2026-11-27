<?php
/**
 * Pulls the delivered check-offs of the drivers back onto the orders.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the current plans from yesterday up to the last planned day and stores when each stop was
 * delivered as order meta, or removes that time when the driver undid the check off.
 */
class DeliverySync {

	/**
	 * Name of the hourly event.
	 *
	 * @var string
	 */
	public const EVENT = 'ctdflow_sync_deliveries';

	/**
	 * Name of the transient that keeps the Routes tab from syncing on every view.
	 *
	 * @var string
	 */
	private string $throttleKey = 'ctdflow_delivery_sync_throttle';

	/**
	 * Seconds between two syncs that are started by viewing the Routes tab.
	 *
	 * @var int
	 */
	private int $throttleSeconds = 300;

	/**
	 * Creates the sync.
	 *
	 * @param Connection $connection Connection with Connect the Dots.
	 * @param ApiClient  $client     Client for the Connect the Dots API.
	 * @param PlanStore  $store      Remembers the plan of every day.
	 * @param Settings   $settings   The plugin settings.
	 */
	public function __construct(
		private Connection $connection,
		private ApiClient $client,
		private PlanStore $store,
		private Settings $settings
	) {
	}

	/**
	 * Builds the sync with the real connection and settings.
	 */
	public static function create(): DeliverySync {
		$connection = new Connection();

		return new DeliverySync( $connection, new ApiClient( $connection ), new PlanStore(), new Settings() );
	}

	/**
	 * Schedules the hourly event when it is not scheduled yet.
	 */
	public static function schedule(): void {
		if ( Plugin::canSchedule() && ! as_has_scheduled_action( self::EVENT, null, Plugin::SCHEDULE_GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::EVENT, array(), Plugin::SCHEDULE_GROUP, true );
		}
	}

	/**
	 * Removes the hourly event.
	 */
	public static function unschedule(): void {
		if ( Plugin::canSchedule() ) {
			as_unschedule_all_actions( self::EVENT );
		}
	}

	/**
	 * Runs the sync, at most once per five minutes. Used when the Routes tab loads.
	 */
	public function runThrottled(): void {
		if ( false !== get_transient( $this->throttleKey ) ) {
			return;
		}

		set_transient( $this->throttleKey, time(), $this->throttleSeconds );

		$this->run();
	}

	/**
	 * Stores the delivery time of every delivered stop in the current plans of yesterday and every later
	 * day, so a plan made for tomorrow is synced too. Does nothing when the shop is not connected.
	 * Failures end the run for that plan quietly.
	 */
	public function run(): void {
		if ( ! $this->connection->isConnected() ) {
			return;
		}

		$yesterday = ( new \DateTimeImmutable( 'yesterday', wp_timezone() ) )->format( 'Y-m-d' );

		foreach ( $this->store->currentFrom( $yesterday ) as $plan_id ) {
			try {
				$plan = $this->client->get( '/v1/woocommerce/plans/' . rawurlencode( $plan_id ) );
			} catch ( ApiError $error ) {
				// plan_not_found: the plan expired. Other errors: try again at the next run.
				continue;
			}

			// RoutesPage keeps the stored plan current. A superseded plan holds routes that were replaced.
			if ( ! empty( $plan['superseded_by'] ) ) {
				continue;
			}

			$this->storeDeliveries( $plan );
		}
	}

	/**
	 * Writes the delivery times of a plan onto its orders.
	 *
	 * @param array $plan The plan.
	 */
	private function storeDeliveries( array $plan ): void {
		foreach ( (array) ( $plan['routes'] ?? array() ) as $route ) {
			foreach ( (array) ( $route['stops'] ?? array() ) as $stop ) {
				$order = wc_get_order( (int) ( $stop['reference'] ?? 0 ) );

				if ( ! $order instanceof \WC_Order ) {
					continue;
				}

				$plan_id   = (string) ( $plan['plan_id'] ?? '' );
				$delivered = $stop['delivered_at'] ?? null;
				$time      = is_string( $delivered ) && '' !== $delivered ? strtotime( $delivered ) : false;

				if ( false === $time ) {
					$this->forgetDelivery( $order, $plan_id );
					continue;
				}

				$this->storeDelivery( $order, $plan_id, $delivered, $time );
			}
		}
	}

	/**
	 * Stores the delivery time of one order and adds the note, both only the first time.
	 *
	 * @param \WC_Order $order     The order.
	 * @param string    $planId    Id of the plan the stop belongs to.
	 * @param string    $delivered ISO 8601 time of delivery.
	 * @param int       $time      The same time as a timestamp.
	 */
	private function storeDelivery( \WC_Order $order, string $planId, string $delivered, int $time ): void {
		// Only an order that carries this plan: its route may have been replaced or left out since.
		if ( '' === $planId || (string) $order->get_meta( '_ctdflow_plan_id', true ) !== $planId ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( '_ctdflow_delivered_at', true ) ) {
			return;
		}

		$order->update_meta_data( '_ctdflow_delivered_at', $delivered );
		$order->save();

		if ( $this->settings->addOrderNote() ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: time of delivery as H:i. */
					__( 'Delivered at %s', 'connect-the-dots' ),
					wp_date( 'H:i', $time )
				)
			);
		}
	}

	/**
	 * Removes the delivery time of an order whose driver undid the check off, and adds a note.
	 *
	 * @param \WC_Order $order  The order.
	 * @param string    $planId Id of the plan the stop belongs to.
	 */
	private function forgetDelivery( \WC_Order $order, string $planId ): void {
		if ( '' === $planId || (string) $order->get_meta( '_ctdflow_plan_id', true ) !== $planId ) {
			return;
		}

		if ( '' === (string) $order->get_meta( '_ctdflow_delivered_at', true ) ) {
			return;
		}

		$order->delete_meta_data( '_ctdflow_delivered_at' );
		$order->save();

		if ( $this->settings->addOrderNote() ) {
			$order->add_order_note( __( 'The driver undid the delivery', 'connect-the-dots' ) );
		}
	}
}
