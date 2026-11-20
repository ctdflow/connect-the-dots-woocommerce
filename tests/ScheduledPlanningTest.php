<?php

namespace ConnectTheDots\Tests;

use ConnectTheDots\Account;
use ConnectTheDots\Admin\SettingsPage;
use ConnectTheDots\Connection;
use ConnectTheDots\PlanStore;
use ConnectTheDots\Plugin;
use ConnectTheDots\ScheduledPlanning;
use ConnectTheDots\Settings;
use ConnectTheDots\Tests\Support\FakeCtd;
use ConnectTheDots\Tests\Support\TestCase;
use RuntimeException;
use WC_Order;

class ScheduledPlanningTest extends TestCase {

	private FakeCtd $ctd;

	/**
	 * Mails captured by pre_wp_mail.
	 *
	 * @var array<int, array{to: mixed, subject: string, message: string}>
	 */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->ctd = FakeCtd::install();
		update_option( 'ctdflow_tokens', array( 'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_at' => time() + 3600 ), false );
		update_option( 'timezone_string', 'Europe/Amsterdam' );
		update_option( 'admin_email', 'owner@example.com' );
		delete_option( 'ctdflow_last_scheduled_run' );
		delete_option( 'ctdflow_scheduled_lock' );
		update_option( 'ctdflow_plans', array() );
		$this->settings( array( 'schedule_enabled' => true, 'schedule_time' => '18:00', 'schedule_day_offset' => 1 ) );
		ScheduledPlanning::unschedule();

		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) {
				// WooCommerce mails about the orders the tests create. Only ours are of interest.
				if ( ! str_contains( $atts['subject'], 'Connect the Dots' ) ) {
					return $short_circuit;
				}

				$this->mails[] = array(
					'to'      => $atts['to'],
					'subject' => $atts['subject'],
					'message' => $atts['message'],
				);

				return true;
			},
			10,
			2
		);
	}

	public function test_the_event_is_scheduled_at_the_chosen_local_time(): void {
		$this->settings( array( 'schedule_enabled' => true, 'schedule_time' => '18:30' ) );

		ScheduledPlanning::schedule();

		$expected = $this->nextLocal( '18:30', 'Europe/Amsterdam' );

		$this->assertSame( $expected, ScheduledPlanning::nextRun() );
		$this->assertSame( '18:30', wp_date( 'H:i', $expected, new \DateTimeZone( 'Europe/Amsterdam' ) ) );
	}

	public function test_the_time_follows_the_site_timezone(): void {
		update_option( 'timezone_string', 'America/New_York' );
		$this->settings( array( 'schedule_enabled' => true, 'schedule_time' => '06:15' ) );

		ScheduledPlanning::schedule();

		$next = ScheduledPlanning::nextRun();

		$this->assertSame( $this->nextLocal( '06:15', 'America/New_York' ), $next );
		$this->assertSame( '06:15', wp_date( 'H:i', $next, new \DateTimeZone( 'America/New_York' ) ) );
	}

	public function test_a_time_that_has_passed_today_is_scheduled_tomorrow(): void {
		$this->settings( array( 'schedule_enabled' => true, 'schedule_time' => '00:00' ) );

		ScheduledPlanning::schedule();

		$next = ScheduledPlanning::nextRun();

		$this->assertGreaterThan( time(), $next );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS, $next );
		$this->assertSame( '00:00', wp_date( 'H:i', $next ) );
	}

	public function test_scheduling_replaces_the_previous_event(): void {
		$this->settings( array( 'schedule_enabled' => true, 'schedule_time' => '18:30' ) );
		ScheduledPlanning::schedule();
		$this->settings( array( 'schedule_enabled' => true, 'schedule_time' => '19:45' ) );
		ScheduledPlanning::schedule();

		$this->assertSame( $this->nextLocal( '19:45', 'Europe/Amsterdam' ), ScheduledPlanning::nextRun() );
		$this->assertCount( 1, $this->pendingActions( 'ctdflow_scheduled_planning' ) );
	}

	public function test_changing_the_settings_reschedules_and_turning_it_off_removes_the_event(): void {
		remove_all_actions( 'update_option_ctdflow_settings' );
		remove_all_actions( 'add_option_ctdflow_settings' );
		Plugin::instance()->registerHooks( true );

		update_option( Settings::OPTION, array( 'schedule_enabled' => true, 'schedule_time' => '17:00' ) );

		$this->assertSame( $this->nextLocal( '17:00', 'Europe/Amsterdam' ), ScheduledPlanning::nextRun() );

		update_option( Settings::OPTION, array( 'schedule_enabled' => true, 'schedule_time' => '20:10' ) );

		$this->assertSame( $this->nextLocal( '20:10', 'Europe/Amsterdam' ), ScheduledPlanning::nextRun() );

		update_option( Settings::OPTION, array( 'schedule_enabled' => false, 'schedule_time' => '20:10' ) );

		$this->assertFalse( ScheduledPlanning::nextRun() );
	}

	public function test_init_schedules_a_missing_event_and_deactivation_removes_everything(): void {
		remove_all_actions( 'init' );
		Plugin::instance()->registerHooks( true );

		do_action( 'init' );

		$this->assertNotFalse( ScheduledPlanning::nextRun() );

		as_schedule_single_action( time() + 60, 'ctdflow_apply_scheduled', array( '2026-12-24', 1 ), 'connect-the-dots' );
		Plugin::deactivate();

		$this->assertFalse( ScheduledPlanning::nextRun() );
		$this->assertFalse( as_next_scheduled_action( 'ctdflow_apply_scheduled', array( '2026-12-24', 1 ), 'connect-the-dots' ) );
	}

	public function test_the_plugin_hooks_both_events(): void {
		remove_all_actions( 'ctdflow_scheduled_planning' );
		remove_all_actions( 'ctdflow_apply_scheduled' );

		Plugin::instance()->registerHooks( true );

		$this->assertNotFalse( has_action( 'ctdflow_scheduled_planning' ) );
		$this->assertNotFalse( has_action( 'ctdflow_apply_scheduled' ) );
	}

	public function test_run_plans_the_day_with_trigger_scheduled_and_all_active_vans(): void {
		$order = $this->order();
		$this->account( array( 'NL' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		ScheduledPlanning::create()->run();

		$posts = $this->posts();

		$this->assertCount( 1, $posts );
		$this->assertSame( 'scheduled', $posts[0]['body']['trigger'] );
		$this->assertSame( $this->tomorrow(), $posts[0]['body']['delivery_date'] );
		$this->assertSame( array( 'Van A', 'Van B' ), array_column( $posts[0]['body']['vehicles'], 'name' ) );
		$this->assertSame( array( (string) $order->get_id() ), array_column( $posts[0]['body']['stops'], 'reference' ) );
		$this->assertSame( 'opt_1', ( new PlanStore() )->pending( $this->tomorrow() ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_run_uses_the_day_offset_of_the_settings(): void {
		$this->settings( array( 'schedule_enabled' => true, 'schedule_day_offset' => 3 ) );
		$this->order();
		$this->account( array( 'NL' ), ( new \DateTimeImmutable( '+3 days', wp_timezone() ) )->format( 'Y-m-d' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		ScheduledPlanning::create()->run();

		$this->assertSame( ( new \DateTimeImmutable( '+3 days', wp_timezone() ) )->format( 'Y-m-d' ), $this->posts()[0]['body']['delivery_date'] );
	}

	public function test_orders_for_countries_the_account_does_not_support_are_left_out(): void {
		$dutch   = $this->order();
		$belgian = $this->order( 'BE' );
		$this->account( array( 'NL' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		ScheduledPlanning::create()->run();

		$this->assertSame( array( (string) $dutch->get_id() ), array_column( $this->posts()[0]['body']['stops'], 'reference' ) );

		$this->assertNotContains( (string) $belgian->get_id(), array_column( $this->posts()[0]['body']['stops'], 'reference' ) );
	}

	public function test_run_schedules_the_apply_event_two_minutes_later_and_stores_the_outcome(): void {
		$this->order();
		$this->account( array( 'NL' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		$before = time();
		ScheduledPlanning::create()->run();

		$next = as_next_scheduled_action( 'ctdflow_apply_scheduled', array( $this->tomorrow(), 1 ), 'connect-the-dots' );

		$this->assertNotFalse( $next );
		$this->assertEqualsWithDelta( $before + 120, $next, 5 );

		$last = ScheduledPlanning::lastRun();

		$this->assertSame( 'succeeded', $last['outcome'] );
		$this->assertSame( $this->tomorrow(), $last['date'] );
		$this->assertEqualsWithDelta( $before, $last['time'], 5 );
		$this->assertFalse( $this->autoload( 'ctdflow_last_scheduled_run' ) );
	}

	public function test_a_run_during_the_lock_sends_nothing(): void {
		$this->order();
		add_option( 'ctdflow_scheduled_lock', time(), '', false );

		ScheduledPlanning::create()->run();

		$this->assertSame( array(), $this->ctd->requests() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_two_runs_in_a_row_plan_once_because_the_lock_stays_for_five_minutes(): void {
		$this->order();
		$this->account( array( 'NL' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		ScheduledPlanning::create()->run();
		ScheduledPlanning::create()->run();

		$this->assertCount( 1, $this->posts() );
		$this->assertNotFalse( get_option( 'ctdflow_scheduled_lock' ) );
		$this->assertFalse( $this->autoload( 'ctdflow_scheduled_lock' ) );
	}

	public function test_a_lock_another_request_just_took_is_respected_even_when_the_cache_missed_it(): void {
		global $wpdb;

		$this->order();
		$this->assertFalse( get_option( 'ctdflow_scheduled_lock' ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'ctdflow_scheduled_lock', (string) time() ) );

		ScheduledPlanning::create()->run();

		$this->assertSame( array(), $this->ctd->requests() );
	}

	public function test_an_old_lock_is_taken_over_once(): void {
		$this->order();
		add_option( 'ctdflow_scheduled_lock', time() - 301, '', false );
		$this->account( array( 'NL' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		ScheduledPlanning::create()->run();
		ScheduledPlanning::create()->run();

		$this->assertCount( 1, $this->posts() );
		$this->assertEqualsWithDelta( time(), (int) get_option( 'ctdflow_scheduled_lock' ), 5 );
		$this->assertFalse( $this->autoload( 'ctdflow_scheduled_lock' ) );
	}

	public function test_an_old_lock_is_taken_over(): void {
		$this->order();
		add_option( 'ctdflow_scheduled_lock', time() - 301, '', false );
		$this->account( array( 'NL' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		ScheduledPlanning::create()->run();

		$this->assertCount( 1, $this->posts() );
	}

	public function test_the_lock_is_taken_while_the_run_calls_the_api(): void {
		$this->order();
		$this->account( array( 'NL' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );
		$seen = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( &$seen ) {
				$seen = $seen ?? get_option( 'ctdflow_scheduled_lock' );

				return $preempt;
			},
			5
		);

		ScheduledPlanning::create()->run();

		$this->assertNotFalse( $seen );
	}

	public function test_a_free_account_gets_one_mail_with_the_reason_and_a_link_to_the_screen(): void {
		$this->order();
		$this->account( array( 'NL' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 402, array( 'error' => array( 'type' => 'payment_required', 'code' => 'scheduled_needs_paid_plan', 'message' => 'Scheduled planning needs a paid plan.' ) ) );

		ScheduledPlanning::create()->run();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'owner@example.com', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'This needs a paid Connect the Dots plan.', $this->mails[0]['message'] );
		$this->assertStringContainsString( admin_url( 'admin.php?page=ctdflow-delivery-routes&date=' . $this->tomorrow() ), $this->mails[0]['message'] );
		$this->assertSame( 'refused', ScheduledPlanning::lastRun()['outcome'] );
		$this->assertSame( 'scheduled_needs_paid_plan', ScheduledPlanning::lastRun()['code'] );
		$this->assertFalse( as_next_scheduled_action( 'ctdflow_apply_scheduled', array( $this->tomorrow(), 1 ), 'connect-the-dots' ) );
	}

	public function test_any_other_failure_mails_the_reason_too(): void {
		$this->order();
		$this->account( array( 'NL' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 503, array( 'error' => array( 'type' => 'unavailable', 'code' => 'routing_unavailable', 'message' => 'Routing is down.' ) ) );

		ScheduledPlanning::create()->run();

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Route planning is temporarily unavailable.', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'page=ctdflow-delivery-routes', $this->mails[0]['message'] );
		$this->assertSame( 'failed', ScheduledPlanning::lastRun()['outcome'] );
		$this->assertSame( 'routing_unavailable', ScheduledPlanning::lastRun()['code'] );
	}

	public function test_a_day_without_orders_sends_no_plan_and_no_mail(): void {
		$this->account( array( 'NL' ) );

		ScheduledPlanning::create()->run();

		$this->assertSame( array(), $this->posts() );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( 'no_orders', ScheduledPlanning::lastRun()['code'] );
	}

	public function test_a_day_that_was_planned_by_hand_is_not_planned_again(): void {
		$this->order();
		( new PlanStore() )->put( $this->tomorrow(), 'opt_by_hand' );

		ScheduledPlanning::create()->run();

		$this->assertSame( array(), $this->ctd->requests() );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( 'refused', ScheduledPlanning::lastRun()['outcome'] );
		$this->assertSame( 'already_planned', ScheduledPlanning::lastRun()['code'] );
		$this->assertSame( 'opt_by_hand', ( new PlanStore() )->get( $this->tomorrow() ) );
		$this->assertFalse( as_next_scheduled_action( 'ctdflow_apply_scheduled', array( $this->tomorrow(), 1 ), 'connect-the-dots' ) );
	}

	public function test_a_day_with_a_pending_plan_is_not_planned_again(): void {
		$this->order();
		( new PlanStore() )->putPending( $this->tomorrow(), 'opt_sent' );

		ScheduledPlanning::create()->run();

		$this->assertSame( array(), $this->ctd->requests() );
		$this->assertSame( 'already_planned', ScheduledPlanning::lastRun()['code'] );
		$this->assertSame( 'opt_sent', ( new PlanStore() )->pending( $this->tomorrow() ) );
	}

	public function test_a_plan_that_cannot_be_made_is_mailed(): void {
		$this->settings( array( 'vehicles' => array( array( 'id' => 'van-a', 'name' => 'Van A', 'available_from' => '08:00', 'available_until' => '17:00', 'capacity' => null, 'active' => false ) ) ) );
		$this->order();
		$this->account( array( 'NL' ) );

		ScheduledPlanning::create()->run();

		$this->assertSame( array(), $this->posts() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Choose at least one active van', $this->mails[0]['message'] );
	}

	public function test_an_unreachable_api_is_mailed_and_never_throws(): void {
		$this->order();
		$this->ctd->fail( 'GET', '/v1/woocommerce/account', 'timeout' );

		ScheduledPlanning::create()->run();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'network_error', ScheduledPlanning::lastRun()['code'] );
	}

	public function test_an_exception_never_escapes_the_cron_handler_and_releases_the_lock(): void {
		$this->order();
		add_filter(
			'pre_http_request',
			static function () {
				throw new RuntimeException( 'boom' );
			},
			5
		);

		ScheduledPlanning::create()->run();

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'boom', $this->mails[0]['message'] );
		$this->assertSame( 'failed', ScheduledPlanning::lastRun()['outcome'] );
		$this->assertSame( 'exception', ScheduledPlanning::lastRun()['code'] );
	}

	public function test_without_a_connection_the_admin_is_told_to_connect(): void {
		delete_option( 'ctdflow_tokens' );

		ScheduledPlanning::create()->run();

		$this->assertSame( array(), $this->ctd->requests() );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'not_connected', ScheduledPlanning::lastRun()['code'] );
	}

	public function test_a_repeated_failure_is_mailed_once(): void {
		$this->order();
		$this->account( array( 'NL' ) );
		$paid_plan_needed = array( 'error' => array( 'type' => 'payment_required', 'code' => 'scheduled_needs_paid_plan', 'message' => 'x' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 402, $paid_plan_needed );

		ScheduledPlanning::create()->run();
		delete_option( 'ctdflow_scheduled_lock' );
		ScheduledPlanning::create()->run();
		delete_option( 'ctdflow_scheduled_lock' );

		$this->assertCount( 1, $this->mails );

		// A different reason is mailed again.
		delete_option( 'ctdflow_tokens' );
		ScheduledPlanning::create()->run();

		$this->assertCount( 2, $this->mails );
	}

	public function test_not_connected_repeated_is_mailed_once(): void {
		delete_option( 'ctdflow_tokens' );

		ScheduledPlanning::create()->run();
		delete_option( 'ctdflow_scheduled_lock' );
		ScheduledPlanning::create()->run();

		$this->assertCount( 1, $this->mails );
	}

	public function test_a_run_that_starts_after_midnight_plans_the_day_of_the_event(): void {
		$this->order();
		$day = ( new \DateTimeImmutable( '+5 days', wp_timezone() ) )->format( 'Y-m-d' );
		$this->account( array( 'NL' ), $day );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		ScheduledPlanning::create()->handleEvent( $day );

		$this->assertSame( $day, $this->posts()[0]['body']['delivery_date'] );
	}

	public function test_the_event_carries_the_day_to_plan(): void {
		$this->settings( array( 'schedule_enabled' => true, 'schedule_time' => '18:30', 'schedule_day_offset' => 2 ) );

		ScheduledPlanning::schedule();

		$due      = ScheduledPlanning::nextRun();
		$expected = ( new \DateTimeImmutable( '@' . $due ) )->setTimezone( wp_timezone() )->modify( '+2 days' )->format( 'Y-m-d' );
		$actions  = $this->pendingActions( 'ctdflow_scheduled_planning' );

		$this->assertSame( array( $expected ), reset( $actions )->get_args() );
		$this->assertSame( 'connect-the-dots', reset( $actions )->get_group() );
	}

	public function test_saving_other_settings_does_not_move_a_run_that_is_due(): void {
		remove_all_actions( 'update_option_ctdflow_settings' );
		Plugin::instance()->registerHooks( true );
		ScheduledPlanning::unschedule();
		$due = time() - 180;
		as_schedule_single_action( $due, 'ctdflow_scheduled_planning', array( $this->tomorrow() ), 'connect-the-dots' );

		$this->settings( array( 'schedule_enabled' => true, 'schedule_time' => '18:00', 'stop_minutes' => 7 ) );

		$this->assertSame( $due, ScheduledPlanning::nextRun() );

		$this->settings( array( 'schedule_enabled' => true, 'schedule_time' => '18:05', 'stop_minutes' => 7 ) );

		$this->assertGreaterThan( $due, ScheduledPlanning::nextRun() );
	}

	public function test_nothing_runs_while_scheduling_is_off(): void {
		$this->settings( array( 'schedule_enabled' => false ) );
		$this->order();

		ScheduledPlanning::create()->handleEvent( $this->tomorrow() );
		ScheduledPlanning::create()->run();

		$this->assertSame( array(), $this->ctd->requests() );
		$this->assertFalse( ScheduledPlanning::nextRun() );
	}

	public function test_an_unpaid_shop_can_still_turn_scheduling_off(): void {
		$this->account( array( 'NL' ), $this->tomorrow(), false );

		$on = $this->settingsTab();

		$this->assertStringNotContainsString( 'disabled', $this->checkbox( $on ) );
		$this->assertStringContainsString( 'type="hidden" name="ctdflow_settings[schedule_enabled]"', $on );

		$this->settings( array( 'schedule_enabled' => false ) );

		$off = $this->settingsTab();

		$this->assertStringContainsString( 'disabled', $this->checkbox( $off ) );
		$this->assertStringNotContainsString( 'type="hidden" name="ctdflow_settings[schedule_enabled]"', $off );
	}

	public function test_the_cron_event_plans_the_next_day_again_first(): void {
		$this->order();
		$this->account( array( 'NL' ) );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 202, $this->queued() );

		ScheduledPlanning::create()->handleEvent();

		$this->assertSame( $this->nextLocal( '18:00', 'Europe/Amsterdam' ), ScheduledPlanning::nextRun() );
		$this->assertCount( 1, $this->posts() );
	}

	public function test_a_pending_plan_that_succeeded_becomes_current_and_is_applied(): void {
		$order = $this->order();
		( new PlanStore() )->put( $this->tomorrow(), 'opt_0' );
		( new PlanStore() )->putPending( $this->tomorrow(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', 'succeeded', $order ) );

		ScheduledPlanning::create()->apply( $this->tomorrow(), 1 );

		$this->assertSame( 'opt_1', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
		$this->assertSame( 'opt_1', ( new PlanStore() )->get( $this->tomorrow() ) );
		$this->assertNull( ( new PlanStore() )->pending( $this->tomorrow() ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_succeeded_plan_is_applied(): void {
		$order = $this->order();
		( new PlanStore() )->put( $this->tomorrow(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', 'succeeded', $order ) );

		ScheduledPlanning::create()->apply( $this->tomorrow(), 1 );

		$this->assertSame( 'opt_1', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
		$this->assertSame( array(), $this->mails );
		$this->assertFalse( as_next_scheduled_action( 'ctdflow_apply_scheduled', array( $this->tomorrow(), 2 ), 'connect-the-dots' ) );
	}

	public function test_a_superseded_plan_is_followed_and_the_newest_is_applied(): void {
		$order       = $this->order();
		$old         = $this->plan( 'opt_1', 'succeeded', $order );
		$old['superseded_by'] = 'opt_2';
		( new PlanStore() )->put( $this->tomorrow(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $old );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_2', 200, $this->plan( 'opt_2', 'succeeded', $order ) );

		ScheduledPlanning::create()->apply( $this->tomorrow(), 1 );

		$this->assertSame( 'opt_2', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
		$this->assertSame( 'opt_2', ( new PlanStore() )->get( $this->tomorrow() ) );
	}

	public function test_a_plan_that_is_still_running_is_checked_again_two_minutes_later(): void {
		$order = $this->order();
		( new PlanStore() )->putPending( $this->tomorrow(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', 'running', $order ) );

		$before = time();
		ScheduledPlanning::create()->apply( $this->tomorrow(), 2 );

		$next = as_next_scheduled_action( 'ctdflow_apply_scheduled', array( $this->tomorrow(), 3 ), 'connect-the-dots' );

		$this->assertNotFalse( $next );
		$this->assertEqualsWithDelta( $before + 120, $next, 5 );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_after_the_last_try_the_admin_hears_that_the_plan_is_not_done_yet(): void {
		$order = $this->order();
		( new PlanStore() )->putPending( $this->tomorrow(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $this->plan( 'opt_1', 'queued', $order ) );

		ScheduledPlanning::create()->apply( $this->tomorrow(), 5 );

		$this->assertFalse( as_next_scheduled_action( 'ctdflow_apply_scheduled', array( $this->tomorrow(), 6 ), 'connect-the-dots' ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'not done yet', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'page=ctdflow-delivery-routes&date=' . $this->tomorrow(), $this->mails[0]['message'] );
	}

	public function test_a_failed_plan_is_mailed_once_with_its_reason_and_the_current_plan_stays(): void {
		$order = $this->order();
		( new PlanStore() )->put( $this->tomorrow(), 'opt_0' );
		( new PlanStore() )->putPending( $this->tomorrow(), 'opt_1' );
		$plan          = $this->plan( 'opt_1', 'failed', $order );
		$plan['error'] = array( 'code' => 'location_unreachable' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 200, $plan );

		ScheduledPlanning::create()->apply( $this->tomorrow(), 1 );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Some addresses cannot be reached by road.', $this->mails[0]['message'] );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
		$this->assertSame( 'failed', ScheduledPlanning::lastRun()['outcome'] );
		$this->assertFalse( as_next_scheduled_action( 'ctdflow_apply_scheduled', array( $this->tomorrow(), 2 ), 'connect-the-dots' ) );
		$this->assertSame( 'opt_0', ( new PlanStore() )->get( $this->tomorrow() ) );
	}

	public function test_an_expired_plan_is_mailed(): void {
		( new PlanStore() )->put( $this->tomorrow(), 'opt_1' );
		$this->ctd->respond( 'GET', '/v1/woocommerce/plans/opt_1', 404, array( 'error' => array( 'type' => 'not_found', 'code' => 'plan_not_found', 'message' => 'Plan not found' ) ) );

		ScheduledPlanning::create()->apply( $this->tomorrow(), 1 );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'plan_not_found', ScheduledPlanning::lastRun()['code'] );
	}

	public function test_a_network_failure_while_waiting_is_tried_again(): void {
		( new PlanStore() )->putPending( $this->tomorrow(), 'opt_1' );
		$this->ctd->fail( 'GET', '/v1/woocommerce/plans/opt_1', 'timeout' );

		ScheduledPlanning::create()->apply( $this->tomorrow(), 1 );

		$this->assertNotFalse( as_next_scheduled_action( 'ctdflow_apply_scheduled', array( $this->tomorrow(), 2 ), 'connect-the-dots' ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_the_settings_field_is_enabled_on_a_paid_account(): void {
		$this->account( array( 'NL' ), $this->tomorrow(), true );

		$html = $this->settingsTab();

		$this->assertStringNotContainsString( 'disabled', $this->checkbox( $html ) );
		$this->assertStringContainsString( 'type="hidden" name="ctdflow_settings[schedule_enabled]"', $html );
		$this->assertStringNotContainsString( 'Scheduled planning needs a paid plan', $html );
	}

	public function test_the_settings_field_is_disabled_with_an_explanation_and_the_pricing_link_when_not_paid(): void {
		$this->settings( array( 'schedule_enabled' => false ) );
		$this->account( array( 'NL' ), $this->tomorrow(), false );

		$html = $this->settingsTab();

		$this->assertStringContainsString( 'Scheduled planning needs a paid plan', $html );
		$this->assertStringContainsString( 'href="https://ctdflow.com/pricing"', $html );
		$this->assertStringContainsString( 'disabled', $this->checkbox( $html ) );
		$this->assertStringContainsString( 'disabled', $this->tag( $html, 'schedule_time' ) );
		$this->assertStringNotContainsString( 'type="hidden" name="ctdflow_settings[schedule_enabled]"', $html );
	}

	public function test_without_a_connection_the_field_is_disabled_and_links_to_the_fallback_pricing_page(): void {
		delete_option( 'ctdflow_tokens' );

		$html = $this->settingsTab();

		$this->assertStringContainsString( 'Scheduled planning needs a paid plan', $html );
		$this->assertStringContainsString( 'href="https://ctdflow.com/pricing"', $html );
		$this->assertSame( array(), $this->ctd->requests() );
	}

	public function test_when_the_account_cannot_be_read_the_field_is_disabled_with_the_fallback_link(): void {
		$this->settings( array( 'schedule_enabled' => false ) );
		$this->ctd->fail( 'GET', '/v1/woocommerce/account', 'timeout' );

		$html = $this->settingsTab();

		$this->assertStringContainsString( 'href="https://ctdflow.com/pricing"', $html );
		$this->assertStringContainsString( 'disabled', $this->checkbox( $html ) );
	}

	public function test_the_help_text_recommends_a_real_cron_without_dashes_as_punctuation(): void {
		$this->account( array( 'NL' ), $this->tomorrow(), true );

		$html = $this->settingsTab();

		$this->assertStringContainsString( 'wp-cron.php', $html );
		$this->assertStringContainsString( 'real cron', $html );
		$this->assertStringContainsString( 'Action Scheduler', $html );
		$this->assertStringContainsString( 'WooCommerce &gt; Status &gt; Scheduled Actions', $html );
		$this->assertStringNotContainsString( 'DISABLE_WP_CRON', $html );
		$this->assertStringNotContainsString( ' - ', wp_strip_all_tags( substr( $html, (int) strpos( $html, 'Scheduled planning' ) ) ) );
	}

	public function test_the_settings_tab_shows_the_last_run_in_the_site_timezone(): void {
		$this->account( array( 'NL' ), $this->tomorrow(), true );
		update_option( 'ctdflow_last_scheduled_run', array( 'time' => strtotime( '2026-12-23 17:00:00 UTC' ), 'outcome' => 'failed', 'code' => 'routing_unavailable', 'date' => '2026-12-24' ), false );

		$html = $this->settingsTab();

		$this->assertStringContainsString( 'Last automatic run', $html );
		$this->assertStringContainsString( '2026-12-23 18:00', $html );
		$this->assertStringContainsString( 'routing_unavailable', $html );
	}

	public function test_the_settings_tab_explains_that_a_planned_day_was_left_alone(): void {
		$this->account( array( 'NL' ), $this->tomorrow(), true );
		update_option( 'ctdflow_last_scheduled_run', array( 'time' => strtotime( '2026-12-23 17:00:00 UTC' ), 'outcome' => 'refused', 'code' => 'already_planned', 'date' => '2026-12-24' ), false );

		$html = $this->settingsTab();

		$this->assertStringContainsString( '2026-12-23 18:00: not planned, because 2026-12-24 already has routes. Plan that day again on the Routes tab to replace them.', $html );
	}

	public function test_the_settings_tab_says_when_it_never_ran(): void {
		$this->account( array( 'NL' ), $this->tomorrow(), true );

		$this->assertStringContainsString( 'Has not run yet', $this->settingsTab() );
	}

	private function settingsTab(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		ob_start();
		( new SettingsPage( new Settings(), new Connection(), new Account() ) )->render();

		return (string) ob_get_clean();
	}

	private function checkbox( string $html ): string {
		return $this->tag( $html, 'schedule_enabled', 'checkbox' );
	}

	private function tag( string $html, string $field, string $type = '' ): string {
		preg_match_all( '/<input[^>]*>/', $html, $matches );

		foreach ( $matches[0] as $tag ) {
			if ( str_contains( $tag, '[' . $field . ']' ) && ( '' === $type || str_contains( $tag, 'type="' . $type . '"' ) ) ) {
				return $tag;
			}
		}

		$this->fail( 'No input for ' . $field );
	}

	private function settings( array $extra ): void {
		update_option(
			Settings::OPTION,
			array_merge(
				array(
					'schedule_enabled' => true,
					'date_source'      => 'status',
					'depot'       => array( 'street' => 'Depotstraat 1', 'postcode' => '1011 AB', 'city' => 'Amsterdam', 'country' => 'NL' ),
					'vehicles'    => array(
						array( 'id' => 'van-a', 'name' => 'Van A', 'available_from' => '08:00', 'available_until' => '17:00', 'capacity' => null, 'active' => true ),
						array( 'id' => 'van-b', 'name' => 'Van B', 'available_from' => '08:00', 'available_until' => '17:00', 'capacity' => null, 'active' => true ),
						array( 'id' => 'van-c', 'name' => 'Van C', 'available_from' => '08:00', 'available_until' => '17:00', 'capacity' => null, 'active' => false ),
					),
				),
				$extra
			)
		);
	}

	/**
	 * The pending actions of a hook.
	 *
	 * @return \ActionScheduler_Action[]
	 */
	private function pendingActions( string $hook ): array {
		return as_get_scheduled_actions( array( 'hook' => $hook, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ) );
	}

	private function tomorrow(): string {
		return ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
	}

	private function nextLocal( string $time, string $timezone ): int {
		$zone = new \DateTimeZone( $timezone );
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
		$next = ( new \DateTimeImmutable( 'today', $zone ) )->setTime( $hour, $minute );

		if ( $next->getTimestamp() <= time() ) {
			$next = ( new \DateTimeImmutable( 'tomorrow', $zone ) )->setTime( $hour, $minute );
		}

		return $next->getTimestamp();
	}

	private function autoload( string $option ): bool {
		global $wpdb;

		return 'yes' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
	}

	/**
	 * @return array<int, array{method: string, path: string, body: mixed}>
	 */
	private function posts(): array {
		return array_values( array_filter( $this->ctd->requests(), static fn ( array $request ): bool => 'POST' === $request['method'] && '/v1/woocommerce/plans' === $request['path'] ) );
	}

	private function account( array $countries, ?string $date = null, bool $paid = true ): void {
		$this->ctd->respond(
			'GET',
			'/v1/woocommerce/account',
			200,
			array(
				'organization'        => 'Bakkerij Jansen',
				'billing_status'      => $paid ? 'active' : 'free',
				'paid'                => $paid,
				'trial_available'     => false,
				'free_tier'           => $paid ? null : array( 'max_vehicles' => 1, 'max_stops' => 20, 'remaining_stops' => 20 ),
				'supported_countries' => $countries,
				'pricing_url'         => 'https://ctdflow.com/pricing',
			)
		);
	}

	private function order( string $country = 'NL' ): WC_Order {
		$order = wc_create_order();
		$order->set_billing_address_1( 'Keizersgracht 1' );
		$order->set_billing_postcode( '1015 CJ' );
		$order->set_billing_city( 'Amsterdam' );
		$order->set_billing_country( $country );
		$order->set_status( 'processing' );
		$order->save();

		return $order;
	}

	private function queued(): array {
		return array( 'plan_id' => 'opt_1', 'status' => 'queued', 'unplanned' => array(), 'free_tier' => null );
	}

	private function plan( string $id, string $status, WC_Order $order ): array {
		return array(
			'plan_id'       => $id,
			'status'        => $status,
			'delivery_date' => $this->tomorrow(),
			'superseded_by' => null,
			'routes'        => array(
				array(
					'vehicle' => 'Van A',
					'stops'   => array(
						array( 'reference' => (string) $order->get_id(), 'position' => 1, 'arrival' => $this->tomorrow() . 'T08:12:00+01:00', 'departure' => $this->tomorrow() . 'T08:15:00+01:00', 'delivered_at' => null ),
					),
				),
			),
			'unplanned'     => array(),
		);
	}
}
