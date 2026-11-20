<?php

namespace ConnectTheDots\Tests;

use ConnectTheDots\MissingTimezone;
use ConnectTheDots\OrderSelection;
use ConnectTheDots\PlanRequestBuilder;
use ConnectTheDots\SelectedOrder;
use ConnectTheDots\Settings;
use ConnectTheDots\Tests\Support\TestCase;
use ConnectTheDots\Vehicle;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Simple;

class PlanRequestBuilderTest extends TestCase {

	private PlanRequestBuilder $builder;

	public function set_up(): void {
		parent::set_up();

		$this->builder = new PlanRequestBuilder( new Settings() );
		$this->settings( array() );
		update_option( 'timezone_string', 'Europe/Amsterdam' );
	}

	public function test_the_body_has_exactly_the_allowed_keys(): void {
		$body = $this->builder->build(
			'2026-12-24',
			array( $this->selected( array( 'from' => '09:00', 'to' => '12:00' ) ) ),
			array( new Vehicle( 'a', 'Van A', '08:00', '17:00', 20 ) ),
			'manual'
		);

		$this->assertSame(
			array( 'site_url', 'delivery_date', 'timezone', 'country', 'trigger', 'depot', 'stops', 'vehicles' ),
			array_keys( $body )
		);
		$this->assertSame( array( 'address' ), array_keys( $body['depot'] ) );
		$this->assertSame(
			array( 'reference', 'address', 'time_window', 'quantity', 'service_minutes' ),
			array_keys( $body['stops'][0] )
		);
		$this->assertSame( array( 'from', 'to' ), array_keys( $body['stops'][0]['time_window'] ) );
		$this->assertSame(
			array( 'name', 'available_from', 'available_until', 'capacity' ),
			array_keys( $body['vehicles'][0] )
		);
	}

	public function test_the_values_of_the_request(): void {
		$this->settings( array( 'stop_minutes' => 5 ) );
		$selected = $this->selected( array( 'from' => '09:00', 'to' => '12:00' ), 4 );

		$body = $this->builder->build( '2026-12-24', array( $selected ), array( new Vehicle( 'a', 'Van A', '08:00', '17:00', 20 ) ), 'scheduled' );

		$this->assertSame( home_url(), $body['site_url'] );
		$this->assertSame( '2026-12-24', $body['delivery_date'] );
		$this->assertSame( 'Europe/Amsterdam', $body['timezone'] );
		$this->assertSame( 'NL', $body['country'] );
		$this->assertSame( 'scheduled', $body['trigger'] );
		$this->assertSame( 'Depotstraat 1, 1011 AB Amsterdam', $body['depot']['address'] );
		$this->assertSame( (string) $selected->order->get_id(), $body['stops'][0]['reference'] );
		$this->assertSame( 'Kerkstraat 10, 3511 AB Utrecht', $body['stops'][0]['address'] );
		$this->assertSame( array( 'from' => '09:00', 'to' => '12:00' ), $body['stops'][0]['time_window'] );
		$this->assertSame( 4, $body['stops'][0]['quantity'] );
		$this->assertSame( 5, $body['stops'][0]['service_minutes'] );
		$this->assertSame(
			array( 'name' => 'Van A', 'available_from' => '08:00', 'available_until' => '17:00', 'capacity' => 20 ),
			$body['vehicles'][0]
		);
	}

	public function test_a_stop_without_time_window_has_no_time_window_key(): void {
		$body = $this->builder->build( '2026-12-24', array( $this->selected( null ) ), array( new Vehicle( 'a', 'Van A' ) ), 'manual' );

		$this->assertArrayNotHasKey( 'time_window', $body['stops'][0] );
	}

	public function test_a_van_without_capacity_sends_null(): void {
		$body = $this->builder->build( '2026-12-24', array( $this->selected( null ) ), array( new Vehicle( 'a', 'Van A' ) ), 'manual' );

		$this->assertNull( $body['vehicles'][0]['capacity'] );
	}

	public function test_inactive_vans_are_left_out(): void {
		$body = $this->builder->build(
			'2026-12-24',
			array( $this->selected( null ) ),
			array( new Vehicle( 'a', 'Van A' ), new Vehicle( 'b', 'Van B', '08:00', '17:00', null, false ) ),
			'manual'
		);

		$this->assertSame( array( 'Van A' ), array_column( $body['vehicles'], 'name' ) );
	}

	public function test_end_address_is_only_sent_when_it_differs_from_the_depot(): void {
		$vehicles = array( new Vehicle( 'a', 'Van A' ) );
		$body     = $this->builder->build( '2026-12-24', array( $this->selected( null ) ), $vehicles, 'manual' );

		$this->assertArrayNotHasKey( 'end_address', $body['vehicles'][0] );

		$this->settings( array( 'end' => array( 'street' => 'Depotstraat 1', 'postcode' => '1011 AB', 'city' => 'Amsterdam', 'country' => 'NL' ) ) );
		$body = $this->builder->build( '2026-12-24', array( $this->selected( null ) ), $vehicles, 'manual' );

		$this->assertArrayNotHasKey( 'end_address', $body['vehicles'][0] );

		$this->settings( array( 'end' => array( 'street' => 'Thuisweg 2', 'postcode' => '3511 AB', 'city' => 'Utrecht', 'country' => 'NL' ) ) );
		$body = $this->builder->build( '2026-12-24', array( $this->selected( null ) ), $vehicles, 'manual' );

		$this->assertSame( 'Thuisweg 2, 3511 AB Utrecht', $body['vehicles'][0]['end_address'] );
		$this->assertSame(
			array( 'name', 'available_from', 'available_until', 'capacity', 'end_address' ),
			array_keys( $body['vehicles'][0] )
		);
	}

	public function test_the_country_is_not_part_of_the_address(): void {
		$selected = $this->selected( null, 1, array( 'street' => 'Rue 5', 'postcode' => '1000', 'city' => 'Bruxelles', 'country' => 'BE' ) );

		$body = $this->builder->build( '2026-12-24', array( $selected ), array( new Vehicle( 'a', 'Van A' ) ), 'manual' );

		$this->assertSame( 'Rue 5, 1000 Bruxelles', $body['stops'][0]['address'] );
	}

	public function test_an_empty_postcode_leaves_no_stray_space(): void {
		$selected = $this->selected( null, 1, array( 'street' => 'Dorpsstraat 3', 'postcode' => '', 'city' => 'Zwolle', 'country' => 'NL' ) );

		$body = $this->builder->build( '2026-12-24', array( $selected ), array( new Vehicle( 'a', 'Van A' ) ), 'manual' );

		$this->assertSame( 'Dorpsstraat 3, Zwolle', $body['stops'][0]['address'] );
	}

	public function test_an_offset_timezone_is_refused(): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 1 );

		$this->assertFalse( PlanRequestBuilder::hasTimezone() );
		$this->expectException( MissingTimezone::class );

		$this->builder->build( '2026-12-24', array( $this->selected( null ) ), array( new Vehicle( 'a', 'Van A' ) ), 'manual' );
	}

	public function test_an_unknown_trigger_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->builder->build( '2026-12-24', array( $this->selected( null ) ), array( new Vehicle( 'a', 'Van A' ) ), 'cron' );
	}

	public function test_no_personal_data_or_order_details_are_sent(): void {
		update_option( 'woocommerce_default_country', 'NL' );

		$product = new WC_Product_Simple();
		$product->set_name( 'Zebrastreep Wijnkoeler' );
		$product->set_regular_price( '7654.32' );
		$product->save();

		$order = wc_create_order();

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$order->{"set_{$type}_first_name"}( 'Zacharias' );
			$order->{"set_{$type}_last_name"}( 'Quackenbush' );
			$order->{"set_{$type}_company"}( 'Walrusfabriek BV' );
			$order->{"set_{$type}_address_1"}( 'Kerkstraat 10' );
			$order->{"set_{$type}_address_2"}( 'Walrusfabriek BV' );
			$order->{"set_{$type}_postcode"}( '3511 AB' );
			$order->{"set_{$type}_city"}( 'Utrecht' );
			$order->{"set_{$type}_country"}( 'NL' );
		}

		$order->set_billing_email( 'zacharias.quackenbush@example.org' );
		$order->set_billing_phone( '+31 6 98765432' );
		$order->update_meta_data( '_orddd_lite_timestamp', strtotime( '2026-12-24 00:00:00 UTC' ) );

		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_total( '7654.32' );
		$order->add_item( $item );
		$order->set_total( '7654.32' );
		$order->set_status( 'processing' );
		$order->save();

		update_option( Settings::OPTION, array( 'date_source' => 'order-delivery-date' ) );
		$settings = new Settings();
		$selected = ( new OrderSelection( $settings ) )->forDay( '2026-12-24' )['plannable'];

		$this->assertCount( 1, $selected );

		$body = ( new PlanRequestBuilder( $settings ) )->build( '2026-12-24', $selected, array( new Vehicle( 'a', 'Van A' ) ), 'manual' );
		$json = wp_json_encode( $body );

		foreach ( array( 'Zacharias', 'Quackenbush', 'Walrusfabriek', 'zacharias.quackenbush', 'example.org', '98765432', 'Zebrastreep', 'Wijnkoeler', '7654.32', '7654,32' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json, "Leaked: {$secret}" );
		}

		$this->assertSame( 'Kerkstraat 10, 3511 AB Utrecht', $body['stops'][0]['address'] );
	}

	public function test_a_house_number_addition_is_kept(): void {
		update_option( 'woocommerce_default_country', 'NL' );
		$order = wc_create_order();
		$order->set_billing_address_1( 'Kerkstraat 10' );
		$order->set_billing_address_2( '2-hoog' );
		$order->set_billing_postcode( '3511 AB' );
		$order->set_billing_city( 'Utrecht' );
		$order->set_billing_country( 'NL' );
		$order->update_meta_data( '_orddd_lite_timestamp', strtotime( '2026-12-24 00:00:00 UTC' ) );
		$order->set_status( 'processing' );
		$order->save();

		update_option( Settings::OPTION, array( 'date_source' => 'order-delivery-date' ) );
		$settings = new Settings();
		$selected = ( new OrderSelection( $settings ) )->forDay( '2026-12-24' )['plannable'];

		$body = ( new PlanRequestBuilder( $settings ) )->build( '2026-12-24', $selected, array( new Vehicle( 'a', 'Van A' ) ), 'manual' );

		$this->assertSame( 'Kerkstraat 10 2-hoog, 3511 AB Utrecht', $body['stops'][0]['address'] );
	}

	/**
	 * @param array{from: string, to: string}|null $window  Time window.
	 * @param int                                  $quantity Quantity.
	 * @param array<string, string>|null           $address Address, defaults to Kerkstraat 10 in Utrecht.
	 */
	private function selected( ?array $window, int $quantity = 1, ?array $address = null ): SelectedOrder {
		$order = wc_create_order();
		$order->save();

		return new SelectedOrder(
			$order,
			$address ?? array( 'street' => 'Kerkstraat 10', 'postcode' => '3511 AB', 'city' => 'Utrecht', 'country' => 'NL' ),
			$window,
			$quantity,
			null
		);
	}

	private function settings( array $extra ): void {
		update_option(
			Settings::OPTION,
			array_merge( array( 'depot' => array( 'street' => 'Depotstraat 1', 'postcode' => '1011 AB', 'city' => 'Amsterdam', 'country' => 'NL' ) ), $extra )
		);
	}
}
