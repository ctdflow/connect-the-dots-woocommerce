<?php
/**
 * Order meta as Delivery & Pickup Date Time for WooCommerce 1.3.92 saves it, one entry per scenario.
 *
 * The keys are unprefixed. Dates are always Y-m-d (the classic checkout converts them, the block
 * checkout sends the date picker value). Slots are "H:i - H:i" in 24 hour notation, whatever the
 * display clock is. delivery_type is "delivery" or "pickup".
 */

return array(
	'delivery with slot'    => array(
		'delivery_type' => 'delivery',
		'delivery_date' => '2026-12-24',
		'delivery_time' => '09:00 - 12:30',
	),
	'delivery without slot' => array(
		'delivery_type' => 'delivery',
		'delivery_date' => '2026-12-24',
	),
	'delivery without type' => array(
		'delivery_date' => '2026-12-24',
		'delivery_time' => '13:00 - 14:00',
	),
	'pickup'                => array(
		'delivery_type' => 'pickup',
		'pickup_date'   => '2026-12-24',
		'pickup_time'   => '15:00 - 16:00',
	),
	'no date'               => array(
		'delivery_type' => 'delivery',
	),
);
