<?php

namespace ConnectTheDots\Tests;

use ConnectTheDots\Account;
use ConnectTheDots\PlanningNotPossible;
use ConnectTheDots\PlanStore;
use ConnectTheDots\Planner;
use ConnectTheDots\Settings;
use ConnectTheDots\Tests\Support\FakeCtd;
use ConnectTheDots\Tests\Support\TestCase;
use WC_Order;

class PlannerTest extends TestCase {

	private FakeCtd $ctd;

	private string $day = '2026-12-24';

	public function set_up(): void {
		parent::set_up();

		$this->ctd = FakeCtd::install();
		update_option( 'ctdflow_tokens', array( 'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_at' => time() + 3600 ), false );
		update_option( 'timezone_string', 'Europe/Amsterdam' );
		update_option(
			Settings::OPTION,
			array(
				'date_source' => 'status',
				'depot'       => array( 'street' => 'Depotstraat 1', 'postcode' => '1011 AB', 'city' => 'Amsterdam', 'country' => 'NL' ),
				'vehicles'    => array(
					array( 'id' => 'van-a', 'name' => 'Van A', 'available_from' => '08:00', 'available_until' => '17:00', 'capacity' => null, 'active' => true ),
					array( 'id' => 'van-b', 'name' => 'Van B', 'available_from' => '08:00', 'available_until' => '17:00', 'capacity' => null, 'active' => true ),
					array( 'id' => 'van-c', 'name' => 'Van C', 'available_from' => '08:00', 'available_until' => '17:00', 'capacity' => null, 'active' => false ),
				),
			)
		);
	}

	public function test_a_queued_plan_is_stored_as_pending_and_keeps_the_current_plan(): void {
		$order = $this->order();
		( new PlanStore() )->put( $this->day, 'opt_0' );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		$result = Planner::create()->plan( $this->day, array( $order->get_id() ), array( 'van-a' ), 'manual' );

		$this->assertSame( 'opt_1', $result['plan_id'] );
		$this->assertSame( 'opt_1', ( new PlanStore() )->pending( $this->day ) );
		$this->assertSame( 'opt_0', ( new PlanStore() )->get( $this->day ), 'A plan that did not succeed yet replaced the current plan.' );

		$body = $this->ctd->requests()[0]['body'];

		$this->assertSame( 'manual', $body['trigger'] );
		$this->assertSame( array( (string) $order->get_id() ), array_column( $body['stops'], 'reference' ) );
		$this->assertSame( array( 'Van A' ), array_column( $body['vehicles'], 'name' ) );
	}

	public function test_only_the_chosen_orders_and_active_chosen_vans_are_sent(): void {
		$chosen = $this->order();
		$this->order();
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		Planner::create()->plan( $this->day, array( $chosen->get_id() ), array( 'van-a', 'van-b', 'van-c' ), 'manual' );

		$body = $this->ctd->requests()[0]['body'];

		$this->assertSame( array( (string) $chosen->get_id() ), array_column( $body['stops'], 'reference' ) );
		$this->assertSame( array( 'Van A', 'Van B' ), array_column( $body['vehicles'], 'name' ) );
	}

	public function test_no_plannable_orders_is_refused_without_a_request(): void {
		$without_address = wc_create_order();
		$without_address->set_status( 'processing' );
		$without_address->save();

		$this->assertRefused(
			'no orders to plan',
			fn () => Planner::create()->plan( $this->day, array( $without_address->get_id() ), array( 'van-a' ), 'manual' )
		);
	}

	public function test_no_active_van_is_refused_without_a_request(): void {
		$order = $this->order();

		$this->assertRefused(
			'active van',
			fn () => Planner::create()->plan( $this->day, array( $order->get_id() ), array( 'van-c' ), 'manual' )
		);
	}

	public function test_an_empty_depot_address_is_refused_without_a_request(): void {
		$settings          = get_option( Settings::OPTION );
		$settings['depot'] = array( 'street' => '', 'postcode' => '', 'city' => '', 'country' => '' );
		update_option( Settings::OPTION, $settings );
		update_option( 'woocommerce_store_address', '' );
		update_option( 'woocommerce_store_postcode', '' );
		update_option( 'woocommerce_store_city', '' );
		update_option( 'woocommerce_default_country', 'NL' );
		$order = $this->order();

		$this->assertRefused(
			'depot',
			fn () => Planner::create()->plan( $this->day, array( $order->get_id() ), array( 'van-a' ), 'manual' )
		);
	}

	public function test_an_empty_depot_country_is_refused_without_a_request(): void {
		$settings          = get_option( Settings::OPTION );
		$settings['depot'] = array( 'street' => 'Depotstraat 1', 'postcode' => '1011 AB', 'city' => 'Amsterdam', 'country' => '' );
		update_option( Settings::OPTION, $settings );
		$order = $this->order();

		$this->assertRefused(
			'depot',
			fn () => Planner::create()->plan( $this->day, array( $order->get_id() ), array( 'van-a' ), 'manual' )
		);
	}

	public function test_a_site_without_a_city_timezone_is_refused_without_a_request(): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 1 );
		$order = $this->order();

		$this->assertRefused(
			'timezone',
			fn () => Planner::create()->plan( $this->day, array( $order->get_id() ), array( 'van-a' ), 'manual' )
		);
	}

	public function test_planning_forgets_the_cached_account_of_the_day(): void {
		$order = $this->order();
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 200, array( 'organization' => 'Bakkerij', 'billing_status' => 'free' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		( new Account() )->fetch( $this->day );
		Planner::create()->plan( $this->day, array( $order->get_id() ), array( 'van-a' ), 'manual' );
		( new Account() )->fetch( $this->day );

		$this->assertSame( array( 'GET', 'POST', 'GET' ), array_column( $this->ctd->requests(), 'method' ) );
	}

	public function test_first_orders_sorts_by_time_window_start_then_order_number(): void {
		$this->useWooDelivery();
		$no_window = $this->order( array( 'delivery_date' => $this->day ) );
		$late      = $this->order( array( 'delivery_date' => $this->day, 'delivery_time' => '13:00 - 15:00' ) );
		$early_1   = $this->order( array( 'delivery_date' => $this->day, 'delivery_time' => '09:00 - 11:00' ) );
		$early_2   = $this->order( array( 'delivery_date' => $this->day, 'delivery_time' => '09:00 - 10:00' ) );
		$ids       = array( $no_window->get_id(), $late->get_id(), $early_1->get_id(), $early_2->get_id() );

		$this->assertSame(
			array( $early_1->get_id(), $early_2->get_id(), $late->get_id() ),
			Planner::create()->firstOrders( $this->day, $ids, 3 )
		);
	}

	public function test_the_account_is_fetched_with_the_day_and_site_and_cached_for_a_minute(): void {
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 200, array( 'organization' => 'Bakkerij', 'billing_status' => 'free' ) );

		$first  = ( new Account() )->fetch( $this->day );
		$second = ( new Account() )->fetch( $this->day );

		$this->assertSame( 'Bakkerij', $first['organization'] );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->ctd->requests() );
		$this->assertSame( array( 'delivery_date' => $this->day, 'site_url' => home_url() ), $this->ctd->requests()[0]['query'] );
	}

	public function test_the_free_tier_of_an_account(): void {
		$tier = array( 'max_vehicles' => 1, 'max_stops' => 25, 'remaining_stops' => 20 );

		$this->assertSame( $tier, Account::freeTier( array( 'paid' => false, 'free_tier' => $tier ) ) );
		$this->assertNull( Account::freeTier( array( 'paid' => true, 'free_tier' => null ) ) );
		$this->assertNull( Account::freeTier( array() ) );
	}

	public function test_the_plan_store_keeps_the_last_30_days_without_autoload(): void {
		global $wpdb;

		$store = new PlanStore();
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$old   = $today->modify( '-31 days' )->format( 'Y-m-d' );
		$kept  = $today->modify( '-30 days' )->format( 'Y-m-d' );

		$store->put( $old, 'opt_old' );
		$store->put( $kept, 'opt_kept' );
		$store->put( $today->format( 'Y-m-d' ), 'opt_today' );

		$this->assertNull( $store->get( $old ) );
		$this->assertSame( 'opt_kept', $store->get( $kept ) );
		$this->assertSame( 'opt_today', $store->get( $today->format( 'Y-m-d' ) ) );
		$this->assertNull( $store->get( '2020-01-01' ) );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'ctdflow_plans' ) );

		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_the_plan_store_keeps_the_pending_plan_apart_and_lists_the_current_plans_from_a_day(): void {
		$store = new PlanStore();
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$day   = static fn ( int $offset ): string => $today->modify( $offset . ' days' )->format( 'Y-m-d' );

		$store->put( $day( -2 ), 'opt_a' );
		$store->put( $day( -1 ), 'opt_b' );
		$store->put( $day( 3 ), 'opt_c' );
		$store->putPending( $day( 3 ), 'opt_d' );

		$this->assertSame( 'opt_c', $store->get( $day( 3 ) ) );
		$this->assertSame( 'opt_d', $store->pending( $day( 3 ) ) );
		$this->assertNull( $store->pending( $day( -1 ) ) );
		$this->assertSame( array( $day( -1 ) => 'opt_b', $day( 3 ) => 'opt_c' ), $store->currentFrom( $day( -1 ) ) );

		$store->forgetPending( $day( 3 ) );

		$this->assertNull( $store->pending( $day( 3 ) ) );
		$this->assertSame( 'opt_c', $store->get( $day( 3 ) ) );
	}

	private function assertRefused( string $expected, callable $plan ): void {
		try {
			$plan();
			$this->fail( 'The plan was not refused.' );
		} catch ( PlanningNotPossible $refused ) {
			$this->assertStringContainsStringIgnoringCase( $expected, $refused->getMessage() );
		}

		$this->assertSame( array(), $this->ctd->requests() );
		$this->assertNull( ( new PlanStore() )->get( $this->day ) );
	}

	private function useWooDelivery(): void {
		$settings                = get_option( Settings::OPTION );
		$settings['date_source'] = 'woo-delivery';
		update_option( Settings::OPTION, $settings );
	}

	/**
	 * A processing order in Amsterdam.
	 *
	 * @param array<string, string> $meta Order meta.
	 */
	private function order( array $meta = array() ): WC_Order {
		$order = wc_create_order();
		$order->set_billing_address_1( 'Keizersgracht 1' );
		$order->set_billing_postcode( '1015 CJ' );
		$order->set_billing_city( 'Amsterdam' );
		$order->set_billing_country( 'NL' );

		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		$order->set_status( 'processing' );
		$order->save();

		return $order;
	}

	private function queued(): array {
		return array(
			'plan_id'   => 'opt_1',
			'status'    => 'queued',
			'unplanned' => array(),
			'free_tier' => null,
		);
	}
}
