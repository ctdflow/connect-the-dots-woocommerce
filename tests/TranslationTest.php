<?php

namespace ConnectTheDots\Tests;

use ConnectTheDots\Plugin;
use ConnectTheDots\Tests\Support\TestCase;
use PO;

class TranslationTest extends TestCase {

	private function dutch(): PO {
		require_once ABSPATH . WPINC . '/pomo/po.php';

		$po = new PO();
		$this->assertTrue( $po->import_from_file( dirname( __DIR__ ) . '/languages/connect-the-dots-nl_NL.po' ) );

		return $po;
	}

	/**
	 * The placeholders of a string, like %1$s or %d, in a comparable order.
	 */
	private function placeholders( string $text ): array {
		preg_match_all( '/%(?:\d+\$)?[sd]/', $text, $matches );
		$found = $matches[0];
		sort( $found );

		return $found;
	}

	public function test_the_plugin_loads_its_translation(): void {
		$this->assertNotFalse( has_action( 'init', array( Plugin::instance(), 'loadTextdomain' ) ) );
		$this->assertMatchesRegularExpression( '/^ \* Domain Path:\s+\/languages$/m', (string) file_get_contents( dirname( __DIR__ ) . '/connect-the-dots.php' ) );
	}

	public function test_the_dutch_header_is_filled(): void {
		$po = $this->dutch();

		$this->assertSame( 'nl_NL', $po->headers['Language'] );
		$this->assertSame( 'nplurals=2; plural=(n != 1);', $po->headers['Plural-Forms'] );
	}

	public function test_every_string_is_translated_and_keeps_its_placeholders(): void {
		foreach ( $this->dutch()->entries as $entry ) {
			foreach ( $entry->translations as $index => $translation ) {
				$this->assertNotSame( '', $translation, "Not translated: {$entry->singular}" );

				$source = 0 === $index ? $entry->singular : $entry->plural;

				$this->assertSame( $this->placeholders( $source ), $this->placeholders( $translation ), "Placeholders differ: {$entry->singular}" );
				$this->assertSame( substr_count( $source, '<' ), substr_count( $translation, '<' ), "HTML differs: {$entry->singular}" );
			}
		}
	}

	public function test_the_dutch_texts_use_the_agreed_terms(): void {
		$entries = $this->dutch()->entries;

		$this->assertSame( 'Bezorgritten', $entries['Delivery routes']->translations[0] );
		$this->assertSame( 'Bezorgrit', $entries['Delivery route']->translations[0] );
		$this->assertSame( 'Bezorgrit %1$s, verwachte aankomst %2$s', $entries['Delivery route %1$s, expected arrival %2$s']->translations[0] );
		$this->assertSame( 'Afgeleverd om %s', $entries['Delivered at %s']->translations[0] );
		$this->assertSame( 'Gratis', $entries['Free']->translations[0] );
		$this->assertSame( 'Proef', $entries['Trial']->translations[0] );
		$this->assertSame( 'Actief', $entries['Active']->translations[0] );
	}

	public function test_the_dutch_texts_use_no_dashes_as_punctuation(): void {
		foreach ( $this->dutch()->entries as $entry ) {
			foreach ( $entry->translations as $translation ) {
				$this->assertStringNotContainsString( '—', $translation );
				$this->assertStringNotContainsString( '–', $translation );
				$this->assertStringNotContainsString( ' - ', $translation );
			}
		}
	}
}
