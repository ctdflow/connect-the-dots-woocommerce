<?php
/**
 * An error from the Connect the Dots API, or from reaching it.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a call to Connect the Dots fails. The code is stable and meant for branching, the user
 * message is meant for the shop owner.
 */
class ApiError extends RuntimeException {

	/**
	 * Stable error code, from the API's error.code or derived from the failure.
	 *
	 * @var string
	 */
	public string $errorCode;

	/**
	 * HTTP status, 0 when the API could not be reached.
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * Translated message for the shop owner.
	 *
	 * @var string
	 */
	public string $userMessage;

	/**
	 * @param string $error_code   Stable error code.
	 * @param int    $status       HTTP status, 0 when the API could not be reached.
	 * @param string $user_message Translated message for the shop owner.
	 * @param string $message      Technical message for logs, defaults to the error code.
	 */
	public function __construct( string $error_code, int $status, string $user_message, string $message = '' ) {
		parent::__construct( '' !== $message ? $message : $error_code, $status );

		$this->errorCode   = $error_code;
		$this->status      = $status;
		$this->userMessage = $user_message;
	}

	/**
	 * Builds the error for a response with an error status.
	 *
	 * @param int   $status HTTP status.
	 * @param array $body   Decoded response body.
	 */
	public static function fromResponse( int $status, array $body ): ApiError {
		if ( isset( $body['error'] ) && is_string( $body['error'] ) && '' !== $body['error'] ) {
			// OAuth endpoints answer with an RFC 6749 body: error is the code, error_description the text.
			$code = $body['error'];
			$text = (string) ( $body['error_description'] ?? $body['message'] ?? '' );
		} else {
			$error = isset( $body['error'] ) && is_array( $body['error'] ) ? $body['error'] : array();
			$code  = isset( $error['code'] ) && is_string( $error['code'] ) && '' !== $error['code'] ? $error['code'] : 'http_' . $status;
			$text  = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : '';
		}

		return new ApiError( $code, $status, self::userMessageFor( $code, $status ), $text );
	}

	/**
	 * Builds the error for a request that never got a response.
	 *
	 * @param string $reason What went wrong, for logs.
	 */
	public static function network( string $reason ): ApiError {
		return new ApiError(
			'network_error',
			0,
			__( 'Connect the Dots could not be reached. Check that your site can make outgoing requests and try again.', 'connect-the-dots' ),
			$reason
		);
	}

	/**
	 * Builds the error for a connection that is gone and has to be set up again.
	 */
	public static function connectionLost(): ApiError {
		return new ApiError(
			'connection_lost',
			401,
			__( 'The connection with Connect the Dots was lost. Connect again to continue.', 'connect-the-dots' )
		);
	}

	/**
	 * Shop owner message for an error code, falling back on the status.
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 */
	private static function userMessageFor( string $code, int $status ): string {
		switch ( $code ) {
			case 'upgrade_required':
			case 'scheduled_needs_paid_plan':
				return __( 'This needs a paid Connect the Dots plan.', 'connect-the-dots' );
			case 'organization_blocked':
				return __( 'Your Connect the Dots account is blocked. Contact Connect the Dots support.', 'connect-the-dots' );
			case 'daily_limit_reached':
				return __( 'The daily limit of Connect the Dots is reached. Try again tomorrow.', 'connect-the-dots' );
			case 'routing_unavailable':
				return __( 'Route planning is temporarily unavailable. Try again in a few minutes.', 'connect-the-dots' );
		}

		if ( 429 === $status ) {
			return __( 'Connect the Dots received too many requests. Try again in a minute.', 'connect-the-dots' );
		}

		if ( $status >= 500 ) {
			return __( 'Connect the Dots had a problem. Try again in a few minutes.', 'connect-the-dots' );
		}

		return __( 'Connect the Dots did not accept this request.', 'connect-the-dots' );
	}
}
