<?php
/**
 * Parses the time slot text of the delivery date plugins.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots\DeliveryDates;

defined( 'ABSPATH' ) || exit;

/**
 * Turns "H:i - H:i" into a window that Connect the Dots accepts: both ends valid H:i and the end after the start.
 */
final class TimeWindow {

	/**
	 * Parses a slot. A slot that ends at midnight ("24:00", or "00:00" after a later start) ends at 23:59.
	 * Anything that is not a valid window with the end after the start gives null.
	 *
	 * @param string $text Slot text such as "09:00 - 12:30".
	 *
	 * @return array{from: string, to: string}|null
	 */
	public static function parse( string $text ): ?array {
		if ( 1 !== preg_match( '/^(\d{1,2}):(\d{2}) - (\d{1,2}):(\d{2})$/', trim( $text ), $parts ) ) {
			return null;
		}

		$from = self::minutes( (int) $parts[1], (int) $parts[2], false );
		$to   = self::minutes( (int) $parts[3], (int) $parts[4], true );

		if ( null === $from || null === $to ) {
			return null;
		}

		if ( 0 === $to && $from > 0 ) {
			$to = 23 * 60 + 59;
		}

		if ( $to <= $from ) {
			return null;
		}

		return array(
			'from' => sprintf( '%02d:%02d', intdiv( $from, 60 ), $from % 60 ),
			'to'   => sprintf( '%02d:%02d', intdiv( $to, 60 ), $to % 60 ),
		);
	}

	/**
	 * Minutes since midnight, null when out of range. 24:00 is only allowed as an end.
	 *
	 * @param int  $hour   Hour.
	 * @param int  $minute Minute.
	 * @param bool $is_end Whether this is the end of the window.
	 */
	private static function minutes( int $hour, int $minute, bool $is_end ): ?int {
		if ( $minute > 59 ) {
			return null;
		}

		if ( 24 === $hour && 0 === $minute && $is_end ) {
			return 23 * 60 + 59;
		}

		return $hour > 23 ? null : $hour * 60 + $minute;
	}
}
