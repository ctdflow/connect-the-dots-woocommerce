<?php
/**
 * Delivery dates from Order Delivery Date for WooCommerce.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots\DeliveryDates;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the delivery date and time slot that the free plugin Order Delivery Date for WooCommerce
 * (Lite version, main file order-delivery-date-for-woocommerce/order_delivery_date.php) saves.
 * The Pro plugin has another path and is not supported.
 *
 * Read from version 4.6.3. Meta keys on the order, saved through the order object under HPOS and
 * as post meta otherwise:
 *
 * - _orddd_lite_timestamp: the date as a Unix timestamp at 00:00 UTC (mktime while WordPress
 *   keeps PHP on UTC). This is what the plugin itself sorts, locks out and reads back, so it is
 *   what this source trusts and what queryArgs() filters on.
 * - The key set by the option orddd_lite_delivery_date_field_label (default "Delivery Date"): the
 *   date as text in the display format of the option orddd_lite_delivery_date_format (default
 *   "d MM, yy"). Only used when the timestamp is missing. All 17 formats the plugin offers are
 *   normalised (see $formats). Month and day names must be English; a localised text gives null.
 * - _orddd_time_slot: "H:i - H:i" for the 12 hour clock, the selected text for the 24 hour clock
 *   (also "H:i - H:i"), "As Soon As Possible." for ASAP, or "H:i" alone for a slot without an
 *   end. Only "H:i - H:i" is a time window, anything else is null.
 *
 * The plugin has no pickup option, so isPickup() is always false. The date and slot are order
 * meta, not item meta or a table, so queryArgs() is an exact meta filter. Orders that only have
 * the display text and no timestamp are not found by queryArgs() (the plugin always saves both).
 */
class OrderDeliveryDateSource implements DeliveryDateSource {

	/**
	 * The jQuery UI date formats of the plugin mapped to PHP date formats (orddd-lite-config.php).
	 *
	 * @var array<string, string>
	 */
	private array $formats = array(
		'mm/dd/y'      => 'm/d/y',
		'dd/mm/y'      => 'd/m/y',
		'y/mm/dd'      => 'y/m/d',
		'dd.mm.y'      => 'd.m.y',
		'y.mm.dd'      => 'y.m.d',
		'yy-mm-dd'     => 'Y-m-d',
		'dd-mm-y'      => 'd-m-y',
		'dd-mm-yy'     => 'd-m-Y',
		'd M, y'       => 'j M, y',
		'd M, yy'      => 'j M, Y',
		'd MM, y'      => 'j F, y',
		'd MM, yy'     => 'j F, Y',
		'DD, d MM, yy' => 'l, j F, Y',
		'D, M d, yy'   => 'D, M j, Y',
		'DD, M d, yy'  => 'l, M j, Y',
		'DD, MM d, yy' => 'l, F j, Y',
		'D, MM d, yy'  => 'D, F j, Y',
	);

	/**
	 * The id used in the date_source setting.
	 */
	public function id(): string {
		return 'order-delivery-date';
	}

	/**
	 * Whether the free plugin is active.
	 */
	public function isAvailable(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'order-delivery-date-for-woocommerce/order_delivery_date.php' );
	}

	/**
	 * The delivery date as Y-m-d.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function deliveryDate( \WC_Order $order ): ?string {
		$timestamp = $order->get_meta( '_orddd_lite_timestamp', true );

		if ( is_numeric( $timestamp ) && (int) $timestamp > 0 ) {
			return gmdate( 'Y-m-d', (int) $timestamp );
		}

		return $this->dateFromText( $order );
	}

	/**
	 * The chosen time slot as a window.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return array{from: string, to: string}|null
	 */
	public function timeWindow( \WC_Order $order ): ?array {
		if ( null === $this->deliveryDate( $order ) ) {
			return null;
		}

		$slot = trim( (string) $order->get_meta( '_orddd_time_slot', true ) );

		return TimeWindow::parse( $slot );
	}

	/**
	 * The plugin has no pickup.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function isPickup( \WC_Order $order ): bool {
		return false;
	}

	/**
	 * Orders whose timestamp falls on the date. Uses meta_key and meta_value, which work in the
	 * legacy post storage and in HPOS, unlike meta_query which only HPOS supports.
	 *
	 * @param string $date Y-m-d.
	 *
	 * @return array<string, mixed>
	 */
	public function queryArgs( string $date ): array {
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );

		if ( false === $start ) {
			return array( 'include' => array( 0 ) );
		}

		return array(
			'meta_key'     => '_orddd_lite_timestamp', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'   => array( $start->getTimestamp(), $start->getTimestamp() + DAY_IN_SECONDS - 1 ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_compare' => 'BETWEEN',
			'meta_type'    => 'NUMERIC',
		);
	}

	/**
	 * Parses the date text in the display format of the plugin.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function dateFromText( \WC_Order $order ): ?string {
		$label = (string) get_option( 'orddd_lite_delivery_date_field_label', '' );
		$text  = trim( (string) $order->get_meta( '' !== $label ? $label : 'Delivery Date', true ) );

		if ( '' === $text ) {
			return null;
		}

		$setting = (string) get_option( 'orddd_lite_delivery_date_format', 'd MM, yy' );
		$format  = $this->formats[ $setting ] ?? $this->formats['d MM, yy'];
		$parsed  = \DateTimeImmutable::createFromFormat( '!' . $format, $text, new \DateTimeZone( 'UTC' ) );

		$errors = \DateTimeImmutable::getLastErrors();

		if ( false === $parsed || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}

		return $parsed->format( 'Y-m-d' );
	}
}
