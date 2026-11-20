<?php

namespace ConnectTheDots\Tests;

use ConnectTheDots\Admin\SettingsPage;
use ConnectTheDots\Connection;
use ConnectTheDots\Settings;
use ConnectTheDots\Tests\Support\TestCase;
use ConnectTheDots\Vehicle;
use WPDieException;

class SettingsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		global $wp_settings_errors;
		$wp_settings_errors = array();

		update_option( 'woocommerce_store_address', 'Keizersgracht 1' );
		update_option( 'woocommerce_store_address_2', '' );
		update_option( 'woocommerce_store_postcode', '1015 CJ' );
		update_option( 'woocommerce_store_city', 'Amsterdam' );
		update_option( 'woocommerce_default_country', 'NL' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		( new SettingsPage( new Settings(), new Connection() ) )->registerSetting();
	}

	public function test_the_defaults_come_from_the_woocommerce_store_options(): void {
		$settings = new Settings();

		$this->assertSame(
			array(
				'street'   => 'Keizersgracht 1',
				'postcode' => '1015 CJ',
				'city'     => 'Amsterdam',
				'country'  => 'NL',
			),
			$settings->depotAddress()
		);
		$this->assertSame( 3, $settings->stopMinutes() );
		$this->assertSame( array( 'wc-processing' ), $settings->statuses() );
		$this->assertSame( 'auto', $settings->dateSource() );
		$this->assertTrue( $settings->addOrderNote() );
		$this->assertNull( $settings->statusAfterPlanning() );
		$this->assertFalse( $settings->scheduleEnabled() );
		$this->assertSame( '18:00', $settings->scheduleTime() );
		$this->assertSame( 1, $settings->scheduleDayOffset() );
	}

	public function test_the_state_part_of_the_default_country_is_stripped_and_address_two_is_appended(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_store_address_2', 'Suite 4' );

		$depot = ( new Settings() )->depotAddress();

		$this->assertSame( 'US', $depot['country'] );
		$this->assertSame( 'Keizersgracht 1 Suite 4', $depot['street'] );
	}

	public function test_the_end_address_defaults_to_the_depot(): void {
		$settings = new Settings();

		$this->assertSame( $settings->depotAddress(), $settings->endAddress() );

		update_option( 'ctdflow_settings', array( 'end' => array( 'street' => 'Havenweg 2', 'postcode' => '3000 AB', 'city' => 'Rotterdam', 'country' => 'NL' ) ) );

		$this->assertSame( 'Rotterdam', $settings->endAddress()['city'] );
		$this->assertSame( 'Amsterdam', $settings->depotAddress()['city'] );
	}

	public function test_there_is_one_default_van_until_vans_are_saved(): void {
		$vehicles = ( new Settings() )->vehicles();

		$this->assertCount( 1, $vehicles );
		$this->assertInstanceOf( Vehicle::class, $vehicles[0] );
		$this->assertSame( '08:00', $vehicles[0]->availableFrom );
		$this->assertSame( '17:00', $vehicles[0]->availableUntil );
		$this->assertNull( $vehicles[0]->capacity );
		$this->assertTrue( $vehicles[0]->active );
	}

	public function test_saving_stores_the_settings_and_vans_get_a_stable_id(): void {
		update_option( 'ctdflow_settings', array( 'vehicles' => array( $this->van( 'Van A', '07:30', '16:00', '40' ) ), 'stop_minutes' => '5', 'schedule_enabled' => '1', 'schedule_time' => '19:15', 'schedule_day_offset' => '2' ) );

		$settings = new Settings();
		$van      = $settings->vehicles()[0];

		$this->assertSame( 'Van A', $van->name );
		$this->assertSame( '07:30', $van->availableFrom );
		$this->assertSame( 40, $van->capacity );
		$this->assertNotSame( '', $van->id );
		$this->assertSame( 5, $settings->stopMinutes() );
		$this->assertTrue( $settings->scheduleEnabled() );
		$this->assertSame( '19:15', $settings->scheduleTime() );
		$this->assertSame( 2, $settings->scheduleDayOffset() );

		update_option( 'ctdflow_settings', array( 'vehicles' => array( $this->van( 'Van A renamed', '07:30', '16:00', '', $van->id ) ) ) );

		$renamed = ( new Settings() )->vehicles()[0];

		$this->assertSame( $van->id, $renamed->id );
		$this->assertSame( 'Van A renamed', $renamed->name );
		$this->assertNull( $renamed->capacity );
	}

	public function test_a_second_van_can_be_saved(): void {
		update_option( 'ctdflow_settings', array( 'vehicles' => array( $this->van( 'Van A' ), $this->van( 'Van B', '09:00', '18:00' ) ) ) );

		$vehicles = ( new Settings() )->vehicles();

		$this->assertCount( 2, $vehicles );
		$this->assertSame( array( 'Van A', 'Van B' ), array( $vehicles[0]->name, $vehicles[1]->name ) );
		$this->assertNotSame( $vehicles[0]->id, $vehicles[1]->id );
	}

	public function test_a_van_with_a_blank_name_is_dropped(): void {
		update_option( 'ctdflow_settings', array( 'vehicles' => array( $this->van( 'Van A' ) ) ) );
		$id = ( new Settings() )->vehicles()[0]->id;

		update_option( 'ctdflow_settings', array( 'vehicles' => array( $this->van( '  ', '08:00', '17:00', '', $id ), $this->van( 'Van B' ) ) ) );

		$vehicles = ( new Settings() )->vehicles();

		$this->assertCount( 1, $vehicles );
		$this->assertSame( 'Van B', $vehicles[0]->name );
	}

	public function test_a_van_that_starts_after_it_ends_is_rejected_and_keeps_its_old_times(): void {
		update_option( 'ctdflow_settings', array( 'vehicles' => array( $this->van( 'Van A', '08:00', '17:00' ) ) ) );
		$id = ( new Settings() )->vehicles()[0]->id;

		update_option( 'ctdflow_settings', array( 'vehicles' => array( $this->van( 'Van A', '18:00', '09:00', '', $id ) ) ) );

		$van = ( new Settings() )->vehicles()[0];

		$this->assertSame( '08:00', $van->availableFrom );
		$this->assertSame( '17:00', $van->availableUntil );
		$this->assertCount( 1, get_settings_errors( 'ctdflow_settings' ) );
	}

	public function test_an_invalid_time_is_rejected_for_a_new_van_and_for_the_schedule(): void {
		update_option( 'ctdflow_settings', array( 'vehicles' => array( $this->van( 'Van A', '25:00', '17:00' ) ), 'schedule_time' => '6pm' ) );

		$settings = new Settings();

		$this->assertSame( 'Van 1', $settings->vehicles()[0]->name );
		$this->assertSame( '18:00', $settings->scheduleTime() );
		$this->assertCount( 2, get_settings_errors( 'ctdflow_settings' ) );
	}

	public function test_names_lose_their_tags(): void {
		update_option( 'ctdflow_settings', array( 'vehicles' => array( $this->van( '<b>Van</b> <script>alert(1)</script>A' ) ), 'depot' => array( 'street' => '<i>Dam 1</i>', 'postcode' => '1012 JS', 'city' => 'Amsterdam', 'country' => 'nl' ) ) );

		$settings = new Settings();

		$this->assertSame( 'Van A', $settings->vehicles()[0]->name );
		$this->assertSame( 'Dam 1', $settings->depotAddress()['street'] );
		$this->assertSame( 'NL', $settings->depotAddress()['country'] );
	}

	public function test_an_invalid_country_keeps_the_previous_one(): void {
		update_option( 'ctdflow_settings', array( 'depot' => array( 'street' => 'Dam 1', 'postcode' => '1012 JS', 'city' => 'Amsterdam', 'country' => 'Netherlands' ) ) );

		$this->assertSame( 'NL', ( new Settings() )->depotAddress()['country'] );
		$this->assertCount( 1, get_settings_errors( 'ctdflow_settings' ) );
	}

	public function test_unknown_choices_fall_back_to_safe_values(): void {
		update_option( 'ctdflow_settings', array( 'statuses' => array( 'wc-bogus' ), 'date_source' => 'bogus', 'status_after_planning' => 'wc-bogus' ) );

		$settings = new Settings();

		$this->assertSame( array( 'wc-processing' ), $settings->statuses() );
		$this->assertSame( 'auto', $settings->dateSource() );
		$this->assertNull( $settings->statusAfterPlanning() );

		update_option( 'ctdflow_settings', array( 'statuses' => array( 'wc-processing', 'wc-on-hold' ), 'status_after_planning' => 'wc-completed', 'add_order_note' => '0' ) );

		$this->assertSame( array( 'wc-processing', 'wc-on-hold' ), $settings->statuses() );
		$this->assertSame( 'wc-completed', $settings->statusAfterPlanning() );
		$this->assertFalse( $settings->addOrderNote() );
	}

	public function test_the_option_is_not_autoloaded(): void {
		update_option( 'ctdflow_settings', array( 'stop_minutes' => '4' ) );

		$this->assertSame( 4, ( new Settings() )->stopMinutes() );
		$this->assertArrayNotHasKey( 'ctdflow_settings', wp_load_alloptions( true ) );
	}

	public function test_a_non_admin_cannot_save(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );

		update_option( 'ctdflow_settings', array( 'stop_minutes' => '9' ) );
	}

	public function test_a_shop_manager_may_save_through_the_settings_api(): void {
		$this->assertSame( 'manage_woocommerce', apply_filters( 'option_page_capability_ctdflow_settings', 'manage_options' ) );
		$this->assertTrue( current_user_can( 'manage_woocommerce' ) );
	}

	public function test_duplicate_van_names_are_refused_and_the_old_vans_are_kept(): void {
		update_option( 'ctdflow_settings', array( 'vehicles' => array( $this->van( 'Van A' ) ) ) );
		$id = ( new Settings() )->vehicles()[0]->id;

		$clean = ( new Settings() )->sanitize( array( 'vehicles' => array( $this->van( 'Van A', '08:00', '17:00', '', $id ), $this->van( ' van a ' ), $this->van( 'Van B' ) ) ) );

		$this->assertCount( 1, $clean['vehicles'] );
		$this->assertSame( 'Van A', $clean['vehicles'][0]['name'] );
		$this->assertSame( 'vehicle_duplicate', get_settings_errors( Settings::OPTION )[0]['code'] );
	}

	public function test_the_settings_tab_warns_about_an_offset_timezone(): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 2 );

		ob_start();
		( new SettingsPage( new Settings(), new Connection() ) )->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'options-general.php', $html );

		update_option( 'timezone_string', 'Europe/Amsterdam' );

		ob_start();
		( new SettingsPage( new Settings(), new Connection() ) )->render();

		$this->assertStringNotContainsString( 'notice-warning', ob_get_clean() );
	}

	public function test_the_status_after_planning_says_that_woocommerce_mails_the_customer(): void {
		ob_start();
		( new SettingsPage( new Settings(), new Connection() ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'WooCommerce sends the customer the email of that status', $html );
		$this->assertStringContainsString( 'Completed sends the order complete email', $html );
	}

	public function test_all_shipping_methods_are_planned_until_some_are_chosen(): void {
		$this->assertSame( array(), ( new Settings() )->shippingMethods() );
	}

	public function test_the_shipping_methods_of_every_zone_and_the_rest_of_the_world_can_be_chosen(): void {
		$zone      = $this->zone( 'Nederland' );
		$flat_rate = $zone->add_shipping_method( 'flat_rate' );
		$world     = ( new \WC_Shipping_Zone( 0 ) )->add_shipping_method( 'free_shipping' );

		$methods = ( new Settings() )->availableShippingMethods();

		$this->assertArrayHasKey( 'flat_rate:' . $flat_rate, $methods );
		$this->assertArrayHasKey( 'free_shipping:' . $world, $methods );
		$this->assertStringStartsWith( 'Nederland: ', $methods[ 'flat_rate:' . $flat_rate ] );
	}

	public function test_saving_keeps_only_shipping_methods_that_exist(): void {
		$flat_rate = $this->zone( 'Nederland' )->add_shipping_method( 'flat_rate' );

		$clean = ( new Settings() )->sanitize( array( 'shipping_methods' => array( '', 'flat_rate:' . $flat_rate, 'flat_rate:999', '<b>bogus</b>' ) ) );

		$this->assertSame( array( 'flat_rate:' . $flat_rate ), $clean['shipping_methods'] );

		update_option( 'ctdflow_settings', array( 'shipping_methods' => array( 'flat_rate:' . $flat_rate ) ) );

		$this->assertSame( array( 'flat_rate:' . $flat_rate ), ( new Settings() )->shippingMethods() );
		$this->assertSame( array(), ( new Settings() )->sanitize( array( 'shipping_methods' => array( '' ) ) )['shipping_methods'] );
	}

	public function test_the_settings_tab_lists_the_shipping_methods_as_checkboxes(): void {
		$flat_rate = $this->zone( 'Nederland' )->add_shipping_method( 'flat_rate' );
		update_option( 'ctdflow_settings', array( 'shipping_methods' => array( 'flat_rate:' . $flat_rate ) ) );

		ob_start();
		( new SettingsPage( new Settings(), new Connection() ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Plan orders with shipping method', $html );
		$this->assertStringContainsString( '<input type="hidden" name="ctdflow_settings[shipping_methods][]" value="" />', $html );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" name="ctdflow_settings\[shipping_methods\]\[\]" value="flat_rate:' . $flat_rate . '" checked=\'checked\' \/> Nederland: /', $html );
	}

	private function zone( string $name ): \WC_Shipping_Zone {
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( $name );
		$zone->save();

		return $zone;
	}

	private function van( string $name, string $from = '08:00', string $until = '17:00', string $capacity = '', string $id = '' ): array {
		return array(
			'id'              => $id,
			'name'            => $name,
			'available_from'  => $from,
			'available_until' => $until,
			'capacity'        => $capacity,
			'active'          => '1',
		);
	}
}
