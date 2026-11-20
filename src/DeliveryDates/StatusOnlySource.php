<?php
/**
 * The source for shops without a delivery date plugin.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots\DeliveryDates;

defined( 'ABSPATH' ) || exit;

/**
 * Knows no dates: orders are selected by status alone and the date comes from the planning date.
 */
class StatusOnlySource implements DeliveryDateSource {

	/**
	 * Days back that orders are looked for, so a shop that plans a status such as Completed does not
	 * load its whole history.
	 *
	 * @var int
	 */
	private int $maxAgeDays = 60;

	/**
	 * The id used in the date_source setting.
	 */
	public function id(): string {
		return 'status';
	}

	/**
	 * Always available, it needs no plugin.
	 */
	public function isAvailable(): bool {
		return true;
	}

	/**
	 * Never has a date.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function deliveryDate( \WC_Order $order ): ?string {
		return null;
	}

	/**
	 * Never has a window.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return null
	 */
	public function timeWindow( \WC_Order $order ): ?array {
		return null;
	}

	/**
	 * Never a pickup.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function isPickup( \WC_Order $order ): bool {
		return false;
	}

	/**
	 * Only orders created in the last 60 days. Orders already planned for the day are found apart from this.
	 *
	 * @param string $date Y-m-d.
	 *
	 * @return array<string, mixed>
	 */
	public function queryArgs( string $date ): array {
		return array( 'date_created' => '>=' . ( time() - $this->maxAgeDays * DAY_IN_SECONDS ) );
	}
}
