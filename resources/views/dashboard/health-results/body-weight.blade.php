@include('dashboard.health-results._table', [
    'title' => 'Body weight',
    'rows' => $results,
    'columns' => ["Height" => fn ($r) => $r->height, "Weight" => fn ($r) => $r->weight, "Unit" => fn ($r) => $r->unit, "BMI" => fn ($r) => $r->bmi_result, "Recorded" => fn ($r) => $r->created_at?->format("M j, Y")],
])
