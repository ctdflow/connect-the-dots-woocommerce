<?php

namespace ConnectTheDots\Tests\Support;

use ConnectTheDots\Plugin;
use WP_Error;

/**
 * Stands in for the Connect the Dots API: answers HTTP requests to the API from registered
 * responses and records every request. WP_UnitTestCase restores the hooks after each test,
 * which removes the fake again.
 *
 * A request without a registered response gets a WP_Error and is also kept in unexpected(),
 * which TestCase checks after every test, so a forgotten response always fails the test.
 */
class FakeCtd {

	/**
	 * The fake installed by the running test, checked by TestCase after the test.
	 *
	 * @var FakeCtd|null
	 */
	private static ?FakeCtd $current = null;

	/**
	 * Registered responses, keyed by "METHOD /path". Several responses for one key are used in order,
	 * the last one answering every request after that.
	 *
	 * @var array<string, array<int, array{status?: int, body?: array, error?: string}>>
	 */
	private array $responses = array();

	/**
	 * Requests made to the API, in order.
	 *
	 * @var array<int, array{method: string, path: string, query: array, headers: array, user_agent: ?string, timeout: mixed, body: mixed}>
	 */
	private array $requests = array();

	/**
	 * Requests that had no registered response, as "METHOD /path".
	 *
	 * @var array<int, string>
	 */
	private array $unexpected = array();

	public static function install(): FakeCtd {
		$fake = new FakeCtd();

		add_filter( 'pre_http_request', array( $fake, 'handle' ), 10, 3 );

		self::$current = $fake;

		return $fake;
	}

	/**
	 * Returns the fake installed by the running test and forgets it.
	 */
	public static function release(): ?FakeCtd {
		$fake = self::$current;
		self::$current = null;

		return $fake;
	}

	/**
	 * Looks up a header of a recorded request, ignoring the case of its name.
	 */
	public static function header( array $request, string $name ): ?string {
		foreach ( $request['headers'] as $header => $value ) {
			if ( 0 === strcasecmp( $header, $name ) ) {
				return (string) $value;
			}
		}

		return null;
	}

	public function respond( string $method, string $path, int $status, array $body ): void {
		$this->responses[ strtoupper( $method ) . ' ' . $path ][] = array(
			'status' => $status,
			'body'   => $body,
		);
	}

	/**
	 * Makes a request fail like an unreachable server: it gets a WP_Error instead of a response.
	 */
	public function fail( string $method, string $path, string $message ): void {
		$this->responses[ strtoupper( $method ) . ' ' . $path ][] = array(
			'error' => $message,
		);
	}

	/**
	 * @return array<int, array{method: string, path: string, query: array, headers: array, user_agent: ?string, timeout: mixed, body: mixed}>
	 */
	public function requests(): array {
		return $this->requests;
	}

	/**
	 * @return array<int, string>
	 */
	public function unexpected(): array {
		return $this->unexpected;
	}

	/**
	 * @param false|array|WP_Error $preempt Response of an earlier filter.
	 * @param array                $args    Request arguments.
	 * @param string               $url     Request URL.
	 *
	 * @return false|array|WP_Error
	 */
	public function handle( $preempt, $args, $url ) {
		$api = wp_parse_url( Plugin::instance()->apiUrl() );
		$request = wp_parse_url( $url );

		if ( ( $request['host'] ?? null ) !== ( $api['host'] ?? null ) || ( $request['port'] ?? null ) !== ( $api['port'] ?? null ) ) {
			return $preempt;
		}

		$method = strtoupper( $args['method'] ?? 'GET' );
		$path = substr( $request['path'] ?? '/', strlen( $api['path'] ?? '' ) );
		parse_str( $request['query'] ?? '', $query );
		$body = $args['body'] ?? null;

		$this->requests[] = array(
			'method'     => $method,
			'path'       => $path,
			'query'      => $query,
			'headers'    => $args['headers'] ?? array(),
			'user_agent' => $args['user-agent'] ?? null,
			'timeout'    => $args['timeout'] ?? null,
			'body'       => is_string( $body ) && '' !== $body ? json_decode( $body, true ) : $body,
		);

		$response = null;

		if ( ! empty( $this->responses[ $method . ' ' . $path ] ) ) {
			$queue = &$this->responses[ $method . ' ' . $path ];
			$response = count( $queue ) > 1 ? array_shift( $queue ) : $queue[0];
			unset( $queue );
		}

		if ( null === $response ) {
			$this->unexpected[] = "{$method} {$path}";

			return new WP_Error( 'ctdflow_fake_unexpected_request', "FakeCtd has no response for {$method} {$path}." );
		}

		if ( isset( $response['error'] ) ) {
			return new WP_Error( 'http_request_failed', $response['error'] );
		}

		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $response['body'] ),
			'response' => array(
				'code'    => $response['status'],
				'message' => get_status_header_desc( $response['status'] ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
