<?php
/**
 * Raised when a plan cannot be sent to Connect the Dots.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * The plan is refused before any request is made. The message is meant for the shop owner and must
 * be escaped with esc_html when printed.
 */
class PlanningNotPossible extends \RuntimeException {
}
