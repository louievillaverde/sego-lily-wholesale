<?php
/**
 * Email Settings — White-Label Configuration
 *
 * Provides configurable email sender info, reply-to, owner name, and
 * signature so the plugin is fully white-label. All emails sent by the
 * plugin route through the static helpers here instead of hardcoding
 * brand-specific values.
 *
 * Settings are stored as individual WP options with slw_email_ prefix.
 * Sensible defaults (site name, admin email) ensure the plugin works
 * out of the box without any configuration.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Email_Settings {

	/** All option keys with their defaults (evaluated lazily). */
	private static $option_keys = array(
		'slw_email_from_name',
		'slw_email_from_address',
		'slw_email_reply_to',
		'slw_email_owner_name',
		'slw_email_signature',
	);

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Get a single email setting with its default.
	 *
	 * @param string $key Short key (e.g. 'from_name') or full key (e.g. 'slw_email_from_name').
	 * @return string
	 */
	public static function get( $key ) {
		$full_key = strpos( $key, 'slw_email_' ) === 0 ? $key : 'slw_email_' . $key;
		$value    = get_option( $full_key );

		if ( $value !== false && $value !== '' ) {
			return $value;
		}

		// Return sensible defaults
		switch ( $full_key ) {
			case 'slw_email_from_name':
				return get_bloginfo( 'name' );
			case 'slw_email_from_address':
				return get_option( 'admin_email' );
			case 'slw_email_reply_to':
				return self::get( 'from_address' );
			case 'slw_email_owner_name':
				return '';
			case 'slw_email_signature':
				return ''; // Built dynamically in get_signature()
			default:
				return '';
		}
	}

	/**
	 * Get the From + Reply-To headers array for wp_mail().
	 *
	 * @return array
	 */
	public static function get_headers() {
		$from_name    = self::get( 'from_name' );
		$from_address = self::get( 'from_address' );
		$reply_to     = self::get( 'reply_to' );

		$headers = array(
			sprintf( 'From: %s <%s>', $from_name, $from_address ),
		);

		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		return $headers;
	}

	/**
	 * Get the formatted email signature text.
	 *
	 * Uses the custom signature if set, otherwise builds one from
	 * owner_name and business_name. Supports {owner_name} and
	 * {business_name} placeholders.
	 *
	 * @return string
	 */
	public static function get_signature() {
		$custom = self::get( 'signature' );
		$owner  = self::get( 'owner_name' );
		$business = SLW_Invoice_Settings::get( 'business_name' );

		if ( $custom ) {
			$sig = str_replace(
				array( '{owner_name}', '{business_name}' ),
				array( $owner, $business ),
				$custom
			);
			return $sig;
		}

		// Default signature: owner name on first line, business name on second
		$parts = array();
		if ( $owner ) {
			$parts[] = $owner;
		}
		if ( $business ) {
			$parts[] = $business;
		}

		return implode( "\n", $parts );
	}

	/**
	 * Get the business name for use in email subjects and bodies.
	 *
	 * @return string
	 */
	public static function get_business_name() {
		return SLW_Invoice_Settings::get( 'business_name' );
	}

	/**
	 * Register all email settings with sanitization callbacks.
	 */
	public static function register_settings() {
		register_setting( 'slw_invoice_settings_group', 'slw_email_from_name', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		));
		register_setting( 'slw_invoice_settings_group', 'slw_email_from_address', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
			'default'           => '',
		));
		register_setting( 'slw_invoice_settings_group', 'slw_email_reply_to', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
			'default'           => '',
		));
		register_setting( 'slw_invoice_settings_group', 'slw_email_owner_name', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		));
		register_setting( 'slw_invoice_settings_group', 'slw_email_signature', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		));
	}

	/**
	 * Render the email settings section on the invoice settings page.
	 * Called from SLW_Invoice_Settings::render_page().
	 */
	public static function render_settings_section() {
		?>
		<h2 class="title">Email Settings (White-Label)</h2>
		<p>Configure sender identity for all emails sent by the plugin. Leave blank to use defaults.</p>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="slw_email_from_name">From Name</label></th>
				<td>
					<input type="text" id="slw_email_from_name" name="slw_email_from_name"
						   value="<?php echo esc_attr( get_option( 'slw_email_from_name', '' ) ); ?>"
						   class="regular-text"
						   placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
					<p class="description">The name that appears in the "From" field of outgoing emails. Default: site name.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slw_email_from_address">From Email Address</label></th>
				<td>
					<input type="email" id="slw_email_from_address" name="slw_email_from_address"
						   value="<?php echo esc_attr( get_option( 'slw_email_from_address', '' ) ); ?>"
						   class="regular-text"
						   placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
					<p class="description">Sender email address. Should match your domain's SPF/DKIM for deliverability. Default: admin email.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slw_email_reply_to">Reply-To Address</label></th>
				<td>
					<input type="email" id="slw_email_reply_to" name="slw_email_reply_to"
						   value="<?php echo esc_attr( get_option( 'slw_email_reply_to', '' ) ); ?>"
						   class="regular-text"
						   placeholder="<?php echo esc_attr( get_option( 'slw_email_from_address', get_option( 'admin_email' ) ) ); ?>" />
					<p class="description">Where customer replies go. Default: same as From address.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slw_email_owner_name">Owner / Signer Name</label></th>
				<td>
					<input type="text" id="slw_email_owner_name" name="slw_email_owner_name"
						   value="<?php echo esc_attr( get_option( 'slw_email_owner_name', '' ) ); ?>"
						   class="regular-text"
						   placeholder="e.g. Holly Stoltz" />
					<p class="description">The name that signs emails (e.g. at the bottom of welcome emails). Leave blank to omit.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="slw_email_signature">Email Signature</label></th>
				<td>
					<textarea id="slw_email_signature" name="slw_email_signature"
							  rows="3" class="large-text"
							  placeholder="<?php echo esc_attr( "{owner_name}\n{business_name}" ); ?>"><?php echo esc_textarea( get_option( 'slw_email_signature', '' ) ); ?></textarea>
					<p class="description">Appended to outgoing emails. Use <code>{owner_name}</code> and <code>{business_name}</code> as placeholders. Default: owner name + business name on separate lines.</p>
				</td>
			</tr>
		</table>
		<?php
	}
}
