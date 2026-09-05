# 03 — Requirements

> **Document purpose.** Provide the numbered, testable requirement baseline for RestaurantOS. Every
> requirement has a stable ID, a priority, a scope marker and an acceptance criterion. QA traces test
> cases to these IDs in [24-qa-test-plan.md](24-qa-test-plan.md); developers trace pull requests to
> them via [28-git-workflow.md](28-git-workflow.md).

**Prerequisite:** [01-project-overview.md](01-project-overview.md).

---

## 1. How to read this document

### 1.1 Identifier scheme

```text
FR-<MODULE>-<NNN>    Functional requirement
NFR-<CATEGORY>-<NNN> Non-functional requirement
CON-<NNN>            Constraint
```

IDs are **permanent**. A withdrawn requirement is marked `WITHDRAWN`; its number is never reused.

### 1.2 Priority — MoSCoW

| Priority | Meaning |
|---|---|
| **M** | Must have. MVP cannot ship without it. |
| **S** | Should have. Painful to omit; may slip one release. |
| **C** | Could have. Included if capacity allows. |
| **W** | Won't have this time. Recorded so it is not re-litigated. |

### 1.3 Scope

`🟡 MVP` · `🔵 Post-MVP` · `⚠️ Needs decision`

### 1.4 Requirement wording

"**Must**" is binding. "**Should**" indicates a strong default that may be overridden with a recorded
decision. Acceptance criteria are written so a QA engineer can turn each one into a test without asking
a follow-up question.

---

## 2. Functional requirements

### 2.1 Authentication — `FR-AUTH`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-AUTH-001 | M | 🟡 | A user must authenticate with email and password to obtain an API token. | `POST /api/v1/auth/login` with valid credentials returns `201` and a token; with invalid credentials returns `401` and no token. |
| FR-AUTH-002 | M | 🟡 | Failed login attempts must be rate limited per email **and** per IP. | After 5 failures within 15 min, the 6th returns `429` with `Retry-After`, even with correct credentials. |
| FR-AUTH-003 | M | 🟡 | Tokens must be revocable individually and in bulk. | `POST /auth/logout` invalidates the current token; `POST /auth/logout-all` invalidates every token for the user. A revoked token returns `401`. |
| FR-AUTH-004 | M | 🟡 | Tokens must expire after a configurable idle period. | With `SANCTUM_EXPIRATION` set, a token unused past the window returns `401` with `error_code = token_expired`. |
| FR-AUTH-005 | M | 🟡 | Deactivated users must be denied access immediately, even with a valid token. | Setting `is_active = false` causes the next request to return `403` with `error_code = account_inactive`. |
| FR-AUTH-006 | M | 🟡 | Passwords must be stored using a one-way adaptive hash. | No plaintext or reversible password exists in the database or logs; hash is bcrypt/argon2 per config. |
| FR-AUTH-007 | S | 🟡 | Users must be able to change their own password with the current password supplied. | Wrong current password returns `422`; success revokes all **other** tokens. |
| FR-AUTH-008 | S | 🟡 | Password reset via emailed, single-use, time-limited token. | Token works once, expires after 60 min, and never reveals whether an email is registered. |
| FR-AUTH-009 | M | 🟡 | The authenticated user's profile, roles, permissions and branch scope must be retrievable in one call. | `GET /auth/me` returns all four; the SPA needs no second call to render navigation. |
| FR-AUTH-010 | C | 🔵 | Two-factor authentication for Admin and Super Admin. | TOTP enrolment, verification, and recovery codes. |
| FR-AUTH-011 | S | 🟡 | A short numeric PIN login for shared POS terminals, scoped to POS abilities only. | PIN login yields a token whose abilities exclude admin and reporting endpoints. |

### 2.2 Users, roles, permissions — `FR-RBAC`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-RBAC-001 | M | 🟡 | The system must support exactly the six roles: Super Admin, Admin, Manager, Cashier, Kitchen, Staff. | Seeder creates all six; the permission matrix in [04](04-user-roles-permissions.md) §4 is reproduced exactly. |
| FR-RBAC-002 | M | 🟡 | Permissions must be granular, named `module.action`, and grouped by module. | `GET /admin/permissions` returns the full catalogue grouped; every protected endpoint maps to one. |
| FR-RBAC-003 | M | 🟡 | A user may hold multiple roles; effective permissions are the union. | A user with Cashier + Kitchen can both create orders and mark tickets ready. |
| FR-RBAC-004 | M | 🟡 | Every write endpoint must enforce a permission server-side. | For each endpoint, a token lacking the permission returns `403`. No endpoint relies on UI hiding. |
| FR-RBAC-005 | M | 🟡 | Users must be scoped to branches; non-privileged users must not read or write another branch's data. | A Cashier at Branch A requesting a Branch B order receives `404` (not `403` — see [22](22-security.md) §5). |
| FR-RBAC-006 | M | 🟡 | Super Admin must operate across all branches. | Super Admin lists orders from every branch in one call. |
| FR-RBAC-007 | S | 🟡 | Roles must be assignable per branch for multi-branch managers. | A user may be Manager at Branch A and Staff at Branch B, with permissions differing by branch context. |
| FR-RBAC-008 | M | 🟡 | The last active Super Admin must not be deletable, deactivatable, or demotable. | Attempting any of the three returns `422` with `error_code = last_super_admin`. |
| FR-RBAC-009 | M | 🟡 | System roles must not be renamed or deleted; their permission sets may be edited by Super Admin only. | `DELETE /admin/roles/{id}` on a system role returns `403`. |
| FR-RBAC-010 | S | 🟡 | Every role and permission change must be audited. | An audit row records actor, target user, before/after role sets. |
| FR-RBAC-011 | M | 🟡 | A user must not escalate their own privileges. | A Manager assigning themselves Admin receives `403` with `error_code = privilege_escalation`. |

### 2.3 Branches — `FR-BRN`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-BRN-001 | M | 🟡 | CRUD for branches with a unique branch code. | Duplicate code returns `422`. |
| FR-BRN-002 | M | 🟡 | Each branch must carry its own timezone and currency code. | Reports and receipts render in branch-local time; see [18](18-reporting.md) §3. |
| FR-BRN-003 | M | 🟡 | A branch must be deactivatable without deleting history. | Deactivated branches reject new orders (`422`) but remain reportable. |
| FR-BRN-004 | M | 🟡 | A branch with orders or stock must not be hard-deleted. | Delete performs a soft delete; history remains queryable. |
| FR-BRN-005 | S | 🟡 | Branch-level settings must override global settings. | Tax rate set at branch level takes precedence; resolution order in [27](27-environment-configuration.md) §5. |

### 2.4 Catalogue: categories, products, variants — `FR-CAT`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-CAT-001 | M | 🟡 | CRUD for categories with ordering and activation. | Inactive categories are hidden from POS but visible in admin. |
| FR-CAT-002 | S | 🟡 | Categories may nest one level (parent → child). | A two-level tree is returned by `GET /catalog/categories?tree=1`; a third level is rejected `422`. |
| FR-CAT-003 | M | 🟡 | CRUD for products with SKU, price, category, image and availability. | Duplicate SKU returns `422`. |
| FR-CAT-004 | M | 🟡 | Products may have variants with price differences. | A product with variants requires a variant selection at POS; without variants, none is requested. |
| FR-CAT-005 | M | 🟡 | Product price and availability may be overridden per branch. | Branch A sells at 4.50, Branch B at 5.00, from one product record. |
| FR-CAT-006 | M | 🟡 | Products already sold must not be hard-deleted. | Delete is a soft delete; historic order items keep their name and price snapshots. |
| FR-CAT-007 | M | 🟡 | Changing a product price must not alter any existing order total. | After repricing, previously created orders return unchanged totals. |
| FR-CAT-008 | S | 🟡 | Products must be markable "out of stock" independently of ingredient levels. | `is_available = false` removes the product from POS immediately. |
| FR-CAT-009 | C | 🔵 | Modifier groups (extra shot, no ice) with price deltas. | Deferred; variants cover MVP needs. |
| FR-CAT-010 | S | 🟡 | Each product must reference a tax rate, or inherit the branch default. | Tax on a line uses the product's rate when present, otherwise the branch default. |

### 2.5 Customers and loyalty — `FR-CUS`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-CUS-001 | M | 🟡 | CRUD for customers with a unique phone number where supplied. | Duplicate phone returns `422`. |
| FR-CUS-002 | M | 🟡 | A customer may be attached to an order at or after creation, before completion. | Attaching after completion returns `422`. |
| FR-CUS-003 | M | 🟡 | Customers must earn loyalty points on completed orders at a configured rate. | Points appear only when the order reaches Completed, never earlier. |
| FR-CUS-004 | M | 🟡 | Points must be redeemable against an order within configured limits. | Redemption beyond `max_redeem_percent_of_subtotal` returns `422`. |
| FR-CUS-005 | M | 🟡 | Every points movement must be recorded in an append-only ledger with the resulting balance. | Ledger sum always equals the customer's balance. |
| FR-CUS-006 | M | 🟡 | Cancelling or refunding a completed order must reverse the points it earned. | A reversal entry appears; the balance returns to its prior value. |
| FR-CUS-007 | S | 🟡 | Loyalty tiers must adjust the earn rate. | A Gold customer with a 1.5× multiplier earns 50 % more on the same spend. |
| FR-CUS-008 | S | 🔵 | Points must expire after a configured period. | A scheduled task writes `expire` entries; expired points are unusable. |
| FR-CUS-009 | M | 🟡 | Customer personal data must be exportable and erasable on request. | Erasure anonymises the customer while preserving order financial totals. |

### 2.6 POS — `FR-POS`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-POS-001 | M | 🟡 | The cashier must select a branch before selling; the default is their home branch. | A user with one branch never sees the selector; a multi-branch user must choose. |
| FR-POS-002 | M | 🟡 | Products must be browsable by category and searchable by name or SKU. | Search returns results in < 300 ms for a 2 000-product catalogue. |
| FR-POS-003 | M | 🟡 | The cart must support add, change quantity, remove, per-line note and clear. | Setting quantity to 0 removes the line. |
| FR-POS-004 | M | 🟡 | Line and order-level discounts must be supported as percentage or fixed amount. | Both types produce the amounts in [09](09-business-rules.md) §5. |
| FR-POS-005 | M | 🟡 | Discounts above a configured threshold must require elevated permission. | A Cashier applying 25 % when the cap is 10 % receives `403`; a Manager succeeds. |
| FR-POS-006 | M | 🟡 | Totals must be calculated server-side and displayed before the order is confirmed. | `POST /pos/cart/calculate` returns the same figures the created order will have. |
| FR-POS-007 | M | 🟡 | Order type (dine-in, takeaway, delivery) must be selectable; dine-in may capture a table number. | Table number is rejected `422` for takeaway. |
| FR-POS-008 | M | 🟡 | The POS must prevent selling a product whose recipe cannot be fulfilled from branch stock. | Insufficient stock returns `422` with the shortfall listed per ingredient. |
| FR-POS-009 | M | 🟡 | A double-submitted order must not create two orders. | Two requests with the same `Idempotency-Key` yield one order and identical responses. |
| FR-POS-010 | S | 🟡 | The cart must survive a page reload on the same terminal. | Cart state is restored from local storage; server totals are recalculated on restore. |
| FR-POS-011 | M | 🟡 | A receipt must be producible for any settled order. | Receipt shows lines, discount, tax, total, tender, change, branch, cashier and order number. |
| FR-POS-012 | C | 🔵 | Park and resume an open cart (multiple concurrent tabs). | Deferred. |
| FR-POS-013 | W | 🔵 | Offline selling. | Explicitly excluded — [01](01-project-overview.md) A6. |

### 2.7 Orders — `FR-ORD`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-ORD-001 | M | 🟡 | Orders must follow the lifecycle Pending → Accepted → Preparing → Ready → Completed. | Any other transition returns `422` with `error_code = invalid_state_transition`. |
| FR-ORD-002 | M | 🟡 | Every order must carry a unique, human-readable number scoped to branch and business day. | Numbers are gapless per branch per day under concurrent creation. |
| FR-ORD-003 | M | 🟡 | Order lines must snapshot product name, SKU, unit price, tax rate and unit cost. | Editing the product afterwards leaves the order unchanged. |
| FR-ORD-004 | M | 🟡 | Orders must be cancellable before Completed, with a mandatory reason. | Cancelling without a reason returns `422`; cancelling a Completed order returns `422`. |
| FR-ORD-005 | M | 🟡 | Cancellation after inventory deduction must restore stock via a compensating ledger entry. | Two ledger rows exist (deduct, restore); the balance returns to its original value; neither row is deleted. |
| FR-ORD-006 | M | 🟡 | An order must not be completed while a balance remains due. | Completing an unpaid order returns `422` with `error_code = order_not_settled`. |
| FR-ORD-007 | M | 🟡 | Every status change must be recorded with actor and timestamp. | `order_status_histories` contains one row per transition. |
| FR-ORD-008 | S | 🟡 | Order items must be editable while the order is Pending. | Editing an Accepted order returns `422` unless the actor holds `orders.edit_after_accept`. |
| FR-ORD-009 | M | 🟡 | Orders must be listable and filterable by branch, status, payment status, date range, cashier and customer. | Filters combine; results are branch-scoped. |
| FR-ORD-010 | S | 🟡 | Voiding an order must require elevated permission and a reason. | A Cashier receives `403`; a Manager succeeds and an audit row is written. |
| FR-ORD-011 | C | 🔵 | Split one order into two bills. | Deferred. |
| FR-ORD-012 | C | 🔵 | Merge orders across tables. | Deferred. |

### 2.8 Payments — `FR-PAY`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-PAY-001 | M | 🟡 | Payments must support Cash, Card, QR and Bank Transfer. | All four are accepted and reportable separately. |
| FR-PAY-002 | M | 🟡 | An order must accept multiple payments (split tender). | Two payments summing to the grand total move the order to `paid`. |
| FR-PAY-003 | M | 🟡 | Total captured payments must never exceed the grand total, except for cash tendered. | Overpayment by card returns `422` with `error_code = overpayment`. |
| FR-PAY-004 | M | 🟡 | Cash payments must record the tendered amount and compute change. | `change_due = tendered − amount_applied`, never negative. |
| FR-PAY-005 | M | 🟡 | Non-cash payments must record a reference. | Card/QR/transfer without a reference returns `422`. |
| FR-PAY-006 | M | 🟡 | Payment status must be derived, never set directly by a client. | Any request attempting to set `payment_status` is ignored and logged. |
| FR-PAY-007 | M | 🟡 | Payments must be voidable before completion with elevated permission and a reason. | Voiding recomputes `paid_total`; the original row is retained with `status = voided`. |
| FR-PAY-008 | M | 🟡 | Refunds must be supported after completion, full or partial, never exceeding the captured amount. | Over-refund returns `422`; a refund row plus a loyalty reversal are written. |
| FR-PAY-009 | M | 🟡 | Duplicate payment submissions must be idempotent. | Same `Idempotency-Key` yields one payment. |
| FR-PAY-010 | S | 🟡 | A cash drawer session (open float → close count) must reconcile expected against counted cash. | Variance is computed and stored; closing twice returns `422`. |
| FR-PAY-011 | W | 🔵 | Live card authorisation via a gateway. | Excluded — [01](01-project-overview.md) A7. |

### 2.9 Kitchen Display System — `FR-KDS`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-KDS-001 | M | 🟡 | Accepted orders must appear on the KDS for their branch. | A ticket appears within one poll interval (≤ 5 s). |
| FR-KDS-002 | M | 🟡 | Kitchen staff must transition tickets Queued → Preparing → Ready. | Skipping Preparing returns `422` unless `kitchen.skip_preparing` is granted. |
| FR-KDS-003 | M | 🟡 | Ticket transitions must propagate to the parent order status. | All tickets Ready ⇒ order Ready. |
| FR-KDS-004 | S | 🟡 | Tickets must be routed to a station based on the product's category. | Drinks tickets appear only on the bar station screen. |
| FR-KDS-005 | M | 🟡 | Tickets must display elapsed time and highlight breaches of the SLA. | A ticket older than the configured SLA is visually flagged and appears in the late-ticket report. |
| FR-KDS-006 | M | 🟡 | The KDS must be usable without a keyboard, on a touch screen, at arm's length. | Touch targets ≥ 44 px; primary actions reachable in one tap. |
| FR-KDS-007 | S | 🟡 | Cancelled orders must disappear from the KDS within one poll interval. | Cancelling removes the ticket and notifies the station. |
| FR-KDS-008 | C | 🔵 | Real-time push instead of polling. | Deferred — [01](01-project-overview.md) A8. |
| FR-KDS-009 | S | 🟡 | Item-level completion within a ticket. | Individual lines can be marked prepared; the ticket completes when all lines are done. |

### 2.10 Ingredients, recipes, inventory — `FR-INV`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-INV-001 | M | 🟡 | CRUD for ingredients with a unit of measure, cost per unit and reorder level. | Duplicate ingredient name returns `422`. |
| FR-INV-002 | M | 🟡 | Units must support conversion within a family (kg↔g, L↔ml). | A recipe in grams deducts correctly from stock held in kilograms. |
| FR-INV-003 | M | 🟡 | Cross-family conversion must be rejected. | A recipe specifying litres for an ingredient stocked in kilograms returns `422`. |
| FR-INV-004 | M | 🟡 | Products must support a recipe of ingredient quantities per unit sold. | Recipe CRUD works; an ingredient may appear at most once per recipe. |
| FR-INV-005 | M | 🟡 | Selling a product must deduct its recipe ingredients from the selling branch. | Cappuccino × 2 deducts milk 0.40 L and coffee 0.04 kg. |
| FR-INV-006 | M | 🟡 | Every movement must create an immutable stock transaction with the resulting balance. | Ledger rows are never updated or deleted; sum of `quantity_change` equals `quantity_on_hand`. |
| FR-INV-007 | M | 🟡 | Stock In, Stock Out, Adjustment and Transfer must all be supported. | Each type is creatable and appears distinctly in the ledger and reports. |
| FR-INV-008 | M | 🟡 | Adjustments must require a reason and elevated permission. | Missing reason returns `422`; a Cashier receives `403`. |
| FR-INV-009 | M | 🟡 | Stock must not go negative unless explicitly permitted by configuration. | With `allow_negative_stock = false`, an over-consuming sale returns `422`. |
| FR-INV-010 | M | 🟡 | Falling to or below the reorder level must raise a low-stock notification. | Managers of that branch receive one notification; it is not repeated while the condition persists. |
| FR-INV-011 | M | 🟡 | Weighted average cost must be recalculated on every stock-in. | New average = (old qty × old cost + in qty × in cost) ÷ total qty. |
| FR-INV-012 | S | 🟡 | Recipe wastage percentage must be applied to deductions. | 5 % wastage on 0.20 L deducts 0.21 L. |
| FR-INV-013 | S | 🟡 | Physical stock counts must be recordable, producing adjustment entries. | Counting 9.5 against an expected 10.0 writes a −0.5 adjustment with reason `stock_count`. |
| FR-INV-014 | C | 🔵 | Batch and expiry tracking. | Deferred. |
| FR-INV-015 | M | 🟡 | Balances must be rebuildable from the ledger. | A rebuild command reproduces every `inventories.quantity_on_hand` exactly. |

### 2.11 Stock transfers — `FR-TRF`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-TRF-001 | M | 🟡 | A branch must be able to request a transfer of ingredients from another branch. | Same source and destination returns `422`. |
| FR-TRF-002 | M | 🟡 | Transfers must follow Draft → Pending → Approved → In Transit → Received, with Rejected and Cancelled terminals. | Invalid transitions return `422`. |
| FR-TRF-003 | M | 🟡 | Approval must be required from a user with authority at the **source** branch. | The destination manager cannot approve their own request. |
| FR-TRF-004 | M | 🟡 | Dispatch must deduct from the source branch; receipt must add to the destination. | Two ledger rows are created — `transfer_out` and `transfer_in`. |
| FR-TRF-005 | M | 🟡 | Received quantities may differ from dispatched; the variance must be recorded. | A variance writes an adjustment at the destination and flags the transfer. |
| FR-TRF-006 | M | 🟡 | Stock in transit must not be counted as available at either branch. | Between dispatch and receipt, neither branch's available balance includes the quantity. |
| FR-TRF-007 | S | 🟡 | Transfer cost must carry the source branch's average cost to the destination. | Destination average cost recalculates using the transferred cost. |
| FR-TRF-008 | M | 🟡 | Insufficient source stock must block dispatch. | Dispatch returns `422` listing shortfalls. |

### 2.12 Expenses — `FR-EXP`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-EXP-001 | M | 🟡 | Expenses must be recordable against a branch, a category and a date. | Future-dated expenses beyond a configured tolerance return `422`. |
| FR-EXP-002 | M | 🟡 | Expenses must follow Draft → Pending → Approved / Rejected → Paid. | Invalid transitions return `422`. |
| FR-EXP-003 | M | 🟡 | Expenses above a configured threshold must require manager approval. | Below-threshold expenses may auto-approve if configured. |
| FR-EXP-004 | M | 🟡 | A user must not approve their own expense. | Self-approval returns `403` with `error_code = self_approval_forbidden`. |
| FR-EXP-005 | S | 🟡 | A receipt image must be attachable. | Only PDF/JPEG/PNG up to the configured size are accepted; MIME is verified from content, not the filename. |
| FR-EXP-006 | M | 🟡 | Approved expenses must flow into profit reporting. | Profit = Revenue − COGS − Approved Expenses for the period. |
| FR-EXP-007 | S | 🟡 | Approved expenses must not be editable; corrections require a reversal. | Editing returns `422` with `error_code = expense_locked`. |
| FR-EXP-008 | C | 🔵 | Recurring expenses (rent, utilities). | Deferred. |

### 2.13 Reporting — `FR-RPT`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-RPT-001 | M | 🟡 | Daily sales summary by branch: order count, gross, discount, tax, net, by payment method. | Figures reconcile exactly to the payments table for the period. |
| FR-RPT-002 | M | 🟡 | Product performance: quantity sold, revenue, COGS, margin. | Ranking is stable and ties are broken deterministically. |
| FR-RPT-003 | M | 🟡 | Inventory valuation at a point in time. | Value = Σ (quantity_on_hand × average_cost) per branch. |
| FR-RPT-004 | M | 🟡 | Stock movement report by ingredient and date range. | Opening + in − out = closing, always. |
| FR-RPT-005 | M | 🟡 | Profit report: Revenue − COGS − Expenses. | Matches manual calculation from the underlying ledgers. |
| FR-RPT-006 | S | 🟡 | Cashier shift report: orders, tenders, discounts, voids, drawer variance. | Reconciles to the cash drawer session. |
| FR-RPT-007 | S | 🟡 | Reports must be exportable to CSV. | Export contains the same rows as the on-screen report, with a UTF-8 BOM. |
| FR-RPT-008 | M | 🟡 | All reports must respect branch scope. | A Manager sees only their branches; totals never include others. |
| FR-RPT-009 | S | 🟡 | Reports must use the branch business day, not the UTC day. | An order at 01:00 branch-local on the 6th is not in the 5th's report. |
| FR-RPT-010 | C | 🔵 | Scheduled email delivery of daily reports. | Deferred. |

### 2.14 Notifications — `FR-NTF`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-NTF-001 | M | 🟡 | In-app notifications for low stock, transfer requests, expense approvals and order events. | Each event produces exactly one notification per eligible recipient. |
| FR-NTF-002 | M | 🟡 | Notifications must be targeted by role, branch and permission. | A Cashier never receives an expense-approval notification. |
| FR-NTF-003 | M | 🟡 | Notifications must be markable read individually and in bulk. | Unread count decreases accordingly. |
| FR-NTF-004 | M | 🟡 | Repeated identical events must be deduplicated within a cool-down window. | Five sales that each keep milk below reorder level produce one notification per window. |
| FR-NTF-005 | S | 🔵 | Email delivery for high-severity events. | Deferred; channel abstraction is designed in MVP. |

### 2.15 Audit — `FR-AUD`

| ID | Pri | Scope | Requirement | Acceptance criterion |
|---|---|---|---|---|
| FR-AUD-001 | M | 🟡 | Every create, update and delete on a financially or operationally significant model must be audited. | Table list in [22](22-security.md) §7.2. |
| FR-AUD-002 | M | 🟡 | Audit rows must record actor, action, entity, before/after values, IP, user agent, request ID and UTC timestamp. | All fields present and non-empty where applicable. |
| FR-AUD-003 | M | 🟡 | Audit rows must be append-only. | No API path updates or deletes them; the DB grant excludes `UPDATE`/`DELETE` on the table. |
| FR-AUD-004 | M | 🟡 | Sensitive values must be redacted in audit payloads. | Passwords and tokens never appear, even in `old_values`. |
| FR-AUD-005 | S | 🟡 | Audit logs must be searchable by actor, entity, action and date range. | Super Admin and Admin only. |
| FR-AUD-006 | M | 🟡 | Audit writes must be inside the same transaction as the change. | Rolling back a failed operation leaves no orphan audit row. |

---

## 3. Non-functional requirements

### 3.1 Performance — `NFR-PERF`

| ID | Pri | Requirement | Measurement |
|---|---|---|---|
| NFR-PERF-001 | M | POS product browse p95 < 250 ms at 2 000 products | k6 scenario, [25](25-performance-testing.md) §5.1 |
| NFR-PERF-002 | M | `POST /orders` p95 < 400 ms at 10 concurrent terminals | [25](25-performance-testing.md) §5.2 |
| NFR-PERF-003 | M | Payment p95 < 500 ms | [25](25-performance-testing.md) §5.3 |
| NFR-PERF-004 | M | KDS poll p95 < 250 ms | [25](25-performance-testing.md) §5.4 |
| NFR-PERF-005 | S | Monthly report for one branch < 3 s | [25](25-performance-testing.md) §5.5 |
| NFR-PERF-006 | M | No endpoint issues more than 20 SQL queries per request | Query-count assertions in CI |
| NFR-PERF-007 | S | SPA first contentful paint < 1.5 s on a mid-range tablet over 4G | Lighthouse in CI |
| NFR-PERF-008 | M | System sustains 150 orders/hour/branch across 15 branches | Soak test, [25](25-performance-testing.md) §6 |

### 3.2 Reliability — `NFR-REL`

| ID | Pri | Requirement |
|---|---|---|
| NFR-REL-001 | M | 99.5 % availability during service hours. |
| NFR-REL-002 | M | RPO ≤ 15 min, RTO ≤ 1 h. |
| NFR-REL-003 | M | No partial writes: every use case is atomic. A crash mid-order leaves no order and no stock movement. |
| NFR-REL-004 | M | Inventory balances must be rebuildable from the ledger with zero drift. |
| NFR-REL-005 | S | Failed queue jobs retry with backoff and land in `failed_jobs` after 3 attempts. |
| NFR-REL-006 | M | Database backups are restorable; a restore drill is performed and recorded quarterly. |

### 3.3 Security — `NFR-SEC`

| ID | Pri | Requirement |
|---|---|---|
| NFR-SEC-001 | M | All traffic over TLS 1.2+; HTTP redirects to HTTPS; HSTS enabled. |
| NFR-SEC-002 | M | Passwords hashed with bcrypt (cost ≥ 12) or argon2id. |
| NFR-SEC-003 | M | API tokens hashed at rest and revocable. |
| NFR-SEC-004 | M | Every endpoint authorised server-side; no reliance on UI hiding. |
| NFR-SEC-005 | M | All input validated and normalised; all output escaped. |
| NFR-SEC-006 | M | Parameterised queries only; no string-concatenated SQL. |
| NFR-SEC-007 | M | Rate limiting on authentication, POS writes and reports. |
| NFR-SEC-008 | M | No secrets in the repository; `.env` git-ignored. |
| NFR-SEC-009 | M | Uploaded files are content-type verified, size limited, stored outside the web root and never executed. |
| NFR-SEC-010 | M | Cross-branch access attempts are denied and logged. |
| NFR-SEC-011 | S | Dependency vulnerability scanning in CI, blocking on high severity. |
| NFR-SEC-012 | M | PAN, CVV and full card data are never stored — only a method, a masked reference and last four digits. |

### 3.4 Usability — `NFR-USE`

| ID | Pri | Requirement |
|---|---|---|
| NFR-USE-001 | M | The POS sell path must be completable with a touch screen alone. |
| NFR-USE-002 | M | A trained cashier must complete a three-item cash sale in ≤ 30 s. |
| NFR-USE-003 | M | Errors must state what went wrong and what to do next, in plain language. |
| NFR-USE-004 | S | WCAG 2.1 AA for back-office screens; KDS colour cues must not rely on hue alone. |
| NFR-USE-005 | S | POS and KDS target 1024 × 768 and above; back office is responsive from 768 px. |
| NFR-USE-006 | M | Destructive actions (void, cancel, adjust) require explicit confirmation showing the consequence. |

### 3.5 Maintainability — `NFR-MNT`

| ID | Pri | Requirement |
|---|---|---|
| NFR-MNT-001 | M | Backend line coverage ≥ 80 %; money and inventory domain classes ≥ 95 %. |
| NFR-MNT-002 | M | Pint and oxlint pass with zero warnings in CI. |
| NFR-MNT-003 | M | Every migration is reversible, or documents why it is not. |
| NFR-MNT-004 | M | The API is described by an OpenAPI 3.1 document generated in CI. |
| NFR-MNT-005 | S | Static analysis (PHPStan/Larastan) at level 6 or above. |
| NFR-MNT-006 | M | No business rule is expressed in more than one place. |

### 3.6 Compliance and data — `NFR-CMP`

| ID | Pri | Requirement |
|---|---|---|
| NFR-CMP-001 | M | Financial records (orders, payments, refunds) retained ≥ 7 years or per local law. |
| NFR-CMP-002 | M | Audit logs retained ≥ 24 months. |
| NFR-CMP-003 | M | Customer data exportable and erasable on request without destroying financial totals. |
| NFR-CMP-004 | S | Receipt content configurable per jurisdiction (tax registration number, legal entity). |
| NFR-CMP-005 | M | Time is stored in UTC; business-day boundaries are branch-local. |

---

## 4. Constraints

| ID | Constraint | Source |
|---|---|---|
| CON-001 | Frontend must be React + TypeScript + Tailwind CSS. | Stakeholder |
| CON-002 | Backend must be Laravel + PHP. | Stakeholder |
| CON-003 | Database must be MySQL. | Stakeholder |
| CON-004 | Authentication must use Laravel Sanctum. | Stakeholder |
| CON-005 | The interface must be a REST API. | Stakeholder |
| CON-006 | Source control must be Git hosted on GitHub. | Stakeholder |
| CON-007 | Tax percentages and financial rates must never be hard-coded. | Stakeholder |
| CON-008 | PHP ≥ 8.3, as required by `backend/composer.json`. | Repository |
| CON-009 | `.env.example` currently targets SQLite and must be changed to MySQL before MVP. | Repository |
| CON-010 | Sanctum is not installed and must be added. | Repository |
| CON-011 | The repository is not under version control and must be initialised. | Repository |
| CON-012 | Tailwind is declared at the repository root, not in `Frontend/`. | Repository |

---

## 5. Open questions ⚠️

Each blocks or reshapes a requirement. Owners and due dates are tracked in
[31-development-roadmap.md](31-development-roadmap.md) §7.

| # | Question | Blocks | Interim assumption |
|---|---|---|---|
| Q1 | Which tax jurisdiction(s), and is tax inclusive or exclusive in menu prices? | FR-CAT-010, [09](09-business-rules.md) §6 | Both supported; per-rate flag; no default value shipped. |
| Q2 | Is a service charge levied, and is it itself taxable? | [09](09-business-rules.md) §7 | Supported, disabled by default. |
| Q3 | Cash rounding rule (e.g. nearest 0.05)? | [09](09-business-rules.md) §8 | Configurable, off by default. |
| Q4 | Discount authority thresholds per role? | FR-POS-005 | Cashier 10 %, Manager 50 %, Admin unlimited. |
| Q5 | Loyalty earn and redemption rates? | FR-CUS-003/004 | Configurable, no shipped default. |
| Q6 | Should inventory deduct at Accepted or Completed? | FR-INV-005, A4 | Accepted. |
| Q7 | Are fiscal/tax-authority receipt numbers required? | FR-ORD-002, NFR-CMP-004 | Not required. |
| Q8 | Do delivery orders need driver assignment in MVP? | FR-ORD-001 | No; order type only. |
| Q9 | Is negative stock ever permitted operationally? | FR-INV-009, A12 | No, configurable. |
| Q10 | Expense approval threshold amount? | FR-EXP-003 | Configurable, no shipped default. |

---

## 6. Traceability

| Requirement group | Design | Tests |
|---|---|---|
| FR-AUTH | [08](08-authentication-authorization.md) | [24](24-qa-test-plan.md) §5 |
| FR-RBAC | [04](04-user-roles-permissions.md) | [24](24-qa-test-plan.md) §6 |
| FR-CAT | [05](05-database-design.md) | [24](24-qa-test-plan.md) §4 |
| FR-POS | [10](10-pos-workflow.md) | [24](24-qa-test-plan.md) §4.1 |
| FR-ORD | [11](11-order-workflow.md) | [24](24-qa-test-plan.md) §4.2 |
| FR-PAY | [12](12-payment-workflow.md) | [24](24-qa-test-plan.md) §7 |
| FR-KDS | [13](13-kitchen-workflow.md) | [24](24-qa-test-plan.md) §4.3 |
| FR-INV | [14](14-inventory-workflow.md) | [24](24-qa-test-plan.md) §8 |
| FR-TRF | [15](15-stock-transfer.md) | [24](24-qa-test-plan.md) §8.4 |
| FR-CUS | [16](16-customer-loyalty.md) | [24](24-qa-test-plan.md) §7.4 |
| FR-EXP | [17](17-expense-management.md) | [24](24-qa-test-plan.md) §4.5 |
| FR-RPT | [18](18-reporting.md) | [24](24-qa-test-plan.md) §7.5 |
| FR-NTF | [19](19-notifications.md) | [24](24-qa-test-plan.md) §4.6 |
| FR-AUD | [22](22-security.md) §7 | [24](24-qa-test-plan.md) §11 |
| NFR-PERF | [25](25-performance-testing.md) | [25](25-performance-testing.md) |
| NFR-SEC | [22](22-security.md) | [24](24-qa-test-plan.md) §11 |
