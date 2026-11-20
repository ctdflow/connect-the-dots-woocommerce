<?php

namespace ConnectTheDots\Tests\DeliveryDates;

use ConnectTheDots\DeliveryDates\TimeWindow;
use PHPUnit\Framework\TestCase;

class TimeWindowTest extends TestCase {

	/**
	 * @dataProvider slots
	 */
	public function test_parse( string $text, ?array $expected ): void {
		$this->assertSame( $expected, TimeWindow::parse( $text ) );
	}

	public static function slots(): array {
		return array(
			'plain'                     => array( '09:00 - 12:30', array( 'from' => '09:00', 'to' => '12:30' ) ),
			'single digit hour'         => array( '9:00 - 12:30', array( 'from' => '09:00', 'to' => '12:30' ) ),
			'ends at midnight as 00:00' => array( '23:00 - 00:00', array( 'from' => '23:00', 'to' => '23:59' ) ),
			'ends at 24:00'             => array( '22:00 - 24:00', array( 'from' => '22:00', 'to' => '23:59' ) ),
			'whole day'                 => array( '00:00 - 24:00', array( 'from' => '00:00', 'to' => '23:59' ) ),
			'inverted'                  => array( '14:00 - 09:00', null ),
			'zero length'               => array( '09:00 - 09:00', null ),
			'zero length at midnight'   => array( '00:00 - 00:00', null ),
			'24:00 as start'            => array( '24:00 - 24:00', null ),
			'invalid minutes'           => array( '09:60 - 12:00', null ),
			'invalid hour'              => array( '09:00 - 25:00', null ),
			'start only'                => array( '09:00', null ),
			'text'                      => array( 'As Soon As Possible.', null ),
			'empty'                     => array( '', null ),
		);
	}
}
