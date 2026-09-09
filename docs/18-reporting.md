# 18 — Reporting

> **Document purpose.** Define every report: its exact definition, the data it reads, how the business
> day is determined, how figures reconcile to the underlying ledgers, and who may see what. A report
> that cannot be reconciled to a ledger is a rumour, so each definition states its source of truth.

**Prerequisites:** [09-business-rules.md](09-business-rules.md) §11 (profit formulas),
[05-database-design.md](05-database-design.md).

**Status:** 🟡 MVP — Planned.

---

## 1. Principles

| # | Principle |
|---|---|
| R1 | Every figure traces to rows in a ledger table. A report never invents a number. |
| R2 | Reports are **read-only**. No report generates, corrects or backfills data. |
| R3 | Reports respect branch scope. A Manager's totals never include another branch. |
| R4 | Reports respect field-level permissions. Without `reports.view_profit`, cost and margin columns are **absent**, not zero. |
| R5 | Every report states its period basis, timezone and the policy assumptions that affect it. |
| R6 | Only `completed` orders count as sales. |
| R7 | Tax is never revenue. |
| R8 | A report is reproducible: the same parameters over unchanged data always return the same figures. |

---

## 2. Report catalogue

| # | Report | Permission | Answers |
|---|---|---|---|
| 1 | Daily sales summary | `reports.view_sales` | What did we sell, and how were we paid? |
| 2 | Product performance | `reports.view_products` | What sells, and what makes money? |
| 3 | Profit | `reports.view_profit` | Did we make money? |
| 4 | Inventory valuation | `reports.view_inventory` | What is our stock worth? |
| 5 | Stock movement | `reports.view_inventory` | Where did the stock go? |
| 6 | Expense summary | `reports.view_expenses` | What did we spend? |
| 7 | Staff performance | `reports.view_staff` | Who sold what, and who is discounting and voiding? |
| 8 | Kitchen performance | `reports.view_staff` | Are we hitting preparation targets? |
| 9 | Cashier shift | `payments.manage_shift` | Does the drawer balance? |
| 10 | Customer / loyalty | `customers.view` + `loyalty.view` | Who are our regulars? |

---

## 3. The business day

**The most common source of "the numbers don't match".**

| Concept | Definition |
|---|---|
| UTC timestamp | What is stored in every `_at` column |
| Branch timezone | `branches.timezone`, IANA name |
| Business day start | `branches.business_day_start`, a branch-local time, default `00:00` |
| **Business date** | The trading day an order belongs to. Computed at order creation and stored in `orders.business_date`. |

```text
business_date = (order local time − business_day_start), date part
              where local time = UTC timestamp converted to branches.timezone
```

**Worked example** — branch in `Asia/Bangkok` (UTC+7) with `business_day_start = 04:00`, because it
closes at 02:00:

| Order UTC | Branch local | Business date | Why |
|---|---|---|---|
| `2026-09-05T10:00Z` | 17:00 on the 5th | `2026-09-05` | After 04:00 |
| `2026-09-05T18:30Z` | 01:30 on the 6th | **`2026-09-05`** | Before 04:00 ⇒ still the 5th's trading night |
| `2026-09-05T22:00Z` | 05:00 on the 6th | `2026-09-06` | After 04:00 |

| # | Rule |
|---|---|
| BR-RPT-01 | Reports filter on `orders.business_date`, never on `created_at`. |
| BR-RPT-02 | `business_date` is fixed at **creation** and never recalculated. An order created at 01:30 and completed at 03:00 belongs to the earlier trading day. |
| BR-RPT-03 | Expenses use `expense_date`, which is entered by the user, not derived. |
| BR-RPT-04 | Stock movements use `occurred_at`, converted to branch-local for grouping. |
| BR-RPT-05 | Cross-branch reports group by each branch's own business day. Two branches in different timezones on "5 September" are two different UTC windows, and that is correct. |

**Why `business_date` is a stored column rather than computed.** Computing it in SQL would require a
timezone conversion and a time subtraction on every row of an 800 000-row table, on every report, and
would prevent the index `idx_orders_branch_business_date` from being used.

---

## 4. Daily sales summary

**Answers:** What did we sell today, and how were we paid?
**Source of truth:** `orders`, `payments`, `refunds`.

### 4.1 Definitions

| Figure | Definition |
|---|---|
| Order count | Count of `completed` orders in the period |
| Item count | `Σ order_items.quantity` for those orders, excluding voided lines |
| Gross sales | `Σ orders.subtotal` |
| Discount total | `Σ orders.discount_amount` |
| Loyalty discount | `Σ orders.loyalty_discount_amount` |
| Net sales | `Gross sales − Discount total − Loyalty discount` |
| Tax total | `Σ orders.tax_amount` |
| Service charge | `Σ orders.service_charge_amount` |
| Rounding | `Σ orders.rounding_adjustment` |
| Grand total | `Σ orders.grand_total` |
| Refund total | `Σ refunds.amount` where `refunded_at` is in the period |
| Net receipts | `Grand total − Refund total` |
| Average order value | `Grand total ÷ Order count`, `0` when count is `0` |

### 4.2 Reconciliation

**Two independent checks that must hold, or the report is wrong:**

```text
Check 1 — orders vs payments
    Σ orders.grand_total (completed, period)
      ==  Σ payments.amount (captured, for those orders)

Check 2 — components vs total
    Σ (subtotal − discount − loyalty_discount + tax + service_charge + rounding)
      ==  Σ grand_total
```

The report runs both and displays a reconciliation banner if either fails. A silently wrong sales
report is worse than an obviously broken one.

### 4.3 Breakdowns

| Dimension | Grouping |
|---|---|
| Payment method | `payments.method` — count and amount |
| Order type | `dine_in`, `takeaway`, `delivery` |
| Period | day, week, month, or hour-of-day |
| Cashier | `orders.user_id` (requires `reports.view_staff`) |
| Category | via `order_items → products → categories` |

**Note on payment-method breakdown.** It sums `payments`, not `orders`, so a split-tender order appears
partly under each method. The method breakdown therefore sums to `Σ payments`, which equals
`Σ grand_total` only when every order is fully settled — always true for `completed` orders (FR-ORD-006).

---

## 5. Inventory reports

### 5.1 Inventory valuation

**Answers:** What is our stock worth right now?
**Source:** `inventories`.

```text
line_value  = inventories.quantity_on_hand × inventories.average_cost
total_value = Σ line_value per branch
```

| # | Rule |
|---|---|
| BR-RPT-06 | Valuation is a **point-in-time** figure using current balances and current average cost. |
| BR-RPT-07 | Historical valuation uses `stock_transactions.balance_after` and `average_cost_after` at the last row before the target instant — the reason those two columns exist. |
| BR-RPT-08 | In-transit stock is valued at the **source's** cost and reported separately, belonging to neither branch's on-hand value. |
| BR-RPT-09 | Requires `inventory.view_valuation`. |

### 5.2 Stock movement

**Answers:** Where did the stock go?
**Source:** `stock_transactions`.

For each ingredient in the period:

| Column | Definition |
|---|---|
| Opening balance | `balance_after` of the last row before `date_from`, else `0` |
| Stock in | `Σ quantity_change` for `stock_in`, `transfer_in` |
| Sales usage | `Σ ABS(quantity_change)` for `sale_deduction` less `sale_reversal` |
| Wastage | `Σ ABS(quantity_change)` for `wastage` |
| Other out | `Σ ABS(quantity_change)` for `stock_out`, `transfer_out` |
| Adjustments | `Σ quantity_change` for `adjustment`, `count_correction` (signed) |
| Closing balance | `balance_after` of the last row at or before `date_to` |

**The identity that must always hold:**

```text
Opening + Stock in − Sales usage − Wastage − Other out + Adjustments  ==  Closing
```

The report computes both sides and flags any discrepancy. A mismatch means the ledger has been
tampered with or a movement bypassed the service layer — either is a critical incident.

### 5.3 Usage variance 🔵

Theoretical usage (from recipes × quantities sold) against actual usage (from the ledger). The
difference is over-portioning, waste or theft. Deferred to Post-MVP because it needs a stable recipe
history to be meaningful.

---

## 6. Profit report

**Answers:** Did we make money?
**Source:** `orders`, `order_items`, `refunds`, `expenses`.
**Permission:** `reports.view_profit` — the most restricted report.

```text
Revenue            = Σ orders.grand_total          (completed, period)
                     − Σ orders.tax_amount
                     − Σ refunds.amount            (refunded in the period)

COGS               = Σ order_items.line_cogs       (those orders)
                     + Σ expenses.total_amount     (approved/paid, is_cogs_related = true)

Gross Profit       = Revenue − COGS
Gross Margin %     = Gross Profit ÷ Revenue × 100

Operating Expenses = Σ expenses.total_amount       (approved/paid, is_cogs_related = false)

Net Profit         = Gross Profit − Operating Expenses
Net Margin %       = Net Profit ÷ Revenue × 100
Food Cost %        = COGS ÷ Revenue × 100
```

### 6.1 Rules restated

| # | Rule |
|---|---|
| BR-PROFIT-01 | **Tax is excluded from revenue.** It is collected on behalf of the authority. |
| BR-PROFIT-02 | Only `completed` orders. |
| BR-PROFIT-03 | Refunds reduce revenue in the period of the **refund**. |
| BR-PROFIT-04 | Only `approved` and `paid` expenses. |
| BR-PROFIT-05 | Expenses attribute by `expense_date`. |
| BR-PROFIT-07 | Division by zero yields `0`. |

### 6.2 The double-counting warning

The report header **must** state which costing policy is active
([17](17-expense-management.md) §2.1):

> *Costing policy: Inventory-led. COGS is derived from order-item cost snapshots. Expense categories
> flagged as COGS-related are also included — verify that purchases are not recorded in both.*

Without this, a reader cannot tell whether food cost has been counted once or twice, and the margin is
uninterpretable.

### 6.3 Worked example

One branch, one day:

| Line | Amount |
|---|---|
| Σ grand_total (completed) | `18 424.87` |
| − Σ tax_amount | `−1 205.37` |
| − Σ refunds | `−145.00` |
| **Revenue** | **`17 074.50`** |
| Σ line_cogs | `5 460.00` |
| + COGS-related expenses | `1 337.50` |
| **COGS** | **`6 797.50`** |
| **Gross profit** | **`10 277.00`** — margin `60.19 %` |
| Operating expenses | `4 200.00` |
| **Net profit** | **`6 077.00`** — margin `35.59 %` |
| Food cost % | `39.81 %` |

---

## 7. Operational reports

### 7.1 Product performance

**Source:** `order_items`.

| Column | Definition | Permission |
|---|---|---|
| Quantity sold | `Σ quantity` | `reports.view_products` |
| Revenue | `Σ line_total − Σ line_tax_amount` | `reports.view_products` |
| COGS | `Σ line_cogs` | **`reports.view_profit`** |
| Margin | `Revenue − COGS` | **`reports.view_profit`** |
| Margin % | `Margin ÷ Revenue × 100` | **`reports.view_profit`** |
| Rank | By revenue, then quantity, then `product_id` — deterministic tie-break | — |

Without `reports.view_profit`, the COGS, Margin and Margin % columns are **omitted from the response
entirely** (R4). A `null` would still reveal that margin data exists.

### 7.2 Staff performance

**Source:** `orders`, `payments`, `audit_logs`.

| Metric | Why it is tracked |
|---|---|
| Orders created, items sold, gross sales | Productivity |
| Average order value | Upselling |
| Discount count and total, average discount % | **Discount abuse is the most common POS fraud** |
| Void count and total | Same |
| Refund count and total | Same |
| Receipt reprint count | Classic skimming indicator |
| Drawer variance, cumulative and average | Cash handling |
| Discount overrides requested / authorised | Threshold pressure |

**This report exists for exception monitoring, not for ranking staff.** One large variance is an
accident; a consistent pattern across weeks is a signal. The report highlights outliers relative to
branch averages rather than presenting a league table, because a raw ranking punishes whoever works the
busiest shift.

### 7.3 Kitchen performance

**Source:** `kitchen_tickets`, `order_status_histories`.

| Metric | Definition |
|---|---|
| Average queue wait | `started_at − queued_at` |
| Average preparation time | `ready_at − started_at` |
| Average total ticket time | `ready_at − queued_at` |
| SLA breach count and rate | Tickets where total time > `sla_minutes` |
| Breaches by station, hour, product | Where the bottleneck is |
| Recall count | Quality signal |
| P50 / P90 / P99 ticket time | Averages hide the bad tail; percentiles do not |

### 7.4 Cashier shift report

**Source:** `cash_drawer_sessions`, `payments`, `orders`.

| Section | Contents |
|---|---|
| Session | Cashier, branch, open/close times, duration |
| Cash | Opening float, cash payments, cash refunds, expected, counted, **variance** |
| Non-cash | Card, QR, transfer totals with counts |
| Orders | Count, gross, discounts, voids |
| Exceptions | Voids, refunds, discount overrides, reprints, with reasons |

Reconciles exactly to [12-payment-workflow.md](12-payment-workflow.md) §8.2.

### 7.5 Customer and loyalty report

| Metric | Definition |
|---|---|
| New customers | Created in the period |
| Active customers | With ≥ 1 completed order in the period |
| Repeat rate | Customers with ≥ 2 orders ÷ active customers |
| Average customer value | `Σ grand_total` for customer orders ÷ active customers |
| Points issued / redeemed / expired | From `loyalty_transactions` by type |
| **Outstanding liability** | `Σ customers.loyalty_points_balance × redeem_value_per_point` |
| Tier distribution | Customers per tier |

**Outstanding loyalty liability is a real financial figure** — money the business has promised. It is
reported so it is not forgotten.

---

## 8. Performance and caching

Reports aggregate over the largest tables in the system.

| Technique | Detail |
|---|---|
| Indexes | `idx_orders_branch_business_date`, `idx_st_branch_ingredient_occurred`, `idx_payments_branch_paid_at` |
| Range cap | `reports.max_range_days`, default 366; beyond ⇒ `422 date_range_too_large` |
| Caching | Results cached in Redis, keyed by report + parameters + branch scope. Completed past days: 24 h TTL. Current day: 5 min TTL. |
| Cache invalidation | Any write to a completed order, refund or expense in a cached period purges that period's keys |
| Rate limit | 10 report requests per minute per user |
| Export | CSV export is **asynchronous**: `POST` returns `202` and a job ID; the client polls for a signed URL |
| Read replica 🔵 | Post-MVP, reports move to a replica so a heavy month-end query cannot slow the POS |
| Pre-aggregation 🔵 | A nightly `daily_sales_snapshots` table for multi-month trends |

**Why exports are asynchronous.** A twelve-month CSV can take 30 seconds and produce 50 MB. Doing that
in a web request ties up a PHP-FPM worker that the POS needs.

---

## 9. Permissions and data visibility

| Report | Permission | Branch reach |
|---|---|---|
| Daily sales | `reports.view_sales` | Scope; all branches with `reports.view_all_branches` |
| Cashier's own shift | `reports.view_sales` (Cashier) | Own shift, current business day only |
| Product performance | `reports.view_products` | Scope |
| Product COGS / margin columns | `reports.view_profit` | Scope |
| Profit | `reports.view_profit` | Scope |
| Inventory valuation | `reports.view_inventory` + `inventory.view_valuation` | Scope |
| Stock movement | `reports.view_inventory` | Scope |
| Expenses | `reports.view_expenses` | Scope |
| Staff | `reports.view_staff` | Scope |
| Kitchen | `reports.view_staff` | Scope |
| Customer / loyalty | `customers.view` + `loyalty.view` | Global (customers are not branch-scoped) |
| Export any | `reports.export` plus the report's own permission | |

---

## 10. Validation

| Parameter | Rule |
|---|---|
| `branch_id` | optional; must be in scope; `all` requires `reports.view_all_branches` |
| `date_from` | required, `Y-m-d`, ≤ `date_to` |
| `date_to` | required, `Y-m-d`, not in the future beyond today's business date |
| Range | ≤ `reports.max_range_days` |
| `group_by` | in `hour,day,week,month` |
| `order_type`, `payment_method`, `category_id`, `user_id`, `ingredient_id` | optional, whitelisted, must exist |
| `format` | in `json,csv` |

---

## 11. Edge cases

| # | Case | Expected behaviour |
|---|---|---|
| E1 | No data in the period | All figures `0`, empty breakdowns. Not an error, and not an empty response body. |
| E2 | Revenue `0`, expenses positive | Net profit negative; margin `0` (division guard), flagged rather than shown as `−∞`. |
| E3 | Order completed after the period it belongs to | Appears in its `business_date` period (BR-RPT-02). |
| E4 | Refund in a later period | Reduces revenue in the refund's period, not the sale's. Two periods, one order — correct and must be explained in the report footnote. |
| E5 | Order spanning midnight | `business_date` fixed at creation. |
| E6 | Branch timezone changed | Historical `business_date` values are unchanged (they are stored). New orders use the new timezone. The change is audited. |
| E7 | Two branches in different timezones, "today" | Each uses its own business day; the consolidated total covers two different UTC windows. Stated in the report header. |
| E8 | Product deleted after being sold | Appears in product performance using `name_snapshot`, marked as deleted. |
| E9 | Ingredient with no movements in the period | Opening = closing; shown with zeroes rather than omitted, so the reader can see it was checked. |
| E10 | Expense approved after the period closed | Appears in the `expense_date` period; that period's profit changes retroactively. A closed-period lock is 🔵. |
| E11 | Report requested for a future date | `422`. |
| E12 | Cached report served after a late refund | Cache invalidation purges the affected period; a stale figure is a correctness bug, not a performance trade-off. |
| E13 | Manager with two branches views a consolidated report | Permitted within scope; per-branch breakdown always available so the total can be decomposed. |
| E14 | Zero-total completed order | Counted in order count, contributes nothing to revenue. Average order value is diluted — correct, and the count is shown so the reader can see why. |

---

## 12. Security considerations

| # | Consideration | Mitigation |
|---|---|---|
| S1 | Cross-branch data leakage | Branch scope applied in the query, not filtered after; `reports.view_all_branches` gates consolidation. |
| S2 | Cost and margin leakage | Columns omitted, not nulled, without `reports.view_profit`. Verified by an automated key scan per role. |
| S3 | Staff data misuse | `reports.view_staff` is Manager and above; access is audited. |
| S4 | Customer PII in exports | Exports containing personal data require `customers.export` and are audited. |
| S5 | Report-driven denial of service | Range cap, rate limit, caching, asynchronous export. |
| S6 | SQL injection via filters | All filter values are bound parameters against a whitelist. `sort` and `group_by` map to fixed column names, never interpolated. |
| S7 | Export URL leakage | Signed, short-lived, single-use URLs. |
| S8 | Inference of hidden data | A Cashier cannot derive margin from revenue alone; cost is never present in any response they can reach. |

---

## 13. Testing considerations

| Area | Test |
|---|---|
| Reconciliation | For a seeded day, assert both §4.2 checks hold exactly. |
| Stock identity | Assert `opening + in − out + adj = closing` for every ingredient over randomised movements. |
| Tax exclusion | Assert revenue ≠ `Σ grand_total` when tax is non-zero, and equals the documented formula. |
| Profit worked example | §6.3 as an integration test built from real orders and expenses. |
| Business day | Orders at 23:58 and 00:02 land in the correct periods for a branch with a 04:00 day start. |
| Timezone | Two branches, different timezones, same nominal date ⇒ correct per-branch windows. |
| Refund period | Sale in period 1, refund in period 2 ⇒ revenue reduced in period 2 only. |
| Permission omission | Assert cost columns are **absent** for a role without `reports.view_profit`. |
| Branch scope | A Manager's report never includes another branch's orders. |
| Empty period | Zeroes, not errors, not nulls. |
| Division guard | Zero revenue ⇒ margin `0`, no exception. |
| Cache correctness | Report, then refund, then report again ⇒ updated figures. |
| Range cap | `max_range_days + 1` ⇒ `422`. |
| Performance | One branch, one month, < 3 s ([25](25-performance-testing.md) §5.5). |
| Determinism | Same parameters twice ⇒ byte-identical figures, including tie-break ordering. |
| Export | CSV rows match the JSON report exactly; UTF-8 BOM present. |

Full plan: [24-qa-test-plan.md](24-qa-test-plan.md) §7.5.

---

## 14. Related documents

[07-api-documentation.md](07-api-documentation.md) §11 ·
[09-business-rules.md](09-business-rules.md) §11 ·
[12-payment-workflow.md](12-payment-workflow.md) §8 ·
[14-inventory-workflow.md](14-inventory-workflow.md) ·
[17-expense-management.md](17-expense-management.md) §2.1 ·
[25-performance-testing.md](25-performance-testing.md)
