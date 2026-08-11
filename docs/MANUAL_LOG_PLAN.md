# MANUAL LOG PLAN
### Converting the Daily Report Spreadsheet Tab into a Full Manual Sales Log
_Status: Planning only — no code changed. Reference: `docs/EXPORT_GAP_ANALYSIS.md`_

---

## 1. DATA MODEL

### Decision: Extend `daily_report_spreadsheet_rows`, do not create a new table

**Why:** The table was created in commit `8a759ec` and may already contain live data.
Dropping it would destroy that data. An idempotent `ALTER TABLE` (column existence
guard via `SHOW COLUMNS`) is safer, reversible, and keeps the existing AJAX handlers
working with zero renaming.

### What already exists (committed schema)

| Column | Type | Notes |
|---|---|---|
| id | INT UNSIGNED PK AI | |
| report_date | DATE NOT NULL | |
| row_order | INT NOT NULL | |
| time_in | VARCHAR(20) | |
| time_out | VARCHAR(20) | |
| slip_no | VARCHAR(50) | |
| client_name | VARCHAR(150) | |
| service_name | VARCHAR(150) | free-text only right now |
| stylist | VARCHAR(150) | free-text only right now |
| regular_price | DECIMAL(10,2) | |
| promo_price | DECIMAL(10,2) | |
| celeb_10 | DECIMAL(10,2) | |
| disc_20_pwd | DECIMAL(10,2) | |
| comm_30 | DECIMAL(10,2) | |
| comm_20 | DECIMAL(10,2) | |
| comm_15 | DECIMAL(10,2) | |
| disc_50_staff | DECIMAL(10,2) | |
| net_sales | DECIMAL(10,2) NOT NULL | |
| mode_of_payment | VARCHAR(50) | |
| remarks | VARCHAR(500) | |
| is_refund | TINYINT(1) | |
| created_by_name | VARCHAR(150) | |
| updated_by_name | VARCHAR(150) | |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### Columns to ADD via idempotent migration in `_daily_report_data.php`

```sql
-- All guarded with SHOW COLUMNS to be idempotent:
ALTER TABLE daily_report_spreadsheet_rows
    ADD COLUMN service_id      INT NULL            AFTER service_name,
    ADD COLUMN therapist_id    INT NULL            AFTER stylist,
    ADD COLUMN comm_tier       TINYINT UNSIGNED NULL
        COMMENT '30, 20, or 15 — which tier column was filled',
    ADD COLUMN advance_payment DECIMAL(10,2) NOT NULL DEFAULT 0,
    ADD COLUMN mop2            VARCHAR(50) NULL
        COMMENT 'Second MOP for split payments (e.g. GC + CASH)',
    ADD COLUMN is_active       TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '0 = voided (kept for audit, excluded from totals)';
```

**`service_id`** — FK reference to `services.id`; stored so dropdown auto-fill can
be replayed if `services.price` changes. Free-text `service_name` is still the
display value (allows custom entries not in the catalogue).

**`therapist_id`** — FK reference to `therapists.id`; stored so commission lookup
can be verified later. Free-text `stylist` remains the display value.

**`comm_tier`** — records which of the three commission columns was auto-computed
(30, 20, or 15). Allows future reporting to distinguish tier mix without parsing
which column is non-zero.

**`advance_payment`** — the client's advance-payment column appears in the log rows
in sheet "28". Currently only tracked at the appointment level in the system; manual
log needs it per row.

**`mop2`** — the client sometimes records a second payment method for split-payment
transactions (e.g., partial GC + cash). Captured as a second MOP field.

**`is_active`** — soft-void flag. A voided row is hidden from display and excluded
from totals, but kept for audit. This is safer than hard-delete for a financial log.

---

## 2. DROPDOWN SOURCES

### Service Dropdown
- **Table**: `services`
- **Columns**: `id` (stored in `service_id`), `name` (shown in dropdown and filled
  into `service_name`), `price` (auto-filled into `regular_price`)
- **Query**: `SELECT id, name, price FROM services ORDER BY name ASC`
- **Note**: Include all services including soft-deleted ones where data may already
  reference them. A `WHERE is_deleted IS NULL OR is_deleted = 0` guard can be added
  once the services table has a deletion flag.
- **Implementation**: served as a PHP-injected JSON array in the page HTML (not a
  separate AJAX call); the list is small enough to embed.

### Stylist Dropdown
- **Table**: `therapists`, cross-referenced with `therapist_attendance`
- **Primary query** (duty day only):
  ```sql
  SELECT t.id, t.full_name
  FROM therapists t
  JOIN therapist_attendance ta ON ta.therapist_id = t.id
  WHERE ta.duty_date = ?
  ORDER BY ta.rotation_order ASC, t.full_name ASC
  ```
- **Fallback** (if no attendance rows for the date): show all therapists ordered by
  `t.id ASC` — prevents an empty dropdown when attendance hasn't been logged yet.
- **Stored in**: `therapist_id` (int) + `stylist` (display name). Display name is
  kept free-text so the receptionist can type "ROSARIO" or a nickname without it
  breaking the row.

### Mode of Payment Dropdown
- **Source**: Static PHP array — no database table needed.
- **Values**: `CASH`, `GCASH`, `MAYA`, `CARD`, `QRPH`, `GC` (gift certificate),
  `MAYA (DP)`, `UNPAID/CORP`
- **Second MOP (mop2)**: same static list, plus an empty/none option.

---

## 3. AUTO-COMPUTE LOGIC

### 3a. On Service select → auto-fill Regular Price

1. User picks a service from the dropdown.
2. JS looks up the service in the embedded `window.SS_SERVICES` array by `id`.
3. Writes `service.price` into the `regular_price` cell of the same row.
4. Writes `service.name` into the `service_name` cell.
5. Stores `service.id` in the row's hidden `data-service-id` attribute.
6. Triggers the commission auto-compute (step 3b) if a `data-therapist-id` is
   also set on the row.
7. The user can overwrite `regular_price` manually — blur-save will persist
   whatever value is in the cell, regardless of the auto-fill.

### 3b. On Stylist + Service select → commission lookup and tier placement

**Lookup:**
```
AJAX or embedded JSON:
SELECT commission_percent
FROM therapist_commission
WHERE therapist_id = :therapist_id
  AND service_id   = :service_id
LIMIT 1
```
Because the commission table is small, the full table is embedded in
`window.SS_COMMISSIONS` as a `{therapist_id: {service_id: percent}}` map at page
load, avoiding per-row AJAX calls.

**Tier decision:**
```
percent == 30 → fill comm_30,  zero comm_20 and comm_15
percent == 20 → fill comm_20,  zero comm_30 and comm_15
percent == 15 → fill comm_15,  zero comm_30 and comm_20
any other non-zero → fill comm_30 (default tier), show yellow warning icon
0 or no row found → see fallback below
```

**Commission peso amount:**
```
base = promo_price if promo_price > 0 else regular_price
commission_amount = round(base * percent / 100, 2)
```

**Fallback when no `therapist_commission` row exists for the pair:**
- All three commission cells are left at zero.
- A ⚠ icon appears inside the row (orange, via a CSS class `ss-comm-warn`).
- The icon tooltip: "No commission rate set for this therapist + service. Enter manually."
- The row still saves — a zero commission is valid (e.g., probationary therapists).
- The warning disappears once the user manually types any non-zero value in a
  commission cell and tabs away.
- **Never silently zero without warning** — the zero must be a conscious staff choice.

### 3c. Net Sales — auto-compute, manually overridable

**Proposed formula:**
```
net_sales = (promo_price > 0 ? promo_price : regular_price)
          − comm_30 − comm_20 − comm_15
          − celeb_10 − disc_20_pwd − disc_50_staff
```
This matches the current export formula `=L−O−P−Q` but also subtracts celeb and
PWD/staff discounts (which the current export omits — confirmed gap in
`EXPORT_GAP_ANALYSIS.md`, Step 6).

**⚠ UNCONFIRMED WITH CLIENT:** Whether `net_sales` subtracts celeb/PWD discounts
in addition to commission, or only commission. The current system (export col S)
uses only Promo Price − commission. The client's own Excel formula may differ.
**Design rule:** auto-compute on every field change, but mark the net_sales cell
with a faint blue border to indicate "auto-computed". If the user types a different
value and blurs, the cell turns white (manual override accepted) and the row saves
the typed value. A small `🔄` icon resets it to the formula if clicked.

---

## 4. SOURCE-OF-TRUTH WIRING

### 4a. Log Tab (read-only, system transactions — KEEP, do not remove)

The existing `📋 Service Log` tab reads from `$service_rows` (system
orders/appointments). It is preserved as a **reconciliation / audit view** only —
staff use it to verify that the manual spreadsheet matches what the system recorded.

A banner is added at the top: _"This tab shows system-recorded transactions.
The Spreadsheet tab is the source of truth for daily summaries and export."_

No totals from this tab feed into the Summary after the migration is complete.

### 4b. Spreadsheet Tab → Summary totals

Current wiring (`_daily_report_data.php`):
```php
$spreadsheet_net_total    // SUM(net_sales) WHERE is_refund=0 AND is_active=1
$spreadsheet_refund_total // SUM(net_sales) WHERE is_refund=1 AND is_active=1
```

After new columns are added, also compute:
```php
$spreadsheet_advance_total  // SUM(advance_payment) WHERE is_active=1
$spreadsheet_comm_total     // SUM(comm_30+comm_20+comm_15) WHERE is_active=1 → feeds STAFF CF
$spreadsheet_celeb_total    // SUM(celeb_10) WHERE is_active=1
$spreadsheet_disc_pwd_total // SUM(disc_20_pwd) WHERE is_active=1
$spreadsheet_disc_50_total  // SUM(disc_50_staff) WHERE is_active=1
```

The Summary panel rows `STAFF CF`, `CELEB. DISCOUNTS 10%`, `DISCOUNTS`, and
`ADVANCE PAYMENT` will then read from the spreadsheet totals **instead of** (or
**in addition to**) the system-transaction totals. Which "instead of" vs "in
addition to" depends on whether system transactions are fully retired — an open
question (see §7).

**Gross Sales** formula stays the same:
```
$gross_sales = $pos_reading + $gc_sold_total - $mktg_expense + $spreadsheet_net_total
```

**Net Cash** gains the advance and mop-related spreadsheet amounts if they are not
already covered by other rows.

### 4c. Excel Export

Currently `export_daily_report.php` iterates `$service_rows` (system transactions)
to populate the service log section (cols E–U). After the migration:

1. `$service_rows` loop is replaced with a `$spreadsheet_rows` loop.
2. Each row maps directly: `time_in` → col E, `time_out` → col F, `slip_no` → G,
   `client_name` → H, `service_name` → I, `stylist` → J, `regular_price` → K,
   `promo_price` → L, `celeb_10` → M, `disc_20_pwd` → N, `comm_30` → O,
   `comm_20` → P, `comm_15` → Q, `disc_50_staff` → R, `net_sales` → S,
   `mode_of_payment` → T, `remarks` → U. Two new export columns: `advance_payment`
   and `mop2` need column slots (Steps 3–4 of `EXPORT_GAP_ANALYSIS.md`).
3. Voided rows (`is_active = 0`) are skipped.
4. The Summary section formulas continue to use cell references (unchanged) so they
   automatically pick up the new data.
5. Staff CF in the Summary is changed from the stored `$staff_cf` PHP value to
   `=SUM(O:O)+SUM(P:P)+SUM(Q:Q)` (a live Excel formula across the log block),
   consistent with the client's workbook formula `O58+P58+Q58`.

### 4d. What happens to system-transaction rows

**Option offered to staff: "Import from System" button** (not auto-applied):
- A button at the top of the Spreadsheet tab reads all `$service_rows` for the date
  and inserts them as `daily_report_spreadsheet_rows` (mapping fields as in §4c).
- It only appears if the spreadsheet is empty for that date and the report is unlocked.
- After import, the system rows are visible as regular manual rows — staff can edit,
  void, or add to them.
- A warning is shown: "This imports today's system transactions as a starting point.
  Review and adjust before locking the report."

The **Log tab remains visible** as a read-only audit trail regardless.

---

## 5. GUARDRAILS

### Report Lock
All three existing AJAX handlers (`unlock_ss_edit`, `save_ss_row`, `delete_ss_row`)
already check `$rpt['is_locked']` and return `{ok:false, msg:'Report is locked.'}`
before doing anything. The new "void row" handler and "import from system" handler
will use the same guard. No change needed.

### PIN Gate (receptionist unlock)
Already implemented: `$_SESSION['ss_edit_unlocked']`, `ss_edit_date`, `ss_edit_expires`
(30-minute window). The `canEdit()` JS function already enforces this client-side.
All new AJAX endpoints reuse the same session checks. No change needed.

### Role-based access
- `is_cashier()` → must PIN-unlock before editing (existing logic).
- `is_full_access()` → can edit without PIN, can lock/unlock report (existing logic).
- The "Import from System" button is available to both roles (but requires the PIN
  unlock for cashiers, same as any edit).
- The void (`is_active = 0`) action is treated as an edit — same PIN gate applies.

### CSRF
All AJAX POST handlers use `verify_csrf_token_ajax()`. The new void and import
handlers will do the same.

---

## 6. BUILD ORDER (safest-first)

Each step is independently testable before the next begins.

**Step 1 — Extend the table** _(data model, no UI)_
- Add `service_id`, `therapist_id`, `comm_tier`, `advance_payment`, `mop2`,
  `is_active` to `daily_report_spreadsheet_rows` via idempotent column-existence
  guards in `_daily_report_data.php`.
- Verify: `SHOW COLUMNS FROM daily_report_spreadsheet_rows` shows all new columns.
  Existing rows default gracefully (`is_active=1`, others NULL/0).

**Step 2 — Read-only render of new columns** _(display only)_
- Add `advance_payment` and `mop2` columns to the Spreadsheet tab table header and
  body (contenteditable if `$can_edit`).
- Add `is_active` filter to the `SELECT *` query (only fetch `WHERE is_active = 1`).
- Add a "Void" button (replaces the current delete ✕) that sets `is_active = 0`
  instead of `DELETE`.
- A separate collapsed "Voided rows" section shows voided rows with an un-void button.
- Verify: add a row, void it, confirm it disappears from totals.

**Step 3 — PHP: embed service and therapist lists in page HTML**
- In the Spreadsheet tab PHP block, query:
  ```php
  $ss_services   = $conn->query("SELECT id, name, price FROM services ORDER BY name")->fetch_all(MYSQLI_ASSOC);
  $ss_therapists = /* duty-day-first query from §2 */;
  $ss_commissions = /* full therapist_commission table as nested map */;
  ```
- Output as `<script>window.SS_SERVICES = <?= json_encode($ss_services); ?>;</script>` etc.
- Verify: `console.log(window.SS_SERVICES)` shows the catalogue.

**Step 4 — Service dropdown and auto-fill** _(JS only)_
- Replace the `service_name` contenteditable cell with a `<select>` + freetext
  fallback (a `<datalist>` attached to a text `<input>` allows both typed entries and
  catalogue selections).
- On select: write `name` into the visible cell, `id` into `data-service-id`, and
  `price` into `regular_price`.
- Trigger commission auto-compute if `data-therapist-id` is also set.
- Verify: pick a service, confirm regular_price auto-fills and row saves correctly.

**Step 5 — Stylist dropdown and commission auto-compute** _(JS only)_
- Replace `stylist` contenteditable with a `<datalist>` input (same pattern as
  service).
- On select: write `full_name` into visible cell, `id` into `data-therapist-id`.
- Commission lookup from `window.SS_COMMISSIONS`; tier placement and peso amount as
  described in §3b.
- Net Sales auto-compute per §3c formula; `🔄` reset icon.
- Show ⚠ warning icon if no commission row found.
- Verify: pick therapist + service, confirm commission cell fills correctly, net_sales
  computes, row saves. Test missing-commission fallback.

**Step 6 — Mode of Payment dropdown** _(JS only)_
- Replace `mode_of_payment` contenteditable with a `<select>` using the static MOP
  list from §2. Same for `mop2`.
- Verify: saves correctly; blank `mop2` is stored as NULL.

**Step 7 — "Import from System" button** _(new AJAX handler)_
- New POST action: `action=import_system_rows`.
- Server-side: if `$spreadsheet_rows` is empty AND report not locked → iterate
  `$service_rows` and INSERT each as a `daily_report_spreadsheet_rows` row, mapping
  fields as in §4c.
- Redirect back to `?tab=spreadsheet` after import.
- Verify: on a date with system transactions and an empty spreadsheet, click import,
  confirm rows appear pre-filled.

**Step 8 — Wire advance_payment, commission into Summary totals** _(data layer)_
- In `_daily_report_data.php`, compute `$spreadsheet_advance_total`,
  `$spreadsheet_comm_total`, `$spreadsheet_celeb_total`, etc.
- Update `$net_cash` to subtract `$spreadsheet_advance_total` from the spreadsheet
  (replacing or adding to the system-derived `$advance_payment_total` — see Open
  Questions §7.Q3).
- Update `$staff_cf` to include `$spreadsheet_comm_total` (same question).
- Verify: enter a row with commission, confirm Summary STAFF CF increases.

**Step 9 — Wire export to spreadsheet rows** _(export file)_
- In `export_daily_report.php`, replace the `$service_rows` loop with a
  `$spreadsheet_rows` loop in the Sales Services section.
- Skip `is_active = 0` rows.
- Map columns as in §4c.
- Update Summary cell formulas that referenced service-log column ranges to reference
  the new row range.
- Verify: export a date with manual rows, confirm they appear in the service log
  section of the Excel file.

**Step 10 — Update Log tab banner** _(cosmetic)_
- Add the reconciliation banner to the Log tab as described in §4a.
- Verify: banner is visible; log tab still works correctly as audit view.

---

## 7. OPEN QUESTIONS (must confirm with client before building Steps 8–9)

**Q1 — Net Sales formula:** Does `net_sales` in the client's log = Promo Price −
commission only (current system), or = Promo Price − commission − celeb − PWD/staff
discounts? The answer changes both the auto-compute formula and the Summary totals.

**Q2 — "Promo Price" vs "Regular Price" as commission base:** The current system
computes commission on `charged_price` (i.e., what the client actually paid, which
may be the promo price). The client's Excel appears to use the same. Confirm before
encoding it.

**Q3 — System transactions vs manual log — coexistence or replacement?**
Should the Summary totals (Staff CF, Advance, Discounts) come from:
  (a) **Spreadsheet rows only** (manual log is the sole source — system rows are
      only shown in the Log tab for reference), or
  (b) **System rows + spreadsheet rows** (additive — receptionist adds manual rows
      for cash-only or walk-in sales not captured by the booking system)?

This is the most important design question. Option (a) requires the "Import from
System" step to be done every day before editing; Option (b) risks double-counting
if the same transaction is in both.

**Q4 — Advance Payment in the log:** The client's sheet shows advance_payment as a
per-row column in the sales log. Our system tracks advance_payment on the
appointment, not per service row. For manual entries, is the field a per-row amount
or a single figure per client visit?

**Q5 — Void vs Delete:** Should voided rows be shown to all roles (for audit), or
only to `is_full_access()` users? Should the cashier/receptionist be able to void
their own rows, or is voiding a manager-only action?

**Q6 — Export: add advance_payment and mop2 columns?** The gap analysis identified
these as missing. Confirm column positions in the client's template before adding
them (they would shift existing columns if inserted mid-table).

**Q7 — "TOTAL MARKETING EXPENSE" label on influencer section:** Changed to "TOTAL"
in Step 1 (label pass). Confirm the client is OK with this shorter label before
locking.
