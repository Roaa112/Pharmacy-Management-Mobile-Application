<?php

namespace App\Modules\HealthService\Services;

use App\Enums\BloodSugarCondition;
use App\Models\HealthResultRule;
use Illuminate\Support\Collection;

/**
 * Classifies a reading with the dashboard-managed result rules (HealthResultRule). A metric with
 * no active rule falls back to the guideline defaults, so a reading is always classified.
 */
class HealthEvaluator
{
    /** The rule a reading falls into, or null when no rule covers it ("unknown"). */
    public function evaluate(string $metric, float $primary, ?float $secondary = null, ?string $condition = null): ?HealthResultRule
    {
        return $this->candidates($metric, $condition)
            ->filter(fn (HealthResultRule $rule) => $rule->matches($primary, $secondary))
            ->sortBy([['priority', 'desc'], ['sort_order', 'asc'], ['id', 'asc']])
            ->first();
    }

    /** BloodSugarCondition → rule condition group (null when the condition is not recognised). */
    public static function sugarGroup(string $input): ?string
    {
        $condition = BloodSugarCondition::fromInput($input);

        return match (true) {
            $condition === null => null,
            $condition->isFastingLike() => 'fasting',
            $condition === BloodSugarCondition::AfterMeal => 'after_meal',
            default => 'random',
        };
    }

    /** @return Collection<int, HealthResultRule> active rules of the metric that apply to the condition */
    public function candidates(string $metric, ?string $condition = null): Collection
    {
        $rules = HealthResultRule::where('metric', $metric)->where('is_active', true)->get();

        if ($rules->isEmpty()) {
            $rules = collect(HealthRuleDefaults::models($metric));
        }

        return $rules->filter(fn (HealthResultRule $rule) => $rule->condition === null || $rule->condition === $condition)->values();
    }

    /**
     * Value ranges no active rule covers, per metric and reading condition (BMI and blood sugar;
     * blood pressure rules combine two numbers and are checked with the preview instead).
     *
     * @return list<string>
     */
    public function coverageWarnings(): array
    {
        $warnings = [];
        $groups = [HealthResultRule::BMI => [null], HealthResultRule::BLOOD_SUGAR => HealthResultRule::SUGAR_CONDITIONS];

        foreach ($groups as $metric => $conditions) {
            foreach ($conditions as $condition) {
                $rules = $this->candidates($metric, $condition);
                if ($rules->contains(fn (HealthResultRule $r) => !$r->hasPrimaryRange())) {
                    continue; // a catch-all covers everything
                }

                $where = str_replace('_', ' ', $metric).($condition ? ' ('.str_replace('_', ' ', $condition).')' : '');
                foreach ($this->gaps($rules) as [$from, $to]) {
                    $warnings[] = ucfirst($where).': readings '.($to === null ? '≥ '.$from : ($from == 0 ? 'below '.$to : "from {$from} to under {$to}")).' match no rule and are reported as "unknown".';
                }
            }
        }

        return $warnings;
    }

    /** @return list<array{0: float, 1: ?float}> uncovered intervals of [0, ∞) */
    private function gaps(Collection $rules): array
    {
        $ranges = $rules->map(fn (HealthResultRule $r) => [$r->primary_from ?? 0.0, $r->primary_to])->sortBy(0)->values();
        $gaps = [];
        $covered = 0.0;

        foreach ($ranges as [$from, $to]) {
            if ($from > $covered) {
                $gaps[] = [$covered, $from];
            }
            if ($to === null) {
                return $gaps;
            }
            $covered = max($covered, $to);
        }

        $gaps[] = [$covered, null];

        return $gaps;
    }
}
