<?php
/**
 * Sends the orders of a delivery day to Connect the Dots.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * Checks that a plan can be made, sends it and remembers its id as the pending plan of the day.
 */
class Planner {

	/**
	 * Creates the planner.
	 *
	 * @param Settings           $settings  The plugin settings.
	 * @param ApiClient          $client    Client for the Connect the Dots API.
	 * @param PlanStore          $store     Remembers the plan of every day.
	 * @param OrderSelection     $selection Selects the orders of a day.
	 * @param PlanRequestBuilder $builder   Builds the request body.
	 */
	public function __construct(
		private Settings $settings,
		private ApiClient $client,
		private PlanStore $store,
		private OrderSelection $selection,
		private PlanRequestBuilder $builder
	) {
	}

	/**
	 * Builds the planner with the real settings and connection.
	 */
	public static function create(): Planner {
		$settings = new Settings();

		return new Planner( $settings, new ApiClient(), new PlanStore(), new OrderSelection( $settings ), new PlanRequestBuilder( $settings ) );
	}

	/**
	 * Plans the chosen orders of a day over the chosen vans and stores the plan id for the day.
	 *
	 * The stops are sent in the order of $orderIds. Orders that cannot be planned (no address,
	 * unsupported country, wrong status) and vans that are inactive are left out. Nothing is sent when no order or no van is left, when the depot has no
	 * address or country, or when the site has no city timezone.
	 *
	 * @param string   $date       Delivery date as Y-m-d.
	 * @param int[]    $orderIds   Orders to plan.
	 * @param string[] $vehicleIds Vans to plan with.
	 * @param string   $trigger    Either manual or scheduled.
	 * @param string[]|null $supportedCountries Countries the account accepts, null to accept every country.
	 *
	 * @return array The 202 answer of POST /v1/woocommerce/plans.
	 *
	 * @throws PlanningNotPossible When the plan cannot be sent.
	 * @throws ApiError When Connect the Dots refuses the plan or cannot be reached.
	 */
	public function plan( string $date, array $orderIds, array $vehicleIds, string $trigger, ?array $supportedCountries = null ): array {
		$day    = $this->selection->forDay( $date, array_map( 'intval', $orderIds ), array(), $supportedCountries );
		$orders = $this->chosen( $day['plannable'], $orderIds );

		if ( array() === $orders ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped where it is shown.
			throw new PlanningNotPossible( __( 'There are no orders to plan. Choose at least one order with a delivery address.', 'connect-the-dots' ) );
		}

		$vehicles = array_values(
			array_filter(
				$this->settings->vehicles(),
				static fn( Vehicle $vehicle ): bool => $vehicle->active && in_array( $vehicle->id, $vehicleIds, true )
			)
		);

		if ( array() === $vehicles ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped where it is shown.
			throw new PlanningNotPossible( __( 'Choose at least one active van. Vans are set up on the Settings tab.', 'connect-the-dots' ) );
		}

		$depot = $this->settings->depotAddress();

		if ( '' === trim( $depot['street'] ) || '' === trim( $depot['country'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped where it is shown.
			throw new PlanningNotPossible( __( 'The depot has no address or country. Fill in the depot on the Settings tab.', 'connect-the-dots' ) );
		}

		try {
			$body = $this->builder->build( $date, $orders, $vehicles, $trigger );
		} catch ( MissingTimezone $missing ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped where it is shown.
			throw new PlanningNotPossible( $missing->getMessage(), 0, $missing );
		}

		$result = $this->client->post( '/v1/woocommerce/plans', $body );

		if ( isset( $result['plan_id'] ) && is_string( $result['plan_id'] ) && '' !== $result['plan_id'] ) {
			$this->store->putPending( $date, $result['plan_id'] );
			$this->store->putSeenOrders(
				$date,
				array_map( static fn( SelectedOrder $selected ): int => $selected->order->get_id(), array_merge( $day['plannable'], $day['unplannable'] ) )
			);
		}

		Account::forget( $date );

		return $result;
	}

	/**
	 * The ids of the first orders to plan within a stop limit: by start of the time window, orders
	 * without a window last, then by order number.
	 *
	 * @param string $date     Delivery date as Y-m-d.
	 * @param int[]  $orderIds Orders to choose from.
	 * @param int    $count    Number of orders to keep.
	 *
	 * @return int[]
	 */
	public function firstOrders( string $date, array $orderIds, int $count ): array {
		$orders = $this->plannable( $date, $orderIds );

		usort(
			$orders,
			static function ( SelectedOrder $a, SelectedOrder $b ): int {
				$by_window = strcmp( $a->timeWindow['from'] ?? '99:99', $b->timeWindow['from'] ?? '99:99' );

				return 0 !== $by_window ? $by_window : strnatcmp( (string) $a->order->get_order_number(), (string) $b->order->get_order_number() );
			}
		);

		return array_map(
			static fn( SelectedOrder $selected ): int => $selected->order->get_id(),
			array_slice( $orders, 0, max( 0, $count ) )
		);
	}

	/**
	 * The chosen orders that can be planned, in the order they were chosen.
	 *
	 * @param string $date     Delivery date as Y-m-d.
	 * @param int[]  $orderIds Chosen orders.
	 * @param string[]|null $supportedCountries Countries the account accepts, null to accept every country.
	 *
	 * @return SelectedOrder[]
	 */
	private function plannable( string $date, array $orderIds, ?array $supportedCountries = null ): array {
		return $this->chosen( $this->selection->forDay( $date, array_map( 'intval', $orderIds ), array(), $supportedCountries )['plannable'], $orderIds );
	}

	/**
	 * The chosen orders among the plannable orders of a day, in the order they were chosen.
	 *
	 * @param SelectedOrder[] $plannable The plannable orders of the day.
	 * @param int[]           $orderIds  Orders to choose.
	 *
	 * @return SelectedOrder[]
	 */
	private function chosen( array $plannable, array $orderIds ): array {
		$ids = array_map( 'intval', $orderIds );

		if ( array() === $ids ) {
			return array();
		}

		$position = array_flip( array_values( array_unique( $ids ) ) );
		$orders   = array_values(
			array_filter(
				$plannable,
				static fn( SelectedOrder $selected ): bool => isset( $position[ $selected->order->get_id() ] )
			)
		);

		usort(
			$orders,
			static fn( SelectedOrder $a, SelectedOrder $b ): int => $position[ $a->order->get_id() ] <=> $position[ $b->order->get_id() ]
		);

		return $orders;
	}
}
