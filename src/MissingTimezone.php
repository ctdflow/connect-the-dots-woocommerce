<?php
/**
 * Raised when the site timezone cannot be sent to Connect the Dots.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * The site uses a UTC offset instead of a city timezone, so local times would shift.
 */
class MissingTimezone extends \RuntimeException {

	/**
	 * Creates the exception with a message for the shop owner.
	 */
	public function __construct() {
		parent::__construct(
			sprintf(
				/* translators: %s: link to the General settings. */
				__( 'Your site timezone is a UTC offset. Choose a city as timezone under %s, so delivery times are planned correctly.', 'connect-the-dots' ),
				esc_html__( 'Settings > General', 'connect-the-dots' )
			)
		);
	}
}
