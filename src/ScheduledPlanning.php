<?php
/**
 * Plans the next delivery day automatically on paid plans.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * Runs from Action Scheduler at the chosen time: plans the day exactly like the Plan button, applies the
 * result a few minutes later and mails the shop admin when something goes wrong. A day that already
 * has a plan is left alone.
 */
class ScheduledPlanning {

	/**
	 * Name of the event that plans the day.
	 *
	 * @var string
	 */
	public const EVENT = 'ctdflow_scheduled_planning';

	/**
	 * Name of the event that applies the plan to the orders.
	 *
	 * @var string
	 */
	public const APPLY_EVENT = 'ctdflow_apply_scheduled';

	/**
	 * Name of the option that holds the time and outcome of the last run.
	 *
	 * @var string
	 */
	public const LAST_RUN_OPTION = 'ctdflow_last_scheduled_run';

	/**
	 * Name of the option that keeps two runs from planning at the same time. It holds the time it was taken.
	 *
	 * @var string
	 */
	private string $lockKey = 'ctdflow_scheduled_lock';

	/**
	 * Seconds the lock holds. It is never released early, so overlapping runs plan once.
	 *
	 * @var int
	 */
	private int $lockSeconds = 300;

	/**
	 * Seconds between planning and applying, and between two checks of a plan.
	 *
	 * @var int
	 */
	private int $applyDelay = 120;

	/**
	 * Number of times a plan is checked before the admin hears it is not done.
	 *
	 * @var int
	 */
	private int $maxTries = 5;

	/**
	 * Creates the scheduled planning.
	 *
	 * @param Settings       $settings   The plugin settings.
	 * @param Connection     $connection Connection with Connect the Dots.
	 * @param Account        $account    The Connect the Dots account.
	 * @param Planner        $planner    Sends plans.
	 * @param OrderSelection $selection  Selects the orders of a day.
	 * @param PlanStore      $store      Remembers the plan of every day.
	 * @param PlanFollower   $follower   Reads the newest plan of a day.
	 * @param ResultApplier  $applier    Writes plans onto the orders.
	 */
	public function __construct(
		private Settings $settings,
		private Connection $connection,
		private Account $account,
		private Planner $planner,
		private OrderSelection $selection,
		private PlanStore $store,
		private PlanFollower $follower,
		private ResultApplier $applier
	) {
	}

	/**
	 * Builds the scheduled planning with the real connection and settings.
	 */
	public static function create(): ScheduledPlanning {
		$settings   = new Settings();
		$connection = new Connection();
		$client     = new ApiClient( $connection );
		$store      = new PlanStore();
		$selection  = new OrderSelection( $settings );

		return new ScheduledPlanning(
			$settings,
			$connection,
			new Account( $client ),
			new Planner( $settings, $client, $store, $selection, new PlanRequestBuilder( $settings ) ),
			$selection,
			$store,
			new PlanFollower( $client, $store ),
			new ResultApplier( $settings )
		);
	}

	/**
	 * Schedules the next run at the chosen time in the site timezone, replacing an earlier one. Does
	 * nothing but remove the event when scheduling is turned off.
	 */
	public static function schedule(): void {
		if ( ! Plugin::canSchedule() ) {
			return;
		}

		as_unschedule_all_actions( self::EVENT );

		$settings = new Settings();

		if ( ! $settings->scheduleEnabled() ) {
			return;
		}

		$time = explode( ':', $settings->scheduleTime() );
		$zone = wp_timezone();
		$next = ( new \DateTimeImmutable( 'today', $zone ) )->setTime( (int) $time[0], (int) ( $time[1] ?? 0 ) );

		if ( $next->getTimestamp() <= time() ) {
			$next = ( new \DateTimeImmutable( 'tomorrow', $zone ) )->setTime( (int) $time[0], (int) ( $time[1] ?? 0 ) );
		}

		// The day to plan is fixed now, so a run that starts late still plans the intended day.
		$target = $next->modify( '+' . $settings->scheduleDayOffset() . ' days' )->format( 'Y-m-d' );

		as_schedule_single_action( $next->getTimestamp(), self::EVENT, array( $target ), Plugin::SCHEDULE_GROUP );
	}

	/**
	 * Reschedules after the settings were saved, only when a scheduling setting changed. A save of other
	 * settings must not move a run that is due.
	 *
	 * @param mixed $old Settings before the save.
	 * @param mixed $new Settings after the save.
	 */
	public static function settingsChanged( $old, $new ): void {
		if ( self::scheduleSettings( $old ) !== self::scheduleSettings( $new ) ) {
			self::schedule();
		}
	}

	/**
	 * The settings that decide when the event runs.
	 *
	 * @param mixed $settings Stored settings.
	 *
	 * @return array{bool, string, int}
	 */
	private static function scheduleSettings( $settings ): array {
		$settings = is_array( $settings ) ? $settings : array();

		return array(
			(bool) ( $settings['schedule_enabled'] ?? false ),
			(string) ( $settings['schedule_time'] ?? '18:00' ),
			(int) ( $settings['schedule_day_offset'] ?? 1 ),
		);
	}

	/**
	 * The time the planning event is due, false when it is not scheduled or runs right now.
	 *
	 * @return int|false
	 */
	public static function nextRun() {
		if ( ! Plugin::canSchedule() ) {
			return false;
		}

		$next = as_next_scheduled_action( self::EVENT, null, Plugin::SCHEDULE_GROUP );

		return is_int( $next ) ? $next : false;
	}

	/**
	 * Makes sure the event exists while scheduling is on and is gone while it is off.
	 */
	public static function ensureScheduled(): void {
		if ( ! Plugin::canSchedule() ) {
			return;
		}

		$enabled = ( new Settings() )->scheduleEnabled();
		$exists  = as_has_scheduled_action( self::EVENT, null, Plugin::SCHEDULE_GROUP );

		if ( $enabled !== $exists ) {
			self::schedule();
		}
	}

	/**
	 * Removes both events.
	 */
	public static function unschedule(): void {
		if ( ! Plugin::canSchedule() ) {
			return;
		}

		as_unschedule_all_actions( self::EVENT );
		as_unschedule_all_actions( self::APPLY_EVENT );
	}

	/**
	 * The time and outcome of the last run, null when it never ran.
	 *
	 * @return array{time: int, outcome: string, code: string, date: string}|null
	 */
	public static function lastRun(): ?array {
		$last = get_option( self::LAST_RUN_OPTION );

		if ( ! is_array( $last ) || ! isset( $last['time'] ) ) {
			return null;
		}

		return array(
			'time'    => (int) $last['time'],
			'outcome' => (string) ( $last['outcome'] ?? '' ),
			'code'    => (string) ( $last['code'] ?? '' ),
			'date'    => (string) ( $last['date'] ?? '' ),
		);
	}

	/**
	 * The handler of the event: plans the next occurrence first, so a failing run never stops the schedule, and
	 * then runs.
	 *
	 * @param string $date Day to plan as Y-m-d, fixed when the event was scheduled.
	 */
	public function handleEvent( string $date = '' ): void {
		if ( ! $this->settings->scheduleEnabled() ) {
			return;
		}

		try {
			self::schedule();
		} catch ( \Throwable $error ) {
			unset( $error );
		}

		$this->run( $date );
	}

	/**
	 * Plans the day, with all active vans, exactly like the Plan button, and schedules the application of
	 * the result. Never throws: a failure is mailed to the shop admin and stored. The lock is kept for
	 * its full time, so a second run right after it plans nothing.
	 *
	 * @param string $date Day to plan as Y-m-d, today plus the day offset when empty.
	 */
	public function run( string $date = '' ): void {
		if ( ! $this->settings->scheduleEnabled() || ! $this->lock() ) {
			return;
		}

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+' . $this->settings->scheduleDayOffset() . ' days' )->format( 'Y-m-d' );
		}

		try {
			$this->plan( $date );
		} catch ( \Throwable $error ) {
			$this->fail( $date, 'failed', 'exception', $error->getMessage() );
		}
	}

	/**
	 * Takes the lock with an INSERT IGNORE of its option row, so of two overlapping requests only one
	 * gets it. add_option is not enough: with an out of date cache it overwrites the row of another
	 * request. A lock older than its time is taken over.
	 */
	private function lock(): bool {
		return ( new OptionLock( $this->lockKey, $this->lockSeconds ) )->acquire();
	}

	/**
	 * Applies the plan of a day once it is done, or checks again later. Never throws.
	 *
	 * @param string $date    Delivery date as Y-m-d.
	 * @param int    $attempt Number of this check, starting at 1.
	 */
	public function apply( string $date, int $attempt = 1 ): void {
		try {
			$this->follow( $date, $attempt );
		} catch ( \Throwable $error ) {
			$this->fail( $date, 'failed', 'exception', $error->getMessage() );
		}
	}

	/**
	 * Selects the orders of the day and sends the plan.
	 *
	 * @param string $date Delivery date as Y-m-d.
	 */
	private function plan( string $date ): void {
		if ( ! $this->connection->isConnected() ) {
			$this->fail( $date, 'failed', 'not_connected', __( 'The shop is not connected to Connect the Dots. Connect it on the Routes tab.', 'connect-the-dots' ) );
			return;
		}

		// A plan made by hand, or one still on its way, is never replaced by the schedule.
		if ( null !== $this->store->get( $date ) || null !== $this->store->pending( $date ) ) {
			$this->record( 'refused', 'already_planned', $date );
			return;
		}

		try {
			$account   = $this->account->fetch( $date );
			$countries = is_array( $account['supported_countries'] ?? null ) ? array_map( 'strval', $account['supported_countries'] ) : null;
			$orders    = $this->selection->forDay( $date, array(), array(), $countries )['plannable'];

			if ( array() === $orders ) {
				$this->record( 'refused', 'no_orders', $date );
				return;
			}

			$vans = array_map(
				static fn( Vehicle $vehicle ): string => $vehicle->id,
				array_filter( $this->settings->vehicles(), static fn( Vehicle $vehicle ): bool => $vehicle->active )
			);

			$this->planner->plan(
				$date,
				array_map( static fn( SelectedOrder $selected ): int => $selected->order->get_id(), $orders ),
				array_values( $vans ),
				'scheduled',
				$countries
			);
		} catch ( PlanningNotPossible $refused ) {
			$this->fail( $date, 'refused', 'not_possible', $refused->getMessage() );
			return;
		} catch ( ApiError $error ) {
			$refused = 'scheduled_needs_paid_plan' === $error->errorCode;
			$this->fail( $date, $refused ? 'refused' : 'failed', $error->errorCode, $error->userMessage );
			return;
		}

		$this->record( 'succeeded', '', $date );
		$this->scheduleApply( $date, 1 );
	}

	/**
	 * Reads the plan of the day and applies it, or schedules another check, or tells the admin.
	 *
	 * @param string $date    Delivery date as Y-m-d.
	 * @param int    $attempt Number of this check, starting at 1.
	 */
	private function follow( string $date, int $attempt ): void {
		try {
			$plan = $this->follower->pending( $date );

			if ( null === $plan ) {
				$plan_id = $this->store->get( $date );

				if ( null === $plan_id ) {
					return;
				}

				$plan = $this->follower->latest( $date, $plan_id );
			}
		} catch ( ApiError $error ) {
			if ( 0 === $error->status || $error->status >= 500 || 429 === $error->status ) {
				$this->tryAgain( $date, $attempt );
				return;
			}

			$this->fail( $date, 'failed', $error->errorCode, $error->userMessage );
			return;
		}

		if ( ! empty( $plan['superseded_by'] ) ) {
			$this->fail( $date, 'failed', 'superseded', __( 'Newer plans replaced this plan too many times in a row.', 'connect-the-dots' ) );
			return;
		}

		$status = (string) ( $plan['status'] ?? '' );

		if ( 'queued' === $status || 'running' === $status ) {
			$this->tryAgain( $date, $attempt );
			return;
		}

		if ( 'succeeded' !== $status ) {
			$code = (string) ( $plan['error']['code'] ?? '' );
			$this->fail( $date, 'failed', '' !== $code ? $code : 'plan_failed', PlanFollower::failureMessage( $code, (array) ( $plan['error'] ?? array() ) ) );
			return;
		}

		$this->applier->apply( $plan );
	}

	/**
	 * Checks the plan again in a few minutes, or tells the admin after the last try.
	 *
	 * @param string $date    Delivery date as Y-m-d.
	 * @param int    $attempt Number of the check that just ran.
	 */
	private function tryAgain( string $date, int $attempt ): void {
		if ( $attempt < $this->maxTries ) {
			$this->scheduleApply( $date, $attempt + 1 );
			return;
		}

		$this->fail( $date, 'failed', 'not_done', __( 'The plan is not done yet. Open the Routes tab later to see the routes.', 'connect-the-dots' ) );
	}

	/**
	 * Schedules a check of the plan of a day.
	 *
	 * @param string $date    Delivery date as Y-m-d.
	 * @param int    $attempt Number of the check.
	 */
	private function scheduleApply( string $date, int $attempt ): void {
		if ( Plugin::canSchedule() ) {
			as_schedule_single_action( time() + $this->applyDelay, self::APPLY_EVENT, array( $date, $attempt ), Plugin::SCHEDULE_GROUP );
		}
	}

	/**
	 * Stores the outcome and mails the shop admin the reason, unless the last run failed for the same reason.
	 *
	 * @param string $date    Delivery date as Y-m-d.
	 * @param string $outcome refused or failed.
	 * @param string $code    Stable code of the failure.
	 * @param string $reason  Reason for the shop admin.
	 */
	private function fail( string $date, string $outcome, string $code, string $reason ): void {
		$previous = self::lastRun();

		$this->record( $outcome, $code, $date );

		// A failure that repeats every day is mailed once.
		if ( null !== $previous && $previous['code'] === $code ) {
			return;
		}

		$link = admin_url( 'admin.php?page=ctdflow-delivery-routes&date=' . rawurlencode( $date ) );

		wp_mail(
			(string) get_option( 'admin_email' ),
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Connect the Dots could not plan your routes automatically', 'connect-the-dots' ),
				wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES )
			),
			sprintf(
				/* translators: 1: delivery date, 2: reason, 3: link to the Routes screen. */
				__( "The automatic planning of %1\$s did not work.\n\nReason: %2\$s\n\nOpen the Routes screen to plan this day yourself:\n%3\$s", 'connect-the-dots' ),
				$date,
				$reason,
				$link
			)
		);
	}

	/**
	 * Stores when the last run happened and how it ended.
	 *
	 * @param string $outcome succeeded, refused or failed.
	 * @param string $code    Stable code of the failure, empty on success.
	 * @param string $date    Delivery date as Y-m-d.
	 */
	private function record( string $outcome, string $code, string $date ): void {
		update_option(
			self::LAST_RUN_OPTION,
			array(
				'time'    => time(),
				'outcome' => $outcome,
				'code'    => $code,
				'date'    => $date,
			),
			false
		);
	}
}
