<?php

namespace ConnectTheDots\Tests;

use ConnectTheDots\ResultApplier;
use ConnectTheDots\Settings;
use ConnectTheDots\Tests\Support\TestCase;
use ConnectTheDots\Tests\Support\UsesOrderStorage;
use WC_Order;

class ResultApplierTest extends TestCase {

	use UsesOrderStorage;

	public function set_up(): void {
		parent::set_up();

		update_option( Settings::OPTION, array() );
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_it_writes_the_route_onto_the_orders( bool $hpos ): void {
		$this->useHpos( $hpos );
		$first  = $this->processing();
		$second = $this->processing();

		( new ResultApplier( new Settings() ) )->apply( $this->plan( 'opt_1', array( $first, $second ) ) );

		$first  = wc_get_order( $first->get_id() );
		$second = wc_get_order( $second->get_id() );

		$this->assertSame( 'opt_1', $first->get_meta( '_ctdflow_plan_id' ) );
		$this->assertSame( '2026-12-24', $first->get_meta( '_ctdflow_plan_date' ) );
		$this->assertSame( 'Van A', $first->get_meta( '_ctdflow_vehicle' ) );
		$this->assertSame( '1', (string) $first->get_meta( '_ctdflow_position' ) );
		$this->assertSame( '2026-12-24T08:12:00+01:00', $first->get_meta( '_ctdflow_expected_at' ) );
		$this->assertSame( 'opt_1', $first->get_meta( '_ctdflow_applied_plan_id' ) );
		$this->assertSame( '2', (string) $second->get_meta( '_ctdflow_position' ) );
		$this->assertSame( '2026-12-24T08:25:00+01:00', $second->get_meta( '_ctdflow_expected_at' ) );
	}

	public function test_it_adds_a_note_when_that_is_on(): void {
		$order = $this->processing();

		( new ResultApplier( new Settings() ) )->apply( $this->plan( 'opt_1', array( $order ) ) );

		$this->assertSame( array( 'Delivery route Van A, expected arrival 08:12' ), $this->notes( $order ) );
	}

	public function test_it_stores_and_notes_the_expected_time_and_not_an_early_arrival(): void {
		$order = $this->processing();
		$plan  = $this->plan( 'opt_1', array( $order ) );

		$plan['routes'][0]['stops'][0]['arrival']     = '2026-12-24T07:38:00+01:00';
		$plan['routes'][0]['stops'][0]['expected_at'] = '2026-12-24T09:00:00+01:00';

		( new ResultApplier( new Settings() ) )->apply( $plan );

		$this->assertSame( '2026-12-24T09:00:00+01:00', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_expected_at' ) );
		$this->assertSame( array( 'Delivery route Van A, expected arrival 09:00' ), $this->notes( $order ) );
	}

	public function test_it_adds_no_note_when_that_is_off(): void {
		update_option( Settings::OPTION, array( 'add_order_note' => false ) );
		$order = $this->processing();

		( new ResultApplier( new Settings() ) )->apply( $this->plan( 'opt_1', array( $order ) ) );

		$this->assertSame( array(), $this->notes( $order ) );
	}

	public function test_it_leaves_the_status_alone_by_default(): void {
		$order = $this->processing();

		( new ResultApplier( new Settings() ) )->apply( $this->plan( 'opt_1', array( $order ) ) );

		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_it_sets_the_chosen_status(): void {
		update_option( Settings::OPTION, array( 'status_after_planning' => 'wc-on-hold' ) );
		$order = $this->processing();

		( new ResultApplier( new Settings() ) )->apply( $this->plan( 'opt_1', array( $order ) ) );

		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_the_same_plan_is_applied_once(): void {
		update_option( Settings::OPTION, array( 'status_after_planning' => 'wc-on-hold' ) );
		$order   = $this->processing();
		$applier = new ResultApplier( new Settings() );

		$applier->apply( $this->plan( 'opt_1', array( $order ) ) );

		$reloaded = wc_get_order( $order->get_id() );
		$reloaded->set_status( 'processing' );
		$reloaded->update_meta_data( '_ctdflow_vehicle', 'changed by hand' );
		$reloaded->save();

		$applier->apply( $this->plan( 'opt_1', array( $order ) ) );

		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( 'processing', $reloaded->get_status() );
		$this->assertSame( 'changed by hand', $reloaded->get_meta( '_ctdflow_vehicle' ) );
		$this->assertCount( 1, $this->notes( $order ) );
	}

	public function test_a_plan_that_was_applied_is_skipped_as_a_whole(): void {
		$order   = $this->processing();
		$applier = new ResultApplier( new Settings() );

		$applier->apply( $this->plan( 'opt_1', array( $order ) ) );

		$reloaded = wc_get_order( $order->get_id() );
		$reloaded->delete_meta_data( '_ctdflow_applied_plan_id' );
		$reloaded->update_meta_data( '_ctdflow_vehicle', 'changed by hand' );
		$reloaded->save();

		$applier->apply( $this->plan( 'opt_1', array( $order ) ) );

		$this->assertSame( 'changed by hand', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_vehicle' ) );
		$this->assertCount( 1, $this->notes( $order ) );
	}

	public function test_a_plan_that_another_request_is_applying_is_skipped(): void {
		$order = $this->processing();
		add_option( 'ctdflow_apply_lock_' . md5( 'opt_1' ), time(), '', false );

		( new ResultApplier( new Settings() ) )->apply( $this->plan( 'opt_1', array( $order ) ) );

		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
	}

	public function test_an_old_apply_lock_is_taken_over_and_the_lock_is_released_after_applying(): void {
		$order = $this->processing();
		add_option( 'ctdflow_apply_lock_' . md5( 'opt_1' ), time() - 600, '', false );

		( new ResultApplier( new Settings() ) )->apply( $this->plan( 'opt_1', array( $order ) ) );

		$this->assertSame( 'opt_1', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
		$this->assertFalse( get_option( 'ctdflow_apply_lock_' . md5( 'opt_1' ) ) );
	}

	public function test_a_new_plan_adds_a_note_only_when_the_van_position_or_time_changed(): void {
		$order   = $this->processing();
		$other   = $this->processing();
		$applier = new ResultApplier( new Settings() );

		$applier->apply( $this->plan( 'opt_1', array( $order, $other ) ) );
		$applier->apply( $this->plan( 'opt_2', array( $order, $other ) ) );

		$this->assertCount( 1, $this->notes( $order ) );
		$this->assertSame( 'opt_2', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );

		$applier->apply( $this->plan( 'opt_3', array( $other, $order ) ) );

		$this->assertCount( 2, $this->notes( $order ) );
		$this->assertContains( 'Delivery route Van A, expected arrival 08:25', $this->notes( $order ) );

		$applier->apply( $this->plan( 'opt_4', array( $other, $order ), 'Van B' ) );

		$this->assertCount( 3, $this->notes( $order ) );
	}

	public function test_a_newer_plan_is_applied_again(): void {
		$order   = $this->processing();
		$applier = new ResultApplier( new Settings() );

		$applier->apply( $this->plan( 'opt_1', array( $order ) ) );
		$applier->apply( $this->plan( 'opt_2', array( $order ), 'Van B' ) );

		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( 'opt_2', $reloaded->get_meta( '_ctdflow_plan_id' ) );
		$this->assertSame( 'Van B', $reloaded->get_meta( '_ctdflow_vehicle' ) );
	}

	public function test_an_order_left_out_of_a_newer_plan_loses_its_route(): void {
		$moved   = $this->processing();
		$dropped = $this->processing();
		$applier = new ResultApplier( new Settings() );

		$applier->apply( $this->plan( 'opt_1', array( $moved, $dropped ) ) );

		$newer              = $this->plan( 'opt_2', array( $moved ) );
		$newer['unplanned'] = array( array( 'reference' => (string) $dropped->get_id(), 'reason' => 'address_not_found' ) );

		$applier->apply( $newer );

		$dropped = wc_get_order( $dropped->get_id() );

		foreach ( array( '_ctdflow_plan_id', '_ctdflow_plan_date', '_ctdflow_vehicle', '_ctdflow_position', '_ctdflow_expected_at', '_ctdflow_applied_plan_id' ) as $key ) {
			$this->assertSame( '', $dropped->get_meta( $key ), $key );
		}
	}

	/**
	 * @dataProvider orderStorages
	 */
	public function test_an_order_left_out_when_planning_the_day_again_loses_its_route( bool $hpos ): void {
		$this->useHpos( $hpos );
		$kept    = $this->processing();
		$dropped = $this->processing();
		$other   = $this->processing();
		$other->update_meta_data( '_ctdflow_plan_id', 'opt_other_day' );
		$other->update_meta_data( '_ctdflow_plan_date', '2026-12-23' );
		$other->save();
		$applier = new ResultApplier( new Settings() );

		$applier->apply( $this->plan( 'opt_a', array( $kept, $dropped ) ) );
		$applier->apply( $this->plan( 'opt_b', array( $kept ), 'Van B' ) );

		$dropped = wc_get_order( $dropped->get_id() );

		foreach ( array( '_ctdflow_plan_id', '_ctdflow_plan_date', '_ctdflow_vehicle', '_ctdflow_position', '_ctdflow_expected_at', '_ctdflow_applied_plan_id' ) as $key ) {
			$this->assertSame( '', $dropped->get_meta( $key ), $key );
		}

		$kept = wc_get_order( $kept->get_id() );

		$this->assertSame( 'opt_b', $kept->get_meta( '_ctdflow_plan_id' ) );
		$this->assertSame( 'opt_b', $kept->get_meta( '_ctdflow_applied_plan_id' ) );
		$this->assertSame( 'Van B', $kept->get_meta( '_ctdflow_vehicle' ) );
		$this->assertSame( 'opt_other_day', wc_get_order( $other->get_id() )->get_meta( '_ctdflow_plan_id' ) );
	}

	public function test_an_unplanned_order_planned_on_another_day_keeps_that_route(): void {
		$order = $this->processing();
		$order->update_meta_data( '_ctdflow_plan_id', 'opt_other_day' );
		$order->update_meta_data( '_ctdflow_plan_date', '2026-12-23' );
		$order->save();

		$plan              = $this->plan( 'opt_2', array() );
		$plan['unplanned'] = array( array( 'reference' => (string) $order->get_id(), 'reason' => 'unknown' ) );

		( new ResultApplier( new Settings() ) )->apply( $plan );

		$this->assertSame( 'opt_other_day', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
	}

	public function test_a_plan_that_did_not_succeed_changes_nothing(): void {
		$order          = $this->processing();
		$plan           = $this->plan( 'opt_1', array( $order ) );
		$plan['status'] = 'running';

		( new ResultApplier( new Settings() ) )->apply( $plan );

		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
	}

	public function test_a_superseded_plan_changes_nothing(): void {
		$order                 = $this->processing();
		$plan                  = $this->plan( 'opt_1', array( $order ) );
		$plan['superseded_by'] = 'opt_2';

		( new ResultApplier( new Settings() ) )->apply( $plan );

		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
		$this->assertSame( array(), $this->notes( $order ) );
	}

	public function test_unknown_references_are_skipped(): void {
		$plan                         = $this->plan( 'opt_1', array() );
		$plan['routes'][0]['stops'][] = array( 'reference' => '999999', 'position' => 1, 'arrival' => '2026-12-24T08:12:00+01:00', 'departure' => '2026-12-24T08:15:00+01:00', 'delivered_at' => null );
		$plan['unplanned'][]          = array( 'reference' => '999998', 'reason' => 'unknown' );

		( new ResultApplier( new Settings() ) )->apply( $plan );

		$this->assertTrue( true );
	}

	public function test_a_new_plan_keeps_a_delivery_it_carried_over(): void {
		$order   = $this->processing();
		$applier = new ResultApplier( new Settings() );
		$applier->apply( $this->plan( 'opt_1', array( $order ) ) );
		$this->markDelivered( $order );

		$plan = $this->plan( 'opt_2', array( $order ) );
		$plan['routes'][0]['stops'][0]['delivered_at'] = '2026-12-24T08:05:00+00:00';
		$applier->apply( $plan );

		$this->assertSame( '2026-12-24T08:05:00+00:00', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_delivered_at' ) );
		$this->assertSame( 'opt_2', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
	}

	public function test_a_delivered_order_that_is_planned_again_is_no_longer_delivered(): void {
		$order   = $this->processing();
		$applier = new ResultApplier( new Settings() );
		$applier->apply( $this->plan( 'opt_1', array( $order ) ) );
		$this->markDelivered( $order );

		$applier->apply( $this->plan( 'opt_2', array( $order ) ) );

		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_delivered_at' ) );
		$this->assertSame( 'opt_2', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
	}

	public function test_a_delivered_order_left_out_of_a_new_plan_keeps_its_delivery_and_route(): void {
		$order   = $this->processing();
		$other   = $this->processing();
		$applier = new ResultApplier( new Settings() );
		$applier->apply( $this->plan( 'opt_1', array( $order, $other ) ) );
		$this->markDelivered( $order );

		$applier->apply( $this->plan( 'opt_2', array( $other ) ) );

		$this->assertSame( '2026-12-24T08:05:00+00:00', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_delivered_at' ) );
		$this->assertSame( 'opt_1', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_plan_id' ) );
		$this->assertSame( 'Van A', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_vehicle' ) );
	}

	public function test_an_unplanned_order_keeps_its_delivery(): void {
		$order   = $this->processing();
		$applier = new ResultApplier( new Settings() );
		$applier->apply( $this->plan( 'opt_1', array( $order ) ) );
		$this->markDelivered( $order );

		$plan              = $this->plan( 'opt_2', array() );
		$plan['unplanned'] = array( array( 'reference' => (string) $order->get_id(), 'reason' => 'no_address' ) );
		$applier->apply( $plan );

		$this->assertSame( '2026-12-24T08:05:00+00:00', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_delivered_at' ) );
	}

	public function test_the_same_plan_applied_again_keeps_the_delivery(): void {
		$order   = $this->processing();
		$applier = new ResultApplier( new Settings() );
		$applier->apply( $this->plan( 'opt_1', array( $order ) ) );
		$this->markDelivered( $order );

		$applier->apply( $this->plan( 'opt_1', array( $order ) ) );

		$this->assertNotSame( '', wc_get_order( $order->get_id() )->get_meta( '_ctdflow_delivered_at' ) );
	}

	private function markDelivered( WC_Order $order ): void {
		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( '_ctdflow_delivered_at', '2026-12-24T08:05:00+00:00' );
		$order->save();
	}

	private function processing(): WC_Order {
		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->save();

		return $order;
	}

	/**
	 * A succeeded plan with one route over the orders, 13 minutes apart from 08:12.
	 *
	 * @param WC_Order[] $orders Orders in route order.
	 */
	private function plan( string $planId, array $orders, string $vehicle = 'Van A' ): array {
		$stops = array();

		foreach ( array_values( $orders ) as $index => $order ) {
			$arrival = ( new \DateTimeImmutable( '2026-12-24T08:12:00+01:00' ) )->modify( '+' . ( 13 * $index ) . ' minutes' );
			$stops[] = array(
				'reference'    => (string) $order->get_id(),
				'position'     => $index + 1,
				'arrival'      => $arrival->format( 'c' ),
				'expected_at'  => $arrival->format( 'c' ),
				'departure'    => $arrival->modify( '+3 minutes' )->format( 'c' ),
				'delivered_at' => null,
			);
		}

		return array(
			'plan_id'       => $planId,
			'status'        => 'succeeded',
			'delivery_date' => '2026-12-24',
			'superseded_by' => null,
			'routes'        => array(
				array(
					'vehicle'          => $vehicle,
					'driver_link'      => 'https://api.ctdflow.com/r/token-1',
					'distance_meters'  => 62000,
					'duration_seconds' => 13200,
					'stops'            => $stops,
				),
			),
			'unplanned'     => array(),
			'error'         => null,
			'free_tier'     => null,
		);
	}

	/**
	 * The texts of the route notes of an order, leaving out the notes WooCommerce adds on a status change.
	 *
	 * @return string[]
	 */
	private function notes( WC_Order $order ): array {
		$texts = array_map( static fn ( $note ) => $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		return array_values( array_filter( $texts, static fn ( string $text ) => str_starts_with( $text, 'Delivery route' ) ) );
	}
}
