# Holly — WordPress Admin To-Dos

Working list of WP admin / external actions discovered during the wholesale portal updates. Raw notes for now — will be reorganized and rewritten in your voice before sending.

---

## Auto-handled (done by the plugin update — no action needed)

- ~~Rename "Tallow Skincare" product category → "Tallow Butter"~~ — handled by a one-shot migration that runs once on the next admin page load.
- ~~Create a "Lip Balm" product category~~ — same migration creates it if missing.
- ~~Auto-create the wholesale booth free-shipping coupon~~ — created on the first booth wholesale render, code defaults to `WHOLESALE-SHOWSHIP` (or whatever you put in Settings → Booth & Lead Capture → Wholesale Bonus Code).
- ~~Mautic: rename "1 hour delay" step~~ — already done by Holly.

---

## Still needs you (WordPress)

- [ ] **Assign your existing lip balm products to the new "Lip Balm" category**
  Products → edit each lip balm product → set the category to "Lip Balm" instead of "Tallow Butter" (or keep both if you want them visible under Tallow Butter too).

- [ ] **Create the four Gift Set products**
  Plugin's category-minimum feature handles the "minimum 4 mixable across gift sets" rule automatically once the products + category are set up.

  1. **Products → Categories → Add New** — create a "Gift Sets" category.
  2. Create four simple products under that category, one per "type":
     - Ageless Gift Set
     - Renewal Gift Set
     - Moxy Gift Set
     - Variety Gift Set
  3. *(For curating scents)* If you want each gift set to be a specific scent assortment (e.g. "Ageless Gift Set – Top 3"), make them simple products with that combination implied in the title and description. If you want the customer to pick one of multiple scent variations per gift set, make them **variable products** with a "Scent" attribute and add only the variations you want them to choose from.
  4. **Wholesale → Pricing → Category Minimums** — find "Gift Sets" in the table, set Minimum Qty to **4**.

  The order form will then show "Minimum 4 units (mix & match)" in the Gift Sets category header, with a live count of what they've added. If they try to check out with fewer than 4 across the four gift sets, the cart will block them.

- [ ] **Set per-category minimums** for Ageless / Renewal / Moxy
  Wholesale → Pricing → Category Minimums. Per the meeting: 6 in each so customers can mix scents (3 honey + 3 lavender, 2/2/2, 4/4/4, etc.) and hit the minimum without being forced to buy 6 of one scent.

- [ ] **Set the Lip Balm case pack** (only if it actually applies)
  First turn on Wholesale → Settings → Order Form → "Enable per-product case pack sizes" (off by default). Then on each lip balm product → General tab → "Wholesale Case Pack Size" = 6. Category-minimum is preferred for everything else; case pack is only for true case-pack scenarios where the customer can't break the case.

- [ ] **Set the Active Trade Show before each event**
  Wholesale → Customers → Leads tab → Trade Show Tools → "Active Trade Show" field. Save it once at the start of the show. Any iPad-direct fill (or QR scan without an event param) will be tagged with that show name automatically. Clear it when the show ends.

- [ ] **Bulk-import existing wholesale customers**
  Wholesale → Customers → Import tab. Either fill out the Quick Add form per customer or download the CSV template, paste in your existing wholesale customer spreadsheet, and upload. The new welcome email is now tuned for migration — it explains the new portal exists, gives them a temp password + reset link, and asks them to add their EIN on the Account tab.

---

## Notes for final cleanup

- Group by "first thing you'll see" vs "do whenever"
- Phrase as friendly directives from Louie
- Add screenshots / paths where helpful
- Drop any items that get superseded by code changes
