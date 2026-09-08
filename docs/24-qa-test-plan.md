# 24 — QA Test Plan

> **Document purpose.** The QA engineer's working document: concrete test cases organised by the test
> types required for this system, with traceability back to requirements. [23](23-testing-strategy.md)
> defines *how* we test; this defines *what* is tested.

**Prerequisites:** [03-requirements.md](03-requirements.md) (requirement IDs),
[23-testing-strategy.md](23-testing-strategy.md) (framework).

**Status:** 🟡 MVP — Planned.

---

## 1. Scope and identification

```text
TC-<AREA>-<NNN>     Test case
```

Areas: `FUNC` functional · `API` · `INTEG` integration · `REG` regression · `AUTHZ` authorisation ·
`CALC` calculation · `INV` inventory · `CONC` concurrency · `PERF` performance · `SEC` security ·
`EDGE` edge case · `NEG` negative.

| Priority | Meaning |
|---|---|
| **P1** | Blocks release. Money, stock, security, data integrity. |
| **P2** | Major functionality. Fix before release unless a workaround exists. |
| **P3** | Minor. May ship with a known-issue note. |

---

## 2. Entry and exit criteria

**Entry to QA**

- Feature complete against its requirement IDs
- Unit and feature tests written and passing in CI
- No known P1 defects
- Test data and environment available

**Exit / release sign-off**

- All P1 cases pass
- ≥ 95 % of P2 cases pass, remainder documented
- Zero open P1 defects; P2 defects triaged with owners
- Coverage thresholds met ([23](23-testing-strategy.md) §9)
- Performance targets met ([25](25-performance-testing.md))
- Security checklist complete ([22](22-security.md) §14)
- Manual checklist complete (§13)

---

## 3. Test coverage matrix

| Requirement group | Cases | Primary sections |
|---|---|---|
| FR-AUTH | 24 | §5 |
| FR-RBAC | 32 | §6 |
| FR-BRN, FR-CAT | 26 | §4.4 |
| FR-POS | 30 | §4.1 |
| FR-ORD | 38 | §4.2 |
| FR-PAY | 34 | §7.2 |
| FR-KDS | 22 | §4.3 |
| FR-INV | 40 | §8 |
| FR-TRF | 20 | §8.4 |
| FR-CUS | 24 | §7.4 |
| FR-EXP | 18 | §4.5 |
| FR-RPT | 22 | §7.5 |
| FR-NTF | 14 | §4.6 |
| FR-AUD | 16 | §11 |
| NFR-PERF | 12 | §10, [25](25-performance-testing.md) |
| NFR-SEC | 30 | §11 |

---

## 4. Functional testing

### 4.1 POS — `TC-FUNC-0xx`

| ID | Pri | Case | Expected | Req |
|---|---|---|---|---|
| TC-FUNC-001 | P1 | Complete a cash sale end to end | Order completed, receipt produced, cart cleared | FR-POS-001..011 |
| TC-FUNC-002 | P1 | Single-branch cashier sees no branch selector | Home branch auto-selected | FR-POS-001 |
| TC-FUNC-003 | P2 | Multi-branch user selects a branch | Products, prices and stock reflect the choice | FR-POS-001 |
| TC-FUNC-004 | P2 | Browse by category | Only active products of that category, ordered by `sort_order` | FR-POS-002 |
| TC-FUNC-005 | P2 | Search by name and by SKU | Both return the product in < 300 ms | FR-POS-002 |
| TC-FUNC-006 | P1 | Add product with variants | Variant picker appears, default pre-selected | FR-CAT-004 |
| TC-FUNC-007 | P1 | Add same product twice | Lines merge, quantity 2 | — |
| TC-FUNC-008 | P2 | Same product, different notes | Two separate lines | EDGE |
| TC-FUNC-009 | P1 | Set quantity to 0 | Line removed | FR-POS-003 |
| TC-FUNC-010 | P1 | Fractional quantity 0.5 | Accepted, priced correctly | — |
| TC-FUNC-011 | P1 | Apply 10 % order discount as Cashier | Accepted, allocated pro-rata | FR-POS-004 |
| TC-FUNC-012 | P1 | Apply 25 % as Cashier (cap 10 %) | `403 discount_limit_exceeded` | FR-POS-005 |
| TC-FUNC-013 | P1 | Manager authorises the 25 % | Accepted; `discount_approved_by` set; audited | FR-POS-005 |
| TC-FUNC-014 | P1 | Apply line-level discount | Only that line reduced | FR-POS-004 |
| TC-FUNC-015 | P1 | Attach customer, then redeem points | Discount applied, points debited | FR-CUS-004 |
| TC-FUNC-016 | P1 | Server totals are authoritative | Manipulated client price ignored | FR-POS-006 |
| TC-FUNC-017 | P2 | Dine-in requires a table number when configured | `422` without it | FR-POS-007 |
| TC-FUNC-018 | P2 | Takeaway rejects a table number | `422` | EDGE |
| TC-FUNC-019 | P1 | Sale blocked when a recipe cannot be fulfilled | `422 insufficient_stock` with shortfalls | FR-POS-008 |
| TC-FUNC-020 | P2 | Cart survives reload | Lines restored, re-priced | FR-POS-010 |
| TC-FUNC-021 | P2 | Price change while in cart | New price shown and highlighted | EDGE |
| TC-FUNC-022 | P2 | Receipt contents complete | All fields per [10](10-pos-workflow.md) §3.12 | FR-POS-011 |
| TC-FUNC-023 | P2 | Reprint within cap | Succeeds and is audited | — |
| TC-FUNC-024 | P2 | Reprint beyond cap | `403` | — |
| TC-FUNC-025 | P1 | 100 % discount | Total is tax only; completes with no tender | EDGE |

### 4.2 Orders — `TC-FUNC-1xx`

| ID | Pri | Case | Expected | Req |
|---|---|---|---|---|
| TC-FUNC-101 | P1 | Full lifecycle Pending→Completed | All transitions succeed, timestamps set | FR-ORD-001 |
| TC-FUNC-102 | P1 | Order number unique per branch per day | Format `B1-YYYYMMDD-NNNN` | FR-ORD-002 |
| TC-FUNC-103 | P1 | Snapshots immutable | Reprice/rename product; order unchanged | FR-ORD-003, FR-CAT-007 |
| TC-FUNC-104 | P1 | Cancel before acceptance | No stock movement | FR-ORD-004 |
| TC-FUNC-105 | P1 | Cancel after acceptance | Stock restored via `sale_reversal` | FR-ORD-005 |
| TC-FUNC-106 | P1 | Cancel without reason | `422` | FR-ORD-004 |
| TC-FUNC-107 | P1 | Complete with balance outstanding | `422 order_not_settled` | FR-ORD-006 |
| TC-FUNC-108 | P1 | Complete when fully paid | Succeeds; loyalty awarded | FR-ORD-006 |
| TC-FUNC-109 | P1 | Every illegal transition | `422 invalid_state_transition` (36-pair matrix) | FR-ORD-001 |
| TC-FUNC-110 | P1 | Status history complete | One row per transition; last row matches status | FR-ORD-007 |
| TC-FUNC-111 | P2 | Edit a Pending order | Totals recalculated | FR-ORD-008 |
| TC-FUNC-112 | P2 | Edit an Accepted order without permission | `422` | FR-ORD-008 |
| TC-FUNC-113 | P1 | Edit after accept with permission | Stock **delta** applied, not reverse-and-rededuct | A7 |
| TC-FUNC-114 | P2 | Void requires elevated permission | Cashier `403`; Manager succeeds, audited | FR-ORD-010 |
| TC-FUNC-115 | P2 | Filter orders by status, date, cashier, customer | Combined filters, branch-scoped | FR-ORD-009 |
| TC-FUNC-116 | P1 | Business date fixed at creation | 23:58 order stays on the earlier day | BR-RPT-02 |

### 4.3 Kitchen — `TC-FUNC-2xx`

| ID | Pri | Case | Expected | Req |
|---|---|---|---|---|
| TC-FUNC-201 | P1 | Ticket appears after acceptance | Within one poll interval | FR-KDS-001 |
| TC-FUNC-202 | P1 | Routing by category station | Drinks to BAR, food to GRILL | FR-KDS-004 |
| TC-FUNC-203 | P2 | Category without a station | Falls back to parent, then branch default | BR-KDS-02 |
| TC-FUNC-204 | P1 | Queued→Preparing→Ready | Order status follows | FR-KDS-002/003 |
| TC-FUNC-205 | P1 | Partial readiness | Order stays Preparing until all tickets ready | BR-KDS-07 |
| TC-FUNC-206 | P2 | Skip preparing without permission | `422` | FR-KDS-002 |
| TC-FUNC-207 | P2 | Recall from Ready | Order returns to Preparing | A6 |
| TC-FUNC-208 | P1 | Cancelled order removes tickets | Gone within one poll | FR-KDS-007 |
| TC-FUNC-209 | P1 | KDS payload has no money or PII | Automated key scan | G13 |
| TC-FUNC-210 | P2 | Late ticket flagged | `is_late` true past SLA | FR-KDS-005 |
| TC-FUNC-211 | P2 | Elapsed time independent of device clock | Correct with client clock skewed 1 h | EDGE |
| TC-FUNC-212 | P2 | Item-level completion | Ticket completes when last item ready | FR-KDS-009 |

### 4.4 Catalogue and branches — `TC-FUNC-3xx`

| ID | Pri | Case | Expected |
|---|---|---|---|
| TC-FUNC-301 | P2 | Category CRUD with ordering | Inactive hidden from POS, visible in admin |
| TC-FUNC-302 | P2 | Two-level nesting allowed, three rejected | `422` on depth 3 |
| TC-FUNC-303 | P1 | Duplicate SKU rejected | `422` |
| TC-FUNC-304 | P2 | SKU reusable after soft delete | Succeeds |
| TC-FUNC-305 | P1 | Branch price override | Same product, different price per branch |
| TC-FUNC-306 | P1 | Product with sales cannot be hard-deleted | Soft delete only; history intact |
| TC-FUNC-307 | P2 | 86 a product | Disappears from POS immediately |
| TC-FUNC-308 | P1 | Branch code unique | `422` on duplicate |
| TC-FUNC-309 | P1 | Currency immutable after orders exist | `422 currency_immutable` |
| TC-FUNC-310 | P2 | Deactivated branch rejects new orders | `422 branch_inactive`; reports still work |

### 4.5 Expenses — `TC-FUNC-4xx`

| ID | Pri | Case | Expected | Req |
|---|---|---|---|---|
| TC-FUNC-401 | P1 | **Self-approval denied** | `403 self_approval_forbidden` | FR-EXP-004 |
| TC-FUNC-402 | P1 | Approval by another user succeeds | `approved_by` recorded | FR-EXP-002 |
| TC-FUNC-403 | P1 | Approval above limit | `403 approval_limit_exceeded` | FR-EXP-003 |
| TC-FUNC-404 | P1 | Approved expense not editable | `422 expense_locked` | FR-EXP-007 |
| TC-FUNC-405 | P2 | Draft deletable, submitted not | `422` on non-draft | — |
| TC-FUNC-406 | P2 | Receipt upload valid types | Accepted; signed URL returned | FR-EXP-005 |
| TC-FUNC-407 | P1 | PHP file renamed `.jpg` rejected | `422 invalid_file_type`, audited | SEC |
| TC-FUNC-408 | P1 | Approved expense in profit report | Appears under `expense_date` | FR-EXP-006 |
| TC-FUNC-409 | P2 | Rejection requires a reason | `422` | — |
| TC-FUNC-410 | P2 | Future-dated beyond tolerance | `422` | FR-EXP-001 |

### 4.6 Notifications — `TC-FUNC-5xx`

| ID | Pri | Case | Expected | Req |
|---|---|---|---|---|
| TC-FUNC-501 | P1 | Low stock notifies managers at that branch only | Correct recipients | FR-NTF-001/002 |
| TC-FUNC-502 | P1 | 50 consecutive low-stock triggers | Exactly one notification | FR-NTF-004 |
| TC-FUNC-503 | P2 | Restock then dip again | Second notification sent | BR-NTF-03 |
| TC-FUNC-504 | P1 | Expense submitter not notified of own submission | Excluded | §19 |
| TC-FUNC-505 | P2 | Cashier receives no expense notification | Not a recipient | FR-NTF-002 |
| TC-FUNC-506 | P2 | Mark read / mark all read | Count decrements correctly | FR-NTF-003 |
| TC-FUNC-507 | P1 | Rolled-back transaction sends nothing | No notification | N5 |
| TC-FUNC-508 | P1 | Payload has no cost or PII | Automated key scan | N6 |
| TC-FUNC-509 | P2 | Critical severity cannot be muted | `422` | §19 §5 |

---

## 5. Authentication testing — `TC-AUTH-xxx`

| ID | Pri | Case | Expected | Req |
|---|---|---|---|---|
| TC-AUTH-001 | P1 | Valid login | `201` + working token | FR-AUTH-001 |
| TC-AUTH-002 | P1 | Wrong password | `401 invalid_credentials` | FR-AUTH-001 |
| TC-AUTH-003 | P1 | Unknown email | **Byte-identical** response to TC-AUTH-002 | SEC |
| TC-AUTH-004 | P1 | Timing parity unknown vs wrong | Difference within tolerance | SEC |
| TC-AUTH-005 | P1 | 6th failure in 15 min | `429` even with correct password | FR-AUTH-002 |
| TC-AUTH-006 | P1 | Lockout applies per IP as well as per account | Both counters observed | FR-AUTH-002 |
| TC-AUTH-007 | P1 | Deactivated user with a valid token | `403 account_inactive`; tokens revoked | FR-AUTH-005 |
| TC-AUTH-008 | P1 | Logout invalidates the token | Next call `401` | FR-AUTH-003 |
| TC-AUTH-009 | P1 | Logout-all invalidates every token | All `401` | FR-AUTH-003 |
| TC-AUTH-010 | P1 | Absolute expiry | `401 token_expired` | FR-AUTH-004 |
| TC-AUTH-011 | P1 | Idle expiry | `401 token_idle_expired` | FR-AUTH-004 |
| TC-AUTH-012 | P2 | Password change revokes other tokens, keeps current | Verified | FR-AUTH-007 |
| TC-AUTH-013 | P2 | Reset token single use | Second attempt `422` | FR-AUTH-008 |
| TC-AUTH-014 | P2 | Reset for unknown email | `200`, no email sent, no disclosure | FR-AUTH-008 |
| TC-AUTH-015 | P1 | `GET /auth/me` returns roles, permissions, scope, thresholds | All present | FR-AUTH-009 |
| TC-AUTH-016 | P1 | PIN login yields `pos:*` only | Admin route `403 insufficient_token_ability` | FR-AUTH-011 |
| TC-AUTH-017 | P1 | Super Admin on a PIN token | Still `403` on `/admin/*` | §8 |
| TC-AUTH-018 | P2 | Trivial PIN rejected at set time | `422` | §8 |
| TC-AUTH-019 | P2 | Weak/breached password rejected | `422` | NFR-SEC-002 |
| TC-AUTH-020 | P2 | Token pruning beyond max per user | Oldest removed | E8 |

---

## 6. Authorization testing — `TC-AUTHZ-xxx`

| ID | Pri | Case | Expected | Req |
|---|---|---|---|---|
| TC-AUTHZ-001 | P1 | **Route coverage sweep** — every route with an unprivileged token | `403` for all | FR-RBAC-004 |
| TC-AUTHZ-002 | P1 | Permission matrix conformance | Seeded grants equal [04](04-user-roles-permissions.md) §4 exactly | FR-RBAC-001 |
| TC-AUTHZ-003 | P1 | **Cross-branch read** for every branch-owned resource | `404`, never `403` | FR-RBAC-005 |
| TC-AUTHZ-004 | P1 | Cross-branch write | `404` | FR-RBAC-005 |
| TC-AUTHZ-005 | P1 | Body `branch_id` disagreeing with active branch | `422 branch_mismatch` | B3 |
| TC-AUTHZ-006 | P1 | `X-Branch-Id` outside scope | `403 branch_out_of_scope` | B1 |
| TC-AUTHZ-007 | P1 | Transfer visible from source and destination, not a third branch | Correct | §15 §10 |
| TC-AUTHZ-008 | P1 | Union of two roles | Both capability sets available | FR-RBAC-003 |
| TC-AUTHZ-009 | P1 | Manager assigns themselves Admin | `403 privilege_escalation` | G1, FR-RBAC-011 |
| TC-AUTHZ-010 | P1 | Admin creates another Admin | `403` (rank ≥ own) | G1 |
| TC-AUTHZ-011 | P1 | User modifies own roles | `403 self_role_modification` | G2 |
| TC-AUTHZ-012 | P1 | Delete last Super Admin | `422 last_super_admin` | FR-RBAC-008 |
| TC-AUTHZ-013 | P1 | Deactivate last Super Admin | `422` | FR-RBAC-008 |
| TC-AUTHZ-014 | P1 | Demote last Super Admin | `422` | FR-RBAC-008 |
| TC-AUTHZ-015 | P1 | Delete a system role | `403` | FR-RBAC-009 |
| TC-AUTHZ-016 | P1 | **Cashier response contains no `cost_price`** | Field **absent**, not null | G11 |
| TC-AUTHZ-017 | P1 | Cashier profit report | `403` | G12 |
| TC-AUTHZ-018 | P1 | Kitchen order projection | No prices, totals, phone or email | G13 |
| TC-AUTHZ-019 | P2 | Cashier without `orders.view_all_users` | Sees only own orders | G14 |
| TC-AUTHZ-020 | P1 | Transfer approved by destination manager | `403 not_source_branch_authority` | G6 |
| TC-AUTHZ-021 | P1 | Permission revoked mid-session | Next request denied, no re-login | E1 |
| TC-AUTHZ-022 | P2 | User with zero roles | Authenticates, then `403` everywhere | E2 |
| TC-AUTHZ-023 | P1 | Discount threshold boundaries | cap−0.01 ✔, cap ✔, cap+0.01 ✗ | §7 |
| TC-AUTHZ-024 | P1 | Refund window boundary | Within ✔, beyond `403` | §7 |

---

## 7. Calculation testing — `TC-CALC-xxx`

**The highest-priority group in this plan.**

### 7.1 Order totals

| ID | Pri | Case | Expected |
|---|---|---|---|
| TC-CALC-001 | P1 | **Golden example** | subtotal `15.00`, discount `1.50`, tax `0.94`, total `14.44` |
| TC-CALC-002 | P1 | Tax is the **sum of line taxes**, not `taxable × rate` | `0.94`, not `0.95` |
| TC-CALC-003 | P1 | Line subtotal rounding | `ROUND(unit × qty, 2)` half-up |
| TC-CALC-004 | P1 | Pro-rata allocation sums exactly | `Σ line_discount == order_discount` for 1000 random carts |
| TC-CALC-005 | P1 | Allocation determinism | Same cart, different add order ⇒ identical allocation |
| TC-CALC-006 | P1 | Remainder to the largest line | 0.10 over 3 × 1.00 ⇒ 0.03/0.03/0.04 |
| TC-CALC-007 | P1 | Exclusive tax | `9.90 × 0.07 = 0.69` |
| TC-CALC-008 | P1 | Inclusive tax | `10.59` gross ⇒ tax `0.69`, net `9.90` |
| TC-CALC-009 | P1 | Mixed inclusive and exclusive lines | Each per its snapshot |
| TC-CALC-010 | P1 | Zero-rated product | tax `0.00`, not an error |
| TC-CALC-011 | P1 | Discount before tax | Tax on the discounted amount |
| TC-CALC-012 | P1 | Discount = subtotal | Total is tax only |
| TC-CALC-013 | P1 | Discount > subtotal | `422 discount_exceeds_total` |
| TC-CALC-014 | P1 | Discounts stack additively | 10 % tier + 10 % manual = 20 % |
| TC-CALC-015 | P1 | Service charge with each configured base | Correct per §7 |
| TC-CALC-016 | P1 | Cash rounding to 0.25 | `14.44 → 14.50`, adjustment `+0.06` |
| TC-CALC-017 | P1 | Rounding applied once on split payment | Not per tender |
| TC-CALC-018 | P1 | **No JSON floats** in any money field | Automated scan of all responses |
| TC-CALC-019 | P1 | Float in a request money field | `422 invalid_money_format` |
| TC-CALC-020 | P1 | 200-line order | Allocation still sums exactly |
| TC-CALC-021 | P1 | Missing tax configuration | `500 configuration_missing`, **not** zero tax |
| TC-CALC-022 | P1 | No hard-coded rates in `app/Domain` | CI grep passes |
| TC-CALC-023 | P1 | 10 000 random orders | `Σ line_total` reconciles to `grand_total`, zero drift |

### 7.2 Payments

| ID | Pri | Case | Expected |
|---|---|---|---|
| TC-CALC-101 | P1 | Exact settlement | `paid`, balance `0.00` |
| TC-CALC-102 | P1 | Change | tendered `20.00`, amount `14.44` ⇒ change `5.56` |
| TC-CALC-103 | P1 | `amount` > balance | `422 overpayment` |
| TC-CALC-104 | P1 | tendered < amount | `422 tendered_less_than_amount` |
| TC-CALC-105 | P1 | Split card + cash to zero | `paid` |
| TC-CALC-106 | P1 | Third payment on a paid order | `422 order_already_paid` |
| TC-CALC-107 | P1 | `paid_total` after void | Recomputed, not decremented |
| TC-CALC-108 | P1 | Partial refunds to the full amount | Then `422 refund_exceeds_payment` |
| TC-CALC-109 | P1 | `paid_total` integrity | Random sequence of pay/void/refund reconciles |
| TC-CALC-110 | P1 | Drawer expected cash | Change not double-counted |

### 7.3 Costing

| ID | Pri | Case | Expected |
|---|---|---|---|
| TC-CALC-201 | P1 | Weighted average sequence | Matches [14](14-inventory-workflow.md) §6 table exactly |
| TC-CALC-202 | P1 | Consumption leaves average unchanged | Verified |
| TC-CALC-203 | P1 | Stock-in at zero balance | Average = incoming cost |
| TC-CALC-204 | P2 | Stock-in at negative balance | Average reset, warning logged |
| TC-CALC-205 | P1 | Recipe unit cost | Cappuccino `0.6330` per [09](09-business-rules.md) §10.2 |
| TC-CALC-206 | P1 | Cost snapshot immutable | Ingredient cost change does not alter past orders |

### 7.4 Loyalty

| ID | Pri | Case | Expected |
|---|---|---|---|
| TC-CALC-301 | P1 | Earn floors | `13.50 × 1.5 = 20.25` ⇒ 20 |
| TC-CALC-302 | P1 | Earn only at completion | Nothing at create, accept or payment |
| TC-CALC-303 | P1 | Redeem below minimum | `422 below_minimum_redemption` |
| TC-CALC-304 | P1 | Redeem off-increment | `422 invalid_redeem_increment` |
| TC-CALC-305 | P1 | Redeem above cap | `422 redemption_cap_exceeded`, **nothing redeemed** |
| TC-CALC-306 | P1 | Redeem more than balance | `422 insufficient_points` |
| TC-CALC-307 | P1 | Partial refund reversal | `FLOOR(earned × refund ÷ total)` |
| TC-CALC-308 | P1 | Earn, spend, refund | Balance clamps at 0, shortfall recorded |
| TC-CALC-309 | P1 | Ledger sum equals balance | 1000 random movements |
| TC-CALC-310 | P2 | Tier promotion boundary | At threshold −1, threshold, +1 |

### 7.5 Reports

| ID | Pri | Case | Expected |
|---|---|---|---|
| TC-CALC-401 | P1 | Sales reconciliation check 1 | `Σ grand_total == Σ captured payments` |
| TC-CALC-402 | P1 | Sales reconciliation check 2 | Components sum to grand total |
| TC-CALC-403 | P1 | **Tax excluded from revenue** | Revenue ≠ `Σ grand_total` |
| TC-CALC-404 | P1 | Profit worked example | Matches [18](18-reporting.md) §6.3 |
| TC-CALC-405 | P1 | Stock movement identity | `opening + in − out + adj = closing` |
| TC-CALC-406 | P1 | Refund in a later period | Reduces revenue in the refund period only |
| TC-CALC-407 | P1 | Business day with 04:00 start | 01:30 order on the previous trading day |
| TC-CALC-408 | P1 | Two branches, different timezones | Each uses its own window |
| TC-CALC-409 | P2 | Zero revenue | Margin `0`, no exception |
| TC-CALC-410 | P2 | Report determinism | Same parameters ⇒ identical output including tie-breaks |

---

## 8. Inventory testing — `TC-INV-xxx`

| ID | Pri | Case | Expected | Req |
|---|---|---|---|---|
| TC-INV-001 | P1 | **Cappuccino × 2** | milk `−0.4000 l`, coffee `−0.0400 kg` | FR-INV-005 |
| TC-INV-002 | P1 | Wastage 5 % on 0.20 L | `0.2100` | FR-INV-012 |
| TC-INV-003 | P1 | g→kg conversion in a recipe | Correct deduction | FR-INV-002 |
| TC-INV-004 | P1 | ml→l conversion | Correct | FR-INV-002 |
| TC-INV-005 | P1 | Cross-family unit | `422 unit_family_mismatch` | FR-INV-003 |
| TC-INV-006 | P1 | Batch yield 10, sell 3 | 0.3 of the batch | — |
| TC-INV-007 | P1 | Three lines using milk | **One** aggregated ledger row | §14 §4.1 |
| TC-INV-008 | P1 | Variant recipe precedence | Variant wins; falls back when absent | §14 §4.2 |
| TC-INV-009 | P1 | Insufficient stock lists every shortfall | Per-ingredient detail | FR-POS-008 |
| TC-INV-010 | P1 | Optional item out of stock | Sale proceeds, shortfall logged | BR-DED-06 |
| TC-INV-011 | P1 | Double accept | One deduction, `422 already_deducted` | BR-DED-02 |
| TC-INV-012 | P1 | **Reversal after recipe change** | Original quantities restored | BR-STATE-04 |
| TC-INV-013 | P1 | `track_inventory = false` | No movement | BR-DED-05 |
| TC-INV-014 | P1 | Product with no recipe and no cost | `422 recipe_unavailable` | §14 §4.2 |
| TC-INV-015 | P1 | **INV-1**: ledger sum = balance | After 1000 random movements | FR-INV-006 |
| TC-INV-016 | P1 | **INV-2**: ledger immutable | `UPDATE` and `DELETE` both fail | FR-INV-006 |
| TC-INV-017 | P1 | Balance rebuild from ledger | Reproduces every balance exactly | FR-INV-015 |
| TC-INV-018 | P1 | Adjustment without reason | `422` | FR-INV-008 |
| TC-INV-019 | P1 | Adjustment above magnitude guard | `403 adjustment_exceeds_limit` | §7 |
| TC-INV-020 | P2 | Count zero variance | No ledger row, `last_counted_at` updated | BR-CNT-02 |
| TC-INV-021 | P1 | Count with variance | One `count_correction` row | FR-INV-013 |
| TC-INV-022 | P1 | Negative stock blocked | `422 negative_stock_forbidden` | FR-INV-009 |
| TC-INV-023 | P2 | Negative stock permitted by config | Proceeds, critical notification | A12 |
| TC-INV-024 | P1 | Low stock at threshold | Notification fired once | FR-INV-010 |
| TC-INV-025 | P1 | Ingredient unit immutable after movement | `422 unit_immutable` | §14 §13 |
| TC-INV-026 | P2 | Duplicate ingredient in a recipe | `422` | FR-INV-004 |

### 8.4 Transfers — `TC-INV-1xx`

| ID | Pri | Case | Expected | Req |
|---|---|---|---|---|
| TC-INV-101 | P1 | Full lifecycle with exact balances | Per [15](15-stock-transfer.md) §6 | FR-TRF-002 |
| TC-INV-102 | P1 | Same source and destination | `422 same_branch_transfer` | FR-TRF-001 |
| TC-INV-103 | P1 | Destination manager approves | `403 not_source_branch_authority` | FR-TRF-003 |
| TC-INV-104 | P1 | Dispatch deducts source only | `transfer_out` at source | FR-TRF-004 |
| TC-INV-105 | P1 | Receive adds destination only | `transfer_in` at destination | FR-TRF-004 |
| TC-INV-106 | P1 | **In transit available nowhere** | Neither branch's available includes it | FR-TRF-006 |
| TC-INV-107 | P1 | Variance recorded with reason | `has_variance`, both managers notified | FR-TRF-005 |
| TC-INV-108 | P1 | Variance without reason | `422 variance_reason_required` | — |
| TC-INV-109 | P1 | Cost travels with goods | Destination average uses source cost | FR-TRF-007 |
| TC-INV-110 | P1 | Insufficient source at dispatch | `422 insufficient_source_stock` | FR-TRF-008 |
| TC-INV-111 | P2 | Partial approval and dispatch | Quantities cascade, cannot increase | A2/A4 |
| TC-INV-112 | P2 | Cancel after dispatch | `422 cannot_cancel_dispatched` | — |
| TC-INV-113 | P1 | Zero received | Full variance, reason required | E1 |
| TC-INV-114 | P1 | Both ledgers reconcile after transfer | INV-1 holds at both branches | — |

---

## 9. API testing — `TC-API-xxx`

| ID | Pri | Case | Expected |
|---|---|---|---|
| TC-API-001 | P1 | Envelope on every success | `data` + `meta` |
| TC-API-002 | P1 | Envelope on every error | `message`, `error_code`, `meta` |
| TC-API-003 | P1 | Status code per §1.6 for each family | Correct |
| TC-API-004 | P1 | `error_code` catalogue stable | Snapshot test |
| TC-API-005 | P1 | Money shape everywhere | `amount`/`minor`/`currency` |
| TC-API-006 | P1 | **Idempotency: same key, same payload** | One record, `Idempotency-Replayed: true` |
| TC-API-007 | P1 | Idempotency: same key, different payload | `410 idempotency_key_conflict` |
| TC-API-008 | P1 | Missing idempotency key on a financial POST | `400` |
| TC-API-009 | P2 | Pagination cap | `per_page > 100` clamped or `422` |
| TC-API-010 | P2 | Cursor stability across inserts | No duplicates or skips |
| TC-API-011 | P1 | Unknown filter parameter | `422`, not ignored |
| TC-API-012 | P1 | Unknown body field | `422 unexpected_field` |
| TC-API-013 | P1 | Server-derived field supplied | `422` |
| TC-API-014 | P1 | Rate limit per bucket | `429` + `Retry-After` |
| TC-API-015 | P1 | Contract conformance | All responses validate against OpenAPI |
| TC-API-016 | P1 | `X-Request-Id` echoed and logged | Present |
| TC-API-017 | P2 | `include` whitelist | Unknown relation `422` |
| TC-API-018 | P1 | N+1 check on every list endpoint | ≤ 20 queries |

---

## 10. Concurrency testing — `TC-CONC-xxx`

**Must run against MySQL** ([23](23-testing-strategy.md) §4).

| ID | Pri | Case | Expected |
|---|---|---|---|
| TC-CONC-001 | P1 | 10 parallel orders for the last portion | Exactly 1 succeeds; 9 × `422 insufficient_stock` |
| TC-CONC-002 | P1 | 2 parallel accepts on one order | 1 × `200`, 1 × `409`/`422`; **one** deduction |
| TC-CONC-003 | P1 | 2 parallel payments for the last balance | 1 × `201`, 1 × `422 overpayment` |
| TC-CONC-004 | P1 | 2 parallel completions | 1 succeeds; one loyalty award only |
| TC-CONC-005 | P1 | 50 concurrent orders at one branch | Order numbers unique, no duplicates |
| TC-CONC-006 | P1 | 2 parallel redemptions of the same points | 1 succeeds |
| TC-CONC-007 | P1 | 2 parallel ticket bumps | 1 × `200`, 1 × `409`; one final state |
| TC-CONC-008 | P1 | 2 parallel transfer receipts | 1 succeeds |
| TC-CONC-009 | P1 | 2 parallel expense approvals | 1 succeeds |
| TC-CONC-010 | P1 | Cross-order deadlock: two orders, overlapping ingredients, opposite input order | No deadlock (lock ordering) or clean retry |
| TC-CONC-011 | P1 | Duplicate submission under load | Idempotency holds |
| TC-CONC-012 | P1 | Concurrent count and sale | Serialised; balance correct afterwards |
| TC-CONC-013 | P1 | INV-1 holds after 500 concurrent operations | Zero drift |

---

## 11. Security testing — `TC-SEC-xxx`

| ID | Pri | Case | Expected |
|---|---|---|---|
| TC-SEC-001 | P1 | SQL injection in every string field and query parameter | Stored/returned literally; never executed |
| TC-SEC-002 | P1 | XSS payload in name, note, description | Escaped on output in all renderings |
| TC-SEC-003 | P1 | **IDOR sweep** across every branch-owned resource | `404` |
| TC-SEC-004 | P1 | Mass assignment of `status`, `paid_total`, `branch_id`, `role_id` | Ignored or `422` |
| TC-SEC-005 | P1 | Path traversal in an upload filename | Rejected; stored under a generated key |
| TC-SEC-006 | P1 | SVG upload | Rejected |
| TC-SEC-007 | P1 | Polyglot file (valid image + PHP) | Rejected by content sniffing |
| TC-SEC-008 | P1 | **PAN in `reference`** | `422`; and redacted in logs |
| TC-SEC-009 | P1 | PAN in `note` and `description` | Same |
| TC-SEC-010 | P1 | Security headers present | All of [22](22-security.md) §6.2 |
| TC-SEC-011 | P1 | CORS from a disallowed origin | Blocked |
| TC-SEC-012 | P1 | Debug output with `APP_DEBUG=false` | No stack trace, SQL, path or class name |
| TC-SEC-013 | P1 | Boot with `APP_DEBUG=true` in production | Refuses to start |
| TC-SEC-014 | P1 | Password/PIN/token in logs | Redacted |
| TC-SEC-015 | P1 | Audit rows append-only | `UPDATE`/`DELETE` fail |
| TC-SEC-016 | P1 | Audit written in-transaction | Rollback leaves none |
| TC-SEC-017 | P1 | Every state change audited | One row each, correct actor |
| TC-SEC-018 | P1 | Denied attempts audited | Self-approval, escalation, transition denial |
| TC-SEC-019 | P1 | Open redirect via `action_url` | Rejected |
| TC-SEC-020 | P1 | Token in a query parameter | Not accepted |
| TC-SEC-021 | P2 | `Cache-Control: no-store` on authenticated responses | Present |
| TC-SEC-022 | P1 | Cost/margin field scan across all responses per role | Absent for unprivileged |

---

## 12. Regression and negative testing

### 12.1 Regression — `TC-REG-xxx`

The regression suite is the union of:

1. **Every P1 case** in this document.
2. **One test per fixed defect**, named for the defect (`TC-REG-<defect-id>`).
3. The golden calculation fixtures.
4. The integrity invariants (INV-1, INV-2, C5–C9).

Run on every PR and nightly. **A defect fix without a regression test is not merged**
([23](23-testing-strategy.md) P4).

### 12.2 Negative testing — `TC-NEG-xxx`

Systematic hostile input against every write endpoint:

| Class | Examples |
|---|---|
| Type confusion | String for number, array for scalar, object for string, `null` for required |
| Boundary | min−1, min, max, max+1 for every numeric rule |
| Format | Malformed dates, invalid enums, bad UUIDs, invalid emails |
| Size | Empty string, 1 char over max, 10 MB body, 501-element array |
| Encoding | Invalid UTF-8, null bytes, RTL override characters, emoji in every text field |
| Numeric | Negative where positive required, zero where non-zero required, `NaN`, `Infinity`, `1e308`, `-0` |
| Money | JSON float, scientific notation, 3+ decimals, currency symbols in the string |
| Missing | Each required field omitted in turn |
| Extra | Unknown fields, duplicate JSON keys |
| Sequence | Transitions from every wrong state; double submission; out-of-order workflow calls |
| Referential | Non-existent IDs, soft-deleted IDs, IDs from another branch |

**Acceptance criterion:** every rule in [20-validation-rules.md](20-validation-rules.md) has at least
one negative test.

---

## 13. Manual test checklist

Per release, on real hardware.

| # | Check |
|---|---|
| M1 | Receipt prints correctly on the thermal printer; alignment, width, cut |
| M2 | Cash drawer opens on cash payment |
| M3 | Full sale completable by touch only, gloved, on the POS terminal |
| M4 | KDS legible at 1.5 m in kitchen lighting |
| M5 | KDS late-ticket state distinguishable in greyscale |
| M6 | Touch targets ≥ 44 px on both POS and KDS |
| M7 | Card terminal batch reconciles to `payments` where `method = card` |
| M8 | Back office usable at 768 px width |
| M9 | Screen reader navigation of the back office |
| M10 | Timed 3-item cash sale ≤ 30 s by a trained cashier |
| M11 | Exploratory session: 2 h, findings logged |
| M12 | Backup restore drill completed and recorded |

---

## 14. Defect management

| Severity | Definition | Target fix |
|---|---|---|
| **S1 Critical** | Money or stock incorrect; data loss; security breach; system down | Immediately; blocks release |
| **S2 Major** | Core function broken, no workaround | Before release |
| **S3 Minor** | Function impaired, workaround exists | Next release |
| **S4 Cosmetic** | Visual or wording | Backlog |

A defect report must contain: environment, exact steps, expected vs actual, `request_id`, screenshots
or payloads, severity, and the affected requirement ID.

---

## 15. Related documents

[03-requirements.md](03-requirements.md) ·
[09-business-rules.md](09-business-rules.md) ·
[20-validation-rules.md](20-validation-rules.md) ·
[21-error-handling.md](21-error-handling.md) ·
[22-security.md](22-security.md) ·
[23-testing-strategy.md](23-testing-strategy.md) ·
[25-performance-testing.md](25-performance-testing.md)
