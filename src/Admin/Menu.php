<?php
/**
 * The Delivery routes admin page: menu entry, tabs, the OAuth callback and the connect and disconnect actions.
 *
 * @package ConnectTheDots
 */

namespace ConnectTheDots\Admin;

use ConnectTheDots\Account;
use ConnectTheDots\ApiError;
use ConnectTheDots\Connection;
use ConnectTheDots\DeliverySync;
use ConnectTheDots\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shell of the page with the slug ctdflow-delivery-routes. The Routes tab is rendered by RoutesPage, the
 * Settings tab by SettingsPage.
 */
class Menu {

	/**
	 * Slug of the admin page, also used in the OAuth redirect URI.
	 *
	 * @var string
	 */
	public const SLUG = 'ctdflow-delivery-routes';

	/**
	 * Hook suffix of the page, empty until the menu is added.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * @param Connection   $connection   Connection with Connect the Dots.
	 * @param SettingsPage $settingsPage Renders the Settings tab.
	 * @param RoutesPage   $routesPage   Renders the Routes tab.
	 */
	public function __construct(
		private Connection $connection,
		private SettingsPage $settingsPage,
		private RoutesPage $routesPage
	) {
	}

	/**
	 * Builds the page with the real connection and settings.
	 */
	public static function create(): Menu {
		return new Menu(
			new Connection(),
			new SettingsPage( new Settings(), new Connection(), new Account() ),
			RoutesPage::create()
		);
	}

	/**
	 * Hooks the page into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_init', array( $this, 'handleOauthCallback' ) );
		add_action( 'admin_post_ctdflow_connect', array( $this, 'handleConnect' ) );
		add_action( 'admin_post_ctdflow_disconnect', array( $this, 'handleDisconnect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		$this->settingsPage->register();
		$this->routesPage->register();
	}

	/**
	 * Adds Delivery routes below WooCommerce.
	 */
	public function addMenu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Delivery routes', 'connect-the-dots' ),
			__( 'Delivery routes', 'connect-the-dots' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);

		$this->hook = is_string( $hook ) ? $hook : '';

		if ( '' !== $this->hook ) {
			add_action( 'load-' . $this->hook, array( $this, 'syncDeliveries' ) );
		}
	}

	/**
	 * Pulls the delivered check-offs onto the orders when the Routes tab loads, at most every five
	 * minutes.
	 */
	public function syncDeliveries(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Viewing a tab changes nothing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'routes';

		if ( 'settings' !== $tab && current_user_can( 'manage_woocommerce' ) ) {
			DeliverySync::create()->runThrottled();
		}
	}

	/**
	 * Loads the script and style of the Routes tab, on this page only.
	 *
	 * @param string $hook Hook suffix of the admin page being loaded.
	 */
	public function enqueue( string $hook ): void {
		if ( '' !== $this->hook && $hook === $this->hook ) {
			$this->routesPage->enqueue();
		}
	}

	/**
	 * URL of the page or one of its tabs.
	 *
	 * @param string $tab Tab, routes or settings.
	 */
	public static function url( string $tab = 'routes' ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Receives the shop owner back from Connect the Dots. The single use state is the CSRF protection
	 * here, which is why there is no nonce.
	 */
	public function handleOauthCallback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'], $_GET['ctdflow_oauth'] ) || self::SLUG !== $_GET['page'] || 'callback' !== $_GET['ctdflow_oauth'] ) {
			return;
		}

		$this->requireCapability();

		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$denied = isset( $_GET['error'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $denied || '' === $code || '' === $state ) {
			$this->flash( 'error', __( 'The connection with Connect the Dots was cancelled. Try connecting again.', 'connect-the-dots' ) );
			$this->redirect( self::url() );
			return;
		}

		try {
			$this->connection->handleCallback( $code, $state );
			$this->flash( 'success', __( 'Your shop is connected to Connect the Dots.', 'connect-the-dots' ) );
		} catch ( ApiError $error ) {
			$this->flash( 'error', $error->userMessage );
			$this->redirect( self::url() );
			return;
		}

		// Back to the day the owner was planning; the Routes tab restores the kept selection once.
		$date = $this->routesPage->rememberedDate();

		$this->redirect( null === $date ? self::url() : add_query_arg( 'date', $date, self::url() ) );
	}

	/**
	 * Sends the shop owner to Connect the Dots to approve the connection.
	 */
	public function handleConnect(): void {
		check_admin_referer( 'ctdflow_connect' );
		$this->requireCapability();

		if ( isset( $_POST['ctdflow_selection'] ) ) {
			$this->routesPage->rememberSelection( map_deep( wp_unslash( $_POST['ctdflow_selection'] ), 'sanitize_text_field' ) );
		}

		try {
			$url = $this->connection->authorizeUrl();
		} catch ( ApiError $error ) {
			$this->flash( 'error', $error->userMessage );
			$this->redirect( self::url() );
			return;
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		add_filter( 'allowed_redirect_hosts', static fn ( array $hosts ): array => array_merge( $hosts, array( $host ) ) );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Forgets the connection.
	 */
	public function handleDisconnect(): void {
		check_admin_referer( 'ctdflow_disconnect' );
		$this->requireCapability();

		$this->connection->disconnect();
		$this->flash( 'success', __( 'Your shop is disconnected from Connect the Dots.', 'connect-the-dots' ) );
		$this->redirect( self::url( 'settings' ) );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		$this->requireCapability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ? 'settings' : 'routes';

		echo '<div class="wrap"><h1>' . esc_html__( 'Delivery routes', 'connect-the-dots' ) . '</h1>';

		$this->renderNotice();
		settings_errors( Settings::OPTION );
		// options.php files "Settings saved." under general, which only settings pages of WordPress itself show.
		settings_errors( 'general' );

		echo '<nav class="nav-tab-wrapper">';
		printf(
			'<a href="%s" class="nav-tab%s">%s</a><a href="%s" class="nav-tab%s">%s</a>',
			esc_url( self::url() ),
			'routes' === $tab ? ' nav-tab-active' : '',
			esc_html__( 'Routes', 'connect-the-dots' ),
			esc_url( self::url( 'settings' ) ),
			'settings' === $tab ? ' nav-tab-active' : '',
			esc_html__( 'Settings', 'connect-the-dots' )
		);
		echo '</nav>';

		if ( 'settings' === $tab ) {
			$this->settingsPage->render();
		} else {
			$this->routesPage->render();
		}

		echo '</div>';
	}

	/**
	 * Shows the notice left by the last action, once.
	 */
	private function renderNotice(): void {
		$key    = $this->noticeKey();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'success' === $notice['type'] ? 'success' : 'error',
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Keeps a notice for the next page load of the current user.
	 *
	 * @param string $type    success or error.
	 * @param string $message Message.
	 */
	private function flash( string $type, string $message ): void {
		set_transient(
			$this->noticeKey(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Transient key of the notice of the current user.
	 */
	private function noticeKey(): string {
		return 'ctdflow_notice_' . get_current_user_id();
	}

	/**
	 * Redirects inside wp-admin and stops.
	 *
	 * @param string $url Destination.
	 */
	private function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Stops users who may not manage WooCommerce.
	 */
	private function requireCapability(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'connect-the-dots' ), '', array( 'response' => 403 ) );
		}
	}
}
