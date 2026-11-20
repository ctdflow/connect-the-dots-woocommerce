<?php

namespace ConnectTheDots\Tests;

use ConnectTheDots\OrderSelection;
use ConnectTheDots\SelectedOrder;
use ConnectTheDots\Settings;
use ConnectTheDots\Tests\Support\TestCase;
use ConnectTheDots\Tests\Support\UsesOrderStorage;
use WC_Order;
use WC_Order_Item_Shipping;

class OrderSelectionTest extends TestCase {

	use UsesOrderStorage;

	private string $day = '2026-12-24';

	private OrderSelection $selection;

	public function set_up(): void {
		parent::set_up();

		update_option( 'woocommerce_default_country', 'NL' );
		$this->selection = new OrderSelection( new Settings() );
		$this->settings( array( 'date_source' => 'order-delivery-date' ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_only_orders_with_a_status_from_the_settings_are_selected( bool $hpos ): void {
		$this->useHpos( $hpos );
		$processing = $this->order();
		$this->order( array(), 'on-hold' );

		$this->assertSame( array( $processing->get_id() ), $this->plannableIds( $this->selection->forDay( $this->day ) ) );

		$this->settings( array( 'date_source' => 'order-delivery-date', 'statuses' => array( 'wc-on-hold', 'wc-processing' ) ) );

		$this->assertCount( 2, $this->selection->forDay( $this->day )['plannable'] );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_orders_delivered_on_the_plan_of_this_day_are_left_out_and_counted( bool $hpos ): void {
		$this->useHpos( $hpos );
		$open = $this->order();
		$this->order( array( '_ctdflow_delivered_at' => '2026-12-24T09:05:00+01:00', '_ctdflow_plan_date' => $this->day ) );

		$selection = $this->selection->forDay( $this->day );

		$this->assertSame( array( $open->get_id() ), $this->plannableIds( $selection ) );
		$this->assertSame( array(), $selection['unplannable'] );
		$this->assertSame( 1, $selection['delivered'] );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_an_order_delivered_on_another_day_can_be_planned_again( bool $hpos ): void {
		$this->useHpos( $hpos );
		$delivered = $this->order( array( '_ctdflow_delivered_at' => '2026-12-20T09:05:00+01:00', '_ctdflow_plan_date' => '2026-12-20' ) );

		$selection = $this->selection->forDay( $this->day );

		$this->assertSame( array( $delivered->get_id() ), $this->plannableIds( $selection ) );
		$this->assertSame( 0, $selection['delivered'] );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_a_delivered_order_added_by_hand_is_planned( bool $hpos ): void {
		$this->useHpos( $hpos );
		$added = $this->order( array( '_ctdflow_delivered_at' => '2026-12-24T09:15:00+01:00', '_ctdflow_plan_date' => $this->day ) );

		$selection = $this->selection->forDay( $this->day, array( $added->get_id() ) );

		$this->assertSame( array( $added->get_id() ), $this->plannableIds( $selection ) );
		$this->assertSame( 0, $selection['delivered'] );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_pickup_shipping_methods_are_left_out( bool $hpos ): void {
		$this->useHpos( $hpos );
		$delivery = $this->order( array(), 'processing', 'flat_rate' );
		$this->order( array(), 'processing', 'local_pickup' );
		$this->order( array(), 'processing', 'pickup_location' );

		$this->assertSame( array( $delivery->get_id() ), $this->plannableIds( $this->selection->forDay( $this->day ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_an_order_with_only_virtual_products_is_left_out( bool $hpos ): void {
		$this->useHpos( $hpos );
		$virtual = $this->order();
		$virtual->add_product( $this->product( true ), 1 );
		$virtual->save();
		$mixed = $this->order();
		$mixed->add_product( $this->product( true ), 1 );
		$mixed->add_product( $this->product(), 1 );
		$mixed->save();

		$this->assertSame( array( $mixed->get_id() ), $this->plannableIds( $this->selection->forDay( $this->day ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_an_order_whose_product_was_deleted_is_still_delivered( bool $hpos ): void {
		$this->useHpos( $hpos );
		$product = $this->product( true );
		$order   = $this->order();
		$order->add_product( $product, 1 );
		$order->save();
		$product->delete( true );

		$this->assertSame( array( $order->get_id() ), $this->plannableIds( $this->selection->forDay( $this->day ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_only_orders_with_a_chosen_shipping_method_are_selected( bool $hpos ): void {
		$this->useHpos( $hpos );
		$this->settings( array( 'date_source' => 'order-delivery-date', 'shipping_methods' => array( 'flat_rate:3', 'free_shipping:5' ) ) );
		$flat_rate = $this->withShipping( $this->order(), 'flat_rate', 3 );
		$free      = $this->withShipping( $this->order(), 'free_shipping', 5 );
		$this->withShipping( $this->order(), 'flat_rate', 4 );
		$this->order();

		$this->assertSame( array( $flat_rate->get_id(), $free->get_id() ), $this->plannableIds( $this->selection->forDay( $this->day ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_without_chosen_shipping_methods_every_method_is_selected( bool $hpos ): void {
		$this->useHpos( $hpos );
		$flat_rate = $this->withShipping( $this->order(), 'flat_rate', 3 );
		$none      = $this->order();

		$this->assertSame( array( $flat_rate->get_id(), $none->get_id() ), $this->plannableIds( $this->selection->forDay( $this->day ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_a_pickup_of_the_date_plugin_is_left_out( bool $hpos ): void {
		$this->useHpos( $hpos );
		$this->settings( array( 'date_source' => 'woo-delivery' ) );
		$delivery = $this->placed( array( 'delivery_type' => 'delivery', 'delivery_date' => $this->day ) + $this->addressMeta() );
		$this->placed( array( 'delivery_type' => 'pickup', 'delivery_date' => $this->day ) + $this->addressMeta() );

		$this->assertSame( array( $delivery->get_id() ), $this->plannableIds( $this->selection->forDay( $this->day ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_with_a_date_source_only_orders_of_that_date_are_selected( bool $hpos ): void {
		$this->useHpos( $hpos );
		$today = $this->order();
		$this->order( array( '_orddd_lite_timestamp' => strtotime( '2026-12-25 00:00:00 UTC' ) ) );
		$this->order( array( '_orddd_lite_timestamp' => '' ) );

		$this->assertSame( array( $today->get_id() ), $this->plannableIds( $this->selection->forDay( $this->day ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_status_only_drops_orders_planned_for_a_later_day( bool $hpos ): void {
		$this->useHpos( $hpos );
		$this->settings( array( 'date_source' => 'status' ) );
		$unplanned = $this->placed( $this->addressMeta() );
		$same_day  = $this->placed( $this->addressMeta() + array( '_ctdflow_plan_date' => $this->day ) );
		$this->placed( $this->addressMeta() + array( '_ctdflow_plan_date' => '2026-12-25' ) );

		$this->assertSame(
			array( $unplanned->get_id(), $same_day->get_id() ),
			$this->plannableIds( $this->selection->forDay( $this->day ) )
		);
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_status_only_brings_back_an_order_of_a_day_before_today_that_was_not_delivered( bool $hpos ): void {
		$this->useHpos( $hpos );
		$this->settings( array( 'date_source' => 'status' ) );
		$undelivered = $this->placed( $this->addressMeta() + array( '_ctdflow_plan_date' => $this->localDay( 'yesterday' ) ) );

		$this->assertSame( array( $undelivered->get_id() ), $this->plannableIds( $this->selection->forDay( $this->localDay( 'tomorrow' ) ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_status_only_keeps_an_order_planned_for_today_out_of_tomorrow_while_its_route_may_still_drive( bool $hpos ): void {
		$this->useHpos( $hpos );
		update_option( 'timezone_string', 'Pacific/Kiritimati' );
		$this->settings( array( 'date_source' => 'status' ) );
		$this->placed( $this->addressMeta() + array( '_ctdflow_plan_date' => $this->localDay( 'today' ) ) );

		$this->assertSame( array(), $this->plannableIds( $this->selection->forDay( $this->localDay( 'tomorrow' ) ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_status_only_keeps_an_order_delivered_on_an_earlier_day_out( bool $hpos ): void {
		$this->useHpos( $hpos );
		$this->settings( array( 'date_source' => 'status' ) );
		$this->placed( $this->addressMeta() + array( '_ctdflow_plan_date' => '2026-12-21', '_ctdflow_delivered_at' => '2026-12-21T10:00:00+01:00' ) );

		$selection = $this->selection->forDay( $this->day );

		$this->assertSame( array(), $selection['plannable'] );
		$this->assertSame( 0, $selection['delivered'] );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_an_order_planned_for_the_day_stays_selected_after_the_status_after_planning_changed_it( bool $hpos ): void {
		$this->useHpos( $hpos );
		$completed = $this->order( array( '_ctdflow_plan_date' => $this->day ), 'completed' );
		$custom    = $this->order( array( '_ctdflow_plan_date' => $this->day ), 'on-hold' );
		$this->order( array(), 'completed' );
		$this->order( array( '_ctdflow_plan_date' => '2026-12-23' ), 'completed' );

		$this->assertSame( array( $completed->get_id(), $custom->get_id() ), $this->plannableIds( $this->selection->forDay( $this->day ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_status_only_keeps_an_order_planned_for_the_day_whatever_its_status( bool $hpos ): void {
		$this->useHpos( $hpos );
		$this->settings( array( 'date_source' => 'status' ) );
		$completed = $this->placed( $this->addressMeta() + array( '_ctdflow_plan_date' => $this->day ), 'completed' );

		$this->assertSame( array( $completed->get_id() ), $this->plannableIds( $this->selection->forDay( $this->day ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_an_order_planned_for_the_day_is_left_out_once_it_is_cancelled_refunded_or_failed( bool $hpos ): void {
		$this->useHpos( $hpos );

		foreach ( array( 'cancelled', 'refunded', 'failed', 'checkout-draft' ) as $status ) {
			$this->order( array( '_ctdflow_plan_date' => $this->day ), $status );
		}

		$trashed = $this->order( array( '_ctdflow_plan_date' => $this->day ), 'completed' );
		$trashed->delete();

		$selection = $this->selection->forDay( $this->day );

		$this->assertSame( array(), $selection['plannable'] );
		$this->assertSame( array(), $selection['unplannable'] );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_added_and_removed_orders( bool $hpos ): void {
		$this->useHpos( $hpos );
		$regular        = $this->order();
		$removed        = $this->order();
		$other_day      = $this->order( array( '_orddd_lite_timestamp' => strtotime( '2026-12-30 00:00:00 UTC' ) ) );
		$pickup         = $this->order( array(), 'processing', 'local_pickup' );
		$wrong_status   = $this->order( array(), 'completed' );
		$added_removed  = $this->order( array( '_orddd_lite_timestamp' => strtotime( '2026-12-30 00:00:00 UTC' ) ) );

		$result = $this->selection->forDay(
			$this->day,
			array( $other_day->get_id(), $pickup->get_id(), $wrong_status->get_id(), $added_removed->get_id(), $regular->get_id() ),
			array( $removed->get_id(), $added_removed->get_id() )
		);

		$this->assertSame(
			array( $regular->get_id(), $other_day->get_id(), $pickup->get_id() ),
			$this->plannableIds( $result )
		);
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_results_are_sorted_by_order_id( bool $hpos ): void {
		$this->useHpos( $hpos );
		$ids = array( $this->order()->get_id(), $this->order()->get_id(), $this->order()->get_id() );

		$this->assertSame( $ids, $this->plannableIds( $this->selection->forDay( $this->day, array_reverse( $ids ) ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_the_shipping_address_is_used_when_it_has_a_street( bool $hpos ): void {
		$this->useHpos( $hpos );
		$this->order(
			array(
				'_shipping_address_1' => 'Dorpsstraat 5',
				'_shipping_address_2' => 'b',
				'_shipping_postcode'  => '3511 AB',
				'_shipping_city'      => 'Utrecht',
				'_shipping_country'   => 'NL',
			)
		);

		$selected = $this->selection->forDay( $this->day )['plannable'][0];

		$this->assertSame(
			array(
				'street'   => 'Dorpsstraat 5 b',
				'postcode' => '3511 AB',
				'city'     => 'Utrecht',
				'country'  => 'NL',
			),
			$selected->address
		);
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_billing_is_used_as_a_whole_when_shipping_has_no_street( bool $hpos ): void {
		$this->useHpos( $hpos );
		$this->order(
			array(
				'_billing_address_1'  => 'Keizersgracht 1',
				'_billing_postcode'   => '1015 CJ',
				'_billing_city'       => 'Amsterdam',
				'_billing_country'    => 'NL',
				'_shipping_postcode'  => '9999 ZZ',
				'_shipping_city'      => 'Elsewhere',
				'_shipping_country'   => 'BE',
			)
		);

		$selected = $this->selection->forDay( $this->day )['plannable'][0];

		$this->assertSame(
			array(
				'street'   => 'Keizersgracht 1',
				'postcode' => '1015 CJ',
				'city'     => 'Amsterdam',
				'country'  => 'NL',
			),
			$selected->address
		);
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_an_order_without_a_street_is_unplannable( bool $hpos ): void {
		$this->useHpos( $hpos );
		$order = $this->placed( array( '_orddd_lite_timestamp' => strtotime( '2026-12-24 00:00:00 UTC' ), '_billing_city' => 'Amsterdam', '_billing_country' => 'NL' ) );

		$result = $this->selection->forDay( $this->day );

		$this->assertSame( array(), $result['plannable'] );
		$this->assertSame( array( $order->get_id() ), $this->ids( $result['unplannable'] ) );
		$this->assertSame( 'no_address', $result['unplannable'][0]->problem );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_a_country_outside_the_supported_countries_is_unplannable( bool $hpos ): void {
		$this->useHpos( $hpos );
		$dutch   = $this->order();
		$belgian = $this->order( array( '_billing_country' => 'BE' ) );

		$result = $this->selection->forDay( $this->day, array(), array(), array( 'NL' ) );

		$this->assertSame( array( $dutch->get_id() ), $this->ids( $result['plannable'] ) );
		$this->assertSame( array( $belgian->get_id() ), $this->ids( $result['unplannable'] ) );
		$this->assertSame( 'unsupported_country', $result['unplannable'][0]->problem );
		$this->assertNull( $result['plannable'][0]->problem );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_an_added_order_still_gets_the_address_checks( bool $hpos ): void {
		$this->useHpos( $hpos );
		$other_day = $this->order( array( '_orddd_lite_timestamp' => strtotime( '2026-12-30 00:00:00 UTC' ), '_billing_address_1' => '' ) );

		$result = $this->selection->forDay( $this->day, array( $other_day->get_id() ) );

		$this->assertSame( array( $other_day->get_id() ), $this->ids( $result['unplannable'] ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_the_quantity_is_the_sum_of_the_item_quantities( bool $hpos ): void {
		$this->useHpos( $hpos );
		$order = $this->order();
		$order->add_product( $this->product(), 2 );
		$order->add_product( $this->product(), 3 );
		$order->save();

		$this->assertSame( 5, $this->selection->forDay( $this->day )['plannable'][0]->quantity );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_the_time_window_comes_from_the_date_source( bool $hpos ): void {
		$this->useHpos( $hpos );
		$this->order( array( '_orddd_time_slot' => '09:00 - 12:00' ) );
		$this->order( array() );

		$result = $this->selection->forDay( $this->day )['plannable'];

		$this->assertSame( array( 'from' => '09:00', 'to' => '12:00' ), $result[0]->timeWindow );
		$this->assertNull( $result[1]->timeWindow );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_it_selects_more_than_ten_orders( bool $hpos ): void {
		$this->useHpos( $hpos );
		for ( $i = 0; $i < 12; $i++ ) {
			$this->order();
		}

		$this->assertCount( 12, $this->selection->forDay( $this->day )['plannable'] );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_a_partial_refund_lowers_the_quantity( bool $hpos ): void {
		$this->useHpos( $hpos );
		$order = $this->order();
		$item  = $order->add_product( $this->product(), 5 );
		$order->calculate_totals();
		$order->save();
		$this->refund( $order, array( $item => 3 ) );

		$this->assertSame( 2, $this->selection->forDay( $this->day )['plannable'][0]->quantity );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_an_order_with_all_items_refunded_is_left_out( bool $hpos ): void {
		$this->useHpos( $hpos );
		$order = $this->order();
		$one   = $order->add_product( $this->product(), 2 );
		$two   = $order->add_product( $this->product(), 1 );
		$order->calculate_totals();
		$order->save();
		$this->refund( $order, array( $one => 2, $two => 1 ) );

		$result = $this->selection->forDay( $this->day, array( $order->get_id() ) );

		$this->assertSame( array(), $result['plannable'] );
		$this->assertSame( array(), $result['unplannable'] );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_an_empty_country_falls_back_to_the_other_address_then_the_shop_country( bool $hpos ): void {
		$this->useHpos( $hpos );
		update_option( 'woocommerce_default_country', 'NL:NH' );
		$billing  = $this->order( array( '_billing_country' => '' ) );
		$shipping = $this->order( array( '_shipping_address_1' => 'Dorpsstraat 5', '_shipping_country' => '', '_billing_country' => 'BE' ) );

		$result = $this->selection->forDay( $this->day, array(), array(), array( 'NL', 'BE' ) );

		$this->assertSame( array( $billing->get_id() ), $this->ids( $result['plannable'] ) );
		$this->assertSame( 'NL', $result['plannable'][0]->address['country'] );
		$this->assertSame( array( $shipping->get_id() ), $this->ids( $result['unplannable'] ) );
		$this->assertSame( 'BE', $result['unplannable'][0]->address['country'] );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_status_only_leaves_out_orders_created_more_than_sixty_days_ago_unless_planned_for_the_day( bool $hpos ): void {
		$this->useHpos( $hpos );
		$this->settings( array( 'date_source' => 'status' ) );
		$recent = $this->placed( $this->addressMeta() );
		$recent->set_date_created( time() - 59 * DAY_IN_SECONDS );
		$recent->save();
		$old = $this->placed( $this->addressMeta() );
		$old->set_date_created( time() - 61 * DAY_IN_SECONDS );
		$old->save();
		$old_planned = $this->placed( $this->addressMeta() + array( '_ctdflow_plan_date' => $this->day ) );
		$old_planned->set_date_created( time() - 90 * DAY_IN_SECONDS );
		$old_planned->save();

		$this->assertSame( array( $recent->get_id(), $old_planned->get_id() ), $this->plannableIds( $this->selection->forDay( $this->day ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_a_date_plugin_finds_orders_whatever_their_age( bool $hpos ): void {
		$this->useHpos( $hpos );
		$old = $this->order();
		$old->set_date_created( time() - 90 * DAY_IN_SECONDS );
		$old->save();

		$this->assertSame( array( $old->get_id() ), $this->plannableIds( $this->selection->forDay( $this->day ) ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_status_only_returns_more_than_a_hundred_orders( bool $hpos ): void {
		$this->useHpos( $hpos );
		$this->settings( array( 'date_source' => 'status' ) );
		$ids = array();

		for ( $i = 0; $i < 105; $i++ ) {
			$ids[] = $this->placed( $this->addressMeta() )->get_id();
		}

		$this->assertSame( $ids, $this->plannableIds( $this->selection->forDay( $this->day ) ) );
	}

	/**
	 * Refunds the given quantities, keyed by line item id.
	 *
	 * @param WC_Order          $order      The order.
	 * @param array<int, int> $quantities Quantity per line item id.
	 */
	private function refund( WC_Order $order, array $quantities ): void {
		$lines = array();

		foreach ( $quantities as $item_id => $quantity ) {
			$item            = $order->get_item( $item_id );
			$lines[ $item_id ] = array(
				'qty'          => $quantity,
				'refund_total' => 0,
			);
		}

		wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 0,
				'line_items' => $lines,
			)
		);
	}

	/**
	 * An order in a planned status with the given meta.
	 *
	 * @param array<string, mixed> $meta Meta key and value pairs.
	 */
	private function placed( array $meta, string $status = 'processing' ): WC_Order {
		return $this->create( $meta, $status, null );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_an_order_in_another_country_than_the_depot_is_unplannable_without_a_country_list( bool $hpos ): void {
		$this->useHpos( $hpos );
		$dutch   = $this->order();
		$belgian = $this->order( array( '_billing_country' => 'BE' ) );

		$result = $this->selection->forDay( $this->day );

		$this->assertSame( array( $dutch->get_id() ), $this->ids( $result['plannable'] ) );
		$this->assertSame( array( $belgian->get_id() ), $this->ids( $result['unplannable'] ) );
		$this->assertSame( 'unsupported_country', $result['unplannable'][0]->problem );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_address_line_2_only_counts_as_a_house_number_addition( bool $hpos ): void {
		$this->useHpos( $hpos );
		$kept = array( '2-hoog' => 'Keizersgracht 1 2-hoog', 'A' => 'Keizersgracht 1 A', 'bis' => 'Keizersgracht 1 bis', 'Unit 12 rear' => 'Keizersgracht 1 Unit 12 rear' );
		$dropped = array( 'Walrusfabriek BV', 't.a.v. Jansen' );

		foreach ( array_merge( array_keys( $kept ), $dropped ) as $line ) {
			$order = $this->order( array( '_billing_address_2' => $line ) );
			$street = $this->selection->forDay( $this->day, array(), array( ) )['plannable'];
			$match  = array_values( array_filter( $street, static fn( SelectedOrder $s ) => $s->order->get_id() === $order->get_id() ) )[0];

			$this->assertSame( $kept[ $line ] ?? 'Keizersgracht 1', $match->address['street'], $line );
		}
	}

	/**
	 * An order that is delivered on the test day to a Dutch address, unless the meta says otherwise.
	 *
	 * @param array<string, mixed> $meta Meta overrides.
	 */
	private function order( array $meta = array(), string $status = 'processing', ?string $shipping_method = null ): WC_Order {
		return $this->create(
			array_merge(
				array( '_orddd_lite_timestamp' => strtotime( $this->day . ' 00:00:00 UTC' ) ),
				$this->addressMeta(),
				$meta
			),
			$status,
			$shipping_method
		);
	}

	/**
	 * Creates an order. Address keys such as _billing_city go through the setters, the rest is order meta.
	 *
	 * @param array<string, mixed> $meta            Keys and values.
	 * @param string               $status          Order status without prefix.
	 * @param string|null          $shipping_method Shipping method id, if any.
	 */
	private function create( array $meta, string $status, ?string $shipping_method ): WC_Order {
		$order = wc_create_order();

		foreach ( $meta as $key => $value ) {
			if ( 1 === preg_match( '/^_(billing|shipping)_(.+)$/', $key, $parts ) ) {
				$order->{'set_' . $parts[1] . '_' . $parts[2]}( $value );
			} else {
				$order->update_meta_data( $key, $value );
			}
		}

		if ( null !== $shipping_method ) {
			$item = new WC_Order_Item_Shipping();
			$item->set_method_id( $shipping_method );
			$order->add_item( $item );
		}

		$order->set_status( $status );
		$order->save();

		return $order;
	}

	/**
	 * A day relative to today in the site timezone, as Y-m-d.
	 *
	 * @param string $relative For example today or tomorrow.
	 */
	private function localDay( string $relative ): string {
		return ( new \DateTimeImmutable( $relative, wp_timezone() ) )->format( 'Y-m-d' );
	}

	/**
	 * @return array<string, string>
	 */
	private function addressMeta(): array {
		return array(
			'_billing_address_1' => 'Keizersgracht 1',
			'_billing_postcode'  => '1015 CJ',
			'_billing_city'      => 'Amsterdam',
			'_billing_country'   => 'NL',
		);
	}

	private function product( bool $virtual = false ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Test product' );
		$product->set_virtual( $virtual );
		$product->save();

		return $product;
	}

	private function withShipping( WC_Order $order, string $method, int $instance ): WC_Order {
		$item = new WC_Order_Item_Shipping();
		$item->set_method_id( $method );
		$item->set_instance_id( $instance );
		$order->add_item( $item );
		$order->save();

		return $order;
	}

	/**
	 * @param array<string, mixed> $settings Stored settings.
	 */
	private function settings( array $settings ): void {
		update_option( Settings::OPTION, $settings );
	}

	/**
	 * @param array{plannable: SelectedOrder[], unplannable: SelectedOrder[]} $result Selection result.
	 *
	 * @return int[]
	 */
	private function plannableIds( array $result ): array {
		return $this->ids( $result['plannable'] );
	}

	/**
	 * @param SelectedOrder[] $selected Selected orders.
	 *
	 * @return int[]
	 */
	private function ids( array $selected ): array {
		return array_map( static fn( SelectedOrder $s ) => $s->order->get_id(), $selected );
	}
}
