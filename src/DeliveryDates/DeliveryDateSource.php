<?php
/**
 * Where the delivery date of an order comes from.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots\DeliveryDates;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the delivery date, time window and pickup choice of an order from one source.
 *
 * Dates are Y-m-d in the site timezone. Time windows are array( 'from' => 'H:i', 'to' => 'H:i' ).
 */
interface DeliveryDateSource {

	/**
	 * The id used in the date_source setting.
	 */
	public function id(): string;

	/**
	 * Whether the plugin behind this source is active.
	 */
	public function isAvailable(): bool;

	/**
	 * The delivery date of the order as Y-m-d, or null when it has none (or is a pickup).
	 *
	 * @param \WC_Order $order The order.
	 */
	public function deliveryDate( \WC_Order $order ): ?string;

	/**
	 * The chosen time window, or null when there is none.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return array{from: string, to: string}|null
	 */
	public function timeWindow( \WC_Order $order ): ?array;

	/**
	 * Whether the customer chose to pick the order up.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function isPickup( \WC_Order $order ): bool;

	/**
	 * Arguments to merge into a wc_get_orders call that narrow the orders to a delivery date.
	 *
	 * @param string $date Y-m-d.
	 *
	 * @return array<string, mixed>
	 */
	public function queryArgs( string $date ): array;
}
