<?php

namespace ConnectTheDots\Tests;

use ConnectTheDots\Plugin;
use ConnectTheDots\Tests\Support\TestCase;

class ReadmeTest extends TestCase {

	private function readme(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
	}

	/**
	 * The text of one "== Heading ==" section, up to the next section.
	 */
	private function section( string $heading ): string {
		$this->assertSame( 1, preg_match( '/^== ' . preg_quote( $heading, '/' ) . ' ==\R(.*?)(?=^== |\z)/ms', $this->readme(), $match ), "readme.txt has no section $heading." );

		return $match[1];
	}

	public function test_the_header_matches_the_plugin(): void {
		$readme = $this->readme();

		$this->assertStringStartsWith( '=== Connect the Dots: Delivery Route Planner for WooCommerce ===', $readme );
		$this->assertMatchesRegularExpression( '/^Stable tag: ' . preg_quote( Plugin::instance()->version(), '/' ) . '$/m', $readme );
		$this->assertMatchesRegularExpression( '/^Requires at least: 6\.5$/m', $readme );
		$this->assertMatchesRegularExpression( '/^Requires PHP: 8\.1$/m', $readme );
		$this->assertMatchesRegularExpression( '/^License: GPLv2 or later$/m', $readme );
	}

	public function test_the_stable_tag_matches_the_plugin_header(): void {
		$header = (string) file_get_contents( dirname( __DIR__ ) . '/connect-the-dots.php' );

		$this->assertMatchesRegularExpression( '/^ \* Version:\s+' . preg_quote( Plugin::instance()->version(), '/' ) . '$/m', $header );
	}

	public function test_the_tags_are_at_most_five(): void {
		$this->assertSame( 1, preg_match( '/^Tags: (.+)$/m', $this->readme(), $match ) );
		$this->assertLessThanOrEqual( 5, count( explode( ',', $match[1] ) ) );
	}

	public function test_the_external_services_section_names_the_service_and_the_data(): void {
		$section = $this->section( 'External services' );

		foreach ( array( 'Connect the Dots', 'https://api.ctdflow.com', 'https://ctdflow.com/terms', 'https://ctdflow.com/privacy' ) as $needle ) {
			$this->assertStringContainsString( $needle, $section );
		}

		foreach ( array( 'order id', 'delivery address', 'time window', 'number of items', 'name', 'start address', 'capacity' ) as $needle ) {
			$this->assertStringContainsStringIgnoringCase( $needle, $section );
		}

		$this->assertStringContainsStringIgnoringCase( 'customer names', $section );
		$this->assertStringContainsStringIgnoringCase( 'email addresses', $section );
		$this->assertStringContainsStringIgnoringCase( 'phone numbers', $section );
	}

	public function test_the_external_services_section_lists_everything_that_is_sent(): void {
		$section = $this->section( 'External services' );

		foreach ( array(
			'street with house number, postcode and city',
			'how long a stop takes',
			'delivery date',
			'time zone',
			'web address of your shop',
			'depot address and its country',
			'started by hand or by automatic planning',
			'host name of your shop',
			'return address',
			'version of the plugin and of WordPress',
		) as $needle ) {
			$this->assertStringContainsStringIgnoringCase( $needle, $section );
		}

		$this->assertStringNotContainsString( 'postcode, city and country', $section );
	}

	public function test_both_headers_require_woocommerce(): void {
		$this->assertMatchesRegularExpression( '/^Requires Plugins: woocommerce$/m', $this->readme() );
		$this->assertMatchesRegularExpression( '/^ \* Requires Plugins:\s+woocommerce$/m', (string) file_get_contents( dirname( __DIR__ ) . '/connect-the-dots.php' ) );
	}

	public function test_the_connect_screen_lists_everything_that_is_sent(): void {
		$ctdflow_message   = null;
		$ctdflow_selection = '';

		ob_start();
		include dirname( __DIR__ ) . '/views/connect.php';
		$html = (string) ob_get_clean();

		foreach ( array(
			'street with house number, postcode and city',
			'how long a stop takes',
			'the delivery date and the time zone of your site',
			'the web address of your shop',
			'the depot address and its country',
			'whether the plan was started by hand or automatically',
		) as $needle ) {
			$this->assertStringContainsString( $needle, $html );
		}

		$this->assertStringNotContainsString( 'postcode, city and country', $html );
	}

	public function test_it_has_the_sections_of_a_listing(): void {
		foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'Screenshots', 'Changelog' ) as $heading ) {
			$this->assertNotSame( '', trim( $this->section( $heading ) ) );
		}

		$screenshots = $this->section( 'Screenshots' );

		$this->assertMatchesRegularExpression( '/^1\. .+$/m', $screenshots );
		$this->assertMatchesRegularExpression( '/^2\. .+$/m', $screenshots );
		$this->assertMatchesRegularExpression( '/^3\. .+$/m', $screenshots );
	}

	public function test_it_uses_the_search_terms_of_the_listing(): void {
		$readme = strtolower( $this->readme() );

		foreach ( array( 'delivery route', 'route planner', 'route optimization', 'local delivery', 'delivery date' ) as $term ) {
			$this->assertStringContainsString( $term, $readme );
		}
	}

	public function test_it_says_that_the_service_decides_what_is_paid(): void {
		$faq = $this->section( 'Frequently Asked Questions' );

		$this->assertStringContainsStringIgnoringCase( 'free', $faq );
		$this->assertStringContainsStringIgnoringCase( 'paid', $faq );
		$this->assertStringContainsString( 'Connect the Dots decides', $faq );
	}

	public function test_it_uses_no_dashes_as_punctuation(): void {
		$readme = $this->readme();

		$this->assertStringNotContainsString( '—', $readme );
		$this->assertStringNotContainsString( '–', $readme );
		$this->assertDoesNotMatchRegularExpression( '/ - /', $readme );
	}
}
