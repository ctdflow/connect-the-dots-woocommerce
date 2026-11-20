<?php
/**
 * The delivery route column of the WooCommerce order list.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots\Admin;

use ConnectTheDots\PlanStore;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the column ctdflow_route to the order list of both order storages: the HPOS screen and the
 * legacy post screen.
 */
class OrderListColumn {

	/**
	 * Name of the column.
	 *
	 * @var string
	 */
	private string $column = 'ctdflow_route';

	/**
	 * Creates the column.
	 *
	 * @param PlanStore $store Remembers the plan of every day.
	 */
	public function __construct( private PlanStore $store ) {
	}

	/**
	 * Builds the column with the real plan store.
	 */
	public static function create(): OrderListColumn {
		return new OrderListColumn( new PlanStore() );
	}

	/**
	 * Hooks the column into both order list screens.
	 */
	public function register(): void {
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'addColumn' ) );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'addColumn' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'renderForOrder' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'renderForPost' ), 10, 2 );
	}

	/**
	 * Adds the column after the status column, or at the end.
	 *
	 * @param mixed $columns Column keys and titles.
	 */
	public function addColumn( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();
		$title   = __( 'Delivery route', 'connect-the-dots' );

		if ( ! array_key_exists( 'order_status', $columns ) ) {
			$columns[ $this->column ] = $title;

			return $columns;
		}

		$result = array();

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$result[ $this->column ] = $title;
			}
		}

		return $result;
	}

	/**
	 * Renders the cell on the HPOS screen.
	 *
	 * @param string $column Column key.
	 * @param mixed  $order  The order.
	 */
	public function renderForOrder( $column, $order ): void {
		if ( $this->column === $column && $order instanceof \WC_Order ) {
			echo wp_kses_post( $this->cell( $order ) );
		}
	}

	/**
	 * Renders the cell on the legacy post screen.
	 *
	 * @param string $column  Column key.
	 * @param mixed  $post_id Id of the order.
	 */
	public function renderForPost( $column, $post_id ): void {
		if ( $this->column !== $column ) {
			return;
		}

		$order = wc_get_order( (int) $post_id );

		if ( $order instanceof \WC_Order ) {
			echo wp_kses_post( $this->cell( $order ) );
		}
	}

	/**
	 * The HTML of the cell, empty when the order has no current route and was not delivered.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function cell( \WC_Order $order ): string {
		$plan_id = (string) $order->get_meta( '_ctdflow_plan_id', true );
		$date    = (string) $order->get_meta( '_ctdflow_plan_date', true );

		$delivered = strtotime( (string) $order->get_meta( '_ctdflow_delivered_at', true ) );
		$expected  = strtotime( (string) $order->get_meta( '_ctdflow_expected_at', true ) );

		// Meta of a plan that was replaced is left over: only the plan stored for the day counts. A
		// delivered order keeps the route it was delivered on, also after the day was planned again.
		$is_current = '' !== $plan_id && $plan_id === $this->store->get( $date );

		if ( ! $is_current && false === $delivered ) {
			return '';
		}

		$html = esc_html( (string) $order->get_meta( '_ctdflow_vehicle', true ) );

		if ( $is_current && false !== $expected ) {
			$html .= ' · ' . esc_html( wp_date( 'H:i', $expected ) );
		}

		if ( false !== $delivered ) {
			$html .= ( '' !== $html ? '<br>' : '' ) . '✓ ' . esc_html( wp_date( 'H:i', $delivered ) );
		}

		return $html;
	}
}
