<?php
/**
 * An order picked for a delivery day.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * The order and the few facts that are sent to Connect the Dots: address, time window and quantity.
 */
final class SelectedOrder {

	/**
	 * The order.
	 *
	 * @var \WC_Order
	 */
	public \WC_Order $order;

	/**
	 * Delivery address.
	 *
	 * @var array{street: string, postcode: string, city: string, country: string}
	 */
	public array $address;

	/**
	 * Chosen time window, if any.
	 *
	 * @var array{from: string, to: string}|null
	 */
	public ?array $timeWindow;

	/**
	 * Number of items.
	 *
	 * @var int
	 */
	public int $quantity;

	/**
	 * Why the order cannot be planned: no_address, unsupported_country or null.
	 *
	 * @var string|null
	 */
	public ?string $problem;

	/**
	 * Creates the value object.
	 *
	 * @param \WC_Order                                                           $order      The order.
	 * @param array{street: string, postcode: string, city: string, country: string} $address    Delivery address.
	 * @param array{from: string, to: string}|null                                $timeWindow Time window.
	 * @param int                                                                 $quantity   Number of items.
	 * @param string|null                                                         $problem    Problem code.
	 */
	public function __construct( \WC_Order $order, array $address, ?array $timeWindow, int $quantity, ?string $problem ) {
		$this->order      = $order;
		$this->address    = $address;
		$this->timeWindow = $timeWindow;
		$this->quantity   = $quantity;
		$this->problem    = $problem;
	}
}
