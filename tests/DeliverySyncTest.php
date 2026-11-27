<?php

namespace ConnectTheDots\Tests;

use ConnectTheDots\DeliverySync;
use ConnectTheDots\PlanStore;
use ConnectTheDots\Settings;
use ConnectTheDots\Tests\Support\FakeCtd;
use ConnectTheDots\Tests\Support\TestCase;
use ConnectTheDots\Tests\Support\UsesOrderStorage;
use WC_Order;

class DeliverySyncTest extends TestCase {

	use UsesOrderStorage;

	private FakeCtd $ctd;

	public function set_up(): void {
		parent::set_up();

		update_option( 'timezone_string', 'Europe/Amsterdam' );
		update_option( Settings::OPTION, array() );
		update_option( 'ctdflow_plans', array() );
		delete_transient( 'ctdflow_delivery_sync_throttle' );
		$this->ctd = FakeCtd::install();
	}

	private function connect(): void {
		update_option( 'ctdflow_tokens', array( 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => time() + 3600 ), false );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_it_stores_the_delivery_time_and_adds_the_note_once_over_two_runs( bool $hpos ): void {
		$this->useHpos( $hpos );
		$this->connect();
		$order = $this->order( 'opt_1' );
		$this->planFor( $this->today(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', array( $order->get_id() => '2026-12-24T08:05:00+00:00' ) ) );

		DeliverySync::create()->run();
		DeliverySync::create()->run();

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( '2026-12-24T08:05:00+00:00', $order->get_meta( '_ctdflow_delivered_at' ) );
		$this->assertSame( array( 'Delivered at 09:05' ), $this->notes( $order ) );
	}

	public function test_the_first_delivery_time_is_kept(): void {
		$this->connect();
		$order = $this->order( 'opt_1' );
		$this->planFor( $this->today(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', array( $order->get_id() => '2026-12-24T08:05:00+00:00' ) ) );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', array( $order->get_id() => '2026-12-24T10:00:00+00:00' ) ) );

		DeliverySync::create()->run();
		DeliverySync::create()->run();

		$this->assertSame( '2026-12-24T08:05:00+00:00', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_delivered_at' ) );
	}

	public function test_it_adds_no_note_when_that_is_off(): void {
		update_option( Settings::OPTION, array( 'add_order_note' => false ) );
		$this->connect();
		$order = $this->order( 'opt_1' );
		$this->planFor( $this->today(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', array( $order->get_id() => '2026-12-24T08:05:00+00:00' ) ) );

		DeliverySync::create()->run();

		$order = wc_get_order( $order->get_id() );

		$this->assertNotSame( '', $order->get_meta( '_ctdflow_delivered_at' ) );
		$this->assertSame( array(), $this->notes( $order ) );
	}

	public function test_stops_that_are_not_delivered_stay_untouched(): void {
		$this->connect();
		$order = $this->order( 'opt_1' );
		$this->planFor( $this->today(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', array( $order->get_id() => null ) ) );

		DeliverySync::create()->run();

		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_delivered_at' ) );
	}

	public function test_a_delivery_the_driver_undid_is_removed_with_a_note(): void {
		$this->connect();
		$order = $this->order( 'opt_1' );
		$this->planFor( $this->today(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', array( $order->get_id() => '2026-12-24T08:05:00+00:00' ) ) );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', array( $order->get_id() => null ) ) );

		DeliverySync::create()->run();
		DeliverySync::create()->run();
		DeliverySync::create()->run();

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( '', $order->get_meta( '_ctdflow_delivered_at' ) );
		$this->assertSame( array( 'The driver undid the delivery', 'Delivered at 09:05' ), $this->notes( $order ) );
	}

	public function test_an_order_of_another_plan_keeps_its_delivery_when_this_plan_has_no_check_off(): void {
		$this->connect();
		$order = $this->orderWithMeta( array( '_ctdflow_plan_id' => 'opt_other', '_ctdflow_delivered_at' => '2026-12-24T08:05:00+00:00' ) );
		$this->planFor( $this->today(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', array( $order->get_id() => null ) ) );

		DeliverySync::create()->run();

		$this->assertSame( '2026-12-24T08:05:00+00:00', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_delivered_at' ) );
	}

	public function test_it_reads_yesterday_and_today_and_skips_days_without_a_plan(): void {
		$this->connect();
		$yesterday = ( new \DateTimeImmutable( 'yesterday', wp_timezone() ) )->format( 'Y-m-d' );
		$order     = $this->order( 'opt_y' );
		$this->planFor( $yesterday, 'opt_y' );
		$this->planFor( ( new \DateTimeImmutable( '-5 days', wp_timezone() ) )->format( 'Y-m-d' ), 'opt_old' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_y', 200, $this->plan( 'opt_y', array( $order->get_id() => '2026-12-24T08:05:00+00:00' ) ) );

		DeliverySync::create()->run();

		$this->assertSame( array( 'GET /v1/woocommerce/plans/opt_y' ), $this->requested() );
		$this->assertNotSame( '', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_delivered_at' ) );
	}

	public function test_it_skips_a_plan_that_answers_404_and_goes_on_with_the_other_day(): void {
		$this->connect();
		$order = $this->order( 'opt_y' );
		$this->planFor( $this->today(), 'opt_gone' );
		$this->planFor( ( new \DateTimeImmutable( 'yesterday', wp_timezone() ) )->format( 'Y-m-d' ), 'opt_y' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_gone', 404, array( 'error' => array( 'type' => 'not_found', 'code' => 'plan_not_found', 'message' => 'Plan not found' ) ) );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_y', 200, $this->plan( 'opt_y', array( $order->get_id() => '2026-12-24T08:05:00+00:00' ) ) );

		DeliverySync::create()->run();

		$this->assertNotSame( '', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_delivered_at' ) );
	}

	public function test_a_network_failure_ends_the_run_quietly(): void {
		$this->connect();
		$order = $this->order( 'opt_1' );
		$this->planFor( $this->today(), 'opt_1' );
		$this->ctd->fail( 'GET', '/v1/woocommerce/plans/opt_1', 'timeout' );

		DeliverySync::create()->run();

		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_delivered_at' ) );
	}

	public function test_it_skips_a_superseded_plan(): void {
		$this->connect();
		$order = $this->order( 'opt_1' );
		$this->planFor( $this->today(), 'opt_1' );
		$plan                  = $this->plan( 'opt_1', array( $order->get_id() => '2026-12-24T08:05:00+00:00' ) );
		$plan['superseded_by'] = 'opt_2';
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $plan );

		DeliverySync::create()->run();

		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_delivered_at' ) );
	}

	public function test_an_order_of_another_plan_gets_no_delivery_and_no_note(): void {
		$this->connect();
		$order = $this->order( 'opt_other' );
		$this->planFor( $this->today(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', array( $order->get_id() => '2026-12-24T08:05:00+00:00' ) ) );

		DeliverySync::create()->run();

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( '', $order->get_meta( '_ctdflow_delivered_at' ) );
		$this->assertSame( array(), $this->notes( $order ) );
	}

	public function test_a_delivery_carried_over_to_a_new_plan_is_kept_and_noted_once(): void {
		$this->connect();
		update_option( Settings::OPTION, array() );
		$order = wc_create_order();
		$order->save();
		$applier = new \ConnectTheDots\ResultApplier( new Settings() );

		$applier->apply( $this->plan( 'opt_1', array( $order->get_id() => null ) ) );
		$this->planFor( $this->today(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', array( $order->get_id() => '2026-12-24T07:00:00+00:00' ) ) );
		DeliverySync::create()->run();

		$applier->apply( $this->plan( 'opt_2', array( $order->get_id() => '2026-12-24T07:00:00+00:00' ) ) );
		$this->planFor( $this->today(), 'opt_2' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_2', 200, $this->plan( 'opt_2', array( $order->get_id() => '2026-12-24T07:00:00+00:00' ) ) );
		DeliverySync::create()->run();

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 'opt_2', $order->get_meta( '_ctdflow_plan_id' ) );
		$this->assertSame( '2026-12-24T07:00:00+00:00', $order->get_meta( '_ctdflow_delivered_at' ) );
		$this->assertSame( array( 'Delivered at 08:00' ), $this->notes( $order ) );
	}

	public function test_it_syncs_every_current_plan_from_yesterday_on(): void {
		$this->connect();
		$day = fn ( int $offset ): string => ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( $offset . ' days' )->format( 'Y-m-d' );
		$tomorrow = $this->order( 'opt_tomorrow' );
		$this->planFor( $day( -2 ), 'opt_old' );
		$this->planFor( $day( -1 ), 'opt_yesterday' );
		$this->planFor( $day( 1 ), 'opt_tomorrow' );
		$this->planFor( $day( 5 ), 'opt_later' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_yesterday', 200, $this->plan( 'opt_yesterday', array() ) );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_tomorrow', 200, $this->plan( 'opt_tomorrow', array( $tomorrow->get_id() => '2026-12-24T08:05:00+00:00' ) ) );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_later', 200, $this->plan( 'opt_later', array() ) );

		DeliverySync::create()->run();

		$this->assertSame(
			array( 'GET /v1/woocommerce/plans/opt_yesterday', 'GET /v1/woocommerce/plans/opt_tomorrow', 'GET /v1/woocommerce/plans/opt_later' ),
			$this->requested()
		);
		$this->assertSame( '2026-12-24T08:05:00+00:00', wc_get_order( $tomorrow->get_id() )->get_meta( '_ctdflow_delivered_at' ) );
	}

	public function test_without_a_connection_it_makes_no_request(): void {
		$this->planFor( $this->today(), 'opt_1' );

		DeliverySync::create()->run();

		$this->assertSame( array(), $this->ctd->requests() );
	}

	public function test_running_throttled_syncs_once_per_five_minutes(): void {
		$this->connect();
		$this->planFor( $this->today(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', array() ) );

		DeliverySync::create()->runThrottled();
		DeliverySync::create()->runThrottled();

		$this->assertCount( 1, $this->ctd->requests() );

		delete_transient( 'ctdflow_delivery_sync_throttle' );
		DeliverySync::create()->runThrottled();

		$this->assertCount( 2, $this->ctd->requests() );
	}

	public function test_the_event_is_scheduled_once_and_cleared(): void {
		DeliverySync::unschedule();

		DeliverySync::schedule();
		$first = as_next_scheduled_action( 'ctdflow_sync_deliveries', null, 'connect-the-dots' );
		DeliverySync::schedule();

		$actions = as_get_scheduled_actions( array( 'hook' => 'ctdflow_sync_deliveries', 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ) );

		$this->assertIsInt( $first );
		$this->assertSame( $first, as_next_scheduled_action( 'ctdflow_sync_deliveries', null, 'connect-the-dots' ) );
		$this->assertCount( 1, $actions );
		$this->assertSame( HOUR_IN_SECONDS, (int) reset( $actions )->get_schedule()->get_recurrence() );
		$this->assertSame( 'connect-the-dots', reset( $actions )->get_group() );

		DeliverySync::unschedule();

		$this->assertFalse( as_next_scheduled_action( 'ctdflow_sync_deliveries', null, 'connect-the-dots' ) );
	}

	public function test_the_plugin_hooks_the_event_and_schedules_on_init(): void {
		remove_all_actions( 'ctdflow_sync_deliveries' );
		remove_all_actions( 'init' );

		\ConnectTheDots\Plugin::instance()->registerHooks( true );

		$this->assertNotFalse( has_action( 'ctdflow_sync_deliveries' ) );

		DeliverySync::unschedule();
		do_action( 'init' );

		$this->assertNotFalse( as_next_scheduled_action( 'ctdflow_sync_deliveries', null, 'connect-the-dots' ) );
	}

	private function today(): string {
		return ( new \DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
	}

	private function planFor( string $date, string $planId ): void {
		( new PlanStore() )->put( $date, $planId );
	}

	private function order( string $planId ): WC_Order {
		return $this->orderWithMeta( array( '_ctdflow_plan_id' => $planId ) );
	}

	/**
	 * @param array<int, string|null> $deliveries Order id to delivered_at.
	 */
	private function plan( string $planId, array $deliveries ): array {
		$stops = array();
		$index = 0;

		foreach ( $deliveries as $orderId => $deliveredAt ) {
			$stops[] = array(
				'reference'    => (string) $orderId,
				'position'     => ++$index,
				'arrival'      => '2026-12-24T08:12:00+01:00',
				'departure'    => '2026-12-24T08:15:00+01:00',
				'delivered_at' => $deliveredAt,
			);
		}

		return array(
			'plan_id'       => $planId,
			'status'        => 'succeeded',
			'delivery_date' => '2026-12-24',
			'superseded_by' => null,
			'routes'        => array( array( 'vehicle' => 'Van A', 'stops' => $stops ) ),
			'unplanned'     => array(),
		);
	}

	/**
	 * @return string[]
	 */
	private function requested(): array {
		return array_map( static fn ( array $request ): string => $request['method'] . ' ' . $request['path'], $this->ctd->requests() );
	}

	/**
	 * @return string[]
	 */
	private function notes( WC_Order $order ): array {
		$texts = array_map( static fn ( $note ) => $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		return array_values( array_filter( $texts, static fn ( string $text ): bool => str_starts_with( $text, 'Delivered' ) || str_starts_with( $text, 'The driver undid' ) ) );
	}
}
