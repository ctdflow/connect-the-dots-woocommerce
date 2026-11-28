<?php
/**
 * The Connect the Dots account of the connected shop.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the account and the free tier of a delivery day, cached for a minute.
 */
class Account {

	/**
	 * Client for the Connect the Dots API.
	 *
	 * @var ApiClient
	 */
	private ApiClient $client;

	/**
	 * Creates the account reader.
	 *
	 * @param ApiClient|null $client Client to use, the shop's own by default.
	 */
	public function __construct( ?ApiClient $client = null ) {
		$this->client = $client ?? new ApiClient();
	}

	/**
	 * The account JSON of GET /v1/woocommerce/account for a delivery day.
	 *
	 * @param string $date Delivery date as Y-m-d.
	 *
	 * @throws ApiError When the account cannot be read.
	 */
	public function fetch( string $date ): array {
		$cached = get_transient( self::key( $date ) );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$account = $this->client->get(
			'/v1/woocommerce/account',
			array(
				'delivery_date' => $date,
				'site_url'      => home_url(),
			)
		);

		set_transient( self::key( $date ), $account, MINUTE_IN_SECONDS );

		return $account;
	}

	/**
	 * Forgets the cached account of a day, for instance after planning used stops of this month.
	 *
	 * @param string $date Delivery date as Y-m-d.
	 */
	public static function forget( string $date ): void {
		delete_transient( self::key( $date ) );
	}

	/**
	 * Forgets the cached accounts of every day, for instance after the shop connected to another account.
	 * The generation in the transient keys moves on, so this also works with a persistent object cache.
	 */
	public static function forgetAll(): void {
		update_option( 'ctdflow_account_generation', (int) get_option( 'ctdflow_account_generation', 0 ) + 1, false );
	}

	/**
	 * The free tier limits of an account, null for a paid account.
	 *
	 * @param array $account Account JSON.
	 *
	 * @return array{max_vehicles: int, max_stops: int, remaining_stops: int}|null
	 */
	public static function freeTier( array $account ): ?array {
		$tier = $account['free_tier'] ?? null;

		if ( ! empty( $account['paid'] ) || ! is_array( $tier ) ) {
			return null;
		}

		return array(
			'max_vehicles'    => (int) ( $tier['max_vehicles'] ?? 0 ),
			'max_stops'       => (int) ( $tier['max_stops'] ?? 0 ),
			'remaining_stops' => (int) ( $tier['remaining_stops'] ?? 0 ),
		);
	}

	/**
	 * Transient key of the account of a day.
	 *
	 * @param string $date Delivery date as Y-m-d.
	 */
	private static function key( string $date ): string {
		return 'ctdflow_account_' . (int) get_option( 'ctdflow_account_generation', 0 ) . '_' . $date;
	}
}
