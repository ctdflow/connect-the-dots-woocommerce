<?php
/**
 * The Routes tab of a connected shop: the day, its orders, the vans, the Plan button and the result.
 * Included by RoutesPage::render().
 *
 * @package ConnectTheDots
 *
 * @var string                                  $ctdflow_date          Delivery date as Y-m-d.
 * @var string                                  $ctdflow_added         Ids of orders added by hand, separated by commas.
 * @var array|null                              $ctdflow_account       Account JSON, null when it could not be read.
 * @var string|null                             $ctdflow_account_error Why the account could not be read.
 * @var string                                  $ctdflow_plan_label    Label of the billing status.
 * @var array|null                              $ctdflow_tier          Free tier limits, null for a paid account.
 * @var array                                   $ctdflow_orders        Rows of orders that can be planned.
 * @var array                                   $ctdflow_unplannable   Rows of orders that cannot be planned.
 * @var int                                     $ctdflow_delivered     Number of delivered orders of the day that were left out.
 * @var \ConnectTheDots\Vehicle[]               $ctdflow_vans          Active vans.
 * @var string[]                                $ctdflow_checked_vans  Ids of the vans that are checked.
 * @var string                                  $ctdflow_choice        HTML of the free tier choice, empty when not needed.
 * @var array{done: bool, html: string}|null    $ctdflow_result        The stored plan of the day.
 */

use ConnectTheDots\Admin\RoutesPage;

defined( 'ABSPATH' ) || exit;

// The variables of a view are local to the method that includes it, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
<div class="ctdflow-routes">
	<?php if ( null !== $ctdflow_account ) : ?>
		<p class="ctdflow-account">
			<?php
			printf(
				/* translators: 1: organisation name, 2: plan such as Free or Active. */
				esc_html__( 'Connected: %1$s · %2$s', 'connect-the-dots' ),
				'<strong>' . esc_html( (string) ( $ctdflow_account['organization'] ?? '' ) ) . '</strong>',
				esc_html( $ctdflow_plan_label )
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( null !== $ctdflow_account_error ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $ctdflow_account_error ); ?></p></div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ctdflow-day">
		<input type="hidden" name="page" value="<?php echo esc_attr( \ConnectTheDots\Admin\Menu::SLUG ); ?>" />
		<input type="hidden" name="tab" value="routes" />
		<label>
			<?php esc_html_e( 'Delivery day', 'connect-the-dots' ); ?>
			<input type="date" name="date" value="<?php echo esc_attr( $ctdflow_date ); ?>" />
		</label>
		<label>
			<?php esc_html_e( 'Add orders by ID', 'connect-the-dots' ); ?>
			<input type="text" name="add" value="<?php echo esc_attr( $ctdflow_added ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'For example 1043, 1051', 'connect-the-dots' ); ?>" />
		</label>
		<?php submit_button( __( 'Show', 'connect-the-dots' ), 'secondary', '', false ); ?>
	</form>

	<form method="post" id="ctdflow-plan-form">
		<input type="hidden" name="date" value="<?php echo esc_attr( $ctdflow_date ); ?>" />

		<h2>
			<?php
			printf(
				/* translators: %d: number of orders. */
				esc_html( _n( '%d order to plan', '%d orders to plan', count( $ctdflow_orders ), 'connect-the-dots' ) ),
				count( $ctdflow_orders )
			);
			?>
		</h2>

		<?php if ( $ctdflow_delivered > 0 ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of delivered orders. */
					esc_html( _n( '%d order of this day is delivered already and is left out.', '%d orders of this day are delivered already and are left out.', $ctdflow_delivered, 'connect-the-dots' ) ),
					(int) $ctdflow_delivered
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( array() === $ctdflow_orders ) : ?>
			<p><?php esc_html_e( 'There are no orders to plan for this day.', 'connect-the-dots' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Uncheck an order to leave it out of the plan.', 'connect-the-dots' ); ?></p>
			<table class="widefat striped ctdflow-orders">
				<thead>
					<tr>
						<td class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Plan', 'connect-the-dots' ); ?></span></td>
						<th scope="col"><?php esc_html_e( 'Order', 'connect-the-dots' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Address', 'connect-the-dots' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Time window', 'connect-the-dots' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Items', 'connect-the-dots' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ctdflow_orders as $ctdflow_row ) : ?>
						<tr>
							<th scope="row" class="check-column">
								<input type="checkbox" name="order_ids[]" value="<?php echo esc_attr( (string) $ctdflow_row['id'] ); ?>"<?php checked( $ctdflow_row['checked'] ); ?> id="ctdflow-order-<?php echo esc_attr( (string) $ctdflow_row['id'] ); ?>" />
							</th>
							<td><label for="ctdflow-order-<?php echo esc_attr( (string) $ctdflow_row['id'] ); ?>"><a href="<?php echo esc_url( $ctdflow_row['order']['url'] ); ?>">#<?php echo esc_html( $ctdflow_row['order']['number'] ); ?></a></label></td>
							<td><?php echo esc_html( $ctdflow_row['address'] ); ?></td>
							<td><?php echo esc_html( $ctdflow_row['window'] ); ?></td>
							<td><?php echo esc_html( (string) $ctdflow_row['quantity'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( array() !== $ctdflow_unplannable ) : ?>
			<h3><?php esc_html_e( 'Cannot be planned', 'connect-the-dots' ); ?></h3>
			<ul class="ctdflow-unplannable">
				<?php foreach ( $ctdflow_unplannable as $ctdflow_row ) : ?>
					<li>
						<a href="<?php echo esc_url( $ctdflow_row['order']['url'] ); ?>">#<?php echo esc_html( $ctdflow_row['order']['number'] ); ?></a>
						<?php echo esc_html( $ctdflow_row['reason'] ); ?>
						<a href="<?php echo esc_url( $ctdflow_row['order']['url'] ); ?>"><?php esc_html_e( 'Edit order', 'connect-the-dots' ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Vans', 'connect-the-dots' ); ?></h2>
		<?php if ( array() === $ctdflow_vans ) : ?>
			<p>
				<?php esc_html_e( 'There is no active van.', 'connect-the-dots' ); ?>
				<a href="<?php echo esc_url( \ConnectTheDots\Admin\Menu::url( 'settings' ) ); ?>"><?php esc_html_e( 'Set up your vans', 'connect-the-dots' ); ?></a>
			</p>
		<?php else : ?>
			<p class="ctdflow-vans">
				<?php foreach ( $ctdflow_vans as $ctdflow_van ) : ?>
					<label><input type="checkbox" name="vehicle_ids[]" value="<?php echo esc_attr( $ctdflow_van->id ); ?>"<?php checked( in_array( $ctdflow_van->id, $ctdflow_checked_vans, true ) ); ?> /> <?php echo esc_html( $ctdflow_van->name ); ?></label>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>

		<?php if ( null !== $ctdflow_tier ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: stops left for the day, 2: stops per day on the free plan, 3: vans per plan. */
					esc_html( _n( 'Free plan: %1$d of %2$d stops left for this day, %3$d van per plan.', 'Free plan: %1$d of %2$d stops left for this day, %3$d vans per plan.', $ctdflow_tier['max_vehicles'], 'connect-the-dots' ) ),
					(int) $ctdflow_tier['remaining_stops'],
					(int) $ctdflow_tier['max_stops'],
					(int) $ctdflow_tier['max_vehicles']
				);
				?>
			</p>
		<?php endif; ?>

		<div id="ctdflow-choice"><?php echo wp_kses( $ctdflow_choice, RoutesPage::allowedHtml() ); ?></div>

		<p>
			<button type="submit" name="choice" value="" class="button button-primary" id="ctdflow-plan-button">
				<?php echo null === $ctdflow_result ? esc_html__( 'Plan', 'connect-the-dots' ) : esc_html__( 'Plan again', 'connect-the-dots' ); ?>
			</button>
		</p>

		<div id="ctdflow-messages" aria-live="polite"></div>
	</form>

	<div id="ctdflow-result" aria-live="polite" data-ctdflow-pending="<?php echo null !== $ctdflow_result && ! $ctdflow_result['done'] ? '1' : '0'; ?>">
		<?php
		if ( null !== $ctdflow_result ) {
			echo wp_kses( $ctdflow_result['html'], RoutesPage::allowedHtml() );
		}
		?>
	</div>
</div>
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
