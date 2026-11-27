<?php
/**
 * The Settings tab: registers the setting with the Settings API and renders the form.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots\Admin;

use ConnectTheDots\Account;
use ConnectTheDots\ApiError;
use ConnectTheDots\Connection;
use ConnectTheDots\PlanRequestBuilder;
use ConnectTheDots\ScheduledPlanning;
use ConnectTheDots\Settings;
use ConnectTheDots\Vehicle;

defined( 'ABSPATH' ) || exit;

/**
 * Form for the depot, the vans and the planning preferences.
 */
class SettingsPage {

	/**
	 * @param Settings     $settings   The plugin settings.
	 * @param Connection   $connection Connection with Connect the Dots.
	 * @param Account|null $account    The Connect the Dots account, to explain the free tier.
	 */
	public function __construct(
		private Settings $settings,
		private Connection $connection,
		private ?Account $account = null
	) {
	}

	/**
	 * Registers the setting. WordPress itself checks the nonce and the capability when options.php saves it.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'registerSetting' ) );
		add_filter( 'option_page_capability_' . Settings::OPTION, array( $this, 'capability' ) );
	}

	/**
	 * Registers ctdflow_settings with its sanitize callback.
	 */
	public function registerSetting(): void {
		register_setting(
			Settings::OPTION,
			Settings::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Capability needed to save the setting, instead of the manage_options of the Settings API.
	 */
	public function capability(): string {
		return 'manage_woocommerce';
	}

	/**
	 * Renders the Settings tab.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$name = Settings::OPTION;

		if ( ! PlanRequestBuilder::hasTimezone() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Your site timezone is a UTC offset. Routes cannot be planned until you choose a city as timezone.', 'connect-the-dots' ),
				esc_url( admin_url( 'options-general.php' ) ),
				esc_html__( 'Open Settings > General', 'connect-the-dots' )
			);
		}

		echo '<h2>' . esc_html__( 'Connection', 'connect-the-dots' ) . '</h2>';
		$this->renderConnection();

		echo '<form method="post" action="options.php">';
		settings_fields( Settings::OPTION );

		echo '<h2>' . esc_html__( 'Depot', 'connect-the-dots' ) . '</h2>';
		echo '<p>' . esc_html__( 'Your vans start here. Leave the end address empty when they return to the depot.', 'connect-the-dots' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$this->addressRows( $name . '[depot]', __( 'Depot', 'connect-the-dots' ), $this->settings->depotAddress() );
		$this->addressRows( $name . '[end]', __( 'End address', 'connect-the-dots' ), $this->endForForm() );
		echo '</table>';

		$account = $this->readAccount();

		echo '<h2>' . esc_html__( 'Vans', 'connect-the-dots' ) . '</h2>';
		echo '<p>' . esc_html__( 'Clear the name of a van to remove it. Fill in the empty row to add a van.', 'connect-the-dots' ) . '</p>';
		$this->renderFreeTierVans( $account );
		$this->renderVehicles( $name );

		echo '<h2>' . esc_html__( 'Planning', 'connect-the-dots' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		$this->planningRows( $name );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Scheduled planning', 'connect-the-dots' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		$this->scheduleRows( $name, $account );
		echo '</table>';

		submit_button();
		echo '</form>';
	}

	/**
	 * Shows whether the shop is connected, with the button to disconnect.
	 */
	private function renderConnection(): void {
		if ( ! $this->connection->isConnected() ) {
			echo '<p>' . esc_html__( 'Not connected. Connect your shop on the Routes tab.', 'connect-the-dots' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'Your shop is connected to Connect the Dots.', 'connect-the-dots' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ctdflow_disconnect" />';
		wp_nonce_field( 'ctdflow_disconnect' );
		submit_button( __( 'Disconnect', 'connect-the-dots' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The account of tomorrow, null when the shop is not connected or the account cannot be read.
	 */
	private function readAccount(): ?array {
		if ( null === $this->account || ! $this->connection->isConnected() ) {
			return null;
		}

		try {
			return $this->account->fetch( ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' ) );
		} catch ( ApiError $error ) {
			return null;
		}
	}

	/**
	 * On the free tier, says that a plan with more vans needs a paid plan. CTD decides this through the
	 * account, the plugin only explains it.
	 *
	 * @param array|null $account Account JSON, null when it is not known.
	 */
	private function renderFreeTierVans( ?array $account ): void {
		if ( null === $account ) {
			return;
		}

		$tier = Account::freeTier( $account );

		if ( null === $tier ) {
			return;
		}

		$text = sprintf(
			/* translators: %d: number of vans in a plan on the free tier. */
			_n(
				'On the free plan a plan uses %d van. Planning with more vans at once needs a paid plan.',
				'On the free plan a plan uses up to %d vans. Planning with more vans at once needs a paid plan.',
				$tier['max_vehicles'],
				'connect-the-dots'
			),
			$tier['max_vehicles']
		);

		if ( ! is_string( $account['pricing_url'] ?? null ) ) {
			echo '<p class="description">' . esc_html( $text ) . '</p>';
			return;
		}

		printf(
			'<p class="description">%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			esc_html( $text ),
			esc_url( $account['pricing_url'] ),
			esc_html__( 'See the plans', 'connect-the-dots' )
		);
	}

	/**
	 * The end address as stored, empty when the vans return to the depot.
	 *
	 * @return array{street: string, postcode: string, city: string, country: string}
	 */
	private function endForForm(): array {
		$end = $this->settings->endAddress();

		return $end === $this->settings->depotAddress()
			? array(
				'street'   => '',
				'postcode' => '',
				'city'     => '',
				'country'  => '',
			)
			: $end;
	}

	/**
	 * One row with the four fields of an address.
	 *
	 * @param string $name    Field name prefix.
	 * @param string $label   Row label.
	 * @param array  $address Current address.
	 */
	private function addressRows( string $name, string $label, array $address ): void {
		$fields = array(
			'street'   => __( 'Street and number', 'connect-the-dots' ),
			'postcode' => __( 'Postcode', 'connect-the-dots' ),
			'city'     => __( 'City', 'connect-the-dots' ),
			'country'  => __( 'Country code', 'connect-the-dots' ),
		);

		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';

		foreach ( $fields as $key => $placeholder ) {
			printf(
				'<input type="text" name="%s" value="%s" placeholder="%s" aria-label="%s" class="%s" /> ',
				esc_attr( $name . '[' . $key . ']' ),
				esc_attr( (string) $address[ $key ] ),
				esc_attr( $placeholder ),
				esc_attr( $label . ': ' . $placeholder ),
				'country' === $key ? 'small-text' : 'regular-text'
			);
		}

		echo '</td></tr>';
	}

	/**
	 * The table of vans, with one empty row to add a van.
	 *
	 * @param string $name Option name.
	 */
	private function renderVehicles( string $name ): void {
		$rows   = $this->settings->vehicles();
		$rows[] = new Vehicle( '', '', '08:00', '17:00', null, true );

		echo '<table class="widefat striped"><thead><tr>';

		foreach ( array( __( 'Name', 'connect-the-dots' ), __( 'Available from', 'connect-the-dots' ), __( 'Available until', 'connect-the-dots' ), __( 'Capacity (items)', 'connect-the-dots' ), __( 'Active', 'connect-the-dots' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( array_values( $rows ) as $index => $vehicle ) {
			$prefix = $name . '[vehicles][' . $index . ']';

			echo '<tr>';
			printf(
				'<td><input type="hidden" name="%1$s[id]" value="%2$s" /><input type="text" name="%1$s[name]" value="%3$s" class="regular-text" /></td>',
				esc_attr( $prefix ),
				esc_attr( $vehicle->id ),
				esc_attr( $vehicle->name )
			);
			printf( '<td><input type="time" name="%s[available_from]" value="%s" /></td>', esc_attr( $prefix ), esc_attr( $vehicle->availableFrom ) );
			printf( '<td><input type="time" name="%s[available_until]" value="%s" /></td>', esc_attr( $prefix ), esc_attr( $vehicle->availableUntil ) );
			printf( '<td><input type="number" min="1" name="%s[capacity]" value="%s" class="small-text" /></td>', esc_attr( $prefix ), esc_attr( null === $vehicle->capacity ? '' : (string) $vehicle->capacity ) );
			printf(
				'<td><input type="hidden" name="%1$s[active]" value="0" /><input type="checkbox" name="%1$s[active]" value="1"%2$s /></td>',
				esc_attr( $prefix ),
				checked( $vehicle->active, true, false )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Rows with the planning preferences.
	 *
	 * @param string $name Option name.
	 */
	private function planningRows( string $name ): void {
		$statuses = wc_get_order_statuses();

		echo '<tr><th scope="row">' . esc_html__( 'Minutes per stop', 'connect-the-dots' ) . '</th><td>';
		printf( '<input type="number" min="0" max="240" name="%s[stop_minutes]" value="%d" class="small-text" />', esc_attr( $name ), (int) $this->settings->stopMinutes() );
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Plan orders with status', 'connect-the-dots' ) . '</th><td>';

		foreach ( $statuses as $status => $label ) {
			printf(
				'<label><input type="checkbox" name="%s[statuses][]" value="%s"%s /> %s</label><br />',
				esc_attr( $name ),
				esc_attr( $status ),
				checked( in_array( $status, $this->settings->statuses(), true ), true, false ),
				esc_html( $label )
			);
		}

		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Plan orders with shipping method', 'connect-the-dots' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '[shipping_methods][]" value="" />';

		foreach ( $this->settings->availableShippingMethods() as $method => $label ) {
			printf(
				'<label><input type="checkbox" name="%s[shipping_methods][]" value="%s"%s /> %s</label><br />',
				esc_attr( $name ),
				esc_attr( $method ),
				checked( in_array( $method, $this->settings->shippingMethods(), true ), true, false ),
				esc_html( $label )
			);
		}

		echo '<p class="description">' . esc_html__( 'Choose none to plan orders with any shipping method. Orders that are picked up, and orders with only virtual products, are always left out.', 'connect-the-dots' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Delivery date comes from', 'connect-the-dots' ) . '</th><td>';
		$sources = array(
			'auto'                => __( 'Detect automatically', 'connect-the-dots' ),
			'order-delivery-date' => __( 'Order Delivery Date plugin', 'connect-the-dots' ),
			'woo-delivery'        => __( 'Woo Delivery plugin', 'connect-the-dots' ),
			'status'              => __( 'The order status only', 'connect-the-dots' ),
		);
		$this->select( $name . '[date_source]', $sources, $this->settings->dateSource() );
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Order note', 'connect-the-dots' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '[add_order_note]" value="0" />';
		printf(
			'<label><input type="checkbox" name="%s[add_order_note]" value="1"%s /> %s</label>',
			esc_attr( $name ),
			checked( $this->settings->addOrderNote(), true, false ),
			esc_html__( 'Add a note to an order when it is planned', 'connect-the-dots' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Status after planning', 'connect-the-dots' ) . '</th><td>';
		$this->select( $name . '[status_after_planning]', array( '' => __( 'Do not change the status', 'connect-the-dots' ) ) + $statuses, (string) $this->settings->statusAfterPlanning() );
		echo '<p class="description">' . esc_html__( 'WooCommerce sends the customer the email of that status, for example Completed sends the order complete email.', 'connect-the-dots' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Rows with the scheduled planning preferences. The fields are disabled unless the account of Connect
	 * the Dots says it is paid. Disabled fields are not submitted, so a saved choice stays as it was.
	 *
	 * @param string     $name    Option name.
	 * @param array|null $account Account JSON, null when the shop is not connected or the account cannot be read.
	 */
	private function scheduleRows( string $name, ?array $account ): void {
		$paid     = null !== $account && ! empty( $account['paid'] );
		$disabled = $paid ? '' : ' disabled';

		if ( ! $paid ) {
			$pricing = is_string( $account['pricing_url'] ?? null ) && '' !== $account['pricing_url'] ? $account['pricing_url'] : 'https://ctdflow.com/pricing';

			printf(
				'<tr><td colspan="2"><div class="notice notice-info inline"><p>%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p></div></td></tr>',
				esc_html__( 'Scheduled planning needs a paid plan. Connect your shop and choose a plan to turn it on.', 'connect-the-dots' ),
				esc_url( $pricing ),
				esc_html__( 'See the plans', 'connect-the-dots' )
			);
		}

		echo '<tr><th scope="row">' . esc_html__( 'Plan automatically', 'connect-the-dots' ) . '</th><td>';

		// An unpaid shop can still turn scheduling off: the checkbox stays usable while it is on.
		$can_toggle = $paid || $this->settings->scheduleEnabled();

		if ( $can_toggle ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '[schedule_enabled]" value="0" />';
		}

		printf(
			'<label><input type="checkbox" name="%s[schedule_enabled]" value="1"%s%s /> %s</label>',
			esc_attr( $name ),
			checked( $this->settings->scheduleEnabled(), true, false ),
			esc_attr( $can_toggle ? '' : ' disabled' ),
			esc_html__( 'Plan routes every day at the time below', 'connect-the-dots' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Time', 'connect-the-dots' ) . '</th><td>';
		printf( '<input type="time" name="%s[schedule_time]" value="%s"%s />', esc_attr( $name ), esc_attr( $this->settings->scheduleTime() ), esc_attr( $disabled ) );
		echo ' <span class="description">' . esc_html__( 'In the timezone of your site.', 'connect-the-dots' ) . '</span>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Days ahead', 'connect-the-dots' ) . '</th><td>';
		printf( '<input type="number" min="0" max="14" name="%s[schedule_day_offset]" value="%d" class="small-text"%s />', esc_attr( $name ), (int) $this->settings->scheduleDayOffset(), esc_attr( $disabled ) );
		echo ' <span class="description">' . esc_html__( '1 is tomorrow, 0 is today.', 'connect-the-dots' ) . '</span>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Last automatic run', 'connect-the-dots' ) . '</th><td>';
		echo esc_html( $this->lastRunText() );
		echo '<p class="description">' . esc_html__( 'WooCommerce starts the planning with Action Scheduler, which runs when someone visits your site, so on a quiet site the planning can start late. For an exact time, let a real cron job call wp-cron.php every few minutes. The planned runs are listed under WooCommerce > Status > Scheduled Actions.', 'connect-the-dots' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * When the last automatic run happened, in the site timezone, and how it ended.
	 */
	private function lastRunText(): string {
		$last = ScheduledPlanning::lastRun();

		if ( null === $last ) {
			return __( 'Has not run yet.', 'connect-the-dots' );
		}

		$when = wp_date( 'Y-m-d H:i', $last['time'] );

		switch ( $last['outcome'] ) {
			case 'succeeded':
				/* translators: %s: date and time. */
				return sprintf( __( '%s: planned.', 'connect-the-dots' ), $when );
			case 'sent':
				/* translators: %s: date and time. */
				return sprintf( __( '%s: sent, the routes are not ready yet.', 'connect-the-dots' ), $when );
			case 'refused':
				if ( 'already_planned' === $last['code'] ) {
					/* translators: 1: date and time, 2: delivery date. */
					return sprintf( __( '%1$s: not planned, because %2$s already has routes. Plan that day again on the Routes tab to replace them.', 'connect-the-dots' ), $when, $last['date'] );
				}

				if ( 'new_orders' === $last['code'] ) {
					/* translators: 1: date and time, 2: delivery date. */
					return sprintf( __( '%1$s: not planned, because %2$s already has routes. New orders came in since and are not on them. Plan that day again on the Routes tab to add them.', 'connect-the-dots' ), $when, $last['date'] );
				}

				/* translators: 1: date and time, 2: reason code. */
				return sprintf( __( '%1$s: not planned (%2$s).', 'connect-the-dots' ), $when, $last['code'] );
			default:
				/* translators: 1: date and time, 2: reason code. */
				return sprintf( __( '%1$s: failed (%2$s).', 'connect-the-dots' ), $when, $last['code'] );
		}
	}

	/**
	 * A select field.
	 *
	 * @param string               $name    Field name.
	 * @param array<string,string> $options Value to label.
	 * @param string               $current Selected value.
	 */
	private function select( string $name, array $options, string $current ): void {
		echo '<select name="' . esc_attr( $name ) . '">';

		foreach ( $options as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $value ), selected( (string) $value, $current, false ), esc_html( $label ) );
		}

		echo '</select>';
	}
}
