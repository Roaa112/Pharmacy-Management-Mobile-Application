@include('dashboard.health-results._table', [
    'title' => 'Blood sugar',
    'rows' => $bloodSugars,
    'columns' => ["Value" => fn ($r) => $r->value, "Condition" => fn ($r) => $r->condition_type, "Measured" => fn ($r) => $r->measured_at?->format("M j, Y H:i")],
])
