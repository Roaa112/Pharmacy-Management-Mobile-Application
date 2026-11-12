# Health services

Code: `app/Modules/HealthService` (`HealthServiceController`, `Services/HealthService`,
`HealthEvaluator`, `HealthRuleDefaults`, `HealthSettings`,
`HealthReminderService`, `VaccineScheduleService`, `DefaultHealthContent`, `Admin/*`),
`app/Modules/Medication`, `app/Models/HealthResultRule`, `app/Http/Middleware/EnsureHealthToolEnabled`,
`app/Jobs/SendHealthReminder`, `app/Notifications/HealthReminder`.
Dashboard: **Health content › Vaccination schedule / Pregnancy weeks / Result rules & messages /
Health settings** and the **Health data** pages.

## 1. Purpose

Free health tools in the customer app — trackers, calculators, a child vaccination calendar and
medication reminders — with reminders by push notification. **The medical content is
controlled from the dashboard**: when a reading falls into a category and the message for it (all on one page, Result rules &
messages), which tools are offered, and the reminder and calculator settings. All other texts (app
messages, pregnancy and ovulation wording, reminder texts) come from `lang/{ar,en}/health.php`.
Only the arithmetic (BMI formula, cycle and pregnancy dates) stays in code.

> **Not medical advice.** The defaults follow published guidelines (WHO BMI, ADA blood sugar,
> 2017 ACC/AHA blood pressure). Changing thresholds is a medical decision: it needs its own
> permission (`health_rules.manage`, Health Specialist by default), every change is audited, and each
> measurement can be reset to the guideline defaults.

## 2. Customer tools

| Tool | Input | Output / rules (defaults) |
|---|---|---|
| Body weight / BMI | height, weight, `metric|imperial` | BMI rounded to 0.1 and its **result rule**: < 18.5 underweight, < 25 normal, < 30 overweight, < 35 obese class 1, < 40 obese class 2, otherwise obese class 3; label, message (`description`), urgent flag; ideal weight from the healthy BMI range (Health settings, 18.5–24.9) |
| Blood sugar | value (mg/dL), condition, measured at | Result rules per reading condition: < 70 low (any condition); fasting / before a meal < 100 normal; after a meal < 140 normal; random < 200 normal; otherwise high; a condition the rules do not know → `unknown`. Returns label, `advice` (the rule's message) and `requires_urgent_care` |
| Blood pressure | systolic, diastolic, condition, measured at | Result rules (2017 ACC/AHA): ≥ 181 or ≥ 121 crisis (urgent + advice); ≥ 140 or ≥ 90 stage 2; ≥ 130 or ≥ 80 stage 1; < 90 or < 60 low; ≥ 120 elevated; else normal. A rule matches on systolic OR diastolic and the highest priority wins, i.e. the higher category of the two numbers. The condition does not change the result. |
| Ovulation calendar | cycle start (within the last year), cycle length, period length (accepted ranges: Health settings, default 21–35 and 3–10) | Day-by-day phases; ovulation = cycle length − luteal phase (default 14); pregnancy chance per phase (Health settings) |
| Pregnancy | last period date (within 2 years) | Due date (280 days), week/day, trimester, month, baby size/weight/length and tip from the pregnancy-week content |
| Children & vaccines | child name, gender, birth date (≤ 18 years) | A vaccine schedule generated from the active template; mark vaccines given/not given; delete a child |
| Histories | `page`, `per_page` | Blood pressure, blood sugar and weight histories (without paging: up to 500 rows, `truncated` flag) |
| Medication reminders | name, notes, image, quantity per dose, repeat (`every_day|specific_days|once`), times, days | Weekly view (store calendar, week starting Saturday) with each dose's status; mark a dose `taken|missed`; remove a day; edit; delete |

Every result also carries `guideline` (editable name) and `disclaimer` (editable, empty by default).
A tool switched off in Health settings answers new entries with `403 {code: health_tool_disabled,
tool}`; histories stay readable and deletable. The app can read the switches, disclaimer and input
limits from `GET /api/v1/user/health/config` (public). Measurement dates cannot be in the future
(end of today, store time). All records are the caller's only. Endpoints:
[api-reference.md §11](../technical/api-reference.md#11-health-services-token).

## 3. Dashboard control

### 3.1 Result rules & messages (`/dashboard/health-content/rules`)

One rule per result category of a measurement (`health_result_rules`):

| Field | Meaning |
|---|---|
| Code | What the app receives in `status_code` / `evaluation_code` (e.g. `overweight`, `high`, `stage_1`) |
| Reading condition | Blood sugar only: fasting / before a meal, after a meal, random, or any condition |
| Ranges | "from ≤ value < to", empty = open; blood pressure has a systolic and a diastolic range (matches on either). No range at all = catch-all |
| Priority | Highest matching rule wins (then order, then age) |
| Label ar/en | `status_label` / `evaluation_label` in the app language |
| Message ar/en | What the customer is told: BMI `description` / `description_label`, blood sugar and blood pressure `advice`. Empty = no message. **This is where an admin chooses, e.g., the message for "overweight".** |
| Arabic text for older apps | `status` / `evaluation` (exact wording older app versions compare against); empty = Arabic label |
| Requires urgent care | `requires_urgent_care: true` in the result |
| Active | Inactive rules are ignored |

Screen features: per-measurement tables, **Try a reading** (shows which rule and message a value
gets), warnings for value ranges no rule covers (reported as `unknown`), **Reset to guideline
defaults** per measurement, create / edit / delete. A measurement with no active rule at all is
classified with the guideline defaults. Viewing: `health_content.view`; changes:
`health_rules.manage`. Audited: `health_rules.create`, `.update` (before/after), `.delete`, `.reset`.
Changes apply to readings saved from now on; stored readings keep the result they were given.

### 3.2 Health settings (`/dashboard/health-content/settings`)

| Setting | Default | Effect |
|---|---|---|
| Tools on/off (BMI, blood sugar, blood pressure, ovulation, pregnancy, children & vaccinations, medication) | all on | Off = new entries refused (`health_tool_disabled`) |
| Disclaimer ar/en | empty | `disclaimer` in every result and in the config endpoint |
| Guideline names (5) | WHO / ADA / ACC-AHA / Wilcox / Naegele texts | `guideline` in results |
| Healthy BMI range | 18.5 – 24.9 | Ideal weight range |
| Cycle length range, period length range | 21–35, 3–10 days | Accepted ovulation input |
| Luteal phase | 14 days (10–16) | Ovulation day; the shortest cycle must be longer than it |
| Pregnancy chance per phase | 0, 0, 10, 15, 25, 30, 5, 0 % | `pregnancy_chance` |
| Vaccination reminders on/off | on | (same column the general Settings page used to show) |
| Vaccine reminder send time | 09:00 store time | Reminders go out from this time (checked every 15 minutes) |
| Default reminder days before | 3 | For vaccines without their own lead time |
| Medication reminders on/off | off | |
| Medication reminder window | 15 minutes (5–120) | Doses due in the last N minutes are reminded |

Stored in `settings.health_settings` (JSON) plus the two reminder columns. Permission:
`health_content.manage`. Audited: `health_settings.update` (before/after).

### 3.3 Other health content

| Screen | Capabilities | Permission |
|---|---|---|
| Vaccination schedule | Template entries: code (unique), names/descriptions ar/en, offset (value + `day|week|month|year`), reminder days before (0–60), order, active; create/edit/delete (soft) | `health_content.view` / `.manage` |
| Pregnancy weeks | Content per week range 1–42: baby size, weight, length (ar/en), tip, image | `health_content.view` / `.manage` |
| Health data | Body weight, blood sugar, blood pressure, ovulation, pregnancy, children & vaccines lists with customer search; **Reminders sent** log (filter by type/status) | `health_data.view` — every page view is audited (`health.view`) |

Template changes never alter schedules already generated: each child's vaccine row is a snapshot
(name, description, date, lead time, template item and version). If the template is empty the
built-in default schedule is used (the same entries the migration seeded: 9 items; 7 pregnancy
week ranges).

## 4. Reminders

| Reminder | When | Switch |
|---|---|---|
| Vaccine | Every 15 minutes from the send time (default 09:00 store time); vaccines not given whose date is between today and today + the vaccine's `reminder_days_before` (default lead time from Health settings); a missed day is caught up | `vaccine_reminders_enabled` (default **on**) |
| Medication dose | Every 5 minutes; doses whose time passed within the window (default 15 minutes) today, on a day the medication is taken | `medication_reminders_enabled` (default **off**) |

Each reminder is one `notification_deliveries` row (unique per type + reference + occurrence), so
it is never sent twice. `SendHealthReminder` claims it (one worker), re-checks it is still due,
stores a database notification and pushes to every device, with the title and body of `health.reminders.*`
in the device's language (not editable from the dashboard). Failures are retried (up to 3 attempts, backoff 60 s / 300 s); lost
or stuck jobs are re-queued by the scheduler; a worker killed between sending and recording can cause
one repeat after 10 minutes (push is at-least-once). Requires a queue worker and, for real pushes,
`PUSH_DRIVER=fcm`.

## 5. Data

`body_weight_results`, `blood_sugar_measurements`, `blood_pressure_measurements`,
`ovulation_results`, `pregnancy_calculations`, `pregnancy_week_contents`, `children`, `vaccines`,
`vaccine_schedule_items`, `medications`, `medication_times`, `medication_days`, `medication_logs`,
`notification_deliveries`, `notifications`, `health_result_rules`,
`settings.health_settings`.

## 6. Security and privacy

Health records are personal data: separate permission, every dashboard view audited, never exported
by the import/export centre, medication images on the private disk. Medical thresholds have their
own permission; texts and settings are health content.

## 7. Tests

`Health/HealthCalculationsTest` (guideline boundaries, now through the seeded rules),
`HealthDashboardControlTest` (rules, settings, tool switches, permissions, audit),
`HealthContentTest`, `HealthRemindersTest`, `ReminderDeliveryTest`, `Api/HealthDataApiTest`,
`MedicationApiTest`.
