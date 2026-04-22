<?php
/**
 * Template: Request for Quote Form
 *
 * Rendered by the [sego_wholesale_rfq] shortcode.
 * Allows wholesale customers to build a multi-product quote request
 * with quantities, notes, and a requested delivery date.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$user       = wp_get_current_user();
$first_name = $user->first_name ?: $user->display_name;
$nonce      = wp_create_nonce( 'slw_rfq_submit' );
$ajax_url   = admin_url( 'admin-ajax.php' );

// Get all published products for the dropdown
$products = wc_get_products( array(
	'status'  => 'publish',
	'limit'   => -1,
	'orderby' => 'title',
	'order'   => 'ASC',
) );
?>

<div class="slw-rfq-wrap">
	<div class="slw-rfq-header">
		<h2>Request a Quote</h2>
		<p>Need custom pricing for a larger order? Add the products you're interested in and we'll get back to you with a quote within 1-2 business days.</p>
	</div>

	<div id="slw-rfq-success" class="slw-notice slw-notice-success" style="display:none;">
		<h3>Quote Request Submitted</h3>
		<?php
		$owner_name = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'owner_name' ) : '';
		$reviewer   = $owner_name ? $owner_name : 'We';
		?>
		<p>Thanks, <?php echo esc_html( $first_name ); ?>! <?php echo esc_html( $reviewer ); ?> will review your request and get back to you shortly.</p>
	</div>

	<form id="slw-rfq-form" class="slw-form slw-rfq-form" novalidate>
		<input type="hidden" name="action" value="slw_submit_rfq" />
		<input type="hidden" name="slw_nonce" value="<?php echo esc_attr( $nonce ); ?>" />

		<h3 class="slw-step-title">Products</h3>
		<p class="slw-step-subtitle">Add the products you'd like quoted. You can add as many as you need.</p>

		<div id="slw-rfq-products">
			<div class="slw-rfq-product-row" data-row="1">
				<div class="slw-form-row">
					<div class="slw-form-field" style="flex:3;">
						<label>Product <span class="required">*</span></label>
						<select class="slw-rfq-product-select" required>
							<option value="">Select a product...</option>
							<?php foreach ( $products as $product ) :
								if ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) continue;
								$sku_label = $product->get_sku() ? ' (SKU: ' . $product->get_sku() . ')' : '';
							?>
								<option value="<?php echo esc_attr( $product->get_id() ); ?>"><?php echo esc_html( $product->get_name() . $sku_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="slw-form-field" style="flex:1;">
						<label>Qty <span class="required">*</span></label>
						<input type="number" class="slw-rfq-qty" min="1" value="1" required />
					</div>
					<div class="slw-form-field" style="flex:2;">
						<label>Notes</label>
						<input type="text" class="slw-rfq-notes" placeholder="e.g. specific scent, size..." />
					</div>
					<div class="slw-form-field slw-rfq-remove-col" style="flex:0 0 40px; align-self:flex-end;">
						<button type="button" class="slw-rfq-remove-row" title="Remove" style="display:none;">&times;</button>
					</div>
				</div>
			</div>
		</div>

		<button type="button" class="slw-btn slw-btn-small slw-rfq-add-row" id="slw-rfq-add-row">+ Add Another Product</button>

		<hr style="margin: 28px 0; border: none; border-top: 1px solid #e0ddd8;" />

		<h3 class="slw-step-title">Details</h3>

		<div class="slw-form-row">
			<div class="slw-form-field slw-half">
				<label for="slw_rfq_delivery_date">Requested Delivery Date</label>
				<input type="date" id="slw_rfq_delivery_date" name="delivery_date" min="<?php echo esc_attr( date( 'Y-m-d', strtotime( '+3 days' ) ) ); ?>" />
				<small class="slw-field-hint">Optional. We'll do our best to accommodate.</small>
			</div>
		</div>

		<div class="slw-form-row">
			<div class="slw-form-field">
				<label for="slw_rfq_notes">Additional Notes</label>
				<textarea id="slw_rfq_notes" name="additional_notes" rows="4" placeholder="Anything else we should know about this order?"></textarea>
			</div>
		</div>

		<div id="slw-rfq-error" class="slw-notice slw-notice-error" style="display:none;"></div>

		<div class="slw-step-nav" style="justify-content: flex-end;">
			<button type="submit" class="slw-btn slw-btn-cta slw-btn-submit" id="slw-rfq-submit-btn">Request Quote</button>
		</div>
	</form>
</div>

<script>
(function() {
	var form        = document.getElementById('slw-rfq-form');
	var productsDiv = document.getElementById('slw-rfq-products');
	var addRowBtn   = document.getElementById('slw-rfq-add-row');
	var errorEl     = document.getElementById('slw-rfq-error');
	var submitBtn   = document.getElementById('slw-rfq-submit-btn');
	var successEl   = document.getElementById('slw-rfq-success');
	var rowCount    = 1;

	if (!form) return;

	// Build the product options HTML from the first row (cache it)
	var firstSelect = productsDiv.querySelector('.slw-rfq-product-select');
	var optionsHTML = firstSelect ? firstSelect.innerHTML : '';

	// Add another product row
	addRowBtn.addEventListener('click', function() {
		rowCount++;
		var row = document.createElement('div');
		row.className = 'slw-rfq-product-row';
		row.setAttribute('data-row', rowCount);
		row.innerHTML =
			'<div class="slw-form-row">' +
				'<div class="slw-form-field" style="flex:3;">' +
					'<label>Product <span class="required">*</span></label>' +
					'<select class="slw-rfq-product-select" required>' + optionsHTML + '</select>' +
				'</div>' +
				'<div class="slw-form-field" style="flex:1;">' +
					'<label>Qty <span class="required">*</span></label>' +
					'<input type="number" class="slw-rfq-qty" min="1" value="1" required />' +
				'</div>' +
				'<div class="slw-form-field" style="flex:2;">' +
					'<label>Notes</label>' +
					'<input type="text" class="slw-rfq-notes" placeholder="e.g. specific scent, size..." />' +
				'</div>' +
				'<div class="slw-form-field slw-rfq-remove-col" style="flex:0 0 40px; align-self:flex-end;">' +
					'<button type="button" class="slw-rfq-remove-row" title="Remove">&times;</button>' +
				'</div>' +
			'</div>';
		productsDiv.appendChild(row);
		updateRemoveButtons();
	});

	// Remove a product row (event delegation)
	productsDiv.addEventListener('click', function(e) {
		if (e.target.classList.contains('slw-rfq-remove-row')) {
			e.target.closest('.slw-rfq-product-row').remove();
			updateRemoveButtons();
		}
	});

	function updateRemoveButtons() {
		var rows = productsDiv.querySelectorAll('.slw-rfq-product-row');
		rows.forEach(function(row) {
			var btn = row.querySelector('.slw-rfq-remove-row');
			if (btn) {
				btn.style.display = rows.length > 1 ? 'inline-block' : 'none';
			}
		});
	}

	// Collect products from the form
	function collectProducts() {
		var items = [];
		var rows = productsDiv.querySelectorAll('.slw-rfq-product-row');
		rows.forEach(function(row) {
			var select = row.querySelector('.slw-rfq-product-select');
			var qty    = row.querySelector('.slw-rfq-qty');
			var notes  = row.querySelector('.slw-rfq-notes');

			if (select && select.value && qty && parseInt(qty.value) > 0) {
				items.push({
					product_id: select.value,
					quantity: parseInt(qty.value),
					notes: notes ? notes.value : ''
				});
			}
		});
		return items;
	}

	// Submit
	form.addEventListener('submit', function(e) {
		e.preventDefault();
		errorEl.style.display = 'none';

		var products = collectProducts();
		if (products.length === 0) {
			errorEl.textContent = 'Please add at least one product with a valid quantity.';
			errorEl.style.display = 'block';
			return;
		}

		submitBtn.disabled = true;
		submitBtn.textContent = 'Submitting...';

		var formData = new FormData();
		formData.append('action', 'slw_submit_rfq');
		formData.append('slw_nonce', form.querySelector('[name="slw_nonce"]').value);
		formData.append('products', JSON.stringify(products));
		formData.append('delivery_date', document.getElementById('slw_rfq_delivery_date').value || '');
		formData.append('additional_notes', document.getElementById('slw_rfq_notes').value || '');

		var xhr = new XMLHttpRequest();
		xhr.open('POST', '<?php echo esc_js( $ajax_url ); ?>');
		xhr.onload = function() {
			var resp;
			try { resp = JSON.parse(xhr.responseText); } catch(ex) { resp = null; }

			if (xhr.status === 200 && resp && resp.success) {
				form.style.display = 'none';
				successEl.style.display = 'block';
				successEl.scrollIntoView({ behavior: 'smooth' });
			} else {
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Something went wrong. Please try again.';
				errorEl.textContent = msg;
				errorEl.style.display = 'block';
				submitBtn.disabled = false;
				submitBtn.textContent = 'Request Quote';
			}
		};
		xhr.onerror = function() {
			errorEl.textContent = 'Network error. Please check your connection and try again.';
			errorEl.style.display = 'block';
			submitBtn.disabled = false;
			submitBtn.textContent = 'Request Quote';
		};
		xhr.send(formData);
	});
})();
</script>
