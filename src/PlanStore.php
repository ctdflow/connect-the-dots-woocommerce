<?php
/**
 * Remembers the plans of every delivery day.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps, per delivery date and for the last 30 days, three plan ids: the current plan in the option
 * ctdflow_plans, which is the last plan that succeeded and whose routes apply, the pending plan in the
 * option ctdflow_pending_plans, which was sent and did not succeed yet, and the plan that was last
 * written onto the orders in the option ctdflow_applied_plans. A pending plan only becomes the current
 * plan once it succeeded, so a failed plan never hides routes that still apply. Beside the plans it
 * keeps the orders the planner could choose from, so a later run can tell which orders are new.
 */
class PlanStore {

	/**
	 * Name of the option with the current plans.
	 *
	 * @var string
	 */
	private string $currentOption = 'ctdflow_plans';

	/**
	 * Name of the option with the pending plans.
	 *
	 * @var string
	 */
	private string $pendingOption = 'ctdflow_pending_plans';

	/**
	 * Name of the option with the plans that were written onto the orders.
	 *
	 * @var string
	 */
	private string $appliedOption = 'ctdflow_applied_plans';

	/**
	 * Name of the option with the orders that could be planned when a day was planned, by date.
	 *
	 * @var string
	 */
	private string $seenOrdersOption = 'ctdflow_seen_orders';

	/**
	 * Number of days before today that plans are kept for.
	 *
	 * @var int
	 */
	private int $keepDays = 30;

	/**
	 * Stores the current plan of a day, replacing the previous one, and forgets days that are too old.
	 *
	 * @param string $date   Delivery date as Y-m-d.
	 * @param string $planId Plan id from Connect the Dots.
	 */
	public function put( string $date, string $planId ): void {
		$this->write( $this->currentOption, $date, $planId );
	}

	/**
	 * The current plan id of a day, null when the day has no plan that succeeded in the last 30 days.
	 *
	 * @param string $date Delivery date as Y-m-d.
	 */
	public function get( string $date ): ?string {
		return $this->read( $this->currentOption, $date );
	}

	/**
	 * Stores a plan that was just sent for a day, replacing the previous pending plan.
	 *
	 * @param string $date   Delivery date as Y-m-d.
	 * @param string $planId Plan id from Connect the Dots.
	 */
	public function putPending( string $date, string $planId ): void {
		$this->write( $this->pendingOption, $date, $planId );
	}

	/**
	 * The pending plan id of a day, null when there is none.
	 *
	 * @param string $date Delivery date as Y-m-d.
	 */
	public function pending( string $date ): ?string {
		return $this->read( $this->pendingOption, $date );
	}

	/**
	 * Forgets the pending plan of a day.
	 *
	 * @param string $date Delivery date as Y-m-d.
	 */
	public function forgetPending( string $date ): void {
		$plans = $this->all( $this->pendingOption );

		unset( $plans[ $date ] );

		update_option( $this->pendingOption, $plans, false );
	}

	/**
	 * Remembers that a plan of a day was written onto the orders.
	 *
	 * @param string $date   Delivery date as Y-m-d.
	 * @param string $planId Plan id from Connect the Dots.
	 */
	public function markApplied( string $date, string $planId ): void {
		$this->write( $this->appliedOption, $date, $planId );
	}

	/**
	 * The plan id of a day that was last written onto the orders, null when there is none.
	 *
	 * @param string $date Delivery date as Y-m-d.
	 */
	public function applied( string $date ): ?string {
		return $this->read( $this->appliedOption, $date );
	}

	/**
	 * Remembers the orders of a day that the planner could choose from when the day was planned, both
	 * the ones that were sent and the ones that were left out on purpose.
	 *
	 * @param string $date     Delivery date as Y-m-d.
	 * @param int[]  $orderIds Order ids.
	 */
	public function putSeenOrders( string $date, array $orderIds ): void {
		$seen          = $this->withoutOldDays( $this->allSeenOrders() );
		$seen[ $date ] = array_values( array_unique( array_map( 'intval', $orderIds ) ) );

		ksort( $seen );

		update_option( $this->seenOrdersOption, $seen, false );
	}

	/**
	 * The orders of a day that could be chosen when the day was last planned, null when that is not known.
	 *
	 * @param string $date Delivery date as Y-m-d.
	 *
	 * @return int[]|null
	 */
	public function seenOrders( string $date ): ?array {
		$seen = $this->allSeenOrders();

		return isset( $seen[ $date ] ) ? $seen[ $date ] : null;
	}

	/**
	 * The stored seen orders, date to order ids.
	 *
	 * @return array<string, int[]>
	 */
	private function allSeenOrders(): array {
		$seen = get_option( $this->seenOrdersOption, array() );

		if ( ! is_array( $seen ) ) {
			return array();
		}

		return array_map( static fn( $ids ): array => array_map( 'intval', (array) $ids ), $seen );
	}

	/**
	 * The current plans of a day and every later day, date to plan id, by date.
	 *
	 * @param string $date First delivery date as Y-m-d.
	 *
	 * @return array<string, string>
	 */
	public function currentFrom( string $date ): array {
		$plans = array_filter(
			$this->all( $this->currentOption ),
			static fn( string $planId, string $day ): bool => $day >= $date && '' !== $planId,
			ARRAY_FILTER_USE_BOTH
		);

		ksort( $plans );

		return $plans;
	}

	/**
	 * Stores a plan id for a day in an option and forgets days that are too old.
	 *
	 * @param string $option Name of the option.
	 * @param string $date   Delivery date as Y-m-d.
	 * @param string $planId Plan id from Connect the Dots.
	 */
	private function write( string $option, string $date, string $planId ): void {
		$plans          = $this->all( $option );
		$plans[ $date ] = $planId;
		$plans          = $this->withoutOldDays( $plans );

		ksort( $plans );

		update_option( $option, $plans, false );
	}

	/**
	 * Leaves out the days more than 30 days before today.
	 *
	 * @param array<string, mixed> $days Values by date as Y-m-d.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutOldDays( array $days ): array {
		$oldest = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . $this->keepDays . ' days' )->format( 'Y-m-d' );

		return array_filter(
			$days,
			static fn( $day ): bool => (string) $day >= $oldest,
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * The plan id of a day in an option.
	 *
	 * @param string $option Name of the option.
	 * @param string $date   Delivery date as Y-m-d.
	 */
	private function read( string $option, string $date ): ?string {
		$plans = $this->all( $option );

		return isset( $plans[ $date ] ) && '' !== $plans[ $date ] ? $plans[ $date ] : null;
	}

	/**
	 * The stored plans of an option, date to plan id.
	 *
	 * @param string $option Name of the option.
	 *
	 * @return array<string, string>
	 */
	private function all( string $option ): array {
		$plans = get_option( $option, array() );

		return is_array( $plans ) ? array_map( 'strval', $plans ) : array();
	}
}
