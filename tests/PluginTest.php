<?php

namespace ConnectTheDots\Tests;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use ConnectTheDots\Plugin;
use ConnectTheDots\Tests\Support\TestCase;

class PluginTest extends TestCase {

	public function test_it_declares_compatibility_with_high_performance_order_storage(): void {
		$plugins = FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );

		$this->assertContains( 'connect-the-dots/connect-the-dots.php', $plugins['compatible'] );
	}

	public function test_it_declares_compatibility_with_the_cart_and_checkout_blocks(): void {
		$plugins = FeaturesUtil::get_compatible_plugins_for_feature( 'cart_checkout_blocks' );

		$this->assertContains( 'connect-the-dots/connect-the-dots.php', $plugins['compatible'] );
	}

	public function test_the_header_names_the_woocommerce_versions(): void {
		$header = (string) file_get_contents( dirname( __DIR__ ) . '/connect-the-dots.php' );

		$this->assertMatchesRegularExpression( '/^ \* WC requires at least:\s+8\.0$/m', $header );
		$this->assertMatchesRegularExpression( '/^ \* WC tested up to:\s+11\.3$/m', $header );
	}

	public function test_without_woocommerce_it_shows_a_notice_and_registers_no_menu(): void {
		$this->actAsUserWhoCanActivatePlugins();
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'admin_menu' );

		Plugin::instance()->registerHooks( false );

		$this->assertStringContainsString( 'Connect the Dots needs WooCommerce', $this->adminNotices() );
		$this->assertNotContains( 'ctdflow-delivery-routes', $this->submenuSlugs() );
	}

	public function test_on_multisite_only_a_network_administrator_sees_the_notice(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Only on multisite.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		remove_all_actions( 'admin_notices' );

		Plugin::instance()->registerHooks( false );

		$this->assertStringNotContainsString( 'Connect the Dots needs WooCommerce', $this->adminNotices() );
	}

	public function test_with_woocommerce_it_shows_no_notice_and_registers_the_menu(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'admin_menu' );

		Plugin::instance()->registerHooks( true );

		$this->assertStringNotContainsString( 'Connect the Dots needs WooCommerce', $this->adminNotices() );
		$this->assertContains( 'ctdflow-delivery-routes', $this->submenuSlugs() );
	}

	public function test_the_notice_is_only_shown_to_users_who_can_activate_plugins(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		remove_all_actions( 'admin_notices' );

		Plugin::instance()->registerHooks( false );

		$this->assertSame( '', $this->adminNotices() );
	}

	public function test_the_version_matches_the_plugin_header(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$header = get_plugin_data( dirname( __DIR__ ) . '/connect-the-dots.php', false, false );

		$this->assertSame( $header['Version'], Plugin::instance()->version() );
	}

	public function test_the_api_url_defaults_to_ctd(): void {
		$this->assertFalse( defined( 'CTDFLOW_API_URL' ), 'CTDFLOW_API_URL must not be defined for this test.' );

		$this->assertSame( 'https://api.ctdflow.com', Plugin::instance()->apiUrl() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_api_url_follows_the_ctdflow_api_url_constant(): void {
		define( 'CTDFLOW_API_URL', 'http://host.docker.internal:8000/' );

		$this->assertSame( 'http://host.docker.internal:8000', Plugin::instance()->apiUrl() );
	}

	/**
	 * Runs admin_menu on an empty menu and returns the slugs below WooCommerce.
	 *
	 * @return string[]
	 */
	private function submenuSlugs(): array {
		global $submenu;

		$submenu = array();
		do_action( 'admin_menu' );

		return wp_list_pluck( $submenu['woocommerce'] ?? array(), 2 );
	}

	private function adminNotices(): string {
		ob_start();
		do_action( 'admin_notices' );

		return (string) ob_get_clean();
	}

	/**
	 * Logs in an administrator who may activate plugins. On multisite only a network administrator may.
	 */
	private function actAsUserWhoCanActivatePlugins(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}

		wp_set_current_user( $user_id );
	}
}
