<?php
/**
 * Tier Settings
 *
 * Admin settings page section for configuring wholesale tiers.
 * Adds a submenu page under the existing Sego Lily Wholesale settings
 * where Holly can customize tier names, discounts, and upgrade thresholds.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Tier_Settings {

	public static function init() {
		// Admin menu is registered centrally by SLW_Admin_Menu
		add_action( 'admin_post_slw_save_tiers', array( __CLASS__, 'save_tiers' ) );
	}

	/**
	 * Add a "Wholesale Tiers" submenu under the existing Wholesale Applications menu.
	 */
	public static function add_settings_page() {
		add_submenu_page(
			'slw-applications',
			'Wholesale Tiers',
			'Tiers',
			'manage_woocommerce',
			'slw-tiers',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the tier configuration page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$tiers = SLW_Tiers::get_tiers();
		$saved = isset( $_GET['saved'] ) && $_GET['saved'] === '1';
		?>
		<div class="wrap">
			<h1>Wholesale Tier Configuration</h1>
			<p>Define your wholesale tiers below. Each tier has a discount percentage and upgrade thresholds. Customers are auto-upgraded when they meet <strong>either</strong> the order count or lifetime spend threshold.</p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>Tier settings saved successfully.</p>
				</div>
			<?php endif; ?>

			<div class="notice notice-warning inline" style="margin:12px 0 16px;">
				<p><strong>Tier slugs and display names are locked by default.</strong> Renaming them will break automation copy (welcome emails, upgrade messages) and reporting that references the old name. Use the <em>Unlock</em> button only if you are sure.</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'slw_save_tiers' ); ?>
				<input type="hidden" name="action" value="slw_save_tiers" />

				<table class="widefat fixed striped" style="max-width:900px;">
					<thead>
						<tr>
							<th style="width:120px;">Tier Slug</th>
							<th style="width:160px;">Display Name</th>
							<th style="width:100px;">Discount %</th>
							<th style="width:130px;">Orders Threshold</th>
							<th style="width:130px;">Spend Threshold ($)</th>
							<th style="width:60px;"></th>
						</tr>
					</thead>
					<tbody id="slw-tier-rows">
						<?php
						$index = 0;
						foreach ( $tiers as $slug => $tier ) :
						?>
						<tr class="slw-tier-row" data-existing="1">
							<td>
								<input type="text" name="tiers[<?php echo $index; ?>][slug]" value="<?php echo esc_attr( $slug ); ?>" class="regular-text slw-tier-locked" style="width:100%;" pattern="[a-z0-9_]+" title="Lowercase letters, numbers, underscores only" required readonly />
							</td>
							<td>
								<input type="text" name="tiers[<?php echo $index; ?>][name]" value="<?php echo esc_attr( $tier['name'] ); ?>" class="regular-text slw-tier-locked" style="width:100%;" required readonly />
							</td>
							<td>
								<input type="number" name="tiers[<?php echo $index; ?>][discount]" value="<?php echo esc_attr( $tier['discount'] ); ?>" min="0" max="99" step="0.5" style="width:100%;" required />
							</td>
							<td>
								<input type="number" name="tiers[<?php echo $index; ?>][order_threshold]" value="<?php echo esc_attr( $tier['order_threshold'] ?? 0 ); ?>" min="0" step="1" style="width:100%;" />
							</td>
							<td>
								<input type="number" name="tiers[<?php echo $index; ?>][spend_threshold]" value="<?php echo esc_attr( $tier['spend_threshold'] ?? 0 ); ?>" min="0" step="1" style="width:100%;" />
							</td>
							<td style="white-space:nowrap;">
								<button type="button" class="button button-small slw-tier-unlock" title="Unlock slug + name">Unlock</button>
								<?php if ( $slug !== 'standard' ) : ?>
									<button type="button" class="button slw-remove-tier" title="Remove tier" style="margin-left:4px;">&times;</button>
								<?php endif; ?>
							</td>
						</tr>
						<?php
						$index++;
						endforeach;
						?>
					</tbody>
				</table>

				<p style="margin-top:12px;">
					<button type="button" class="button" id="slw-add-tier">+ Add Tier</button>
				</p>

				<h3>How Tiers Work</h3>
				<ul style="list-style:disc;margin-left:20px;color:#628393;">
					<li>The <strong>Standard</strong> tier is the default for all new wholesale partners. Its discount should match your global wholesale discount.</li>
					<li>Higher tiers unlock automatically when a customer reaches <strong>either</strong> the order count or the lifetime spend threshold.</li>
					<li>Tiers are evaluated from top to bottom. The highest tier the customer qualifies for is assigned.</li>
					<li>Auto-upgrade happens when an order status changes to "Completed". You can also set tiers manually on user profiles.</li>
					<li>Tiers never auto-downgrade. Use the user profile to manually adjust if needed.</li>
				</ul>

				<?php submit_button( 'Save Tier Settings' ); ?>
			</form>
		</div>

		<script>
		(function() {
			var tbody = document.getElementById('slw-tier-rows');
			var addBtn = document.getElementById('slw-add-tier');

			addBtn.addEventListener('click', function() {
				var rows = tbody.querySelectorAll('.slw-tier-row');
				var idx = rows.length;
				var tr = document.createElement('tr');
				tr.className = 'slw-tier-row';
				// New rows are editable from creation — only existing tiers are locked.
				tr.innerHTML = '<td><input type="text" name="tiers[' + idx + '][slug]" value="" class="regular-text" style="width:100%;" pattern="[a-z0-9_]+" title="Lowercase letters, numbers, underscores only" required /></td>'
					+ '<td><input type="text" name="tiers[' + idx + '][name]" value="" class="regular-text" style="width:100%;" required /></td>'
					+ '<td><input type="number" name="tiers[' + idx + '][discount]" value="50" min="0" max="99" step="0.5" style="width:100%;" required /></td>'
					+ '<td><input type="number" name="tiers[' + idx + '][order_threshold]" value="0" min="0" step="1" style="width:100%;" /></td>'
					+ '<td><input type="number" name="tiers[' + idx + '][spend_threshold]" value="0" min="0" step="1" style="width:100%;" /></td>'
					+ '<td><button type="button" class="button slw-remove-tier" title="Remove tier">&times;</button></td>';
				tbody.appendChild(tr);
			});

			tbody.addEventListener('click', function(e) {
				if (e.target.classList.contains('slw-remove-tier')) {
					e.target.closest('tr').remove();
					return;
				}
				if (e.target.classList.contains('slw-tier-unlock')) {
					var row = e.target.closest('tr');
					var locked = row.querySelectorAll('.slw-tier-locked');
					if (!locked.length) return;
					var answer = window.prompt('Renaming tier slugs or display names will break automation copy and reporting. Type RESET to enable editing.');
					if (answer === 'RESET') {
						locked.forEach(function(input) {
							input.removeAttribute('readonly');
							input.classList.remove('slw-tier-locked');
							input.style.background = '#fff8e1';
						});
						e.target.disabled = true;
						e.target.textContent = 'Unlocked';
					}
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Save tier configuration from the admin form.
	 */
	public static function save_tiers() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized', 403 );
		}
		check_admin_referer( 'slw_save_tiers' );

		$raw_tiers = isset( $_POST['tiers'] ) ? $_POST['tiers'] : array();
		$tiers = array();

		foreach ( $raw_tiers as $raw ) {
			$slug = sanitize_key( $raw['slug'] ?? '' );
			if ( ! $slug ) {
				continue;
			}

			$tiers[ $slug ] = array(
				'name'            => sanitize_text_field( $raw['name'] ?? $slug ),
				'discount'        => max( 0, min( 99, (float) ( $raw['discount'] ?? 50 ) ) ),
				'order_threshold' => max( 0, absint( $raw['order_threshold'] ?? 0 ) ),
				'spend_threshold' => max( 0, (float) ( $raw['spend_threshold'] ?? 0 ) ),
			);
		}

		// Ensure standard tier always exists
		if ( ! isset( $tiers['standard'] ) ) {
			$tiers = array_merge( array(
				'standard' => array(
					'name'            => 'Standard',
					'discount'        => 50,
					'order_threshold' => 0,
					'spend_threshold' => 0,
				),
			), $tiers );
		}

		update_option( 'slw_wholesale_tiers', $tiers );

		wp_redirect( admin_url( 'admin.php?page=slw-tiers&saved=1' ) );
		exit;
	}
}
