<?php
/**
 * Calls the Connect the Dots API on behalf of the connected shop.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated JSON client for the Connect the Dots API.
 */
class ApiClient {

	/**
	 * The connection that supplies the access token.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * @param Connection|null $connection The connection to use, the shop's own by default.
	 */
	public function __construct( ?Connection $connection = null ) {
		$this->connection = $connection ?? new Connection();
	}

	/**
	 * Sends a GET request.
	 *
	 * @param string $path  Path below the API base URL, such as /v1/woocommerce/account.
	 * @param array  $query Query parameters.
	 *
	 * @throws ApiError When the call fails.
	 */
	public function get( string $path, array $query = array() ): array {
		if ( array() !== $query ) {
			$path = add_query_arg( rawurlencode_deep( $query ), $path );
		}

		return $this->authenticated( 'GET', $path, null );
	}

	/**
	 * Sends a POST request with a JSON body.
	 *
	 * @param string $path Path below the API base URL.
	 * @param array  $body Body, sent as JSON.
	 *
	 * @throws ApiError When the call fails.
	 */
	public function post( string $path, array $body ): array {
		return $this->authenticated( 'POST', $path, $body );
	}

	/**
	 * Sends a request without authentication and returns the decoded body of a successful response.
	 *
	 * @param string     $method HTTP method, GET or POST.
	 * @param string     $path   Path below the API base URL.
	 * @param array|null $body   Body, sent as JSON.
	 *
	 * @throws ApiError When the call fails.
	 */
	public static function send( string $method, string $path, ?array $body ): array {
		return self::unwrap( self::dispatch( $method, $path, $body, null ) );
	}

	/**
	 * Sends a request with the access token, refreshing it once when the API refuses it.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path below the API base URL.
	 * @param array|null $body   Body, sent as JSON.
	 *
	 * @throws ApiError When the call fails.
	 */
	private function authenticated( string $method, string $path, ?array $body ): array {
		$response = self::dispatch( $method, $path, $body, $this->connection->accessToken() );

		if ( 401 === $response['status'] ) {
			$this->connection->refreshTokens();

			$response = self::dispatch( $method, $path, $body, $this->connection->accessToken() );

			if ( 401 === $response['status'] ) {
				$this->connection->disconnect();

				throw ApiError::connectionLost();
			}
		}

		return self::unwrap( $response );
	}

	/**
	 * Performs the HTTP request.
	 *
	 * @param string      $method HTTP method.
	 * @param string      $path   Path below the API base URL.
	 * @param array|null  $body   Body, sent as JSON.
	 * @param string|null $token  Access token, none for unauthenticated calls.
	 *
	 * @return array{status: int, body: array}
	 *
	 * @throws ApiError When the API could not be reached.
	 */
	private static function dispatch( string $method, string $path, ?array $body, ?string $token ): array {
		$plugin  = Plugin::instance();
		$headers = array( 'Accept' => 'application/json' );
		$args    = array(
			'timeout'    => 30,
			'user-agent' => sprintf( 'ConnectTheDots-WooCommerce/%s WordPress/%s', $plugin->version(), get_bloginfo( 'version' ) ),
		);

		if ( null !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
			$args['body']            = wp_json_encode( $body );
		}

		$args['headers'] = $headers;
		$url             = $plugin->apiUrl() . $path;
		$response        = 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped where it is shown.
			throw ApiError::network( $response->get_error_message() );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => is_array( $decoded ) ? $decoded : array(),
		);
	}

	/**
	 * Returns the body of a successful response and throws for an error status.
	 *
	 * @param array{status: int, body: array} $response Response of dispatch().
	 *
	 * @throws ApiError When the status is not a success.
	 */
	private static function unwrap( array $response ): array {
		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped where it is shown.
			throw ApiError::fromResponse( $response['status'], $response['body'] );
		}

		return $response['body'];
	}
}
