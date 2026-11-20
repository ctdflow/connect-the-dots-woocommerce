<?php

namespace ConnectTheDots\Tests;

use ConnectTheDots\Plugin;
use ConnectTheDots\Tests\Support\FakeCtd;
use ConnectTheDots\Tests\Support\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class FakeCtdTest extends TestCase {

	public function test_it_answers_a_request_with_the_registered_response(): void {
		$ctd = FakeCtd::install();
		$ctd->respond( 'POST', '/v1/woocommerce/plans', 201, array( 'plan_id' => 'opt_123' ) );

		$response = wp_remote_post(
			Plugin::instance()->apiUrl() . '/v1/woocommerce/plans',
			array(
				'headers' => array(
					'Authorization' => 'Bearer secret',
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'delivery_date' => '2026-11-20' ) ),
			)
		);

		$this->assertSame( 201, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( array( 'plan_id' => 'opt_123' ), json_decode( wp_remote_retrieve_body( $response ), true ) );
		$this->assertSame( 'application/json', wp_remote_retrieve_header( $response, 'content-type' ) );
	}

	public function test_it_records_every_request(): void {
		$ctd = FakeCtd::install();
		$ctd->respond( 'POST', '/v1/woocommerce/plans', 201, array() );
		$ctd->respond( 'GET', '/v1/woocommerce/account', 200, array() );

		wp_remote_post(
			Plugin::instance()->apiUrl() . '/v1/woocommerce/plans',
			array(
				'headers' => array( 'Authorization' => 'Bearer secret' ),
				'body'    => wp_json_encode( array( 'stops' => array( 1, 2 ) ) ),
			)
		);
		wp_remote_get( Plugin::instance()->apiUrl() . '/v1/woocommerce/account?delivery_date=2026-11-20&site_url=https%3A%2F%2Fshop.test' );

		$requests = $ctd->requests();

		$this->assertCount( 2, $requests );
		$this->assertSame( 'POST', $requests[0]['method'] );
		$this->assertSame( '/v1/woocommerce/plans', $requests[0]['path'] );
		$this->assertSame( 'Bearer secret', $requests[0]['headers']['Authorization'] );
		$this->assertSame( array( 'stops' => array( 1, 2 ) ), $requests[0]['body'] );
		$this->assertSame( 'GET', $requests[1]['method'] );
		$this->assertSame( '/v1/woocommerce/account', $requests[1]['path'] );
		$this->assertSame(
			array(
				'delivery_date' => '2026-11-20',
				'site_url'      => 'https://shop.test',
			),
			$requests[1]['query']
		);
		$this->assertNull( $requests[1]['body'] );
	}

	public function test_a_request_without_a_registered_response_gets_an_error(): void {
		$ctd = FakeCtd::install();

		$response = wp_remote_get( Plugin::instance()->apiUrl() . '/v1/woocommerce/plans/opt_1' );

		$this->assertWPError( $response );
		$this->assertStringContainsString( 'GET /v1/woocommerce/plans/opt_1', $response->get_error_message() );
		$this->assertCount( 1, $ctd->requests() );
		$this->assertSame( array( 'GET /v1/woocommerce/plans/opt_1' ), $ctd->unexpected() );

		$this->expectUnexpectedRequestFailure();
	}

	public function test_a_request_without_a_registered_response_fails_the_test(): void {
		FakeCtd::install();

		// Plugin code handles the WP_Error gracefully; the test must still fail.
		wp_remote_get( Plugin::instance()->apiUrl() . '/v1/woocommerce/account' );

		$this->expectUnexpectedRequestFailure();
	}

	public function test_a_test_with_only_registered_requests_passes(): void {
		$ctd = FakeCtd::install();
		$ctd->respond( 'GET', '/v1/woocommerce/account', 200, array() );

		wp_remote_get( Plugin::instance()->apiUrl() . '/v1/woocommerce/account' );

		$this->assertNoUnexpectedCtdRequests();
		$this->assertSame( array(), $ctd->unexpected() );
	}

	public function test_it_records_the_timeout_and_user_agent_and_finds_headers_in_any_case(): void {
		$ctd = FakeCtd::install();
		$ctd->respond( 'POST', '/v1/woocommerce/plans', 201, array() );

		wp_remote_post(
			Plugin::instance()->apiUrl() . '/v1/woocommerce/plans',
			array(
				'timeout'    => 30,
				'user-agent' => 'ConnectTheDots-WooCommerce/0.1.0 WordPress/7.1',
				'headers'    => array( 'Authorization' => 'Bearer secret' ),
			)
		);

		$request = $ctd->requests()[0];

		$this->assertSame( 30, $request['timeout'] );
		$this->assertSame( 'ConnectTheDots-WooCommerce/0.1.0 WordPress/7.1', $request['user_agent'] );
		$this->assertSame( 'Bearer secret', FakeCtd::header( $request, 'authorization' ) );
		$this->assertSame( 'Bearer secret', FakeCtd::header( $request, 'AUTHORIZATION' ) );
		$this->assertNull( FakeCtd::header( $request, 'Content-Type' ) );
	}

	/**
	 * Runs the check TestCase runs after every test and asserts that it fails.
	 */
	private function expectUnexpectedRequestFailure(): void {
		try {
			$this->assertNoUnexpectedCtdRequests();
		} catch ( AssertionFailedError $failure ) {
			$this->assertStringContainsString( 'without a FakeCtd response', $failure->getMessage() );

			return;
		}

		$this->fail( 'An unexpected request to Connect the Dots did not fail the test.' );
	}

	public function test_several_responses_for_one_request_are_used_in_order_and_the_last_one_repeats(): void {
		$ctd = FakeCtd::install();
		$ctd->respond( 'GET', '/v1/woocommerce/account', 401, array() );
		$ctd->respond( 'GET', '/v1/woocommerce/account', 200, array() );

		$codes = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$codes[] = wp_remote_retrieve_response_code( wp_remote_get( Plugin::instance()->apiUrl() . '/v1/woocommerce/account' ) );
		}

		$this->assertSame( array( 401, 200, 200 ), $codes );
	}

	public function test_it_leaves_requests_to_other_hosts_alone(): void {
		$ctd = FakeCtd::install();

		$this->assertFalse( apply_filters( 'pre_http_request', false, array( 'method' => 'GET' ), 'https://example.com/v1/woocommerce/plans' ) );
		$this->assertSame( array(), $ctd->requests() );
	}
}
