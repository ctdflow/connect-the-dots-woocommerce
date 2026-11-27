<?php
/**
 * Builds the body of a plan request for Connect the Dots.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * Turns selected orders and vans into the request body. This is the privacy boundary of the plugin:
 * only the fields built here leave the shop, never names, contact details, amounts or products.
 */
class PlanRequestBuilder {

	/**
	 * The plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Creates the builder.
	 *
	 * @param Settings $settings The plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The body of POST /v1/woocommerce/plans.
	 *
	 * @param string          $date     Delivery date as Y-m-d.
	 * @param SelectedOrder[] $orders   Orders that can be planned.
	 * @param Vehicle[]       $vehicles Vans, inactive ones are left out.
	 * @param string          $trigger  Either manual or scheduled.
	 *
	 * @throws \InvalidArgumentException When the trigger is not manual or scheduled.
	 * @throws MissingTimezone When the site has no IANA timezone.
	 */
	public function build( string $date, array $orders, array $vehicles, string $trigger ): array {
		if ( ! in_array( $trigger, array( 'manual', 'scheduled' ), true ) ) {
			throw new \InvalidArgumentException( 'The trigger must be manual or scheduled.' );
		}

		$timezone     = $this->timezone();
		$depot        = $this->settings->depotAddress();
		$depotCountry = $depot['country'];
		$depotAddress = $this->addressString( $depot );
		$endAddress   = $this->addressString( $this->settings->endAddress() );
		$minutes      = $this->settings->stopMinutes();

		$stops = array();

		foreach ( $orders as $selected ) {
			$stop = array(
				'reference'    => (string) $selected->order->get_id(),
				'order_number' => (string) $selected->order->get_order_number(),
				'address'      => $this->addressString( $selected->address ),
			);

			if ( null !== $selected->timeWindow ) {
				$stop['time_window'] = array(
					'from' => $selected->timeWindow['from'],
					'to'   => $selected->timeWindow['to'],
				);
			}

			$stop['quantity']        = $selected->quantity;
			$stop['service_minutes'] = $minutes;

			$stops[] = $stop;
		}

		$vans = array();

		foreach ( $vehicles as $vehicle ) {
			if ( ! $vehicle->active ) {
				continue;
			}

			$van = array(
				'name'            => $vehicle->name,
				'available_from'  => '' !== $vehicle->availableFrom ? $vehicle->availableFrom : null,
				'available_until' => '' !== $vehicle->availableUntil ? $vehicle->availableUntil : null,
				'capacity'        => $vehicle->capacity,
			);

			if ( $endAddress !== $depotAddress ) {
				$van['end_address'] = $endAddress;
			}

			$vans[] = $van;
		}

		return array(
			'site_url'      => home_url(),
			'delivery_date' => $date,
			'timezone'      => $timezone,
			'country'       => $depotCountry,
			'trigger'       => $trigger,
			'depot'         => array( 'address' => $depotAddress ),
			'stops'         => $stops,
			'vehicles'      => $vans,
		);
	}

	/**
	 * An address as one line. Orders in another country than the depot are not planned, so the
	 * country is not part of the string.
	 *
	 * @param array{street: string, postcode: string, city: string, country: string} $address Address.
	 */
	private function addressString( array $address ): string {
		$locality = trim( trim( $address['postcode'] ) . ' ' . trim( $address['city'] ) );
		$parts    = array_filter( array( trim( $address['street'] ), $locality ), static fn( string $part ): bool => '' !== $part );

		return implode( ', ', $parts );
	}

	/**
	 * The IANA timezone of the shop. An offset such as +01:00 is not a timezone with daylight saving
	 * and would shift every local time, so it is refused.
	 *
	 * @throws MissingTimezone When the site has no IANA timezone.
	 */
	private function timezone(): string {
		if ( ! self::hasTimezone() ) {
			throw new MissingTimezone();
		}

		return wp_timezone_string();
	}

	/**
	 * Whether the site timezone is an IANA identifier.
	 */
	public static function hasTimezone(): bool {
		return in_array( wp_timezone_string(), \DateTimeZone::listIdentifiers( \DateTimeZone::ALL ), true );
	}
}
