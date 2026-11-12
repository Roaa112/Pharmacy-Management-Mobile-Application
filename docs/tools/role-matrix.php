<?php
// Default role × permission matrix (docs/permissions-matrix.md §2) from a migrated SQLite database.
// Usage: php docs/tools/role-matrix.php /path/to/schema.sqlite
// Read-only: never point it at a real database you care about (it only reads, but use a scratch copy).
$db = new PDO('sqlite:' . $argv[1]);
$roles = $db->query('select id, slug, is_super_admin from roles order by id')->fetchAll(PDO::FETCH_ASSOC);
$permissions = $db->query('select id, slug, module from permissions order by id')->fetchAll(PDO::FETCH_ASSOC);

$granted = [];
foreach ($db->query('select role_id, permission_id from permission_role') as $row) {
    $granted[$row['role_id']][$row['permission_id']] = true;
}

echo '| Permission | Module | ' . implode(' | ', array_column($roles, 'slug')) . " |\n";
echo '|---|---|' . str_repeat('---|', count($roles)) . "\n";
foreach ($permissions as $p) {
    echo "| `{$p['slug']}` | {$p['module']} |";
    foreach ($roles as $r) {
        // The super admin role holds every permission by definition (Role::grantedSlugs()).
        echo ' ' . (($r['is_super_admin'] || isset($granted[$r['id']][$p['id']])) ? '✓' : '·') . ' |';
    }
    echo "\n";
}
