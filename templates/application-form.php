<?php
/**
 * Template: Wholesale Application Form
 *
 * Rendered by the [sego_wholesale_application] shortcode.
 * Submitted via AJAX to avoid page reloads.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$nonce = wp_create_nonce( 'slw_application_submit' );
$ajax_url = admin_url( 'admin-ajax.php' );
$minimum = number_format( (float) slw_get_option( 'first_order_minimum', 300 ), 0 );
?>

<div class="slw-application-wrap">
    <div class="slw-application-header">
        <h2>Become a Wholesale Partner</h2>
        <p>We love working with boutiques, salons, and shops that share our values. Fill out the form below and Holly will review your application personally. You'll hear back within 2-3 business days.</p>
    </div>

    <div id="slw-form-success" style="display:none;">
        <div class="slw-notice slw-notice-success">
            <h3>Application Received</h3>
            <p>Thanks for applying! Holly reviews applications personally and you'll hear back within 2-3 business days.</p>
        </div>
    </div>

    <form id="slw-application-form" class="slw-form" novalidate>
        <input type="hidden" name="action" value="slw_submit_application" />
        <input type="hidden" name="slw_nonce" value="<?php echo esc_attr( $nonce ); ?>" />

        <!-- Honeypot: hidden from real users, bots will fill it.
             Field name intentionally avoids "url", "website", "email",
             "name", "phone" so browser autofill does not fill it for
             real users and trigger a false spam rejection. -->
        <div style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;" aria-hidden="true">
            <label for="slw_hp_confirm">Leave this empty</label>
            <input type="text" name="slw_hp_confirm" id="slw_hp_confirm" tabindex="-1" autocomplete="new-password" />
        </div>

        <div class="slw-form-row">
            <div class="slw-form-field slw-half">
                <label for="slw_business_name">Business Name <span class="required">*</span></label>
                <input type="text" id="slw_business_name" name="business_name" required />
            </div>
            <div class="slw-form-field slw-half">
                <label for="slw_contact_name">Contact Name <span class="required">*</span></label>
                <input type="text" id="slw_contact_name" name="contact_name" required />
            </div>
        </div>

        <div class="slw-form-row">
            <div class="slw-form-field slw-half">
                <label for="slw_email">Email <span class="required">*</span></label>
                <input type="email" id="slw_email" name="email" required />
            </div>
            <div class="slw-form-field slw-half">
                <label for="slw_phone">Phone <span class="required">*</span></label>
                <input type="tel" id="slw_phone" name="phone" required />
            </div>
        </div>

        <div class="slw-form-row">
            <div class="slw-form-field">
                <label for="slw_address">Business Address <span class="required">*</span></label>
                <textarea id="slw_address" name="address" rows="3" required></textarea>
            </div>
        </div>

        <div class="slw-form-row">
            <div class="slw-form-field slw-half">
                <label for="slw_website">Website or Social Handle</label>
                <input type="text" id="slw_website" name="website" placeholder="yourstore.com or @yourstore" />
                <small style="color:#628393;display:block;margin-top:4px;font-size:13px;">Optional. A website, Instagram handle, or Facebook page all work.</small>
            </div>
            <div class="slw-form-field slw-half">
                <label for="slw_ein">EIN / Resale Certificate Number <span class="required">*</span></label>
                <input type="text" id="slw_ein" name="ein" required />
            </div>
        </div>

        <div class="slw-form-row">
            <div class="slw-form-field slw-half">
                <label for="slw_business_type">Business Type <span class="required">*</span></label>
                <select id="slw_business_type" name="business_type" required>
                    <option value="">Select your business type</option>
                    <option value="boutique">Boutique</option>
                    <option value="salon_spa">Salon / Spa</option>
                    <option value="health_food">Health Food Store</option>
                    <option value="gift_shop">Gift Shop</option>
                    <option value="coffee_cafe">Coffee Shop / Cafe</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="slw-form-field slw-half">
                <label for="slw_how_heard">How did you hear about us?</label>
                <input type="text" id="slw_how_heard" name="how_heard" />
            </div>
        </div>

        <div class="slw-form-row">
            <div class="slw-form-field">
                <label for="slw_why_carry">Why do you want to carry Sego Lily?</label>
                <textarea id="slw_why_carry" name="why_carry" rows="4" placeholder="Tell us about your shop and your customers."></textarea>
            </div>
        </div>

        <div class="slw-form-row">
            <div class="slw-form-field">
                <label class="slw-checkbox-label">
                    <input type="checkbox" name="agree_minimum" id="slw_agree_minimum" required />
                    I understand the $<?php echo $minimum; ?> minimum first order requirement.
                </label>
            </div>
        </div>

        <div id="slw-form-error" class="slw-notice slw-notice-error" style="display:none;"></div>

        <div class="slw-form-row">
            <button type="submit" class="slw-btn slw-btn-primary" id="slw-submit-btn">Submit Application</button>
        </div>
    </form>
</div>

<script>
(function() {
    var form = document.getElementById('slw-application-form');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = document.getElementById('slw-submit-btn');
        var errorEl = document.getElementById('slw-form-error');
        errorEl.style.display = 'none';
        btn.disabled = true;
        btn.textContent = 'Submitting...';

        var formData = new FormData(form);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '<?php echo esc_js( $ajax_url ); ?>');
        xhr.onload = function() {
            var resp;
            try { resp = JSON.parse(xhr.responseText); } catch(e) { resp = null; }

            if (xhr.status === 200 && resp && resp.success) {
                form.style.display = 'none';
                document.getElementById('slw-form-success').style.display = 'block';
                // Scroll to success message
                document.getElementById('slw-form-success').scrollIntoView({ behavior: 'smooth' });
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Something went wrong. Please try again.';
                errorEl.textContent = msg;
                errorEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Submit Application';
            }
        };
        xhr.onerror = function() {
            errorEl.textContent = 'Network error. Please check your connection and try again.';
            errorEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Submit Application';
        };
        xhr.send(formData);
    });
})();
</script>
