# Loyalty points and rewards

Code: `app/Modules/Points` (services `LoyaltyLedger`, `LoyaltyService`, `RewardRedemptionService`,
`LoyaltySettings`), models `LoyaltyLedgerEntry`, `LoyaltyAllocation`, `LoyaltyReward`,
`LoyaltyRedemption`, `LoyaltyPointReversal`. Dashboard: **Sales › Loyalty** and **Sales › Rewards**.

## The ledger is the source of truth

There is no balance column. Every movement is a row of `loyalty_ledger`:

| type | sign | meaning |
|---|---|---|
| `earned` | + | points of an order (a **lot**) |
| `bonus` | + | points given by staff (a lot) |
| `adjusted` | ± | manual correction: + is a lot, − is taken from lots |
| `redeemed` | − | spent: a reward, part of an order's payment, or "Redeem" on the points-per-order page |
| `expired` | − | what was left of a lot when it lapsed |
| `reversed` | − | the earned points of a canceled order |
| `refunded` | − | points taken back by a refund (decision D2) |
| `cancelled` | + | points given back: a cancelled/expired redemption, a canceled order paid with points |

A **lot** (credit entry) has `remaining_points`, a `status` (`pending` → `active` → `depleted` /
`expired`; `void` when its order was canceled before the points became usable), `earned_at` and
`expires_at`. Every debit records which lots it took from in `loyalty_ledger_allocations` (and a
`cancelled` entry records which lots it gave back to). Therefore, for every customer,
**Σ entry points = Σ lot remaining points**, and the ledger answers: what was earned and why, from
which order, what is still valid, what expired, what was redeemed or reversed, who adjusted it and
when it expires.

Entries are never deleted, and an entry's type, points, customer, order and reference never change
(model guard). Only a lot's bookkeeping (remaining points, status, dates) moves, through
`LoyaltyLedger` only.

**Valid points** = Σ `remaining_points` of lots that are `active` and not past `expires_at`.

## Settings (Loyalty › Settings, permission `loyalty.settings`)

Stored on the settings row (`settings.loyalty_*`, `spend_x`, `get_y`, `points_validity_days`,
`points_never_expire`); read through `LoyaltySettings`. Defaults reproduce the behaviour before the
programme was configurable.

| Setting | Default |
|---|---|
| Programme on | on |
| `get_y` points per `spend_x` spent | as configured before |
| Earned on | products subtotal (or: final amount paid) |
| Delivery fee earns points | no |
| Discounts reduce points (subtotal basis) | no |
| COD orders earn points | yes |
| Minimum order / maximum points per order | 0 / no limit |
| Points become usable | on completion (the old `PHARMACY_POINTS_REDEEM_REQUIRES_COMPLETION`); or immediately, or once paid |
| Valid for | `points_validity_days` (presets 30/60/90/180/365) or never |
| Point value at checkout | 0 = points cannot pay at checkout |
| Min points per redemption / max per order | 0 / no limit |
| Combine with other discounts | yes |
| Customer chooses how many points (partial) | yes; off = an order uses all the points it can |
| Several redemptions open at once | yes; off = one open reward redemption or points-paid order in progress at a time |
| Rewards must be collected within | 30 days |

## Life of an order's points

1. **Checkout** computes `orders.points_earned` and writes an `earned` lot (`earned:order:{id}`),
   `pending` unless points are usable immediately.
2. **Activation** (`OrderWorkflow` on completion, `PaymentService::projectOrder` when paid, and the
   daily run as a safety net) makes the lot `active`; its validity starts then.
3. **Expiry**: `loyalty:record-expirations` (daily 02:00) writes `expired` entries for lots past
   `expires_at` (reading only due lots through the `status, expires_at` index), activates due lots,
   and expires uncollected reward redemptions. Redemption never depends on it: a lot past its
   expiry is not usable the moment it lapses.
4. **Cancellation** (`OrderWorkflow`): points the order paid with come back (`cancelled`), and its
   earned points are reversed (`reversed:order:{id}`).
5. **Refund** (accepted return, `ReturnDecisionService`): decision D2 computes the points to take back
   (`loyalty_point_reversals`, one row per return); the ledger takes them (`refunded:loyalty_point_reversal:{id}`)
   from the order's own lot first, then from the customer's other valid points, oldest first.
   **A balance never goes negative**: what cannot be taken (points already spent) is recorded as
   `metadata.shortfall` and shown as *owed* on the customer page. Points the customer paid part of
   the order with come back in the same proportion as the money refunded.

Every step is keyed by its event (`idempotency_key`, unique), so repeating it changes nothing.

## Redemption

* **FIFO**: points are taken from the lots that expire soonest (never-expiring last), e.g. lots of
  100 (Nov 10), 50 (Nov 30), 200 (Dec 20) and a redemption of 120 leave 0 / 30 / 200.
* **At checkout** (`redeem_points` on `POST /api/user/orders`): checked under the customer lock;
  points pay only for products, never delivery; an online payment must keep an amount to pay. The
  order stores `points_redeemed` / `points_discount_amount`, `total_price` is what remains to pay and
  the payment is created for that amount. A refused redemption answers 422 `points_rejected`.
* **Rewards** (`RewardRedemptionService::redeem`): one transaction under the customer's row lock,
  then the reward's row lock: checks (active, dates, stock, total and per-customer limits, minimum,
  one-at-a-time rule, valid points) → reward stock → points (FIFO) → redemption `RDM-000123` →
  ledger → audit log. Concurrent redemptions wait for each other; the same `Idempotency-Key`
  returns the first redemption.
* **Workflow**: `pending` → `approved` → `fulfilled` (staff hand it over; a product reward leaves the
  default warehouse as a recorded stock adjustment). `pending`/`approved` → `cancelled` (staff, with a
  reason) or `expired` (not collected in time): stock and points go back.

## Manual adjustments (`loyalty.adjust_points`)

Customer page › Adjust points: amount (±), reason (required), optional validity for added points.
Added points are a new lot; removed points come from the oldest valid lots and never more than the
customer has. Each adjustment is one entry with the admin, plus an audit log row with the balance
before and after. A double-submitted form is recorded once (`request_key`).

## Customer API (Sanctum, own data only)

| Endpoint | |
|---|---|
| `GET /api/user/loyalty` | balance, pending, expiring soon, lifetime totals, the rules |
| `GET /api/user/loyalty/history?type=&from=&to=&per_page=` | paginated ledger |
| `GET /api/user/loyalty/expiring?days=` | valid lots expiring soon |
| `GET /api/user/loyalty/rewards`, `/rewards/{id}` | rewards available now (with `affordable`) |
| `POST /api/user/loyalty/rewards/{id}/redeem` | `quantity`, header `Idempotency-Key`; 201, or 422 `redemption_refused` |
| `GET /api/user/loyalty/redemptions`, `/redemptions/{id}` | the customer's redemptions |
| `GET /api/user/points/valid`, `/expired`, `/notvalid` | unchanged shape, one row per order lot |

Admin actions are dashboard routes (session auth, permission per action); `POST
/dashboard/loyalty/customers/{id}/adjust` also answers JSON.

## Dashboard

Overview (active / pending / earned / redeemed / expired / reversed points, rewards, redemptions,
top balances), Customers (balances), Transactions (filters: customer, type, dates, order), Expiring
(upcoming and history), Rewards (CRUD, activate, stock, limits, dates), Redemptions (approve, fulfil,
cancel), Points per order (the original page) and Settings. The customer page shows the balances,
the full history with type/date filters, adjustments and redeeming a reward at the counter.

## Permissions

`loyalty.view`, `loyalty.manage` (redemptions, order "Redeem"), `loyalty.adjust_points`,
`loyalty.manage_rewards`, `loyalty.settings`, `loyalty_rewards.import/export`,
`loyalty_transactions.export`.

## Migration of existing data

`2026_11_12_000001_upgrade_loyalty_programme` renames the old types (earn → earned, redeem → redeemed,
expire → expired, reverse → reversed for cancellations / refunded for refunds) and turns each order's
earn into a lot holding exactly what its own reversals, redemption and expiry left; existing lots
keep their old expiry (order date + validity). Nothing is deleted.
