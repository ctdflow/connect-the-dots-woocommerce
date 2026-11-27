<?php
/**
 * The Routes tab: pick a day, see its orders, plan them and see the routes.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots\Admin;

use ConnectTheDots\Account;
use ConnectTheDots\ApiClient;
use ConnectTheDots\ApiError;
use ConnectTheDots\Connection;
use ConnectTheDots\DeliveryDates\DeliveryDateSources;
use ConnectTheDots\OrderSelection;
use ConnectTheDots\PlanningNotPossible;
use ConnectTheDots\PlanFollower;
use ConnectTheDots\PlanStore;
use ConnectTheDots\Planner;
use ConnectTheDots\Plugin;
use ConnectTheDots\ResultApplier;
use ConnectTheDots\SelectedOrder;
use ConnectTheDots\Settings;
use ConnectTheDots\Vehicle;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Routes tab and answers its two AJAX actions: ctdflow_plan sends a plan, ctdflow_plan_status
 * reads it back, applies it once it succeeded and returns the rendered routes.
 */
class RoutesPage {

	/**
	 * Creates the page.
	 *
	 * @param Connection     $connection Connection with Connect the Dots.
	 * @param Settings       $settings   The plugin settings.
	 * @param Account        $account    The Connect the Dots account.
	 * @param Planner        $planner    Sends plans.
	 * @param PlanStore      $store      Remembers the plan of every day.
	 * @param ResultApplier  $applier    Writes plans onto the orders.
	 * @param ApiClient      $client     Client for the Connect the Dots API.
	 * @param OrderSelection $selection  Selects the orders of a day.
	 */
	public function __construct(
		private Connection $connection,
		private Settings $settings,
		private Account $account,
		private Planner $planner,
		private PlanStore $store,
		private ResultApplier $applier,
		private ApiClient $client,
		private OrderSelection $selection
	) {
	}

	/**
	 * Builds the page with the real connection and settings.
	 */
	public static function create(): RoutesPage {
		$settings   = new Settings();
		$connection = new Connection();
		$client     = new ApiClient( $connection );

		return new RoutesPage(
			$connection,
			$settings,
			new Account( $client ),
			Planner::create(),
			new PlanStore(),
			new ResultApplier( $settings ),
			$client,
			new OrderSelection( $settings )
		);
	}

	/**
	 * Hooks the AJAX actions into WordPress.
	 */
	public function register(): void {
		add_action( 'wp_ajax_ctdflow_plan', array( $this, 'handlePlan' ) );
		add_action( 'wp_ajax_ctdflow_plan_status', array( $this, 'handleStatus' ) );
	}

	/**
	 * Loads the script and style of the page. Menu calls this on the page only.
	 */
	public function enqueue(): void {
		$file    = dirname( __DIR__, 2 ) . '/connect-the-dots.php';
		$version = Plugin::instance()->version();

		wp_enqueue_style( 'ctdflow-routes-page', plugins_url( 'assets/routes-page.css', $file ), array(), $version );
		wp_enqueue_script( 'ctdflow-routes-page', plugins_url( 'assets/routes-page.js', $file ), array(), $version, true );
		wp_localize_script(
			'ctdflow-routes-page',
			'ctdflowRoutes',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'ctdflow_routes' ),
				'interval' => 2000,
				'timeout'  => 60000,
				'strings'  => array(
					'planning'     => __( 'Planning the routes…', 'connect-the-dots' ),
					'slow'         => __( 'Planning takes longer than usual. Reload the page in a minute to see the routes.', 'connect-the-dots' ),
					'failed'       => __( 'Something went wrong. Reload the page and try again.', 'connect-the-dots' ),
					'expired'      => __( 'Your session expired. Reload the page.', 'connect-the-dots' ),
					'copied'       => __( 'Copied', 'connect-the-dots' ),
					'copyManually' => __( 'Selected. Press Ctrl+C or ⌘C.', 'connect-the-dots' ),
				),
			)
		);
	}

	/**
	 * Renders the Routes tab.
	 */
	public function render(): void {
		if ( ! $this->connection->isConnected() ) {
			$this->renderConnect( null );
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Viewing a day changes nothing.
		$date  = $this->requestedDate( isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '' );
		$added = $this->orderIds( isset( $_GET['add'] ) ? sanitize_text_field( wp_unslash( $_GET['add'] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$restored = $this->takeSelection( $date );

		if ( null !== $restored ) {
			$added = array_values( array_unique( array_merge( $added, $restored['added'], $restored['order_ids'] ) ) );
		}

		$account       = null;
		$account_error = null;

		try {
			$account = $this->account->fetch( $date );
		} catch ( ApiError $error ) {
			if ( 'connection_lost' === $error->errorCode ) {
				$this->renderConnect(
					$error->userMessage,
					array(
						'date'        => $date,
						'added'       => $added,
						'order_ids'   => array(),
						'vehicle_ids' => array(),
					)
				);
				return;
			}

			$account_error = $error->userMessage;
		}

		$countries = is_array( $account['supported_countries'] ?? null ) ? array_map( 'strval', $account['supported_countries'] ) : null;
		$selection = $this->selection->forDay( $date, $added, array(), $countries );
		$vans      = $this->activeVans();
		$tier      = null === $account ? null : Account::freeTier( $account );

		$ctdflow_date          = $date;
		$ctdflow_added         = implode( ',', $added );
		$ctdflow_account       = $account;
		$ctdflow_account_error = $account_error;
		$ctdflow_plan_label    = null === $account ? '' : $this->planLabel( (string) ( $account['billing_status'] ?? '' ) );
		$ctdflow_tier_html     = $this->tierHtml( $tier );
		$ctdflow_orders        = array_map( array( $this, 'orderRow' ), $selection['plannable'] );
		$ctdflow_unplannable   = array_map( array( $this, 'unplannableRow' ), $selection['unplannable'] );
		$ctdflow_delivered     = $selection['delivered'];
		$ctdflow_vans          = $vans;
		$ctdflow_checked_vans  = array();

		// A selection kept over a new connection checks only the orders and vans that were chosen.
		foreach ( $ctdflow_orders as $index => $row ) {
			$ctdflow_orders[ $index ]['checked'] = null === $restored || array() === $restored['order_ids'] || in_array( $row['id'], $restored['order_ids'], true );
		}

		foreach ( $vans as $vehicle ) {
			if ( null === $restored || array() === $restored['vehicle_ids'] || in_array( $vehicle->id, $restored['vehicle_ids'], true ) ) {
				$ctdflow_checked_vans[] = $vehicle->id;
			}
		}

		$checked_orders = count( array_filter( array_column( $ctdflow_orders, 'checked' ) ) );
		$ctdflow_choice     = null === $tier ? '' : $this->choiceHtml( $tier, $checked_orders, count( $ctdflow_checked_vans ), (array) $account );
		$ctdflow_result        = $this->result( $date );

		include dirname( __DIR__, 2 ) . '/views/routes-page.php';
	}

	/**
	 * AJAX ctdflow_plan: plans the posted orders over the posted vans.
	 */
	public function handlePlan(): void {
		check_ajax_referer( 'ctdflow_routes', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( $this->forbidden(), 403 );
		}

		$date        = $this->requestedDate( isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '' );
		$order_ids   = isset( $_POST['order_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['order_ids'] ) ) ) ) : array();
		$vehicle_ids = isset( $_POST['vehicle_ids'] ) ? array_values( array_map( 'sanitize_key', (array) wp_unslash( $_POST['vehicle_ids'] ) ) ) : array();
		$choice      = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';

		wp_send_json( $this->plan( $date, $order_ids, $vehicle_ids, in_array( $choice, array( 'first', 'trial' ), true ) ? $choice : '' ) );
	}

	/**
	 * AJAX ctdflow_plan_status: reads the plan of the posted day and returns it rendered once it is done.
	 */
	public function handleStatus(): void {
		check_ajax_referer( 'ctdflow_routes', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( $this->forbidden(), 403 );
		}

		$date   = $this->requestedDate( isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '' );
		$result = $this->result( $date );

		if ( null === $result ) {
			wp_send_json_error(
				array(
					'code' => 'no_plan',
					'html' => $this->notice( 'warning', __( 'This day has not been planned yet.', 'connect-the-dots' ) ),
				)
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Sends a plan, after the free tier choice when the selection is above the free tier.
	 *
	 * @param string   $date       Delivery date as Y-m-d.
	 * @param int[]    $orderIds   Chosen orders.
	 * @param string[] $vehicleIds Chosen vans.
	 * @param string   $choice     first, trial or empty when no choice was made.
	 *
	 * @return array{success: bool, data: array}
	 */
	private function plan( string $date, array $orderIds, array $vehicleIds, string $choice ): array {
		$selection = array(
			'date'        => $date,
			'order_ids'   => $orderIds,
			'vehicle_ids' => $vehicleIds,
		);
		$account   = null;
		$left_out  = array();

		try {
			// The free tier decides how many orders go out. A cached tier that is out of date could send
			// more than it allows, and that starts the trial the owner may just have turned down.
			Account::forget( $date );

			$account = $this->account->fetch( $date );
			$tier    = Account::freeTier( $account );

			if ( null !== $tier ) {
				$vans        = $this->chosenVanIds( $vehicleIds );
				$choice_html = $this->choiceHtml( $tier, count( $orderIds ), count( $vans ), $account );

				if ( '' !== $choice_html && '' === $choice ) {
					return $this->failure( 'choice_needed', $choice_html, $selection );
				}

				if ( 'first' === $choice ) {
					if ( count( $orderIds ) > $tier['remaining_stops'] ) {
						$chosen   = array_map( 'intval', $orderIds );
						$orderIds = $this->planner->firstOrders( $date, $orderIds, $tier['remaining_stops'] );
						$left_out = array_values( array_diff( $chosen, $orderIds ) );
					}

					$vehicleIds = array_slice( $vans, 0, max( 1, $tier['max_vehicles'] ) );
				}
			}

			$countries = is_array( $account['supported_countries'] ?? null ) ? array_map( 'strval', $account['supported_countries'] ) : null;
			$result    = $this->planner->plan( $date, $orderIds, $vehicleIds, 'manual', $countries );
		} catch ( PlanningNotPossible $refused ) {
			return $this->failure( 'not_possible', $this->notice( 'error', $refused->getMessage() ), $selection );
		} catch ( ApiError $error ) {
			$keep = array(
				'date'        => $date,
				'added'       => $selection['order_ids'],
				'order_ids'   => $selection['order_ids'],
				'vehicle_ids' => $selection['vehicle_ids'],
			);

			return $this->failure( $error->errorCode, $this->errorHtml( $error, $account, $keep ), $selection );
		}

		$plan_id = (string) ( $result['plan_id'] ?? '' );

		$this->store->putLeftOut( $date, $plan_id, $left_out );

		return array(
			'success' => true,
			'data'    => array(
				'plan_id' => $plan_id,
				'status'  => (string) ( $result['status'] ?? 'queued' ),
				'html'    => $this->pendingHtml(),
			) + $this->accountAfterPlanning( $date ),
		);
	}

	/**
	 * The plan label and free plan line after planning, which may have started a trial. Empty when the
	 * account cannot be read, so the screen keeps what it shows.
	 *
	 * @param string $date Delivery date as Y-m-d.
	 *
	 * @return array{plan_label?: string, tier_html?: string}
	 */
	private function accountAfterPlanning( string $date ): array {
		try {
			$account = $this->account->fetch( $date );
		} catch ( ApiError $error ) {
			unset( $error );

			return array();
		}

		return array(
			'plan_label' => $this->planLabel( (string) ( $account['billing_status'] ?? '' ) ),
			'tier_html'  => $this->tierHtml( Account::freeTier( $account ) ),
		);
	}

	/**
	 * The line about the free plan, empty on a paid plan.
	 *
	 * @param array{max_vehicles: int, max_stops: int, remaining_stops: int}|null $tier The free tier.
	 */
	private function tierHtml( ?array $tier ): string {
		if ( null === $tier ) {
			return '';
		}

		return sprintf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: stops a plan of this day can have, 2: vans per plan. */
					_n( 'Free plan: plans of up to %1$d stops for this day, with %2$d van.', 'Free plan: plans of up to %1$d stops for this day, with %2$d vans.', $tier['max_vehicles'], 'connect-the-dots' ),
					$tier['remaining_stops'],
					$tier['max_vehicles']
				)
			)
		);
	}

	/**
	 * The plans of a day: whether they are done, and their HTML. Null when the day has no plan. A plan
	 * that is still being made shows the planning message, one that failed shows why; both above the
	 * routes of the current plan, which still apply until a newer plan succeeds.
	 *
	 * @param string $date Delivery date as Y-m-d.
	 *
	 * @return array{done: bool, html: string}|null
	 */
	private function result( string $date ): ?array {
		try {
			$pending = $this->follower()->pending( $date );
		} catch ( ApiError $error ) {
			return array(
				'done' => true,
				'html' => $this->errorHtml( $error, null ),
			);
		}

		$current = $this->currentResult( $date );

		if ( null === $pending ) {
			return $current;
		}

		$routes = null === $current ? '' : $current['html'];
		$status = (string) ( $pending['status'] ?? '' );

		if ( 'queued' === $status || 'running' === $status ) {
			return array(
				'done' => false,
				'html' => $this->pendingHtml() . $routes,
			);
		}

		$message = $this->failureMessage( (array) ( $pending['error'] ?? array() ) );

		if ( null !== $current ) {
			$message .= ' ' . __( 'The routes below still apply.', 'connect-the-dots' );
		}

		return array(
			'done' => true,
			'html' => $this->notice( 'error', $message ) . $routes,
		);
	}

	/**
	 * The current plan of a day: whether it is done, and its HTML. Null when the day has no current
	 * plan. A succeeded plan is applied to the orders here, once.
	 *
	 * @param string $date Delivery date as Y-m-d.
	 *
	 * @return array{done: bool, html: string}|null
	 */
	private function currentResult( string $date ): ?array {
		$plan_id = $this->store->get( $date );

		if ( null === $plan_id ) {
			return null;
		}

		try {
			$plan = $this->latestPlan( $date, $plan_id );
		} catch ( ApiError $error ) {
			if ( 'plan_not_found' === $error->errorCode ) {
				return array(
					'done' => true,
					'html' => $this->notice( 'warning', __( 'Plan expired. Connect the Dots keeps plans for a limited time. Plan this day again to see its routes.', 'connect-the-dots' ) ),
				);
			}

			return array(
				'done' => true,
				'html' => $this->errorHtml( $error, null ),
			);
		}

		if ( ! empty( $plan['superseded_by'] ) ) {
			return array(
				'done' => true,
				'html' => $this->notice( 'warning', __( 'Newer plans replaced this plan too many times in a row. Plan this day again to see its routes.', 'connect-the-dots' ) ),
			);
		}

		$status = (string) ( $plan['status'] ?? '' );

		if ( 'queued' === $status || 'running' === $status ) {
			return array(
				'done' => false,
				'html' => $this->pendingHtml(),
			);
		}

		if ( 'succeeded' !== $status ) {
			return array(
				'done' => true,
				'html' => $this->notice( 'error', $this->failureMessage( (array) ( $plan['error'] ?? array() ) ) ),
			);
		}

		$this->applier->apply( $plan );

		return array(
			'done' => true,
			'html' => $this->resultHtml( $plan ),
		);
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
	private function latestPlan( string $date, string $planId ): array {
		return $this->follower()->latest( $date, $planId );
	}

	/**
	 * Reads the plans of a day.
	 */
	private function follower(): PlanFollower {
		return new PlanFollower( $this->client, $this->store );
	}

	/**
	 * The routes of a succeeded plan as HTML.
	 *
	 * @param array $plan The plan.
	 */
	private function resultHtml( array $plan ): string {
		$source = DeliveryDateSources::forSetting( $this->settings->dateSource() );
		$routes = array();

		foreach ( (array) ( $plan['routes'] ?? array() ) as $index => $route ) {
			$stops = array();

			foreach ( (array) ( $route['stops'] ?? array() ) as $stop ) {
				$order  = wc_get_order( (int) ( $stop['reference'] ?? 0 ) );
				$window = $order instanceof \WC_Order ? $source->timeWindow( $order ) : null;

				$stops[] = array(
					'position'  => (int) ( $stop['position'] ?? 0 ),
					'expected'  => ResultApplier::localTime( ResultApplier::expectedAt( (array) $stop ) ),
					'delivered' => is_string( $stop['delivered_at'] ?? null ) ? ResultApplier::localTime( $stop['delivered_at'] ) : '',
					'order'     => $this->orderLink( $order, (string) ( $stop['reference'] ?? '' ) ),
					'address'   => $order instanceof \WC_Order ? $this->displayAddress( $order ) : '',
					'window'    => null === $window ? '' : $this->windowText( $window ),
				);
			}

			$routes[] = array(
				'id'          => 'ctdflow-driver-link-' . $index,
				'vehicle'     => (string) ( $route['vehicle'] ?? '' ),
				'driver_link' => is_string( $route['driver_link'] ?? null ) ? $route['driver_link'] : '',
				'duration'    => $this->durationText( (int) ( $route['duration_seconds'] ?? 0 ) ),
				'distance'    => $this->distanceText( (int) ( $route['distance_meters'] ?? 0 ) ),
				'stops'       => $stops,
			);
		}

		$unplanned = array();

		foreach ( (array) ( $plan['unplanned'] ?? array() ) as $item ) {
			$order       = wc_get_order( (int) ( $item['reference'] ?? 0 ) );
			$unplanned[] = array(
				'order'  => $this->orderLink( $order, (string) ( $item['reference'] ?? '' ) ),
				'reason' => $this->unplannedReason( (string) ( $item['reason'] ?? '' ) ),
			);
		}

		foreach ( $this->store->leftOut( (string) ( $plan['delivery_date'] ?? '' ), (string) ( $plan['plan_id'] ?? '' ) ) as $order_id ) {
			$unplanned[] = array(
				'order'  => $this->orderLink( wc_get_order( $order_id ), (string) $order_id ),
				'reason' => __( 'Not sent, to stay within the free plan', 'connect-the-dots' ),
			);
		}

		$ctdflow_routes    = $routes;
		$ctdflow_unplanned = $unplanned;

		ob_start();
		include dirname( __DIR__, 2 ) . '/views/plan-result.php';

		return (string) ob_get_clean();
	}

	/**
	 * The free tier choice, or an empty string when the selection fits the free tier.
	 *
	 * @param array{max_vehicles: int, max_stops: int, remaining_stops: int} $tier    The free tier.
	 * @param int                                                           $orders  Number of chosen orders.
	 * @param int                                                           $vans    Number of chosen active vans.
	 * @param array                                                         $account Account JSON.
	 */
	private function choiceHtml( array $tier, int $orders, int $vans, array $account ): string {
		$too_many_stops = $orders > $tier['remaining_stops'];
		$too_many_vans  = $vans > $tier['max_vehicles'];

		if ( ! $too_many_stops && ! $too_many_vans ) {
			return '';
		}

		$limits = sprintf(
			/* translators: 1: stops a plan of this day can have, 2: number of vans in a plan. */
			_n(
				'Your free plan makes plans of up to %1$d stops for this day, with %2$d van.',
				'Your free plan makes plans of up to %1$d stops for this day, with %2$d vans.',
				$tier['max_vehicles'],
				'connect-the-dots'
			),
			$tier['remaining_stops'],
			$tier['max_vehicles']
		);
		$chosen = sprintf(
			/* translators: 1: number of orders, like "26 orders", 2: number of vans, like "2 vans". */
			__( 'You chose %1$s and %2$s.', 'connect-the-dots' ),
			/* translators: %d: number of orders. */
			sprintf( _n( '%d order', '%d orders', $orders, 'connect-the-dots' ), $orders ),
			/* translators: %d: number of vans. */
			sprintf( _n( '%d van', '%d vans', $vans, 'connect-the-dots' ), $vans )
		);

		$first = '';

		if ( $too_many_stops && $too_many_vans ) {
			/* translators: 1: number of stops, 2: number of vans. */
			$first = sprintf( _n( 'Plan the first %1$d with %2$d van', 'Plan the first %1$d with %2$d vans', $tier['max_vehicles'], 'connect-the-dots' ), $tier['remaining_stops'], $tier['max_vehicles'] );
		} elseif ( $too_many_stops ) {
			/* translators: %d: number of stops. */
			$first = sprintf( __( 'Plan the first %d', 'connect-the-dots' ), $tier['remaining_stops'] );
		} else {
			/* translators: %d: number of vans. */
			$first = sprintf( _n( 'Plan with %d van', 'Plan with %d vans', $tier['max_vehicles'], 'connect-the-dots' ), $tier['max_vehicles'] );
		}

		$buttons = '';

		if ( ! $too_many_stops || $tier['remaining_stops'] > 0 ) {
			$buttons .= sprintf( '<button type="submit" name="choice" value="first" class="button">%s</button> ', esc_html( $first ) );
		}

		if ( ! empty( $account['trial_available'] ) ) {
			$buttons .= sprintf( '<button type="submit" name="choice" value="trial" class="button button-primary">%s</button>', esc_html__( 'Try a paid plan free for 30 days', 'connect-the-dots' ) );
		} elseif ( is_string( $account['pricing_url'] ?? null ) ) {
			$buttons .= sprintf( '<a href="%s" class="button button-primary" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $account['pricing_url'] ), esc_html__( 'Choose a paid plan', 'connect-the-dots' ) );
		}

		return sprintf(
			'<div class="notice notice-warning inline ctdflow-choice"><p>%s %s</p><p>%s</p></div>',
			esc_html( $limits ),
			esc_html( $chosen ),
			$buttons
		);
	}

	/**
	 * The HTML of an API error. A lost connection offers to connect again, a refused paid feature
	 * links to the pricing.
	 *
	 * @param ApiError   $error     The error.
	 * @param array|null $account   Account JSON, if it was read.
	 * @param array|null $selection Date, added orders, chosen orders and vans, kept over a new connection.
	 */
	private function errorHtml( ApiError $error, ?array $account, ?array $selection = null ): string {
		if ( 'connection_lost' === $error->errorCode ) {
			return $this->notice( 'error', $error->userMessage ) . $this->connectForm( __( 'Connect again', 'connect-the-dots' ), $selection );
		}

		$pricing = is_string( $account['pricing_url'] ?? null ) ? $account['pricing_url'] : '';

		if ( in_array( $error->errorCode, array( 'upgrade_required', 'scheduled_needs_paid_plan' ), true ) && '' !== $pricing ) {
			return sprintf(
				'<div class="notice notice-error inline"><p>%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p></div>',
				esc_html( $error->userMessage ),
				esc_url( $pricing ),
				esc_html__( 'See the plans', 'connect-the-dots' )
			);
		}

		return $this->notice( 'error', $error->userMessage );
	}

	/**
	 * The form that starts the connection with Connect the Dots, through the ctdflow_connect action of Menu.
	 *
	 * @param string     $label     Button label.
	 * @param array|null $selection Selection to restore after connecting, as hidden fields.
	 */
	private function connectForm( string $label, ?array $selection ): string {
		return sprintf(
			'<form method="post" action="%s"><input type="hidden" name="action" value="ctdflow_connect" />%s%s<p><button type="submit" class="button button-primary">%s</button></p></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'ctdflow_connect', '_wpnonce', true, false ),
			$this->selectionFields( $selection ),
			esc_html( $label )
		);
	}

	/**
	 * Hidden fields that carry a selection through the Connect action.
	 *
	 * @param array|null $selection Date, added, order_ids and vehicle_ids.
	 */
	private function selectionFields( ?array $selection ): string {
		if ( null === $selection ) {
			return '';
		}

		$html = sprintf( '<input type="hidden" name="ctdflow_selection[date]" value="%s" />', esc_attr( (string) $selection['date'] ) );

		foreach ( array( 'added', 'order_ids', 'vehicle_ids' ) as $key ) {
			foreach ( (array) $selection[ $key ] as $value ) {
				$html .= sprintf( '<input type="hidden" name="ctdflow_selection[%s][]" value="%s" />', esc_attr( $key ), esc_attr( (string) $value ) );
			}
		}

		return $html;
	}

	/**
	 * Keeps a posted selection for the current user for 10 minutes, while the owner connects again.
	 *
	 * @param mixed $raw The posted ctdflow_selection field, not sanitized yet.
	 */
	public function rememberSelection( $raw ): void {
		if ( ! is_array( $raw ) ) {
			return;
		}

		$selection = array(
			'date'        => $this->requestedDate( sanitize_text_field( (string) ( $raw['date'] ?? '' ) ) ),
			'added'       => array_values( array_filter( array_map( 'absint', (array) ( $raw['added'] ?? array() ) ) ) ),
			'order_ids'   => array_values( array_filter( array_map( 'absint', (array) ( $raw['order_ids'] ?? array() ) ) ) ),
			'vehicle_ids' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $raw['vehicle_ids'] ?? array() ) ) ) ),
		);

		set_transient( $this->selectionKey(), $selection, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * The date of the kept selection, null when there is none.
	 */
	public function rememberedDate(): ?string {
		$selection = get_transient( $this->selectionKey() );

		return is_array( $selection ) ? (string) $selection['date'] : null;
	}

	/**
	 * The kept selection of a day, once: it is forgotten when read.
	 *
	 * @param string $date Delivery date being shown.
	 *
	 * @return array{date: string, added: int[], order_ids: int[], vehicle_ids: string[]}|null
	 */
	private function takeSelection( string $date ): ?array {
		$selection = get_transient( $this->selectionKey() );

		if ( ! is_array( $selection ) || ( $selection['date'] ?? '' ) !== $date ) {
			return null;
		}

		delete_transient( $this->selectionKey() );

		return array(
			'date'        => $date,
			'added'       => array_map( 'intval', (array) ( $selection['added'] ?? array() ) ),
			'order_ids'   => array_map( 'intval', (array) ( $selection['order_ids'] ?? array() ) ),
			'vehicle_ids' => array_map( 'strval', (array) ( $selection['vehicle_ids'] ?? array() ) ),
		);
	}

	/**
	 * Transient key of the kept selection of the current user.
	 */
	private function selectionKey(): string {
		return 'ctdflow_selection_' . get_current_user_id();
	}

	/**
	 * Renders the explanation and the button to connect.
	 *
	 * @param string|null $message   Why the shop has to connect again, if it was connected.
	 * @param array|null  $selection Selection to restore after connecting again.
	 */
	private function renderConnect( ?string $message, ?array $selection = null ): void {
		$ctdflow_message   = $message;
		$ctdflow_selection = $this->selectionFields( $selection );

		include dirname( __DIR__, 2 ) . '/views/connect.php';
	}

	/**
	 * A failed AJAX answer.
	 *
	 * @param string $code      Error code.
	 * @param string $html      Escaped HTML to show.
	 * @param array  $selection The posted selection, so the screen can keep it.
	 *
	 * @return array{success: bool, data: array}
	 */
	private function failure( string $code, string $html, array $selection ): array {
		return array(
			'success' => false,
			'data'    => array(
				'code'      => $code,
				'html'      => $html,
				'selection' => $selection,
			),
		);
	}

	/**
	 * The answer for a user without the capability.
	 */
	private function forbidden(): array {
		return array(
			'code' => 'forbidden',
			'html' => $this->notice( 'error', __( 'Sorry, you are not allowed to do this.', 'connect-the-dots' ) ),
		);
	}

	/**
	 * The tags and attributes the HTML built by this page uses, for wp_kses() where the views print it.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowedHtml(): array {
		$common = array(
			'class' => true,
			'id'    => true,
		);

		return array(
			'a'      => $common + array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'button' => $common + array(
				'type'          => true,
				'name'          => true,
				'value'         => true,
				'data-ctdflow-copy' => true,
			),
			'div'    => $common,
			'form'   => $common + array(
				'method' => true,
				'action' => true,
			),
			'h3'     => $common,
			'input'  => $common + array(
				'type'     => true,
				'name'     => true,
				'value'    => true,
				'readonly' => true,
			),
			'label'  => $common + array( 'for' => true ),
			'li'     => $common,
			'p'      => $common,
			'span'   => $common,
			'table'  => $common,
			'tbody'  => $common,
			'td'     => $common,
			'th'     => $common + array( 'scope' => true ),
			'thead'  => $common,
			'tr'     => $common,
			'ul'     => $common,
		);
	}

	/**
	 * A notice with an escaped message.
	 *
	 * @param string $type    error, warning or success.
	 * @param string $message Message, not escaped yet.
	 */
	private function notice( string $type, string $message ): string {
		return sprintf( '<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/**
	 * What the result shows while the plan is being made.
	 */
	private function pendingHtml(): string {
		return '<p class="ctdflow-pending"><span class="spinner is-active"></span>' . esc_html__( 'Planning the routes…', 'connect-the-dots' ) . '</p>';
	}

	/**
	 * Message for a plan that failed at Connect the Dots.
	 *
	 * @param array $error The error of the plan.
	 */
	private function failureMessage( array $error ): string {
		return PlanFollower::failureMessage( (string) ( $error['code'] ?? '' ), $error );
	}

	/**
	 * Label of a billing status.
	 *
	 * @param string $status free, trial, active or blocked.
	 */
	private function planLabel( string $status ): string {
		switch ( $status ) {
			case 'free':
				return __( 'Free', 'connect-the-dots' );
			case 'trial':
				return __( 'Trial', 'connect-the-dots' );
			case 'active':
				return __( 'Active', 'connect-the-dots' );
			case 'blocked':
				return __( 'Blocked', 'connect-the-dots' );
			default:
				return '';
		}
	}

	/**
	 * Why CTD did not plan a stop.
	 *
	 * @param string $reason Reason from the plan.
	 */
	private function unplannedReason( string $reason ): string {
		switch ( $reason ) {
			case 'address_not_found':
				return __( 'Address not found', 'connect-the-dots' );
			case 'address_ambiguous':
				return __( 'Address is ambiguous', 'connect-the-dots' );
			case 'location_unreachable':
				return __( 'No road leads to this address. Check the address.', 'connect-the-dots' );
			case '':
			case 'unknown':
				return __( 'Reason unknown', 'connect-the-dots' );
			default:
				/* translators: %s: reason given by the route planner. */
				return sprintf( __( 'Did not fit in the routes (%s)', 'connect-the-dots' ), $reason );
		}
	}

	/**
	 * A row of the order list.
	 *
	 * @param SelectedOrder $selected The order.
	 *
	 * @return array{id: int, order: array{number: string, url: string}, address: string, window: string, quantity: int}
	 */
	private function orderRow( SelectedOrder $selected ): array {
		return array(
			'id'       => $selected->order->get_id(),
			'order'    => $this->orderLink( $selected->order, '' ),
			'address'  => $this->addressText( $selected->address ),
			'window'   => null === $selected->timeWindow ? '' : $this->windowText( $selected->timeWindow ),
			'quantity' => $selected->quantity,
		);
	}

	/**
	 * A row of the list of orders that cannot be planned.
	 *
	 * @param SelectedOrder $selected The order.
	 *
	 * @return array{order: array{number: string, url: string}, reason: string}
	 */
	private function unplannableRow( SelectedOrder $selected ): array {
		return array(
			'order'  => $this->orderLink( $selected->order, '' ),
			'reason' => 'no_address' === $selected->problem ? __( 'No delivery address', 'connect-the-dots' ) : __( 'Country not supported', 'connect-the-dots' ),
		);
	}

	/**
	 * Number and edit URL of an order, the reference when the order no longer exists.
	 *
	 * @param \WC_Order|false|null $order     The order.
	 * @param string               $reference Reference from the plan.
	 *
	 * @return array{number: string, url: string}
	 */
	private function orderLink( $order, string $reference ): array {
		if ( ! $order instanceof \WC_Order ) {
			return array(
				'number' => $reference,
				'url'    => '',
			);
		}

		return array(
			'number' => (string) $order->get_order_number(),
			'url'    => $order->get_edit_order_url(),
		);
	}

	/**
	 * The address of an order for the screen: the shipping street, else the billing street, with the city.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function displayAddress( \WC_Order $order ): string {
		$shipping = '' !== trim( $order->get_shipping_address_1() );

		return $this->addressText(
			array(
				'street'   => $shipping ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
				'postcode' => '',
				'city'     => $shipping ? $order->get_shipping_city() : $order->get_billing_city(),
			)
		);
	}

	/**
	 * An address on one line.
	 *
	 * @param array $address Address with street, postcode and city.
	 */
	private function addressText( array $address ): string {
		$locality = trim( trim( (string) ( $address['postcode'] ?? '' ) ) . ' ' . trim( (string) ( $address['city'] ?? '' ) ) );

		return implode( ', ', array_filter( array( trim( (string) ( $address['street'] ?? '' ) ), $locality ) ) );
	}

	/**
	 * A time window as text.
	 *
	 * @param array{from: string, to: string} $window The window.
	 */
	private function windowText( array $window ): string {
		/* translators: 1: start time, 2: end time. */
		return sprintf( __( '%1$s to %2$s', 'connect-the-dots' ), $window['from'], $window['to'] );
	}

	/**
	 * A driving time as hours and minutes.
	 *
	 * @param int $seconds Duration in seconds.
	 */
	private function durationText( int $seconds ): string {
		$minutes = (int) round( $seconds / 60 );
		$hours   = intdiv( $minutes, 60 );

		if ( 0 === $hours ) {
			/* translators: %d: minutes. */
			return sprintf( __( '%d min', 'connect-the-dots' ), $minutes );
		}

		/* translators: 1: hours, 2: minutes. */
		return sprintf( __( '%1$d h %2$d min', 'connect-the-dots' ), $hours, $minutes % 60 );
	}

	/**
	 * A distance in kilometres, with one decimal below 10 km.
	 *
	 * @param int $meters Distance in meters.
	 */
	private function distanceText( int $meters ): string {
		$kilometres = $meters / 1000;

		/* translators: %s: distance in kilometres. */
		return sprintf( __( '%s km', 'connect-the-dots' ), number_format_i18n( $kilometres, $kilometres < 10 ? 1 : 0 ) );
	}

	/**
	 * The active vans, in the order of the settings.
	 *
	 * @return Vehicle[]
	 */
	private function activeVans(): array {
		return array_values( array_filter( $this->settings->vehicles(), static fn( Vehicle $vehicle ): bool => $vehicle->active ) );
	}

	/**
	 * The chosen ids of active vans, in the order of the settings.
	 *
	 * @param string[] $vehicleIds Chosen van ids.
	 *
	 * @return string[]
	 */
	private function chosenVanIds( array $vehicleIds ): array {
		$ids = array();

		foreach ( $this->activeVans() as $vehicle ) {
			if ( in_array( $vehicle->id, $vehicleIds, true ) ) {
				$ids[] = $vehicle->id;
			}
		}

		return $ids;
	}

	/**
	 * The requested delivery date, tomorrow in the site timezone when none or an invalid one is given.
	 *
	 * @param string $value Requested date.
	 */
	private function requestedDate( string $value ): string {
		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return $value;
		}

		return ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
	}

	/**
	 * Order ids from a list separated by commas or spaces.
	 *
	 * @param string $value The list.
	 *
	 * @return int[]
	 */
	private function orderIds( string $value ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $value ) ?: array() ) ) ) );
	}
}
