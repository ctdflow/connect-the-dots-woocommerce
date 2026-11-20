<?php
/**
 * Loads the classes of the ConnectTheDots namespace from src/.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-4 autoloader, so the plugin ships without Composer.
 */
class Autoloader {

	/**
	 * Registers the autoloader for classes in the ConnectTheDots namespace.
	 *
	 * @param string $directory Directory that holds the classes of the namespace.
	 */
	public static function register( string $directory ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $directory ): void {
				$prefix = __NAMESPACE__ . '\\';

				if ( ! str_starts_with( $class_name, $prefix ) ) {
					return;
				}

				$file = $directory . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
