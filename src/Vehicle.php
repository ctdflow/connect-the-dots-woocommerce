<?php
/**
 * A delivery van that routes are planned for.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for one van, as stored in the plugin settings.
 */
final class Vehicle {

	/**
	 * @param string   $id             Stable id, kept when the van is edited.
	 * @param string   $name           Name shown to the shop owner and sent to Connect the Dots.
	 * @param string   $availableFrom  Time the van can start, as H:i.
	 * @param string   $availableUntil Time the van must be back, as H:i.
	 * @param int|null $capacity       Number of items the van carries, null for no limit.
	 * @param bool     $active         Whether the van is used for planning.
	 */
	public function __construct(
		public string $id,
		public string $name,
		public string $availableFrom = '08:00',
		public string $availableUntil = '17:00',
		public ?int $capacity = null,
		public bool $active = true
	) {
	}
}
