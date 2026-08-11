# Export Gap Analysis — Recovery Spa Daily Report
**Source read:** `SALES REPORT RECOVERY SPA.xlsx`, sheet `"28"`  
**Compared against:** `admin/export_daily_report.php` + `admin/_daily_report_data.php`  
**Date of analysis:** 2026-08-08  

---

## 1. Cell-by-Cell Map of Sheet "28"

### 1.1 Sheet Meta / Header (rows 5–6)
| Cell | Value / Formula | Notes |
|------|----------------|-------|
| G5 | `"DATE:"` | label |
| H5 | `46201` | Excel serial date (2026-06-28) |
| G6 | `"OPENING CASHIER:"` | label |
| H6 | `"ANGEL"` | receptionist name (hardcoded) |
| I6 | `"CLOSING CASHIER: "` | label |
| J6 | `"ERA (TRAINEE)"` | receptionist name (hardcoded) |

---

### 1.2 Sales Services Log (rows 13–58, columns E–W)
**Section header:** E13 = `"Sales Services"`

**Column headers (row 14):**

| Col | Letter | Header label (exact) |
|-----|--------|----------------------|
| 5 | E | `"TIME IN "` |
| 6 | F | `"TIME OUT "` |
| 7 | G | `"Service Slip No."` |
| 8 | H | `"Client Name"` |
| 9 | I | `"Services"` |
| 10 | J | `"Stylist"` |
| 11 | K | `"Regular  Price"` (two spaces) |
| 12 | L | `"Promo price"` |
| 13 | M | `"CELEBRATION PROMO 10%"` |
| 14 | N | `"Disc 20% \n(PWD/SNR)"` |
| 15 | O | `"30% \nCommission fee"` |
| 16 | P | `"20% \nCommission fee"` |
| 17 | Q | `"15% \nCommission fee"` |
| 18 | R | `" 50%  DISC. FOR STAFF"` |
| 19 | S | `"Net Sales"` |
| 20 | T | `"Mode of Payment"` |
| **21** | **U** | **`"ADVANCE \nPAYMENT"`** ← extra col |
| **22** | **V** | **`"MOP"`** ← extra col |
| 23 | W | `"Remarks"` |

**Per-row formulas (data rows 15–57):**

| Col | Formula | Description |
|-----|---------|-------------|
| K | `=IFNA(VLOOKUP(I,services,2,FALSE),"")` | Regular Price — VLOOKUP from named range |
| L | `=IFNA(VLOOKUP(I,services,3,FALSE),"")` | Promo Price — VLOOKUP from named range |
| O | `=L*0.3` | 30% commission fee (of Promo Price) |
| P | `=L*0.2` | 20% commission fee (of Promo Price) |
| Q | `=L*0.15` | 15% commission fee (of Promo Price) |
| R | `=K*0.5` | 50% staff discount (of Regular Price) |
| S | **`=K-O-P-Q`** | **Net Sales = Regular Price − commissions** |
| U | *(numeric, entered per row)* | Advance payment amount for this appointment |
| V | *(text, entered per row)* | Second MOP / payment channel for this row |

**Totals row (row 58):**

| Cell | Formula | Computed value (test date) |
|------|---------|---------------------------|
| K58 | `=SUM(K15:K57)` | sum of Regular Prices |
| L58 | `=SUM(L15:L57)` | sum of Promo Prices |
| M58 | `=SUM(M15:M57)` | sum of Celebration 10% |
| N58 | `=SUM(N15:N57)` | sum of Disc 20% PWD/SNR |
| O58 | `=SUM(O15:O57)` | sum of 30% CF |
| P58 | `=SUM(P15:P57)` | sum of 20% CF |
| Q58 | `=SUM(Q15:Q57)` | sum of 15% CF |
| R58 | `=SUM(R15:R57)` | sum of 50% staff disc |
| S58 | `=SUM(S15:S57)` | sum of Net Sales |
| U58 | `=SUM(U16:U53)` | **644.50** — total advance payments |

---

### 1.3 Cash Breakdown (rows 15–33, columns A–C)
**Section header:** A15 = `"Cash Breakdown"`  
**Column headers (row 16):** A16=`"QTY"`, B16=`"DENOMINATION"`, C16=`"TOTAL COLLECTION"`

**Denomination rows (17–31):** B = denomination value, C = `=A*B`  
Denominations: 1000, 500, 200, 100, 50, 20, 10, 5, 1, 0.50, 0.10, 0.05

**Total (row 33):** A33=`"TOTAL"`, C33=`=SUM(C17:C31)` → **753.00** (test date)

---

### 1.4 Summary Report (rows 35–56, columns A–B)
**Section header:** A35 = `"SUMMARY REPORT :"`

| Cell | Label (exact) | Formula | Computed (test date) |
|------|--------------|---------|----------------------|
| A36 | `"GROSS SALES"` | `=B39+B38-B50` | — |
| A37 | `"STAFF CF"` | `=O58+P58+Q58+N76` | — |
| A38 | `"SOLD GC"` | `=T86` | 0 |
| A39 | `"POS READING"` | `=K58+O76+V104` | — |
| A40 | `"DISCOUNTS"` | **374.50 (hardcoded)** | 374.50 |
| A41 | `"CELEB. DISCOUNTS 10%"` | `=M58` | 0 |
| A42 | `"REEDEMED GC"` *(typo)* | *(blank/hardcoded)* | 0 |
| A43 | `"SWIPER"` | **8177.50 (hardcoded)** | 8177.50 |
| A44 | `"GCASH (SALES)"` | **0 (hardcoded)** | 0 |
| A45 | `"MAYA (SALES)"` | **0 (hardcoded)** | 0 |
| A46 | `"MAYA (DP)"` | *(blank/hardcoded)* | 0 |
| A47 | `"UNPAIDS"` | `=N90` | 2072.50 |
| A48 | `"ADVANCE PAYMENT"` | `=U58` | 644.50 |
| A49 | `"EXPENSES"` | `=H95` | 554.00 |
| A50 | `"MARKETING EXPENSE"` | `=O76` | 0 |
| A51 | `"PRODUCT SOLD"` | `=V105` | 0 |
| A52 | `"Net Cash"` | `=B39-B40-B41-B42-B43-B44-B45-B46-B47-B48-B49-B50-B51` | — |
| A53 | `"COH (Cash on Hand)"` | **753 (hardcoded)** | 753.00 |
| A56 | `"(Short )Over"` *(note space)* | `=B53-B52` | — |

> **No "QRPH" row exists in the client's summary.**

---

### 1.5 Influencer / Marketing (rows 62–76, columns E–N)
**Section header row 63:** E63=`"TIME"`, G63=`"Service Slip No."`, H63=`"Client Name"`, I63=`"Services"`, J63=`"Stylist"`, K63=`"AT COST"`, L63=*(blank)*, M63=`"TOTAL MKTG EXP."`, N63=`"Remarks"`  
**Sub-header row 64:** E64=`"Time Start"`, F64=`"Time End"`

**Per-row formulas (rows 65–75):**

| Col | Formula | Description |
|-----|---------|-------------|
| K | `=IFNA(VLOOKUP(I,MKTG,4,FALSE),"")` | At Cost — from named range MKTG |
| L | `=IFNA(VLOOKUP(I,MKTG,3,FALSE),"")` | Commission — from named range MKTG |
| M | `=K+L` | Total MKTG expense per row |

**Totals (row 76):**  
- L76 = `"TOTAL"` (label)  
- N76 = `=SUM(L65:L75)` → sum of commissions (feeds B37 STAFF CF)  
- O76 = *(formula, likely `=SUM(M65:M75)`)* → total MKTG expense, feeds B50

---

### 1.6 Expenses (rows 78–95, columns F–H)
**Section header:** F78 = `"EXPENSES"`  
**Column headers (row 79):** F79=`"•"`, G79=`"Particular"`, H79=`"Amount"`  
**Data rows (80–94):** F=`"•"`, G=description, H=amount (numeric)  
**Total (row 95):** F95=`"Total"`, G95=`"Total"`, H95=`=SUM(H80:H94)` → **554.00**

---

### 1.7 Unpaids Corp. (rows 78–90, columns J–N)
**Section header:** J78 = `"UNPAIDS CORP."`  
**Column headers (row 79):** J79=`"•"`, K79=`"Name"`, N79=`"Amount"`  
(L, M not labeled — merged or spacer)  
**Data rows (80–86):** J=`"•"`, K=client name, N=amount  
**Total row 87:** L87=`"Total"`, N87=`=SUM(N79:N86)` → 2072.50  
**Subtotal row 90:** K90=`"Total"`, N90=`=SUM(N80:N86)` → **2072.50** (referenced by B47)

---

### 1.8 Service GC (Sold) (rows 78–86, columns P–U)
**Section header:** P78 = `"SERVICE GC (SOLD)"`  
**Column headers (row 79):** P79=`"SERIES"`, Q79=`"NAME "`, R79=`"VOUCHER"`, S79=`"QTY"`, T79=`"Amount"`, U79=`"REMARKS"`  
**Data rows (80–85):** P=series, Q=name, R=voucher, S=qty, T=amount, U=remarks  
**Total row 86:** S86=`"Total"`, T86=`=SUM(T80:T85)` → **0** (referenced by B38 SOLD GC)

---

### 1.9 Paid GC / Redeemed (rows 92–103, columns J–P)
**Section header:** J92=`"PAID GC"`, J94=`"PAID GC(SOLD)"`  
**Column headers (row 95):** J95=`"SERIES"`, K95=`"NAME "`, L95=`"VOUCHER"`, N95=`"QTY"`, O95=`"Amount"`, P95=`"REMARKS"`  
**Data rows (95–102):** J=series, K=name, L=voucher, N=qty, O=amount, P=remarks  
**Total (row 103):** K103=`"Total"`, O103=`=SUM(O95:O102)` → 0

> *Note: B42 "REEDEMED GC" has no visible formula in the template — it appears to be manually entered or references this section's total.*

---

### 1.10 Product Sold (rows 95–105, columns R–V)
**Section header:** R95 = `"PRODUCT SOLD"`  
**Column headers (row 96):** R96=`"•"`, S96=`"Particular"`, T96=`"QTY"`, U96=`"PRICE"`, V96=`"Amount"`  
**Per-row formula:** V = `=T*U` (rows 97–103)  
**Subtotal:** U104=`"Total"`, V104=`=SUM(V97:V103)` → 0  
**Grand total:** V105=`=SUM(V97:V104)` → 0 (referenced by B51 PRODUCT SOLD)

---

## 2. Data Availability Audit

For each client column or summary line — does `_daily_report_data.php` already provide the data?

### 2.1 Sales Services Log Columns

| Client Col | Header | System field | Available? |
|-----------|--------|-------------|-----------|
| E | Time In | `DATE(a.appointment_date)` | ✅ |
| F | Time Out | computed via `duration_minutes` | ✅ |
| G | Service Slip No. | `o.slip_number` | ✅ |
| H | Client Name | `o.customer_name` | ✅ |
| I | Services | `s.name` via `COALESCE(s.name, '[Deleted Service]')` | ✅ |
| J | Stylist | `GROUP_CONCAT(t.full_name)` | ✅ |
| K | Regular Price | `s.price` → `svcRow['regular_price']` | ✅ |
| L | Promo Price | `a.charged_price` → `svcRow['charged_price']` | ✅ |
| M | Celebration Promo 10% | `a.celebration_discount` → `svcRow['celebration_discount']` | ✅ |
| N | Disc 20% PWD/SNR | `o.discount_amount` (when type=senior/pwd) | ✅ partial — only PWD/SNR discount, not all discounts |
| O | 30% Commission Fee | `SUM(at2.commission)` | ✅ stored value |
| P | 20% Commission Fee | `SUM(at2.commission)` | ✅ stored value |
| Q | 15% Commission Fee | `SUM(at2.commission)` | ✅ stored value |
| R | 50% Disc for Staff | `o.discount_amount` (when type=employee) | ✅ |
| S | Net Sales | derived | ✅ — but formula differs (see §3) |
| T | Mode of Payment | `o.payment_method` / `o.paymongo_method` | ✅ |
| **U** | **ADVANCE PAYMENT** | **`a.advance_payment` → `svcRow['advance_payment']`** | **✅ — field exists** |
| **V** | **MOP** (second payment col) | *No separate second-payment field per appointment* | **⚠️ PARTIAL — unclear what V means vs T; possibly `o.paymongo_method` vs `o.payment_method`** |
| W | Remarks | `a.status` + `a.rate_type` (our export puts status+rate here) | ⚠️ we put status/rate, client puts booking type ("APPOINTMENT", "WALK IN", "STAFF") |

**Key finding on U (ADVANCE PAYMENT):** `_daily_report_data.php` already fetches `a.advance_payment` per row. The column **data is available** — it just needs to be added to the export.

**Key finding on V (MOP):** The client's V column appears to record a secondary payment channel (e.g., T16="QR PH" and V16="GCASH" on the same row). Our system records one payment method per order. This may represent the payment method of the advance payment vs. the balance — needs clarification with client.

### 2.2 Summary Report Lines

| Client row | Label | Our system variable | Available? |
|-----------|-------|-------------------|-----------|
| B36 | GROSS SALES | `$gross_sales` | ✅ |
| B37 | STAFF CF | `$staff_cf` | ✅ |
| B38 | SOLD GC | `$gc_sold_total` | ✅ |
| B39 | POS READING | `$pos_reading` | ✅ — but formula source differs (see §3) |
| B40 | DISCOUNTS | `$total_discounts` | ✅ — but client hardcodes manually |
| B41 | CELEB. DISCOUNTS 10% | `$celeb_discount` | ✅ |
| B42 | REEDEMED GC | `$gc_redeem_total` | ✅ |
| B43 | SWIPER | `$card_total` | ✅ — but client hardcodes manually |
| B44 | GCASH (SALES) | `$gcash_total` | ✅ |
| B45 | MAYA (SALES) | `$maya_total` | ✅ |
| B46 | MAYA (DP) | `$maya_dp_total` | ✅ |
| B47 | UNPAIDS | `$unpaids_total` | ✅ |
| B48 | ADVANCE PAYMENT | `$advance_payment_total` | ✅ |
| B49 | EXPENSES | `$expenses_total` | ✅ |
| B50 | MARKETING EXPENSE | `$mktg_expense` | ✅ |
| B51 | PRODUCT SOLD | `$prod_sold_total` | ✅ |
| B52 | Net Cash | `$net_cash` | ✅ — but formula differs (see §3) |
| B53 | COH (Cash on Hand) | `$cash_on_hand` | ✅ |
| B56 | (Short )Over | `$short_over` | ✅ |
| — | **QRPH** | `$qrph_total` | **❌ — client has NO QRPH line** |

---

## 3. Concrete Differences Between Export and Client Template

### Diff 1 — Two extra columns in the Sales Services log
- **Client:** 19 columns E–W: ... S=Net Sales, **T=Mode of Payment**, **U=ADVANCE PAYMENT**, **V=MOP**, W=Remarks
- **Our export:** 17 columns E–U: ... S=Net Sales, T=Mode of Payment, U=Remarks (col 21)
- **Effect:** Client sheet has U (advance payment amount, per row) and V (secondary MOP) that our export omits. Remarks is shifted from col 23 (W) to col 21 (U) in our export.

### Diff 2 — Net Sales formula uses Regular Price (K), not Promo Price (L)
- **Client formula:** `=K-O-P-Q` (Regular Price minus commissions)
- **Our export formula:** `=L-O-P-Q` (Promo/charged price minus commissions)
- **Effect:** When a service is sold at promo price (L < K), the client's Net Sales is **higher** than ours. On a normal promo day where K=600, L=450, CF=L×0.3=135, client gets S=600-135=465, we get S=450-135=315. This is a significant money discrepancy.
- **Why our code uses L:** The comment in `export_daily_report.php` line 289 says "Net Sales = Promo Price − commissions (=L−O−P−Q, works for all tiers)". This was a deliberate choice that contradicts the client's formula.

### Diff 3 — Commission formulas: VLOOKUP-derived vs. stored values
- **Client:** O=`=L*0.3`, P=`=L*0.2`, Q=`=L*0.15` — formula-computed from Promo Price
- **Our export:** Writes actual stored values from `appointment_therapists.commission` (computed at booking time per therapist, not re-derived from the promo price column)
- **Effect:** If an appointment's commission was stored at a rate other than the flat ×0.3/×0.2/×0.15 of L, the numbers will differ. Also, if L comes from a VLOOKUP (client) vs. `charged_price` (us), commissions compound the formula difference.

### Diff 4 — Regular Price sourced from VLOOKUP (client) vs. database (us)
- **Client:** K=`=IFNA(VLOOKUP(I,services,2,FALSE),"")` — pulls from a named range called `services` in the workbook (likely a lookup table on another sheet)
- **Our export:** K=`(float)$svcRow['regular_price']` — from `services.price` in the database
- **Effect:** If the lookup table prices in the workbook differ from the live database prices, column K values will diverge. This is a data-source question only (prices should match), but the mechanism is different.

### Diff 5 — POS READING formula vs. manual entry
- **Client formula:** B39 = `=K58+O76+V104` → computed as sum of Regular Prices of all service rows + total influencer MKTG expense + total product sold
- **Our export:** B39 = `=H2` → references the manually-entered POS Reading from the report header (`daily_reports.pos_reading`)
- **Effect:** The client's POS Reading is entirely formula-derived from the sheet's own data. Our system uses a separate manually-entered field. These will only agree if the receptionist manually enters the correct total; otherwise they diverge.

### Diff 6 — PRODUCT SOLD deducted from Net Cash in client template, not in ours
- **Client:** B52 = `=B39-…-B51` where B51 = PRODUCT SOLD → products reduce net cash
- **Our export formula:** `=C{pos}-C{gcred}-C{swiper}-C{gcash}-C{maya}-C{qrph}-C{mayadp}-C{unp}-C{adv}-C{disc}-C{celeb}-C{exp}-C{mktg}` → no product sold deduction
- **Effect:** Our Net Cash is overstated vs. client's by the product sold amount whenever products are sold.

### Diff 7 — Our export includes QRPH summary row, client does not
- **Client:** No QRPH line exists in the summary (rows 35–56)
- **Our export:** Includes `['label' => 'QRPH', 'val' => $qrph_total, ...]` in `$sum_meta`
- **Effect:** Our export has 20 summary rows; client has 19. Row numbering for all formulas below GCASH shifts.

### Diff 8 — DISCOUNTS and SWIPER are hardcoded in client, formula-driven in ours
- **Client:** B40 (DISCOUNTS) = 374.5 hardcoded; B43 (SWIPER) = 8177.5 hardcoded; B44 (GCASH) = 0 hardcoded. These are entered manually from the POS machine / card terminal report.
- **Our export:** These are computed from order records (`$total_discounts`, `$card_total`, `$gcash_total`)
- **Effect:** For dates where payment terminal records differ from individual order records, amounts will diverge. This is an architectural difference in how the client uses the sheet vs. how we compute totals.

### Diff 9 — "Redeemed GC" label spelling and row presence
- **Client:** A42 = `"REEDEMED GC"` (typo, double-E)
- **Our export:** `"Redeemed GC"` (correct spelling)
- **Effect:** Cosmetic only — does not affect values.

### Diff 10 — Summary label differences
| Client label | Our export label |
|-------------|-----------------|
| `"GROSS SALES"` | `"Gross Sales"` |
| `"STAFF CF"` | `"Staff CF"` |
| `"SOLD GC"` | `"Sold GC"` |
| `"POS READING"` | `"POS Reading"` |
| `"DISCOUNTS"` | `"Discounts"` |
| `"CELEB. DISCOUNTS 10%"` | `"Celeb. Discounts 10%"` |
| `"REEDEMED GC"` | `"Redeemed GC"` |
| `"SWIPER"` | `"Swiper"` |
| `"GCASH (SALES)"` | `"GCash"` |
| `"MAYA (SALES)"` | `"Maya"` |
| `"MAYA (DP)"` | `"Maya (DP)"` |
| `"UNPAIDS"` | `"Unpaids"` |
| `"MARKETING EXPENSE"` | `"Marketing Expense"` |
| `"ADVANCE PAYMENT"` | `"Advance Payment"` |
| `"PRODUCT SOLD"` | `"Product Sold"` |
| `"Net Cash"` | `"Net Cash"` |
| `"COH (Cash on Hand)"` | `"COH (Cash on Hand)"` |
| `"(Short )Over"` *(space before Over)* | `"(Short)/Over"` |
| *(no QRPH)* | `"QRPH"` |

### Diff 11 — Influencer section column positions
- **Client:** Uses columns E–N (shares column band with service log above it). Columns: E=Time Start, F=Time End, G=Slip, H=Client, I=Services, J=Stylist, K=AT COST, L=Commission, M=Total MKTG Exp, N=Remarks
- **Our export:** Uses columns A–J (1–10). Same logical order, different physical columns.
- **Effect:** The export is structurally correct but column positions differ. Not a blocking issue for readability, but prevents direct cell-reference chaining with the service log above.

### Diff 12 — Remarks column content
- **Client W column (Remarks):** Text such as `"APPOINTMENT"`, `"WALK IN"`, `"STAFF"`, `"CHARGE TO IQOR"`
- **Our export U column:** `ucfirst($svcRow['appt_status']).' · '.strtoupper($svcRow['rate_type'])` → e.g. `"Completed · REGULAR"`
- **Effect:** The client uses this column for booking source/type. Our code writes status+rate instead.

---

## 4. Proposed Alignment Plan (Safest-First Order)

### Step 1 — Fix summary label casing and spelling (cosmetic only)
**File:** `export_daily_report.php`, `$sum_meta` array  
Change label strings to uppercase and match client exactly:
`"GROSS SALES"`, `"STAFF CF"`, `"SOLD GC"`, `"POS READING"`, `"DISCOUNTS"`, `"CELEB. DISCOUNTS 10%"`, `"REEDEMED GC"`, `"SWIPER"`, `"GCASH (SALES)"`, `"MAYA (SALES)"`, `"MAYA (DP)"`, `"UNPAIDS"`, `"MARKETING EXPENSE"`, `"ADVANCE PAYMENT"`, `"PRODUCT SOLD"`, `"Net Cash"`, `"COH (Cash on Hand)"`, `"(Short )Over"`.  
**Risk:** Zero — labels only.

### Step 2 — Remove the QRPH row from the export summary
**File:** `export_daily_report.php`, `$sum_meta` array  
Remove `['QRPH', '', false]` entry and all references to `$sr_qrph`. Adjust the Net Cash formula to not subtract QRPH.  
**Risk:** Low — but verify $qrph_total is always 0 or already absorbed into GCASH in client's usage before removing.

### Step 3 — Add the two extra Sales Services columns (U: ADVANCE PAYMENT, V: MOP)
**File:** `export_daily_report.php`  
- Widen column widths: shift Remarks from col 21 (U) to col 23 (W); add U=ADVANCE PAYMENT (9pt), V=MOP (9pt)
- In each service row, write `(float)$svcRow['advance_payment']` at col 21, and the secondary MOP at col 22 (confirm with client what V = see §5b)
- Write `ucfirst($svcRow['appt_status'])` (or the booking-type text) at col 23
- Update the `U58` SUM formula to `=SUM(U16:U{last})` in the totals row
- Update the column-width array for the new cols
- Update `const SVC_C2 = 23` and all merge/range calls  
**Risk:** Medium — column index changes affect all subsequent formulas.

### Step 4 — Fix Remarks column content to match client usage
**File:** `export_daily_report.php`  
Change `ucfirst($svcRow['appt_status']).' · '.strtoupper($svcRow['rate_type'])` to write booking-type text only (e.g. `"APPOINTMENT"` for normal bookings, `"WALK IN"` for walk-ins, `"STAFF"` for employee rate_type). Map `rate_type` → client remark string.  
**Risk:** Low.

### Step 5 — Add PRODUCT SOLD deduction to Net Cash formula
**File:** `export_daily_report.php`, deferred summary formula for `$sr_net`  
Append `-C{$sr_prod}` to the Net Cash formula string.  
**Risk:** Low to medium — verify this matches the PHP `$net_cash` calculation in `_daily_report_data.php` (it currently does NOT deduct product sold from `$net_cash`). Both `_daily_report_data.php` and `export_daily_report.php` need the same logic. Update `$net_cash` in `_daily_report_data.php` first if aligning to client, then update the export formula.

### Step 6 — Fix the Net Sales formula: K−O−P−Q instead of L−O−P−Q
**File:** `export_daily_report.php`, rows where `setCellValue([19,$R], "=L{$R}-O...")` is called  
Change to `"=K{$R}-O{$R}-P{$R}-Q{$R}"` for regular service rows and add-on rows.  
**Risk:** High — this changes reported revenue numbers. Confirm with client before doing (see §5b). If commissions are also sourced from L×rate formula rather than stored values, commission cells need updating too.

### Step 7 — Align commission formulas: stored values vs. L×rate
**File:** `export_daily_report.php`  
Currently: commission amounts are static PHP values (from `appointment_therapists.commission`).  
Client template: O=`=L*0.3`, P=`=L*0.2`, Q=`=L*0.15` — live Excel formulas.  
Option A: Keep writing stored values (safe, matches what was actually paid).  
Option B: Write live formulas `=L{$R}*0.3` / `=L{$R}*0.2` / `=L{$R}*0.15`.  
**Risk:** High for Option B (if a commission was overridden, the formula will differ from the actual payout). Recommend Option A unless client specifically requests Option B.

### Step 8 — Address POS READING source (low priority / confirm with client)
**File:** `export_daily_report.php`  
Client: `=K58+O76+V104` (derived from service totals). Ours: `=H2` (manual entry).  
If client wants a formula-derived POS Reading, change `$sr_pos` formula to reference the service log K-totals row + influencer + product. This requires re-ordering the write sequence to ensure all sub-ledger section totals are written before the summary formula.  
**Risk:** Medium — architectural. Only do this if client confirms the formula-derived version is authoritative.

---

## 5. Open Questions for the Client

### 5a — Price source: database vs. workbook VLOOKUP table
The client template uses `=IFNA(VLOOKUP(I, services, 2, FALSE), "")` to populate Regular Price (K) and `=IFNA(VLOOKUP(I, services, 3, FALSE), "")` for Promo Price (L), pulling from a named range called `services` that lives in another sheet of the workbook.

Our system pulls these values from the database (`services.price` for regular, `appointments.charged_price` for promo).

**Question:** Do the prices in your Excel lookup table match the prices currently in the system database? If a service price changes, do you update both the Excel named range AND the system? Going forward, should the export derive prices from the database (as it does now) or does the Excel named range have values the database doesn't?

### 5b — Confirming Net Sales and Net Cash formulas are the current correct ones
Two critical formula choices need client sign-off:

1. **Net Sales = K−O−P−Q (Regular Price) or L−O−P−Q (Promo Price)?**  
   The client template uses K (Regular Price) in Net Sales. Our export currently uses L (Promo/Charged Price). On promo-price days these give different totals. Which is the correct basis for Net Sales in the cash report?

2. **Net Cash: should PRODUCT SOLD be deducted?**  
   Client template B52 = `=B39−…−B51` where B51 = PRODUCT SOLD. Our PHP currently does NOT deduct product sold from Net Cash. Is B51 intentionally a deduction (products are paid and leave the cash drawer), or is it informational only?

3. **MOP column (V) — what does it mean?**  
   Some rows have T≠V (e.g. T="QR PH", V="GCASH" on the same appointment). Is T the advance/deposit payment method and V the balance payment method? Or does one column track the appointment booking payment and the other the in-branch payment on the day?

---

*Analysis produced 2026-08-08. Re-verify against a fresh copy of the xlsx before implementing Step 6 (Net Sales formula change) as it directly affects revenue figures.*
