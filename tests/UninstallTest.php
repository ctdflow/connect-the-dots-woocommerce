<?php

namespace ConnectTheDots\Tests;

use ConnectTheDots\Tests\Support\TestCase;

class UninstallTest extends TestCase {

	private array $options = array(
		'ctdflow_settings',
		'ctdflow_tokens',
		'ctdflow_client_id',
		'ctdflow_client_redirect_uri',
		'ctdflow_plans',
		'ctdflow_pending_plans',
		'ctdflow_applied_plans',
		'ctdflow_last_scheduled_run',
		'ctdflow_scheduled_lock',
		'ctdflow_account_generation',
	);

	private function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'connect-the-dots/connect-the-dots.php' );
		}

		require dirname( __DIR__ ) . '/uninstall.php';
	}

	public function test_it_removes_the_options(): void {
		foreach ( $this->options as $option ) {
			update_option( $option, 'value', false );
		}

		update_option( 'blogname', 'Keep me' );

		$this->uninstall();

		foreach ( $this->options as $option ) {
			$this->assertFalse( get_option( $option ), "$option is still there." );
		}

		$this->assertSame( 'Keep me', get_option( 'blogname' ) );
	}

	public function test_it_removes_the_transients_and_locks_of_the_plugin_only(): void {
		set_transient( 'ctdflow_account_2026-11-20', array( 'plan' => 'free' ), HOUR_IN_SECONDS );
		set_transient( 'ctdflow_notice_7', 'notice', HOUR_IN_SECONDS );
		set_transient( 'ctdflow_selection_7', array( 1 ), HOUR_IN_SECONDS );
		set_transient( 'ctdflow_pkce_abc', 'verifier', HOUR_IN_SECONDS );
		set_transient( 'ctdflow_delivery_sync_throttle', time(), HOUR_IN_SECONDS );
		set_transient( 'ctdother_transient', 'keep', HOUR_IN_SECONDS );
		set_transient( 'woocommerce_ctdflow_note', 'keep', HOUR_IN_SECONDS );
		add_option( 'ctdflow_apply_lock_' . md5( 'opt_1' ), time(), '', false );

		$this->uninstall();

		// The uninstall removes the rows. A real uninstall runs in a request of its own, so the
		// cache of this test process is emptied to see what the database holds.
		wp_cache_flush();

		foreach ( array( 'ctdflow_account_2026-11-20', 'ctdflow_notice_7', 'ctdflow_selection_7', 'ctdflow_pkce_abc', 'ctdflow_delivery_sync_throttle' ) as $transient ) {
			$this->assertFalse( get_transient( $transient ), "$transient is still there." );
		}

		$this->assertFalse( get_option( 'ctdflow_apply_lock_' . md5( 'opt_1' ) ) );
		$this->assertSame( 'keep', get_transient( 'ctdother_transient' ) );
		$this->assertSame( 'keep', get_transient( 'woocommerce_ctdflow_note' ) );
	}

	public function test_it_unschedules_the_scheduled_actions(): void {
		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, 'ctdflow_sync_deliveries', array(), 'connect-the-dots' );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'ctdflow_scheduled_planning', array( '2026-11-21' ), 'connect-the-dots' );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'ctdflow_apply_scheduled', array( '2026-11-21', 1 ), 'connect-the-dots' );

		$this->uninstall();

		foreach ( array( 'ctdflow_sync_deliveries', 'ctdflow_scheduled_planning', 'ctdflow_apply_scheduled' ) as $hook ) {
			$this->assertFalse( as_has_scheduled_action( $hook ), "$hook is still scheduled." );
		}
	}

	public function test_it_keeps_the_order_meta(): void {
		$order = wc_create_order();
		$order->update_meta_data( '_ctdflow_plan_id', 'opt_1' );
		$order->save();

		$this->uninstall();

		$this->assertSame( 'opt_1', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id', true ) );
	}

	public function test_on_multisite_it_cleans_every_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Run with WP_TESTS_MULTISITE=1 to test the network cleanup.' );
		}

		$other = self::factory()->blog->create();

		update_option( 'ctdflow_tokens', 'main', false );
		switch_to_blog( $other );
		update_option( 'ctdflow_tokens', 'other', false );
		set_transient( 'ctdflow_account_2026-11-20', 'other', HOUR_IN_SECONDS );
		restore_current_blog();

		$this->uninstall();
		wp_cache_flush();

		$this->assertFalse( get_option( 'ctdflow_tokens' ) );
		switch_to_blog( $other );
		$this->assertFalse( get_option( 'ctdflow_tokens' ) );
		$this->assertFalse( get_transient( 'ctdflow_account_2026-11-20' ) );
		restore_current_blog();
	}

	public function test_the_file_does_nothing_outside_an_uninstall(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;", $source );
	}
}
