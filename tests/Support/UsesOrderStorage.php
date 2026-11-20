<?php

namespace ConnectTheDots\Tests\Support;

use WC_Order;

/**
 * Lets a test run against the legacy post storage and against HPOS.
 *
 * The storage is switched by faking the WooCommerce option, because the real switch refuses to
 * run while orders are out of sync. Every order created or queried afterwards uses that storage.
 */
trait UsesOrderStorage {

	/**
	 * Storage modes for a data provider.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function orderStorages(): array {
		return array(
			'legacy post storage' => array( false ),
			'HPOS'                => array( true ),
		);
	}

	protected function useHpos( bool $hpos ): void {
		remove_all_filters( 'pre_option_woocommerce_custom_orders_table_enabled' );

		add_filter(
			'pre_option_woocommerce_custom_orders_table_enabled',
			static fn() => $hpos ? 'yes' : 'no'
		);
	}

	/**
	 * Creates an order with the given meta.
	 *
	 * @param array<string, mixed> $meta Meta key and value pairs.
	 */
	protected function orderWithMeta( array $meta ): WC_Order {
		$order = wc_create_order();

		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		$order->save();

		return $order;
	}
}
