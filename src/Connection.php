<?php
/**
 * The OAuth connection between this shop and Connect the Dots.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shop as an OAuth client, runs the authorization code flow with PKCE and keeps the
 * tokens fresh.
 */
class Connection {

	/**
	 * Seconds after which a refresh lock that was not released is taken over. Longer than the HTTP timeout.
	 *
	 * @var int
	 */
	private int $refreshLockSeconds = 45;

	/**
	 * How often a request looks for the tokens another request is refreshing, a quarter second apart.
	 *
	 * @var int
	 */
	private int $refreshWaitAttempts = 40;

	/**
	 * Whether the shop is connected, which means it holds a refresh token.
	 */
	public function isConnected(): bool {
		$tokens = $this->tokens();

		return '' !== $tokens['refresh_token'];
	}

	/**
	 * URL that sends the shop owner to Connect the Dots to approve the connection.
	 *
	 * @throws ApiError When the shop cannot be registered.
	 */
	public function authorizeUrl(): string {
		$client_id = $this->registeredClientId();
		$verifier  = self::base64Url( random_bytes( 48 ) );
		$state     = wp_generate_password( 32, false );

		set_transient( $this->verifierKey( $state ), $verifier, 10 * MINUTE_IN_SECONDS );

		return $this->apiBase() . '/oauth/authorize?' . http_build_query(
			array(
				'response_type'         => 'code',
				'client_id'             => $client_id,
				'redirect_uri'          => $this->redirectUri(),
				'scope'                 => 'woocommerce',
				'state'                 => $state,
				'code_challenge'        => self::base64Url( hash( 'sha256', $verifier, true ) ),
				'code_challenge_method' => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Exchanges the authorization code for tokens. A state can be used once.
	 *
	 * @param string $code  Authorization code from the callback.
	 * @param string $state State from the callback.
	 *
	 * @throws ApiError When the state is unknown or the code is refused.
	 */
	public function handleCallback( string $code, string $state ): void {
		$verifier = '' === $state ? false : get_transient( $this->verifierKey( $state ) );

		if ( ! is_string( $verifier ) || '' === $verifier ) {
			throw new ApiError(
				'invalid_state',
				400,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped where it is shown.
				__( 'The connection attempt is not valid or has expired. Start connecting again.', 'connect-the-dots' )
			);
		}

		delete_transient( $this->verifierKey( $state ) );

		$client_id = $this->storedClientId();

		if ( '' === $client_id ) {
			throw new ApiError(
				'invalid_client',
				400,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped where it is shown.
				__( 'The connection attempt is not valid. Start connecting again.', 'connect-the-dots' )
			);
		}

		try {
			$response = ApiClient::send(
				'POST',
				'/oauth/token',
				array(
					'grant_type'    => 'authorization_code',
					'client_id'     => $client_id,
					'redirect_uri'  => $this->redirectUri(),
					'code'          => $code,
					'code_verifier' => $verifier,
				)
			);
		} catch ( ApiError $error ) {
			$this->forgetClientWhenUnknown( $error );

			throw $error;
		}

		$this->storeTokens( $response );

		// The shop may now be connected to another account than before.
		Account::forgetAll();
	}

	/**
	 * Access token for a call, refreshed first when less than 60 seconds remain.
	 *
	 * @throws ApiError When the shop is not connected or the token cannot be refreshed.
	 */
	public function accessToken(): string {
		if ( ! $this->isConnected() ) {
			throw ApiError::connectionLost();
		}

		$tokens = $this->tokens();

		if ( $tokens['expires_at'] - time() < 60 ) {
			$this->refreshTokens();
			$tokens = $this->tokens();
		}

		return $tokens['access_token'];
	}

	/**
	 * Trades the refresh token for new tokens, one request at a time. CTD rotates refresh tokens, so
	 * two requests refreshing at once would make the loser send a token that is already used. A
	 * request that finds another one refreshing waits for its tokens instead.
	 *
	 * @throws ApiError When the tokens cannot be refreshed.
	 */
	public function refreshTokens(): void {
		$refresh_token = $this->tokens()['refresh_token'];

		if ( '' === $refresh_token ) {
			throw ApiError::connectionLost();
		}

		$lock = new OptionLock( 'ctdflow_refresh_lock', $this->refreshLockSeconds );

		if ( ! $lock->acquire() ) {
			$this->waitForRefresh( $refresh_token );

			return;
		}

		try {
			$this->forgetCachedTokens();

			if ( $this->tokens()['refresh_token'] !== $refresh_token ) {
				$this->guardConnected();

				return;
			}

			$this->requestNewTokens( $refresh_token );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Sends the refresh token to CTD and stores the new tokens. Only an answer that says the token or
	 * the client is no longer valid disconnects the shop. Any other error leaves the tokens in place.
	 *
	 * @param string $refresh_token The refresh token to trade.
	 *
	 * @throws ApiError When the tokens cannot be refreshed.
	 */
	private function requestNewTokens( string $refresh_token ): void {
		try {
			$response = ApiClient::send(
				'POST',
				'/oauth/token',
				array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $this->storedClientId(),
					'refresh_token' => $refresh_token,
				)
			);
		} catch ( ApiError $error ) {
			$this->forgetClientWhenUnknown( $error );

			if ( ! in_array( $error->errorCode, array( 'invalid_grant', 'invalid_client' ), true ) ) {
				throw $error;
			}

			// A request that took over an expired lock may have rotated the token meanwhile.
			$this->forgetCachedTokens();

			if ( $this->tokens()['refresh_token'] !== $refresh_token && $this->isConnected() ) {
				return;
			}

			$this->disconnect();

			throw ApiError::connectionLost();
		}

		$this->storeTokens( $response );
	}

	/**
	 * Waits until the request that holds the refresh lock stored new tokens.
	 *
	 * @param string $refresh_token The refresh token this request started with.
	 *
	 * @throws ApiError When no new tokens arrived in time, or the other request lost the connection.
	 */
	private function waitForRefresh( string $refresh_token ): void {
		for ( $attempt = 0; $attempt < $this->refreshWaitAttempts; $attempt++ ) {
			$this->pause();
			$this->forgetCachedTokens();

			if ( $this->tokens()['refresh_token'] !== $refresh_token ) {
				$this->guardConnected();

				return;
			}
		}

		throw new ApiError(
			'refresh_in_progress',
			503,
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped where it is shown.
			__( 'The connection with Connect the Dots is being renewed. Try again in a few seconds.', 'connect-the-dots' )
		);
	}

	/**
	 * Waits a moment before looking at the tokens again.
	 */
	protected function pause(): void {
		usleep( 250000 );
	}

	/**
	 * Throws when the shop holds no tokens any more.
	 *
	 * @throws ApiError When the shop is not connected.
	 */
	private function guardConnected(): void {
		if ( ! $this->isConnected() ) {
			throw ApiError::connectionLost();
		}
	}

	/**
	 * Drops the tokens from the object cache, so they are read as another request stored them.
	 */
	private function forgetCachedTokens(): void {
		wp_cache_delete( 'ctdflow_tokens', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Forgets the tokens. The client id stays, so connecting again does not register a new client.
	 */
	public function disconnect(): void {
		delete_option( 'ctdflow_tokens' );
		Account::forgetAll();
	}

	/**
	 * The client id of this shop, registered on first use and again when the redirect URI changed since.
	 *
	 * @throws ApiError When the shop cannot be registered.
	 */
	private function registeredClientId(): string {
		$client_id = $this->storedClientId();

		if ( '' !== $client_id && get_option( 'ctdflow_client_redirect_uri', '' ) === $this->redirectUri() ) {
			return $client_id;
		}

		$response = ApiClient::send(
			'POST',
			'/v1/woocommerce/oauth/register',
			array(
				'client_name'   => 'WooCommerce ' . wp_parse_url( home_url(), PHP_URL_HOST ),
				'redirect_uris' => array( $this->redirectUri() ),
			)
		);

		$client_id = isset( $response['client_id'] ) ? (string) $response['client_id'] : '';

		if ( '' === $client_id ) {
			throw new ApiError(
				'registration_failed',
				502,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped where it is shown.
				__( 'Connect the Dots did not register this shop. Try again in a few minutes.', 'connect-the-dots' )
			);
		}

		update_option( 'ctdflow_client_id', $client_id, false );
		update_option( 'ctdflow_client_redirect_uri', $this->redirectUri(), false );

		return $client_id;
	}

	/**
	 * The stored client id, empty when the shop was never registered.
	 */
	private function storedClientId(): string {
		$client_id = get_option( 'ctdflow_client_id', '' );

		return is_string( $client_id ) ? $client_id : '';
	}

	/**
	 * Drops the client when Connect the Dots does not know it any more, so the next connection attempt
	 * registers the shop again.
	 *
	 * @param ApiError $error Error of a token request.
	 */
	private function forgetClientWhenUnknown( ApiError $error ): void {
		if ( 'invalid_client' === $error->errorCode ) {
			delete_option( 'ctdflow_client_id' );
			delete_option( 'ctdflow_client_redirect_uri' );
		}
	}

	/**
	 * Stores the tokens of a token response without autoload.
	 *
	 * @param array $response Body of the token endpoint.
	 *
	 * @throws ApiError When the response holds no tokens.
	 */
	private function storeTokens( array $response ): void {
		$access_token  = isset( $response['access_token'] ) ? (string) $response['access_token'] : '';
		$refresh_token = isset( $response['refresh_token'] ) ? (string) $response['refresh_token'] : '';

		if ( '' === $access_token || '' === $refresh_token ) {
			throw new ApiError(
				'invalid_token_response',
				502,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped where it is shown.
				__( 'Connect the Dots sent an unexpected answer. Try connecting again.', 'connect-the-dots' )
			);
		}

		update_option(
			'ctdflow_tokens',
			array(
				'access_token'  => $access_token,
				'refresh_token' => $refresh_token,
				'expires_at'    => time() + (int) ( $response['expires_in'] ?? 0 ),
			),
			false
		);
	}

	/**
	 * The stored tokens, with empty values when there are none.
	 *
	 * @return array{access_token: string, refresh_token: string, expires_at: int}
	 */
	private function tokens(): array {
		$stored = get_option( 'ctdflow_tokens', array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'access_token'  => (string) ( $stored['access_token'] ?? '' ),
			'refresh_token' => (string) ( $stored['refresh_token'] ?? '' ),
			'expires_at'    => (int) ( $stored['expires_at'] ?? 0 ),
		);
	}

	/**
	 * The page Connect the Dots sends the shop owner back to.
	 */
	private function redirectUri(): string {
		return admin_url( 'admin.php?page=ctdflow-delivery-routes&ctdflow_oauth=callback' );
	}

	/**
	 * Base URL of the OAuth endpoints, which sit beside /v1 rather than below it.
	 */
	private function apiBase(): string {
		return Plugin::instance()->apiUrl();
	}

	/**
	 * Transient key of the PKCE verifier of a connection attempt.
	 *
	 * @param string $state State of the attempt.
	 */
	private function verifierKey( string $state ): string {
		return 'ctdflow_pkce_' . hash( 'sha256', $state );
	}

	/**
	 * Encodes bytes as base64url without padding, as PKCE requires.
	 *
	 * @param string $bytes Raw bytes.
	 */
	private static function base64Url( string $bytes ): string {
		return sodium_bin2base64( $bytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
	}
}
