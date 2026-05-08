<?php
/**
 * Template: Wholesale Application Form
 *
 * Rendered by the [sego_wholesale_application] shortcode.
 *
 * The form is split into 3 progressive steps (About Your Business, Contact
 * Info, The Details) with per-step validation. Only the active step is
 * visible; "Next" advances to the next step after validating required
 * fields in the current one. The final step's submit button ships the whole
 * payload via AJAX to the same admin-ajax handler — so the server side
 * receives a single POST containing every field, exactly as before.
 *
 * Pages embedding this form are expected to provide their own intro / heading
 * above the shortcode. The plugin no longer renders a built-in banner, so the
 * form sits flush to whatever page section it lives in.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$nonce = wp_create_nonce( 'slw_application_submit' );
$ajax_url = admin_url( 'admin-ajax.php' );
$minimum = number_format( (float) slw_get_option( 'first_order_minimum', 300 ), 0 );
?>

<div class="slw-application-wrap">
    <div id="slw-form-success" style="display:none;">
        <div class="slw-notice slw-notice-success">
            <h3>Application Received</h3>
            <?php
            $owner_name = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'owner_name' ) : '';
            $review_msg = $owner_name
                ? sprintf( 'Thanks for applying! %s reviews applications personally and you\'ll hear back within 2-3 business days.', esc_html( $owner_name ) )
                : 'Thanks for applying! We review applications personally and you\'ll hear back within 2-3 business days.';
            ?>
            <p><?php echo $review_msg; ?></p>
        </div>
        <div style="background:#FFF8E1;border:1px solid #ffe082;border-radius:8px;padding:14px 18px;margin-top:14px;color:#5d4037;font-size:14px;line-height:1.5;">
            <strong>Heads up:</strong> we just sent a confirmation to your inbox, and your approval reply will follow there too. Small businesses sometimes land in spam or promotions, so if you don't see it within a few minutes, check those folders and mark it as not-spam so the approval doesn't get filtered.
        </div>
    </div>

    <form id="slw-application-form" class="slw-form" novalidate>
        <input type="hidden" name="action" value="slw_submit_application" />
        <input type="hidden" name="slw_nonce" value="<?php echo esc_attr( $nonce ); ?>" />

        <!-- Honeypot: hidden from real users, bots will fill it. -->
        <div style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;" aria-hidden="true">
            <label for="slw_hp_confirm">Leave this empty</label>
            <input type="text" name="slw_hp_confirm" id="slw_hp_confirm" tabindex="-1" autocomplete="new-password" />
        </div>

        <!-- Progress bar + step markers -->
        <div class="slw-progress" aria-hidden="true">
            <div class="slw-progress-bar">
                <div class="slw-progress-fill" style="width: 33.33%;"></div>
            </div>
            <div class="slw-progress-steps">
                <div class="slw-progress-step is-active" data-step-marker="1">
                    <span class="slw-progress-dot">1</span>
                    <span class="slw-progress-label">About Your Business</span>
                </div>
                <div class="slw-progress-step" data-step-marker="2">
                    <span class="slw-progress-dot">2</span>
                    <span class="slw-progress-label">Contact Info</span>
                </div>
                <div class="slw-progress-step" data-step-marker="3">
                    <span class="slw-progress-dot">3</span>
                    <span class="slw-progress-label">The Details</span>
                </div>
            </div>
        </div>

        <!-- STEP 1: About Your Business -->
        <div class="slw-step is-active" data-step="1">
            <h3 class="slw-step-title">About Your Business</h3>
            <p class="slw-step-subtitle">Tell us a bit about the shop.</p>

            <div class="slw-form-row">
                <div class="slw-form-field">
                    <label for="slw_business_name">Business Name <span class="required">*</span></label>
                    <input type="text" id="slw_business_name" name="business_name" required />
                </div>
            </div>

            <div class="slw-form-row">
                <div class="slw-form-field">
                    <label for="slw_contact_name">Your Name <span class="required">*</span></label>
                    <input type="text" id="slw_contact_name" name="contact_name" required />
                </div>
            </div>

            <div class="slw-form-row">
                <div class="slw-form-field">
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
            </div>

            <div class="slw-form-row">
                <div class="slw-form-field">
                    <label for="slw_website">Website or Social Handle</label>
                    <input type="text" id="slw_website" name="website" placeholder="yourstore.com or @yourstore" />
                    <small class="slw-field-hint">Optional. A website, Instagram handle, or Facebook page all work.</small>
                </div>
            </div>

            <div class="slw-step-nav">
                <button type="button" class="slw-btn slw-btn-cta" data-slw-next="2">Continue &rarr;</button>
            </div>
        </div>

        <!-- STEP 2: Contact Info -->
        <div class="slw-step" data-step="2">
            <h3 class="slw-step-title">Contact Info</h3>
            <?php
            $contact_owner = class_exists( 'SLW_Email_Settings' ) ? SLW_Email_Settings::get( 'owner_name' ) : '';
            $contact_label = $contact_owner ? sprintf( 'How can %s reach you?', esc_html( $contact_owner ) ) : 'How can we reach you?';
            ?>
            <p class="slw-step-subtitle"><?php echo $contact_label; ?></p>

            <div class="slw-form-row">
                <div class="slw-form-field">
                    <label for="slw_email">Email <span class="required">*</span></label>
                    <input type="email" id="slw_email" name="email" required />
                </div>
            </div>

            <div class="slw-form-row">
                <div class="slw-form-field">
                    <label for="slw_phone">Phone <span class="required">*</span></label>
                    <input type="tel" id="slw_phone" name="phone" required />
                </div>
            </div>

            <div class="slw-form-row">
                <div class="slw-form-field">
                    <label for="slw_address">Business Address <span class="required">*</span></label>
                    <textarea id="slw_address" name="address" rows="3" required placeholder="Street, City, State, ZIP"></textarea>
                </div>
            </div>

            <div class="slw-step-nav">
                <button type="button" class="slw-btn slw-btn-ghost" data-slw-prev="1">&larr; Back</button>
                <button type="button" class="slw-btn slw-btn-cta" data-slw-next="3">Continue &rarr;</button>
            </div>
        </div>

        <!-- STEP 3: The Details -->
        <div class="slw-step" data-step="3">
            <h3 class="slw-step-title">The Details</h3>
            <p class="slw-step-subtitle">Last step. Resale info plus a couple of friendly questions.</p>

            <div class="slw-form-row">
                <div class="slw-form-field">
                    <label for="slw_ein">EIN / Resale Certificate Number <span class="required">*</span></label>
                    <input type="text" id="slw_ein" name="ein" required />
                </div>
            </div>

            <div class="slw-form-row">
                <div class="slw-form-field">
                    <label for="slw_how_heard">How did you hear about us?</label>
                    <input type="text" id="slw_how_heard" name="how_heard" />
                </div>
            </div>

            <div class="slw-form-row">
                <div class="slw-form-field">
                    <label for="slw_why_carry">Why do you want to carry <?php echo esc_html( get_bloginfo( 'name' ) ); ?>?</label>
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

            <div class="slw-step-nav">
                <button type="button" class="slw-btn slw-btn-ghost" data-slw-prev="2">&larr; Back</button>
                <button type="submit" class="slw-btn slw-btn-cta slw-btn-submit" id="slw-submit-btn">Become a Wholesale Partner</button>
            </div>
        </div>
    </form>
</div>

<script>
(function() {
    var form = document.getElementById('slw-application-form');
    if (!form) return;

    var steps     = form.querySelectorAll('.slw-step');
    var markers   = form.querySelectorAll('.slw-progress-step');
    var fill      = form.querySelector('.slw-progress-fill');
    var errorEl   = document.getElementById('slw-form-error');
    var submitBtn = document.getElementById('slw-submit-btn');
    var total     = steps.length;

    function clearFieldErrors(stepEl) {
        stepEl.querySelectorAll('.slw-field-error').forEach(function(el) {
            el.classList.remove('slw-field-error');
        });
    }

    function validateStep(stepEl) {
        var valid = true;
        var firstInvalid = null;
        clearFieldErrors(stepEl);

        stepEl.querySelectorAll('[required]').forEach(function(field) {
            var v = (field.value || '').trim();
            var isCheckbox = field.type === 'checkbox';
            var ok = isCheckbox ? field.checked : v.length > 0;

            // Basic email format check
            if (ok && field.type === 'email') {
                ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
            }

            if (!ok) {
                valid = false;
                var wrapper = field.closest('.slw-form-field') || field.parentNode;
                wrapper.classList.add('slw-field-error');
                if (!firstInvalid) firstInvalid = field;
            }
        });

        if (!valid && firstInvalid) {
            firstInvalid.focus();
        }
        return valid;
    }

    function showStep(n) {
        steps.forEach(function(s) {
            s.classList.toggle('is-active', parseInt(s.dataset.step, 10) === n);
        });
        markers.forEach(function(m) {
            var sn = parseInt(m.dataset.stepMarker, 10);
            m.classList.toggle('is-active', sn === n);
            m.classList.toggle('is-complete', sn < n);
        });
        if (fill) {
            fill.style.width = ((n / total) * 100) + '%';
        }
        // Scroll to top of form for a clean handoff between steps
        var topY = form.getBoundingClientRect().top + window.pageYOffset - 40;
        window.scrollTo({ top: Math.max(topY, 0), behavior: 'smooth' });
    }

    // Next buttons
    form.querySelectorAll('[data-slw-next]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var currentStep = btn.closest('.slw-step');
            if (!validateStep(currentStep)) return;
            var target = parseInt(btn.getAttribute('data-slw-next'), 10);
            showStep(target);
        });
    });

    // Back buttons
    form.querySelectorAll('[data-slw-prev]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = parseInt(btn.getAttribute('data-slw-prev'), 10);
            showStep(target);
        });
    });

    // Live: clear field-level error as user types
    form.querySelectorAll('input, select, textarea').forEach(function(field) {
        field.addEventListener('input', function() {
            var wrapper = field.closest('.slw-form-field');
            if (wrapper) wrapper.classList.remove('slw-field-error');
        });
        field.addEventListener('change', function() {
            var wrapper = field.closest('.slw-form-field');
            if (wrapper) wrapper.classList.remove('slw-field-error');
        });
    });

    // Final submit
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var lastStep = form.querySelector('.slw-step[data-step="3"]');
        if (!validateStep(lastStep)) return;

        errorEl.style.display = 'none';
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';

        var formData = new FormData(form);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '<?php echo esc_js( $ajax_url ); ?>');
        xhr.onload = function() {
            var resp;
            try { resp = JSON.parse(xhr.responseText); } catch(e) { resp = null; }

            if (xhr.status === 200 && resp && resp.success) {
                form.style.display = 'none';
                var success = document.getElementById('slw-form-success');
                success.style.display = 'block';
                success.scrollIntoView({ behavior: 'smooth' });
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Something went wrong. Please try again.';
                errorEl.textContent = msg;
                errorEl.style.display = 'block';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Become a Wholesale Partner';
            }
        };
        xhr.onerror = function() {
            errorEl.textContent = 'Network error. Please check your connection and try again.';
            errorEl.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Become a Wholesale Partner';
        };
        xhr.send(formData);
    });
})();
</script>
