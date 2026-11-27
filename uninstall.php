<?php
/**
 * Removes what Connect the Dots stored in the database when the plugin is deleted: its options,
 * its transients and its scheduled actions. The meta on orders stays, because it is part of the
 * order history of the shop.
 *
 * @package ConnectTheDots
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

( static function (): void {
	// Everything the plugin stores is per site, so on a multisite network every site is cleaned.
	$cleanup = static function (): void {
		global $wpdb;

		foreach ( array(
			'ctdflow_settings',
			'ctdflow_tokens',
			'ctdflow_client_id',
			'ctdflow_client_redirect_uri',
			'ctdflow_plans',
			'ctdflow_pending_plans',
			'ctdflow_applied_plans',
			'ctdflow_seen_orders',
			'ctdflow_left_out_orders',
			'ctdflow_last_scheduled_run',
			'ctdflow_scheduled_lock',
			'ctdflow_refresh_lock',
			'ctdflow_account_generation',
		) as $option ) {
			delete_option( $option );
		}

		// The names of some transients end in a date or a user id, and those of the locks of applying a
		// plan in a hash of the plan, so they are found by their prefix.
		$transients = $wpdb->esc_like( '_transient_ctdflow_' ) . '%';
		$timeouts   = $wpdb->esc_like( '_transient_timeout_ctdflow_' ) . '%';
		$locks      = $wpdb->esc_like( 'ctdflow_apply_lock_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $transients, $timeouts, $locks ) );

		// Action Scheduler comes with WooCommerce. Without WooCommerce active there is nothing to unschedule.
		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) ) {
			foreach ( array( 'ctdflow_sync_deliveries', 'ctdflow_scheduled_planning', 'ctdflow_apply_scheduled' ) as $hook ) {
				as_unschedule_all_actions( $hook );
			}
		}
	};

	if ( ! is_multisite() ) {
		$cleanup();

		return;
	}

	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		$cleanup();
		restore_current_blog();
	}
} )();
