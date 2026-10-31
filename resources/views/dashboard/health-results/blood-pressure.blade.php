@include('dashboard.health-results._table', [
    'title' => 'Blood pressure',
    'rows' => $bloodPressures,
    'columns' => ["Systolic" => fn ($r) => $r->systolic, "Diastolic" => fn ($r) => $r->diastolic, "Condition" => fn ($r) => $r->condition_type, "Measured" => fn ($r) => $r->measured_at?->format("M j, Y H:i")],
])
