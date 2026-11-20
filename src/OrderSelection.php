<?php
/**
 * Decides which orders of a delivery day go to the route planner.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

use ConnectTheDots\DeliveryDates\DeliveryDateSource;
use ConnectTheDots\DeliveryDates\DeliveryDateSources;

defined( 'ABSPATH' ) || exit;

/**
 * Selects the orders of a day by status, delivery date, pickup and shipping, and checks their address.
 */
class OrderSelection {

	/**
	 * The plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Statuses that take an order planned for the day off the route.
	 *
	 * @var string[]
	 */
	private array $closedStatuses = array( 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-checkout-draft' );

	/**
	 * Creates the selection.
	 *
	 * @param Settings $settings The plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The orders of a delivery day, split in orders that can be planned and orders that cannot.
	 *
	 * Added orders bypass the date, pickup and shipping filters, but need an allowed status and still get the
	 * address checks. Removed orders are always left out, also when they are added. An order delivered on
	 * the plan of this day is left out too and only counted, so planning the day again never sends a van
	 * to an order it delivered. A delivered order of another day, or one added by hand, can be planned,
	 * so a mistaken check off never keeps an order off the road.
	 *
	 * @param string          $date                Y-m-d.
	 * @param int[]           $addedOrderIds       Orders the owner added by hand.
	 * @param int[]           $removedOrderIds     Orders the owner removed by hand.
	 * @param string[]|null   $supportedCountries  ISO 3166 alpha-2 codes CTD routes in, null for no check.
	 *
	 * @return array{plannable: SelectedOrder[], unplannable: SelectedOrder[], delivered: int}
	 */
	public function forDay( string $date, array $addedOrderIds = array(), array $removedOrderIds = array(), ?array $supportedCountries = null ): array {
		$source  = DeliveryDateSources::forSetting( $this->settings->dateSource() );
		$added   = array_map( 'intval', $addedOrderIds );
		$removed = array_map( 'intval', $removedOrderIds );
		$orders  = array();

		foreach ( $this->ofTheDay( $source, $date ) as $order ) {
			$orders[ $order->get_id() ] = $order;
		}

		foreach ( $this->added( $addedOrderIds ) as $order ) {
			$orders[ $order->get_id() ] = $order;
		}

		ksort( $orders );

		$result = array(
			'plannable'   => array(),
			'unplannable' => array(),
			'delivered'   => 0,
		);

		foreach ( $orders as $id => $order ) {
			if ( in_array( $id, $removed, true ) ) {
				continue;
			}

			if ( ! in_array( $id, $added, true ) && $this->isDeliveredOnThisDay( $order, $date ) ) {
				++$result['delivered'];
				continue;
			}

			$selected = $this->select( $order, $source, $supportedCountries );

			if ( null === $selected ) {
				continue;
			}

			$result[ null === $selected->problem ? 'plannable' : 'unplannable' ][] = $selected;
		}

		return $result;
	}

	/**
	 * Whether a driver checked the order off on the plan of this day.
	 *
	 * @param \WC_Order $order The order.
	 * @param string    $date  Y-m-d.
	 */
	private function isDeliveredOnThisDay( \WC_Order $order, string $date ): bool {
		return '' !== (string) $order->get_meta( '_ctdflow_delivered_at', true )
			&& (string) $order->get_meta( '_ctdflow_plan_date', true ) === $date;
	}

	/**
	 * Orders with an allowed status that belong to the day, and the orders already planned for the day.
	 *
	 * @param DeliveryDateSource $source The date source.
	 * @param string             $date   Y-m-d.
	 *
	 * @return \WC_Order[]
	 */
	private function ofTheDay( DeliveryDateSource $source, string $date ): array {
		$ids = wc_get_orders(
			array_merge(
				array(
					'status' => $this->settings->statuses(),
					'limit'  => -1,
					'type'   => 'shop_order',
					'return' => 'ids',
				),
				$source->queryArgs( $date )
			)
		);
		$ids = array_unique( array_merge( array_map( 'intval', $ids ), $this->plannedFor( $date ) ) );

		$is_status_only = 'status' === $source->id();
		$orders         = array();

		// Only ids are fetched, then orders are loaded one at a time (wc_get_orders ignores an include
		// argument) so orders that are filtered out are not kept in memory.
		foreach ( $ids as $id ) {
			$order = wc_get_order( (int) $id );

			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			if ( $is_status_only && $this->isPlannedElsewhere( $order, $date ) ) {
				continue;
			}

			if ( $this->isPickup( $order, $source ) || ! $this->needsShipping( $order ) || ! $this->hasChosenShippingMethod( $order ) ) {
				continue;
			}

			if ( ! $is_status_only && $source->deliveryDate( $order ) !== $date ) {
				continue;
			}

			$orders[] = $order;
		}

		return $orders;
	}

	/**
	 * Ids of the orders a plan of the day placed, whatever their status, so the status after planning
	 * never takes an order off the day when it is planned again. A cancelled, refunded, failed or
	 * trashed order is left out.
	 *
	 * @param string $date Y-m-d.
	 *
	 * @return int[]
	 */
	private function plannedFor( string $date ): array {
		// meta_key and meta_value work in both order storages, a meta_query does not.
		$ids = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'status'     => array_values( array_diff( array_keys( wc_get_order_statuses() ), $this->closedStatuses ) ),
				'limit'      => -1,
				'return'     => 'ids',
				'meta_key'   => '_ctdflow_plan_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $date, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Whether the order belongs to the route of another day. Without a delivery date plugin this is
	 * what keeps an order off a second route. _ctdflow_plan_date is a Y-m-d date (written by the Routes
	 * screen). An order planned for a later day stays on that day. An order planned for an earlier day
	 * stays there once it was delivered. When the driver did not deliver it, it comes back only once
	 * that day is over (before today in the site timezone), because a route of today may still be
	 * driving. An order planned for the same day stays in so it can be planned again.
	 *
	 * @param \WC_Order $order The order.
	 * @param string    $date  Y-m-d.
	 */
	private function isPlannedElsewhere( \WC_Order $order, string $date ): bool {
		$planned = (string) $order->get_meta( '_ctdflow_plan_date', true );

		if ( '' === $planned || $planned === $date ) {
			return false;
		}

		if ( $planned > $date || '' !== (string) $order->get_meta( '_ctdflow_delivered_at', true ) ) {
			return true;
		}

		return $planned >= ( new \DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
	}

	/**
	 * The orders added by hand that have an allowed status.
	 *
	 * @param int[] $ids Order ids.
	 *
	 * @return \WC_Order[]
	 */
	private function added( array $ids ): array {
		$orders = array();

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$order = wc_get_order( $id );

			if ( $order instanceof \WC_Order && in_array( 'wc-' . $order->get_status(), $this->settings->statuses(), true ) ) {
				$orders[] = $order;
			}
		}

		return $orders;
	}

	/**
	 * Whether the order is picked up in the shop, by the date plugin or by the shipping method.
	 *
	 * @param \WC_Order          $order  The order.
	 * @param DeliveryDateSource $source The date source.
	 */
	private function isPickup( \WC_Order $order, DeliveryDateSource $source ): bool {
		if ( $source->isPickup( $order ) ) {
			return true;
		}

		foreach ( $order->get_shipping_methods() as $method ) {
			if ( in_array( $method->get_method_id(), array( 'local_pickup', 'pickup_location' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a product of the order is shipped. An order with only virtual products has nothing to
	 * deliver. A product that no longer exists counts as shipped, and so does an order without products.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function needsShipping( \WC_Order $order ): bool {
		$items = $order->get_items();

		if ( array() === $items ) {
			return true;
		}

		foreach ( $items as $item ) {
			$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;

			if ( ! $product instanceof \WC_Product || $product->needs_shipping() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the order is shipped with a method the shop chose to plan. Without a choice every order
	 * matches. With a choice, an order without a shipping method does not.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function hasChosenShippingMethod( \WC_Order $order ): bool {
		$chosen = $this->settings->shippingMethods();

		if ( array() === $chosen ) {
			return true;
		}

		foreach ( $order->get_shipping_methods() as $method ) {
			if ( in_array( $method->get_method_id() . ':' . $method->get_instance_id(), $chosen, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds the selected order with its address, window, quantity and problem. Null when every item was refunded.
	 *
	 * @param \WC_Order          $order              The order.
	 * @param DeliveryDateSource $source             The date source.
	 * @param string[]|null      $supportedCountries Supported country codes or null.
	 */
	private function select( \WC_Order $order, DeliveryDateSource $source, ?array $supportedCountries ): ?SelectedOrder {
		$quantity = $this->quantity( $order );

		if ( null === $quantity ) {
			return null;
		}

		$address = $this->address( $order );
		$problem = null;

		if ( '' === $address['street'] ) {
			$problem = 'no_address';
		} elseif ( $this->isOtherCountry( $address['country'] ) || ( null !== $supportedCountries && ! in_array( $address['country'], array_map( 'strtoupper', $supportedCountries ), true ) ) ) {
			$problem = 'unsupported_country';
		}

		return new SelectedOrder( $order, $address, $source->timeWindow( $order ), $quantity, $problem );
	}

	/**
	 * Whether the country differs from the depot country. One request has a single country, so
	 * cross border stops cannot be geocoded reliably. Without a depot country nothing is flagged.
	 *
	 * @param string $country Country code of the order.
	 */
	private function isOtherCountry( string $country ): bool {
		$depot = strtoupper( $this->settings->depotAddress()['country'] );

		return '' !== $depot && $country !== $depot;
	}

	/**
	 * The number of items still to deliver: ordered minus refunded. Null when items were ordered
	 * and all of them were refunded, because there is nothing left to deliver.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function quantity( \WC_Order $order ): ?int {
		$ordered = 0;
		$net     = 0;

		foreach ( $order->get_items() as $item ) {
			$quantity = (int) $item->get_quantity();
			$ordered += $quantity;
			// The refunded quantity of a line is negative.
			$net += $quantity + (int) $order->get_qty_refunded_for_item( $item->get_id() );
		}

		return $ordered > 0 && $net <= 0 ? null : $net;
	}

	/**
	 * The shipping address, or the whole billing address when shipping has no street. An empty country falls back to the other address, then to the shop's base country.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return array{street: string, postcode: string, city: string, country: string}
	 */
	private function address( \WC_Order $order ): array {
		$billing  = $this->format(
			$order->get_billing_address_1(),
			$order->get_billing_address_2(),
			$order->get_billing_postcode(),
			$order->get_billing_city(),
			$order->get_billing_country()
		);
		$shipping = $this->format(
			$order->get_shipping_address_1(),
			$order->get_shipping_address_2(),
			$order->get_shipping_postcode(),
			$order->get_shipping_city(),
			$order->get_shipping_country()
		);

		$chosen = '' !== $shipping['street'] ? $shipping : $billing;
		$other  = '' !== $shipping['street'] ? $billing : $shipping;

		if ( '' === $chosen['country'] ) {
			$chosen['country'] = '' !== $other['country'] ? $other['country'] : strtoupper( (string) WC()->countries->get_base_country() );
		}

		return $chosen;
	}

	/**
	 * Builds the street from line 1 and, only when it looks like a house number addition, line 2.
	 * Line 2 often holds a company name or a contact person, which must not leave the shop. An
	 * addition contains a digit or is at most 3 characters, like A, bis, 2-hoog or 12.
	 *
	 * @param string $line1    Address line 1.
	 * @param string $line2    Address line 2.
	 * @param string $postcode Postcode.
	 * @param string $city     City.
	 * @param string $country  Country code.
	 *
	 * @return array{street: string, postcode: string, city: string, country: string}
	 */
	private function format( string $line1, string $line2, string $postcode, string $city, string $country ): array {
		return array(
			'street'   => trim( trim( $line1 ) . ( $this->isHouseNumberAddition( $line2 ) ? ' ' . trim( $line2 ) : '' ) ),
			'postcode' => trim( $postcode ),
			'city'     => trim( $city ),
			'country'  => strtoupper( trim( $country ) ),
		);
	}

	/**
	 * Whether an address line 2 is a house number addition.
	 *
	 * @param string $line Address line 2.
	 */
	private function isHouseNumberAddition( string $line ): bool {
		$line = trim( $line );

		return '' !== $line && ( 1 === preg_match( '/\d/', $line ) || mb_strlen( $line ) <= 3 );
	}
}
