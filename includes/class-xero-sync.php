<?php
/**
 * Native Xero integration.
 *
 * Connects the store to Xero directly (no paid third-party connector) so a
 * wholesale order's invoice posts to Xero automatically, and a payment is
 * applied to that Xero invoice when the WooCommerce order is paid. Built to
 * hand off with the plugin: one Xero app (LP-registered), the client authorizes
 * their own org, tokens are stored encrypted.
 *
 * Flow:
 *   1. Admin pastes the Xero app's Client ID + Secret (Wholesale > Xero).
 *   2. "Connect to Xero" -> OAuth2 consent -> callback stores tokens + tenant.
 *   3. On order processing/completed: find/create the Xero Contact, create (or
 *      update) an ACCREC invoice from the order. Store the Xero InvoiceID.
 *   4. On the order being paid (date_paid set): post a Payment against that
 *      invoice so it shows paid in Xero.
 *
 * Access tokens live 30 min and are refreshed automatically; the refresh token
 * rotates on each use and is valid 60 days. Everything no-ops cleanly when not
 * connected, so it never blocks order processing.
 *
 * @see XERO-INTEGRATION-PLAN.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLW_Xero_Sync {

	const AUTHORIZE_URL   = 'https://login.xero.com/identity/connect/authorize';
	const TOKEN_URL       = 'https://identity.xero.com/connect/token';
	const CONNECTIONS_URL = 'https://api.xero.com/connections';
	const API_BASE        = 'https://api.xero.com/api.xro/2.0/';
	const SCOPES          = 'openid profile email accounting.transactions accounting.contacts offline_access';

	const OPT_CLIENT_ID     = 'slw_xero_client_id';
	const OPT_CLIENT_SECRET = 'slw_xero_client_secret'; // encrypted
	const OPT_TOKENS        = 'slw_xero_tokens';         // encrypted JSON
	const OPT_CONFIG        = 'slw_xero_config';

	public static function init() {
		add_action( 'admin_post_slw_xero_connect',    array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_slw_xero_callback',   array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_slw_xero_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_slw_xero_settings',   array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_slw_xero_sync_order', array( __CLASS__, 'handle_manual_sync' ) );

		// Sync invoice when the order reaches a real (non-draft) state.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_sync_invoice' ), 30 );
		add_action( 'woocommerce_order_status_completed',  array( __CLASS__, 'maybe_sync_invoice' ), 30 );

		// Apply the payment in Xero once the order is actually paid.
		add_action( 'woocommerce_payment_complete',      array( __CLASS__, 'maybe_sync_payment' ), 30 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'maybe_sync_payment' ), 40 );
	}

	/* =====================================================================
	   Config
	   ===================================================================== */

	public static function get_client_id() {
		if ( defined( 'SLW_XERO_CLIENT_ID' ) && SLW_XERO_CLIENT_ID ) {
			return SLW_XERO_CLIENT_ID;
		}
		return (string) get_option( self::OPT_CLIENT_ID, '' );
	}

	public static function get_client_secret() {
		if ( defined( 'SLW_XERO_CLIENT_SECRET' ) && SLW_XERO_CLIENT_SECRET ) {
			return SLW_XERO_CLIENT_SECRET;
		}
		$stored = get_option( self::OPT_CLIENT_SECRET, '' );
		return $stored ? SLW_Encryption::decrypt( $stored ) : '';
	}

	/** Redirect URI registered in the Xero app. */
	public static function get_redirect_uri() {
		return admin_url( 'admin-post.php?action=slw_xero_callback' );
	}

	public static function is_configured() {
		return self::get_client_id() && self::get_client_secret();
	}

	public static function is_connected() {
		$tokens = self::get_tokens();
		return ! empty( $tokens['refresh_token'] ) && ! empty( $tokens['tenant_id'] );
	}

	public static function get_config() {
		$defaults = array(
			'sales_account'   => '200',   // Xero: Sales
			'payment_account' => '090',   // Xero: a bank/clearing account code
			'invoice_status'  => 'AUTHORISED', // or DRAFT
			'line_amount_type'=> 'NoTax', // Exclusive | Inclusive | NoTax
			'auto_invoice'    => 1,
			'auto_payment'    => 1,
		);
		return wp_parse_args( get_option( self::OPT_CONFIG, array() ), $defaults );
	}

	/* =====================================================================
	   Token store
	   ===================================================================== */

	private static function get_tokens() {
		$raw = get_option( self::OPT_TOKENS, '' );
		if ( ! $raw ) {
			return array();
		}
		$json = SLW_Encryption::decrypt( $raw );
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	private static function save_tokens( array $tokens ) {
		update_option( self::OPT_TOKENS, SLW_Encryption::encrypt( wp_json_encode( $tokens ) ), false );
	}

	private static function clear_tokens() {
		delete_option( self::OPT_TOKENS );
	}

	/**
	 * Return a valid access token, refreshing if it's expired/near expiry.
	 * Returns '' if not connected or the refresh fails.
	 */
	private static function get_access_token() {
		$tokens = self::get_tokens();
		if ( empty( $tokens['refresh_token'] ) ) {
			return '';
		}
		// 60s safety margin.
		if ( ! empty( $tokens['access_token'] ) && ! empty( $tokens['expires_at'] ) && time() < ( (int) $tokens['expires_at'] - 60 ) ) {
			return $tokens['access_token'];
		}
		return self::refresh_access_token( $tokens );
	}

	private static function refresh_access_token( array $tokens ) {
		$response = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( self::get_client_id() . ':' . self::get_client_secret() ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $tokens['refresh_token'],
			),
		) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			self::log( 'Token refresh failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ) ) );
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			return '';
		}

		$tokens['access_token']  = $data['access_token'];
		$tokens['refresh_token'] = $data['refresh_token'] ?? $tokens['refresh_token']; // rotates
		$tokens['expires_at']    = time() + (int) ( $data['expires_in'] ?? 1800 );
		self::save_tokens( $tokens );

		return $tokens['access_token'];
	}

	/* =====================================================================
	   OAuth flow
	   ===================================================================== */

	public static function handle_connect() {
		self::guard( 'slw_xero_connect' );
		if ( ! self::is_configured() ) {
			self::redirect_settings( 'error', 'Add your Xero Client ID and Secret first.' );
		}

		$state = wp_create_nonce( 'slw_xero_state' );
		set_transient( 'slw_xero_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );

		$url = add_query_arg( array(
			'response_type' => 'code',
			'client_id'     => self::get_client_id(),
			'redirect_uri'  => self::get_redirect_uri(),
			'scope'         => self::SCOPES,
			'state'         => $state,
		), self::AUTHORIZE_URL );

		wp_redirect( $url );
		exit;
	}

	public static function handle_callback() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Permission denied.' );
		}

		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$expected = get_transient( 'slw_xero_state_' . get_current_user_id() );
		if ( ! $state || $state !== $expected ) {
			self::redirect_settings( 'error', 'Xero connection state mismatch. Please try again.' );
		}
		delete_transient( 'slw_xero_state_' . get_current_user_id() );

		if ( ! empty( $_GET['error'] ) ) {
			self::redirect_settings( 'error', 'Xero returned: ' . sanitize_text_field( wp_unslash( $_GET['error'] ) ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( ! $code ) {
			self::redirect_settings( 'error', 'No authorization code returned from Xero.' );
		}

		// Exchange code for tokens.
		$response = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( self::get_client_id() . ':' . self::get_client_secret() ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => self::get_redirect_uri(),
			),
		) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			self::redirect_settings( 'error', 'Token exchange failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ) ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			self::redirect_settings( 'error', 'Xero did not return an access token.' );
		}

		$tokens = array(
			'access_token'  => $data['access_token'],
			'refresh_token' => $data['refresh_token'] ?? '',
			'expires_at'    => time() + (int) ( $data['expires_in'] ?? 1800 ),
		);

		// Resolve the tenant (org) this token can access.
		$conn = wp_remote_get( self::CONNECTIONS_URL, array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $tokens['access_token'],
				'Accept'        => 'application/json',
			),
		) );
		if ( ! is_wp_error( $conn ) && wp_remote_retrieve_response_code( $conn ) === 200 ) {
			$connections = json_decode( wp_remote_retrieve_body( $conn ), true );
			if ( ! empty( $connections[0]['tenantId'] ) ) {
				$tokens['tenant_id']   = $connections[0]['tenantId'];
				$tokens['tenant_name'] = $connections[0]['tenantName'] ?? '';
			}
		}

		if ( empty( $tokens['tenant_id'] ) ) {
			self::redirect_settings( 'error', 'Connected, but no Xero organization was returned. Try reconnecting.' );
		}

		self::save_tokens( $tokens );
		self::redirect_settings( 'success', 'Connected to ' . ( $tokens['tenant_name'] ?: 'Xero' ) . '.' );
	}

	public static function handle_disconnect() {
		self::guard( 'slw_xero_disconnect' );
		self::clear_tokens();
		self::redirect_settings( 'success', 'Disconnected from Xero.' );
	}

	/* =====================================================================
	   API client
	   ===================================================================== */

	/**
	 * @return array|WP_Error Decoded JSON body, or WP_Error on failure.
	 */
	private static function api( $method, $endpoint, $body = null ) {
		$token = self::get_access_token();
		if ( ! $token ) {
			return new WP_Error( 'slw_xero_no_token', 'Not connected to Xero.' );
		}
		$tokens = self::get_tokens();

		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $token,
				'Xero-tenant-id' => $tokens['tenant_id'],
				'Accept'         => 'application/json',
				'Content-Type'   => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url      = 0 === strpos( $endpoint, 'http' ) ? $endpoint : self::API_BASE . ltrim( $endpoint, '/' );
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );

		// One retry on rate limit.
		if ( 429 === $code ) {
			sleep( 2 );
			$response = wp_remote_request( $url, $args );
			$code     = wp_remote_retrieve_response_code( $response );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'slw_xero_api', 'Xero API ' . $code . ': ' . wp_remote_retrieve_body( $response ), $data );
		}
		return is_array( $data ) ? $data : array();
	}

	/* =====================================================================
	   Sync: invoice
	   ===================================================================== */

	public static function maybe_sync_invoice( $order_id ) {
		$cfg = self::get_config();
		if ( empty( $cfg['auto_invoice'] ) || ! self::is_connected() ) {
			return;
		}
		self::sync_invoice( $order_id );
	}

	/**
	 * Create or update the Xero invoice for an order. Idempotent: stores the
	 * Xero InvoiceID on the order and updates that invoice on re-sync.
	 *
	 * @return string|WP_Error Xero InvoiceID on success.
	 */
	public static function sync_invoice( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'slw_xero_no_order', 'Order not found.' );
		}
		$cfg = self::get_config();

		$contact = self::find_or_create_contact( $order );
		if ( is_wp_error( $contact ) ) {
			self::note( $order, 'Xero: contact sync failed, ' . $contact->get_error_message() );
			return $contact;
		}

		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			$qty  = (float) $item->get_quantity();
			$unit = $qty > 0 ? round( (float) $item->get_total() / $qty, 4 ) : (float) $item->get_total();
			$line_items[] = array(
				'Description' => $item->get_name(),
				'Quantity'    => $qty,
				'UnitAmount'  => $unit,
				'AccountCode' => $cfg['sales_account'],
			);
		}
		$ship = (float) $order->get_shipping_total();
		if ( $ship > 0 ) {
			$line_items[] = array(
				'Description' => 'Shipping',
				'Quantity'    => 1,
				'UnitAmount'  => $ship,
				'AccountCode' => $cfg['sales_account'],
			);
		}

		$due = $order->get_meta( '_slw_net30_due_date' );
		$invoice = array(
			'Type'            => 'ACCREC',
			'Contact'         => array( 'ContactID' => $contact ),
			'LineItems'       => $line_items,
			'Date'            => gmdate( 'Y-m-d' ),
			'Reference'       => 'WC #' . $order->get_order_number(),
			'Status'          => $cfg['invoice_status'],
			'LineAmountTypes' => $cfg['line_amount_type'],
		);
		if ( $due ) {
			$invoice['DueDate'] = gmdate( 'Y-m-d', strtotime( $due ) );
		}

		$existing_id = $order->get_meta( '_slw_xero_invoice_id' );
		if ( $existing_id ) {
			$invoice['InvoiceID'] = $existing_id;
		}

		$result = self::api( 'POST', 'Invoices', array( 'Invoices' => array( $invoice ) ) );
		if ( is_wp_error( $result ) ) {
			self::note( $order, 'Xero: invoice sync failed, ' . $result->get_error_message() );
			return $result;
		}

		$invoice_id = $result['Invoices'][0]['InvoiceID'] ?? '';
		if ( $invoice_id ) {
			$order->update_meta_data( '_slw_xero_invoice_id', $invoice_id );
			$order->save();
			self::note( $order, sprintf( 'Xero: invoice %s %s.', $result['Invoices'][0]['InvoiceNumber'] ?? '', $existing_id ? 'updated' : 'created' ) );
		}
		return $invoice_id;
	}

	/**
	 * @return string|WP_Error Xero ContactID.
	 */
	private static function find_or_create_contact( $order ) {
		$email = $order->get_billing_email();
		$name  = $order->get_billing_company() ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( ! $name ) {
			$name = 'Wholesale Customer';
		}

		if ( $email ) {
			$where  = 'EmailAddress=="' . str_replace( '"', '', $email ) . '"';
			$lookup = self::api( 'GET', 'Contacts?where=' . rawurlencode( $where ) );
			if ( ! is_wp_error( $lookup ) && ! empty( $lookup['Contacts'][0]['ContactID'] ) ) {
				return $lookup['Contacts'][0]['ContactID'];
			}
		}

		$contact = array(
			'Name'         => $name,
			'FirstName'    => $order->get_billing_first_name(),
			'LastName'     => $order->get_billing_last_name(),
			'EmailAddress' => $email,
		);
		$created = self::api( 'POST', 'Contacts', array( 'Contacts' => array( $contact ) ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		return $created['Contacts'][0]['ContactID'] ?? new WP_Error( 'slw_xero_contact', 'Could not create Xero contact.' );
	}

	/* =====================================================================
	   Sync: payment
	   ===================================================================== */

	public static function maybe_sync_payment( $order_id ) {
		$cfg = self::get_config();
		if ( empty( $cfg['auto_payment'] ) || ! self::is_connected() ) {
			return;
		}
		self::sync_payment( $order_id );
	}

	/**
	 * Apply a payment against the order's Xero invoice once the order is paid.
	 */
	public static function sync_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_date_paid() ) {
			return;
		}
		if ( $order->get_meta( '_slw_xero_payment_id' ) ) {
			return; // already applied
		}
		$cfg = self::get_config();

		$invoice_id = $order->get_meta( '_slw_xero_invoice_id' );
		if ( ! $invoice_id ) {
			$invoice_id = self::sync_invoice( $order_id ); // ensure invoice exists first
			if ( is_wp_error( $invoice_id ) || ! $invoice_id ) {
				return;
			}
		}

		$payment = array(
			'Invoice' => array( 'InvoiceID' => $invoice_id ),
			'Account' => array( 'Code' => $cfg['payment_account'] ),
			'Date'    => gmdate( 'Y-m-d', $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time() ),
			'Amount'  => (float) $order->get_total(),
		);

		$result = self::api( 'POST', 'Payments', array( 'Payments' => array( $payment ) ) );
		if ( is_wp_error( $result ) ) {
			self::note( $order, 'Xero: payment sync failed, ' . $result->get_error_message() );
			return;
		}
		$payment_id = $result['Payments'][0]['PaymentID'] ?? '';
		if ( $payment_id ) {
			$order->update_meta_data( '_slw_xero_payment_id', $payment_id );
			$order->save();
			self::note( $order, 'Xero: payment applied, invoice marked paid.' );
		}
	}

	public static function handle_manual_sync() {
		$order_id = absint( $_REQUEST['order_id'] ?? 0 );
		self::guard( 'slw_xero_sync_order_' . $order_id );
		$res = self::sync_invoice( $order_id );
		self::sync_payment( $order_id );
		$url = wc_get_order( $order_id ) ? wc_get_order( $order_id )->get_edit_order_url() : admin_url();
		wp_safe_redirect( add_query_arg( 'slw_xero_synced', is_wp_error( $res ) ? 0 : 1, $url ) );
		exit;
	}

	/* =====================================================================
	   Settings page (Wholesale > Xero)
	   ===================================================================== */

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Permission denied.' );
		}
		$cfg       = self::get_config();
		$connected = self::is_connected();
		$tokens    = self::get_tokens();
		$post_url  = esc_url( admin_url( 'admin-post.php' ) );

		if ( isset( $_GET['slw_xero_msg'] ) ) {
			$type = ( ( $_GET['slw_xero_type'] ?? '' ) === 'error' ) ? 'error' : 'success';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['slw_xero_msg'] ) ) ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1>Xero</h1>
			<p style="max-width:760px;color:#3a4a52;">Post wholesale invoices to Xero automatically, and mark them paid there when the order is paid. No monthly connector fee.</p>

			<h2>Connection</h2>
			<?php if ( $connected ) : ?>
				<p>Connected to <strong><?php echo esc_html( $tokens['tenant_name'] ?: 'your Xero organization' ); ?></strong>.</p>
				<form method="post" action="<?php echo $post_url; ?>" style="display:inline;">
					<?php wp_nonce_field( 'slw_xero_disconnect' ); ?>
					<input type="hidden" name="action" value="slw_xero_disconnect">
					<button class="button">Disconnect</button>
				</form>
			<?php elseif ( self::is_configured() ) : ?>
				<form method="post" action="<?php echo $post_url; ?>" style="display:inline;">
					<?php wp_nonce_field( 'slw_xero_connect' ); ?>
					<input type="hidden" name="action" value="slw_xero_connect">
					<button class="button button-primary">Connect to Xero</button>
				</form>
			<?php else : ?>
				<p style="color:#b45309;">Add your Xero app's Client ID and Secret below, then Save, and the Connect button appears.</p>
			<?php endif; ?>

			<h2 style="margin-top:24px;">Settings</h2>
			<form method="post" action="<?php echo $post_url; ?>">
				<?php wp_nonce_field( 'slw_xero_settings' ); ?>
				<input type="hidden" name="action" value="slw_xero_settings">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="x-cid">Client ID</label></th>
						<td><input type="text" id="x-cid" name="client_id" value="<?php echo esc_attr( self::get_client_id() ); ?>" class="regular-text" <?php echo defined( 'SLW_XERO_CLIENT_ID' ) ? 'disabled' : ''; ?>>
						<?php if ( defined( 'SLW_XERO_CLIENT_ID' ) ) : ?><p class="description">Set in wp-config.</p><?php endif; ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="x-sec">Client Secret</label></th>
						<td><input type="password" id="x-sec" name="client_secret" value="" placeholder="<?php echo self::get_client_secret() ? '••••••••(saved)' : ''; ?>" class="regular-text" <?php echo defined( 'SLW_XERO_CLIENT_SECRET' ) ? 'disabled' : ''; ?>>
						<p class="description">Redirect URI to register in the Xero app: <code><?php echo esc_html( self::get_redirect_uri() ); ?></code></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="x-sales">Sales account code</label></th>
						<td><input type="text" id="x-sales" name="sales_account" value="<?php echo esc_attr( $cfg['sales_account'] ); ?>" class="small-text"> <span class="description">Xero revenue account (e.g. 200).</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="x-pay">Payment account code</label></th>
						<td><input type="text" id="x-pay" name="payment_account" value="<?php echo esc_attr( $cfg['payment_account'] ); ?>" class="small-text"> <span class="description">Bank/clearing account payments post to (e.g. 090).</span></td>
					</tr>
					<tr>
						<th scope="row">Invoice status</th>
						<td>
							<label><input type="radio" name="invoice_status" value="AUTHORISED" <?php checked( $cfg['invoice_status'], 'AUTHORISED' ); ?>> Authorised (ready to send)</label><br>
							<label><input type="radio" name="invoice_status" value="DRAFT" <?php checked( $cfg['invoice_status'], 'DRAFT' ); ?>> Draft</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Tax</th>
						<td>
							<select name="line_amount_type">
								<?php foreach ( array( 'NoTax' => 'No tax', 'Exclusive' => 'Tax exclusive', 'Inclusive' => 'Tax inclusive' ) as $k => $v ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cfg['line_amount_type'], $k ); ?>><?php echo esc_html( $v ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Automation</th>
						<td>
							<label><input type="checkbox" name="auto_invoice" value="1" <?php checked( $cfg['auto_invoice'], 1 ); ?>> Create the Xero invoice automatically when an order is processing/completed</label><br>
							<label><input type="checkbox" name="auto_payment" value="1" <?php checked( $cfg['auto_payment'], 1 ); ?>> Apply the payment in Xero automatically when the order is paid</label>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_save_settings() {
		self::guard( 'slw_xero_settings' );

		if ( ! defined( 'SLW_XERO_CLIENT_ID' ) ) {
			update_option( self::OPT_CLIENT_ID, sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) ) );
		}
		if ( ! defined( 'SLW_XERO_CLIENT_SECRET' ) ) {
			$secret = trim( (string) wp_unslash( $_POST['client_secret'] ?? '' ) );
			if ( $secret !== '' ) { // only overwrite when a new value is typed
				update_option( self::OPT_CLIENT_SECRET, SLW_Encryption::encrypt( $secret ) );
			}
		}

		$cfg = self::get_config();
		$cfg['sales_account']    = sanitize_text_field( wp_unslash( $_POST['sales_account'] ?? $cfg['sales_account'] ) );
		$cfg['payment_account']  = sanitize_text_field( wp_unslash( $_POST['payment_account'] ?? $cfg['payment_account'] ) );
		$cfg['invoice_status']   = in_array( $_POST['invoice_status'] ?? '', array( 'AUTHORISED', 'DRAFT' ), true ) ? $_POST['invoice_status'] : $cfg['invoice_status'];
		$cfg['line_amount_type'] = in_array( $_POST['line_amount_type'] ?? '', array( 'NoTax', 'Exclusive', 'Inclusive' ), true ) ? $_POST['line_amount_type'] : $cfg['line_amount_type'];
		$cfg['auto_invoice']     = empty( $_POST['auto_invoice'] ) ? 0 : 1;
		$cfg['auto_payment']     = empty( $_POST['auto_payment'] ) ? 0 : 1;
		update_option( self::OPT_CONFIG, $cfg );

		self::redirect_settings( 'success', 'Settings saved.' );
	}

	/* =====================================================================
	   Helpers
	   ===================================================================== */

	private static function guard( $nonce_action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Permission denied.' );
		}
		check_admin_referer( $nonce_action );
	}

	private static function redirect_settings( $type, $msg ) {
		wp_safe_redirect( add_query_arg( array(
			'page'          => 'slw-xero',
			'slw_xero_type' => $type,
			'slw_xero_msg'  => rawurlencode( $msg ),
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function note( $order, $msg ) {
		if ( $order instanceof WC_Order ) {
			$order->add_order_note( $msg );
		}
		self::log( $msg );
	}

	private static function log( $msg ) {
		if ( class_exists( 'SLW_Audit_Log' ) && method_exists( 'SLW_Audit_Log', 'log' ) ) {
			SLW_Audit_Log::log( 'xero_sync', $msg );
		}
	}
}
