<?php
/**
 * The plugin settings: depot, vans and planning preferences.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitises the single option ctdflow_settings.
 */
class Settings {

	/**
	 * Name of the option that holds all settings.
	 *
	 * @var string
	 */
	public const OPTION = 'ctdflow_settings';

	/**
	 * Whether the option is being created by sanitize(), which keeps that call from recursing.
	 *
	 * @var bool
	 */
	private bool $creating = false;

	/**
	 * Where the delivery date of an order comes from.
	 *
	 * @var string[]
	 */
	private array $dateSources = array( 'auto', 'order-delivery-date', 'woo-delivery', 'status' );

	/**
	 * Address the vans start from. Defaults to the store address of WooCommerce.
	 *
	 * @return array{street: string, postcode: string, city: string, country: string}
	 */
	public function depotAddress(): array {
		$stored = $this->stored( 'depot' );

		if ( is_array( $stored ) && $this->hasAddress( $stored ) ) {
			return $this->normaliseAddress( $stored );
		}

		$country = (string) get_option( 'woocommerce_default_country', '' );

		return array(
			'street'   => trim( get_option( 'woocommerce_store_address', '' ) . ' ' . get_option( 'woocommerce_store_address_2', '' ) ),
			'postcode' => (string) get_option( 'woocommerce_store_postcode', '' ),
			'city'     => (string) get_option( 'woocommerce_store_city', '' ),
			'country'  => strtoupper( explode( ':', $country )[0] ),
		);
	}

	/**
	 * Address the vans end at. Defaults to the depot.
	 *
	 * @return array{street: string, postcode: string, city: string, country: string}
	 */
	public function endAddress(): array {
		$stored = $this->stored( 'end' );

		if ( is_array( $stored ) && $this->hasAddress( $stored ) ) {
			return $this->normaliseAddress( $stored );
		}

		return $this->depotAddress();
	}

	/**
	 * The vans. Without saved vans there is one default van.
	 *
	 * @return Vehicle[]
	 */
	public function vehicles(): array {
		$vehicles = array();

		foreach ( $this->storedVehicles() as $row ) {
			$vehicles[] = new Vehicle(
				(string) $row['id'],
				(string) $row['name'],
				(string) $row['available_from'],
				(string) $row['available_until'],
				null === $row['capacity'] ? null : (int) $row['capacity'],
				(bool) $row['active']
			);
		}

		if ( array() === $vehicles ) {
			$vehicles[] = new Vehicle( 'van-1', __( 'Van 1', 'connect-the-dots' ) );
		}

		return $vehicles;
	}

	/**
	 * Minutes spent at every stop.
	 */
	public function stopMinutes(): int {
		return (int) $this->stored( 'stop_minutes', 3 );
	}

	/**
	 * Order statuses that are planned, with the wc- prefix.
	 *
	 * @return string[]
	 */
	public function statuses(): array {
		$statuses = $this->stored( 'statuses', array( 'wc-processing' ) );

		return is_array( $statuses ) && array() !== $statuses ? array_values( array_map( 'strval', $statuses ) ) : array( 'wc-processing' );
	}

	/**
	 * Shipping methods whose orders are planned, as method_id:instance_id. Empty plans every method.
	 *
	 * @return string[]
	 */
	public function shippingMethods(): array {
		$methods = $this->stored( 'shipping_methods', array() );

		return is_array( $methods ) ? array_values( array_map( 'strval', $methods ) ) : array();
	}

	/**
	 * The shipping methods of every shipping zone of the shop, including the zone for the rest of the
	 * world, as method_id:instance_id to the zone and method title.
	 *
	 * @return array<string, string>
	 */
	public function availableShippingMethods(): array {
		$zones   = array_map( static fn( array $zone ): \WC_Shipping_Zone => new \WC_Shipping_Zone( (int) $zone['id'] ), \WC_Shipping_Zones::get_zones() );
		$zones[] = new \WC_Shipping_Zone( 0 );
		$methods = array();

		foreach ( $zones as $zone ) {
			foreach ( $zone->get_shipping_methods() as $method ) {
				$methods[ $method->id . ':' . $method->get_instance_id() ] = $zone->get_zone_name() . ': ' . $method->get_title();
			}
		}

		return $methods;
	}

	/**
	 * Where the delivery date comes from: auto, order-delivery-date, woo-delivery or status.
	 */
	public function dateSource(): string {
		$source = (string) $this->stored( 'date_source', 'auto' );

		return in_array( $source, $this->dateSources, true ) ? $source : 'auto';
	}

	/**
	 * Whether a note is added to an order once it is planned.
	 */
	public function addOrderNote(): bool {
		return (bool) $this->stored( 'add_order_note', true );
	}

	/**
	 * Status an order gets after planning, null to leave the status alone.
	 */
	public function statusAfterPlanning(): ?string {
		$status = $this->stored( 'status_after_planning', null );

		return is_string( $status ) && '' !== $status ? $status : null;
	}

	/**
	 * Whether routes are planned on a schedule.
	 */
	public function scheduleEnabled(): bool {
		return (bool) $this->stored( 'schedule_enabled', false );
	}

	/**
	 * Time of day the scheduled planning runs, as H:i in the site timezone.
	 */
	public function scheduleTime(): string {
		return (string) $this->stored( 'schedule_time', '18:00' );
	}

	/**
	 * Days ahead the scheduled planning plans for, 1 is tomorrow.
	 */
	public function scheduleDayOffset(): int {
		return (int) $this->stored( 'schedule_day_offset', 1 );
	}

	/**
	 * Cleans the submitted settings. Invalid values raise a settings error and keep the stored value.
	 *
	 * This is the sanitize callback of register_setting, so it runs on every save of ctdflow_settings.
	 *
	 * @param mixed $input Submitted settings.
	 */
	public function sanitize( $input ): array {
		if ( get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'connect-the-dots' ), '', array( 'response' => 403 ) );
		}

		$input = is_array( $input ) ? $input : array();

		// The option is created here, before WordPress writes it, so it never autoloads. Creating it
		// runs this callback again, which the flag short circuits.
		if ( ! $this->creating && false === get_option( self::OPTION ) ) {
			$this->creating = true;
			add_option( self::OPTION, array(), '', false );
			$this->creating = false;
		}

		if ( $this->creating ) {
			return is_array( $input ) ? $input : array();
		}

		$current = get_option( self::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		$clean   = $current;

		if ( isset( $input['depot'] ) ) {
			$clean['depot'] = $this->sanitizeAddress( $input['depot'], $this->depotAddress(), __( 'depot', 'connect-the-dots' ) );
		}

		if ( isset( $input['end'] ) ) {
			$clean['end'] = $this->sanitizeAddress( $input['end'], $this->endAddress(), __( 'end address', 'connect-the-dots' ), true );
		}

		if ( isset( $input['vehicles'] ) ) {
			$clean['vehicles'] = $this->sanitizeVehicles( $input['vehicles'] );
		}

		if ( isset( $input['stop_minutes'] ) ) {
			$clean['stop_minutes'] = min( 240, max( 0, absint( $input['stop_minutes'] ) ) );
		}

		if ( isset( $input['statuses'] ) ) {
			$allowed  = array_keys( wc_get_order_statuses() );
			$statuses = array_values( array_intersect( array_map( 'sanitize_key', (array) $input['statuses'] ), $allowed ) );

			if ( array() === $statuses ) {
				$this->error( 'statuses_empty', __( 'Choose at least one order status to plan. The previous statuses are kept.', 'connect-the-dots' ) );
			} else {
				$clean['statuses'] = $statuses;
			}
		}

		if ( isset( $input['shipping_methods'] ) ) {
			$submitted                 = array_map( 'sanitize_text_field', array_map( 'strval', (array) $input['shipping_methods'] ) );
			$clean['shipping_methods'] = array_values( array_intersect( array_keys( $this->availableShippingMethods() ), $submitted ) );
		}

		if ( isset( $input['date_source'] ) ) {
			$source               = sanitize_key( (string) $input['date_source'] );
			$clean['date_source'] = in_array( $source, $this->dateSources, true ) ? $source : 'auto';
		}

		if ( isset( $input['add_order_note'] ) ) {
			$clean['add_order_note'] = $this->flag( $input['add_order_note'] );
		}

		if ( isset( $input['status_after_planning'] ) ) {
			$status                         = sanitize_key( (string) $input['status_after_planning'] );
			$clean['status_after_planning'] = array_key_exists( $status, wc_get_order_statuses() ) ? $status : null;
		}

		if ( isset( $input['schedule_enabled'] ) ) {
			$clean['schedule_enabled'] = $this->flag( $input['schedule_enabled'] );
		}

		if ( isset( $input['schedule_time'] ) ) {
			$time = $this->time( $input['schedule_time'] );

			if ( null === $time ) {
				$this->error( 'schedule_time', __( 'The planning time must be a time like 18:00. The previous time is kept.', 'connect-the-dots' ) );
			} else {
				$clean['schedule_time'] = $time;
			}
		}

		if ( isset( $input['schedule_day_offset'] ) ) {
			$clean['schedule_day_offset'] = min( 14, max( 0, absint( $input['schedule_day_offset'] ) ) );
		}

		return $clean;
	}

	/**
	 * A stored setting.
	 *
	 * @param string $key     Key in the option.
	 * @param mixed  $default Value when the setting was never saved.
	 *
	 * @return mixed
	 */
	private function stored( string $key, $default = null ) {
		$option = get_option( self::OPTION, array() );

		return is_array( $option ) && array_key_exists( $key, $option ) ? $option[ $key ] : $default;
	}

	/**
	 * The stored vans, as complete rows.
	 *
	 * @return array<int, array{id: string, name: string, available_from: string, available_until: string, capacity: ?int, active: bool}>
	 */
	private function storedVehicles(): array {
		$rows = $this->stored( 'vehicles', array() );
		$list = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || '' === (string) ( $row['id'] ?? '' ) ) {
				continue;
			}

			$list[] = array(
				'id'              => (string) $row['id'],
				'name'            => (string) ( $row['name'] ?? '' ),
				'available_from'  => (string) ( $row['available_from'] ?? '08:00' ),
				'available_until' => (string) ( $row['available_until'] ?? '17:00' ),
				'capacity'        => isset( $row['capacity'] ) && '' !== $row['capacity'] ? (int) $row['capacity'] : null,
				'active'          => (bool) ( $row['active'] ?? true ),
			);
		}

		return $list;
	}

	/**
	 * Cleans the submitted vans. A van with a blank name is dropped, a van with invalid times keeps its
	 * stored values, or is not added when it is new.
	 *
	 * @param mixed $rows Submitted rows.
	 */
	private function sanitizeVehicles( $rows ): array {
		$stored = array();

		foreach ( $this->storedVehicles() as $row ) {
			$stored[ $row['id'] ] = $row;
		}

		$names = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = is_array( $row ) ? mb_strtolower( trim( sanitize_text_field( (string) ( $row['name'] ?? '' ) ) ) ) : '';

			if ( '' === $name ) {
				continue;
			}

			if ( isset( $names[ $name ] ) ) {
				$this->error( 'vehicle_duplicate', __( 'Two vans have the same name. Give every van its own name. The previous vans are kept.', 'connect-the-dots' ) );

				return $this->storedVehicles();
			}

			$names[ $name ] = true;
		}

		$clean = array();
		$seen  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = trim( sanitize_text_field( (string) ( $row['name'] ?? '' ) ) );

			if ( '' === $name ) {
				continue;
			}

			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			$id = '' !== $id && ! isset( $seen[ $id ] ) ? $id : wp_generate_uuid4();

			$seen[ $id ] = true;
			$from        = $this->time( $row['available_from'] ?? '08:00' );
			$until       = $this->time( $row['available_until'] ?? '17:00' );

			if ( null === $from || null === $until || $from >= $until ) {
				$this->error(
					'vehicle_' . $id,
					sprintf(
						/* translators: %s: name of the van. */
						__( 'The times of %s are not valid: use times like 08:00 and let the van start before it ends. The previous times are kept.', 'connect-the-dots' ),
						$name
					)
				);

				if ( isset( $stored[ $id ] ) ) {
					$clean[] = $stored[ $id ];
				}

				continue;
			}

			$capacity = isset( $row['capacity'] ) ? absint( $row['capacity'] ) : 0;

			$clean[] = array(
				'id'              => $id,
				'name'            => $name,
				'available_from'  => $from,
				'available_until' => $until,
				'capacity'        => $capacity > 0 ? $capacity : null,
				'active'          => $this->flag( $row['active'] ?? false ),
			);
		}

		// Every name was cleared. A van whose times were refused already has its own error.
		if ( array() === $names ) {
			$this->error( 'vehicles_empty', __( 'Keep at least one van. The previous vans are kept.', 'connect-the-dots' ) );

			return $this->storedVehicles();
		}

		return $clean;
	}

	/**
	 * Cleans a submitted address. An invalid country keeps the current one.
	 *
	 * @param mixed  $address  Submitted address.
	 * @param array  $current  Address in use now.
	 * @param string $label    Name of the address for the error message.
	 * @param bool   $optional Whether a blank address is allowed, which means the depot is used.
	 */
	private function sanitizeAddress( $address, array $current, string $label, bool $optional = false ): array {
		$address = is_array( $address ) ? $address : array();
		$clean   = array(
			'street'   => sanitize_text_field( (string) ( $address['street'] ?? '' ) ),
			'postcode' => sanitize_text_field( (string) ( $address['postcode'] ?? '' ) ),
			'city'     => sanitize_text_field( (string) ( $address['city'] ?? '' ) ),
			'country'  => strtoupper( sanitize_text_field( (string) ( $address['country'] ?? '' ) ) ),
		);

		if ( $optional && ! $this->hasAddress( $clean ) ) {
			return $clean;
		}

		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $clean['country'] ) ) {
			$this->error(
				'country_' . sanitize_key( $label ),
				sprintf(
					/* translators: %s: the kind of address, like depot. */
					__( 'The country of the %s must be a two letter code like NL. The previous country is kept.', 'connect-the-dots' ),
					$label
				)
			);

			$clean['country'] = $current['country'];
		}

		return $clean;
	}

	/**
	 * Whether any field of an address is filled in.
	 *
	 * @param array $address Address.
	 */
	private function hasAddress( array $address ): bool {
		foreach ( array( 'street', 'postcode', 'city' ) as $field ) {
			if ( '' !== trim( (string) ( $address[ $field ] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gives an address all its keys.
	 *
	 * @param array $address Address.
	 *
	 * @return array{street: string, postcode: string, city: string, country: string}
	 */
	private function normaliseAddress( array $address ): array {
		return array(
			'street'   => (string) ( $address['street'] ?? '' ),
			'postcode' => (string) ( $address['postcode'] ?? '' ),
			'city'     => (string) ( $address['city'] ?? '' ),
			'country'  => (string) ( $address['country'] ?? '' ),
		);
	}

	/**
	 * Returns a valid H:i time, or null.
	 *
	 * @param mixed $value Submitted time.
	 */
	private function time( $value ): ?string {
		$value = trim( (string) $value );

		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : null;
	}

	/**
	 * Reads a submitted checkbox or hidden field as a bool.
	 *
	 * @param mixed $value Submitted value.
	 */
	private function flag( $value ): bool {
		return in_array( $value, array( true, 1, '1', 'on', 'yes' ), true );
	}

	/**
	 * Raises a settings error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message for the shop owner.
	 */
	private function error( string $code, string $message ): void {
		add_settings_error( self::OPTION, $code, $message );
	}
}
