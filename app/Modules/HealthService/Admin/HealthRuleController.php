<?php

namespace App\Modules\HealthService\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\HealthResultRule;
use App\Modules\HealthService\Services\HealthEvaluator;
use App\Modules\HealthService\Services\HealthRuleDefaults;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Health content › Result rules: when a BMI, blood sugar or blood pressure reading falls into a
 * category, and the label and message the customer gets for it. Viewing needs
 * health_content.view; changing medical thresholds needs health_rules.manage. Every change is in
 * the audit log, and a metric can be reset to the guideline defaults.
 */
class HealthRuleController extends Controller
{
    public const UNITS = [HealthResultRule::BMI => 'kg/m²', HealthResultRule::BLOOD_SUGAR => 'mg/dL', HealthResultRule::BLOOD_PRESSURE => 'mmHg'];

    public function index(Request $request, HealthEvaluator $evaluator)
    {
        return view('dashboard.health-content.rules', [
            'rules' => HealthResultRule::orderBy('metric')->orderByDesc('priority')->orderBy('sort_order')->orderBy('id')->get()->groupBy('metric'),
            'warnings' => $evaluator->coverageWarnings(),
            'preview' => $this->preview($request, $evaluator),
            'units' => self::UNITS,
        ]);
    }

    public function store(Request $request)
    {
        $rule = HealthResultRule::create($this->validated($request));
        AdminAuditLog::record('health_rules.create', $rule, $rule->toArray());

        return redirect()->back()->with('success', 'Result rule added. It applies to readings saved from now on.');
    }

    public function update(Request $request, HealthResultRule $rule)
    {
        $before = $rule->only($rule->getFillable());
        $rule->update($this->validated($request, $rule));
        AdminAuditLog::record('health_rules.update', $rule, ['before' => $before, 'after' => $rule->getChanges()]);

        return redirect()->back()->with('success', 'Result rule saved. Readings saved earlier keep the result they were given.');
    }

    public function destroy(HealthResultRule $rule)
    {
        AdminAuditLog::record('health_rules.delete', $rule, $rule->only($rule->getFillable()));
        $rule->delete();

        return redirect()->back()->with('success', 'Result rule deleted.');
    }

    /** Replace a metric's rules with the guideline defaults (WHO / ADA / ACC-AHA). */
    public function reset(string $metric)
    {
        abort_unless(in_array($metric, HealthResultRule::METRICS, true), 404);

        DB::transaction(function () use ($metric) {
            $before = HealthResultRule::where('metric', $metric)->get()->map->only(['code', 'condition', 'primary_from', 'primary_to', 'secondary_from', 'secondary_to', 'label_en'])->all();
            HealthResultRule::where('metric', $metric)->delete();
            foreach (HealthRuleDefaults::rows($metric) as $row) {
                HealthResultRule::create($row);
            }
            AdminAuditLog::record('health_rules.reset', null, ['metric' => $metric, 'before' => $before]);
        });

        return redirect()->back()->with('success', 'The '.str_replace('_', ' ', $metric).' rules were reset to the guideline defaults.');
    }

    /** "Try a reading" on the rules page: which rule (and text) a value gets right now. */
    private function preview(Request $request, HealthEvaluator $evaluator): ?array
    {
        $metric = $request->query('try_metric');
        if (!in_array($metric, HealthResultRule::METRICS, true) || !is_numeric($request->query('try_value'))) {
            return null;
        }

        $secondary = is_numeric($request->query('try_value2')) ? (float) $request->query('try_value2') : null;
        $condition = $metric === HealthResultRule::BLOOD_SUGAR && in_array($request->query('try_condition'), HealthResultRule::SUGAR_CONDITIONS, true)
            ? $request->query('try_condition') : null;

        return [
            'metric' => $metric,
            'value' => (float) $request->query('try_value'),
            'value2' => $secondary,
            'condition' => $condition,
            'rule' => $evaluator->evaluate($metric, (float) $request->query('try_value'), $metric === HealthResultRule::BLOOD_PRESSURE ? $secondary : null, $condition),
        ];
    }

    private function validated(Request $request, ?HealthResultRule $rule = null): array
    {
        $metric = $rule?->metric ?? $request->input('metric');
        $bounds = ['nullable', 'numeric', 'min:0', 'max:999999'];

        $validator = validator($request->all(), [
            'metric' => [$rule ? 'prohibited' : 'required', Rule::in(HealthResultRule::METRICS)],
            'condition' => ['nullable', Rule::in(HealthResultRule::SUGAR_CONDITIONS)],
            'code' => ['required', 'alpha_dash', 'max:50'],
            'priority' => ['required', 'integer', 'min:-1000', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'primary_from' => $bounds,
            'primary_to' => $bounds,
            'secondary_from' => $bounds,
            'secondary_to' => $bounds,
            'label_ar' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            'legacy_ar' => ['nullable', 'string', 'max:255'],
            'message_ar' => ['nullable', 'string', 'max:2000'],
            'message_en' => ['nullable', 'string', 'max:2000'],
            'requires_urgent_care' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'primary_from' => $metric === HealthResultRule::BLOOD_PRESSURE ? 'systolic from' : 'from',
            'primary_to' => $metric === HealthResultRule::BLOOD_PRESSURE ? 'systolic to' : 'to',
            'secondary_from' => 'diastolic from',
            'secondary_to' => 'diastolic to',
        ]);

        $validator->after(function (Validator $validator) use ($request) {
            foreach ([['primary_from', 'primary_to'], ['secondary_from', 'secondary_to']] as [$from, $to]) {
                if (is_numeric($request->input($from)) && is_numeric($request->input($to)) && (float) $request->input($to) <= (float) $request->input($from)) {
                    $validator->errors()->add($to, 'The upper bound must be greater than the lower bound ("from ≤ value < to").');
                }
            }
        });

        $data = $validator->validate();
        unset($data['metric']);

        $number = fn (string $key) => is_numeric($request->input($key)) ? (float) $request->input($key) : null;

        return array_merge($data, $rule ? [] : ['metric' => $metric], [
            // Reading conditions exist for blood sugar only; diastolic ranges for blood pressure only.
            'condition' => $metric === HealthResultRule::BLOOD_SUGAR ? ($data['condition'] ?? null) : null,
            'primary_from' => $number('primary_from'),
            'primary_to' => $number('primary_to'),
            'secondary_from' => $metric === HealthResultRule::BLOOD_PRESSURE ? $number('secondary_from') : null,
            'secondary_to' => $metric === HealthResultRule::BLOOD_PRESSURE ? $number('secondary_to') : null,
            'sort_order' => (int) $request->input('sort_order', 0),
            'requires_urgent_care' => $request->boolean('requires_urgent_care'),
            'is_active' => $request->boolean('is_active'),
        ]);
    }
}
