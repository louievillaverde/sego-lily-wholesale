<?php
/**
 * Template: Wholesale Customer Dashboard
 *
 * Rendered by the [sego_wholesale_dashboard] shortcode.
 * Shows a branded hub for wholesale customers with welcome card,
 * account summary, quick links, and full order history with reorder.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$user          = wp_get_current_user();
$first_name    = $user->first_name ?: $user->display_name;
$business_name = get_user_meta( $user->ID, 'slw_business_name', true );
$has_ordered   = get_user_meta( $user->ID, 'slw_first_order_placed', true );
$net30_approved = get_user_meta( $user->ID, 'slw_net30_approved', true ) === '1';

// Pagination
$current_page = isset( $_GET['slw_page'] ) ? absint( $_GET['slw_page'] ) : 1;
$order_data   = SLW_Dashboard::get_orders( $user->ID, $current_page, 10 );
$orders       = $order_data['orders'];
$total_pages  = $order_data['pages'];

// Nonce for reorder AJAX
$reorder_nonce = wp_create_nonce( 'slw_reorder_nonce' );
?>

<div class="slw-dashboard-wrap">

	<!-- Header / Welcome -->
	<div class="slw-dashboard-header">
		<h2>Welcome back, <?php echo esc_html( $first_name ); ?></h2>
		<?php if ( $business_name ) : ?>
			<p class="slw-business-name"><?php echo esc_html( $business_name ); ?></p>
		<?php endif; ?>
	</div>

	<div class="slw-dashboard-grid">

		<!-- Account Summary -->
		<div class="slw-dashboard-card">
			<h3>Account Summary</h3>
			<dl class="slw-account-summary">
				<div class="slw-account-summary-row">
					<dt>Wholesale Tier</dt>
					<dd>Wholesale Partner</dd>
				</div>
				<div class="slw-account-summary-row">
					<dt>Discount</dt>
					<dd><?php echo esc_html( slw_get_option( 'discount_percent', 50 ) ); ?>% off retail</dd>
				</div>
				<div class="slw-account-summary-row">
					<dt>First Order</dt>
					<dd>
						<?php if ( $has_ordered ) : ?>
							<span class="slw-summary-completed">Completed</span>
						<?php else : ?>
							<span class="slw-summary-pending">$<?php echo esc_html( number_format( (float) slw_get_option( 'first_order_minimum', 300 ), 0 ) ); ?> minimum required</span>
						<?php endif; ?>
					</dd>
				</div>
				<?php if ( $net30_approved ) : ?>
				<div class="slw-account-summary-row">
					<dt>Payment Terms</dt>
					<dd><strong>NET 30 Approved</strong></dd>
				</div>
				<?php endif; ?>
			</dl>
		</div>

		<!-- Quick Links -->
		<div class="slw-dashboard-card">
			<h3>Quick Links</h3>
			<ul class="slw-quick-links">
				<li><a href="<?php echo esc_url( home_url( '/wholesale-order' ) ); ?>" class="slw-btn slw-btn-primary">Place a New Order</a></li>
				<li><a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="slw-btn slw-btn-secondary">View Cart</a></li>
				<li><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-account' ) ); ?>">Edit Account Details</a></li>
				<li><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-address' ) ); ?>">Update Shipping Address</a></li>
				<?php
				$contact_email = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'from_address' ) : get_option( 'admin_email' );
				$contact_name  = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'owner_name' ) : '';
				$contact_label = $contact_name ? 'Contact ' . esc_html( $contact_name ) : 'Contact Us';
				?>
				<li><a href="mailto:<?php echo esc_attr( $contact_email ); ?>"><?php echo $contact_label; ?></a></li>
			</ul>
		</div>

		<!-- Order History (full width) -->
		<div class="slw-dashboard-card slw-dashboard-card-wide">
			<h3>Order History</h3>
			<?php if ( empty( $orders ) ) : ?>
				<p class="slw-empty-orders">No orders yet. <a href="<?php echo esc_url( home_url( '/wholesale-order' ) ); ?>">Place your first order</a> to get started.</p>
			<?php else : ?>
			<div class="slw-order-history-table-wrap">
				<table class="slw-order-history-table">
					<thead>
						<tr>
							<th>Order #</th>
							<th>Date</th>
							<th>Status</th>
							<th>Items</th>
							<th>Total</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $orders as $order ) :
							$status     = $order->get_status();
							$item_count = $order->get_item_count();
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
									#<?php echo esc_html( $order->get_order_number() ); ?>
								</a>
							</td>
							<td><?php echo esc_html( $order->get_date_created()->date( 'M j, Y' ) ); ?></td>
							<td>
								<span class="slw-status-badge slw-status-<?php echo esc_attr( $status ); ?>">
									<?php echo esc_html( wc_get_order_status_name( $status ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $item_count ); ?></td>
							<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
							<td class="slw-order-actions">
								<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" class="slw-btn slw-btn-small slw-btn-ghost">View</a>
								<button type="button"
									class="slw-btn slw-btn-small slw-reorder-btn"
									data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
									title="Add all items from this order to your cart">
									Reorder
								</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $total_pages > 1 ) : ?>
			<div class="slw-pagination">
				<?php if ( $current_page > 1 ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'slw_page', $current_page - 1 ) ); ?>" class="slw-btn slw-btn-small slw-btn-ghost">&larr; Previous</a>
				<?php else : ?>
					<span class="slw-btn slw-btn-small slw-btn-ghost slw-pagination-disabled">&larr; Previous</span>
				<?php endif; ?>

				<span class="slw-pagination-info">
					Page <?php echo esc_html( $current_page ); ?> of <?php echo esc_html( $total_pages ); ?>
				</span>

				<?php if ( $current_page < $total_pages ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'slw_page', $current_page + 1 ) ); ?>" class="slw-btn slw-btn-small slw-btn-ghost">Next &rarr;</a>
				<?php else : ?>
					<span class="slw-btn slw-btn-small slw-btn-ghost slw-pagination-disabled">Next &rarr;</span>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php endif; ?>
		</div>

		<!-- Resources -->
		<div class="slw-dashboard-card slw-dashboard-card-wide">
			<h3>Wholesale Resources</h3>
			<?php
			$res_email = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'from_address' ) : get_option( 'admin_email' );
			$res_owner = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'owner_name' ) : '';
			$res_label = $res_owner ? sprintf( 'Contact %s (%s)', esc_html( $res_owner ), esc_html( $res_email ) ) : sprintf( 'Contact Us (%s)', esc_html( $res_email ) );
			$res_help  = $res_owner
				? sprintf( 'Need brand assets, shelf talkers, or marketing materials? Email %s and they\'ll send them over.', esc_html( $res_owner ) )
				: 'Need brand assets, shelf talkers, or marketing materials? Reach out and we\'ll send them over.';
			?>
			<ul class="slw-resource-links">
				<li><a href="mailto:<?php echo esc_attr( $res_email ); ?>"><?php echo $res_label; ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/wholesale-order' ) ); ?>">Product Catalog / Order Form</a></li>
			</ul>
			<p class="slw-help-text"><?php echo $res_help; ?></p>
		</div>

	</div>
</div>

<!-- Reorder AJAX -->
<script>
(function() {
	var buttons = document.querySelectorAll('.slw-reorder-btn');
	if ( ! buttons.length ) return;

	buttons.forEach(function(btn) {
		btn.addEventListener('click', function(e) {
			e.preventDefault();
			var button  = this;
			var orderId = button.getAttribute('data-order-id');

			if ( button.disabled ) return;

			// Loading state
			button.disabled = true;
			var originalText = button.textContent;
			button.textContent = 'Adding...';

			var formData = new FormData();
			formData.append('action', 'slw_reorder');
			formData.append('order_id', orderId);
			formData.append('nonce', '<?php echo esc_js( $reorder_nonce ); ?>');

			fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			})
			.then(function(response) { return response.json(); })
			.then(function(data) {
				if ( data.success ) {
					// Show skipped items notice if any
					if ( data.data.skipped && data.data.skipped.length ) {
						alert( 'Some items were skipped:\n\n' + data.data.skipped.join('\n') + '\n\n' + data.data.message );
					}
					// Redirect to cart
					window.location.href = data.data.redirect;
				} else {
					var msg = data.data.message || 'Something went wrong.';
					if ( data.data.skipped && data.data.skipped.length ) {
						msg += '\n\nSkipped:\n' + data.data.skipped.join('\n');
					}
					alert( msg );
					button.disabled = false;
					button.textContent = originalText;
				}
			})
			.catch(function() {
				alert('Something went wrong. Please try again.');
				button.disabled = false;
				button.textContent = originalText;
			});
		});
	});
})();
</script>
