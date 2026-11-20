<?php

namespace ConnectTheDots\Tests\Admin;

use ConnectTheDots\Admin\OrderListColumn;
use ConnectTheDots\PlanStore;
use ConnectTheDots\Tests\Support\TestCase;
use ConnectTheDots\Tests\Support\UsesOrderStorage;
use WC_Order;

class OrderListColumnTest extends TestCase {

	use UsesOrderStorage;

	public function set_up(): void {
		parent::set_up();

		update_option( 'timezone_string', 'Europe/Amsterdam' );
		update_option( 'ctdflow_plans', array() );
		remove_all_filters( 'manage_woocommerce_page_wc-orders_columns' );
		remove_all_filters( 'manage_edit-shop_order_columns' );
		remove_all_actions( 'manage_woocommerce_page_wc-orders_custom_column' );
		remove_all_actions( 'manage_shop_order_posts_custom_column' );

		OrderListColumn::create()->register();
	}

	public function test_the_column_comes_after_the_status_column_on_both_screens(): void {
		$columns = array(
			'cb'           => '',
			'order_number' => 'Order',
			'order_status' => 'Status',
			'order_total'  => 'Total',
		);

		foreach ( array( 'manage_woocommerce_page_wc-orders_columns', 'manage_edit-shop_order_columns' ) as $filter ) {
			$result = apply_filters( $filter, $columns );

			$this->assertSame( array( 'cb', 'order_number', 'order_status', 'ctdflow_route', 'order_total' ), array_keys( $result ), $filter );
			$this->assertSame( 'Delivery route', $result['ctdflow_route'] );
		}
	}

	public function test_the_column_is_appended_without_a_status_column(): void {
		$result = apply_filters( 'manage_edit-shop_order_columns', array( 'cb' => '', 'order_total' => 'Total' ) );

		$this->assertSame( array( 'cb', 'order_total', 'ctdflow_route' ), array_keys( $result ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_a_planned_order_shows_vehicle_and_arrival_in_the_site_timezone( bool $hpos ): void {
		$this->useHpos( $hpos );
		( new PlanStore() )->put( '2026-12-24', 'opt_1' );
		$order = $this->planned( 'opt_1' );

		$this->assertSame( 'Van A · 08:12', $this->cell( $order, $hpos ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_a_delivered_order_shows_the_check_and_the_time( bool $hpos ): void {
		$this->useHpos( $hpos );
		( new PlanStore() )->put( '2026-12-24', 'opt_1' );
		$order = $this->planned( 'opt_1', array( '_ctdflow_delivered_at' => '2026-12-24T08:05:00+00:00' ) );

		$this->assertSame( 'Van A · 08:12<br>✓ 09:05', $this->cell( $order, $hpos ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_a_delivered_order_of_a_replaced_plan_still_shows_the_check( bool $hpos ): void {
		$this->useHpos( $hpos );
		( new PlanStore() )->put( '2026-12-24', 'opt_2' );
		$order = $this->planned( 'opt_1', array( '_ctdflow_delivered_at' => '2026-12-24T08:05:00+00:00' ) );

		$this->assertSame( 'Van A<br>✓ 09:05', $this->cell( $order, $hpos ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_an_unplanned_order_has_an_empty_cell( bool $hpos ): void {
		$this->useHpos( $hpos );
		( new PlanStore() )->put( '2026-12-24', 'opt_1' );

		$this->assertSame( '', $this->cell( $this->orderWithMeta( array() ), $hpos ) );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_meta_of_a_plan_that_is_no_longer_current_is_not_shown( bool $hpos ): void {
		$this->useHpos( $hpos );
		( new PlanStore() )->put( '2026-12-24', 'opt_2' );

		$this->assertSame( '', $this->cell( $this->planned( 'opt_1' ), $hpos ) );
	}

	public function test_other_columns_stay_empty(): void {
		$order = $this->planned( 'opt_1' );
		( new PlanStore() )->put( '2026-12-24', 'opt_1' );

		ob_start();
		do_action( 'manage_woocommerce_page_wc-orders_custom_column', 'order_total', $order );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_output_is_escaped(): void {
		( new PlanStore() )->put( '2026-12-24', 'opt_1' );
		$order = $this->planned( 'opt_1', array( '_ctdflow_vehicle' => '<script>x</script>' ) );

		$cell = $this->cell( $order, true );

		$this->assertStringNotContainsString( '<script>', $cell );
		$this->assertStringContainsString( '&lt;script&gt;', $cell );
	}

	private function planned( string $planId, array $extra = array() ): WC_Order {
		return $this->orderWithMeta(
			array_merge(
				array(
					'_ctdflow_plan_id'   => $planId,
					'_ctdflow_plan_date' => '2026-12-24',
					'_ctdflow_vehicle'   => 'Van A',
					'_ctdflow_position'  => 1,
					'_ctdflow_expected_at' => '2026-12-24T07:12:00+00:00',
				),
				$extra
			)
		);
	}

	private function cell( WC_Order $order, bool $hpos ): string {
		$order = wc_get_order( $order->get_id() );

		ob_start();

		if ( $hpos ) {
			do_action( 'manage_woocommerce_page_wc-orders_custom_column', 'ctdflow_route', $order );
		} else {
			do_action( 'manage_shop_order_posts_custom_column', 'ctdflow_route', $order->get_id() );
		}

		return ob_get_clean();
	}
}
