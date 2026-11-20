<?php
/**
 * Order meta as Order Delivery Date for WooCommerce (Lite) 4.6.3 saves it, one entry per scenario.
 *
 * The date is saved twice: as text in the display format chosen in the plugin settings under the
 * key of the field label (default "Delivery Date"), and as a timestamp at 00:00 UTC under
 * _orddd_lite_timestamp. The slot is saved under _orddd_time_slot as "H:i - H:i" when the 12 hour
 * clock is chosen, and as selected otherwise.
 */

return array(
	'date and slot'       => array(
		'Delivery Date'                 => '24 December, 2026',
		'_orddd_lite_timestamp'         => gmmktime( 0, 0, 0, 12, 24, 2026 ),
		'Time Slot'                     => '09:00 AM - 12:00 PM',
		'_orddd_time_slot'              => '09:00 - 12:00',
		'_orddd_lite_timeslot_timestamp' => gmmktime( 9, 0, 0, 12, 24, 2026 ),
	),
	'date without slot'   => array(
		'Delivery Date'         => '24 December, 2026',
		'_orddd_lite_timestamp' => gmmktime( 0, 0, 0, 12, 24, 2026 ),
	),
	'as soon as possible' => array(
		'Delivery Date'         => '24 December, 2026',
		'_orddd_lite_timestamp' => gmmktime( 0, 0, 0, 12, 24, 2026 ),
		'Time Slot'             => 'As Soon As Possible.',
		'_orddd_time_slot'      => 'As Soon As Possible.',
	),
	'slot without end'    => array(
		'Delivery Date'         => '24 December, 2026',
		'_orddd_lite_timestamp' => gmmktime( 0, 0, 0, 12, 24, 2026 ),
		'_orddd_time_slot'      => '09:00',
	),
	'no date'             => array(
		'Delivery Date' => '',
	),
	'display text only'   => array(
		'Delivery Date' => '24 December, 2026',
	),
);
