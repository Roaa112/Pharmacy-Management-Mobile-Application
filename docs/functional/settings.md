# Store settings

Code: `app/Modules/Setting` (`SettingController`, `ApiSettingController`, `Requests/UpdateSettingRequest`),
`App\Models\Setting`. Dashboard: **Operations › Settings** (`settings.view` / `settings.update`);
loyalty rules on **Loyalty › Settings** (`loyalty.settings`, see [loyalty.md](../loyalty.md)).

## 1. Purpose

One row of store-wide settings: what the app shows (contact, legal texts) and the store policies the
backend enforces.

## 2. Fields

| Group | Field | Rule | Default | Effect |
|---|---|---|---|---|
| Contact | `facebook`, `instagram`, `tiktok`, `youtube`, `map_location_url` | URL ≤ 255 | | Shown in the app |
| | `phone_number` | ≤ 20 | | |
| Legal | `privacy_policy_ar/en`, `terms_conditions_ar/en` | ≤ 60 000 | | API returns `privacy_policy` / `terms_conditions` in the request language |
| Points (basic) | `spend_x`, `get_y`, `points_validity_days` | numeric ≥ 0; integer; 0–3650 | seeder: 100 / 5 / 30 | `get_y` points per `spend_x` spent; validity |
| Stock | `low_stock_threshold` | 0–100 000 | `PHARMACY_LOW_STOCK_THRESHOLD` (5) | Low-stock lists and badges |
| Orders | `return_window_days` | 1–365 or empty | empty = no limit | Only completed orders within N days of completion can be returned |
| | `customer_can_cancel_pending` | bool | off | Customers may cancel their own pending orders (D4) |
| Coupons | `coupon_applies_to_delivery` | bool | on | Coupons discount delivery unless the coupon says otherwise (D9) |
| | `coupon_release_on_cancel` | bool | on | A canceled order gives its coupon use back (D5) |
| Health | `vaccine_reminders_enabled` | bool | on | Daily vaccine reminders |
| | `medication_reminders_enabled` | bool | off | Medication dose reminders |
| Loyalty | `loyalty_*`, `points_never_expire` | see loyalty.md | | Earning, activation, expiry, redemption |
| Legacy | `keycard` | — | | Retired Qi Card setting (kept); `zaincash` removed (API returns `null`) |

## 3. Behaviour

* Only the fields sent are written, so saving one section never clears another.
* Every save is audited with before/after values (`settings.update`).
* `Setting::current()` reads the oldest row and creates it when missing; `SettingSeeder` creates it
  with default texts only when no row exists.
* Public read: `GET /api/v1/user/settings`.
