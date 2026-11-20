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
	 * Trades the refresh token for new tokens. When Connect the Dots refuses it, the connection is gone
	 * and the shop is disconnected.
	 *
	 * @throws ApiError When the tokens cannot be refreshed.
	 */
	public function refreshTokens(): void {
		$refresh_token = $this->tokens()['refresh_token'];

		if ( '' === $refresh_token ) {
			throw ApiError::connectionLost();
		}

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

			if ( $error->status >= 400 && $error->status < 500 && 429 !== $error->status ) {
				// CTD rotates refresh tokens. A concurrent request may have rotated this one already,
				// so look at what is stored now before concluding that the connection is gone.
				wp_cache_delete( 'ctdflow_tokens', 'options' );
				wp_cache_delete( 'alloptions', 'options' );

				if ( $this->tokens()['refresh_token'] !== $refresh_token && $this->isConnected() ) {
					return;
				}

				$this->disconnect();

				throw ApiError::connectionLost();
			}

			throw $error;
		}

		$this->storeTokens( $response );
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
