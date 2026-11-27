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
	 * Parses a slot on the 24 hour clock ("09:00 - 12:30") or the 12 hour clock ("2:00 PM - 4:00 PM").
	 * A slot that ends at midnight ("24:00", "12:00 AM", or "00:00" after a later start) ends at 23:59.
	 * Anything that is not a valid window with the end after the start gives null.
	 *
	 * @param string $text Slot text such as "09:00 - 12:30".
	 *
	 * @return array{from: string, to: string}|null
	 */
	public static function parse( string $text ): ?array {
		$time = '(\d{1,2}):(\d{2})(?:\s*([ap])\.?\s*m\.?)?';

		if ( 1 !== preg_match( '/^' . $time . '\s*-\s*' . $time . '$/i', trim( $text ), $parts ) ) {
			return null;
		}

		$from_hour = self::hour( (int) $parts[1], strtolower( $parts[3] ?? '' ) );
		$to_hour   = self::hour( (int) $parts[4], strtolower( $parts[6] ?? '' ) );

		if ( null === $from_hour || null === $to_hour ) {
			return null;
		}

		$from = self::minutes( $from_hour, (int) $parts[2], false );
		$to   = self::minutes( $to_hour, (int) $parts[5], true );

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
	 * The hour on the 24 hour clock. A 12 hour time (with a or p) needs an hour from 1 to 12.
	 *
	 * @param int    $hour     Hour as written.
	 * @param string $meridiem a, p or empty for the 24 hour clock.
	 */
	private static function hour( int $hour, string $meridiem ): ?int {
		if ( '' === $meridiem ) {
			return $hour;
		}

		if ( $hour < 1 || $hour > 12 ) {
			return null;
		}

		return ( 12 === $hour ? 0 : $hour ) + ( 'p' === $meridiem ? 12 : 0 );
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
