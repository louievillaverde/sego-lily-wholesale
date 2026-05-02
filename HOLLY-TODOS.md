# Wholesale Portal — Your Punch List

Hey Holly,

Just pushed all the updates from our walkthrough. Most of what we talked through is already running — rolled it all into one release so you'll see everything show up at once on the next admin page load.

A few things still need a hand from your side. None are heavy — the whole list below shouldn't take more than 30 minutes start to finish, and you can knock them out in any order. I've grouped them by "do this once to get set up" → "do this every show" so you can pace yourself.

---

## Already done for you

You don't need to touch any of this — flagging it just so you know what changed:

- **"Tallow Skincare" is now "Tallow Butter"** across the order form, the visibility list, and everywhere else.
- **A "Lip Balm" category was created** for you (you'll just need to assign your lip balm products to it — see below).
- **A free-shipping coupon for the booth bonus is in place** (`WHOLESALE-SHOWSHIP`). Wholesale visitors who fill out your booth quiz now see it on their thank-you screen automatically. Nothing to set up in WC → Coupons.
- **The Mautic step rename, settings text fixes, the "Welcome! Here\'s" backslash bug, and the EIN encryption issue** — all handled.

---

## One-time setup (do these once and forget)

### 1. Assign lip balm products to the new Lip Balm category
Products → edit each lip balm product → set the category to **Lip Balm**. (If you want the products to also stay under Tallow Butter, tick both — your call.)

### 2. Set the per-category minimums
Wholesale → Pricing → **Category Minimums**.

Set these to **6**:
- Ageless
- Renewal
- Moxy

That gives your customers what we talked about — they can mix scents within a category (3 honey + 3 lavender, 2/2/2, whatever) and still hit the minimum without being forced to buy 6 of a single scent. The order form will show a live "X / 6" counter in each category header so they always know where they stand.

### 3. Create the four Gift Set products
Once these are set up, the same category-minimum rule handles the "minimum 4 mixable across gift sets" automatically.

1. **Products → Categories → Add New** — create a "Gift Sets" category.
2. Create four simple products under that category:
   - Ageless Gift Set
   - Renewal Gift Set
   - Moxy Gift Set
   - Variety Gift Set
3. In the description for each, list the scents that come in it (you said you'd curate the top sellers per category — that part's all yours).
4. Wholesale → Pricing → **Category Minimums** → find "Gift Sets" → set Minimum Qty to **4**.

That's it. Customer mixes any combination across the four gift sets, total has to hit 4, cart blocks them with a friendly error if they try to check out short.

### 4. Bulk-import your existing wholesale customers
Wholesale → Customers → **Import** tab.

Two options:
- **Quick Add form** — one customer at a time
- **CSV upload** — download the template, paste your existing spreadsheet, upload

The welcome email is already worded for migration — it explains the new portal, gives them a temporary password + reset link, and prompts them to add their EIN once they log in. So this is basically your first touch on every existing wholesale account, all in one go.

---

## Per-show ritual (do this each trade show)

### 5. Set the Active Trade Show
Before each show: Wholesale → Customers → Leads → Trade Show Tools → **Active Trade Show** field.

Type in the show name (e.g. "Montana Craft Fair"), hit save. Every lead captured during the show — whether they scan the QR or fill out the iPad directly — gets tagged with that show name automatically. Clear the field when the show ends.

---

## Optional

### 6. Lip balm case pack (only if you actually need the rule)
By default, case packs are off — the field doesn't even show on product edit pages and the "Case of N" labels don't appear on the order form. If you want lip balm to enforce a case-of-6 rule:

1. Wholesale → Settings → Order Form → check **"Enable per-product case pack sizes"**
2. Edit each lip balm product → General tab → set **Wholesale Case Pack Size** to 6

I'd say leave this off unless you have a real "the customer can't break the case" scenario. Category minimums cover almost every other situation more cleanly.

---

## One thing I need from you

The Assets tab is built and live (Wholesale → Assets in the admin, and your wholesale customers see it as a new "Assets" tab in their portal). Right now it's empty waiting for content.

Send me whatever brand assets you'd like in there — logos, product photos, shelf talkers, marketing PDFs, video links, whatever — and I'll seed the default library so every wholesale customer sees the same starter set. From there you can edit/add/remove anytime, and there's a per-customer override editor for any partners who need extras.

---

Let me know when you've worked through the list (or sooner if anything's confusing). Anything that's not behaving the way you expected, just send a screenshot and I'll get it sorted.

Talk soon,
Louie
