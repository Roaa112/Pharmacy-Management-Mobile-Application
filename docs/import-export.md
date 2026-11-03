# Import & export centre

Code: `app/Modules/DataTransfer` (`ImportManager`, `ExportManager`, `TemplateBuilder`, `EntityRegistry`,
`Entities/*`, `Support/Spreadsheet` (OpenSpout, streaming), `Support/ImageFetcher`), jobs
`ValidateImport`, `ProcessImport`, `RunExport`, models `ImportRun`, `ExportRun`.
Dashboard: **Operations › Import & export** (visible with any import or export permission).

## Which data, and why

| Data | Import | Export | Match / notes |
|---|---|---|---|
| Products + sizes | ✓ | ✓ | `product_code` (products.code, stable), sizes by SKU → barcode → size label |
| Categories | ✓ | ✓ | id (names never create duplicates) |
| Brands | ✓ | ✓ | id |
| Inventory | ✓ (adjustments) | ✓ | SKU/barcode + warehouse code; every row is a recorded stock adjustment |
| Suppliers | ✓ | ✓ | code; invoices, payments and balances are never imported |
| Cities, Areas | ✓ | ✓ | id; delivery zones and rates stay on the Delivery screens |
| Loyalty rewards | ✓ | ✓ | reward code; product rewards name their size by SKU |
| Customers | — | ✓ sensitive | export only: accounts need the customer's own credentials and verification |
| Orders, Order lines | — | ✓ sensitive | created by checkout only |
| Payments, Refunds | — | ✓ sensitive | financial history; provider ids, payloads and receipts never exported |
| Point transactions | — | ✓ | the ledger is written by the programme; manual changes are adjustments |
| Admins, roles, permissions | — | — | security boundary, managed on their screens |
| Delivery zones / rates / providers | — | — | small configuration with provider credentials; managed on screen |
| Health records, audit log, sessions, tokens, jobs, cache, migrations, password resets | — | — | never |

Formats: CSV (UTF-8 with BOM) and XLSX. Files are read and written as streams, one row at a time.

## Import flow

1. **Template** (`products.import` etc.): XLSX with *Data* (headers), *Examples* and *Instructions*
   (each column: required, description, accepted values, example; match rules; modes; notes), or CSV
   with headers and example rows. Generated from the entity definition, so it never drifts.
2. **Upload** (≤ 20 MB) with a mode: **create only** (existing records rejected), **update only**
   (unknown records rejected) or **create or update**. Inventory accepts update only.
3. **Validate / preview** (writes nothing): total, valid, invalid rows, warnings, records to create and
   to update, duplicates in the file, relationship errors, the first errors and warnings, and a
   downloadable **error report** (row, severity, column, message, value).
4. **Confirm**: every record is validated again against the data as it is then and written in **its own
   transaction**; a record that fails (even while saving, e.g. an image that cannot be downloaded) is
   rolled back completely and reported, the others go ahead. Files above
   `PHARMACY_IMPORT_SYNC_MAX_BYTES` (512 KB) run on the queue (`php artisan queue:work`); the page shows
   progress. An import can be cancelled while pending or processing (records already written stay).
5. **Results**: status (`pending`, `processing`, `completed`, `completed_with_errors`, `failed`,
   `cancelled`), created / updated / failed counts, result report (one line per record), error report and
   the original file — all downloadable from **Import history**.

## Products

One row per size; the product columns repeat on each of its rows, which must be next to each other.

| Column | |
|---|---|
| product_code * | stable product identifier (existing products got `PRD-000123`; new ones from the form too) |
| name_en *, name_ar *, description_en, description_ar | |
| category_id *, category_name | by id; a name is used only if `category_id` is empty and it matches exactly one category (warning) |
| brand_id, brand_name | same rule |
| health_issue_ids | ids separated by `\|`; replaces the product's health issues when filled |
| image_url * | http(s) URL (downloaded once) or an existing path (`products/x.png`) |
| extra_image_urls | added; existing images kept |
| variant_sku, variant_barcode | unique across all products |
| size *, color, price * (> 0), cost_price, min_stock, max_stock, reorder_point, reorder_quantity | |
| stock | **opening stock of new sizes only**, recorded as an `initial` stock movement in the default warehouse |
| export only | product_id, variant_id, stock_status, reserved, sale_price (after promotions), deleted, created_at, updated_at |

Rules: SKU/barcode used twice in the file, or by another product → error. Category/brand/health issue
that does not exist → error (nothing is created from a name). Sizes and images not in the file are kept:
**an import never deletes**. For existing sizes the `stock` column is ignored (warning when different):
stock changes go through the **Inventory** import, which records `adjustment` movements with a reason
through `InventoryService::adjust` (set the counted quantity, or adjust by a signed amount; never below 0).

**Images**: only http(s); the host must resolve to public addresses only (no private, loopback or reserved
ranges), redirects are not followed and the connection is pinned to the checked address; at most 5 MB;
JPG/PNG/WebP by detected content. A failed download fails only its record.

## Export

Choose the data, filters (products: category, brand, supplier, status, stock status, price range,
created/updated dates; orders: status, payment status, dates; …), columns and format. Headers are column
keys, so an export of an importable entity can be imported as it is (tested: export → import in update
mode changes nothing). Rows are streamed with `lazyById(500)`; up to `PHARMACY_EXPORT_SYNC_MAX_ROWS`
(5 000) the file is built at once, larger exports on the queue (**Export history** lists them). Values
starting with `= + - @` are prefixed with `'` (formula injection) and the prefix is removed on import.

## Security and audit

* Each entity has its own permission (`products.import`, `customers.export`, …); no admin gets them by
  default except the super admin and the role defaults listed in roles-and-permissions.md. Downloads of
  templates, reports, originals and exports re-check the permission; history lists only entities the
  admin may move.
* Sensitive entities export only approved columns (never passwords, codes, tokens, provider secrets or
  health data) and the audit entry is flagged `sensitive`.
* Audited: `data.import_upload`, `data.import_confirm`, `data.import` (counts and status),
  `data.import_cancel`, `data.import_failed`, `data.import_download`, `data.export` (entity, format,
  filters, columns, record count), `data.export_download` — with admin, IP and user agent.

## Known limitations

* An import never deletes records, sizes or images; removals are done on screen.
* Rows of one product must be consecutive in the file (exports are).
* Product promotions, flash sales and stock per warehouse are not part of the product file (promotions
  have their own screens; stock has the Inventory import/export).
* Queued imports and exports need a queue worker; small ones run immediately.
