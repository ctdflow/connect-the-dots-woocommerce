<?php

namespace ConnectTheDots\Tests\Admin;

use ConnectTheDots\Account;
use ConnectTheDots\Admin\Menu;
use ConnectTheDots\Admin\RoutesPage;
use ConnectTheDots\PlanStore;
use ConnectTheDots\Settings;
use ConnectTheDots\Tests\Support\FakeCtd;
use ConnectTheDots\Tests\Support\TestCase;
use RuntimeException;
use WC_Order;

class RoutesPageTest extends TestCase {

	private FakeCtd $ctd;

	private string $tomorrow;

	public function set_up(): void {
		parent::set_up();

		$this->ctd = FakeCtd::install();
		update_option( 'timezone_string', 'Europe/Amsterdam' );
		update_option( 'woocommerce_default_country', 'NL' );
		$this->settings( array() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$this->tomorrow = ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	public function test_not_connected_it_explains_what_goes_to_ctdflow_and_shows_no_orders(): void {
		$this->order();

		$page = $this->renderedPage();

		$this->assertStringContainsString( 'Connect to Connect the Dots', $page );
		$this->assertStringContainsString( 'name="action" value="ctdflow_connect"', $page );
		$this->assertStringContainsString( '_wpnonce', $page );

		foreach ( array( 'order ID', 'delivery address', 'time window', 'number of items', 'name of each van', 'start and end address', 'available', 'capacity', 'Never names, e-mail addresses, phone numbers, amounts or products' ) as $item ) {
			$this->assertStringContainsString( $item, $page );
		}

		$this->assertStringContainsString( 'href="https://ctdflow.com/privacy"', $page );
		$this->assertStringContainsString( 'href="https://ctdflow.com/terms"', $page );
		$this->assertStringNotContainsString( 'order_ids[]', $page );
		$this->assertSame( array(), $this->ctd->requests() );
	}

	/**
	 * @dataProvider planLabels
	 */
	public function test_the_header_shows_the_organisation_and_the_plan( string $status, string $label ): void {
		$this->connect();
		$this->account( array( 'billing_status' => $status, 'paid' => 'free' !== $status ) );

		$page = $this->renderedPage();

		$this->assertStringContainsString( 'Bakkerij Jansen', $page );
		$this->assertStringContainsString( $label, $page );
	}

	public static function planLabels(): array {
		return array(
			'free'   => array( 'free', 'Free' ),
			'trial'  => array( 'trial', 'Trial' ),
			'active' => array( 'active', 'Active' ),
		);
	}

	public function test_it_shows_the_orders_of_tomorrow_and_the_unplannable_ones_with_their_reason(): void {
		$this->connect();
		$this->account();
		$plannable = $this->order();
		$no_street = $this->order( array( '_billing_address_1' => '' ) );

		$page = $this->renderedPage();

		$this->assertSame( $this->tomorrow, $this->ctd->requests()[0]['query']['delivery_date'] );
		$this->assertStringContainsString( 'value="' . $this->tomorrow . '"', $page );
		$this->assertStringContainsString( 'name="order_ids[]" value="' . $plannable->get_id() . '" checked', $page );
		$this->assertStringNotContainsString( 'name="order_ids[]" value="' . $no_street->get_id() . '"', $page );
		$this->assertStringContainsString( '#' . $no_street->get_order_number(), $page );
		$this->assertStringContainsString( 'No delivery address', $page );
	}

	public function test_the_page_says_how_many_delivered_orders_were_left_out(): void {
		$this->connect();
		$this->account();
		$this->orders( 1 );
		$this->order( array( '_ctdflow_delivered_at' => $this->tomorrow . 'T09:05:00+01:00', '_ctdflow_plan_date' => $this->tomorrow ) );

		$page = $this->renderedPage();

		$this->assertStringContainsString( '1 order to plan', $page );
		$this->assertStringContainsString( '1 order of this day is delivered already and is left out.', $page );
	}

	public function test_another_day_can_be_chosen(): void {
		$this->connect();
		$this->account();

		$this->renderedPage( array( 'date' => '2026-12-24' ) );

		$this->assertSame( '2026-12-24', $this->ctd->requests()[0]['query']['delivery_date'] );
	}

	public function test_the_order_list_uses_the_supported_countries_of_the_account(): void {
		$this->connect();
		$this->account( array( 'supported_countries' => array( 'BE' ) ) );
		$order = $this->order();

		$page = $this->renderedPage();

		$this->assertStringNotContainsString( 'name="order_ids[]" value="' . $order->get_id() . '"', $page );
		$this->assertStringContainsString( 'Country not supported', $page );
	}

	public function test_orders_can_be_added_by_id(): void {
		$this->connect();
		$this->account();
		$planned_elsewhere = $this->order( array( '_ctdflow_plan_date' => '2026-12-01' ) );

		$this->assertStringNotContainsString( 'value="' . $planned_elsewhere->get_id() . '" checked', $this->renderedPage() );
		$this->assertStringContainsString( 'value="' . $planned_elsewhere->get_id() . '" checked', $this->renderedPage( array( 'add' => (string) $planned_elsewhere->get_id() ) ) );
	}

	public function test_a_free_account_with_more_orders_than_the_free_tier_gets_the_choice_first(): void {
		$this->connect();
		$this->account();
		$this->orders( 26 );

		$page = $this->renderedPage();

		$this->assertStringContainsString( 'Plan the first 25', $page );
		$this->assertStringContainsString( 'Try a paid plan free for 30 days', $page );
	}

	public function test_the_choice_keeps_its_buttons_when_escaped_on_output(): void {
		$this->connect();
		$this->account();
		$this->orders( 26 );

		$page = $this->renderedPage();

		$this->assertStringContainsString( '<button type="submit" name="choice" value="first" class="button">Plan the first 25</button>', $page );
		$this->assertStringContainsString( '<button type="submit" name="choice" value="trial" class="button button-primary">', $page );
	}

	public function test_a_lost_connection_on_the_page_keeps_the_selection_fields_when_escaped_on_output(): void {
		$this->connect();
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 401, array( 'error' => array( 'type' => 'authentication_error', 'code' => 'unauthenticated', 'message' => 'No.' ) ) );
		$this->ctd->respond( 'POST', '/oauth/token', 400, array( 'error' => 'invalid_grant', 'error_description' => 'Revoked.' ) );

		$page = $this->renderedPage( array( 'date' => $this->tomorrow ) );

		$this->assertStringContainsString( '<input type="hidden" name="ctdflow_selection[date]" value="' . $this->tomorrow . '" />', $page );
	}

	public function test_planning_above_the_free_tier_without_a_choice_asks_for_it_and_sends_nothing(): void {
		$this->connect();
		$this->account();
		$orders = $this->orders( 26 );

		$response = $this->ajax( 'handlePlan', $this->planRequest( $orders ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'choice_needed', $response['data']['code'] );
		$this->assertStringContainsString( 'Plan the first 25', $response['data']['html'] );
		$this->assertSame( array( 'GET' ), array_column( $this->ctd->requests(), 'method' ) );
	}

	public function test_the_first_choice_sends_25_stops_by_time_window_then_order_number(): void {
		$this->connect();
		$this->account();
		$this->settings( array( 'date_source' => 'woo-delivery' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		$orders = array();

		for ( $i = 0; $i < 26; $i++ ) {
			$window   = 0 === $i ? array() : array( 'delivery_time' => $i <= 12 ? '13:00 - 15:00' : '09:00 - 11:00' );
			$orders[] = $this->order( array( 'delivery_date' => $this->tomorrow ) + $window );
		}

		$response = $this->ajax( 'handlePlan', $this->planRequest( $orders, array( 'van-1' ), 'first' ) );

		$this->assertTrue( $response['success'] );

		$expected = array_map( static fn ( WC_Order $order ) => (string) $order->get_id(), array_merge( array_slice( $orders, 13 ), array_slice( $orders, 1, 12 ) ) );

		$this->assertSame( $expected, array_column( $this->planBody()['stops'], 'reference' ) );
	}

	public function test_the_first_choice_reads_the_free_tier_fresh_instead_of_from_the_cache(): void {
		$this->connect();
		$this->account();
		$this->account( array( 'free_tier' => array( 'max_vehicles' => 1, 'max_stops' => 25, 'remaining_stops' => 10 ) ) );
		( new Account() )->fetch( $this->tomorrow );
		$this->settings( array( 'date_source' => 'woo-delivery' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		$orders = array();

		for ( $i = 0; $i < 26; $i++ ) {
			$orders[] = $this->order( array( 'delivery_date' => $this->tomorrow ) );
		}

		$response = $this->ajax( 'handlePlan', $this->planRequest( $orders, array( 'van-1' ), 'first' ) );

		$this->assertTrue( $response['success'] );
		$this->assertCount( 10, $this->planBody()['stops'] );
	}

	public function test_the_trial_choice_sends_all_orders(): void {
		$this->connect();
		$this->account();
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );
		$orders = $this->orders( 26 );

		$response = $this->ajax( 'handlePlan', $this->planRequest( $orders, array( 'van-1' ), 'trial' ) );

		$this->assertTrue( $response['success'] );
		$this->assertCount( 26, $this->planBody()['stops'] );
	}

	public function test_a_free_account_with_two_active_vans_gets_the_choice_and_the_first_sends_one_van(): void {
		$this->connect();
		$this->account();
		$this->settings( array( 'vehicles' => array( $this->van( 'van-a', 'Van A' ), $this->van( 'van-b', 'Van B' ) ) ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );
		$orders = $this->orders( 3 );

		$page = $this->renderedPage();

		$this->assertStringContainsString( 'Plan with 1 van', $page );
		$this->assertStringContainsString( 'Try a paid plan free for 30 days', $page );

		$response = $this->ajax( 'handlePlan', $this->planRequest( $orders, array( 'van-a', 'van-b' ), 'first' ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'Van A' ), array_column( $this->planBody()['vehicles'], 'name' ) );
		$this->assertCount( 3, $this->planBody()['stops'] );
	}

	public function test_a_paid_account_plans_everything_without_a_choice(): void {
		$this->connect();
		$this->account( array( 'billing_status' => 'active', 'paid' => true, 'free_tier' => null ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );
		$orders = $this->orders( 26 );

		$this->assertStringNotContainsString( 'Plan the first', $this->renderedPage() );

		$response = $this->ajax( 'handlePlan', $this->planRequest( $orders ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'opt_1', $response['data']['plan_id'] );
		$this->assertCount( 26, $this->planBody()['stops'] );
		$this->assertSame( 'opt_1', ( new PlanStore() )->pending( $this->tomorrow ) );
	}

	public function test_upgrade_required_shows_the_message_with_a_link_to_the_pricing(): void {
		$this->connect();
		$this->account();
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 402, array( 'error' => array( 'type' => 'billing_error', 'code' => 'upgrade_required', 'message' => 'Upgrade.' ) ) );
		$orders = $this->orders( 26 );

		$response = $this->ajax( 'handlePlan', $this->planRequest( $orders, array( 'van-1' ), 'trial' ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'upgrade_required', $response['data']['code'] );
		$this->assertStringContainsString( 'needs a paid Connect the Dots plan', $response['data']['html'] );
		$this->assertStringContainsString( 'href="https://ctdflow.com/pricing"', $response['data']['html'] );
	}

	public function test_a_lost_connection_offers_to_connect_again_and_keeps_the_selection(): void {
		$this->connect();
		$this->account();
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 401, array( 'error' => array( 'type' => 'authentication_error', 'code' => 'unauthenticated', 'message' => 'No.' ) ) );
		$this->ctd->respond( 'POST', '/oauth/token', 400, array( 'error' => 'invalid_grant', 'error_description' => 'Revoked.' ) );
		$orders = $this->orders( 2 );

		$response = $this->ajax( 'handlePlan', $this->planRequest( $orders ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'connection_lost', $response['data']['code'] );
		$this->assertStringContainsString( 'Connect again', $response['data']['html'] );
		$this->assertStringContainsString( 'name="action" value="ctdflow_connect"', $response['data']['html'] );
		$this->assertSame(
			array(
				'date'        => $this->tomorrow,
				'order_ids'   => array_map( static fn ( WC_Order $order ) => $order->get_id(), $orders ),
				'vehicle_ids' => array( 'van-1' ),
			),
			$response['data']['selection']
		);
	}

	public function test_the_connect_again_form_carries_the_selection(): void {
		$this->connect();
		$this->account();
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 401, array( 'error' => array( 'type' => 'authentication_error', 'code' => 'unauthenticated', 'message' => 'No.' ) ) );
		$this->ctd->respond( 'POST', '/oauth/token', 400, array( 'error' => 'invalid_grant', 'error_description' => 'Revoked.' ) );
		$orders = $this->orders( 1 );

		$html = $this->ajax( 'handlePlan', $this->planRequest( $orders ) )['data']['html'];

		$this->assertStringContainsString( 'name="ctdflow_selection[date]" value="' . $this->tomorrow . '"', $html );
		$this->assertStringContainsString( 'name="ctdflow_selection[order_ids][]" value="' . $orders[0]->get_id() . '"', $html );
		$this->assertStringContainsString( 'name="ctdflow_selection[added][]" value="' . $orders[0]->get_id() . '"', $html );
		$this->assertStringContainsString( 'name="ctdflow_selection[vehicle_ids][]" value="van-1"', $html );
	}

	public function test_a_kept_selection_is_restored_once_for_its_day(): void {
		$this->connect();
		$this->account();
		$this->settings( array( 'vehicles' => array( $this->van( 'van-a', 'Van A' ), $this->van( 'van-b', 'Van B' ) ) ) );
		$chosen            = $this->order();
		$left_out          = $this->order();
		$planned_elsewhere = $this->order( array( '_ctdflow_plan_date' => '2026-12-01' ) );

		RoutesPage::create()->rememberSelection(
			array(
				'date'        => $this->tomorrow,
				'added'       => array( (string) $planned_elsewhere->get_id() ),
				'order_ids'   => array( (string) $chosen->get_id(), (string) $planned_elsewhere->get_id() ),
				'vehicle_ids' => array( 'van-b' ),
			)
		);

		$this->assertSame( $this->tomorrow, RoutesPage::create()->rememberedDate() );

		$page = $this->renderedPage( array( 'date' => $this->tomorrow ) );

		$this->assertStringContainsString( 'value="' . $chosen->get_id() . '" checked', $page );
		$this->assertStringContainsString( 'value="' . $planned_elsewhere->get_id() . '" checked', $page );
		$this->assertStringContainsString( 'value="' . $left_out->get_id() . '" id=', $page );
		$this->assertStringContainsString( 'value="van-a" />', $page );
		$this->assertStringContainsString( 'value="van-b" checked=\'checked\' />', $page );
		$this->assertNull( RoutesPage::create()->rememberedDate() );

		$again = $this->renderedPage( array( 'date' => $this->tomorrow ) );

		$this->assertStringContainsString( 'value="' . $left_out->get_id() . '" checked', $again );
		$this->assertStringNotContainsString( 'value="' . $planned_elsewhere->get_id() . '"', $again );
	}

	public function test_a_kept_selection_of_another_day_is_not_restored(): void {
		$this->connect();
		$this->account();
		$order = $this->order();

		RoutesPage::create()->rememberSelection( array( 'date' => '2026-12-30', 'order_ids' => array( '999' ) ) );

		$this->assertStringContainsString( 'value="' . $order->get_id() . '" checked', $this->renderedPage() );
		$this->assertSame( '2026-12-30', RoutesPage::create()->rememberedDate() );
	}

	public function test_a_refused_plan_shows_the_reason_without_a_request(): void {
		$this->connect();
		$this->account();

		$response = $this->ajax( 'handlePlan', $this->planRequest( array() ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'not_possible', $response['data']['code'] );
		$this->assertStringContainsString( 'no orders to plan', $response['data']['html'] );
		$this->assertSame( array( 'GET' ), array_column( $this->ctd->requests(), 'method' ) );
	}

	public function test_a_missing_timezone_is_shown_escaped(): void {
		$this->connect();
		$this->account();
		update_option( 'timezone_string', '' );
		$orders = $this->orders( 1 );

		$response = $this->ajax( 'handlePlan', $this->planRequest( $orders ) );

		$this->assertSame( 'not_possible', $response['data']['code'] );
		$this->assertStringContainsString( 'Settings &gt; General', $response['data']['html'] );
		$this->assertStringNotContainsString( '&amp;gt;', $response['data']['html'] );
	}

	public function test_planning_needs_a_nonce(): void {
		$this->connect();
		$request          = $this->planRequest( $this->orders( 1 ) );
		$request['nonce'] = 'wrong';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'ajax-die:-1' );

		try {
			$this->ajax( 'handlePlan', $request );
		} finally {
			$this->assertSame( array(), $this->ctd->requests() );
		}
	}

	public function test_planning_needs_the_capability(): void {
		$this->connect();
		$request = $this->planRequest( $this->orders( 1 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$request['nonce'] = wp_create_nonce( 'ctdflow_routes' );

		$response = $this->ajax( 'handlePlan', $request );

		$this->assertFalse( $response['success'] );
		$this->assertSame( array(), $this->ctd->requests() );
	}

	public function test_the_status_of_a_queued_plan_is_not_done(): void {
		$this->connect();
		( new PlanStore() )->putPending( $this->tomorrow, 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, array( 'plan_id' => 'opt_1', 'status' => 'running', 'routes' => array(), 'unplanned' => array() ) );

		$response = $this->ajax( 'handleStatus', $this->statusRequest() );

		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['data']['done'] );
	}

	public function test_a_pending_plan_that_succeeded_becomes_the_current_plan(): void {
		$this->connect();
		$orders = $this->orders( 2 );
		( new PlanStore() )->put( $this->tomorrow, 'opt_0' );
		( new PlanStore() )->putPending( $this->tomorrow, 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->succeeded( $orders[0], $orders[1] ) );

		$response = $this->ajax( 'handleStatus', $this->statusRequest() );

		$this->assertTrue( $response['data']['done'] );
		$this->assertStringContainsString( 'Van A', $response['data']['html'] );
		$this->assertSame( 'opt_1', ( new PlanStore() )->get( $this->tomorrow ) );
		$this->assertNull( ( new PlanStore() )->pending( $this->tomorrow ) );
		$this->assertSame( 'opt_1', wc_get_order( $orders[0]->get_id() )->get_meta( '_ctdflow_plan_id' ) );
	}

	public function test_a_running_replan_keeps_showing_the_current_routes(): void {
		$this->connect();
		$orders = $this->orders( 2 );
		( new PlanStore() )->put( $this->tomorrow, 'opt_1' );
		( new PlanStore() )->putPending( $this->tomorrow, 'opt_2' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->succeeded( $orders[0], $orders[1] ) );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_2', 200, array( 'plan_id' => 'opt_2', 'status' => 'running', 'routes' => array(), 'unplanned' => array() ) );

		$response = $this->ajax( 'handleStatus', $this->statusRequest() );

		$this->assertFalse( $response['data']['done'] );
		$this->assertStringContainsString( 'Planning the routes', $response['data']['html'] );
		$this->assertStringContainsString( 'https://api.ctdflow.com/r/token-1', $response['data']['html'] );
	}

	public function test_a_failed_replan_shows_why_above_the_routes_that_still_apply(): void {
		$this->connect();
		$this->account();
		$orders = $this->orders( 2 );
		( new PlanStore() )->put( $this->tomorrow, 'opt_1' );
		( new PlanStore() )->putPending( $this->tomorrow, 'opt_2' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->succeeded( $orders[0], $orders[1] ) );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_2', 200, array( 'plan_id' => 'opt_2', 'status' => 'failed', 'superseded_by' => null, 'routes' => array(), 'unplanned' => array(), 'error' => array( 'code' => 'solver_timeout', 'message' => 'Too long.' ) ) );

		$response = $this->ajax( 'handleStatus', $this->statusRequest() );
		$html     = $response['data']['html'];

		$this->assertTrue( $response['data']['done'] );
		$this->assertStringContainsString( 'Planning took too long', $html );
		$this->assertStringContainsString( 'The routes below still apply', $html );
		$this->assertLessThan( strpos( $html, 'https://api.ctdflow.com/r/token-1' ), strpos( $html, 'Planning took too long' ) );
		$this->assertSame( 'opt_1', ( new PlanStore() )->get( $this->tomorrow ) );
		$this->assertSame( 'opt_1', wc_get_order( $orders[0]->get_id() )->get_meta( '_ctdflow_plan_id' ) );
		$this->assertStringContainsString( 'Planning took too long', $this->renderedPage() );
	}

	public function test_a_stop_that_cannot_be_reached_by_road_is_listed_as_not_planned(): void {
		$this->connect();
		$orders = $this->orders( 2 );
		( new PlanStore() )->putPending( $this->tomorrow, 'opt_1' );
		$plan              = $this->succeeded( $orders[0], $orders[1] );
		$plan['unplanned'] = array( array( 'reference' => (string) $orders[1]->get_id(), 'reason' => 'location_unreachable' ) );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $plan );

		$html = $this->ajax( 'handleStatus', $this->statusRequest() )['data']['html'];

		$this->assertStringContainsString( 'Not planned', $html );
		$this->assertStringContainsString( '#' . $orders[1]->get_order_number(), $html );
		$this->assertStringContainsString( 'No road leads to this address. Check the address.', $html );
	}

	/**
	 * @dataProvider unreachableVehicleAddresses
	 */
	public function test_a_plan_that_failed_on_the_depot_or_an_end_address_names_it( string $address, string $message ): void {
		$this->connect();
		( new PlanStore() )->putPending( $this->tomorrow, 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, array( 'plan_id' => 'opt_1', 'status' => 'failed', 'routes' => array(), 'unplanned' => array(), 'error' => array( 'code' => 'location_unreachable', 'message' => 'x', 'vehicle' => 'Van A', 'address' => $address ) ) );

		$this->assertStringContainsString( $message, $this->ajax( 'handleStatus', $this->statusRequest() )['data']['html'] );
	}

	public static function unreachableVehicleAddresses(): array {
		return array(
			'depot'       => array( 'start', 'No road leads to the depot. Check the depot address on the Settings tab.' ),
			'end address' => array( 'end', 'No road leads to the end address of Van A. Check it on the Settings tab.' ),
		);
	}

	public function test_the_routes_show_when_a_stop_was_delivered(): void {
		$this->connect();
		$orders = $this->orders( 2 );
		( new PlanStore() )->put( $this->tomorrow, 'opt_1' );
		$plan = $this->succeeded( $orders[0], $orders[1] );
		$plan['routes'][0]['stops'][0]['delivered_at'] = $this->tomorrow . 'T09:47:00+01:00';
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $plan );

		$html = $this->ajax( 'handleStatus', $this->statusRequest() )['data']['html'];

		$this->assertStringContainsString( '<th scope="col">Delivered</th>', $html );
		$this->assertStringContainsString( '✓ 09:47', $html );
	}

	public function test_a_succeeded_plan_is_applied_and_rendered(): void {
		$this->connect();
		$orders = $this->orders( 2 );
		( new PlanStore() )->put( $this->tomorrow, 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->succeeded( $orders[0], $orders[1] ) );

		$response = $this->ajax( 'handleStatus', $this->statusRequest() );
		$html     = $response['data']['html'];

		$this->assertTrue( $response['data']['done'] );
		$this->assertSame( 'opt_1', wc_get_order( $orders[0]->get_id() )->get_meta( '_ctdflow_plan_id' ) );
		$this->assertSame( $this->tomorrow, wc_get_order( $orders[0]->get_id() )->get_meta( '_ctdflow_plan_date' ) );
		$this->assertStringContainsString( 'Van A', $html );
		$this->assertStringContainsString( '08:12', $html );
		$this->assertStringNotContainsString( '07:41', $html, 'The early arrival is shown instead of the expected time.' );
		$this->assertStringContainsString( '09:00 to 11:00', $html );
		$this->assertStringContainsString( '3 h 40 min', $html );
		$this->assertStringContainsString( '62 km', $html );
		$this->assertStringContainsString( 'value="https://api.ctdflow.com/r/token-1"', $html );
		$this->assertStringContainsString( 'data-ctdflow-copy', $html );
		$this->assertStringContainsString( 'href="https://api.ctdflow.com/r/token-1"', $html );
		$this->assertStringContainsString( 'Not planned', $html );
		$this->assertStringContainsString( 'Address not found', $html );
		$this->assertStringContainsString( esc_url( $orders[1]->get_edit_order_url() ), $html );
	}

	public function test_a_superseded_plan_is_never_applied_and_the_newer_plan_is_stored_and_applied(): void {
		$this->connect();
		$orders = $this->orders( 2 );
		( new PlanStore() )->put( $this->tomorrow, 'opt_old' );

		$old                  = $this->succeeded( $orders[0], $orders[1] );
		$old['plan_id']       = 'opt_old';
		$old['superseded_by'] = 'opt_new';
		$old['unplanned']     = array();
		$old['routes'][0]['stops'][] = array( 'reference' => (string) $orders[1]->get_id(), 'position' => 2, 'arrival' => $this->tomorrow . 'T08:30:00+01:00', 'departure' => $this->tomorrow . 'T08:33:00+01:00', 'delivered_at' => null );

		$new                               = $this->succeeded( $orders[0], $orders[1] );
		$new['plan_id']                    = 'opt_new';
		$new['unplanned']                  = array();
		$new['routes'][0]['vehicle']       = 'Van B';

		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_old', 200, $old );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_new', 200, $new );

		$response = $this->ajax( 'handleStatus', $this->statusRequest() );

		$this->assertTrue( $response['data']['done'] );
		$this->assertStringContainsString( 'Van B', $response['data']['html'] );
		$this->assertSame( 'opt_new', ( new PlanStore() )->get( $this->tomorrow ) );
		$this->assertSame( 'opt_new', wc_get_order( $orders[0]->get_id() )->get_meta( '_ctdflow_plan_id' ) );
		$this->assertSame( 'Van B', wc_get_order( $orders[0]->get_id() )->get_meta( '_ctdflow_vehicle' ) );
		$this->assertSame( '', wc_get_order( $orders[1]->get_id() )->get_meta( '_ctdflow_plan_id' ), 'The superseded plan was applied.' );
	}

	public function test_a_chain_of_supersessions_is_followed_at_most_five_times(): void {
		$this->connect();
		$order = $this->orders( 1 )[0];
		( new PlanStore() )->put( $this->tomorrow, 'opt_0' );

		for ( $i = 0; $i <= 6; $i++ ) {
			$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_' . $i, 200, array( 'plan_id' => 'opt_' . $i, 'status' => 'succeeded', 'delivery_date' => $this->tomorrow, 'superseded_by' => 'opt_' . ( $i + 1 ), 'routes' => array( array( 'vehicle' => 'Van A', 'driver_link' => null, 'distance_meters' => 1, 'duration_seconds' => 1, 'stops' => array( array( 'reference' => (string) $order->get_id(), 'position' => 1, 'arrival' => $this->tomorrow . 'T08:12:00+01:00', 'departure' => $this->tomorrow . 'T08:15:00+01:00', 'delivered_at' => null ) ) ) ), 'unplanned' => array() ) );
		}

		$response = $this->ajax( 'handleStatus', $this->statusRequest() );

		$this->assertTrue( $response['data']['done'] );
		$this->assertStringContainsString( 'Plan this day again', $response['data']['html'] );
		$this->assertCount( 6, $this->ctd->requests() );
		$this->assertSame( 'opt_5', ( new PlanStore() )->get( $this->tomorrow ) );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
	}

	public function test_an_expired_plan_says_so_in_the_status_and_on_the_page(): void {
		$this->connect();
		$this->account();
		( new PlanStore() )->put( $this->tomorrow, 'opt_gone' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_gone', 404, array( 'error' => array( 'type' => 'invalid_request_error', 'code' => 'plan_not_found', 'message' => 'Not found.' ) ) );

		$response = $this->ajax( 'handleStatus', $this->statusRequest() );

		$this->assertTrue( $response['data']['done'] );
		$this->assertStringContainsString( 'Plan expired', $response['data']['html'] );
		$this->assertStringContainsString( 'Plan expired', $this->renderedPage() );
	}

	public function test_a_failed_plan_shows_why(): void {
		$this->connect();
		( new PlanStore() )->putPending( $this->tomorrow, 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, array( 'plan_id' => 'opt_1', 'status' => 'failed', 'routes' => array(), 'unplanned' => array(), 'error' => array( 'code' => 'routing_unavailable', 'message' => 'Down.' ) ) );

		$response = $this->ajax( 'handleStatus', $this->statusRequest() );

		$this->assertTrue( $response['data']['done'] );
		$this->assertStringContainsString( 'temporarily unavailable', $response['data']['html'] );
	}

	public function test_the_status_needs_a_nonce(): void {
		$this->connect();
		$request          = $this->statusRequest();
		$request['nonce'] = 'wrong';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'ajax-die:-1' );

		$this->ajax( 'handleStatus', $request );
	}

	public function test_the_status_needs_the_capability(): void {
		$this->connect();
		( new PlanStore() )->put( $this->tomorrow, 'opt_1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->ajax( 'handleStatus', $this->statusRequest() );

		$this->assertFalse( $response['success'] );
		$this->assertSame( array(), $this->ctd->requests() );
	}

	public function test_without_javascript_a_reload_shows_the_stored_plan(): void {
		$this->connect();
		$this->account();
		$orders = $this->orders( 2 );
		( new PlanStore() )->put( $this->tomorrow, 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->succeeded( $orders[0], $orders[1] ) );

		$page = $this->renderedPage();

		$this->assertStringContainsString( 'https://api.ctdflow.com/r/token-1', $page );
		$this->assertMatchesRegularExpression( '/<input type="text" readonly id="[^"]+" value="https:\/\/api\.ctdflow\.com\/r\/token-1" class="regular-text" \/>/', $page );
		$this->assertMatchesRegularExpression( '/<button type="button" class="button" data-ctdflow-copy="[^"]+">Copy<\/button>/', $page );
		$this->assertSame( 'Van A', wc_get_order( $orders[0]->get_id() )->get_meta( '_ctdflow_vehicle' ) );
	}

	public function test_a_running_plan_on_the_page_makes_the_script_poll(): void {
		$this->connect();
		$this->account();
		( new PlanStore() )->putPending( $this->tomorrow, 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, array( 'plan_id' => 'opt_1', 'status' => 'queued', 'routes' => array(), 'unplanned' => array() ) );

		$this->assertStringContainsString( 'data-ctdflow-pending="1"', $this->renderedPage() );
	}

	public function test_the_script_and_style_are_only_enqueued_on_this_page(): void {
		wp_dequeue_script( 'ctdflow-routes-page' );
		wp_dequeue_style( 'ctdflow-routes-page' );

		$menu = Menu::create();
		$menu->addMenu();
		$menu->enqueue( 'edit.php' );

		$this->assertFalse( wp_script_is( 'ctdflow-routes-page', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'ctdflow-routes-page', 'enqueued' ) );

		$menu->enqueue( get_plugin_page_hookname( Menu::SLUG, 'woocommerce' ) );

		$this->assertTrue( wp_script_is( 'ctdflow-routes-page', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'ctdflow-routes-page', 'enqueued' ) );
		$this->assertStringStartsWith( plugins_url(), wp_scripts()->registered['ctdflow-routes-page']->src );
		$this->assertStringContainsString( wp_create_nonce( 'ctdflow_routes' ), (string) wp_scripts()->get_data( 'ctdflow-routes-page', 'data' ) );
	}

	public function test_on_a_free_account_the_settings_tab_says_more_vans_need_a_paid_plan(): void {
		$this->connect();
		$this->account();

		$page = $this->renderedPage( array( 'tab' => 'settings' ) );

		$this->assertStringContainsString( 'needs a paid plan', $page );
		$this->assertStringContainsString( 'href="https://ctdflow.com/pricing"', $page );
	}

	public function test_on_a_paid_account_the_settings_tab_has_no_van_notice(): void {
		$this->connect();
		$this->account( array( 'billing_status' => 'active', 'paid' => true, 'free_tier' => null ) );

		$this->assertStringNotContainsString( 'needs a paid plan', $this->renderedPage( array( 'tab' => 'settings' ) ) );
	}

	private function renderedPage( array $query = array() ): string {
		$_GET = $query + array( 'page' => Menu::SLUG );

		ob_start();
		Menu::create()->render();

		return (string) ob_get_clean();
	}

	/**
	 * Calls an AJAX handler of the page and returns the decoded JSON it sent.
	 *
	 * @param string $handler Method of RoutesPage.
	 * @param array  $post    Posted fields.
	 */
	private function ajax( string $handler, array $post ): array {
		$_POST    = $post;
		$_REQUEST = $post;

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static fn () => static function ( $message ) {
				throw new RuntimeException( 'ajax-die:' . $message );
			},
			999
		);

		ob_start();

		try {
			RoutesPage::create()->{$handler}();
		} catch ( RuntimeException $die ) {
			if ( 'ajax-die:' !== $die->getMessage() ) {
				ob_end_clean();
				throw $die;
			}
		}

		$decoded = json_decode( (string) ob_get_clean(), true );

		$this->assertIsArray( $decoded, 'The handler sent no JSON.' );

		return $decoded;
	}

	/**
	 * @param WC_Order[] $orders Orders to plan.
	 * @param string[]   $vans   Van ids.
	 * @param string     $choice Free tier choice.
	 */
	private function planRequest( array $orders, array $vans = array( 'van-1' ), string $choice = '' ): array {
		return array(
			'action'      => 'ctdflow_plan',
			'nonce'       => wp_create_nonce( 'ctdflow_routes' ),
			'date'        => $this->tomorrow,
			'order_ids'   => array_map( static fn ( WC_Order $order ) => (string) $order->get_id(), $orders ),
			'vehicle_ids' => $vans,
			'choice'      => $choice,
		);
	}

	private function statusRequest(): array {
		return array(
			'action' => 'ctdflow_plan_status',
			'nonce'  => wp_create_nonce( 'ctdflow_routes' ),
			'date'   => $this->tomorrow,
		);
	}

	private function planBody(): array {
		foreach ( $this->ctd->requests() as $request ) {
			if ( 'POST' === $request['method'] && '/v1/woocommerce/plans' === $request['path'] ) {
				return $request['body'];
			}
		}

		$this->fail( 'No plan was sent.' );
	}

	private function connect(): void {
		update_option( 'ctdflow_tokens', array( 'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_at' => time() + 3600 ), false );
	}

	/**
	 * Registers the account answer, a free account by default.
	 *
	 * @param array $overrides Fields to change.
	 */
	private function account( array $overrides = array() ): void {
		$this->ctd->respond(
			'GET',
			'/v1/woocommerce/account',
			200,
			array_merge(
				array(
					'organization'        => 'Bakkerij Jansen',
					'billing_status'      => 'free',
					'paid'                => false,
					'trial_available'     => true,
					'free_tier'           => array( 'max_vehicles' => 1, 'max_stops' => 25, 'remaining_stops' => 25 ),
					'supported_countries' => array( 'NL', 'BE' ),
					'pricing_url'         => 'https://ctdflow.com/pricing',
				),
				$overrides
			)
		);
	}

	/**
	 * @param array $settings Stored settings, merged over a depot in Amsterdam.
	 */
	private function settings( array $settings ): void {
		update_option(
			Settings::OPTION,
			$settings + array(
				'date_source' => 'status',
				'depot'       => array( 'street' => 'Depotstraat 1', 'postcode' => '1011 AB', 'city' => 'Amsterdam', 'country' => 'NL' ),
				'vehicles'    => array( $this->van( 'van-1', 'Van A' ) ),
			)
		);
	}

	private function van( string $id, string $name ): array {
		return array( 'id' => $id, 'name' => $name, 'available_from' => '08:00', 'available_until' => '17:00', 'capacity' => null, 'active' => true );
	}

	/**
	 * Processing orders in Amsterdam.
	 *
	 * @return WC_Order[]
	 */
	private function orders( int $count ): array {
		$orders = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$orders[] = $this->order();
		}

		return $orders;
	}

	/**
	 * A processing order in Amsterdam. Address keys such as _billing_city go through the setters, the rest is order meta.
	 *
	 * @param array<string, string> $meta Keys and values.
	 */
	private function order( array $meta = array() ): WC_Order {
		$order = wc_create_order();
		$meta  = $meta + array(
			'_billing_address_1' => 'Keizersgracht 1',
			'_billing_postcode'  => '1015 CJ',
			'_billing_city'      => 'Amsterdam',
			'_billing_country'   => 'NL',
		);

		foreach ( $meta as $key => $value ) {
			if ( 1 === preg_match( '/^_billing_(.+)$/', $key, $parts ) ) {
				$order->{'set_billing_' . $parts[1]}( $value );
			} else {
				$order->update_meta_data( $key, $value );
			}
		}

		$order->set_status( 'processing' );
		$order->save();

		return $order;
	}

	private function queued(): array {
		return array( 'plan_id' => 'opt_1', 'status' => 'queued', 'unplanned' => array(), 'free_tier' => null );
	}

	private function succeeded( WC_Order $planned, WC_Order $unplanned ): array {
		$planned->update_meta_data( 'delivery_date', $this->tomorrow );
		$planned->update_meta_data( 'delivery_time', '09:00 - 11:00' );
		$planned->save();
		$this->settings( array( 'date_source' => 'woo-delivery' ) );

		return array(
			'plan_id'       => 'opt_1',
			'status'        => 'succeeded',
			'delivery_date' => $this->tomorrow,
			'superseded_by' => null,
			'routes'        => array(
				array(
					'vehicle'          => 'Van A',
					'driver_link'      => 'https://api.ctdflow.com/r/token-1',
					'distance_meters'  => 62000,
					'duration_seconds' => 13200,
					'stops'            => array(
						array( 'reference' => (string) $planned->get_id(), 'position' => 1, 'arrival' => $this->tomorrow . 'T07:41:00+01:00', 'expected_at' => $this->tomorrow . 'T08:12:00+01:00', 'departure' => $this->tomorrow . 'T08:15:00+01:00', 'delivered_at' => null ),
					),
				),
			),
			'unplanned'     => array( array( 'reference' => (string) $unplanned->get_id(), 'reason' => 'address_not_found' ) ),
			'error'         => null,
			'free_tier'     => null,
		);
	}
}
