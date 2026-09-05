# 07 — API Documentation

> **Document purpose.** Define the REST contract between the React SPA and the Laravel API: global
> conventions, the response envelope, and the endpoint catalogue. This document is the **authoritative
> source for endpoints**. Field-level validation rules are catalogued in
> [20-validation-rules.md](20-validation-rules.md); error semantics in
> [21-error-handling.md](21-error-handling.md); permissions in
> [04-user-roles-permissions.md](04-user-roles-permissions.md).

**Status:** 🟡 MVP — Planned. `backend/routes/api.php` does not exist yet
([01](01-project-overview.md) §2).

---

## 1. Conventions

### 1.1 Base and versioning

```text
Base URL (production)   https://api.restaurantos.example/api/v1
Base URL (local)        http://localhost:8000/api/v1
```

The version is in the path. Breaking changes create `/api/v2`; `/api/v1` is then maintained for one
release cycle. Additive changes (new optional field, new endpoint) are **not** breaking and ship in
place.

### 1.2 Required headers

| Header | Value | Required |
|---|---|---|
| `Accept` | `application/json` | Always. Without it Laravel may return an HTML error page. |
| `Content-Type` | `application/json` | On requests with a body. |
| `Authorization` | `Bearer <token>` | All endpoints except login, password reset and health. |
| `X-Branch-Id` | Branch ID | Optional. Narrows scope; can never widen it ([04](04-user-roles-permissions.md) §5.3). |
| `Idempotency-Key` | UUID v4 | Required on `POST /orders`, `/payments`, `/refunds`. Recommended elsewhere. |
| `X-Request-Id` | UUID v4 | Optional. Echoed back and written into logs and audit rows. |

### 1.3 Response envelope

Every response uses the same shape. Nothing is returned bare.

**Success — single resource**

```json
{
  "data": { "id": 42, "order_number": "B1-20260905-0042" },
  "meta": { "request_id": "9f1c...", "timestamp": "2026-09-05T14:31:00+07:00" }
}
```

**Success — collection**

```json
{
  "data": [ { "id": 1 }, { "id": 2 } ],
  "meta": {
    "pagination": {
      "total": 248, "per_page": 25, "current_page": 1, "last_page": 10,
      "from": 1, "to": 25, "next_cursor": "eyJpZCI6MjV9"
    },
    "request_id": "9f1c...",
    "timestamp": "2026-09-05T14:31:00+07:00"
  }
}
```

**Error**

```json
{
  "message": "The given data was invalid.",
  "error_code": "validation_failed",
  "errors": { "quantity": ["The quantity must be greater than 0."] },
  "meta": { "request_id": "9f1c...", "timestamp": "2026-09-05T14:31:00+07:00" }
}
```

`error_code` is a stable machine-readable string. The frontend branches on `error_code`, never on
`message`, which is localisable and may change. Full catalogue in
[21-error-handling.md](21-error-handling.md) §4.

### 1.4 Money representation

Money is never a JSON number. Each monetary field is emitted as an object:

```json
"grand_total": { "amount": "12.50", "minor": 1250, "currency": "THB", "formatted": "฿12.50" }
```

| Field | Purpose |
|---|---|
| `amount` | Exact decimal **string**. Use this for display and for sending back. |
| `minor` | Integer minor units. Use this for arithmetic. |
| `currency` | ISO 4217, from the branch. |
| `formatted` | Pre-formatted for display. Convenience only; never parsed. |

**Requests** accept either a decimal string (`"12.50"`) or a minor-unit integer, disambiguated per
field in [20-validation-rules.md](20-validation-rules.md). A JSON float in a money field is rejected
`422` — `0.1 + 0.2` is why.

### 1.5 Common query parameters

| Parameter | Applies to | Notes |
|---|---|---|
| `page`, `per_page` | Paged lists | `per_page` default 25, max 100. |
| `cursor` | Cursor lists (`orders`, `stock-transactions`, `audit-logs`) | Opaque. |
| `sort` | Lists | Whitelisted fields only, `-` prefix for descending: `sort=-placed_at`. |
| `filter[...]` | Lists | Whitelisted per endpoint. Unknown filters return `422`, not silently ignored. |
| `include` | Detail endpoints | Whitelisted relations: `include=items,payments`. |
| `date_from`, `date_to` | Date-ranged lists | `Y-m-d`, interpreted in the **branch business day**. |

> **Why unknown filters are rejected.** Silently ignoring `filter[branch_id]=2` would let a caller
> believe they had scoped a query when they had not. Failing loudly is the safer default.

### 1.6 HTTP status usage

| Code | Used for |
|---|---|
| `200` | Successful read or update. |
| `201` | Resource created. |
| `202` | Accepted for asynchronous processing (report export). |
| `204` | Successful delete with no body. |
| `400` | Malformed request — unparseable JSON, missing `Idempotency-Key` where required. |
| `401` | Missing, invalid, expired or revoked token. |
| `403` | Authenticated but not permitted, or a policy rule failed. |
| `404` | Not found — **or** found but outside branch scope ([22](22-security.md) §5). |
| `409` | Concurrency conflict — optimistic lock failure, deadlock after retries. |
| `410` | Idempotency key reused with a different payload. |
| `422` | Validation failure or a domain rule violation. |
| `429` | Rate limit exceeded. `Retry-After` is always present. |
| `500` | Unhandled server error. Body carries `request_id` and nothing else. |
| `503` | Maintenance mode or a dependency is unavailable. |

**`422` covers two distinct things** — field validation and business-rule violations — distinguished by
`error_code`. See [21](21-error-handling.md) §3.

### 1.7 Rate limits

| Bucket | Limit | Applies to |
|---|---|---|
| `auth` | 5 / 15 min per email **and** per IP | Login, password reset |
| `pos-write` | 120 / min per user | Order and payment creation |
| `read` | 300 / min per user | All `GET` |
| `report` | 10 / min per user | Report generation |
| `export` | 3 / min per user | CSV export |

Responses carry `X-RateLimit-Limit`, `X-RateLimit-Remaining` and, on `429`, `Retry-After`.

---

## 2. Authentication

### 2.1 Log in

```text
POST /api/v1/auth/login
```

**Purpose:** Exchange credentials for a personal access token.
**Authentication:** None.
**Permission:** None.

**Request**

```json
{
  "email": "cashier@restaurantos.example",
  "password": "correct-horse-battery-staple",
  "device_name": "pos-terminal-1",
  "remember": false
}
```

**Validation**

- `email`: required, email, max 190, must exist (error is deliberately generic — §2.1 security note)
- `password`: required, string, min 8
- `device_name`: required, string, max 100
- `remember`: optional, boolean

**Response** `201`

```json
{
  "data": {
    "token": "12|kJ8xQ...plaintext-shown-once",
    "token_type": "Bearer",
    "expires_at": "2026-09-06T02:31:00+07:00",
    "abilities": ["pos:*", "orders:*", "payments:create"],
    "user": {
      "id": 7,
      "name": "Ana Cruz",
      "email": "cashier@restaurantos.example",
      "branch": { "id": 1, "code": "B1", "name": "Riverside", "timezone": "Asia/Bangkok" },
      "roles": ["cashier"],
      "permissions": ["pos.access", "orders.create", "payments.create"],
      "branch_scope": [1],
      "must_change_password": false
    }
  },
  "meta": { "request_id": "9f1c...", "timestamp": "2026-09-05T14:31:00+07:00" }
}
```

**Error responses**

| Code | `error_code` | Cause |
|---|---|---|
| `400` | `malformed_request` | Unparseable body. |
| `401` | `invalid_credentials` | Wrong email or password. Identical response for both. |
| `403` | `account_inactive` | `is_active = false`. |
| `403` | `account_locked` | Too many failures; `locked_until` in the body. |
| `422` | `validation_failed` | Missing or malformed fields. |
| `429` | `too_many_attempts` | Rate limit. `Retry-After` present. |
| `500` | `server_error` | |

> **Security note.** `invalid_credentials` is returned for both an unknown email and a wrong password,
> with a constant-time comparison and a dummy hash computation for unknown emails, so response timing
> does not reveal which accounts exist ([22](22-security.md) §3).

### 2.2 Current user

```text
GET /api/v1/auth/me
```

**Purpose:** Return the authenticated user with roles, permissions and branch scope in one call, so the
SPA can render navigation without a second request (FR-AUTH-009).
**Authentication:** Bearer.
**Permission:** None beyond authentication.

**Response** `200` — the same `user` object as §2.1, plus:

```json
{
  "data": {
    "id": 7,
    "name": "Ana Cruz",
    "roles": [{ "name": "cashier", "display_name": "Cashier", "branch_id": 1 }],
    "permissions": ["pos.access", "orders.create", "payments.create"],
    "branch_scope": [1],
    "active_branch": { "id": 1, "code": "B1", "currency_code": "THB" },
    "thresholds": { "discount_max_percent": "10.00", "receipt_max_reprints": 2 },
    "unread_notifications": 3
  }
}
```

`thresholds` lets the UI pre-validate before the server rejects. The server remains the enforcement
point.

**Errors:** `401 unauthenticated` · `403 account_inactive` · `500`

### 2.3 Remaining authentication endpoints

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `POST` | `/auth/login-pin` | PIN login on a shared terminal (FR-AUTH-011) | None |
| `POST` | `/auth/logout` | Revoke the current token | Authenticated |
| `POST` | `/auth/logout-all` | Revoke every token for the user | Authenticated |
| `POST` | `/auth/refresh` | Extend an unexpired token's idle window | Authenticated |
| `POST` | `/auth/change-password` | Change own password; revokes other tokens | `profile.change_password` |
| `POST` | `/auth/forgot-password` | Send a reset link | None |
| `POST` | `/auth/reset-password` | Complete a reset | None |
| `GET` | `/auth/tokens` | List own active tokens (devices) | Authenticated |
| `DELETE` | `/auth/tokens/{id}` | Revoke a specific token | Authenticated |

Mechanics in [08-authentication-authorization.md](08-authentication-authorization.md).

---

## 3. POS

### 3.1 Product grid

```text
GET /api/v1/pos/products
```

**Purpose:** Return sellable products for the active branch, with branch-effective prices and
availability. Optimised for the POS grid.
**Authentication:** Bearer. **Permission:** `pos.access`

**Query parameters**

| Parameter | Rule |
|---|---|
| `branch_id` | Optional. Must be inside branch scope; defaults to the active branch. |
| `category_id` | Optional, exists. |
| `search` | Optional, min 2 characters. Matches name or SKU. |
| `available_only` | Optional boolean, default `true`. |
| `per_page` | Default 100 for this endpoint — the POS grid loads a page at a time. |

**Response** `200`

```json
{
  "data": [
    {
      "id": 15,
      "sku": "BEV-CAP",
      "name": "Cappuccino",
      "category": { "id": 3, "name": "Hot Drinks" },
      "image_url": "https://cdn.example/products/cap.webp",
      "effective_price": { "amount": "4.50", "minor": 450, "currency": "THB", "formatted": "฿4.50" },
      "tax_rate": "0.0700",
      "is_tax_inclusive": false,
      "has_variants": true,
      "is_available": true,
      "stock_status": "in_stock",
      "preparation_minutes": 4,
      "variants": [
        { "id": 31, "name": "Regular", "price_delta": { "amount": "0.00", "minor": 0 }, "is_default": true,  "is_available": true },
        { "id": 32, "name": "Large",   "price_delta": { "amount": "1.00", "minor": 100 }, "is_default": false, "is_available": true }
      ]
    }
  ],
  "meta": { "pagination": { "total": 214, "per_page": 100, "current_page": 1 } }
}
```

`stock_status` is one of `in_stock`, `low_stock`, `out_of_stock`, `not_tracked`. It is derived from the
recipe and branch inventory so the cashier sees availability without holding cost data — a Cashier
never receives `cost_price` ([04](04-user-roles-permissions.md) G11).

**Errors:** `401` · `403 forbidden` · `403 branch_out_of_scope` · `422 validation_failed` · `429` · `500`

### 3.2 Calculate cart

```text
POST /api/v1/pos/cart/calculate
```

**Purpose:** Return **authoritative** totals for a cart without creating an order. The POS calls this
after every cart mutation. Client-side totals are advisory only
([02](02-system-architecture.md) §5.1).
**Authentication:** Bearer. **Permission:** `pos.access`

**Request**

```json
{
  "branch_id": 1,
  "order_type": "dine_in",
  "customer_id": 88,
  "items": [
    { "product_id": 15, "product_variant_id": 32, "quantity": "2.000", "discount_type": "none", "discount_value": "0.00", "note": "extra hot" },
    { "product_id": 21, "product_variant_id": null, "quantity": "1.000" }
  ],
  "discount_type": "percentage",
  "discount_value": "10.00",
  "loyalty_points_to_redeem": 0
}
```

**Validation**

- `branch_id`: required, exists, inside branch scope, branch active
- `order_type`: required, in `dine_in,takeaway,delivery`
- `customer_id`: optional, exists, active
- `items`: required, array, min 1, max 200
- `items.*.product_id`: required, exists, active, available at branch
- `items.*.product_variant_id`: required **if** the product has variants; must belong to that product
- `items.*.quantity`: required, decimal, `> 0`, max 3 decimals, max 9999
- `items.*.discount_type`: in `none,percentage,fixed`
- `items.*.discount_value`: `>= 0`; if percentage, `<= 100`; if fixed, `<= line_subtotal`
- `discount_value`: subject to the role threshold ([04](04-user-roles-permissions.md) §7)
- `loyalty_points_to_redeem`: integer, `>= 0`, `<= customer balance`, multiple of `redeem_increment`

**Response** `200`

```json
{
  "data": {
    "currency": "THB",
    "items": [
      {
        "product_id": 15, "product_variant_id": 32,
        "name": "Cappuccino (Large)", "sku": "BEV-CAP-L",
        "quantity": "2.000",
        "unit_price":          { "amount": "5.50",  "minor": 550 },
        "line_subtotal":       { "amount": "11.00", "minor": 1100 },
        "line_discount_amount":{ "amount": "1.10",  "minor": 110 },
        "taxable_amount":      { "amount": "9.90",  "minor": 990 },
        "tax_rate": "0.0700",
        "line_tax_amount":     { "amount": "0.69",  "minor": 69 },
        "line_total":          { "amount": "10.59", "minor": 1059 }
      }
    ],
    "subtotal":              { "amount": "15.00", "minor": 1500 },
    "discount_amount":       { "amount": "1.50",  "minor": 150 },
    "loyalty_discount_amount": { "amount": "0.00", "minor": 0 },
    "taxable_amount":        { "amount": "13.50", "minor": 1350 },
    "tax_amount":            { "amount": "0.94",  "minor": 94 },
    "service_charge_amount": { "amount": "0.00",  "minor": 0 },
    "rounding_adjustment":   { "amount": "0.00",  "minor": 0 },
    "grand_total":           { "amount": "14.44", "minor": 1444 },
    "loyalty_points_to_earn": 14,
    "stock_warnings": [
      { "ingredient_id": 4, "ingredient_name": "Milk", "required": "0.4000", "available": "0.3000", "unit": "l", "severity": "insufficient" }
    ]
  }
}
```

`stock_warnings` is advisory at this stage — the cart is not yet an order. The hard block happens at
order creation (§4.1). This lets the cashier see the problem before the customer has committed.

**Errors:** `401` · `403` · `422 validation_failed` · `422 branch_inactive` ·
`422 product_unavailable` · `422 discount_limit_exceeded` · `422 loyalty_redeem_invalid` · `429` · `500`

### 3.3 Other POS endpoints

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `GET` | `/pos/categories` | Category grid for the branch | `pos.access` |
| `GET` | `/pos/products/{id}` | Product detail with variants and stock | `pos.access` |
| `GET` | `/pos/customers/search` | Lookup by phone, name or code | `customers.view` |
| `GET` | `/pos/session` | Current cash drawer session | `payments.manage_shift` |

---

## 4. Orders

### 4.1 Create an order

The most important endpoint in the system.

```text
POST /api/v1/orders
```

**Purpose:** Create an order from a cart, recalculating all totals server-side.
**Authentication:** Bearer. **Permission:** `orders.create`
**Idempotency:** `Idempotency-Key` header **required**.

**Request**

```json
{
  "branch_id": 1,
  "order_type": "dine_in",
  "table_number": "12",
  "customer_id": 88,
  "items": [
    { "product_id": 15, "product_variant_id": 32, "quantity": "2.000", "note": "extra hot" },
    { "product_id": 21, "product_variant_id": null, "quantity": "1.000" }
  ],
  "discount_type": "percentage",
  "discount_value": "10.00",
  "discount_reason": "Staff meal",
  "loyalty_points_to_redeem": 0,
  "note": "Birthday - bring candle",
  "auto_accept": false
}
```

**Validation**

All rules from §3.2, plus:

- `Idempotency-Key`: required header, UUID v4
- `table_number`: required if `order_type = dine_in` **and** `pos.require_table_number` is enabled;
  must be absent for `takeaway` and `delivery`
- `discount_reason`: required when `discount_value` exceeds `discount.require_reason_above`
- `note`: optional, max 500
- `auto_accept`: optional boolean, default `false`; requires `orders.accept`

**Business rules applied**

1. Prices, tax and totals are recalculated from the database — client-supplied prices are **ignored**.
2. Stock sufficiency is checked for every recipe-backed line; insufficiency is a hard `422`.
3. The order number is allocated atomically from `daily_sequences`.
4. Price, cost and tax rate are snapshotted onto every line.
5. Inventory is deducted only if `inventory.deduction_point = on_create`; the default is `on_accept`
   ([01](01-project-overview.md) A4), so a plain create does not move stock.

**Response** `201`

```json
{
  "data": {
    "id": 4213,
    "order_number": "B1-20260905-0042",
    "branch": { "id": 1, "code": "B1", "name": "Riverside" },
    "business_date": "2026-09-05",
    "order_type": "dine_in",
    "table_number": "12",
    "status": "pending",
    "payment_status": "unpaid",
    "customer": { "id": 88, "name": "Somchai P.", "loyalty_points_balance": 240 },
    "cashier": { "id": 7, "name": "Ana Cruz" },
    "items": [
      {
        "id": 9901, "product_id": 15, "product_variant_id": 32,
        "name": "Cappuccino (Large)", "sku": "BEV-CAP-L",
        "quantity": "2.000",
        "unit_price":   { "amount": "5.50",  "minor": 550 },
        "line_total":   { "amount": "10.59", "minor": 1059 },
        "status": "pending", "note": "extra hot"
      }
    ],
    "subtotal":       { "amount": "15.00", "minor": 1500 },
    "discount_amount":{ "amount": "1.50",  "minor": 150 },
    "tax_amount":     { "amount": "0.94",  "minor": 94 },
    "grand_total":    { "amount": "14.44", "minor": 1444 },
    "paid_total":     { "amount": "0.00",  "minor": 0 },
    "balance_due":    { "amount": "14.44", "minor": 1444 },
    "loyalty_points_to_earn": 14,
    "placed_at": "2026-09-05T14:31:00+07:00",
    "version": 0
  }
}
```

**Error responses**

| Code | `error_code` | Cause |
|---|---|---|
| `400` | `idempotency_key_required` | Header missing. |
| `401` | `unauthenticated` | |
| `403` | `forbidden` | Lacks `orders.create`. |
| `403` | `discount_limit_exceeded` | Discount above the role threshold. |
| `404` | `not_found` | Branch, product or customer outside scope. |
| `409` | `concurrency_conflict` | Deadlock persisted after retries. |
| `410` | `idempotency_key_conflict` | Key reused with a different payload. |
| `422` | `validation_failed` | Field-level failure. |
| `422` | `insufficient_stock` | See the shape below. |
| `422` | `branch_inactive` · `product_unavailable` · `variant_required` · `empty_order` | Domain rules. |
| `429` | `too_many_requests` | |
| `500` | `server_error` | |

**`insufficient_stock` body** — the cashier must be told exactly what is short:

```json
{
  "message": "There is not enough stock to fulfil this order.",
  "error_code": "insufficient_stock",
  "errors": {
    "items": [
      {
        "product_id": 15, "product_name": "Cappuccino",
        "shortfalls": [
          { "ingredient_id": 4, "ingredient_name": "Milk", "required": "0.4000", "available": "0.3000", "unit": "l" }
        ]
      }
    ]
  }
}
```

**Retry semantics:** a repeat with the same `Idempotency-Key` **and** an identical payload returns the
original `201` response, unchanged, with header `Idempotency-Replayed: true`. A repeat with a different
payload returns `410`.

### 4.2 Accept an order

```text
POST /api/v1/orders/{id}/accept
```

**Purpose:** Move Pending → Accepted. **This is where inventory is deducted** under the default policy,
and where kitchen tickets are created.
**Authentication:** Bearer. **Permission:** `orders.accept`

**Request**

```json
{ "version": 0 }
```

**Validation**

- `version`: required, integer, must match `orders.version` (optimistic lock)
- Order status must be `pending`
- Branch must be in scope

**Side effects, all in one transaction**

| Effect | Detail |
|---|---|
| Status | `pending → accepted`; `accepted_at` set |
| History | One `order_status_histories` row |
| Inventory | Recipe explosion; one `stock_transactions` row of type `sale_deduction` per ingredient; `inventories` updated; `orders.inventory_deducted_at` set |
| Kitchen | One `kitchen_tickets` row per station, with items |
| Audit | One `audit_logs` row |
| Events (after commit) | `OrderAccepted`, low-stock checks, notifications |

**Response** `200`

```json
{
  "data": {
    "id": 4213, "status": "accepted", "version": 1,
    "accepted_at": "2026-09-05T14:32:10+07:00",
    "inventory_deducted_at": "2026-09-05T14:32:10+07:00",
    "kitchen_tickets": [
      { "id": 771, "ticket_number": "T-042-BAR", "station": "BAR",   "status": "queued", "item_count": 1 },
      { "id": 772, "ticket_number": "T-042-GRL", "station": "GRILL", "status": "queued", "item_count": 1 }
    ],
    "stock_movements": [
      { "ingredient_id": 4, "ingredient_name": "Milk",   "quantity_change": "-0.4000", "unit": "l",  "balance_after": "11.6000" },
      { "ingredient_id": 9, "ingredient_name": "Coffee", "quantity_change": "-0.0400", "unit": "kg", "balance_after": "4.9600" }
    ]
  }
}
```

`stock_movements` is included only for users holding `inventory.view`.

**Errors:** `401` · `403 forbidden` · `404 not_found` · `409 version_mismatch` ·
`422 invalid_state_transition` · `422 insufficient_stock` · `422 already_deducted` · `500`

> `already_deducted` protects against a double-accept race: `inventory_deducted_at` is the idempotency
> guard (constraint C13 in [05](05-database-design.md) §8.2).

### 4.3 Cancel an order

```text
POST /api/v1/orders/{id}/cancel
```

**Purpose:** Cancel before completion and restore any deducted stock.
**Authentication:** Bearer. **Permission:** `orders.cancel` (Cashier limited to Pending, unpaid —
[04](04-user-roles-permissions.md) §4.1)

**Request**

```json
{ "reason": "Customer changed their mind", "version": 1 }
```

**Validation**

- `reason`: required, string, 3–255 characters
- `version`: required, matches
- Status must not be `completed` or `cancelled`
- No captured payment, unless the actor holds `payments.refund`

**Side effects:** status → `cancelled`; history row; if `inventory_deducted_at` is set, one
`sale_reversal` ledger row per ingredient restoring the exact deducted quantity (**never** a
recomputation from the current recipe — the recipe may have changed since); kitchen tickets cancelled;
loyalty reversed if awarded; audit row.

**Response** `200` — the cancelled order plus `stock_movements` showing the restorations.

**Errors:** `401` · `403 forbidden` · `403 payment_captured_cancel_forbidden` · `404` ·
`409 version_mismatch` · `422 invalid_state_transition` · `422 validation_failed` · `500`

### 4.4 Order lifecycle endpoints

All share the shape of §4.2: a `version` in the body, a `200` with the updated order, and the same error
family.

| Method | Path | Transition | Permission |
|---|---|---|---|
| `POST` | `/orders/{id}/accept` | Pending → Accepted | `orders.accept` |
| `POST` | `/orders/{id}/start-preparing` | Accepted → Preparing | `kitchen.update_ticket` or `orders.accept` |
| `POST` | `/orders/{id}/ready` | Preparing → Ready | `kitchen.update_ticket` |
| `POST` | `/orders/{id}/complete` | Ready → Completed | `orders.complete` |
| `POST` | `/orders/{id}/cancel` | any → Cancelled | `orders.cancel` |
| `POST` | `/orders/{id}/void` | any → Cancelled, flagged void | `orders.void` |

`POST /orders/{id}/complete` additionally requires `balance_due = 0`, otherwise
`422 order_not_settled`. It awards loyalty points (FR-CUS-003).

### 4.5 Order CRUD and reads

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `GET` | `/orders` | List. Filters: `status`, `payment_status`, `order_type`, `branch_id`, `user_id`, `customer_id`, `date_from`, `date_to`, `order_number` | `orders.view` |
| `GET` | `/orders/{id}` | Detail. `include=items,payments,histories,tickets` | `orders.view` |
| `PATCH` | `/orders/{id}` | Edit a Pending order's items, discount or note | `orders.update` |
| `GET` | `/orders/{id}/receipt` | Receipt payload for printing | `orders.view` |
| `POST` | `/orders/{id}/reprint` | Record and return a reprint | `orders.reprint_receipt` |
| `GET` | `/orders/{id}/histories` | Status transitions | `orders.view` |
| `POST` | `/orders/{id}/customer` | Attach or detach a customer | `orders.update` |

---

## 5. Payments

### 5.1 Take a payment

```text
POST /api/v1/orders/{orderId}/payments
```

**Purpose:** Record a tender against an order. Supports split payment by calling repeatedly.
**Authentication:** Bearer. **Permission:** `payments.create`
**Idempotency:** `Idempotency-Key` **required**.

**Request — cash**

```json
{
  "method": "cash",
  "amount": "14.44",
  "tendered_amount": "20.00"
}
```

**Request — card**

```json
{
  "method": "card",
  "amount": "14.44",
  "reference": "APPROVAL-884213",
  "card_last_four": "4242",
  "card_brand": "visa",
  "provider": "Terminal-3"
}
```

**Validation**

| Field | Rule |
|---|---|
| `method` | required, in `cash,card,qr,bank_transfer` |
| `amount` | required, decimal `> 0`, ≤ `balance_due` (FR-PAY-003) |
| `tendered_amount` | required **if** `method = cash`; must be `>= amount`; forbidden otherwise |
| `reference` | required **if** method ≠ `cash`; max 100 (FR-PAY-005) |
| `card_last_four` | optional, exactly 4 digits; only when `method = card` |
| `card_brand` | optional, max 30 |
| Order status | must not be `cancelled` or `completed` |
| Drawer | for `cash`, an open `cash_drawer_session` is required when `payments.require_shift` is on |

**Response** `201`

```json
{
  "data": {
    "id": 5510,
    "payment_number": "P-B1-20260905-0071",
    "order_id": 4213,
    "method": "cash",
    "amount":          { "amount": "14.44", "minor": 1444 },
    "tendered_amount": { "amount": "20.00", "minor": 2000 },
    "change_amount":   { "amount": "5.56",  "minor": 556 },
    "status": "captured",
    "received_by": { "id": 7, "name": "Ana Cruz" },
    "paid_at": "2026-09-05T14:40:00+07:00",
    "order": {
      "id": 4213,
      "grand_total": { "amount": "14.44", "minor": 1444 },
      "paid_total":  { "amount": "14.44", "minor": 1444 },
      "balance_due": { "amount": "0.00",  "minor": 0 },
      "payment_status": "paid"
    }
  }
}
```

The updated order totals are embedded so the POS needs no follow-up request.

**Errors**

| Code | `error_code` |
|---|---|
| `400` | `idempotency_key_required` |
| `401` | `unauthenticated` |
| `403` | `forbidden` · `shift_not_open` |
| `404` | `not_found` |
| `409` | `concurrency_conflict` |
| `410` | `idempotency_key_conflict` |
| `422` | `validation_failed` · `overpayment` · `order_cancelled` · `order_already_paid` · `reference_required` · `tendered_less_than_amount` |
| `429` | `too_many_requests` |
| `500` | `server_error` |

**Split payment example** — a 14.44 total settled as 10.00 card + 4.44 cash:

```text
POST /orders/4213/payments  {"method":"card","amount":"10.00","reference":"APPROVAL-1"}
  -> 201, paid_total 10.00, payment_status "partially_paid", balance_due 4.44

POST /orders/4213/payments  {"method":"cash","amount":"4.44","tendered_amount":"5.00"}
  -> 201, paid_total 14.44, payment_status "paid", balance_due 0.00, change 0.56
```

A third payment then returns `422 order_already_paid`.

### 5.2 Refund

```text
POST /api/v1/payments/{paymentId}/refunds
```

**Purpose:** Return money after capture. **Authentication:** Bearer. **Permission:** `payments.refund`
**Idempotency:** required.

**Request**

```json
{
  "amount": "4.44",
  "method": "cash",
  "reason": "Item returned - wrong order",
  "restock_inventory": false
}
```

**Validation**

- `amount`: required, `> 0`, ≤ (`payment.amount − payment.refunded_total`)
- `method`: required; may differ from the original
- `reason`: required, 3–255
- `restock_inventory`: boolean, default `false`
- Order must be `completed` or `cancelled`
- Within `refund.max_days_after_completion` days ([04](04-user-roles-permissions.md) §7)

**Side effects:** refund row; `payment.refunded_total` and `payment.status` updated;
`order.refunded_total` and `payment_status` recomputed; loyalty points reversed proportionally
(FR-CUS-006); stock restored only when `restock_inventory = true`; audit row.

**Response** `201` with the refund and the recomputed order.

**Errors:** `401` · `403 forbidden` · `403 refund_window_expired` · `404` · `410` ·
`422 refund_exceeds_payment` · `422 order_not_refundable` · `500`

### 5.3 Other payment endpoints

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `GET` | `/orders/{id}/payments` | Payments on an order | `payments.view` |
| `POST` | `/payments/{id}/void` | Void an unsettled payment | `payments.void` |
| `GET` | `/payments` | List with filters | `payments.view` |
| `POST` | `/shifts/open` | Open a drawer session | `payments.manage_shift` |
| `POST` | `/shifts/{id}/close` | Close and reconcile | `payments.manage_shift` |
| `GET` | `/shifts/{id}` | Session detail with variance | `payments.manage_shift` |
| `GET` | `/shifts/{id}/report` | Shift report | `payments.manage_shift` |

---

## 6. Kitchen

### 6.1 Poll tickets

The highest-frequency endpoint in the system — once per 5 s per screen.

```text
GET /api/v1/kitchen/tickets
```

**Purpose:** Return active tickets for a branch or station.
**Authentication:** Bearer. **Permission:** `kitchen.view_tickets`

**Query parameters:** `branch_id` (in scope), `station_id`, `status` (default `queued,preparing`),
`since` (ISO 8601 — returns only tickets changed after this instant).

**Response** `200`

```json
{
  "data": [
    {
      "id": 771,
      "ticket_number": "T-042-BAR",
      "order_number": "B1-20260905-0042",
      "order_type": "dine_in",
      "table_number": "12",
      "station": { "id": 2, "code": "BAR", "name": "Bar" },
      "status": "queued",
      "priority": 0,
      "queued_at": "2026-09-05T14:32:10+07:00",
      "elapsed_seconds": 184,
      "sla_minutes": 10,
      "is_late": false,
      "items": [
        { "id": 8801, "order_item_id": 9901, "name": "Cappuccino (Large)", "quantity": "2.000", "note": "extra hot", "status": "queued" }
      ],
      "version": 0
    }
  ],
  "meta": { "server_time": "2026-09-05T14:35:14+07:00", "poll_interval_seconds": 5 }
}
```

`server_time` lets the KDS compute elapsed time without trusting the device clock — kitchen tablets are
frequently wrong. **No prices, no totals, no customer contact details** are present
([04](04-user-roles-permissions.md) G13).

**Errors:** `401` · `403 forbidden` · `404` · `422 validation_failed` · `429` · `500`

### 6.2 Ticket transitions

| Method | Path | Transition | Permission |
|---|---|---|---|
| `POST` | `/kitchen/tickets/{id}/start` | Queued → Preparing | `kitchen.update_ticket` |
| `POST` | `/kitchen/tickets/{id}/ready` | Preparing → Ready | `kitchen.update_ticket` |
| `POST` | `/kitchen/tickets/{id}/recall` | Ready → Preparing | `kitchen.recall_ticket` |
| `POST` | `/kitchen/tickets/{id}/items/{itemId}/ready` | Mark one line prepared | `kitchen.update_ticket` |
| `PATCH` | `/kitchen/tickets/{id}/priority` | Bump priority | `kitchen.update_ticket` |

Each takes `{ "version": n }` and returns the updated ticket plus, when the parent order's status
changed as a result, an `order` object. Errors mirror §4.2, adding `422 skip_preparing_forbidden`.

### 6.3 Station management

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `GET` | `/kitchen/stations` | List stations for a branch | `kitchen.view_tickets` |
| `POST` | `/kitchen/stations` | Create | `kitchen.manage_stations` |
| `PATCH` | `/kitchen/stations/{id}` | Update name, SLA | `kitchen.manage_stations` |
| `DELETE` | `/kitchen/stations/{id}` | Deactivate | `kitchen.manage_stations` |

---

## 7. Inventory

### 7.1 Adjust stock

```text
POST /api/v1/inventory/adjustments
```

**Purpose:** Correct a stock balance. The most sensitive inventory operation — it changes stock without
a corresponding business event.
**Authentication:** Bearer. **Permission:** `inventory.adjust`

**Request**

```json
{
  "branch_id": 1,
  "ingredient_id": 4,
  "adjustment_type": "set",
  "quantity": "10.0000",
  "unit_id": 3,
  "reason": "Physical count correction after weekly stocktake",
  "occurred_at": "2026-09-05T09:00:00+07:00"
}
```

**Validation**

| Field | Rule |
|---|---|
| `branch_id` | required, exists, in scope |
| `ingredient_id` | required, exists, active |
| `adjustment_type` | required, in `set,increase,decrease` |
| `quantity` | required, decimal, `>= 0` for `set`, `> 0` otherwise, max 4 decimals |
| `unit_id` | required, exists, **same family** as the ingredient's stock unit |
| `reason` | required, 10–255 characters (FR-INV-008) |
| `occurred_at` | optional, not in the future, not older than `inventory.max_backdate_days` |
| Percentage guard | if the change exceeds `inventory.adjust.max_percent_without_admin`, requires Admin |

**Response** `201`

```json
{
  "data": {
    "stock_transaction": {
      "id": 998112,
      "type": "adjustment",
      "quantity_change": "-1.6000",
      "unit": "l",
      "balance_after": "10.0000",
      "average_cost_after": "1.2500",
      "reason": "Physical count correction after weekly stocktake",
      "performed_by": { "id": 3, "name": "M. Silva" },
      "occurred_at": "2026-09-05T09:00:00+07:00"
    },
    "inventory": {
      "branch_id": 1, "ingredient_id": 4,
      "quantity_on_hand": "10.0000",
      "average_cost": "1.2500",
      "value": { "amount": "12.50", "minor": 1250 }
    }
  }
}
```

**Errors:** `401` · `403 forbidden` · `403 adjustment_exceeds_limit` · `404` ·
`422 validation_failed` · `422 unit_family_mismatch` · `422 negative_stock_forbidden` ·
`422 backdate_too_old` · `500`

### 7.2 Inventory endpoints

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `GET` | `/inventory` | Stock levels for a branch. Filters: `low_stock`, `ingredient_id`, `category` | `inventory.view` |
| `GET` | `/inventory/{ingredientId}` | Level, average cost, recent movements | `inventory.view` |
| `POST` | `/inventory/stock-in` | Record receipt of stock; recalculates average cost | `inventory.stock_in` |
| `POST` | `/inventory/stock-out` | Record wastage or staff usage | `inventory.stock_out` |
| `POST` | `/inventory/adjustments` | §7.1 | `inventory.adjust` |
| `POST` | `/inventory/counts` | Submit a physical count; produces adjustments | `inventory.count` |
| `GET` | `/inventory/transactions` | Ledger. Cursor-paginated | `inventory.view_transactions` |
| `GET` | `/inventory/valuation` | Σ quantity × average cost | `inventory.view_valuation` |
| `GET` | `/ingredients` `POST` `PATCH` `DELETE` | Ingredient CRUD | `ingredients.*` |
| `GET` | `/units` | Unit list with conversion factors | Authenticated |
| `GET` | `/products/{id}/recipe` | Active recipe | `recipes.view` |
| `POST` | `/products/{id}/recipe` | Create a **new version** | `recipes.create` |
| `POST` | `/recipes/{id}/cost` | Compute cost from current ingredient costs | `recipes.view` + `ingredients.view_cost` |

---

## 8. Stock transfers

### 8.1 Create a transfer request

```text
POST /api/v1/stock-transfers
```

**Purpose:** Request ingredients from another branch.
**Authentication:** Bearer. **Permission:** `transfers.create`

**Request**

```json
{
  "from_branch_id": 2,
  "to_branch_id": 1,
  "expected_arrival_at": "2026-09-06T09:00:00+07:00",
  "note": "Weekend cover",
  "items": [
    { "ingredient_id": 4, "requested_quantity": "20.0000", "unit_id": 3 },
    { "ingredient_id": 9, "requested_quantity": "5.0000",  "unit_id": 1 }
  ],
  "submit": true
}
```

**Validation**

- `from_branch_id`, `to_branch_id`: required, exist, **must differ** (FR-TRF-001)
- `to_branch_id`: must be in the requester's scope
- `items`: required, min 1; `ingredient_id` unique within the request
- `items.*.requested_quantity`: `> 0`
- `items.*.unit_id`: same family as the ingredient's stock unit
- `submit`: if `true`, status becomes `pending`, otherwise `draft`

**Response** `201`

```json
{
  "data": {
    "id": 312,
    "transfer_number": "TRF-20260905-0007",
    "from_branch": { "id": 2, "code": "B2", "name": "Uptown" },
    "to_branch":   { "id": 1, "code": "B1", "name": "Riverside" },
    "status": "pending",
    "requested_by": { "id": 3, "name": "M. Silva" },
    "items": [
      { "id": 981, "ingredient": { "id": 4, "name": "Milk" }, "requested_quantity": "20.0000", "unit": "l",
        "source_available": "45.5000", "is_fulfillable": true }
    ],
    "requested_at": "2026-09-05T15:02:00+07:00"
  }
}
```

`source_available` is shown so the requester knows immediately whether the source can fulfil.

**Errors:** `401` · `403 forbidden` · `404` · `422 validation_failed` · `422 same_branch_transfer` ·
`422 duplicate_ingredient` · `422 unit_family_mismatch` · `500`

### 8.2 Transfer lifecycle

| Method | Path | Transition | Permission | Notes |
|---|---|---|---|---|
| `POST` | `/stock-transfers/{id}/submit` | Draft → Pending | `transfers.submit` | |
| `POST` | `/stock-transfers/{id}/approve` | Pending → Approved | `transfers.approve` | Authority at **source**; may reduce quantities |
| `POST` | `/stock-transfers/{id}/reject` | Pending → Rejected | `transfers.approve` | `reason` required |
| `POST` | `/stock-transfers/{id}/dispatch` | Approved → In Transit | `transfers.dispatch` | Deducts source stock (`transfer_out`) |
| `POST` | `/stock-transfers/{id}/receive` | In Transit → Received | `transfers.receive` | Adds destination stock (`transfer_in`); records variance |
| `POST` | `/stock-transfers/{id}/cancel` | any pre-dispatch → Cancelled | `transfers.cancel` | |

**Receive request**

```json
{
  "items": [
    { "id": 981, "received_quantity": "19.5000", "variance_reason": "Spillage in transit" }
  ],
  "version": 4
}
```

A variance writes an extra adjustment at the destination and sets `has_variance` (FR-TRF-005).

**Errors add:** `403 not_source_branch_authority` · `422 insufficient_source_stock` ·
`422 variance_reason_required` · `422 invalid_state_transition`

---

## 9. Customers and loyalty

### 9.1 Redeem points

```text
POST /api/v1/orders/{orderId}/loyalty/redeem
```

**Purpose:** Apply loyalty points as a discount to an open order.
**Authentication:** Bearer. **Permission:** `loyalty.redeem`

**Request**

```json
{ "points": 200, "version": 0 }
```

**Validation**

| Rule | Detail |
|---|---|
| `points` | required, integer `> 0` |
| Balance | ≤ `customer.loyalty_points_balance` |
| Minimum | ≥ `loyalty_rules.min_points_to_redeem` |
| Increment | multiple of `loyalty_rules.redeem_increment` |
| Cap | resulting discount ≤ `max_redeem_percent_of_subtotal` × subtotal |
| Order | must have a customer attached, status not `completed` or `cancelled`, `paid_total = 0` |

**Response** `200`

```json
{
  "data": {
    "order": {
      "id": 4213,
      "loyalty_points_redeemed": 200,
      "loyalty_discount_amount": { "amount": "2.00", "minor": 200 },
      "grand_total":             { "amount": "12.44", "minor": 1244 },
      "balance_due":             { "amount": "12.44", "minor": 1244 },
      "version": 1
    },
    "loyalty_transaction": {
      "id": 77120, "type": "redeem", "points": -200, "balance_after": 40,
      "monetary_value": { "amount": "2.00", "minor": 200 }
    }
  }
}
```

**Errors:** `401` · `403 forbidden` · `404` · `409 version_mismatch` ·
`422 insufficient_points` · `422 below_minimum_redemption` · `422 invalid_redeem_increment` ·
`422 redemption_cap_exceeded` · `422 no_customer_attached` · `422 order_already_paid` · `500`

### 9.2 Customer and loyalty endpoints

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `GET` | `/customers` | List and search | `customers.view` |
| `POST` | `/customers` | Create | `customers.create` |
| `GET` | `/customers/{id}` | Detail with balance and tier | `customers.view` |
| `PATCH` | `/customers/{id}` | Update | `customers.update` |
| `DELETE` | `/customers/{id}` | Soft delete | `customers.delete` |
| `GET` | `/customers/{id}/orders` | Order history | `customers.view` + `orders.view` |
| `GET` | `/customers/{id}/loyalty` | Point ledger | `loyalty.view` |
| `POST` | `/customers/{id}/loyalty/adjust` | Manual credit or debit; `reason` required | `loyalty.adjust` |
| `POST` | `/customers/{id}/export` | Personal-data export | `customers.export` |
| `POST` | `/customers/{id}/anonymise` | Erasure preserving financial totals | `customers.anonymise` |
| `GET` | `/loyalty/tiers` `POST` `PATCH` | Tier management | `loyalty.manage_rules` |
| `GET` | `/loyalty/rules` `PATCH` | Earn and redeem rates | `loyalty.manage_rules` |

---

## 10. Expenses

### 10.1 Create an expense

```text
POST /api/v1/expenses
```

**Purpose:** Record a branch cost. **Authentication:** Bearer. **Permission:** `expenses.create`

**Request** — `multipart/form-data` when a receipt is attached, otherwise JSON:

```json
{
  "branch_id": 1,
  "expense_category_id": 4,
  "amount": "1250.00",
  "tax_amount": "87.50",
  "expense_date": "2026-09-04",
  "vendor_name": "Riverside Dairy Co.",
  "payment_method": "bank_transfer",
  "description": "Weekly milk delivery",
  "submit": true
}
```

**Validation**

| Field | Rule |
|---|---|
| `branch_id` | required, exists, in scope |
| `expense_category_id` | required, exists, active |
| `amount` | required, decimal `> 0`, ≤ `expense.max_amount` |
| `tax_amount` | optional, `>= 0`, ≤ `amount` |
| `expense_date` | required, date, not more than `expense.max_future_days` ahead, not older than `expense.max_backdate_days` |
| `vendor_name` | optional, max 150 |
| `description` | required, max 500 |
| `receipt` | optional file; `pdf,jpg,jpeg,png`; ≤ 5 MB; **MIME verified from content**, not the extension ([22](22-security.md) §9) |
| `submit` | boolean; `true` ⇒ `pending`, else `draft` |

**Response** `201`

```json
{
  "data": {
    "id": 4402,
    "expense_number": "EXP-B1-20260905-0012",
    "status": "pending",
    "amount":       { "amount": "1250.00", "minor": 125000 },
    "tax_amount":   { "amount": "87.50",   "minor": 8750 },
    "total_amount": { "amount": "1337.50", "minor": 133750 },
    "expense_date": "2026-09-04",
    "category": { "id": 4, "name": "Food Supplies", "is_cogs_related": true },
    "created_by": { "id": 3, "name": "M. Silva" },
    "receipt_url": null,
    "requires_approval": true
  }
}
```

**Errors:** `401` · `403 forbidden` · `404` · `422 validation_failed` · `422 amount_exceeds_limit` ·
`422 expense_date_out_of_range` · `422 invalid_file_type` · `413 file_too_large` · `500`

### 10.2 Expense endpoints

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `GET` | `/expenses` | List with filters | `expenses.view` |
| `GET` | `/expenses/{id}` | Detail | `expenses.view` |
| `PATCH` | `/expenses/{id}` | Edit a Draft | `expenses.update` |
| `DELETE` | `/expenses/{id}` | Delete a Draft | `expenses.delete` |
| `POST` | `/expenses/{id}/submit` | Draft → Pending | `expenses.submit` |
| `POST` | `/expenses/{id}/approve` | Pending → Approved | `expenses.approve` |
| `POST` | `/expenses/{id}/reject` | Pending → Rejected; `reason` required | `expenses.approve` |
| `POST` | `/expenses/{id}/mark-paid` | Approved → Paid | `expenses.mark_paid` |
| `GET` | `/expenses/{id}/receipt` | Signed, time-limited receipt URL | `expenses.view` |
| `GET` | `/expense-categories` `POST` `PATCH` | Category management | `expenses.manage_categories` |

`approve` returns `403 self_approval_forbidden` when the approver created the expense (FR-EXP-004).

---

## 11. Reports

### 11.1 Daily sales

```text
GET /api/v1/reports/sales
```

**Purpose:** Sales summary for a branch and date range, reconciling to payments.
**Authentication:** Bearer. **Permission:** `reports.view_sales`

**Query parameters:** `branch_id` (or `all` with `reports.view_all_branches`), `date_from`, `date_to`,
`group_by` (`day|week|month|hour`), `order_type`, `payment_method`.

**Response** `200`

```json
{
  "data": {
    "period": { "from": "2026-09-01", "to": "2026-09-05", "basis": "business_date", "timezone": "Asia/Bangkok" },
    "branch": { "id": 1, "code": "B1", "name": "Riverside" },
    "totals": {
      "order_count": 412,
      "item_count": 1187,
      "gross_sales":     { "amount": "18450.00", "minor": 1845000 },
      "discount_total":  { "amount": "920.50",   "minor": 92050 },
      "loyalty_discount":{ "amount": "310.00",   "minor": 31000 },
      "net_sales":       { "amount": "17219.50", "minor": 1721950 },
      "tax_total":       { "amount": "1205.37",  "minor": 120537 },
      "service_charge":  { "amount": "0.00",     "minor": 0 },
      "grand_total":     { "amount": "18424.87", "minor": 1842487 },
      "refund_total":    { "amount": "145.00",   "minor": 14500 },
      "average_order_value": { "amount": "44.72", "minor": 4472 }
    },
    "by_payment_method": [
      { "method": "cash",          "count": 210, "amount": { "amount": "8100.00", "minor": 810000 } },
      { "method": "card",          "count": 160, "amount": { "amount": "8900.87", "minor": 890087 } },
      { "method": "qr",            "count": 40,  "amount": { "amount": "1200.00", "minor": 120000 } },
      { "method": "bank_transfer", "count": 2,   "amount": { "amount": "224.00",  "minor": 22400 } }
    ],
    "by_period": [
      { "date": "2026-09-01", "order_count": 78, "grand_total": { "amount": "3410.00", "minor": 341000 } }
    ]
  },
  "meta": { "generated_at": "2026-09-05T18:00:00+07:00", "cached": false }
}
```

**Errors:** `401` · `403 forbidden` · `403 branch_out_of_scope` · `422 validation_failed` ·
`422 date_range_too_large` · `429 report_rate_limited` · `500`

`date_range_too_large` fires above `reports.max_range_days` (default 366) to protect the database.

### 11.2 Report endpoints

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `GET` | `/reports/sales` | §11.1 | `reports.view_sales` |
| `GET` | `/reports/products` | Quantity, revenue, COGS, margin per product | `reports.view_products` |
| `GET` | `/reports/profit` | Revenue − COGS − Expenses | `reports.view_profit` |
| `GET` | `/reports/inventory-valuation` | Stock value per branch | `reports.view_inventory` |
| `GET` | `/reports/stock-movement` | Opening, in, out, closing per ingredient | `reports.view_inventory` |
| `GET` | `/reports/expenses` | Expenses by category and period | `reports.view_expenses` |
| `GET` | `/reports/staff` | Per-cashier orders, tenders, discounts, voids | `reports.view_staff` |
| `GET` | `/reports/kitchen-performance` | Ticket times against SLA | `reports.view_staff` |
| `POST` | `/reports/{report}/export` | Queue a CSV export; returns `202` and a job ID | `reports.export` |
| `GET` | `/reports/exports/{jobId}` | Poll status; returns a signed URL when ready | `reports.export` |

Definitions and formulas: [18-reporting.md](18-reporting.md).

---

## 12. Administration

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `GET` `POST` `PATCH` `DELETE` | `/admin/users` | User CRUD | `users.*` |
| `POST` | `/admin/users/{id}/roles` | Assign roles | `users.assign_role` |
| `POST` | `/admin/users/{id}/activate` | Activate or deactivate | `users.activate` |
| `GET` | `/admin/roles` `POST` `PATCH` `DELETE` | Role management | `roles.*` |
| `GET` | `/admin/permissions` | Permission catalogue, grouped | `permissions.view` |
| `GET` `POST` `PATCH` `DELETE` | `/admin/branches` | Branch CRUD | `branches.*` |
| `GET` `PATCH` | `/admin/settings` | Settings, global and per branch | `system.manage_settings` / `branches.manage_settings` |
| `GET` `POST` `PATCH` | `/admin/tax-rates` | Tax rate management | `system.manage_settings` |
| `GET` | `/admin/audit-logs` | Audit search | `audit.view` |
| `GET` | `/admin/health` | Health and queue status | `system.view_health` |

### 12.1 Catalogue management

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `GET` `POST` `PATCH` `DELETE` | `/categories` | Category CRUD; `?tree=1` for nesting | `categories.*` |
| `GET` `POST` `PATCH` `DELETE` | `/products` | Product CRUD | `products.*` |
| `POST` `PATCH` `DELETE` | `/products/{id}/variants` | Variant management | `products.manage_variants` |
| `PATCH` | `/products/{id}/branches/{branchId}` | Branch price and availability | `products.manage_pricing` |
| `POST` | `/products/{id}/availability` | Toggle "86'd" | `products.manage_availability` |

### 12.2 Notifications

| Method | Path | Purpose | Permission |
|---|---|---|---|
| `GET` | `/notifications` | List; `?unread=1` | `notifications.view` |
| `POST` | `/notifications/{id}/read` | Mark one read | `notifications.view` |
| `POST` | `/notifications/read-all` | Mark all read | `notifications.view` |
| `GET` | `/notifications/unread-count` | Badge count | `notifications.view` |
| `GET` `PATCH` | `/notifications/preferences` | Own preferences | `notifications.manage_preferences` |

---

## 13. Endpoint index

Roughly 130 endpoints. Permissions as defined in [04](04-user-roles-permissions.md) §3.

| Namespace | Endpoints | Primary permission group |
|---|---|---|
| `/auth/*` | 10 | none / authenticated |
| `/pos/*` | 5 | `pos.*` |
| `/orders/*` | 14 | `orders.*` |
| `/payments/*`, `/shifts/*` | 11 | `payments.*` |
| `/kitchen/*` | 9 | `kitchen.*` |
| `/inventory/*`, `/ingredients/*`, `/units`, `/recipes/*` | 18 | `inventory.*`, `ingredients.*`, `recipes.*` |
| `/stock-transfers/*` | 9 | `transfers.*` |
| `/customers/*`, `/loyalty/*` | 14 | `customers.*`, `loyalty.*` |
| `/expenses/*`, `/expense-categories/*` | 12 | `expenses.*` |
| `/reports/*` | 10 | `reports.*` |
| `/categories/*`, `/products/*` | 13 | `categories.*`, `products.*` |
| `/admin/*` | 17 | `users.*`, `roles.*`, `branches.*`, `system.*`, `audit.*` |
| `/notifications/*` | 5 | `notifications.*` |

---

## 14. Security considerations

| # | Consideration | Mitigation |
|---|---|---|
| S1 | Client-supplied prices | Never trusted. All prices and totals are recomputed server-side from the database. |
| S2 | Client-supplied `branch_id` | Validated against branch scope; the resolved branch always wins ([04](04-user-roles-permissions.md) B3). |
| S3 | IDOR | Out-of-scope records return `404`, never `403` — a `403` would confirm the record exists. |
| S4 | Mass assignment | Explicit allow-lists per FormRequest. `status`, `payment_status`, `paid_total`, `user_id` are never accepted from a client. |
| S5 | Duplicate financial writes | `Idempotency-Key` required on order, payment and refund creation. |
| S6 | Enumeration via login | Identical response and timing for unknown email and wrong password. |
| S7 | Over-fetching | `include` is whitelisted; no arbitrary relation loading. |
| S8 | Report-driven denial of service | Rate-limited bucket, range cap, cached results, exports queued asynchronously. |
| S9 | Sensitive fields in responses | `cost_price`, `unit_cost_snapshot`, `margin` **omitted** — not nulled — without `products.view_cost`. |
| S10 | File upload | Content-sniffed MIME, size cap, stored outside the web root, served via short-lived signed URLs. |
| S11 | Token leakage in logs | `Authorization` headers and `token` fields are redacted by the log formatter. |
| S12 | CORS | Explicit origin allow-list per environment; no wildcard with credentials. |

---

## 15. Testing considerations

| Area | Test |
|---|---|
| Contract | Generated OpenAPI 3.1 spec validated in CI; responses validated against it (NFR-MNT-004). |
| Envelope | Every endpoint returns the §1.3 shape, including errors. |
| Status codes | One test per row of §1.6 per endpoint family. |
| Idempotency | Same key + same payload ⇒ one record, identical response, `Idempotency-Replayed: true`. Same key + different payload ⇒ `410`. |
| Money format | No JSON floats anywhere in any response. Automated scan of all fixtures. |
| Permission enforcement | Route-walking test: every non-public route returns `403` for a token lacking its permission. |
| Branch scope | Every branch-owned endpoint returns `404` for an out-of-scope record. |
| Validation | Every rule in [20](20-validation-rules.md) has a negative test. |
| Pagination | Cursor stability across inserts; `per_page` cap enforced. |
| Rate limits | Each bucket in §1.7 returns `429` with `Retry-After` at the limit. |
| Concurrency | Two simultaneous accepts on one order ⇒ one `200`, one `409`. |
| N+1 | Query-count assertions on every list endpoint (NFR-PERF-006). |

---

## 16. Related documents

[04-user-roles-permissions.md](04-user-roles-permissions.md) ·
[08-authentication-authorization.md](08-authentication-authorization.md) ·
[09-business-rules.md](09-business-rules.md) ·
[20-validation-rules.md](20-validation-rules.md) ·
[21-error-handling.md](21-error-handling.md) ·
[24-qa-test-plan.md](24-qa-test-plan.md)
