<?php

namespace ConnectTheDots\Tests;

use ConnectTheDots\Account;
use ConnectTheDots\ApiError;
use ConnectTheDots\Connection;
use ConnectTheDots\Tests\Support\FakeCtd;
use ConnectTheDots\Tests\Support\TestCase;

class ConnectionTest extends TestCase {

	private FakeCtd $ctd;

	public function set_up(): void {
		parent::set_up();

		$this->ctd = FakeCtd::install();
	}

	public function test_a_new_shop_is_not_connected(): void {
		$this->assertFalse( ( new Connection() )->isConnected() );
	}

	public function test_the_first_authorize_url_registers_a_client_and_later_ones_reuse_it(): void {
		$this->registerClient();

		$connection = new Connection();
		$connection->authorizeUrl();
		$connection->authorizeUrl();

		$registrations = array_filter(
			$this->ctd->requests(),
			static fn ( array $request ): bool => '/v1/woocommerce/oauth/register' === $request['path']
		);

		$this->assertCount( 1, $registrations );
		$this->assertSame( 'client-1', get_option( 'ctdflow_client_id' ) );
		$this->assertSame(
			array( admin_url( 'admin.php?page=ctdflow-delivery-routes&ctdflow_oauth=callback' ) ),
			array_values( $registrations )[0]['body']['redirect_uris']
		);
		$this->assertNotEmpty( array_values( $registrations )[0]['body']['client_name'] );
		$this->assertArrayNotHasKey( 'ctdflow_client_id', wp_load_alloptions( true ) );
		$this->assertArrayNotHasKey( 'ctdflow_client_redirect_uri', wp_load_alloptions( true ) );
	}

	public function test_a_changed_redirect_uri_registers_the_shop_again(): void {
		$this->registerClient();
		$this->ctd->respond(
			'POST',
			'/v1/woocommerce/oauth/register',
			201,
			array( 'client_id' => 'client-2' )
		);

		$connection = new Connection();
		$connection->authorizeUrl();

		$this->assertSame( 'client-1', get_option( 'ctdflow_client_id' ) );

		add_filter(
			'admin_url',
			static fn ( string $url ): string => str_replace( home_url(), 'https://new-domain.example', $url )
		);

		parse_str( (string) wp_parse_url( $connection->authorizeUrl(), PHP_URL_QUERY ), $query );

		$registrations = array_values(
			array_filter(
				$this->ctd->requests(),
				static fn ( array $request ): bool => '/v1/woocommerce/oauth/register' === $request['path']
			)
		);

		$this->assertCount( 2, $registrations );
		$this->assertStringStartsWith( 'https://new-domain.example', $registrations[1]['body']['redirect_uris'][0] );
		$this->assertSame( 'client-2', $query['client_id'] );
		$this->assertSame( 'client-2', get_option( 'ctdflow_client_id' ) );
		$this->assertSame( $registrations[1]['body']['redirect_uris'][0], get_option( 'ctdflow_client_redirect_uri' ) );
	}

	public function test_an_invalid_client_at_the_token_exchange_drops_the_client_id(): void {
		$this->registerClient();
		$this->ctd->respond( 'POST', '/oauth/token', 401, array( 'error' => 'invalid_client', 'error_description' => 'Client authentication failed.', 'message' => 'Client authentication failed.' ) );

		$connection = new Connection();
		$query      = $this->authorize( $connection );

		try {
			$connection->handleCallback( 'code-1', $query['state'] );
			$this->fail( 'The code was accepted.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'invalid_client', $error->errorCode );
		}

		$this->assertFalse( get_option( 'ctdflow_client_id' ) );
		$this->assertFalse( get_option( 'ctdflow_client_redirect_uri' ) );
	}

	public function test_the_authorize_url_asks_for_pkce_with_the_woocommerce_scope_and_a_state(): void {
		$this->registerClient();

		$url = ( new Connection() )->authorizeUrl();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( 'http', $url );
		$this->assertSame( '/oauth/authorize', wp_parse_url( $url, PHP_URL_PATH ) );
		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( 'client-1', $query['client_id'] );
		$this->assertSame( 'woocommerce', $query['scope'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertSame( admin_url( 'admin.php?page=ctdflow-delivery-routes&ctdflow_oauth=callback' ), $query['redirect_uri'] );
		$this->assertNotEmpty( $query['state'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $query['code_challenge'] );
	}

	public function test_a_callback_with_an_unknown_state_is_refused_without_calling_ctd(): void {
		try {
			( new Connection() )->handleCallback( 'code-1', 'unknown-state' );
			$this->fail( 'The callback was accepted.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'invalid_state', $error->errorCode );
		}

		$this->assertFalse( ( new Connection() )->isConnected() );
		$this->assertSame( array(), $this->ctd->requests() );
	}

	public function test_the_callback_exchanges_the_code_with_the_verifier_and_stores_the_tokens_without_autoload(): void {
		$this->registerClient();
		$this->ctd->respond( 'POST', '/oauth/token', 200, $this->tokenResponse( 'access-1', 'refresh-1', 3600 ) );

		$connection = new Connection();
		$query      = $this->authorize( $connection );

		$before = time();
		$connection->handleCallback( 'code-1', $query['state'] );

		$request = $this->lastRequest();

		$this->assertSame( 'authorization_code', $request['body']['grant_type'] );
		$this->assertSame( 'client-1', $request['body']['client_id'] );
		$this->assertSame( 'code-1', $request['body']['code'] );
		$this->assertSame( admin_url( 'admin.php?page=ctdflow-delivery-routes&ctdflow_oauth=callback' ), $request['body']['redirect_uri'] );
		$this->assertArrayNotHasKey( 'client_secret', $request['body'] );
		$this->assertSame(
			$query['code_challenge'],
			rtrim( strtr( base64_encode( hash( 'sha256', $request['body']['code_verifier'], true ) ), '+/', '-_' ), '=' )
		);

		$tokens = get_option( 'ctdflow_tokens' );

		$this->assertSame( 'access-1', $tokens['access_token'] );
		$this->assertSame( 'refresh-1', $tokens['refresh_token'] );
		$this->assertEqualsWithDelta( $before + 3600, $tokens['expires_at'], 5 );
		$this->assertTrue( $connection->isConnected() );

		$alloptions = wp_load_alloptions( true );

		$this->assertArrayNotHasKey( 'ctdflow_tokens', $alloptions );
		$this->assertArrayNotHasKey( 'ctdflow_client_id', $alloptions );
	}

	public function test_a_state_works_only_once(): void {
		$this->registerClient();
		$this->ctd->respond( 'POST', '/oauth/token', 200, $this->tokenResponse( 'access-1', 'refresh-1', 3600 ) );

		$connection = new Connection();
		$query      = $this->authorize( $connection );
		$connection->handleCallback( 'code-1', $query['state'] );

		$this->expectException( ApiError::class );

		$connection->handleCallback( 'code-1', $query['state'] );
	}

	public function test_a_refused_code_is_reported_and_the_state_is_spent(): void {
		$this->registerClient();
		$this->ctd->respond( 'POST', '/oauth/token', 400, array( 'error' => 'invalid_grant', 'error_description' => 'The code is invalid.', 'hint' => 'Token has been revoked', 'message' => 'The code is invalid.' ) );

		$connection = new Connection();
		$query      = $this->authorize( $connection );

		try {
			$connection->handleCallback( 'bad-code', $query['state'] );
			$this->fail( 'The code was accepted.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'invalid_grant', $error->errorCode );
			$this->assertSame( 400, $error->status );
		}

		$this->assertFalse( $connection->isConnected() );

		$this->expectException( ApiError::class );
		$connection->handleCallback( 'bad-code', $query['state'] );
	}

	public function test_a_valid_token_is_returned_without_a_request(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() + 3600 );

		$this->assertSame( 'access-1', ( new Connection() )->accessToken() );
		$this->assertSame( array(), $this->ctd->requests() );
	}

	public function test_a_token_with_less_than_60_seconds_left_is_refreshed(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() + 30 );
		$this->ctd->respond( 'POST', '/oauth/token', 200, $this->tokenResponse( 'access-2', 'refresh-2', 3600 ) );

		$this->assertSame( 'access-2', ( new Connection() )->accessToken() );

		$request = $this->lastRequest();

		$this->assertSame( 'refresh_token', $request['body']['grant_type'] );
		$this->assertSame( 'refresh-1', $request['body']['refresh_token'] );
		$this->assertSame( 'client-1', $request['body']['client_id'] );
		$this->assertSame( 'refresh-2', get_option( 'ctdflow_tokens' )['refresh_token'] );
		$this->assertArrayNotHasKey( 'ctdflow_tokens', wp_load_alloptions( true ) );
	}

	public function test_a_revoked_refresh_token_disconnects(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() - 10 );
		$this->ctd->respond( 'POST', '/oauth/token', 400, array( 'error' => 'invalid_grant', 'error_description' => 'Revoked.', 'hint' => 'Token has been revoked', 'message' => 'Revoked.' ) );

		$connection = new Connection();

		try {
			$connection->accessToken();
			$this->fail( 'The revoked token was accepted.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'connection_lost', $error->errorCode );
		}

		$this->assertFalse( $connection->isConnected() );
		$this->assertFalse( get_option( 'ctdflow_tokens' ) );
	}

	public function test_an_oauth_error_body_keeps_its_error_code_and_description(): void {
		$error = ApiError::fromResponse( 400, array( 'error' => 'invalid_grant', 'error_description' => 'The code is invalid.', 'message' => 'x' ) );

		$this->assertSame( 'invalid_grant', $error->errorCode );
		$this->assertSame( 'The code is invalid.', $error->getMessage() );
		$this->assertSame( 400, $error->status );
	}

	public function test_a_refresh_token_rotated_by_a_concurrent_request_keeps_the_shop_connected(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() - 10 );
		$this->ctd->respond( 'POST', '/oauth/token', 400, array( 'error' => 'invalid_grant', 'error_description' => 'The refresh token is invalid.', 'message' => 'The refresh token is invalid.' ) );

		// While our refresh is in flight, another request stores the rotated tokens.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/oauth/token' ) ) {
					$this->storeTokens( 'access-2', 'refresh-2', time() + 3600 );
				}

				return $preempt;
			},
			5,
			3
		);

		$connection = new Connection();

		$this->assertSame( 'access-2', $connection->accessToken() );
		$this->assertTrue( $connection->isConnected() );
		$this->assertSame( 'refresh-2', get_option( 'ctdflow_tokens' )['refresh_token'] );
	}

	public function test_an_outage_while_refreshing_keeps_the_connection(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() - 10 );
		$this->ctd->respond( 'POST', '/oauth/token', 503, array( 'error' => 'server_error', 'error_description' => 'The authorization server encountered an unexpected condition.', 'message' => 'Down.' ) );

		$connection = new Connection();

		try {
			$connection->accessToken();
			$this->fail( 'The outage was not reported.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 503, $error->status );
		}

		$this->assertTrue( $connection->isConnected() );
	}

	public function test_a_refused_refresh_other_than_an_invalid_token_keeps_the_connection(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() - 10 );
		$this->ctd->respond( 'POST', '/oauth/token', 400, array( 'error' => 'invalid_request', 'error_description' => 'The request is missing a parameter.', 'message' => 'Missing.' ) );

		$connection = new Connection();

		try {
			$connection->accessToken();
			$this->fail( 'The refused refresh was not reported.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'invalid_request', $error->errorCode );
		}

		$this->assertTrue( $connection->isConnected() );
		$this->assertSame( 'refresh-1', get_option( 'ctdflow_tokens' )['refresh_token'] );
	}

	public function test_an_unknown_client_while_refreshing_disconnects_and_forgets_the_client(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() - 10 );
		$this->ctd->respond( 'POST', '/oauth/token', 401, array( 'error' => 'invalid_client', 'error_description' => 'Client authentication failed.', 'message' => 'Unknown client.' ) );

		$connection = new Connection();

		try {
			$connection->accessToken();
			$this->fail( 'The unknown client was accepted.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'connection_lost', $error->errorCode );
		}

		$this->assertFalse( $connection->isConnected() );
		$this->assertFalse( get_option( 'ctdflow_client_id' ) );
	}

	public function test_a_refresh_releases_its_lock(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() - 10 );
		$this->ctd->respond( 'POST', '/oauth/token', 200, $this->tokenResponse( 'access-2', 'refresh-2', 3600 ) );

		( new Connection() )->accessToken();

		$this->assertFalse( get_option( 'ctdflow_refresh_lock' ) );
	}

	public function test_a_request_that_finds_a_refresh_in_progress_waits_for_its_tokens(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() - 10 );
		$this->takeRefreshLock();

		$connection = $this->connectionThatPauses( fn () => $this->storeTokens( 'access-2', 'refresh-2', time() + 3600 ) );

		$this->assertSame( 'access-2', $connection->accessToken() );
		$this->assertTrue( $connection->isConnected() );
		$this->assertSame( array(), $this->tokenRequests() );
	}

	public function test_a_request_that_finds_a_refresh_in_progress_gives_up_after_a_while_and_stays_connected(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() - 10 );
		$this->takeRefreshLock();

		$connection = $this->connectionThatPauses( static function (): void {} );

		try {
			$connection->accessToken();
			$this->fail( 'The refresh in progress was not reported.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'refresh_in_progress', $error->errorCode );
			$this->assertSame( 503, $error->status );
		}

		$this->assertTrue( $connection->isConnected() );
		$this->assertSame( array(), $this->tokenRequests() );
	}

	public function test_a_request_that_waited_reports_a_connection_the_other_request_lost(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() - 10 );
		$this->takeRefreshLock();

		$connection = $this->connectionThatPauses( static fn () => delete_option( 'ctdflow_tokens' ) );

		try {
			$connection->accessToken();
			$this->fail( 'The lost connection was not reported.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'connection_lost', $error->errorCode );
		}
	}

	public function test_disconnect_removes_the_tokens_and_keeps_the_client_id(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() + 3600 );

		$connection = new Connection();
		$connection->disconnect();

		$this->assertFalse( $connection->isConnected() );
		$this->assertFalse( get_option( 'ctdflow_tokens' ) );
		$this->assertSame( 'client-1', get_option( 'ctdflow_client_id' ) );
	}

	public function test_disconnecting_forgets_the_cached_account(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() + 3600 );
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 200, array( 'organization' => array( 'name' => 'Old shop' ) ) );
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 200, array( 'organization' => array( 'name' => 'New shop' ) ) );

		$this->assertSame( 'Old shop', ( new Account() )->fetch( '2026-11-21' )['organization']['name'] );

		( new Connection() )->disconnect();
		$this->storeTokens( 'access-2', 'refresh-2', time() + 3600 );

		$this->assertSame( 'New shop', ( new Account() )->fetch( '2026-11-21' )['organization']['name'] );
	}

	public function test_connecting_forgets_the_cached_account(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() + 3600 );
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 200, array( 'organization' => array( 'name' => 'Old shop' ) ) );
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 200, array( 'organization' => array( 'name' => 'New shop' ) ) );
		$this->ctd->respond( 'POST', '/oauth/token', 200, $this->tokenResponse( 'access-2', 'refresh-2', 3600 ) );

		$this->assertSame( 'Old shop', ( new Account() )->fetch( '2026-11-21' )['organization']['name'] );

		$connection = new Connection();
		$connection->handleCallback( 'code-1', $this->authorize( $connection )['state'] );

		$this->assertSame( 'New shop', ( new Account() )->fetch( '2026-11-21' )['organization']['name'] );
	}

	public function test_every_request_carries_the_user_agent_and_timeout(): void {
		$this->registerClient();
		$this->ctd->respond( 'POST', '/oauth/token', 200, $this->tokenResponse( 'access-1', 'refresh-1', 3600 ) );

		$connection = new Connection();
		$query      = $this->authorize( $connection );
		$connection->handleCallback( 'code-1', $query['state'] );

		$this->assertCount( 2, $this->ctd->requests() );

		foreach ( $this->ctd->requests() as $request ) {
			$this->assertMatchesRegularExpression(
				'#^ConnectTheDots-WooCommerce/\d+\.\d+\.\d+ WordPress/' . preg_quote( get_bloginfo( 'version' ), '#' ) . '$#',
				$request['user_agent']
			);
			$this->assertSame( 30, $request['timeout'] );
		}
	}

	public function test_an_unreachable_api_gives_a_network_error(): void {
		$this->ctd->fail( 'POST', '/v1/woocommerce/oauth/register', 'cURL error 6' );

		try {
			( new Connection() )->authorizeUrl();
			$this->fail( 'The failure was not reported.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'network_error', $error->errorCode );
			$this->assertSame( 0, $error->status );
			$this->assertNotSame( '', $error->userMessage );
		}
	}

	private function registerClient(): void {
		$this->ctd->respond(
			'POST',
			'/v1/woocommerce/oauth/register',
			201,
			array(
				'client_id'                  => 'client-1',
				'client_name'                => 'WooCommerce',
				'redirect_uris'              => array(),
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'token_endpoint_auth_method' => 'none',
				'scope'                      => 'woocommerce',
			)
		);
	}

	private function authorize( Connection $connection ): array {
		parse_str( (string) wp_parse_url( $connection->authorizeUrl(), PHP_URL_QUERY ), $query );

		return $query;
	}

	private function takeRefreshLock(): void {
		add_option( 'ctdflow_refresh_lock', (string) time(), '', false );
	}

	private function connectionThatPauses( callable $whilePausing ): Connection {
		return new class( $whilePausing ) extends Connection {
			/**
			 * @var callable
			 */
			private $whilePausing;

			public function __construct( callable $whilePausing ) {
				$this->whilePausing = $whilePausing;
			}

			protected function pause(): void {
				( $this->whilePausing )();
			}
		};
	}

	private function tokenRequests(): array {
		return array_values(
			array_filter(
				$this->ctd->requests(),
				static fn ( array $request ): bool => '/oauth/token' === $request['path']
			)
		);
	}

	private function storeTokens( string $access, string $refresh, int $expires_at ): void {
		update_option( 'ctdflow_client_id', 'client-1', false );
		update_option( 'ctdflow_client_redirect_uri', admin_url( 'admin.php?page=ctdflow-delivery-routes&ctdflow_oauth=callback' ), false );
		update_option(
			'ctdflow_tokens',
			array(
				'access_token'  => $access,
				'refresh_token' => $refresh,
				'expires_at'    => $expires_at,
			),
			false
		);
	}

	private function tokenResponse( string $access, string $refresh, int $expires_in ): array {
		return array(
			'token_type'    => 'Bearer',
			'expires_in'    => $expires_in,
			'access_token'  => $access,
			'refresh_token' => $refresh,
		);
	}

	private function lastRequest(): array {
		$requests = $this->ctd->requests();

		return $requests[ count( $requests ) - 1 ];
	}
}
