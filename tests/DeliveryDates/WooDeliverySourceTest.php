<?php

namespace ConnectTheDots\Tests\DeliveryDates;

use ConnectTheDots\DeliveryDates\WooDeliverySource;
use ConnectTheDots\Tests\Support\TestCase;
use ConnectTheDots\Tests\Support\UsesOrderStorage;
use WC_Order;

class WooDeliverySourceTest extends TestCase {

	use UsesOrderStorage;

	private WooDeliverySource $source;

	public function set_up(): void {
		parent::set_up();

		$this->source = new WooDeliverySource();
	}

	public function test_it_has_the_setting_id(): void {
		$this->assertSame( 'woo-delivery', $this->source->id() );
	}

	public function test_it_is_available_when_the_plugin_is_active(): void {
		$this->assertFalse( $this->source->isAvailable() );

		add_filter( 'pre_option_active_plugins', static fn() => array( 'woo-delivery/coderockz-woo-delivery.php' ) );

		$this->assertTrue( $this->source->isAvailable() );
	}

	public function test_a_delivery_with_date_and_slot(): void {
		$order = $this->order( 'delivery with slot' );

		$this->assertSame( '2026-12-24', $this->source->deliveryDate( $order ) );
		$this->assertSame( array( 'from' => '09:00', 'to' => '12:30' ), $this->source->timeWindow( $order ) );
		$this->assertFalse( $this->source->isPickup( $order ) );
	}

	public function test_a_delivery_with_a_date_and_no_slot(): void {
		$order = $this->order( 'delivery without slot' );

		$this->assertSame( '2026-12-24', $this->source->deliveryDate( $order ) );
		$this->assertNull( $this->source->timeWindow( $order ) );
	}

	public function test_a_delivery_without_a_type_is_a_delivery(): void {
		$order = $this->order( 'delivery without type' );

		$this->assertSame( '2026-12-24', $this->source->deliveryDate( $order ) );
		$this->assertSame( array( 'from' => '13:00', 'to' => '14:00' ), $this->source->timeWindow( $order ) );
		$this->assertFalse( $this->source->isPickup( $order ) );
	}

	public function test_an_order_without_a_date(): void {
		$order = $this->order( 'no date' );

		$this->assertNull( $this->source->deliveryDate( $order ) );
		$this->assertNull( $this->source->timeWindow( $order ) );
		$this->assertNull( $this->source->deliveryDate( wc_create_order() ) );
	}

	public function test_a_pickup_has_no_delivery_date_or_window(): void {
		$order = $this->order( 'pickup' );

		$this->assertTrue( $this->source->isPickup( $order ) );
		$this->assertNull( $this->source->deliveryDate( $order ) );
		$this->assertNull( $this->source->timeWindow( $order ) );
	}

	public function test_a_date_in_another_notation_is_normalised(): void {
		$order = $this->orderWithMeta( array( 'delivery_date' => 'December 24, 2026' ) );

		$this->assertSame( '2026-12-24', $this->source->deliveryDate( $order ) );
		$this->assertNull( $this->source->deliveryDate( $this->orderWithMeta( array( 'delivery_date' => 'not a date' ) ) ) );
	}

	public function test_text_that_reads_as_the_start_of_1970_is_no_date(): void {
		$this->assertNull( $this->source->deliveryDate( $this->orderWithMeta( array( 'delivery_date' => '@0' ) ) ) );
		$this->assertNull( $this->source->deliveryDate( $this->orderWithMeta( array( 'delivery_date' => 'January 1, 1970' ) ) ) );
	}

	public function test_a_malformed_slot_is_no_window(): void {
		$order = $this->orderWithMeta( array( 'delivery_date' => '2026-12-24', 'delivery_time' => 'afternoon' ) );

		$this->assertNull( $this->source->timeWindow( $order ) );
	}

	public function test_slots_ending_at_midnight_and_inverted_slots(): void {
		$window = fn( string $slot ) => $this->source->timeWindow(
			$this->orderWithMeta( array( 'delivery_date' => '2026-12-24', 'delivery_time' => $slot ) )
		);

		$this->assertSame( array( 'from' => '23:00', 'to' => '23:59' ), $window( '23:00 - 00:00' ) );
		$this->assertSame( array( 'from' => '22:00', 'to' => '23:59' ), $window( '22:00 - 24:00' ) );
		$this->assertNull( $window( '14:00 - 09:00' ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_query_args_find_only_orders_with_that_date( bool $hpos ): void {
		$this->useHpos( $hpos );

		$onDate = $this->order( 'delivery with slot' );
		$noType = $this->order( 'delivery without type' );
		$other  = $this->orderWithMeta( array( 'delivery_date' => '2026-12-25' ) );
		$this->order( 'pickup' );
		$this->order( 'no date' );

		$ids = wc_get_orders( array_merge( array( 'return' => 'ids' ), $this->source->queryArgs( '2026-12-24' ) ) );
		sort( $ids );

		$this->assertSame( array( $onDate->get_id(), $noType->get_id() ), $ids );
		$this->assertSame(
			array( $other->get_id() ),
			wc_get_orders( array_merge( array( 'return' => 'ids' ), $this->source->queryArgs( '2026-12-25' ) ) )
		);
	}

	private function order( string $scenario ): WC_Order {
		$fixtures = require dirname( __DIR__ ) . '/Fixtures/woo-delivery.php';

		return $this->orderWithMeta( $fixtures[ $scenario ] );
	}
}
