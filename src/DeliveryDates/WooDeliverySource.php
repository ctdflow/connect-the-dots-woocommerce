<?php
/**
 * Delivery dates from Delivery & Pickup Date Time for WooCommerce.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots\DeliveryDates;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the date, time slot and pickup choice that the plugin Delivery & Pickup Date Time for
 * WooCommerce (slug woo-delivery, main file woo-delivery/coderockz-woo-delivery.php) saves.
 *
 * Read from version 1.3.92. All values are order meta with unprefixed keys, saved through the
 * order object under HPOS and as post meta otherwise, by the classic checkout, the block checkout
 * and the admin order screen:
 *
 * - delivery_type: "delivery" or "pickup". Absent when the shop does not offer the choice, which
 *   means a delivery.
 * - delivery_date and pickup_date: Y-m-d. The classic checkout converts the displayed text to
 *   Y-m-d before saving, the block checkout sends the date picker value (flatpickr dateFormat
 *   Y-m-d). The display format of the plugin (its date_format setting, default "F j, Y") is only
 *   used on screen, so no display format needs normalising. Other notations are still parsed
 *   with strtotime() as a safety net.
 * - delivery_time and pickup_time: "H:i - H:i" in 24 hour notation, whatever the display clock.
 *   Anything else is no window.
 *
 * A pickup has no delivery date or window here (isPickup() is true). The data is order meta, not
 * item meta or a table. queryArgs() narrows on delivery_date with meta_key and meta_value, which
 * work in the legacy post storage and in HPOS. It cannot also exclude pickups (that needs a
 * meta_query, which only HPOS supports), so the caller must drop orders where isPickup() is true
 * or deliveryDate() differs from the date. A pickup normally only has pickup_date, so this
 * is rare.
 */
class WooDeliverySource implements DeliveryDateSource {

	/**
	 * The id used in the date_source setting.
	 */
	public function id(): string {
		return 'woo-delivery';
	}

	/**
	 * Whether the plugin is active.
	 */
	public function isAvailable(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'woo-delivery/coderockz-woo-delivery.php' );
	}

	/**
	 * The delivery date as Y-m-d, null for a pickup.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function deliveryDate( \WC_Order $order ): ?string {
		if ( $this->isPickup( $order ) ) {
			return null;
		}

		$date = trim( (string) $order->get_meta( 'delivery_date', true ) );

		if ( '' === $date ) {
			return null;
		}

		$exact = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

		if ( false !== $exact && $exact->format( 'Y-m-d' ) === $date ) {
			return $date;
		}

		$timestamp = strtotime( $date );

		// strtotime() reads some text that is no date as the start of 1970, which no delivery is on.
		if ( false === $timestamp || '1970-01-01' === gmdate( 'Y-m-d', $timestamp ) ) {
			return null;
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * The chosen delivery slot as a window, null for a pickup.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return array{from: string, to: string}|null
	 */
	public function timeWindow( \WC_Order $order ): ?array {
		if ( null === $this->deliveryDate( $order ) ) {
			return null;
		}

		$slot = trim( (string) $order->get_meta( 'delivery_time', true ) );

		return TimeWindow::parse( $slot );
	}

	/**
	 * Whether the customer chose pickup.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function isPickup( \WC_Order $order ): bool {
		return 'pickup' === $order->get_meta( 'delivery_type', true );
	}

	/**
	 * Orders with the delivery date. Pickups are not excluded, see the class docblock.
	 *
	 * @param string $date Y-m-d.
	 *
	 * @return array<string, mixed>
	 */
	public function queryArgs( string $date ): array {
		return array(
			'meta_key'   => 'delivery_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $date, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		);
	}
}
