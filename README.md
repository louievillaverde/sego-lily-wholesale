# Sego Lily Wholesale Plugin

Custom wholesale portal for Sego Lily Skincare. Replaces Wholesale Suite with a zero-cost, purpose-built solution.

Built by Lead Piranha. Current version maintained at https://github.com/louievillaverde/sego-lily-wholesale

## First-time installation (one time only)

### Step 1: Install Git Updater (so future updates are automatic)

1. WP Admin > Plugins > Add New
2. Search for **Git Updater**
3. Install and activate it

After Git Updater is active, every future Sego Lily Wholesale release appears in your native WP Plugins > Updates screen and updates with one click. You never have to upload a ZIP manually again.

### Step 2: Install Sego Lily Wholesale

1. Download the latest ZIP from https://github.com/louievillaverde/sego-lily-wholesale/releases/latest
2. WP Admin > Plugins > Add New > Upload Plugin > choose the ZIP > Install Now
3. Activate

The plugin auto-creates three pages:

| URL | Purpose |
|---|---|
| `/wholesale-partners` | Public application form |
| `/wholesale-order` | Product catalog + order form (wholesale users only) |
| `/wholesale-dashboard` | Customer dashboard (wholesale users only) |

### Step 3: Configure

Go to **Settings > Sego Lily Wholesale** and set:

- **Wholesale Discount %** (default: 50%)
- **First Order Minimum** (default: $300)
- **Reorder Minimum** (default: $0)
- **AIOS Webhook URL** (paste the URL provided by Lead Piranha)
- **Enable NET 30** (toggle, then approve per user on their profile)
- **Default wholesale tax exemption** (applies to all wholesale users automatically)
- **Shipping method restrictions** (which methods each role can use at checkout)

## How updates work from this point on

1. When Lead Piranha releases a new version (bug fix, new feature), it gets published on GitHub
2. Within hours, your WP Admin shows an update notification in Plugins > Updates
3. Click **Update Now**. WordPress downloads the new version and replaces the plugin files.
4. Your settings, users, orders, and applications are preserved across every update.

No FTP, no File Manager, no ZIP uploads.

## Features

### Wholesale pricing
- Role-based pricing: wholesale users see the discounted price everywhere
- Retail users see the regular price (no change to their experience)
- Per-product price overrides (set a custom wholesale price on any product)
- Category-level discount overrides (different discount % per product category)
- Tiered quantity pricing (buy 12+ for $X, buy 24+ for $Y, etc.)

### Application flow
- Public application form at `/wholesale-partners`
- Admin approval workflow
- Welcome email with login credentials sent automatically on approval
- Polite decline email on rejection
- Honeypot + rate limiting for bot protection

### Order rules
- First order minimum ($300 default)
- No reorder minimum (or configurable)
- NET 30 payment terms (toggle per user)
- Tax exemption (per user or global default)
- Shipping method restrictions per role

### Admin tools
- Wholesale Applications admin page with approve/decline
- Users list column showing wholesale status + badges
- User profile section for wholesale/tax-exempt/NET 30 toggles
- Bulk user import via CSV
- CSV export of all applications
- Self-healing database (auto-creates table if missing)

### Integrations
- AIOS webhook on approval (fires `wholesale-active` Mautic tag)
- AIOS webhook on first order (fires `first-order-placed` Mautic tag)
- HPOS (High-Performance Order Storage) compatible
- Elementor compatible

## NET 30 setup

1. Enable NET 30 in plugin settings
2. Edit a wholesale user's profile
3. Check "NET 30 Payment Terms" under the Sego Lily Wholesale section
4. That customer now sees "NET 30 Terms" as a payment option at checkout

## Bulk user import

Go to **Wholesale Applications > Import Users**. Upload a CSV with these columns:

- Required: `email`, `first_name`, `last_name`, `business_name`
- Optional: `phone`, `address`, `ein`, `business_type`, `net30_approved` (yes/no), `tax_exempt` (yes/no), `send_welcome_email` (yes/no)

Existing users matched by email are promoted to wholesale. New emails create new accounts. Welcome emails with login credentials send automatically if the CSV row says yes (or you check the "send to all" box).

## Deactivation and uninstall

Deactivating the plugin does NOT delete data. Users, orders, applications, settings, and the wholesale role all survive deactivation and can be reactivated later without losing anything.

## Support

Contact Lead Piranha if you run into issues. When a fix or new feature is released, it appears in your Plugins > Updates screen automatically.
