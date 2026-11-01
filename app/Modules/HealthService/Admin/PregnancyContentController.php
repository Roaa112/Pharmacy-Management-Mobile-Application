<?php

namespace App\Modules\HealthService\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\PregnancyWeekContent;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/**
 * Pregnancy week content (baby size, weight, length, image, tips). Informational only: the
 * pregnancy week and due date are calculated in code and are not editable.
 */
class PregnancyContentController extends Controller
{
    public function index()
    {
        return view('dashboard.health-content.pregnancy', [
            'contents' => PregnancyWeekContent::orderBy('week_from')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $content = PregnancyWeekContent::create($this->validated($request));
        AdminAuditLog::record('health_content.pregnancy.create', $content, $content->toArray());

        return redirect()->back()->with('success', 'Pregnancy content added.');
    }

    public function update(Request $request, PregnancyWeekContent $content)
    {
        $content->update($this->validated($request, $content));
        AdminAuditLog::record('health_content.pregnancy.update', $content, $content->getChanges());

        return redirect()->back()->with('success', 'Pregnancy content saved.');
    }

    public function destroy(PregnancyWeekContent $content)
    {
        AdminAuditLog::record('health_content.pregnancy.delete', $content, $content->only(['week_from', 'week_to']));
        $content->delete();

        return redirect()->back()->with('success', 'Pregnancy content deleted.');
    }

    private function validated(Request $request, ?PregnancyWeekContent $content = null): array
    {
        $validator = validator($request->all(), [
            'week_from' => 'required|integer|min:1|max:42',
            'week_to' => 'required|integer|min:1|max:42|gte:week_from',
            'size_ar' => 'required|string|max:255',
            'size_en' => 'required|string|max:255',
            'weight_ar' => 'required|string|max:50',
            'weight_en' => 'required|string|max:50',
            'length_ar' => 'required|string|max:50',
            'length_en' => 'required|string|max:50',
            'tip_ar' => 'nullable|string|max:2000',
            'tip_en' => 'nullable|string|max:2000',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'is_active' => 'nullable|boolean',
        ]);

        // Active week ranges may not overlap: each week has one piece of content.
        $validator->after(function (Validator $validator) use ($request, $content) {
            if (!$request->boolean('is_active', true)) {
                return;
            }
            $overlap = PregnancyWeekContent::where('is_active', true)
                ->when($content, fn ($q) => $q->whereKeyNot($content->id))
                ->where('week_from', '<=', (int) $request->week_to)
                ->where('week_to', '>=', (int) $request->week_from)
                ->exists();
            if ($overlap) {
                $validator->errors()->add('week_from', 'These weeks overlap another active entry.');
            }
        });

        $data = $validator->validate();
        unset($data['image']);
        $data['is_active'] = $request->boolean('is_active', true);

        if ($request->hasFile('image')) {
            // Illustrations are public content, served like product images.
            $data['image'] = 'storage/'.$request->file('image')->store('pregnancy', 'public');
        }

        return $data;
    }
}
