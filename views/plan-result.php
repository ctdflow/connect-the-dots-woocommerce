<?php
/**
 * The routes of a succeeded plan: per van the stops with their expected time, then the orders that were not
 * planned. Included by RoutesPage::resultHtml().
 *
 * @package ConnectTheDots
 *
 * @var array $ctdflow_routes    Per van: id, vehicle, driver_link, duration, distance and stops.
 * @var array $ctdflow_unplanned Orders that were not planned, with the reason.
 */

defined( 'ABSPATH' ) || exit;

// The variables of a view are local to the method that includes it, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
<div class="ctdflow-result">
	<?php foreach ( $ctdflow_routes as $ctdflow_route ) : ?>
		<div class="ctdflow-route">
			<div class="ctdflow-route-header">
				<h3>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: name of the van, 2: number of stops, like "18 stops", 3: driving time, 4: distance. */
							__( '%1$s · %2$s · %3$s · %4$s', 'connect-the-dots' ),
							$ctdflow_route['vehicle'],
							/* translators: %d: number of stops. */
							sprintf( _n( '%d stop', '%d stops', count( $ctdflow_route['stops'] ), 'connect-the-dots' ), count( $ctdflow_route['stops'] ) ),
							$ctdflow_route['duration'],
							$ctdflow_route['distance']
						)
					);
					?>
				</h3>
				<?php if ( '' !== $ctdflow_route['driver_link'] ) : ?>
					<p class="ctdflow-driver-link">
						<label for="<?php echo esc_attr( $ctdflow_route['id'] ); ?>"><?php esc_html_e( 'Driver link', 'connect-the-dots' ); ?></label>
						<input type="text" readonly id="<?php echo esc_attr( $ctdflow_route['id'] ); ?>" value="<?php echo esc_attr( $ctdflow_route['driver_link'] ); ?>" class="regular-text" />
						<button type="button" class="button" data-ctdflow-copy="<?php echo esc_attr( $ctdflow_route['id'] ); ?>"><?php esc_html_e( 'Copy', 'connect-the-dots' ); ?></button>
						<a href="<?php echo esc_url( $ctdflow_route['driver_link'] ); ?>" class="button" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open', 'connect-the-dots' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Stop', 'connect-the-dots' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Expected', 'connect-the-dots' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Order', 'connect-the-dots' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Address', 'connect-the-dots' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Time window', 'connect-the-dots' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Delivered', 'connect-the-dots' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ctdflow_route['stops'] as $ctdflow_stop ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $ctdflow_stop['position'] ); ?></td>
							<td><?php echo esc_html( $ctdflow_stop['expected'] ); ?></td>
							<td>
								<?php if ( '' !== $ctdflow_stop['order']['url'] ) : ?>
									<a href="<?php echo esc_url( $ctdflow_stop['order']['url'] ); ?>">#<?php echo esc_html( $ctdflow_stop['order']['number'] ); ?></a>
								<?php else : ?>
									#<?php echo esc_html( $ctdflow_stop['order']['number'] ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $ctdflow_stop['address'] ); ?></td>
							<td><?php echo esc_html( $ctdflow_stop['window'] ); ?></td>
							<td><?php echo '' !== $ctdflow_stop['delivered'] ? esc_html( '✓ ' . $ctdflow_stop['delivered'] ) : ''; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endforeach; ?>

	<?php if ( array() !== $ctdflow_unplanned ) : ?>
		<h3><?php esc_html_e( 'Not planned', 'connect-the-dots' ); ?></h3>
		<ul class="ctdflow-unplanned">
			<?php foreach ( $ctdflow_unplanned as $ctdflow_item ) : ?>
				<li>
					#<?php echo esc_html( $ctdflow_item['order']['number'] ); ?>
					<?php echo esc_html( $ctdflow_item['reason'] ); ?>
					<?php if ( '' !== $ctdflow_item['order']['url'] ) : ?>
						<a href="<?php echo esc_url( $ctdflow_item['order']['url'] ); ?>"><?php esc_html_e( 'Edit order', 'connect-the-dots' ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
