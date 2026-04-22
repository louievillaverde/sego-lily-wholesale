# Wholesale Portal

Complete B2B wholesale solution for WooCommerce. Turn any store into a full wholesale operation with a unified customer portal, tiered pricing, automated email sequences, and everything a wholesale business needs to scale.

Built by [Lead Piranha](https://leadpiranha.com).

## Customer Portal

A unified, tabbed experience at `/wholesale-portal` where wholesale partners manage everything:

- **Dashboard** — Welcome hub with order history, saved order templates, quick links
- **Order Form** — Full product catalog with wholesale pricing, case packs, shipping calculator, product recommendations
- **Invoices** — Download and print invoices for past orders
- **Account** — Profile, shipping address, password management
- **Request a Quote** — Multi-product quote requests for custom/large orders
- **Price List** — Downloadable wholesale catalog with retail vs wholesale pricing
- **Help / Contact** — FAQ, brand assets info, support contact

## Admin Dashboard

Modern SaaS-style admin under **Wholesale** in the WordPress sidebar:

| Page | Purpose |
|---|---|
| Dashboard | Quick stats, recent activity, setup checklist |
| Applications | Review, approve, or decline wholesale applications |
| Orders | Wholesale-only order view with filters, CSV export |
| Sequences | Email campaign stats from Mautic/Mailchimp, newsletter compose, drag-reorder |
| Quotes | Manage request-for-quote submissions |
| Leads | Lightweight prospect capture + management pipeline |
| Tiers | Configure Standard/Preferred/VIP wholesale tiers |
| Import | Add single customers or bulk import via CSV |
| Preview | See the customer portal exactly as partners see it |
| Invoices | Customize invoice branding, email sender settings, live preview |
| Settings | Discounts, minimums, NET terms, shipping restrictions, store notice |
| Help | 32 searchable documentation articles + system info |

## Features

### Pricing
- Global wholesale discount percentage
- Multiple wholesale tiers (Standard, Preferred, VIP) with auto-upgrade
- Per-product price overrides
- Category-level discount overrides
- Tiered quantity pricing (buy X+ for Y)
- Per-product minimum quantities
- Case pack ordering (buy in multiples of 6, 12, etc.)

### Onboarding
- 3-step application wizard with bot protection
- Admin approval/decline with email notifications
- Automatic WooCommerce account creation
- Lead capture form for prospects
- Bulk user import via CSV + single-customer form

### Orders & Payments
- First order minimum + reorder minimum
- NET 30/60/90 payment terms (per customer)
- Tax exemption (per customer or global)
- Shipping method restrictions per role
- Wholesale-only products and coupons
- Quick reorder from past orders
- Saved order templates ("My Usual Order")
- Shipping calculator on order form
- Product recommendation pairings

### Invoicing
- Customizable PDF invoices (logo, colors, business info)
- Live invoice preview in settings
- Send invoices to customers via email
- Downloadable wholesale price list / line sheet

### Email & Automation
- Email sequence dashboard (Mautic, Mailchimp, ActiveCampaign, Klaviyo, ConvertKit)
- Newsletter compose + send from admin
- 7 automated campaign triggers:
  - Onboarding sequence (application approved)
  - Cart abandonment recovery (2hr detection)
  - Reorder reminders (45/75/120 days)
  - Win-back (180 days lapsed)
  - Referral request (3rd order)
  - Payment due reminders (NET terms)
  - Admin alert (stale applications 72hr+)
- White-label email settings (From, Reply-To, signature)
- Webhook integration for any CRM

### Security
- AES-256 field-level encryption for EIN/resale certificates
- Admin audit log (approvals, status changes, tier upgrades)
- Honeypot + rate limiting on public forms
- Nonce verification on all forms and AJAX
- WooCommerce HPOS compatible

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+

## Installation

1. Upload the plugin ZIP via Plugins > Add New > Upload Plugin
2. Activate
3. The plugin auto-creates pages: `/wholesale-partners`, `/wholesale-order`, `/wholesale-dashboard`, `/wholesale-portal`, `/wholesale-rfq`, `/wholesale-leads`
4. Configure at Wholesale > Settings

## Updates

Built-in self-updater checks GitHub releases every 12 hours. Updates appear in Dashboard > Updates — click "Update Now." All data preserved across updates.

## Support

Contact Lead Piranha for support, feature requests, or to set up the plugin for a new client.
