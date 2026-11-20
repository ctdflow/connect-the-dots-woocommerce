<?php

namespace ConnectTheDots\Tests\DeliveryDates;

use ConnectTheDots\DeliveryDates\DeliveryDateSource;
use ConnectTheDots\DeliveryDates\DeliveryDateSources;
use ConnectTheDots\DeliveryDates\OrderDeliveryDateSource;
use ConnectTheDots\DeliveryDates\StatusOnlySource;
use ConnectTheDots\DeliveryDates\WooDeliverySource;
use ConnectTheDots\Tests\Support\TestCase;

class DeliveryDateSourcesTest extends TestCase {

	private string $odd = 'order-delivery-date-for-woocommerce/order_delivery_date.php';

	private string $woo = 'woo-delivery/coderockz-woo-delivery.php';

	public function test_detect_prefers_order_delivery_date_when_both_are_active(): void {
		$this->activate( array( $this->woo, $this->odd ) );

		$this->assertInstanceOf( OrderDeliveryDateSource::class, DeliveryDateSources::detect() );
	}

	public function test_detect_uses_the_only_active_plugin(): void {
		$this->activate( array( $this->woo ) );
		$this->assertInstanceOf( WooDeliverySource::class, DeliveryDateSources::detect() );

		$this->activate( array( $this->odd ) );
		$this->assertInstanceOf( OrderDeliveryDateSource::class, DeliveryDateSources::detect() );
	}

	public function test_detect_falls_back_to_the_status_only_source(): void {
		$this->assertInstanceOf( StatusOnlySource::class, DeliveryDateSources::detect() );
	}

	public function test_for_setting_maps_each_setting_id(): void {
		$sources = DeliveryDateSources::class;

		$this->assertInstanceOf( OrderDeliveryDateSource::class, $sources::forSetting( 'order-delivery-date' ) );
		$this->assertInstanceOf( WooDeliverySource::class, $sources::forSetting( 'woo-delivery' ) );
		$this->assertInstanceOf( StatusOnlySource::class, $sources::forSetting( 'status' ) );
	}

	public function test_an_explicit_setting_wins_over_detection(): void {
		$this->activate( array( $this->odd ) );

		$this->assertInstanceOf( WooDeliverySource::class, DeliveryDateSources::forSetting( 'woo-delivery' ) );
	}

	public function test_auto_and_unknown_ids_detect(): void {
		$this->activate( array( $this->woo ) );
		$sources = DeliveryDateSources::class;

		$this->assertInstanceOf( WooDeliverySource::class, $sources::forSetting( 'auto' ) );
		$this->assertInstanceOf( WooDeliverySource::class, $sources::forSetting( 'unknown' ) );
	}

	public function test_the_source_ids_match_the_settings_ids(): void {
		$sources = DeliveryDateSources::class;

		foreach ( array( 'order-delivery-date', 'woo-delivery', 'status' ) as $id ) {
			$this->assertSame( $id, $sources::forSetting( $id )->id() );
		}
	}

	public function test_the_status_only_source_knows_nothing_about_orders(): void {
		$source = new StatusOnlySource();
		$order  = wc_create_order();

		$this->assertTrue( $source->isAvailable() );
		$this->assertNull( $source->deliveryDate( $order ) );
		$this->assertNull( $source->timeWindow( $order ) );
		$this->assertFalse( $source->isPickup( $order ) );
		$this->assertSame( array( 'date_created' ), array_keys( $source->queryArgs( '2026-12-24' ) ) );
	}

	public function test_another_plugin_can_register_a_source_that_detection_uses(): void {
		$this->activate( array( $this->odd ) );
		$custom = $this->customSource( 'my-dates', true );
		add_filter( 'ctdflow_delivery_date_sources', static fn( array $sources ): array => array_merge( array( $custom, 'not a source' ), $sources ) );

		$this->assertSame( $custom, DeliveryDateSources::detect() );
		$this->assertSame( $custom, DeliveryDateSources::forSetting( 'auto' ) );
	}

	public function test_a_registered_source_that_is_not_available_is_passed_over(): void {
		$custom = $this->customSource( 'my-dates', false );
		add_filter( 'ctdflow_delivery_date_sources', static fn( array $sources ): array => array_merge( array( $custom ), $sources ) );

		$this->assertInstanceOf( StatusOnlySource::class, DeliveryDateSources::detect() );
	}

	private function customSource( string $id, bool $available ): DeliveryDateSource {
		return new class( $id, $available ) implements DeliveryDateSource {

			public function __construct( private string $id, private bool $available ) {
			}

			public function id(): string {
				return $this->id;
			}

			public function isAvailable(): bool {
				return $this->available;
			}

			public function deliveryDate( \WC_Order $order ): ?string {
				return null;
			}

			public function timeWindow( \WC_Order $order ): ?array {
				return null;
			}

			public function isPickup( \WC_Order $order ): bool {
				return false;
			}

			public function queryArgs( string $date ): array {
				return array();
			}
		};
	}

	/**
	 * @param string[] $plugins
	 */
	private function activate( array $plugins ): void {
		remove_all_filters( 'pre_option_active_plugins' );
		add_filter( 'pre_option_active_plugins', static fn() => $plugins );
	}
}
