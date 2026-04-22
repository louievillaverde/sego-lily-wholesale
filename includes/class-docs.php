<?php
/**
 * In-Plugin Documentation
 *
 * Searchable 2-column docs browser with articles organized by topic.
 * Articles are stored as PHP arrays — no database, no external deps.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Docs {

    public static function init() {
        // Menu registered centrally by SLW_Admin_Menu.
    }

    /* ----------------------------------------------------------
     * Topics
     * ---------------------------------------------------------- */

    public static function get_topics() {
        return array(
            'getting-started'    => 'Getting Started',
            'pricing-discounts'  => 'Pricing & Discounts',
            'applications'       => 'Applications & Onboarding',
            'orders-payments'    => 'Orders & Payments',
            'emails-comms'       => 'Emails & Communications',
            'advanced'           => 'Advanced Features',
            'invoices-linesheets'=> 'Invoices & Line Sheets',
            'troubleshooting'    => 'Troubleshooting',
        );
    }

    /* ----------------------------------------------------------
     * Articles
     * ---------------------------------------------------------- */

    public static function get_articles() {
        return array(

            // =====================================================
            // TOPIC 1: Getting Started
            // =====================================================

            array(
                'slug'  => 'first-time-setup',
                'topic' => 'getting-started',
                'title' => 'First-Time Setup',
                'content' => '<p>After activating <strong>Wholesale Portal</strong>, the plugin automatically creates several pages on your site: <em>Wholesale Partners</em> (application form), <em>Wholesale Order Form</em>, <em>My Wholesale Account</em> (customer dashboard), <em>Request a Quote</em>, and <em>Become a Wholesale Partner</em> (lead capture). A new <strong>Wholesale Customer</strong> user role is also registered.</p>
<h4>Step 1 — Configure Core Settings</h4>
<p>Go to <strong>Wholesale → Settings</strong>. Set your default wholesale discount percentage, first-order minimum, and reorder minimum. These three values control the baseline behavior for every wholesale customer.</p>
<h4>Step 2 — Review Your Application Page</h4>
<p>Visit the <em>Wholesale Partners</em> page on the front end to see the 3-step application wizard. If you use Elementor, you can edit the surrounding page content while leaving the <code>[sego_wholesale_application]</code> shortcode in place.</p>
<h4>Step 3 — Branding (Optional)</h4>
<p>If the Invoices module is active, go to <strong>Wholesale → Invoices</strong> to upload your logo and set header/footer text for PDF invoices.</p>
<h4>Step 4 — Test</h4>
<p>Submit a test application, approve it from <strong>Wholesale → Applications</strong>, then log in as that user to confirm pricing and ordering work correctly. See the <em>Testing the Full Flow</em> article for a detailed walkthrough.</p>',
            ),

            array(
                'slug'  => 'configuring-discount',
                'topic' => 'getting-started',
                'title' => 'Configuring Your Discount',
                'content' => '<p>Wholesale Portal supports a layered discount system so you can offer different pricing to different customers and products.</p>
<h4>Global Discount</h4>
<p>Go to <strong>Wholesale → Settings</strong> and set the <em>Default Wholesale Discount (%)</em>. This percentage is applied to all products for every wholesale customer unless overridden at a more specific level. For example, setting 50% means wholesale customers pay half of the retail price.</p>
<h4>Per-Category Overrides</h4>
<p>Under <strong>Wholesale → Settings → Category Discounts</strong>, you can assign a different discount percentage to individual product categories. A category override always takes priority over the global discount.</p>
<h4>Per-Product Overrides</h4>
<p>Edit any WooCommerce product and scroll to the <strong>Wholesale Pricing</strong> panel. Enter a fixed wholesale price or a product-specific discount percentage. Per-product pricing has the highest priority and overrides both category and global discounts.</p>
<h4>Tier-Based Discounts</h4>
<p>If you use the Tiers module (<strong>Wholesale → Tiers</strong>), each tier can define its own default discount. When a customer is assigned to a tier, the tier discount replaces the global discount — but per-category and per-product overrides still win.</p>',
            ),

            array(
                'slug'  => 'order-minimums',
                'topic' => 'getting-started',
                'title' => 'Order Minimums',
                'content' => '<p>Order minimums ensure that wholesale orders meet a minimum dollar value before checkout can complete. The plugin distinguishes between a customer\'s <strong>first order</strong> and all <strong>subsequent reorders</strong>.</p>
<h4>Setting Minimums</h4>
<p>Go to <strong>Wholesale → Settings</strong>. You will see two fields:</p>
<ul>
<li><strong>First Order Minimum</strong> — the dollar threshold a new wholesale customer must meet on their very first order. This is typically higher (e.g., $300) to ensure a meaningful opening purchase.</li>
<li><strong>Reorder Minimum</strong> — the threshold for every order after the first. Set this to <code>0</code> to allow reorders of any amount.</li>
</ul>
<h4>How Enforcement Works</h4>
<p>When a wholesale customer views their cart, the plugin checks whether the cart subtotal (at wholesale prices) meets the applicable minimum. If it does not, a notice is displayed and the <em>Proceed to Checkout</em> button is disabled. The customer must add more items before they can complete the order.</p>
<p>The plugin automatically detects whether the customer has placed a previous WooCommerce order to determine which minimum applies. Admins can also set per-user minimums from the user profile screen.</p>',
            ),

            array(
                'slug'  => 'application-form',
                'topic' => 'getting-started',
                'title' => 'The Application Form',
                'content' => '<p>The wholesale application form is the primary way new customers request access to your wholesale program. It lives on the <em>Wholesale Partners</em> page using the <code>[sego_wholesale_application]</code> shortcode.</p>
<h4>3-Step Wizard</h4>
<p>The form guides applicants through three steps:</p>
<ol>
<li><strong>Business Information</strong> — business name, contact name, email, phone, and mailing address.</li>
<li><strong>Business Details</strong> — EIN / tax ID, business type (retailer, salon, spa, etc.), website URL, and how they heard about you.</li>
<li><strong>Review & Submit</strong> — the applicant reviews all entered information and submits.</li>
</ol>
<h4>Elementor Integration</h4>
<p>If you use Elementor, edit the <em>Wholesale Partners</em> page, place a <strong>Shortcode</strong> widget, and paste <code>[sego_wholesale_application]</code>. You can add branding, hero images, and trust badges around the form using normal Elementor sections.</p>
<h4>After Submission</h4>
<p>A new row appears in <strong>Wholesale → Applications</strong> with a <em>Pending</em> status. The applicant receives a confirmation email, and a webhook fires (if configured in Settings) so you can connect to your CRM or Slack.</p>',
            ),

            array(
                'slug'  => 'testing-flow',
                'topic' => 'getting-started',
                'title' => 'Testing the Full Flow',
                'content' => '<p>Before launching your wholesale program, walk through the complete flow end-to-end. Use a separate browser or incognito window so you are not logged in as an admin.</p>
<h4>Step 1 — Submit a Test Application</h4>
<p>Visit your <em>Wholesale Partners</em> page and fill out the 3-step application form using a test email address. Confirm you receive the submission confirmation email.</p>
<h4>Step 2 — Approve the Application</h4>
<p>In your admin panel, go to <strong>Wholesale → Applications</strong>. Find the test application and click <strong>Approve</strong>. This creates a WordPress user account with the <em>Wholesale Customer</em> role and sends the applicant their login credentials.</p>
<h4>Step 3 — Log In as the Test User</h4>
<p>Log in with the test credentials. Navigate to the <em>Wholesale Order Form</em> page. Verify that:</p>
<ul>
<li>Product prices show the wholesale discount</li>
<li>The order minimum notice appears if your cart is below the threshold</li>
<li>Adding enough products clears the minimum and enables checkout</li>
</ul>
<h4>Step 4 — Place a Test Order</h4>
<p>Complete the checkout. Confirm the order appears in <strong>WooCommerce → Orders</strong> and in <strong>Wholesale → Orders</strong>. If invoicing is enabled, verify a PDF invoice is generated.</p>
<h4>Step 5 — Clean Up</h4>
<p>Delete or decline the test application and optionally remove the test user account from <strong>Users</strong>.</p>',
            ),

            // =====================================================
            // TOPIC 2: Pricing & Discounts
            // =====================================================

            array(
                'slug'  => 'how-pricing-works',
                'topic' => 'pricing-discounts',
                'title' => 'How Pricing Works',
                'content' => '<p>Wholesale Portal uses a 4-tier pricing resolution system. When a wholesale customer views a product, the plugin checks for a price override at each level — the most specific match wins.</p>
<h4>Resolution Order (Highest to Lowest Priority)</h4>
<ol>
<li><strong>Per-Product Price</strong> — a fixed wholesale price set directly on the product. This always wins.</li>
<li><strong>Category Discount</strong> — a percentage discount assigned to the product\'s category in <strong>Wholesale → Settings → Category Discounts</strong>.</li>
<li><strong>Tier Discount</strong> — the default discount defined on the customer\'s assigned tier in <strong>Wholesale → Tiers</strong>.</li>
<li><strong>Global Discount</strong> — the site-wide default wholesale discount set in <strong>Wholesale → Settings</strong>.</li>
</ol>
<h4>Example</h4>
<p>Global discount is 40%. The "Candles" category has a 50% category discount. Product "Rose Candle" has a fixed wholesale price of $8.00 (retail $20). A Gold-tier customer (55% tier discount) adds Rose Candle to cart:</p>
<ul>
<li>Per-product price exists ($8.00) → <strong>$8.00 is used</strong>.</li>
<li>If the per-product price were removed, the category discount (50%) would apply → $10.00.</li>
<li>If the category discount were also removed, the tier discount (55%) would apply → $9.00.</li>
<li>If no tier were assigned, the global discount (40%) would apply → $12.00.</li>
</ul>
<p>This layered system lets you control pricing broadly or with surgical precision.</p>',
            ),

            array(
                'slug'  => 'per-product-prices',
                'topic' => 'pricing-discounts',
                'title' => 'Per-Product Wholesale Prices',
                'content' => '<p>For products that need a specific wholesale price rather than a percentage discount, you can set a fixed per-product wholesale price.</p>
<h4>Setting a Per-Product Price</h4>
<ol>
<li>Go to <strong>Products</strong> in your WordPress admin and edit the product.</li>
<li>Scroll down to the <strong>Product Data</strong> meta box.</li>
<li>Click the <strong>Wholesale Pricing</strong> tab (look for the wholesale icon).</li>
<li>Enter your desired wholesale price in the <em>Wholesale Price</em> field.</li>
<li>Click <strong>Update</strong> to save.</li>
</ol>
<h4>Variable Products</h4>
<p>For variable products, you can set a wholesale price on each individual variation. Go to the <strong>Variations</strong> tab, expand the variation, and enter the wholesale price in the dedicated field. This lets you charge different wholesale prices for different sizes, colors, or configurations.</p>
<h4>When to Use Per-Product Pricing</h4>
<ul>
<li>Items with very thin margins where a percentage discount would be too generous</li>
<li>Loss-leader products you want to offer at a specific price point</li>
<li>Bundled or kitted products where the wholesale price does not follow the standard formula</li>
</ul>
<p>Remember: a per-product price always overrides category, tier, and global discounts for that product.</p>',
            ),

            array(
                'slug'  => 'category-discounts',
                'topic' => 'pricing-discounts',
                'title' => 'Category Discounts',
                'content' => '<p>Category discounts let you assign a unique wholesale discount percentage to an entire product category, overriding the global default for every product in that category.</p>
<h4>Setting Category Discounts</h4>
<ol>
<li>Go to <strong>Wholesale → Settings</strong>.</li>
<li>Scroll to the <strong>Category Discounts</strong> section.</li>
<li>You will see a list of your WooCommerce product categories. Enter a discount percentage next to any category you want to override.</li>
<li>Leave a category blank to use the global (or tier) discount for that category.</li>
<li>Click <strong>Save Changes</strong>.</li>
</ol>
<h4>How It Works</h4>
<p>When a wholesale customer views a product, the plugin checks whether the product\'s category has a specific discount. If it does, that percentage is applied instead of the global or tier discount. If a product belongs to multiple categories, the highest discount wins.</p>
<h4>Use Cases</h4>
<ul>
<li><strong>High-margin categories</strong> (e.g., accessories) can get a deeper discount to incentivize volume.</li>
<li><strong>Low-margin categories</strong> (e.g., electronics) can have a smaller discount to protect margins.</li>
<li><strong>Seasonal categories</strong> can be temporarily adjusted for promotions.</li>
</ul>
<p>Note: per-product prices still override category discounts. The hierarchy is always: per-product → category → tier → global.</p>',
            ),

            array(
                'slug'  => 'tiered-quantity-pricing',
                'topic' => 'pricing-discounts',
                'title' => 'Tiered Quantity Pricing',
                'content' => '<p>Tiered quantity pricing lets you offer better prices when a customer buys more units of a product — for example, "Buy 10+ for $5 each, Buy 25+ for $4 each."</p>
<h4>Setting Up Quantity Brackets</h4>
<ol>
<li>Edit a product in <strong>Products</strong>.</li>
<li>In the <strong>Wholesale Pricing</strong> tab, look for the <strong>Quantity Pricing</strong> section.</li>
<li>Add pricing brackets by specifying a minimum quantity and the corresponding unit price. For example:
<ul>
<li>1–9 units: $8.00 each</li>
<li>10–24 units: $6.50 each</li>
<li>25+ units: $5.00 each</li>
</ul>
</li>
<li>Save the product.</li>
</ol>
<h4>How It Displays</h4>
<p>When a wholesale customer views the product, they see a pricing table showing all available quantity brackets and the price per unit at each level. As the customer adjusts the quantity in their cart, the price automatically recalculates to the correct bracket.</p>
<h4>Interaction with Other Discounts</h4>
<p>Quantity-based pricing is applied <em>after</em> the wholesale price is determined. If a product has both a per-product wholesale price and quantity brackets, the brackets use the wholesale price as the starting point. This gives you maximum flexibility: set the base wholesale price, then reward volume with additional breaks.</p>',
            ),

            // =====================================================
            // TOPIC 3: Applications & Onboarding
            // =====================================================

            array(
                'slug'  => 'reviewing-applications',
                'topic' => 'applications',
                'title' => 'Reviewing Applications',
                'content' => '<p>All wholesale applications land in <strong>Wholesale → Applications</strong>. Each entry shows the business name, contact name, email, submission date, and current status.</p>
<h4>The Review Workflow</h4>
<ol>
<li>Click an application row to expand the full details — address, EIN, business type, website, and any additional notes the applicant provided.</li>
<li>Review the information. You can open the applicant\'s website in a new tab to verify legitimacy.</li>
<li>Click <strong>Approve</strong> or <strong>Decline</strong>.</li>
</ol>
<h4>What Happens on Approve</h4>
<ul>
<li>A WordPress user account is created with the <strong>Wholesale Customer</strong> role.</li>
<li>The customer receives a <em>Welcome Email</em> containing their username, a password-set link, and instructions for placing their first order.</li>
<li>If a webhook URL is configured in <strong>Wholesale → Settings</strong>, a JSON payload is sent with the new customer\'s details.</li>
<li>The application status changes to <em>Approved</em> with a timestamp.</li>
</ul>
<h4>What Happens on Decline</h4>
<ul>
<li>The application status changes to <em>Declined</em>.</li>
<li>No user account is created.</li>
<li>The applicant receives a polite decline email (if email notifications are enabled).</li>
</ul>
<p>You can filter the applications list by status (Pending, Approved, Declined) to quickly find items that need attention.</p>',
            ),

            array(
                'slug'  => 'welcome-emails',
                'topic' => 'applications',
                'title' => 'Welcome Emails',
                'content' => '<p>When you approve an application, the new wholesale customer automatically receives a welcome email with everything they need to start ordering.</p>
<h4>What the Email Contains</h4>
<ul>
<li>A personalized greeting using the customer\'s contact name</li>
<li>Their username (typically their email address)</li>
<li>A secure password-set link (WordPress native)</li>
<li>A link to the wholesale order form page</li>
<li>Your first-order minimum and any other key policies</li>
</ul>
<h4>Customizing the Email</h4>
<p>Go to <strong>Wholesale → Settings → Email</strong> to configure:</p>
<ul>
<li><strong>From Name</strong> — the sender name (e.g., "Sego Lily Wholesale")</li>
<li><strong>From Address</strong> — the reply-to email</li>
<li><strong>Email Signature</strong> — appended to the bottom of all wholesale emails</li>
</ul>
<p>The email template uses your WordPress site\'s default HTML email styling. For deeper customization, you can filter the email content using the <code>slw_welcome_email_content</code> filter hook in your theme\'s <code>functions.php</code>.</p>
<h4>Email Sequences</h4>
<p>If the Email Sequences module is active (<strong>Wholesale → Sequences</strong>), approved customers are automatically enrolled in an onboarding sequence that sends follow-up emails over the next few weeks to encourage their first order.</p>',
            ),

            array(
                'slug'  => 'lead-capture',
                'topic' => 'applications',
                'title' => 'Lead Capture',
                'content' => '<p>Not every visitor is ready to fill out a full wholesale application. The Lead Capture module provides a lightweight form to collect interested prospects\' contact information.</p>
<h4>Lightweight Form vs. Full Application</h4>
<ul>
<li><strong>Lead Capture</strong> — collects name, email, business name, and phone. No tax ID or address required. Best for trade shows, social media links, and top-of-funnel pages.</li>
<li><strong>Full Application</strong> — the complete 3-step wizard with business verification details. Best for serious applicants ready to open an account.</li>
</ul>
<h4>Setting Up Lead Capture</h4>
<p>Add the <code>[wholesale_lead_capture]</code> shortcode to any page. The plugin auto-creates a <em>Become a Wholesale Partner</em> page at <code>/wholesale-leads</code> on activation.</p>
<h4>Managing Leads</h4>
<p>View captured leads at <strong>Wholesale → Leads</strong>. Each lead shows name, email, business, source, and status (New, Contacted, Converted). You can:</p>
<ul>
<li>Update the status as you follow up</li>
<li>Add internal notes</li>
<li>Convert a lead to a full application with one click — this pre-fills the application form with the lead\'s info</li>
</ul>
<h4>Webhook Integration</h4>
<p>Leads also fire the webhook (if configured), so they can flow into your CRM, email marketing tool, or Slack channel automatically.</p>',
            ),

            array(
                'slug'  => 'bulk-importing',
                'topic' => 'applications',
                'title' => 'Bulk Importing Customers',
                'content' => '<p>If you already have wholesale customers in a spreadsheet, the Import tool lets you create their accounts in bulk via CSV upload.</p>
<h4>Accessing the Import Tool</h4>
<p>Go to <strong>Wholesale → Import</strong>. You will see two options: a single-customer quick-add form and a CSV bulk import section.</p>
<h4>CSV Format</h4>
<p>Your CSV file should include the following columns:</p>
<ul>
<li><strong>email</strong> (required) — the customer\'s email address, used as their username</li>
<li><strong>first_name</strong> (required) — first name</li>
<li><strong>last_name</strong> (required) — last name</li>
<li><strong>business_name</strong> (optional) — their company name</li>
<li><strong>phone</strong> (optional) — phone number</li>
<li><strong>tier</strong> (optional) — the slug of a tier to assign (e.g., <code>gold</code>)</li>
</ul>
<h4>What Happens During Import</h4>
<ol>
<li>Each row creates a WordPress user with the <strong>Wholesale Customer</strong> role.</li>
<li>A random secure password is generated for each user.</li>
<li>If the <em>Send Welcome Emails</em> checkbox is checked, each user receives their login credentials and a password-reset link.</li>
<li>If a tier is specified and exists, the user is assigned to that tier.</li>
<li>Duplicate emails (users that already exist) are skipped with a warning — no data is overwritten.</li>
</ol>
<p>After import, a summary shows how many users were created, skipped, and emailed.</p>',
            ),

            // =====================================================
            // TOPIC 4: Orders & Payments
            // =====================================================

            array(
                'slug'  => 'order-minimums-explained',
                'topic' => 'orders-payments',
                'title' => 'Order Minimums Explained',
                'content' => '<p>Order minimums protect your margins by ensuring every wholesale order meets a minimum dollar value. The plugin supports two separate thresholds.</p>
<h4>First Order Minimum</h4>
<p>This is the minimum cart subtotal (at wholesale prices) required for a customer\'s very first order. It is typically set higher — for example, $300 — to ensure an opening order is worth the fulfillment cost. Configure this at <strong>Wholesale → Settings → First Order Minimum</strong>.</p>
<h4>Reorder Minimum</h4>
<p>This applies to every order after the first. It can be the same as the first-order minimum, lower, or set to <code>$0</code> to allow reorders of any size. Configure this at <strong>Wholesale → Settings → Reorder Minimum</strong>.</p>
<h4>How the Plugin Detects First vs. Reorder</h4>
<p>The plugin queries WooCommerce for completed orders by the current user. If zero completed orders exist, the first-order minimum is enforced. Once at least one order has been completed, all future orders use the reorder minimum.</p>
<h4>Cart Enforcement</h4>
<p>When the cart subtotal is below the applicable minimum:</p>
<ul>
<li>A prominent notice shows the remaining amount needed (e.g., "Add $45.00 more to meet the $300 minimum").</li>
<li>The <em>Proceed to Checkout</em> button is hidden or disabled.</li>
<li>Once the customer adds enough products, the notice disappears and checkout is enabled.</li>
</ul>',
            ),

            array(
                'slug'  => 'net-payment-terms',
                'topic' => 'orders-payments',
                'title' => 'NET Payment Terms',
                'content' => '<p>NET payment terms allow trusted wholesale customers to place orders now and pay later — typically NET 30, NET 60, or NET 90 days.</p>
<h4>Enabling NET Terms</h4>
<ol>
<li>Go to <strong>Wholesale → Settings</strong>.</li>
<li>In the <strong>Payment Terms</strong> section, check <em>Enable NET Payment Terms</em>.</li>
<li>Select the default NET period (30, 60, or 90 days).</li>
<li>Save changes.</li>
</ol>
<h4>Per-User Terms</h4>
<p>You can override the default NET period for individual customers. Edit the user in <strong>Users → Edit</strong>, scroll to the <strong>Wholesale Settings</strong> section, and set a custom NET period. This is useful for long-standing customers who have earned extended terms.</p>
<h4>Checkout Flow</h4>
<p>When a customer with NET terms enabled reaches checkout, they see a <em>NET 30</em> (or 60/90) payment option alongside any other active payment gateways. Selecting it places the order with a status of <em>On Account</em> — no payment is collected at checkout.</p>
<h4>Invoicing & Follow-Up</h4>
<p>An invoice is generated with the due date calculated from the order date plus the NET period. If the Reminders module is active, automated payment reminder emails are sent as the due date approaches and after it passes.</p>',
            ),

            array(
                'slug'  => 'tax-exemption',
                'topic' => 'orders-payments',
                'title' => 'Tax Exemption',
                'content' => '<p>Many wholesale buyers are resellers who should not be charged sales tax on their purchases. Wholesale Portal provides two ways to handle tax exemption.</p>
<h4>Option 1 — Global Tax Exemption</h4>
<p>Go to <strong>Wholesale → Settings</strong> and enable <em>Exempt all wholesale customers from tax</em>. When this toggle is on, any user with the <strong>Wholesale Customer</strong> role will see zero tax applied at checkout. This is the simplest option if all your wholesale customers are tax-exempt.</p>
<h4>Option 2 — Per-User Tax Exemption</h4>
<p>If only some wholesale customers are tax-exempt, leave the global toggle off. Instead, edit individual users in <strong>Users → Edit</strong> and check the <em>Tax Exempt</em> box in the Wholesale Settings section. You can also upload or note their resale certificate number in the same area for your records.</p>
<h4>How It Works Technically</h4>
<p>The plugin hooks into WooCommerce\'s <code>woocommerce_customer_taxable_address</code> and <code>woocommerce_apply_base_tax_for_local_pickup</code> filters. When a wholesale customer is marked as tax-exempt (either globally or individually), the plugin tells WooCommerce to skip tax calculation entirely for that session.</p>
<h4>Resale Certificates</h4>
<p>For compliance, you can require applicants to provide a resale certificate number during the application process. This value is stored on the user profile and visible in the admin. Consult your accountant or state tax authority for your specific obligations.</p>',
            ),

            // =====================================================
            // TOPIC 5: Emails & Communications
            // =====================================================

            array(
                'slug'    => 'email-overview',
                'topic'   => 'emails-comms',
                'title'   => 'How Emails Work in Wholesale Portal',
                'content' => '<p>The plugin sends <strong>transactional emails</strong> directly via WordPress (using your SMTP configuration) for immediate, one-off notifications. <strong>Campaign sequences</strong> (multi-email flows) are handled by your connected email provider (Mautic, Mailchimp, etc.) triggered by webhooks.</p><h4>Transactional Emails (WordPress)</h4><ul><li><strong>Welcome email</strong> — sent on application approval with login credentials</li><li><strong>Decline email</strong> — polite rejection on application decline</li><li><strong>Admin notification</strong> — alerts you when a new application is submitted</li><li><strong>Quote response</strong> — sent when you respond to an RFQ</li><li><strong>Invoice email</strong> — when you click "Send Invoice" on an order</li></ul><h4>Campaign Sequences (Email Provider)</h4><ul><li><strong>Onboarding sequence</strong> — triggered by <code>wholesale-approved</code> webhook</li><li><strong>First order follow-up</strong> — triggered by <code>first-order-placed</code> webhook</li><li><strong>Reorder reminders</strong> — triggered by <code>reorder-reminder</code> webhook at 45/75/120 days</li></ul><p>View all sequence stats in <strong>Wholesale → Sequences</strong>.</p>',
            ),
            array(
                'slug'    => 'white-label-emails',
                'topic'   => 'emails-comms',
                'title'   => 'Customizing Email Branding (White-Label)',
                'content' => '<p>All transactional emails use configurable sender details so they match your brand.</p><h4>How to Configure</h4><ol><li>Go to <strong>Wholesale → Invoices</strong></li><li>Scroll to the <strong>Email Settings</strong> section</li><li>Set your <strong>From Name</strong> (e.g., "Sego Lily Skincare")</li><li>Set your <strong>From Address</strong> (e.g., wholesale@yourdomain.com)</li><li>Set your <strong>Reply-To</strong> address</li><li>Set the <strong>Owner Name</strong> — this personalizes emails ("Holly reviews applications" vs "We review applications")</li><li>Set your <strong>Signature</strong> — appears at the bottom of every email</li><li>Save</li></ol><p>If left blank, From Name defaults to your WordPress site name and From Address defaults to your admin email.</p>',
            ),
            array(
                'slug'    => 'connecting-email-provider',
                'topic'   => 'emails-comms',
                'title'   => 'Connecting Your Email Provider',
                'content' => '<p>The Sequences dashboard pulls live campaign stats from your email provider and gives you deep links to edit emails without leaving WordPress.</p><h4>Setup</h4><ol><li>Go to <strong>Wholesale → Sequences</strong></li><li>Select your <strong>Provider</strong> from the dropdown (Mautic, Mailchimp, ActiveCampaign, Klaviyo, ConvertKit, or Webhook Only)</li><li>Enter your API credentials</li><li>Click <strong>Test Connection</strong> to verify</li><li>Save</li></ol><p>Once connected, you\'ll see your campaigns, email stats (sent, open rate, click rate), and "Edit" buttons that open the email directly in your provider.</p><p><strong>Webhook Only mode:</strong> If your provider isn\'t listed, select "Webhook Only." Webhooks fire to your configured URL regardless — you just won\'t see campaign stats in the dashboard.</p>',
            ),
            array(
                'slug'    => 'reorder-reminders',
                'topic'   => 'emails-comms',
                'title'   => 'Automated Reorder Reminders',
                'content' => '<p>The plugin checks daily if any wholesale customer hasn\'t ordered in a while and fires a webhook to trigger a reminder email from your email provider.</p><h4>How It Works</h4><ul><li>A daily cron job checks each wholesale customer\'s last completed order date</li><li>At <strong>45 days</strong>, <strong>75 days</strong>, and <strong>120 days</strong> without an order, a webhook fires</li><li>Each level only fires once (no duplicate reminders)</li><li>When a customer places a new order, the counter resets</li><li>Brand new customers who haven\'t ordered yet are skipped entirely</li></ul><h4>Configuration</h4><p>The day thresholds are configurable in <strong>Wholesale → Settings</strong> under the Reorder Reminders section. You can also disable reminders entirely with the toggle.</p>',
            ),

            // =====================================================
            // TOPIC 6: Advanced Features
            // =====================================================

            array(
                'slug'    => 'wholesale-tiers',
                'topic'   => 'advanced',
                'title'   => 'Setting Up Wholesale Tiers',
                'content' => '<p>Tiers let you reward your best wholesale partners with better pricing as they grow. The plugin supports multiple tiers with automatic upgrades.</p><h4>Default Tiers</h4><ul><li><strong>Standard</strong> — default for new partners, uses the global discount %</li><li><strong>Preferred</strong> — unlocked after 3+ orders or $1,500+ lifetime spend, +5% discount</li><li><strong>VIP</strong> — unlocked after 10+ orders or $5,000+ lifetime spend, +10% discount</li></ul><h4>Configuration</h4><ol><li>Go to <strong>Wholesale → Tiers</strong></li><li>Adjust tier names, discount percentages, and upgrade thresholds</li><li>Save</li></ol><p>Tiers upgrade automatically when a customer completes an order that crosses the threshold. Admins can also manually set a customer\'s tier from their user profile.</p>',
            ),
            array(
                'slug'    => 'automatic-tier-upgrades',
                'topic'   => 'advanced',
                'title'   => 'Automatic Tier Upgrades',
                'content' => '<p>When a wholesale customer completes an order, the plugin automatically checks if they now qualify for a higher tier based on two criteria (whichever is met first):</p><ul><li><strong>Order count</strong> — total completed wholesale orders</li><li><strong>Lifetime spend</strong> — total revenue from completed orders</li></ul><p>Example: If Preferred requires 3 orders OR $1,500 spend, a customer who places their 3rd order (even if total spend is only $900) gets upgraded to Preferred.</p><h4>What Happens on Upgrade</h4><ul><li>The customer\'s tier meta is updated</li><li>Their discount % changes immediately on next page load</li><li>The upgrade is recorded in their tier history (visible on their user profile)</li><li>A <code>slw_tier_upgraded</code> action fires for webhook integration</li></ul><p>Tier upgrades are one-directional — customers don\'t get downgraded automatically. Admins can manually change tiers from the user profile.</p>',
            ),
            array(
                'slug'    => 'product-visibility',
                'topic'   => 'advanced',
                'title'   => 'Product Visibility by Tier',
                'content' => '<p>You can restrict specific products to certain wholesale tiers. A product marked "VIP only" won\'t appear in the catalog for Standard or Preferred customers.</p><h4>How to Set It</h4><ol><li>Go to <strong>Products → Edit</strong> on the product</li><li>In the General tab, find <strong>Visible to Wholesale Tiers</strong></li><li>Select which tiers can see this product (leave empty for all tiers)</li><li>Update the product</li></ol><p>Retail customers always see all products — this visibility control only applies within the wholesale tier system.</p>',
            ),
            array(
                'slug'    => 'per-product-minimums',
                'topic'   => 'advanced',
                'title'   => 'Per-Product Minimum Quantities',
                'content' => '<p>Set a minimum wholesale quantity on individual products. Useful for items that only make sense to sell in bulk (e.g., "minimum 6 units").</p><h4>How to Set It</h4><ol><li>Go to <strong>Products → Edit</strong> on the product</li><li>In the General tab, find <strong>Min. Wholesale Qty</strong></li><li>Enter the minimum (e.g., 6)</li><li>Update</li></ol><h4>How It\'s Enforced</h4><ul><li>The quantity stepper on the order form starts at the minimum</li><li>If a customer tries to check out with less than the minimum, they get a clear error message</li><li>The minimum is shown next to the product on the order form</li></ul><p>Leave the field empty for no minimum (customer can order any quantity).</p>',
            ),

            // =====================================================
            // TOPIC 7: Invoices & Line Sheets
            // =====================================================

            array(
                'slug'    => 'customizing-invoices',
                'topic'   => 'invoices-linesheets',
                'title'   => 'Customizing Your Invoices',
                'content' => '<p>The plugin generates clean, print-ready invoices for every WooCommerce order. Customize them to match your brand.</p><h4>Invoice Settings</h4><p>Go to <strong>Wholesale → Invoices</strong> and configure:</p><ul><li><strong>Logo</strong> — upload via WordPress media library</li><li><strong>Business Name, Address, Phone, Email</strong> — appears on every invoice</li><li><strong>Accent Color</strong> — color picker for headers and accents</li><li><strong>Invoice Number Prefix</strong> — e.g., "SLW-" produces "SLW-1234"</li><li><strong>Footer Text</strong> — e.g., "Thank you for your business!"</li><li><strong>Payment Terms Note</strong> — shown on NET invoices</li></ul><p>Use the <strong>Preview</strong> at the bottom of the page to see how your invoice looks before sending it to a customer.</p>',
            ),
            array(
                'slug'    => 'sending-invoices',
                'topic'   => 'invoices-linesheets',
                'title'   => 'Sending Invoices to Customers',
                'content' => '<p>Every WooCommerce order has an invoice that can be viewed, printed, or emailed.</p><h4>From the Order Screen</h4><ol><li>Go to <strong>WooCommerce → Orders</strong> and open an order</li><li>In the <strong>Invoice</strong> metabox, click <strong>View Invoice</strong> to preview</li><li>Click <strong>Send Invoice to Customer</strong> to email the invoice link</li></ol><h4>From My Account (Customer Side)</h4><p>Wholesale customers see an "Invoice" button next to each order in their My Account → Orders table. Clicking it opens the print-ready invoice.</p><h4>Printing / PDF</h4><p>The invoice opens as a clean HTML page. Click the <strong>Print / Save as PDF</strong> button (or press Ctrl+P / Cmd+P) to save or print it.</p>',
            ),
            array(
                'slug'    => 'price-list-linesheet',
                'topic'   => 'invoices-linesheets',
                'title'   => 'Downloading the Price List / Line Sheet',
                'content' => '<p>The line sheet is a print-ready product catalog showing all wholesale products with images, SKUs, retail prices (strikethrough), and wholesale prices. Grouped by category.</p><h4>Who Can Access It</h4><p>Only logged-in wholesale customers. Retail visitors are redirected.</p><h4>Where to Find It</h4><ul><li><strong>Admin:</strong> Wholesale → Dashboard → "Download Line Sheet" quick action</li><li><strong>Customer:</strong> The wholesale dashboard has a download link</li></ul><p>The line sheet uses your invoice branding settings (logo, business name, accent color) for a consistent look.</p>',
            ),

            // =====================================================
            // TOPIC 8: Troubleshooting
            // =====================================================

            array(
                'slug'    => 'form-not-showing',
                'topic'   => 'troubleshooting',
                'title'   => 'Application Form Not Showing',
                'content' => '<p>If the application form doesn\'t appear on your wholesale page, check:</p><ol><li><strong>Shortcode present:</strong> Edit the page and verify it contains <code>[sego_wholesale_application]</code></li><li><strong>Elementor:</strong> If using Elementor, place the shortcode inside a <strong>Shortcode widget</strong>, not a Text widget</li><li><strong>Theme conflict:</strong> Try switching to a default theme (Twenty Twenty-Four) temporarily to rule out theme CSS/JS conflicts</li><li><strong>Plugin conflict:</strong> Deactivate other form plugins (Contact Form 7, WPForms, etc.) temporarily to check for conflicts</li><li><strong>Caching:</strong> Clear any caching plugin cache and browser cache</li></ol>',
            ),
            array(
                'slug'    => 'retail-prices-showing',
                'topic'   => 'troubleshooting',
                'title'   => 'Customer Seeing Retail Prices',
                'content' => '<p>If a wholesale customer sees retail prices instead of wholesale prices:</p><ol><li><strong>Check their role:</strong> Go to Users → edit the customer → verify they have the <code>wholesale_customer</code> role (under the Wholesale Portal section, "Wholesale Status" should be checked)</li><li><strong>Check they\'re logged in:</strong> Have them visit the site in a fresh browser and log in at <code>/my-account</code></li><li><strong>Per-product override:</strong> Check if the specific product has a custom wholesale price set to $0 or blank by mistake (Products → Edit → General tab → Wholesale Price)</li><li><strong>Caching:</strong> Page caching plugins can serve cached retail prices to wholesale users. Add wholesale users to your caching plugin\'s exclusion rules.</li></ol>',
            ),
            array(
                'slug'    => 'net-terms-missing',
                'topic'   => 'troubleshooting',
                'title'   => 'NET Terms Not Appearing at Checkout',
                'content' => '<p>NET payment terms only appear at checkout when <strong>both</strong> conditions are met:</p><ol><li><strong>Global toggle:</strong> Go to <strong>Wholesale → Settings</strong> → "Enable NET Payment Terms" must be checked</li><li><strong>Per-user toggle:</strong> Go to <strong>Users → edit the customer</strong> → scroll to Wholesale Portal section → "NET Payment Terms" must be set to NET 30, 60, or 90 (not "No NET terms")</li></ol><p>If both are set and it still doesn\'t show, check that no other payment gateway plugin is hiding it. The NET gateway appears as "NET 30/60/90 Payment Terms" in the payment method list at checkout.</p>',
            ),
            array(
                'slug'    => 'emails-not-sending',
                'topic'   => 'troubleshooting',
                'title'   => 'Emails Not Sending',
                'content' => '<p>If welcome emails, admin notifications, or other transactional emails aren\'t arriving:</p><ol><li><strong>Check spam folder</strong> — especially Gmail and Outlook</li><li><strong>Verify SMTP:</strong> Install and configure <strong>WP Mail SMTP</strong> plugin. Without it, WordPress uses PHP\'s <code>mail()</code> function which many hosts block or filter</li><li><strong>Test SMTP:</strong> WP Mail SMTP has a "Send Test Email" feature — use it</li><li><strong>Check From address:</strong> Go to <strong>Wholesale → Invoices → Email Settings</strong> and verify the From Address matches a real mailbox on your domain</li><li><strong>SPF/DKIM:</strong> Your domain\'s DNS needs SPF and DKIM records that authorize your SMTP server to send on its behalf. Check with your host.</li></ol>',
            ),
            array(
                'slug'    => 'updates-not-showing',
                'topic'   => 'troubleshooting',
                'title'   => 'Plugin Updates Not Appearing',
                'content' => '<p>The plugin has a built-in updater that checks GitHub releases every 12 hours. If updates aren\'t showing:</p><ol><li><strong>Force refresh:</strong> Go to <strong>Dashboard → Updates</strong> and click <strong>"Check Again"</strong> at the top</li><li><strong>Check your version:</strong> Go to <strong>Plugins → Installed Plugins</strong> and find Wholesale Portal. If it already shows the latest version, there\'s nothing to update</li><li><strong>Object caching:</strong> If you use Redis or Memcached, the update transient may be cached. Clear your object cache</li><li><strong>Firewall:</strong> Some security plugins block outgoing API requests to GitHub. Whitelist <code>api.github.com</code></li></ol><p>The plugin never requires manual ZIP uploads after initial installation. All updates flow through the standard WordPress update mechanism.</p>',
            ),

        ); // end articles array
    }

    /* ----------------------------------------------------------
     * Render Page
     * ---------------------------------------------------------- */

    public static function render_page() {
        $articles = self::get_articles();
        $topics   = self::get_topics();
        $current  = isset( $_GET['article'] ) ? sanitize_key( $_GET['article'] ) : '';
        $current_topic = isset( $_GET['topic'] ) ? sanitize_key( $_GET['topic'] ) : '';

        // Find current article.
        $active_article = null;
        if ( $current ) {
            foreach ( $articles as $a ) {
                if ( $a['slug'] === $current ) {
                    $active_article = $a;
                    break;
                }
            }
        }

        // Group articles by topic.
        $by_topic = array();
        foreach ( $articles as $a ) {
            $by_topic[ $a['topic'] ][] = $a;
        }

        $docs_url = admin_url( 'admin.php?page=slw-docs' );
        ?>
        <div class="wrap slw-docs">

            <!-- Sidebar -->
            <div class="slw-docs-sidebar">
                <div class="slw-docs-search">
                    <input type="text" id="slw-docs-search-input" placeholder="Search docs&hellip;" autocomplete="off" />
                </div>
                <div id="slw-docs-no-results" style="display:none;padding:12px 16px;color:#888;font-size:13px;">No results found.</div>
                <nav class="slw-docs-nav">
                    <?php foreach ( $topics as $topic_slug => $topic_label ) :
                        if ( empty( $by_topic[ $topic_slug ] ) ) continue;
                        $is_open = ( $active_article && $active_article['topic'] === $topic_slug ) || $current_topic === $topic_slug;
                        ?>
                        <div class="slw-docs-topic<?php echo $is_open ? ' slw-docs-topic--open' : ''; ?>" data-topic="<?php echo esc_attr( $topic_slug ); ?>">
                            <button type="button" class="slw-docs-topic__toggle" onclick="this.parentElement.classList.toggle('slw-docs-topic--open')">
                                <span><?php echo esc_html( $topic_label ); ?></span>
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
                            </button>
                            <ul class="slw-docs-topic__list">
                                <?php foreach ( $by_topic[ $topic_slug ] as $a ) : ?>
                                    <li>
                                        <a href="<?php echo esc_url( add_query_arg( 'article', $a['slug'], $docs_url ) ); ?>"
                                           class="slw-docs-article-link<?php echo $current === $a['slug'] ? ' slw-docs-article-link--active' : ''; ?>"
                                           data-title="<?php echo esc_attr( strtolower( $a['title'] ) ); ?>"
                                           data-slug="<?php echo esc_attr( $a['slug'] ); ?>">
                                            <?php echo esc_html( $a['title'] ); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </nav>
            </div>

            <!-- Content Area -->
            <div class="slw-docs-content">
                <?php if ( $active_article ) : ?>
                    <div class="slw-docs-breadcrumb">
                        <a href="<?php echo esc_url( $docs_url ); ?>">Docs</a>
                        <span class="slw-docs-breadcrumb__sep">&rsaquo;</span>
                        <a href="<?php echo esc_url( add_query_arg( 'topic', $active_article['topic'], $docs_url ) ); ?>"><?php echo esc_html( $topics[ $active_article['topic'] ] ?? '' ); ?></a>
                        <span class="slw-docs-breadcrumb__sep">&rsaquo;</span>
                        <span><?php echo esc_html( $active_article['title'] ); ?></span>
                    </div>
                    <article class="slw-docs-article">
                        <h1><?php echo esc_html( $active_article['title'] ); ?></h1>
                        <?php echo wp_kses_post( $active_article['content'] ); ?>
                    </article>
                <?php else : ?>
                    <h1 style="font-family:Georgia,'Times New Roman',serif;font-size:28px;color:#1E2A30;margin:0 0 6px;">Help & Resources</h1>
                    <p style="color:#628393;font-size:15px;margin-bottom:32px;">Everything you need to know about your wholesale portal. Browse topics or search.</p>
                    <div class="slw-docs-topic-grid">
                        <?php foreach ( $topics as $topic_slug => $topic_label ) :
                            if ( empty( $by_topic[ $topic_slug ] ) ) continue;
                            $count = count( $by_topic[ $topic_slug ] );
                            ?>
                            <a href="<?php echo esc_url( add_query_arg( 'topic', $topic_slug, $docs_url ) ); ?>" class="slw-docs-topic-card">
                                <strong><?php echo esc_html( $topic_label ); ?></strong>
                                <span><?php echo esc_html( $count ); ?> article<?php echo $count !== 1 ? 's' : ''; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    // If a topic is selected, list its articles.
                    if ( $current_topic && ! empty( $by_topic[ $current_topic ] ) ) : ?>
                        <h2 style="margin-top:40px;"><?php echo esc_html( $topics[ $current_topic ] ?? $current_topic ); ?></h2>
                        <ul class="slw-docs-article-list">
                            <?php foreach ( $by_topic[ $current_topic ] as $a ) : ?>
                                <li><a href="<?php echo esc_url( add_query_arg( 'article', $a['slug'], $docs_url ) ); ?>"><?php echo esc_html( $a['title'] ); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>

        <script>
        (function(){
            var input = document.getElementById('slw-docs-search-input');
            var noResults = document.getElementById('slw-docs-no-results');
            var topics = document.querySelectorAll('.slw-docs-topic');
            var timer;

            if (!input) return;

            input.addEventListener('keyup', function(){
                clearTimeout(timer);
                timer = setTimeout(function(){
                    var q = input.value.toLowerCase().trim();
                    var anyVisible = false;

                    topics.forEach(function(topic){
                        var links = topic.querySelectorAll('.slw-docs-article-link');
                        var topicHasMatch = false;

                        links.forEach(function(link){
                            var title = link.getAttribute('data-title') || '';
                            var slug  = link.getAttribute('data-slug')  || '';
                            var match = !q || title.indexOf(q) !== -1 || slug.indexOf(q) !== -1;
                            link.parentElement.style.display = match ? '' : 'none';
                            if (match) topicHasMatch = true;
                        });

                        topic.style.display = topicHasMatch ? '' : 'none';
                        if (q && topicHasMatch) topic.classList.add('slw-docs-topic--open');
                        if (topicHasMatch) anyVisible = true;
                    });

                    noResults.style.display = anyVisible ? 'none' : '';
                }, 300);
            });
        })();
        </script>
        <?php
    }
}
