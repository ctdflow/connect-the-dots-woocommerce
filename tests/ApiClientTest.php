<?php

namespace ConnectTheDots\Tests;

use ConnectTheDots\ApiClient;
use ConnectTheDots\ApiError;
use ConnectTheDots\Connection;
use ConnectTheDots\Tests\Support\FakeCtd;
use ConnectTheDots\Tests\Support\TestCase;

class ApiClientTest extends TestCase {

	private FakeCtd $ctd;

	public function set_up(): void {
		parent::set_up();

		$this->ctd = FakeCtd::install();

		update_option( 'ctdflow_client_id', 'client-1', false );
		$this->storeTokens( 'access-1', 'refresh-1', time() + 3600 );
	}

	public function test_get_sends_the_query_and_the_bearer_token_and_returns_the_body(): void {
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 200, array( 'plan' => 'solo' ) );

		$body = ( new ApiClient() )->get(
			'/v1/woocommerce/account',
			array(
				'delivery_date' => '2026-11-20',
				'site_url'      => 'https://shop.test',
			)
		);

		$request = $this->ctd->requests()[0];

		$this->assertSame( array( 'plan' => 'solo' ), $body );
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( array( 'delivery_date' => '2026-11-20', 'site_url' => 'https://shop.test' ), $request['query'] );
		$this->assertSame( 'Bearer access-1', FakeCtd::header( $request, 'Authorization' ) );
		$this->assertSame( 'application/json', FakeCtd::header( $request, 'Accept' ) );
	}

	public function test_post_sends_a_json_body(): void {
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 201, array( 'plan_id' => 'opt_1' ) );

		$body = ( new ApiClient() )->post( '/v1/woocommerce/plans', array( 'delivery_date' => '2026-11-20' ) );

		$request = $this->ctd->requests()[0];

		$this->assertSame( array( 'plan_id' => 'opt_1' ), $body );
		$this->assertSame( array( 'delivery_date' => '2026-11-20' ), $request['body'] );
		$this->assertSame( 'application/json', FakeCtd::header( $request, 'Content-Type' ) );
	}

	public function test_every_request_carries_the_user_agent_and_timeout(): void {
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 200, array() );
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 201, array() );

		$client = new ApiClient();
		$client->get( '/v1/woocommerce/account' );
		$client->post( '/v1/woocommerce/plans', array() );

		foreach ( $this->ctd->requests() as $request ) {
			$this->assertMatchesRegularExpression(
				'#^ConnectTheDots-WooCommerce/\d+\.\d+\.\d+ WordPress/' . preg_quote( get_bloginfo( 'version' ), '#' ) . '$#',
				$request['user_agent']
			);
			$this->assertSame( 30, $request['timeout'] );
		}
	}

	public function test_an_expired_token_is_refreshed_before_the_call(): void {
		$this->storeTokens( 'access-1', 'refresh-1', time() - 5 );
		$this->ctd->respond( 'POST', '/oauth/token', 200, $this->tokenResponse( 'access-2', 'refresh-2' ) );
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 200, array( 'ok' => true ) );

		$this->assertSame( array( 'ok' => true ), ( new ApiClient() )->get( '/v1/woocommerce/account' ) );

		$requests = $this->ctd->requests();

		$this->assertSame( '/oauth/token', $requests[0]['path'] );
		$this->assertSame( 'Bearer access-2', FakeCtd::header( $requests[1], 'Authorization' ) );
	}

	public function test_a_401_refreshes_once_and_retries(): void {
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 401, array( 'error' => array( 'type' => 'auth', 'code' => 'unauthenticated', 'message' => 'No.' ) ) );
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 200, array( 'ok' => true ) );
		$this->ctd->respond( 'POST', '/oauth/token', 200, $this->tokenResponse( 'access-2', 'refresh-2' ) );

		$this->assertSame( array( 'ok' => true ), ( new ApiClient() )->get( '/v1/woocommerce/account' ) );

		$requests = $this->ctd->requests();

		$this->assertCount( 3, $requests );
		$this->assertSame( 'Bearer access-1', FakeCtd::header( $requests[0], 'Authorization' ) );
		$this->assertSame( '/oauth/token', $requests[1]['path'] );
		$this->assertSame( 'Bearer access-2', FakeCtd::header( $requests[2], 'Authorization' ) );
		$this->assertTrue( ( new Connection() )->isConnected() );
	}

	public function test_a_second_401_disconnects_and_throws_connection_lost(): void {
		$this->ctd->respond( 'POST', '/v1/woocommerce/plans', 401, array() );
		$this->ctd->respond( 'POST', '/oauth/token', 200, $this->tokenResponse( 'access-2', 'refresh-2' ) );

		try {
			( new ApiClient() )->post( '/v1/woocommerce/plans', array() );
			$this->fail( 'The 401 was accepted.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'connection_lost', $error->errorCode );
			$this->assertSame( 401, $error->status );
			$this->assertNotSame( '', $error->userMessage );
		}

		$this->assertCount( 3, $this->ctd->requests() );
		$this->assertFalse( ( new Connection() )->isConnected() );
		$this->assertSame( 'client-1', get_option( 'ctdflow_client_id' ) );
	}

	public function test_a_401_with_a_revoked_refresh_token_disconnects_and_throws_connection_lost(): void {
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 401, array() );
		$this->ctd->respond( 'POST', '/oauth/token', 400, array( 'error' => 'invalid_grant', 'error_description' => 'Revoked.', 'hint' => 'Token has been revoked', 'message' => 'Revoked.' ) );

		try {
			( new ApiClient() )->get( '/v1/woocommerce/account' );
			$this->fail( 'The revoked token was accepted.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'connection_lost', $error->errorCode );
		}

		$this->assertFalse( ( new Connection() )->isConnected() );
	}

	public function test_a_call_without_a_connection_throws_connection_lost_without_a_request(): void {
		( new Connection() )->disconnect();

		try {
			( new ApiClient() )->get( '/v1/woocommerce/account' );
			$this->fail( 'The call was made.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'connection_lost', $error->errorCode );
		}

		$this->assertSame( array(), $this->ctd->requests() );
	}

	public function test_an_error_response_becomes_an_api_error_with_the_api_code(): void {
		$this->ctd->respond(
			'POST',
			'/v1/woocommerce/plans',
			402,
			array(
				'error' => array(
					'type'    => 'billing',
					'code'    => 'upgrade_required',
					'message' => 'Raw API text.',
				),
			)
		);

		try {
			( new ApiClient() )->post( '/v1/woocommerce/plans', array() );
			$this->fail( 'The error was swallowed.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'upgrade_required', $error->errorCode );
			$this->assertSame( 402, $error->status );
			$this->assertNotSame( 'Raw API text.', $error->userMessage );
			$this->assertNotSame( '', $error->userMessage );
			$this->assertSame( 'Raw API text.', $error->getMessage() );
		}
	}

	public function test_an_error_response_without_a_code_falls_back_on_the_status(): void {
		$this->ctd->respond( 'GET', '/v1/woocommerce/account', 500, array() );

		try {
			( new ApiClient() )->get( '/v1/woocommerce/account' );
			$this->fail( 'The error was swallowed.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'http_500', $error->errorCode );
			$this->assertSame( 500, $error->status );
			$this->assertNotSame( '', $error->userMessage );
		}
	}

	public function test_a_network_failure_becomes_a_network_error(): void {
		$this->ctd->fail( 'GET', '/v1/woocommerce/account', 'cURL error 28: timed out' );

		try {
			( new ApiClient() )->get( '/v1/woocommerce/account' );
			$this->fail( 'The failure was swallowed.' );
		} catch ( ApiError $error ) {
			$this->assertSame( 'network_error', $error->errorCode );
			$this->assertSame( 0, $error->status );
			$this->assertNotSame( '', $error->userMessage );
		}

		$this->assertTrue( ( new Connection() )->isConnected() );
	}

	private function storeTokens( string $access, string $refresh, int $expires_at ): void {
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

	private function tokenResponse( string $access, string $refresh ): array {
		return array(
			'token_type'    => 'Bearer',
			'expires_in'    => 3600,
			'access_token'  => $access,
			'refresh_token' => $refresh,
		);
	}
}
