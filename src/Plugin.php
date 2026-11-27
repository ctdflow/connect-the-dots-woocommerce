<?php
/**
 * The plugin itself: boots the parts of Connect the Dots once WordPress has loaded all plugins.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use ConnectTheDots\Admin\Menu;
use ConnectTheDots\Admin\OrderListColumn;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the plugin.
 */
class Plugin {

	/**
	 * Action Scheduler group of the scheduled actions of the plugin.
	 *
	 * @var string
	 */
	public const SCHEDULE_GROUP = 'connect-the-dots';

	/**
	 * The one instance of the plugin.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Version of the plugin, kept equal to the Version header of connect-the-dots.php.
	 *
	 * @var string
	 */
	private string $version = '0.1.0';

	/**
	 * Path of the main plugin file.
	 *
	 * @var string
	 */
	private string $file = '';

	/**
	 * Returns the one instance of the plugin.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new Plugin();
		}

		return self::$instance;
	}

	/**
	 * Hooks the plugin into WordPress and WooCommerce.
	 *
	 * @param string $file Path of the main plugin file.
	 */
	public function boot( string $file ): void {
		$this->file = $file;

		add_action( 'before_woocommerce_init', array( $this, 'declareCompatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'load' ) );
		add_action( 'init', array( $this, 'loadTextdomain' ) );
	}

	/**
	 * Loads the translation that ships with the plugin, for locales that have no translation from
	 * translate.wordpress.org yet.
	 */
	public function loadTextdomain(): void {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Shops that get the zip directly have no language pack from wordpress.org.
		load_plugin_textdomain( 'connect-the-dots', false, dirname( plugin_basename( $this->file ) ) . '/languages' );
	}

	/**
	 * Registers the hooks of the plugin once all plugins are loaded.
	 */
	public function load(): void {
		$this->registerHooks( class_exists( 'WooCommerce' ) );
	}

	/**
	 * Registers the hooks of the plugin. Without WooCommerce it only explains that WooCommerce is needed.
	 *
	 * @param bool $woocommerce_active Whether WooCommerce is active.
	 */
	public function registerHooks( bool $woocommerce_active ): void {
		if ( ! $woocommerce_active ) {
			add_action( 'admin_notices', array( $this, 'showMissingWooCommerceNotice' ) );
			return;
		}

		Menu::create()->register();
		OrderListColumn::create()->register();

		add_action( DeliverySync::EVENT, array( $this, 'syncDeliveries' ) );
		add_action( 'init', array( DeliverySync::class, 'schedule' ) );

		add_action( ScheduledPlanning::EVENT, array( $this, 'planScheduled' ) );
		add_action( ScheduledPlanning::APPLY_EVENT, array( $this, 'applyScheduled' ), 10, 2 );
		add_action( 'init', array( ScheduledPlanning::class, 'ensureScheduled' ) );
		add_action( 'add_option_' . Settings::OPTION, array( ScheduledPlanning::class, 'schedule' ) );
		add_action( 'update_option_' . Settings::OPTION, array( ScheduledPlanning::class, 'settingsChanged' ), 10, 2 );
		// The run is due at a time in the site timezone, so a new timezone moves it.
		add_action( 'update_option_timezone_string', array( ScheduledPlanning::class, 'schedule' ), 10, 0 );
		add_action( 'update_option_gmt_offset', array( ScheduledPlanning::class, 'schedule' ), 10, 0 );
	}

	/**
	 * Plans the delivery day of the event. Runs on the scheduled planning action.
	 *
	 * @param string $date Day to plan as Y-m-d.
	 */
	public function planScheduled( $date = '' ): void {
		ScheduledPlanning::create()->handleEvent( (string) $date );
	}

	/**
	 * Applies the scheduled plan of a day to the orders. Runs two minutes after planning.
	 *
	 * @param string $date    Delivery date as Y-m-d.
	 * @param int    $attempt Number of this check.
	 */
	public function applyScheduled( $date, $attempt = 1 ): void {
		ScheduledPlanning::create()->apply( (string) $date, (int) $attempt );
	}

	/**
	 * Pulls the delivered check-offs onto the orders. Runs on the hourly action.
	 */
	public function syncDeliveries(): void {
		DeliverySync::create()->run();
	}

	/**
	 * Schedules the hourly delivery sync. Runs when the plugin is activated.
	 */
	public static function activate(): void {
		DeliverySync::schedule();
	}

	/**
	 * Removes the scheduled actions. Runs when the plugin is deactivated.
	 */
	public static function deactivate(): void {
		DeliverySync::unschedule();
		ScheduledPlanning::unschedule();
	}

	/**
	 * Whether Action Scheduler, which WooCommerce loads, is ready to take scheduled actions.
	 */
	public static function canSchedule(): bool {
		return function_exists( 'as_schedule_single_action' ) && did_action( 'action_scheduler_init' ) > 0;
	}

	/**
	 * Declares that the plugin works with High-Performance Order Storage and with the cart and checkout blocks.
	 */
	public function declareCompatibility(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', $this->file, true );
			FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', $this->file, true );
		}
	}

	/**
	 * Tells administrators that the plugin does nothing without WooCommerce.
	 */
	public function showMissingWooCommerceNotice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Connect the Dots needs WooCommerce. Install and activate WooCommerce to plan delivery routes.', 'connect-the-dots' )
		);
	}

	/**
	 * Version of the plugin.
	 */
	public function version(): string {
		return $this->version;
	}

	/**
	 * Base URL of the Connect the Dots API, without a trailing slash.
	 */
	public function apiUrl(): string {
		$url = defined( 'CTDFLOW_API_URL' ) ? (string) constant( 'CTDFLOW_API_URL' ) : 'https://api.ctdflow.com';

		return untrailingslashit( $url );
	}
}
