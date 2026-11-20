<?php

namespace ConnectTheDots\Tests\DeliveryDates;

use ConnectTheDots\DeliveryDates\OrderDeliveryDateSource;
use ConnectTheDots\Tests\Support\TestCase;
use ConnectTheDots\Tests\Support\UsesOrderStorage;
use WC_Order;

class OrderDeliveryDateSourceTest extends TestCase {

	use UsesOrderStorage;

	private OrderDeliveryDateSource $source;

	public function set_up(): void {
		parent::set_up();

		$this->source = new OrderDeliveryDateSource();
		delete_option( 'orddd_lite_delivery_date_format' );
		delete_option( 'orddd_lite_delivery_date_field_label' );
	}

	public function test_it_has_the_setting_id(): void {
		$this->assertSame( 'order-delivery-date', $this->source->id() );
	}

	public function test_it_is_available_when_the_free_plugin_is_active(): void {
		$this->assertFalse( $this->source->isAvailable() );

		$this->activate( 'order-delivery-date-for-woocommerce/order_delivery_date.php' );

		$this->assertTrue( $this->source->isAvailable() );
	}

	public function test_the_pro_plugin_does_not_count(): void {
		$this->activate( 'order-delivery-date/order_delivery_date.php' );

		$this->assertFalse( $this->source->isAvailable() );
	}

	public function test_an_order_with_date_and_slot(): void {
		$order = $this->order( 'date and slot' );

		$this->assertSame( '2026-12-24', $this->source->deliveryDate( $order ) );
		$this->assertSame( array( 'from' => '09:00', 'to' => '12:00' ), $this->source->timeWindow( $order ) );
		$this->assertFalse( $this->source->isPickup( $order ) );
	}

	public function test_an_order_with_a_date_and_no_slot(): void {
		$order = $this->order( 'date without slot' );

		$this->assertSame( '2026-12-24', $this->source->deliveryDate( $order ) );
		$this->assertNull( $this->source->timeWindow( $order ) );
	}

	public function test_as_soon_as_possible_and_a_slot_without_end_are_no_window(): void {
		$this->assertNull( $this->source->timeWindow( $this->order( 'as soon as possible' ) ) );
		$this->assertNull( $this->source->timeWindow( $this->order( 'slot without end' ) ) );
	}

	public function test_slots_ending_at_midnight_and_inverted_slots(): void {
		$window = fn( string $slot ) => $this->source->timeWindow(
			$this->orderWithMeta(
				array(
					'_orddd_lite_timestamp' => gmmktime( 0, 0, 0, 12, 24, 2026 ),
					'_orddd_time_slot'      => $slot,
				)
			)
		);

		$this->assertSame( array( 'from' => '23:00', 'to' => '23:59' ), $window( '23:00 - 00:00' ) );
		$this->assertSame( array( 'from' => '22:00', 'to' => '23:59' ), $window( '22:00 - 24:00' ) );
		$this->assertNull( $window( '14:00 - 09:00' ) );
	}

	public function test_an_order_without_a_date(): void {
		$order = $this->order( 'no date' );

		$this->assertNull( $this->source->deliveryDate( $order ) );
		$this->assertNull( $this->source->timeWindow( $order ) );
		$this->assertNull( $this->source->deliveryDate( wc_create_order() ) );
	}

	public function test_the_timestamp_is_read_at_midnight_utc_whatever_the_site_timezone(): void {
		update_option( 'timezone_string', 'Pacific/Auckland' );

		$this->assertSame( '2026-12-24', $this->source->deliveryDate( $this->order( 'date and slot' ) ) );

		update_option( 'timezone_string', 'America/Los_Angeles' );

		$this->assertSame( '2026-12-24', $this->source->deliveryDate( $this->order( 'date and slot' ) ) );
	}

	/**
	 * @dataProvider displayFormats
	 */
	public function test_the_display_text_is_normalised_when_there_is_no_timestamp( string $format, string $text ): void {
		update_option( 'orddd_lite_delivery_date_format', $format );
		$order = $this->orderWithMeta( array( 'Delivery Date' => $text ) );

		$this->assertSame( '2026-12-24', $this->source->deliveryDate( $order ) );
	}

	public static function displayFormats(): array {
		return array(
			'default'                  => array( 'd MM, yy', '24 December, 2026' ),
			'mm/dd/y'                  => array( 'mm/dd/y', '12/24/26' ),
			'dd/mm/y'                  => array( 'dd/mm/y', '24/12/26' ),
			'y/mm/dd'                  => array( 'y/mm/dd', '26/12/24' ),
			'dd.mm.y'                  => array( 'dd.mm.y', '24.12.26' ),
			'y.mm.dd'                  => array( 'y.mm.dd', '26.12.24' ),
			'yy-mm-dd'                 => array( 'yy-mm-dd', '2026-12-24' ),
			'dd-mm-y'                  => array( 'dd-mm-y', '24-12-26' ),
			'dd-mm-yy'                 => array( 'dd-mm-yy', '24-12-2026' ),
			'd M, y'                   => array( 'd M, y', '24 Dec, 26' ),
			'd M, yy'                  => array( 'd M, yy', '24 Dec, 2026' ),
			'd MM, y'                  => array( 'd MM, y', '24 December, 26' ),
			'DD, d MM, yy'             => array( 'DD, d MM, yy', 'Thursday, 24 December, 2026' ),
			'D, M d, yy'               => array( 'D, M d, yy', 'Thu, Dec 24, 2026' ),
			'DD, M d, yy'              => array( 'DD, M d, yy', 'Thursday, Dec 24, 2026' ),
			'DD, MM d, yy'             => array( 'DD, MM d, yy', 'Thursday, December 24, 2026' ),
			'D, MM d, yy'              => array( 'D, MM d, yy', 'Thu, December 24, 2026' ),
		);
	}

	public function test_text_that_does_not_match_the_format_is_no_date(): void {
		update_option( 'orddd_lite_delivery_date_format', 'dd-mm-yy' );

		$this->assertNull( $this->source->deliveryDate( $this->orderWithMeta( array( 'Delivery Date' => 'Eerste kerstdag' ) ) ) );
	}

	public function test_a_renamed_field_label_is_followed(): void {
		update_option( 'orddd_lite_delivery_date_field_label', 'Bezorgdatum' );
		update_option( 'orddd_lite_delivery_date_format', 'dd-mm-yy' );

		$this->assertSame( '2026-12-24', $this->source->deliveryDate( $this->orderWithMeta( array( 'Bezorgdatum' => '24-12-2026' ) ) ) );
	}

	public function test_there_is_no_pickup(): void {
		$this->assertFalse( $this->source->isPickup( $this->order( 'date and slot' ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_query_args_find_only_orders_on_that_date( bool $hpos ): void {
		$this->useHpos( $hpos );

		$onDate    = $this->order( 'date and slot' );
		$otherDate = $this->orderWithMeta( array( '_orddd_lite_timestamp' => gmmktime( 0, 0, 0, 12, 25, 2026 ) ) );
		$this->order( 'no date' );

		$this->assertSame(
			array( $onDate->get_id() ),
			wc_get_orders( array_merge( array( 'return' => 'ids' ), $this->source->queryArgs( '2026-12-24' ) ) )
		);
		$this->assertSame(
			array( $otherDate->get_id() ),
			wc_get_orders( array_merge( array( 'return' => 'ids' ), $this->source->queryArgs( '2026-12-25' ) ) )
		);
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_query_args_match_a_timestamp_later_in_the_day( bool $hpos ): void {
		$this->useHpos( $hpos );

		$inside  = $this->orderWithMeta( array( '_orddd_lite_timestamp' => gmmktime( 23, 59, 59, 12, 24, 2026 ) ) );
		$outside = $this->orderWithMeta( array( '_orddd_lite_timestamp' => gmmktime( 0, 0, 0, 12, 25, 2026 ) ) );

		$ids = wc_get_orders( array_merge( array( 'return' => 'ids' ), $this->source->queryArgs( '2026-12-24' ) ) );

		$this->assertSame( array( $inside->get_id() ), $ids );
		$this->assertNotContains( $outside->get_id(), $ids );
	}

	private function order( string $scenario ): WC_Order {
		$fixtures = require dirname( __DIR__ ) . '/Fixtures/order-delivery-date.php';

		return $this->orderWithMeta( $fixtures[ $scenario ] );
	}

	private function activate( string $plugin ): void {
		add_filter(
			'pre_option_active_plugins',
			static fn() => array( $plugin )
		);
	}
}
