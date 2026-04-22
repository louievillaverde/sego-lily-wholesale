<?php
/**
 * Request for Quote (RFQ) System
 *
 * Allows wholesale customers to submit multi-product quote requests for
 * larger or custom orders. Admins review, set custom pricing, and respond
 * from a dedicated admin page under "Wholesale Applications > Quote Requests."
 *
 * - Frontend shortcode: [sego_wholesale_rfq]
 * - AJAX submission with nonce verification
 * - Custom DB table for RFQ storage
 * - Admin list + detail views with status management
 * - Email notifications on submission + admin response
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_RFQ {

	public static function init() {
		// Shortcode
		add_shortcode( 'sego_wholesale_rfq', array( __CLASS__, 'render_form' ) );

		// AJAX handlers
		add_action( 'wp_ajax_slw_submit_rfq', array( __CLASS__, 'handle_submission' ) );

		// Admin menu is registered centrally by SLW_Admin_Menu

		// Handle admin actions (quote, accept, decline)
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_action' ) );

		// Self-healing: ensure DB table exists
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_table' ) );
	}

	/* ---------------------------------------------------------------
	   Database Table
	   --------------------------------------------------------------- */

	/**
	 * Create the RFQ database table. Safe to call multiple times (uses
	 * CREATE TABLE IF NOT EXISTS + dbDelta).
	 */
	public static function create_table() {
		global $wpdb;
		$table           = $wpdb->prefix . 'slw_rfq';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL,
			products longtext NOT NULL,
			delivery_date date DEFAULT NULL,
			additional_notes text DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			admin_response text DEFAULT NULL,
			total_quoted decimal(10,2) DEFAULT NULL,
			submitted_at datetime NOT NULL,
			responded_at datetime DEFAULT NULL,
			responded_by bigint(20) UNSIGNED DEFAULT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Self-healing check on admin_init: if the table is missing, create it.
	 */
	public static function maybe_create_table() {
		if ( get_option( 'slw_rfq_table_verified' ) ) {
			return;
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'slw_rfq';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists !== $table ) {
			self::create_table();
		}

		update_option( 'slw_rfq_table_verified', '1', true );
	}

	/* ---------------------------------------------------------------
	   Frontend Shortcode
	   --------------------------------------------------------------- */

	/**
	 * Render the RFQ form. Only visible to logged-in wholesale users.
	 */
	public static function render_form( $atts = array() ) {
		if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) {
			return '<div class="slw-notice slw-notice-warning">Please <a href="' . esc_url( wp_login_url( home_url( '/wholesale-rfq' ) ) ) . '">log in</a> with your wholesale account to request a quote.</div>';
		}

		ob_start();
		include SLW_PLUGIN_DIR . 'templates/rfq-form.php';
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------
	   AJAX Submission
	   --------------------------------------------------------------- */

	/**
	 * Handle RFQ form submission via AJAX.
	 */
	public static function handle_submission() {
		check_ajax_referer( 'slw_rfq_submit', 'slw_nonce' );

		if ( ! is_user_logged_in() || ! slw_is_wholesale_user() ) {
			wp_send_json_error( array( 'message' => 'Wholesale access required.' ) );
		}

		$user_id = get_current_user_id();

		// Parse products JSON
		$products_raw = json_decode( stripslashes( $_POST['products'] ?? '[]' ), true );
		if ( empty( $products_raw ) || ! is_array( $products_raw ) ) {
			wp_send_json_error( array( 'message' => 'Please add at least one product to your quote request.' ) );
		}

		// Sanitize each product row
		$products = array();
		foreach ( $products_raw as $row ) {
			$product_id = absint( $row['product_id'] ?? 0 );
			$quantity   = absint( $row['quantity'] ?? 0 );
			$notes      = sanitize_text_field( $row['notes'] ?? '' );

			if ( $product_id > 0 && $quantity > 0 ) {
				$products[] = array(
					'product_id' => $product_id,
					'quantity'   => $quantity,
					'notes'      => $notes,
				);
			}
		}

		if ( empty( $products ) ) {
			wp_send_json_error( array( 'message' => 'Please add at least one product with a valid quantity.' ) );
		}

		$delivery_date    = sanitize_text_field( $_POST['delivery_date'] ?? '' );
		$additional_notes = sanitize_textarea_field( $_POST['additional_notes'] ?? '' );

		// Validate delivery date format if provided
		if ( $delivery_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $delivery_date ) ) {
			$delivery_date = '';
		}

		// Insert into DB
		global $wpdb;
		$table = $wpdb->prefix . 'slw_rfq';

		$inserted = $wpdb->insert( $table, array(
			'user_id'          => $user_id,
			'products'         => wp_json_encode( $products ),
			'delivery_date'    => $delivery_date ?: null,
			'additional_notes' => $additional_notes,
			'status'           => 'pending',
			'submitted_at'     => current_time( 'mysql' ),
		) );

		if ( $inserted === false ) {
			error_log( 'SLW: RFQ insert failed. DB error: ' . $wpdb->last_error );
			$fallback_email = SLW_Email_Settings::get( 'from_address' );
			wp_send_json_error( array(
				'message' => sprintf(
					'Sorry, we could not save your quote request right now. Please try again or email %s directly.',
					$fallback_email
				),
			) );
		}

		$rfq_id = $wpdb->insert_id;

		// Notify admin
		self::send_admin_notification( $rfq_id, $user_id, $products );

		$owner = SLW_Email_Settings::get( 'owner_name' );
		$rfq_msg = $owner
			? sprintf( 'Your quote request has been submitted! %s will review it and get back to you within 1-2 business days.', $owner )
			: 'Your quote request has been submitted! We will review it and get back to you within 1-2 business days.';

		wp_send_json_success( array(
			'message' => $rfq_msg,
		) );
	}

	/* ---------------------------------------------------------------
	   Email Notifications
	   --------------------------------------------------------------- */

	/**
	 * Send admin notification when a new RFQ is submitted.
	 */
	private static function send_admin_notification( $rfq_id, $user_id, $products ) {
		$admin_email = get_option( 'slw_admin_notification_email', get_option( 'admin_email' ) );
		if ( ! $admin_email ) {
			$admin_email = SLW_Email_Settings::get( 'from_address' );
		}

		$user      = get_userdata( $user_id );
		$name      = $user->first_name ?: $user->display_name;
		$business  = get_user_meta( $user_id, 'slw_business_name', true );
		$num_items = count( $products );

		$subject = 'New Quote Request #' . $rfq_id . ' from ' . ( $business ?: $name );

		$body  = "A new quote request has been submitted.\n\n";
		$body .= "RFQ #: {$rfq_id}\n";
		$body .= "Customer: {$name}\n";
		$body .= "Business: {$business}\n";
		$body .= "Email: {$user->user_email}\n";
		$body .= "Products: {$num_items} item(s)\n\n";

		foreach ( $products as $item ) {
			$product = wc_get_product( $item['product_id'] );
			$pname   = $product ? $product->get_name() : 'Product #' . $item['product_id'];
			$body   .= "  - {$pname} x {$item['quantity']}";
			if ( ! empty( $item['notes'] ) ) {
				$body .= " ({$item['notes']})";
			}
			$body .= "\n";
		}

		$body .= "\nReview it in your WordPress admin:\n";
		$body .= admin_url( 'admin.php?page=slw-rfq&rfq_id=' . $rfq_id ) . "\n";

		$email_headers = SLW_Email_Settings::get_headers();
		$email_headers[] = 'Reply-To: ' . $user->user_email;

		$sent = wp_mail( $admin_email, $subject, $body, $email_headers );
		if ( ! $sent ) {
			error_log( 'SLW: Failed to send RFQ admin notification for RFQ #' . $rfq_id );
		}
	}

	/**
	 * Send quote response email to the customer.
	 */
	private static function send_customer_quote_email( $rfq ) {
		$user = get_userdata( $rfq->user_id );
		if ( ! $user ) {
			return;
		}

		$first_name    = $user->first_name ?: $user->display_name;
		$products      = json_decode( $rfq->products, true );
		$business_name = SLW_Email_Settings::get_business_name();
		$owner         = SLW_Email_Settings::get( 'owner_name' );

		$subject = 'Your Quote Request #' . $rfq->id . ' — ' . $business_name . ' Wholesale';

		$body  = "Hi {$first_name},\n\n";
		$body .= "Great news! We've reviewed your quote request and here's your custom pricing:\n\n";

		if ( ! empty( $products ) ) {
			foreach ( $products as $item ) {
				$product = wc_get_product( $item['product_id'] );
				$pname   = $product ? $product->get_name() : 'Product #' . $item['product_id'];
				$body   .= "  - {$pname} x {$item['quantity']}";
				if ( isset( $item['quoted_price'] ) ) {
					$body .= " @ $" . number_format( (float) $item['quoted_price'], 2 );
				}
				$body .= "\n";
			}
		}

		if ( $rfq->total_quoted ) {
			$body .= "\nQuoted Total: $" . number_format( (float) $rfq->total_quoted, 2 ) . "\n";
		}

		if ( $rfq->admin_response ) {
			$msg_from = $owner ? "Message from {$owner}" : 'Message';
			$body .= "\n{$msg_from}:\n" . $rfq->admin_response . "\n";
		}

		$body .= "\nTo accept this quote, log in to your wholesale account and visit:\n";
		$body .= home_url( '/wholesale-rfq' ) . "\n\n";
		$body .= "Or simply reply to this email.\n\n";
		$body .= "Thank you,\n" . SLW_Email_Settings::get_signature();

		wp_mail( $user->user_email, $subject, $body, SLW_Email_Settings::get_headers() );
	}

	/* ---------------------------------------------------------------
	   Admin Menu
	   --------------------------------------------------------------- */

	/**
	 * Add "Quote Requests" as a sub-menu under "Wholesale Applications."
	 */
	public static function add_admin_menu() {
		add_submenu_page(
			'slw-applications',
			'Quote Requests',
			'Quote Requests',
			'manage_woocommerce',
			'slw-rfq',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Render the admin RFQ list or single detail view.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'slw_rfq';

		// Single RFQ view
		if ( isset( $_GET['rfq_id'] ) ) {
			self::render_single_rfq( $table );
			return;
		}

		// List view
		self::render_rfq_list( $table );
	}

	/**
	 * Render the RFQ list view.
	 */
	private static function render_rfq_list( $table ) {
		global $wpdb;

		$status_filter = sanitize_text_field( $_GET['status'] ?? 'all' );
		$where         = '';
		$valid_statuses = array( 'pending', 'quoted', 'accepted', 'declined' );

		if ( in_array( $status_filter, $valid_statuses, true ) ) {
			$where = $wpdb->prepare( "WHERE status = %s", $status_filter );
		}

		$rfqs = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY submitted_at DESC" );

		// Count by status
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status", OBJECT_K );
		$total  = array_sum( wp_list_pluck( $counts, 'count' ) );
		?>
		<div class="wrap">
			<h1>Quote Requests</h1>

			<!-- Summary Stats -->
			<div class="slw-admin-stats" style="margin-bottom:16px;">
				<div class="slw-admin-stats__card slw-admin-stats__card--gold" style="flex:1;min-width:120px;">
					<div style="padding:16px 20px;text-align:center;">
						<span style="display:block;font-size:28px;font-weight:700;color:#1E2A30;"><?php echo esc_html( $counts['pending']->count ?? 0 ); ?></span>
						<span style="font-size:12px;color:#628393;text-transform:uppercase;letter-spacing:0.5px;">Pending</span>
					</div>
				</div>
				<div class="slw-admin-stats__card slw-admin-stats__card--teal" style="flex:1;min-width:120px;">
					<div style="padding:16px 20px;text-align:center;">
						<span style="display:block;font-size:28px;font-weight:700;color:#1E2A30;"><?php echo esc_html( $counts['quoted']->count ?? 0 ); ?></span>
						<span style="font-size:12px;color:#628393;text-transform:uppercase;letter-spacing:0.5px;">Quoted</span>
					</div>
				</div>
				<div class="slw-admin-stats__card slw-admin-stats__card--green" style="flex:1;min-width:120px;">
					<div style="padding:16px 20px;text-align:center;">
						<span style="display:block;font-size:28px;font-weight:700;color:#1E2A30;"><?php echo esc_html( $counts['accepted']->count ?? 0 ); ?></span>
						<span style="font-size:12px;color:#628393;text-transform:uppercase;letter-spacing:0.5px;">Accepted</span>
					</div>
				</div>
				<div class="slw-admin-stats__card" style="flex:1;min-width:120px;">
					<div style="padding:16px 20px;text-align:center;">
						<span style="display:block;font-size:28px;font-weight:700;color:#1E2A30;"><?php echo esc_html( $total ); ?></span>
						<span style="font-size:12px;color:#628393;text-transform:uppercase;letter-spacing:0.5px;">Total</span>
					</div>
				</div>
			</div>

			<ul class="subsubsub">
				<li><a href="?page=slw-rfq&status=all" <?php echo $status_filter === 'all' ? 'class="current"' : ''; ?>>All (<?php echo esc_html( $total ); ?>)</a> |</li>
				<li><a href="?page=slw-rfq&status=pending" <?php echo $status_filter === 'pending' ? 'class="current"' : ''; ?>>Pending (<?php echo esc_html( $counts['pending']->count ?? 0 ); ?>)</a> |</li>
				<li><a href="?page=slw-rfq&status=quoted" <?php echo $status_filter === 'quoted' ? 'class="current"' : ''; ?>>Quoted (<?php echo esc_html( $counts['quoted']->count ?? 0 ); ?>)</a> |</li>
				<li><a href="?page=slw-rfq&status=accepted" <?php echo $status_filter === 'accepted' ? 'class="current"' : ''; ?>>Accepted (<?php echo esc_html( $counts['accepted']->count ?? 0 ); ?>)</a> |</li>
				<li><a href="?page=slw-rfq&status=declined" <?php echo $status_filter === 'declined' ? 'class="current"' : ''; ?>>Declined (<?php echo esc_html( $counts['declined']->count ?? 0 ); ?>)</a></li>
			</ul>

			<table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
				<thead>
					<tr>
						<th style="width:60px;">ID</th>
						<th>Customer</th>
						<th>Business</th>
						<th style="width:80px;white-space:nowrap;">Items</th>
						<th style="width:100px;">Status</th>
						<th style="width:120px;">Date</th>
						<th style="width:100px;">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rfqs ) ) : ?>
						<tr><td colspan="7">No quote requests found.</td></tr>
					<?php else : ?>
						<?php foreach ( $rfqs as $rfq ) :
							$user     = get_userdata( $rfq->user_id );
							$name     = $user ? ( $user->first_name . ' ' . $user->last_name ) : 'Unknown';
							$business = $user ? get_user_meta( $rfq->user_id, 'slw_business_name', true ) : '';
							$products = json_decode( $rfq->products, true );
							$num      = is_array( $products ) ? count( $products ) : 0;
						?>
						<tr>
							<td><?php echo esc_html( $rfq->id ); ?></td>
							<td><a href="?page=slw-rfq&rfq_id=<?php echo esc_attr( $rfq->id ); ?>"><?php echo esc_html( trim( $name ) ); ?></a></td>
							<td><?php echo esc_html( $business ); ?></td>
							<td><?php echo esc_html( $num ); ?></td>
							<td><span class="slw-status-<?php echo esc_attr( $rfq->status ); ?>"><?php echo esc_html( ucfirst( $rfq->status ) ); ?></span></td>
							<td><?php echo esc_html( date( 'M j, Y', strtotime( $rfq->submitted_at ) ) ); ?></td>
							<td>
								<a href="?page=slw-rfq&rfq_id=<?php echo esc_attr( $rfq->id ); ?>">View</a>
							</td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<style>
			.slw-status-pending  { color: #996800; font-weight: bold; }
			.slw-status-quoted   { color: #386174; font-weight: bold; }
			.slw-status-accepted { color: #007017; font-weight: bold; }
			.slw-status-declined { color: #8b0000; font-weight: bold; }
		</style>
		<?php
	}

	/**
	 * Render a single RFQ detail view with admin actions.
	 */
	private static function render_single_rfq( $table ) {
		global $wpdb;

		$rfq_id = absint( $_GET['rfq_id'] );
		$rfq    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $rfq_id ) );

		if ( ! $rfq ) {
			echo '<div class="wrap"><p>Quote request not found.</p></div>';
			return;
		}

		$user     = get_userdata( $rfq->user_id );
		$name     = $user ? ( $user->first_name . ' ' . $user->last_name ) : 'Unknown';
		$email    = $user ? $user->user_email : '';
		$business = $user ? get_user_meta( $rfq->user_id, 'slw_business_name', true ) : '';
		$products = json_decode( $rfq->products, true );
		$nonce    = wp_create_nonce( 'slw_rfq_admin_action' );
		?>
		<div class="wrap">
			<h1>Quote Request #<?php echo esc_html( $rfq->id ); ?></h1>

			<table class="form-table">
				<tr><th>Status</th><td><span class="slw-status-<?php echo esc_attr( $rfq->status ); ?>" style="font-size:14px;"><?php echo esc_html( ucfirst( $rfq->status ) ); ?></span></td></tr>
				<tr><th>Customer</th><td><?php echo esc_html( trim( $name ) ); ?></td></tr>
				<tr><th>Business</th><td><?php echo esc_html( $business ); ?></td></tr>
				<tr><th>Email</th><td><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></td></tr>
				<tr><th>Submitted</th><td><?php echo esc_html( $rfq->submitted_at ); ?></td></tr>
				<?php if ( $rfq->delivery_date ) : ?>
				<tr><th>Requested Delivery</th><td><?php echo esc_html( date( 'M j, Y', strtotime( $rfq->delivery_date ) ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( $rfq->additional_notes ) : ?>
				<tr><th>Customer Notes</th><td><?php echo nl2br( esc_html( $rfq->additional_notes ) ); ?></td></tr>
				<?php endif; ?>
			</table>

			<h2>Products Requested</h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Product</th>
						<th style="width:80px;">SKU</th>
						<th style="width:80px;">Qty</th>
						<th style="width:120px;">Wholesale Price</th>
						<th>Notes</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $products ) ) : ?>
						<?php foreach ( $products as $item ) :
							$product = wc_get_product( $item['product_id'] );
							$pname   = $product ? $product->get_name() : 'Product #' . $item['product_id'];
							$sku     = $product ? $product->get_sku() : '';
							$price   = $product ? wc_price( $product->get_price() ) : 'N/A';
						?>
						<tr>
							<td><?php echo esc_html( $pname ); ?></td>
							<td><?php echo esc_html( $sku ); ?></td>
							<td><?php echo esc_html( $item['quantity'] ); ?></td>
							<td><?php echo $price; ?></td>
							<td><?php echo esc_html( $item['notes'] ?? '' ); ?></td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $rfq->responded_at ) : ?>
				<h2>Admin Response</h2>
				<table class="form-table">
					<?php if ( $rfq->total_quoted ) : ?>
					<tr><th>Quoted Total</th><td><strong>$<?php echo esc_html( number_format( (float) $rfq->total_quoted, 2 ) ); ?></strong></td></tr>
					<?php endif; ?>
					<?php if ( $rfq->admin_response ) : ?>
					<tr><th>Response Message</th><td><?php echo nl2br( esc_html( $rfq->admin_response ) ); ?></td></tr>
					<?php endif; ?>
					<tr><th>Responded</th><td><?php echo esc_html( $rfq->responded_at ); ?></td></tr>
				</table>
			<?php endif; ?>

			<?php if ( $rfq->status === 'pending' || $rfq->status === 'quoted' ) : ?>
				<hr />
				<h2><?php echo $rfq->status === 'pending' ? 'Send Quote' : 'Update Quote'; ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=slw-rfq' ) ); ?>">
					<input type="hidden" name="slw_rfq_action" value="send_quote" />
					<input type="hidden" name="rfq_id" value="<?php echo esc_attr( $rfq->id ); ?>" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />

					<table class="form-table">
						<tr>
							<th><label for="slw_total_quoted">Quoted Total ($)</label></th>
							<td>
								<input type="number" name="total_quoted" id="slw_total_quoted" step="0.01" min="0" class="regular-text"
									value="<?php echo esc_attr( $rfq->total_quoted ?: '' ); ?>" />
								<p class="description">Optional. Enter a custom total for this quote.</p>
							</td>
						</tr>
						<tr>
							<th><label for="slw_admin_response">Response Message</label></th>
							<td>
								<textarea name="admin_response" id="slw_admin_response" rows="5" class="large-text"><?php echo esc_textarea( $rfq->admin_response ?: '' ); ?></textarea>
								<p class="description">This message will be included in the email to the customer.</p>
							</td>
						</tr>
					</table>

					<p>
						<button type="submit" name="quote_action" value="quoted" class="button button-primary">Send Quote to Customer</button>
						<?php if ( $rfq->status === 'quoted' ) : ?>
							<button type="submit" name="quote_action" value="accepted" class="button" style="margin-left:8px;">Mark as Accepted</button>
						<?php endif; ?>
						<button type="submit" name="quote_action" value="declined" class="button" style="margin-left:8px;">Decline</button>
					</p>
				</form>
			<?php endif; ?>

			<?php if ( $rfq->status === 'quoted' ) : ?>
				<hr />
				<p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=slw-rfq&slw_rfq_action=accept&rfq_id=' . $rfq->id ), 'slw_rfq_admin_action' ) ); ?>"
					   class="button button-primary" onclick="return confirm('Mark as Accepted? This will create a WooCommerce order with the quoted prices.');">
						Accept &amp; Create Order
					</a>
				</p>
			<?php endif; ?>

			<p style="margin-top:20px;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=slw-rfq' ) ); ?>">&larr; Back to all quote requests</a></p>
		</div>

		<style>
			.slw-status-pending  { color: #996800; font-weight: bold; }
			.slw-status-quoted   { color: #386174; font-weight: bold; }
			.slw-status-accepted { color: #007017; font-weight: bold; }
			.slw-status-declined { color: #8b0000; font-weight: bold; }
		</style>
		<?php
	}

	/* ---------------------------------------------------------------
	   Admin Actions
	   --------------------------------------------------------------- */

	/**
	 * Handle admin actions from form POST or GET links.
	 */
	public static function handle_admin_action() {
		// Handle form-based POST actions (Send Quote, Accept, Decline)
		if ( isset( $_POST['slw_rfq_action'] ) && $_POST['slw_rfq_action'] === 'send_quote' ) {
			if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'slw_rfq_admin_action' ) ) {
				return;
			}
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$rfq_id       = absint( $_POST['rfq_id'] ?? 0 );
			$quote_action = sanitize_text_field( $_POST['quote_action'] ?? '' );

			if ( ! $rfq_id || ! in_array( $quote_action, array( 'quoted', 'accepted', 'declined' ), true ) ) {
				return;
			}

			global $wpdb;
			$table = $wpdb->prefix . 'slw_rfq';
			$rfq   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $rfq_id ) );

			if ( ! $rfq ) {
				return;
			}

			$total_quoted   = ! empty( $_POST['total_quoted'] ) ? floatval( $_POST['total_quoted'] ) : null;
			$admin_response = sanitize_textarea_field( $_POST['admin_response'] ?? '' );

			$update_data = array(
				'status'         => $quote_action,
				'admin_response' => $admin_response,
				'total_quoted'   => $total_quoted,
				'responded_at'   => current_time( 'mysql' ),
				'responded_by'   => get_current_user_id(),
			);

			$wpdb->update( $table, $update_data, array( 'id' => $rfq_id ) );

			// Refresh the RFQ object with updated data
			$rfq = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $rfq_id ) );

			// Send email to customer when quoting
			if ( $quote_action === 'quoted' ) {
				self::send_customer_quote_email( $rfq );
			}

			// Create WC order if accepted
			if ( $quote_action === 'accepted' && $rfq ) {
				self::create_order_from_rfq( $rfq );
			}

			wp_redirect( admin_url( 'admin.php?page=slw-rfq&rfq_id=' . $rfq_id . '&updated=1' ) );
			exit;
		}

		// Handle GET-based accept action (from the "Accept & Create Order" link)
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'slw-rfq' && isset( $_GET['slw_rfq_action'] ) && $_GET['slw_rfq_action'] === 'accept' ) {
			if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'slw_rfq_admin_action' ) ) {
				return;
			}
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$rfq_id = absint( $_GET['rfq_id'] ?? 0 );
			if ( ! $rfq_id ) {
				return;
			}

			global $wpdb;
			$table = $wpdb->prefix . 'slw_rfq';
			$rfq   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $rfq_id ) );

			if ( ! $rfq || $rfq->status !== 'quoted' ) {
				return;
			}

			$wpdb->update( $table, array(
				'status'       => 'accepted',
				'responded_at' => current_time( 'mysql' ),
				'responded_by' => get_current_user_id(),
			), array( 'id' => $rfq_id ) );

			// Refresh
			$rfq = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $rfq_id ) );
			self::create_order_from_rfq( $rfq );

			wp_redirect( admin_url( 'admin.php?page=slw-rfq&rfq_id=' . $rfq_id . '&updated=1' ) );
			exit;
		}
	}

	/**
	 * Create a WooCommerce order from an accepted RFQ.
	 *
	 * @param object $rfq The RFQ database row.
	 */
	private static function create_order_from_rfq( $rfq ) {
		$user = get_userdata( $rfq->user_id );
		if ( ! $user ) {
			return;
		}

		$products = json_decode( $rfq->products, true );
		if ( empty( $products ) ) {
			return;
		}

		$order = wc_create_order( array(
			'customer_id' => $rfq->user_id,
			'status'      => 'on-hold',
		) );

		if ( is_wp_error( $order ) ) {
			error_log( 'SLW: Could not create order from RFQ #' . $rfq->id . ': ' . $order->get_error_message() );
			return;
		}

		foreach ( $products as $item ) {
			$product = wc_get_product( $item['product_id'] );
			if ( ! $product ) {
				continue;
			}

			// Use quoted price if available, otherwise use the product's current price
			$price = isset( $item['quoted_price'] ) ? floatval( $item['quoted_price'] ) : $product->get_price();

			$item_id = $order->add_product( $product, $item['quantity'] );

			// Override line item price if a custom quoted price was set
			if ( isset( $item['quoted_price'] ) && $item_id ) {
				$line_item = $order->get_item( $item_id );
				$line_item->set_subtotal( $price * $item['quantity'] );
				$line_item->set_total( $price * $item['quantity'] );
				$line_item->save();
			}
		}

		// Set billing address from user meta
		$order->set_billing_first_name( $user->first_name );
		$order->set_billing_last_name( $user->last_name );
		$order->set_billing_email( $user->user_email );
		$order->set_billing_company( get_user_meta( $rfq->user_id, 'slw_business_name', true ) );

		// Add order note referencing the RFQ
		$order->add_order_note( sprintf( 'Created from Quote Request #%d', $rfq->id ) );

		$order->calculate_totals();
		$order->save();
	}

	/* ---------------------------------------------------------------
	   Page Auto-Creation
	   --------------------------------------------------------------- */

	/**
	 * Create the /wholesale-rfq page with the shortcode if it doesn't exist.
	 * Callable from the plugin's activation hook.
	 */
	public static function ensure_rfq_page() {
		$page = get_page_by_path( 'wholesale-rfq' );
		if ( $page ) {
			return;
		}

		wp_insert_post( array(
			'post_title'   => 'Request a Quote',
			'post_name'    => 'wholesale-rfq',
			'post_content' => '[sego_wholesale_rfq]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => 1,
		) );
	}
}
