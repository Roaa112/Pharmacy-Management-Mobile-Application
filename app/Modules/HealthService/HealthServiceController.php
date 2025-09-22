<?php


namespace App\Modules\HealthService;

use Carbon\Carbon;
use App\Models\Child;
use App\Models\Vaccine;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\HealthService\Services\HealthService;

class HealthServiceController extends Controller
{
    protected $healthService;

    public function __construct(HealthService $healthService)
    {
        $this->healthService = $healthService;
    }




    public function getOvulationResults()
    {
        $results = $this->service->getAllOvulationResults();
        return response()->json(['data' => $results]);
    }

    public function getBodyWeightResults()
    {
        $results = $this->service->getAllBodyWeightResults();
        return response()->json(['data' => $results]);
    }

    public function getBloodSugarResults()
    {
        $results = $this->service->getAllBloodSugarResults();
        return response()->json(['data' => $results]);
    }

    public function getBloodPressureResults()
    {
        $results = $this->service->getAllBloodPressureResults();
        return response()->json(['data' => $results]);
    }

    public function getPregnancyCalculations()
    {
        $results = $this->service->getAllPregnancyCalculations();
        return response()->json(['data' => $results]);
    }

    public function getVaccinationSchedules()
    {
        $results = $this->service->getAllVaccinationSchedules();
        return response()->json(['data' => $results]);
    }

public function storeOvulation(Request $request)
{
    $validated = $request->validate([
        'start_day_of_cycle' => 'required|date',
        'cycle_length' => 'required|integer|min:21|max:35',
        'period_length' => 'required|integer|min:3|max:10',
    ]);

    return response()->json(
        $this->healthService->calculateOvulation($request->user()->id, $validated)
    );
}



    public function storeBodyWeight(Request $request)
    {
        $data = $request->only(['height', 'weight', 'unit']);
        return response()->json(
            $this->healthService->calculateBodyWeight($request->user()->id, $data)
        );
    }

    public function storeBloodSugar(Request $request)
    {
        $data = $request->only(['value', 'condition_type', 'measured_at']);
        return response()->json(
            $this->healthService->storeBloodSugar($request->user()->id, $data)
        );
    }

    public function storeBloodPressure(Request $request)
    {
        $data = $request->only(['systolic', 'diastolic', 'condition_type', 'measured_at']);
        return response()->json(
            $this->healthService->storeBloodPressure($request->user()->id, $data)
        );
    }

   public function storePregnancy(Request $request)
{
    $data = $request->only(['last_period_date']);
    return response()->json(
        $this->healthService->calculatePregnancy($request->user()->id, $data)
    );
}

   public function storeVaccination(Request $request)
{
    $request->validate([
        'child_name' => 'required|string',
        'gender' => 'required|in:ذكر,أنثى',
        'birth_date' => 'required|date',
    ]);
$birth_date = Carbon::parse($request->birth_date)->format('Y-m-d');

    $child = Child::create([
        'user_id' => auth()->id(),
        'child_name' => $request->child_name,
        'gender' => $request->gender,
        'birth_date' => $birth_date,
    ]);

    $this->generateDefaultVaccines($child);

 return response()->json([
    'message' => 'تم إنشاء الطفل وجدول التطعيمات بنجاح',
    'child' => $child,
]);

}

protected function generateDefaultVaccines(Child $child)
{
    $defaultVaccines = [
    [
        'name' => 'لقاح عند الولادة',
        'offset' => '0 days',
        'description' => 'يُعطى عند الولادة مباشرة، يشمل لقاح السل ولقاح التهاب الكبد B.'
    ],
    [
        'name' => 'لقاح بعد 6 أسابيع',
        'offset' => '6 weeks',
        'description' => 'يشمل الجرعة الأولى من تطعيم الخماسي (الدفتيريا، التيتانوس، السعال الديكي، التهاب الكبد B، المستدمية النزلية)، بالإضافة إلى شلل الأطفال الفموي ولقاح الروتا.'
    ],
    [
        'name' => 'لقاح بعد 10 أسابيع',
        'offset' => '10 weeks',
        'description' => 'الجرعة الثانية من تطعيم الخماسي وشلل الأطفال الفموي ولقاح الروتا.'
    ],
    [
        'name' => 'لقاح بعد 14 أسبوع',
        'offset' => '14 weeks',
        'description' => 'الجرعة الثالثة من تطعيم الخماسي وشلل الأطفال ولقاح الروتا، وقد يُضاف لقاح المكورات الرئوية.'
    ],
    [
        'name' => 'لقاح عند عمر سنة',
        'offset' => '12 months',
        'description' => 'يشمل لقاح الحصبة والحصبة الألمانية والنكاف (MMR)، وقد يُضاف لقاح الجدري المائي.'
    ],
];


    foreach ($defaultVaccines as $vaccine) {
        Vaccine::create([
            'child_id' => $child->id,
            'vaccine_name' => $vaccine['name'],
            'description'=> $vaccine['description'],
            'scheduled_date' => \Carbon\Carbon::parse($child->birth_date)->add($vaccine['offset']),
        ]);
    }
}


public function getVaccines(Child $child)
{


    return response()->json($child->vaccines);
}

public function markAsCompleted(Request $request, Vaccine $vaccine)
{
    $vaccine->update([
        'is_completed' => $request->input('is_completed', true),
    ]);

    return response()->json([
        'message' => 'تم تحديث حالة التطعيم',
        'vaccine' => $vaccine,
    ]);
}

}
