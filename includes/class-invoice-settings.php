<?php
/**
 * Invoice Settings Page
 *
 * Adds a "Wholesale Invoices" sub-page under Settings with customizable
 * fields for logo, business info, accent color, and invoice text.
 * All settings stored as individual WP options with slw_invoice_ prefix.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Invoice_Settings {

	/** All option keys with their defaults. */
	private static $defaults = array(
		'slw_invoice_logo_id'        => 0,
		'slw_invoice_business_name'  => '',
		'slw_invoice_business_address' => '',
		'slw_invoice_business_phone' => '',
		'slw_invoice_business_email' => '',
		'slw_invoice_accent_color'   => '#386174',
		'slw_invoice_number_prefix'  => 'SLW-',
		'slw_invoice_footer_text'    => 'Thank you for your business!',
		'slw_invoice_payment_terms'  => 'Payment due within 30 days of invoice date.',
	);

	public static function init() {
		// Admin menu is registered centrally by SLW_Admin_Menu
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	/**
	 * Get a single invoice setting with its default.
	 *
	 * @param string $key Option name (with or without slw_invoice_ prefix).
	 * @return mixed
	 */
	public static function get( $key ) {
		$full_key = strpos( $key, 'slw_invoice_' ) === 0 ? $key : 'slw_invoice_' . $key;
		$default  = isset( self::$defaults[ $full_key ] ) ? self::$defaults[ $full_key ] : '';
		$value    = get_option( $full_key );

		// Dynamic defaults for fields that should auto-populate from WP settings
		if ( ( $value === false || $value === '' ) && $default === '' ) {
			if ( $full_key === 'slw_invoice_business_name' ) {
				return get_bloginfo( 'name' );
			}
			if ( $full_key === 'slw_invoice_business_email' ) {
				return get_option( 'admin_email', '' );
			}
		}

		return ( $value !== false && $value !== '' ) ? $value : $default;
	}

	/**
	 * Register the sub-menu page under Sego Lily Wholesale.
	 */
	public static function add_settings_page() {
		add_submenu_page(
			'slw-applications',
			'Invoice Settings',
			'Invoices',
			'manage_woocommerce',
			'slw-invoice-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register all settings with sanitization callbacks.
	 */
	public static function register_settings() {
		register_setting( 'slw_invoice_settings_group', 'slw_invoice_logo_id', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		));
		register_setting( 'slw_invoice_settings_group', 'slw_invoice_business_name', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		));
		register_setting( 'slw_invoice_settings_group', 'slw_invoice_business_address', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		));
		register_setting( 'slw_invoice_settings_group', 'slw_invoice_business_phone', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		));
		register_setting( 'slw_invoice_settings_group', 'slw_invoice_business_email', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
			'default'           => '',
		));
		register_setting( 'slw_invoice_settings_group', 'slw_invoice_accent_color', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_hex_color',
			'default'           => '#386174',
		));
		register_setting( 'slw_invoice_settings_group', 'slw_invoice_number_prefix', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'SLW-',
		));
		register_setting( 'slw_invoice_settings_group', 'slw_invoice_footer_text', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => 'Thank you for your business!',
		));
		register_setting( 'slw_invoice_settings_group', 'slw_invoice_payment_terms', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => 'Payment due within 30 days of invoice date.',
		));
	}

	/**
	 * Enqueue the WP media uploader and color picker on our settings page only.
	 */
	public static function enqueue_admin_assets( $hook ) {
		// Load modern admin CSS on all plugin pages.
		// Hook names vary: "toplevel_page_slw-applications", "wholesale_page_slw-rfq",
		// "settings_page_slw-invoice-settings", etc. All contain "slw-" or "slw_".
		if ( strpos( $hook, 'slw-' ) !== false || strpos( $hook, 'slw_' ) !== false ) {
			wp_enqueue_style( 'slw-admin', SLW_PLUGIN_URL . 'assets/admin.css', array(), SLW_VERSION );
		}

		// Media uploader + color picker only on the invoice settings page
		if ( strpos( $hook, 'slw-invoice-settings' ) === false ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$logo_id  = absint( self::get( 'logo_id' ) );
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		?>
		<div class="wrap">
			<h1>Wholesale Invoice Settings</h1>
			<p>Customize the look and content of your wholesale invoices and line sheets.</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'slw_invoice_settings_group' ); ?>

				<h2 class="title">Business Information</h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label>Business Logo</label></th>
						<td>
							<div id="slw-logo-preview" style="margin-bottom:10px;">
								<?php if ( $logo_url ) : ?>
									<img src="<?php echo esc_url( $logo_url ); ?>" style="max-width:200px;max-height:80px;" />
								<?php endif; ?>
							</div>
							<input type="hidden" id="slw_invoice_logo_id" name="slw_invoice_logo_id"
								   value="<?php echo esc_attr( $logo_id ); ?>" />
							<button type="button" class="button" id="slw-upload-logo">
								<?php echo $logo_id ? 'Change Logo' : 'Upload Logo'; ?>
							</button>
							<?php if ( $logo_id ) : ?>
								<button type="button" class="button" id="slw-remove-logo" style="margin-left:8px;">Remove</button>
							<?php endif; ?>
							<p class="description">Recommended: PNG or SVG, max 400px wide. Appears on invoices and line sheets.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slw_invoice_business_name">Business Name</label></th>
						<td>
							<input type="text" id="slw_invoice_business_name" name="slw_invoice_business_name"
								   value="<?php echo esc_attr( self::get( 'business_name' ) ); ?>"
								   class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slw_invoice_business_address">Business Address</label></th>
						<td>
							<textarea id="slw_invoice_business_address" name="slw_invoice_business_address"
									  rows="3" class="large-text"><?php echo esc_textarea( self::get( 'business_address' ) ); ?></textarea>
							<p class="description">Multi-line. Each line break will appear on the invoice.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slw_invoice_business_phone">Business Phone</label></th>
						<td>
							<input type="text" id="slw_invoice_business_phone" name="slw_invoice_business_phone"
								   value="<?php echo esc_attr( self::get( 'business_phone' ) ); ?>"
								   class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slw_invoice_business_email">Business Email</label></th>
						<td>
							<input type="email" id="slw_invoice_business_email" name="slw_invoice_business_email"
								   value="<?php echo esc_attr( self::get( 'business_email' ) ); ?>"
								   class="regular-text" />
						</td>
					</tr>
				</table>

				<h2 class="title">Invoice Appearance</h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="slw_invoice_accent_color">Accent Color</label></th>
						<td>
							<input type="text" id="slw_invoice_accent_color" name="slw_invoice_accent_color"
								   value="<?php echo esc_attr( self::get( 'accent_color' ) ); ?>"
								   class="slw-color-picker" data-default-color="#386174" />
							<p class="description">Used for headings, borders, and accent elements on invoices.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slw_invoice_number_prefix">Invoice Number Prefix</label></th>
						<td>
							<input type="text" id="slw_invoice_number_prefix" name="slw_invoice_number_prefix"
								   value="<?php echo esc_attr( self::get( 'number_prefix' ) ); ?>"
								   class="regular-text" placeholder="SLW-" />
							<p class="description">Prepended to the order ID to form the invoice number (e.g., SLW-1234).</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Invoice Text</h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="slw_invoice_footer_text">Custom Footer Text</label></th>
						<td>
							<textarea id="slw_invoice_footer_text" name="slw_invoice_footer_text"
									  rows="3" class="large-text"><?php echo esc_textarea( self::get( 'footer_text' ) ); ?></textarea>
							<p class="description">Shown at the bottom of every invoice. Use for payment instructions, return policy, etc.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slw_invoice_payment_terms">Payment Terms Note</label></th>
						<td>
							<textarea id="slw_invoice_payment_terms" name="slw_invoice_payment_terms"
									  rows="2" class="large-text"><?php echo esc_textarea( self::get( 'payment_terms' ) ); ?></textarea>
							<p class="description">Shown on NET 30 invoices. Ignored for standard (paid) orders.</p>
						</td>
					</tr>
				</table>

				<?php
				// Email settings section (white-label)
				if ( class_exists( 'SLW_Email_Settings' ) ) {
					SLW_Email_Settings::render_settings_section();
				}
				?>

				<?php submit_button( 'Save Invoice Settings' ); ?>
			</form>

			<!-- Invoice Preview -->
			<div class="slw-invoice-preview-card">
				<h3>Invoice Preview</h3>
				<p>Preview shows how your invoice looks with the current saved settings. Save changes above first, then refresh the preview.</p>
				<iframe id="slw-invoice-preview-iframe"
						class="slw-invoice-preview-frame"
						src="<?php echo esc_url( add_query_arg( 'slw_invoice_preview', '1', home_url( '/' ) ) ); ?>"
						frameborder="0"
						loading="lazy"></iframe>
				<div class="slw-preview-actions">
					<button type="button" class="button" id="slw-refresh-preview">Refresh Preview</button>
					<a href="<?php echo esc_url( add_query_arg( 'slw_invoice_preview', '1', home_url( '/' ) ) ); ?>"
					   target="_blank" class="button">Open in New Tab</a>
				</div>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			// Color picker
			$('.slw-color-picker').wpColorPicker();

			// Media uploader for logo
			var frame;
			$('#slw-upload-logo').on('click', function(e) {
				e.preventDefault();
				if (frame) { frame.open(); return; }
				frame = wp.media({
					title: 'Select Invoice Logo',
					button: { text: 'Use This Logo' },
					multiple: false,
					library: { type: 'image' }
				});
				frame.on('select', function() {
					var attachment = frame.state().get('selection').first().toJSON();
					$('#slw_invoice_logo_id').val(attachment.id);
					var url = attachment.sizes && attachment.sizes.medium
						? attachment.sizes.medium.url : attachment.url;
					$('#slw-logo-preview').html(
						'<img src="' + url + '" style="max-width:200px;max-height:80px;" />'
					);
					$('#slw-upload-logo').text('Change Logo');
					if (!$('#slw-remove-logo').length) {
						$('#slw-upload-logo').after(
							' <button type="button" class="button" id="slw-remove-logo" style="margin-left:8px;">Remove</button>'
						);
						bindRemove();
					}
				});
				frame.open();
			});

			function bindRemove() {
				$('#slw-remove-logo').off('click').on('click', function(e) {
					e.preventDefault();
					$('#slw_invoice_logo_id').val('0');
					$('#slw-logo-preview').html('');
					$('#slw-upload-logo').text('Upload Logo');
					$(this).remove();
				});
			}
			bindRemove();

			// Refresh invoice preview
			$('#slw-refresh-preview').on('click', function(e) {
				e.preventDefault();
				var iframe = document.getElementById('slw-invoice-preview-iframe');
				if (iframe) {
					iframe.src = iframe.src;
				}
			});
		});
		</script>
		<?php
	}
}
