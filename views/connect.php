<?php
/**
 * The Routes tab before the shop is connected: what Connect the Dots does, what it receives, and the
 * button to connect. Included by RoutesPage::renderConnect().
 *
 * @package ConnectTheDots
 *
 * @var string|null $ctdflow_message   Why the shop has to connect again, if it was connected.
 * @var string      $ctdflow_selection Hidden fields that keep the selection over a new connection.
 */

use ConnectTheDots\Admin\RoutesPage;

defined( 'ABSPATH' ) || exit;
?>
<div class="ctdflow-connect">
	<?php if ( null !== $ctdflow_message ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $ctdflow_message ); ?></p></div>
	<?php endif; ?>

	<p><?php esc_html_e( 'Connect your shop to Connect the Dots to plan the routes of your deliveries. You choose a day, Connect the Dots plans the orders of that day over your vans, and every driver gets a link with the route.', 'connect-the-dots' ); ?></p>

	<h2><?php esc_html_e( 'What goes to Connect the Dots', 'connect-the-dots' ); ?></h2>
	<p><?php esc_html_e( 'For every order you plan:', 'connect-the-dots' ); ?></p>
	<ul class="ul-disc">
		<li><?php esc_html_e( 'the order ID, as reference', 'connect-the-dots' ); ?></li>
		<li><?php esc_html_e( 'the delivery address: street with house number, postcode and city', 'connect-the-dots' ); ?></li>
		<li><?php esc_html_e( 'the time window, if the order has one', 'connect-the-dots' ); ?></li>
		<li><?php esc_html_e( 'the number of items', 'connect-the-dots' ); ?></li>
		<li><?php esc_html_e( 'how long a stop takes, the minutes per stop from your settings', 'connect-the-dots' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'For every van:', 'connect-the-dots' ); ?></p>
	<ul class="ul-disc">
		<li><?php esc_html_e( 'the name of each van', 'connect-the-dots' ); ?></li>
		<li><?php esc_html_e( 'its start and end address', 'connect-the-dots' ); ?></li>
		<li><?php esc_html_e( 'the times it is available', 'connect-the-dots' ); ?></li>
		<li><?php esc_html_e( 'its capacity, if you set one', 'connect-the-dots' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'For every plan:', 'connect-the-dots' ); ?></p>
	<ul class="ul-disc">
		<li><?php esc_html_e( 'the delivery date and the time zone of your site', 'connect-the-dots' ); ?></li>
		<li><?php esc_html_e( 'the web address of your shop', 'connect-the-dots' ); ?></li>
		<li><?php esc_html_e( 'the depot address and its country', 'connect-the-dots' ); ?></li>
		<li><?php esc_html_e( 'whether the plan was started by hand or automatically', 'connect-the-dots' ); ?></li>
	</ul>
	<p><strong><?php esc_html_e( 'Never names, e-mail addresses, phone numbers, amounts or products.', 'connect-the-dots' ); ?></strong></p>
	<p>
		<?php
		printf(
			/* translators: 1: link to the privacy statement, 2: link to the terms. */
			esc_html__( 'Read the %1$s and the %2$s of Connect the Dots.', 'connect-the-dots' ),
			'<a href="' . esc_url( 'https://ctdflow.com/privacy' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'privacy statement', 'connect-the-dots' ) . '</a>',
			'<a href="' . esc_url( 'https://ctdflow.com/terms' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'terms', 'connect-the-dots' ) . '</a>'
		);
		?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ctdflow_connect" />
		<?php wp_nonce_field( 'ctdflow_connect' ); ?>
		<?php echo wp_kses( $ctdflow_selection, RoutesPage::allowedHtml() ); ?>
		<?php submit_button( null === $ctdflow_message ? __( 'Connect to Connect the Dots', 'connect-the-dots' ) : __( 'Connect again', 'connect-the-dots' ), 'primary', 'submit', false ); ?>
	</form>
</div>
