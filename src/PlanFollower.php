<?php
/**
 * Reads the newest plan of a delivery day.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the pending plan of a day, making it the current plan once it succeeded, and follows
 * superseded_by from the current plan of a day to the newest plan and stores that one.
 */
class PlanFollower {

	/**
	 * Number of superseded_by links that are followed at most.
	 *
	 * @var int
	 */
	private int $maxLinks = 5;

	/**
	 * Creates the follower.
	 *
	 * @param ApiClient $client Client for the Connect the Dots API.
	 * @param PlanStore $store  Remembers the plan of every day.
	 */
	public function __construct( private ApiClient $client, private PlanStore $store ) {
	}

	/**
	 * The pending plan of a day while it is queued, running or failed. Null when the day has no pending
	 * plan, or when it succeeded: then it is stored as the current plan of the day and forgotten as
	 * pending, and latest() returns it. A pending plan that expired is forgotten.
	 *
	 * @param string $date Delivery date as Y-m-d.
	 *
	 * @throws ApiError When the pending plan cannot be read.
	 */
	public function pending( string $date ): ?array {
		$plan_id = $this->store->pending( $date );

		if ( null === $plan_id ) {
			return null;
		}

		try {
			$plan = $this->client->get( '/v1/woocommerce/plans/' . rawurlencode( $plan_id ) );
		} catch ( ApiError $error ) {
			if ( 'plan_not_found' !== $error->errorCode ) {
				throw $error;
			}

			$this->store->forgetPending( $date );

			return null;
		}

		if ( 'succeeded' !== ( $plan['status'] ?? '' ) ) {
			return $plan;
		}

		$this->store->put( $date, $plan_id );
		$this->store->forgetPending( $date );

		return null;
	}

	/**
	 * The plan of a day, following superseded_by to the newest plan and storing that one for the day.
	 * A superseded plan is never returned unless the chain is longer than the link limit.
	 *
	 * @param string $date   Delivery date as Y-m-d.
	 * @param string $planId Stored plan id.
	 *
	 * @throws ApiError When a plan cannot be read.
	 */
	public function latest( string $date, string $planId ): array {
		$plan  = $this->client->get( '/v1/woocommerce/plans/' . rawurlencode( $planId ) );
		$links = 0;

		while ( is_string( $plan['superseded_by'] ?? null ) && '' !== $plan['superseded_by'] && $links < $this->maxLinks ) {
			$planId = $plan['superseded_by'];
			$this->store->put( $date, $planId );
			$plan = $this->client->get( '/v1/woocommerce/plans/' . rawurlencode( $planId ) );
			++$links;
		}

		return $plan;
	}

	/**
	 * Message for a plan that failed at Connect the Dots.
	 *
	 * @param string $code  The error.code of the plan.
	 * @param array  $error The error of the plan, which names the van when its depot or end address cannot be reached.
	 */
	public static function failureMessage( string $code, array $error = array() ): string {
		$vehicle = is_string( $error['vehicle'] ?? null ) ? $error['vehicle'] : '';

		if ( 'location_unreachable' === $code && 'start' === ( $error['address'] ?? null ) ) {
			return __( 'No road leads to the depot. Check the depot address on the Settings tab.', 'connect-the-dots' );
		}

		if ( 'location_unreachable' === $code && 'end' === ( $error['address'] ?? null ) && '' !== $vehicle ) {
			/* translators: %s: name of the van. */
			return sprintf( __( 'No road leads to the end address of %s. Check it on the Settings tab.', 'connect-the-dots' ), $vehicle );
		}

		switch ( $code ) {
			case 'routing_unavailable':
				return __( 'Route planning is temporarily unavailable. Try again in a few minutes.', 'connect-the-dots' );
			case 'location_unreachable':
				return __( 'Some addresses cannot be reached by road. Check the addresses and plan again.', 'connect-the-dots' );
			case 'solver_timeout':
				return __( 'Planning took too long. Try again with fewer orders or more vans.', 'connect-the-dots' );
			case 'cancelled':
				return __( 'The planning was cancelled. Plan again to get routes.', 'connect-the-dots' );
			default:
				return __( 'The planning failed. Try again in a few minutes.', 'connect-the-dots' );
		}
	}
}
