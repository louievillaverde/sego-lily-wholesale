# Sego Lily Wholesale Plugin — User Guide

This guide walks you through testing and using the wholesale portal on your WordPress site. Everything you need to run wholesale is here.

---

## Quick links

- **Plugin repo:** https://github.com/louievillaverde/sego-lily-wholesale
- **Latest release:** https://github.com/louievillaverde/sego-lily-wholesale/releases/latest
- **Your wholesale pages:**
  - Application form: `segolilyskincare.com/wholesale-partners`
  - Order form: `segolilyskincare.com/wholesale-order`
  - Customer dashboard: `segolilyskincare.com/wholesale-dashboard`

---

## Part 1: First-time setup

### Step 1: Install Git Updater (one time)

Git Updater is a free plugin that lets your WordPress site automatically check for new Sego Lily Wholesale versions and install them with one click, just like any other plugin.

1. WP Admin > Plugins > Add New
2. Search for **Git Updater** in the search box
3. Click **Install Now** next to the plugin by "Andy Fragen"
4. Click **Activate**

Done. You'll never need to touch Git Updater again. It runs quietly in the background.

### Step 2: Install Sego Lily Wholesale

1. Download the latest ZIP: https://github.com/louievillaverde/sego-lily-wholesale/releases/latest (click `sego-lily-wholesale.zip` under Assets)
2. WP Admin > Plugins > Add New > Upload Plugin
3. Choose the ZIP file you just downloaded
4. Click **Install Now**, then **Activate**

The plugin automatically creates three pages on activation:

| Page | URL |
|---|---|
| Application form | /wholesale-partners |
| Order form | /wholesale-order |
| Customer dashboard | /wholesale-dashboard |

### Step 3: Configure

Go to **Settings > Sego Lily Wholesale** and set:

| Setting | What it does | Default |
|---|---|---|
| Wholesale Discount % | How much off retail wholesale customers get | 50% |
| First Order Minimum | Minimum cart total for a partner's first order | $300 |
| Reorder Minimum | Minimum for subsequent orders | $0 (none) |
| AIOS Webhook URL | For Lead Piranha integration (ask Louie for this) | |
| Enable NET 30 | Allow NET 30 payment terms | Off |
| Default wholesale tax exemption | Make ALL wholesale users tax-exempt | Off |
| Shipping method restrictions | Which shipping methods each role can use | None (all allowed) |

Click **Save Settings** at the bottom.

---

## Part 2: Testing the full flow

Do this BEFORE you launch publicly to make sure everything works end to end.

### Test 1: Submit a test application

1. Open a new incognito / private browser window (so you're not logged in as admin)
2. Go to `segolilyskincare.com/wholesale-partners`
3. Fill out the form with test info. Use your own email or a throwaway like `yourname+wholesaletest@gmail.com`
4. Submit

You should see a green "Application Received" confirmation message.

**What you just triggered:**
- A row saved to the `wp_slw_applications` table
- An email notification sent to the admin email address (check your inbox and spam folder)

### Test 2: Approve the application

1. Back in your admin browser, go to WP Admin
2. Left sidebar > **Wholesale Applications**
3. You should see your test application listed as "Pending"
4. Click on it
5. Review the details
6. Click **Approve**

**What happens on approval:**
- A WooCommerce user is created with the wholesale_customer role
- The partner gets a welcome email with username + password
- A webhook fires to AIOS which triggers the 6-email Mautic onboarding sequence
- The application is marked "Approved" in your list

### Test 3: Log in as the new wholesale customer

1. Open ANOTHER incognito window (or log out first)
2. Check the welcome email for login credentials
3. Go to `segolilyskincare.com/my-account` or `segolilyskincare.com/wp-login.php`
4. Log in with the new credentials

### Test 4: Visit the wholesale pages as a customer

While logged in as the test wholesale user:

- `/wholesale-dashboard` should show the customer dashboard with account details
- `/wholesale-order` should show the product catalog at wholesale prices (50% off retail)
- Add some products to the cart

### Test 5: Verify minimum order enforcement

1. Try to checkout with less than $300 in the cart
2. You should see an error: "Your first wholesale order requires a \$300 minimum..."
3. Add more products to reach $300
4. Checkout should now proceed

### Test 6: Place the test order

Complete checkout with a test payment. Or just confirm the order can be placed.

**What happens after the first order completes:**
- The `first-order-placed` meta flag is set on the user
- Future orders will NOT have the $300 minimum
- A webhook fires to AIOS which triggers the Day 45 referral email in Mautic

---

## Part 3: Daily use (how you'll use the plugin going forward)

### Review applications

**Wholesale Applications** in your WP Admin sidebar shows all applications. Filter by status (All / Pending / Approved / Declined).

- Click an application to see the full details
- **Approve** creates the account and sends the welcome email
- **Decline** sends a polite decline email
- **Export CSV** downloads all applications as a spreadsheet for your records

### Manage wholesale customers

**Users** in your WP Admin sidebar. The new "Wholesale" column shows who's wholesale at a glance. Click on a user to edit them.

At the bottom of every user profile, you'll see a "Sego Lily Wholesale" section with these toggles:

| Toggle | What it does |
|---|---|
| Wholesale Customer | Turns wholesale pricing on/off for this user |
| Resale Certificate Verified | Makes this user tax-exempt at checkout |
| Resale Certificate Number | Where you record the number for your records |
| NET 30 Terms | Lets this user pay NET 30 instead of upfront (only shows if NET 30 is enabled in settings) |

You can manually promote any existing retail customer to wholesale from here without them having to fill out the application form.

### Approve NET 30 for a specific customer

1. Settings > Sego Lily Wholesale > make sure "Enable NET 30 Option" is checked > Save
2. Users > find the wholesale customer > Edit
3. Scroll to the Sego Lily Wholesale section
4. Check "Approved for NET 30 payment terms" > Update
5. That customer can now choose NET 30 at checkout

### Set per-product wholesale prices

If a specific product shouldn't be 50% off (say it's a high-margin item and you want to give wholesale 40% off instead):

1. Products > edit the product
2. In the Product Data section > General tab
3. Find the "Wholesale Price ($)" field
4. Enter the exact wholesale price you want for this product
5. Update

### Set per-category wholesale discount

If an entire category should have a different discount:

1. Products > Categories > edit the category
2. Scroll to "Wholesale Discount Override (%)"
3. Enter the percentage (e.g. 40 means 40% off retail for this category)
4. Update

### Make a product wholesale-only

Hides the product from retail customers entirely. Only wholesale users see it in the shop.

1. Products > edit the product
2. General tab > check "Wholesale only"
3. Update

### Make a coupon wholesale-only

Only wholesale customers can apply this coupon. Retail customers get an "invalid coupon" error.

1. Marketing > Coupons > add or edit a coupon
2. Under General > check "Wholesale only"
3. Save

### Bulk import wholesale users from a CSV

If you have a list of existing wholesale partners you want to import all at once:

1. Wholesale Applications > Import Users
2. Click "Download CSV template" to get the format
3. Fill in the template with your partner list (email, first_name, last_name, business_name, phone, etc.)
4. Upload the filled CSV
5. Each row either creates a new wholesale user OR promotes an existing one
6. Welcome emails are sent automatically (or you can turn that off)

### Export all applications to CSV

Wholesale Applications > Export CSV button at the top. Downloads everything with every field.

### Restrict shipping methods per role

You can hide certain shipping methods from wholesale or retail customers. Useful if wholesale orders ship via freight and retail ships standard.

1. Settings > Sego Lily Wholesale > scroll to "Shipping Method Restrictions"
2. Check which methods each role can use
3. Leave ALL boxes unchecked for a role to allow every shipping method (no restrictions)
4. Save

---

## Part 4: Updating the plugin

This is why you installed Git Updater. New versions of Sego Lily Wholesale appear automatically in your normal WordPress updates screen.

1. Dashboard > Updates (or the number badge next to "Plugins" in the sidebar)
2. Find Sego Lily Wholesale in the list
3. Click **Update Now**
4. Done. Your settings, users, orders, and applications are all preserved.

---

## Part 5: Troubleshooting

### The application form shows a "Please enter a URL" error

The Website field is set to accept any format (text, handles, or URLs). If you're seeing strict URL validation, it means something else on the page is overriding the form. Check:

- Is the page built with Elementor? If so, verify the shortcode `[sego_wholesale_application]` is inside an Elementor shortcode widget, or switch the page to the default WordPress template.
- Is Wholesale Suite still installed and active? Deactivate it.
- Is there another form plugin rendering on this page?

### No application notification email arrived

Check:
- Your admin email address (Settings > General > Administration Email Address) — the notification goes there
- Spam folder
- WP Mail SMTP is configured properly (Settings > WP Mail SMTP should show "Mailer: Other SMTP" with your mail settings)

### Submissions don't appear in the Wholesale Applications page

The plugin self-heals the database table on every admin page load. Visit any admin page, then check again. If applications still don't appear, check the `wp_slw_applications` table exists in phpMyAdmin.

### A customer says they're being charged the retail price

Verify they're logged in as a wholesale user. Check their profile — the "Wholesale Customer" box should be checked. Also check that the product doesn't have a specific per-product wholesale price set to $0 or blank by mistake.

### NET 30 option doesn't appear at checkout

NET 30 appears only when BOTH:
- Settings > Sego Lily Wholesale > "Enable NET 30 Option" is checked
- The specific user has "Approved for NET 30 payment terms" checked on their profile

### Updates aren't showing up in the WordPress updates screen

1. Settings > Git Updater > click "Refresh Cache"
2. Go back to Dashboard > Updates
3. If still nothing appears, check that you're running an older version than what's on GitHub. Plugins > Sego Lily Wholesale > check the version number.

### Need to roll back to a previous version

1. Plugins > Deactivate Sego Lily Wholesale
2. Delete it (data is preserved)
3. Go to https://github.com/louievillaverde/sego-lily-wholesale/releases and download an older release
4. Plugins > Add New > Upload Plugin > upload the older ZIP > Install > Activate

---

## Part 6: Contact

Questions or issues? Contact Louie at Lead Piranha. When a fix or feature ships, it appears in your Plugins > Updates screen automatically within a few hours.
