<?php
/**
 * A lock in the options table that only one request can take.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * Takes a lock by inserting an option row. option_name is unique, so of two requests that insert the
 * same row at the same time exactly one succeeds, also when the object cache of either request is
 * out of date. The row holds the time the lock was taken, and a lock older than its time is taken over.
 */
class OptionLock {

	/**
	 * Creates the lock.
	 *
	 * @param string $name    Name of the option that holds the lock.
	 * @param int    $seconds Seconds after which a lock that was not released is taken over.
	 */
	public function __construct( private string $name, private int $seconds ) {
	}

	/**
	 * Takes the lock. False when another request holds it.
	 */
	public function acquire(): bool {
		if ( $this->insert() ) {
			return true;
		}

		$taken = $this->takenAt();

		if ( null !== $taken && (int) $taken > time() - $this->seconds ) {
			return false;
		}

		if ( null !== $taken ) {
			$this->deleteIfStill( $taken );
		}

		return $this->insert();
	}

	/**
	 * Releases the lock.
	 */
	public function release(): void {
		delete_option( $this->name );
	}

	/**
	 * Inserts the row unless it exists. True when this request inserted it.
	 */
	private function insert(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Only a direct INSERT IGNORE is atomic.
		$inserted = 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $this->name, (string) time() ) );

		$this->forgetCache();

		return $inserted;
	}

	/**
	 * The time in the row, read from the database because the cache may not know the row. Null without a row.
	 */
	private function takenAt(): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The cache may be out of date.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->name ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Deletes an expired row, only when it still holds the time that was read, so of two requests that
	 * take over the same expired lock only one gets it.
	 *
	 * @param string $taken The time that was read.
	 */
	private function deleteIfStill( string $taken ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare and delete in one statement.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $this->name, $taken ) );

		$this->forgetCache();
	}

	/**
	 * Removes the option from the object cache, so get_option reads the row as it is now.
	 */
	private function forgetCache(): void {
		wp_cache_delete( $this->name, 'options' );

		$missing = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $missing ) && isset( $missing[ $this->name ] ) ) {
			unset( $missing[ $this->name ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
	}
}
