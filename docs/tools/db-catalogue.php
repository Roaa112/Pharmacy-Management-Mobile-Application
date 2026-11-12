<?php
// Table catalogue of docs/technical/database.md §4, from a SQLite database built by running the migrations.
// Usage: php docs/tools/db-catalogue.php /path/to/schema.sqlite > db_tables.md
$db = new PDO('sqlite:' . $argv[1]);

$domains = [
    'Accounts & access' => ['users', 'admins', 'roles', 'permissions', 'permission_role', 'admin_audit_logs', 'personal_access_tokens', 'device_tokens'],
    'Locations & settings' => ['countries', 'cities', 'areas', 'addresses', 'settings'],
    'Catalog' => ['products', 'product_sizes', 'product_images', 'categories', 'brands', 'health_issues', 'health_issue_product', 'product_reviews', 'favorites', 'media'],
    'Cart & orders' => ['carts', 'orders', 'order_items', 'order_status_histories', 'order_promotions'],
    'Payments & refunds' => ['payment_methods', 'payments', 'payment_events', 'payment_status_histories', 'refunds', 'cod_settlements'],
    'Returns' => ['return_requests', 'return_request_items', 'return_request_images'],
    'Delivery' => ['delivery_zones', 'delivery_zone_locations', 'delivery_rates', 'delivery_providers', 'provider_status_maps', 'shipments', 'shipment_events', 'delivery_prices'],
    'Inventory' => ['warehouses', 'warehouse_locations', 'inventory_balances', 'inventory_batches', 'batch_balances', 'stock_movements', 'stock_movement_batches', 'stock_reservations', 'stock_transfers', 'stock_transfer_items', 'stock_transfer_receipts', 'stock_counts', 'stock_count_items'],
    'Purchasing & suppliers' => ['suppliers', 'purchase_orders', 'purchase_order_items', 'purchase_receipts', 'purchase_receipt_items', 'supplier_invoices', 'supplier_payments', 'supplier_returns', 'supplier_return_items'],
    'Marketing & promotions' => ['discount_rules', 'discount_rule_targets', 'promotion_rewards', 'promotion_campaigns', 'promotion_customers', 'promotion_redemptions', 'flash_sales', 'flash_sale_items', 'flash_sale_redemptions', 'coupons', 'coupon_targets', 'coupon_customers', 'coupon_redemptions', 'coupon_user', 'banners', 'opening_ads'],
    'Loyalty' => ['loyalty_ledger', 'loyalty_ledger_allocations', 'loyalty_rewards', 'loyalty_redemptions', 'loyalty_point_reversals'],
    'Health services' => ['medications', 'medication_times', 'medication_days', 'medication_logs', 'body_weight_results', 'blood_sugar_measurements', 'blood_pressure_measurements', 'ovulation_results', 'pregnancy_calculations', 'pregnancy_week_contents', 'children', 'vaccines', 'vaccine_schedule_items', 'health_result_rules'],
    'Notifications & contact' => ['notifications', 'notification_deliveries', 'contact_messages', 'contact_message_replies'],
    'Import & export' => ['import_runs', 'export_runs'],
    'Framework' => ['migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks'],
];

$all = $db->query("select name from sqlite_master where type='table' and name not like 'sqlite_%' order by name")->fetchAll(PDO::FETCH_COLUMN);
$listed = array_merge(...array_values($domains));
$missing = array_diff($all, $listed);
if ($missing) {
    $domains['Other'] = array_values($missing);
}
$unknown = array_diff($listed, $all);
if ($unknown) {
    fwrite(STDERR, 'Not in schema: ' . implode(', ', $unknown) . "\n");
}

foreach ($domains as $domain => $tables) {
    echo "### $domain\n\n";
    foreach ($tables as $t) {
        if (!in_array($t, $all, true)) continue;
        $fks = [];
        foreach ($db->query("pragma foreign_key_list('$t')") as $f) {
            $fks[$f['from']] = "→ `{$f['table']}.{$f['to']}` (on delete " . strtolower($f['on_delete']) . ')';
        }
        $uniques = [];
        $indexes = [];
        foreach ($db->query("pragma index_list('$t')") as $i) {
            if ($i['origin'] === 'pk') continue;
            $cols = array_column($db->query("pragma index_info('{$i['name']}')")->fetchAll(), 'name');
            $cols = array_map(fn ($c) => $c ?? '(expression)', $cols);
            if ($i['unique']) $uniques[] = '(' . implode(', ', $cols) . ')'; else $indexes[] = '(' . implode(', ', $cols) . ')';
        }
        echo "#### `$t`\n\n| Column | Type | Null | Default | Reference |\n|---|---|---|---|---|\n";
        foreach ($db->query("pragma table_info('$t')") as $c) {
            $type = strtolower($c['type']) ?: '-';
            $type = str_replace(['integer', 'tinyint(1)', 'varchar', 'numeric'], ['int', 'bool', 'string', 'decimal'], $type);
            $null = $c['notnull'] ? 'no' : 'yes';
            $default = $c['dflt_value'] === null ? '' : '`' . trim($c['dflt_value'], "'") . '`';
            $ref = $c['pk'] ? 'primary key' : ($fks[$c['name']] ?? '');
            echo "| `{$c['name']}` | $type | $null | $default | $ref |\n";
        }
        if ($uniques) echo "\nUnique: " . implode(', ', $uniques) . "  \n";
        if ($t === 'carts') {
            // SQLite's table_info hides virtual columns; documented by hand from the migration.
            echo "`customer_line_size_id` is a virtual column (`case when is_gift = 0 then product_size_id end`, not listed above): one paid line per size per customer; gift lines are keyed by `gift_key`.  \n";
        }
        if ($indexes) echo ($uniques ? '' : "\n") . "Indexes: " . implode(', ', $indexes) . "\n";
        echo "\n";
    }
}
