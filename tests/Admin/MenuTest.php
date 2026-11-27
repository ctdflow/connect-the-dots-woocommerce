<?php

namespace ConnectTheDots\Tests\Admin;

use ConnectTheDots\Admin\Menu;
use ConnectTheDots\Connection;
use ConnectTheDots\Tests\Support\FakeCtd;
use ConnectTheDots\Tests\Support\TestCase;
use RuntimeException;
use WPDieException;

class MenuTest extends TestCase {

	private FakeCtd $ctd;

	private string $callback;

	public function set_up(): void {
		parent::set_up();

		$this->ctd      = FakeCtd::install();
		$this->callback = admin_url( 'admin.php?page=ctdflow-delivery-routes&ctdflow_oauth=callback' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . $location );
			}
		);
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	public function test_it_adds_delivery_routes_below_woocommerce(): void {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Menu::create()->addMenu();

		$slugs = wp_list_pluck( $submenu['woocommerce'] ?? array(), 2 );

		$this->assertContains( 'ctdflow-delivery-routes', $slugs );

		$entry = $submenu['woocommerce'][ array_search( 'ctdflow-delivery-routes', $slugs, true ) ];

		$this->assertSame( 'Delivery routes', $entry[0] );
		$this->assertSame( 'manage_woocommerce', $entry[1] );
	}

	public function test_the_callback_connects_and_redirects_with_a_success_notice(): void {
		$this->registerClient();
		$this->ctd->respond( 'POST', '/oauth/token', 200, $this->tokens() );
		$this->account();

		$state = $this->authorizeState();

		$this->callbackRequest( 'code-1', $state );

		$this->assertRedirectTo( Menu::url(), fn () => Menu::create()->handleOauthCallback() );
		$this->assertTrue( ( new Connection() )->isConnected() );
		$this->assertStringContainsString( 'connected', $this->renderedPage() );
	}

	public function test_connect_keeps_the_posted_selection_and_the_callback_returns_to_its_day(): void {
		$this->registerClient();
		$_REQUEST['_wpnonce']    = wp_create_nonce( 'ctdflow_connect' );
		$_POST['ctdflow_selection'] = array(
			'date'        => '2026-12-24',
			'added'       => array( '12' ),
			'order_ids'   => array( '12', '15', 'x' ),
			'vehicle_ids' => array( 'van-b' ),
		);

		try {
			Menu::create()->handleConnect();
			$this->fail( 'No redirect.' );
		} catch ( RuntimeException $redirect ) {
			$this->assertStringContainsString( '/oauth/authorize?', $redirect->getMessage() );
		}

		$this->assertSame(
			array( 'date' => '2026-12-24', 'added' => array( 12 ), 'order_ids' => array( 12, 15 ), 'vehicle_ids' => array( 'van-b' ) ),
			get_transient( 'ctdflow_selection_' . get_current_user_id() )
		);

		$this->ctd->respond( 'POST', '/oauth/token', 200, $this->tokens() );
		$this->callbackRequest( 'code-1', $this->authorizeState() );

		$this->assertRedirectTo( add_query_arg( 'date', '2026-12-24', Menu::url() ), fn () => Menu::create()->handleOauthCallback() );
	}

	public function test_a_refused_code_shows_the_user_message_and_does_not_connect(): void {
		$this->registerClient();
		$this->ctd->respond( 'POST', '/oauth/token', 400, array( 'error' => 'invalid_grant', 'error_description' => 'The code is invalid.' ) );

		$state = $this->authorizeState();

		$this->callbackRequest( 'bad', $state );

		$this->assertRedirectTo( Menu::url(), fn () => Menu::create()->handleOauthCallback() );
		$this->assertFalse( ( new Connection() )->isConnected() );

		$page = $this->renderedPage();

		$this->assertStringContainsString( 'notice-error', $page );
		$this->assertStringContainsString( 'Connect the Dots did not accept this request.', $page );
	}

	public function test_an_unknown_state_shows_a_notice_without_calling_ctd(): void {
		$this->callbackRequest( 'code-1', 'forged' );

		$this->assertRedirectTo( Menu::url(), fn () => Menu::create()->handleOauthCallback() );
		$this->assertSame( array(), $this->ctd->requests() );
		$this->assertStringContainsString( 'not valid or has expired', $this->renderedPage() );
	}

	public function test_a_callback_without_a_code_is_treated_as_cancelled(): void {
		$_GET = array( 'page' => 'ctdflow-delivery-routes', 'ctdflow_oauth' => 'callback', 'error' => 'access_denied', 'state' => 'x' );

		$this->assertRedirectTo( Menu::url(), fn () => Menu::create()->handleOauthCallback() );
		$this->assertStringContainsString( 'cancelled', $this->renderedPage() );
	}

	public function test_the_callback_needs_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->callbackRequest( 'code-1', 'state' );

		$this->expectException( WPDieException::class );

		Menu::create()->handleOauthCallback();
	}

	public function test_other_requests_are_ignored_by_the_callback(): void {
		$_GET = array( 'page' => 'something-else' );

		Menu::create()->handleOauthCallback();

		$this->assertSame( array(), $this->ctd->requests() );
	}

	public function test_connect_redirects_to_the_authorize_url(): void {
		$this->registerClient();
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'ctdflow_connect' );

		try {
			Menu::create()->handleConnect();
			$this->fail( 'No redirect.' );
		} catch ( RuntimeException $redirect ) {
			$this->assertStringStartsWith( 'redirect:http', $redirect->getMessage() );
			$this->assertStringContainsString( '/oauth/authorize?', $redirect->getMessage() );
			$this->assertStringContainsString( 'client_id=client-1', $redirect->getMessage() );
		}
	}

	public function test_connect_needs_a_valid_nonce(): void {
		$this->expectException( WPDieException::class );

		Menu::create()->handleConnect();
	}

	public function test_connect_needs_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'ctdflow_connect' );

		$this->expectException( WPDieException::class );

		Menu::create()->handleConnect();
	}

	public function test_a_registration_failure_shows_the_user_message(): void {
		$this->ctd->fail( 'POST', '/v1/woocommerce/oauth/register', 'cURL error 6' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'ctdflow_connect' );

		$this->assertRedirectTo( Menu::url(), fn () => Menu::create()->handleConnect() );
		$this->assertStringContainsString( 'could not be reached', $this->renderedPage() );
	}

	public function test_disconnect_forgets_the_tokens(): void {
		update_option( 'ctdflow_tokens', array( 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => time() + 3600 ), false );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'ctdflow_disconnect' );

		$this->assertRedirectTo( Menu::url( 'settings' ), fn () => Menu::create()->handleDisconnect() );
		$this->assertFalse( ( new Connection() )->isConnected() );
	}

	public function test_disconnect_needs_a_nonce_and_the_capability(): void {
		update_option( 'ctdflow_tokens', array( 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => time() + 3600 ), false );

		try {
			Menu::create()->handleDisconnect();
			$this->fail( 'Accepted without a nonce.' );
		} catch ( WPDieException $e ) {
			$this->assertTrue( ( new Connection() )->isConnected() );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'ctdflow_disconnect' );

		$this->expectException( WPDieException::class );

		Menu::create()->handleDisconnect();
	}

	public function test_the_routes_tab_offers_to_connect_when_not_connected(): void {
		$page = $this->renderedPage();

		$this->assertStringContainsString( 'Connect to Connect the Dots', $page );
		$this->assertStringContainsString( 'name="action" value="ctdflow_connect"', $page );
		$this->assertStringContainsString( '_wpnonce', $page );
	}

	public function test_the_routes_tab_shows_the_routes_screen_when_connected(): void {
		update_option( 'ctdflow_tokens', array( 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => time() + 3600 ), false );
		$this->account();

		$page = $this->renderedPage();

		$this->assertStringNotContainsString( 'ctdflow_connect', $page );
		$this->assertStringContainsString( 'id="ctdflow-plan-form"', $page );
	}

	public function test_the_settings_tab_shows_the_form_and_a_disconnect_button_when_connected(): void {
		update_option( 'ctdflow_tokens', array( 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => time() + 3600 ), false );
		$this->account();

		$page = $this->renderedPage( 'settings' );

		$this->assertStringContainsString( 'name="ctdflow_settings[depot][street]"', $page );
		$this->assertStringContainsString( 'name="ctdflow_settings[vehicles][0][name]"', $page );
		$this->assertStringContainsString( 'name="action" value="ctdflow_disconnect"', $page );
		$this->assertStringContainsString( 'name=\'option_page\' value=\'ctdflow_settings\'', $page );		$this->assertStringContainsString( '1 is tomorrow, 0 is today.', $page );
	}

	public function test_saving_the_settings_shows_that_they_were_saved(): void {
		update_option( 'ctdflow_tokens', array( 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => time() + 3600 ), false );
		$this->account();
		set_transient( 'settings_errors', array( array( 'setting' => 'general', 'code' => 'settings_updated', 'message' => 'Settings saved.', 'type' => 'success' ) ), 30 );
		$_GET['settings-updated'] = 'true';

		$this->assertStringContainsString( 'Settings saved.', $this->renderedPage( 'settings' ) );
	}

	public function test_the_page_needs_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );

		Menu::create()->render();
	}

	private function renderedPage( string $tab = 'routes' ): string {
		$_GET['tab'] = $tab;

		ob_start();
		Menu::create()->render();

		return (string) ob_get_clean();
	}

	private function callbackRequest( string $code, string $state ): void {
		$_GET = array(
			'page'      => 'ctdflow-delivery-routes',
			'ctdflow_oauth' => 'callback',
			'code'      => $code,
			'state'     => $state,
		);
	}

	private function authorizeState(): string {
		parse_str( (string) wp_parse_url( ( new Connection() )->authorizeUrl(), PHP_URL_QUERY ), $query );

		return $query['state'];
	}

	private function assertRedirectTo( string $expected, callable $action ): void {
		try {
			$action();
			$this->fail( 'No redirect.' );
		} catch ( RuntimeException $redirect ) {
			$this->assertSame( 'redirect:' . $expected, $redirect->getMessage() );
		}
	}

	private function account(): void {
		$this->ctd->respond(
			'GET',
			'/v1/woocommerce/account',
			200,
			array( 'organization' => 'Bakkerij Jansen', 'billing_status' => 'active', 'paid' => true, 'trial_available' => false, 'free_tier' => null, 'supported_countries' => array( 'NL' ), 'pricing_url' => 'https://ctdflow.com/pricing' )
		);
	}

	private function registerClient(): void {
		$this->ctd->respond( 'POST', '/v1/woocommerce/oauth/register', 201, array( 'client_id' => 'client-1' ) );
	}

	private function tokens(): array {
		return array( 'token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'access-1', 'refresh_token' => 'refresh-1' );
	}
}
