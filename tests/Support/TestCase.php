<?php

namespace ConnectTheDots\Tests\Support;

use WP_UnitTestCase;

/**
 * Base class for the plugin's tests. Fails a test that made a request to Connect the Dots
 * without a registered FakeCtd response, even when the plugin handled the error gracefully.
 */
abstract class TestCase extends WP_UnitTestCase {

	public function tear_down(): void {
		try {
			$this->assertNoUnexpectedCtdRequests();
		} finally {
			parent::tear_down();
		}
	}

	protected function assertNoUnexpectedCtdRequests(): void {
		$fake = FakeCtd::release();

		if ( null === $fake ) {
			return;
		}

		$this->assertSame(
			array(),
			$fake->unexpected(),
			'Requests to Connect the Dots without a FakeCtd response.'
		);
	}
}
