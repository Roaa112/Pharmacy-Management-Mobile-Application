<?php
// Dashboard routes -> required abilities (docs/permissions-matrix.md §3).
// Usage: php artisan route:list --json > routes.json && php docs/tools/route-permissions.php routes.json
$r = json_decode(file_get_contents($argv[1]), true);
usort($r, fn ($a, $b) => strcmp($a['uri'], $b['uri']) ?: strcmp($a['method'], $b['method']));
echo "| Method | URI | Abilities required (all) | Controller action |\n|---|---|---|---|\n";
foreach ($r as $x) {
    if (!str_starts_with($x['uri'], 'dashboard')) continue;
    $can = [];
    $throttle = [];
    foreach ($x['middleware'] as $m) {
        if (preg_match('/Authorize:(.+)$/', $m, $mm)) $can[] = '`' . $mm[1] . '`';
        if (preg_match('/ThrottleRequests:(\d+,\d+)$/', $m, $mm)) $throttle[] = 'throttle ' . $mm[1];
    }
    $method = str_replace(['|HEAD', '|'], ['', '/'], $x['method']);
    $action = str_replace(['App\\Modules\\', 'App\\Http\\Controllers\\'], '', $x['action']);
    $abilities = $can ? implode(' + ', $can) : 'admin session only';
    if ($throttle) $abilities .= ' (' . implode(', ', $throttle) . ')';
    echo "| $method | `/{$x['uri']}` | $abilities | `$action` |\n";
}
