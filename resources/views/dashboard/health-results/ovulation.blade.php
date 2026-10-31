@include('dashboard.health-results._table', [
    'title' => 'Ovulation',
    'rows' => $ovulations,
    'columns' => ["Cycle start" => fn ($r) => $r->start_day_of_cycle?->format("M j, Y"), "Cycle length (days)" => fn ($r) => $r->cycle_length],
])
