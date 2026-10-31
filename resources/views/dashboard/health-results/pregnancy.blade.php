@include('dashboard.health-results._table', [
    'title' => 'Pregnancy',
    'rows' => $pregnancies,
    'columns' => ["Last period" => fn ($r) => $r->last_period_date?->format("M j, Y"), "Due date" => fn ($r) => $r->due_date?->format("M j, Y")],
])
