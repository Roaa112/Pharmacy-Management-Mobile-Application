<?php

namespace App\Modules\Medication;

use Carbon\Carbon;
use App\Models\Medication;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\MedicationDay;
use App\Models\MedicationLog;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Modules\Medication\Services\MedicationService;
use App\Modules\Medication\Resources\MedicationResource;
use App\Modules\Medication\Requests\StoreMedicationRequest;
use App\Modules\Medication\Requests\UpdateMedicationRequest;

class MedicationController extends Controller
{
    protected $service;

    public function __construct(MedicationService $service)
    {
        $this->service = $service;
    }

public function index(Request $request)
{
    $user = $request->user();

    if (!$user) {
        Log::error('Unauthenticated user attempt');
        return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
    }

    $weekDays = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
    $carbonWeekDays = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    $today = Carbon::today();
    $todayIndex = array_search($today->format('l'), $carbonWeekDays);

    $result = collect();

    foreach ($weekDays as $day) {
        $targetIndex = array_search(ucfirst($day), $carbonWeekDays);
        $diff = $targetIndex - $todayIndex;
        $date = $today->copy()->addDays($diff); // التاري المطلوب عرضه

        $dayMedications = Medication::with('times', 'days')
            ->where('user_id', $user->id)
            ->get()
            ->filter(function ($medication) use ($day, $date, $user) {
                if ($medication->repeat_type === 'every_day') {
                    return true;
                }

                if ($medication->repeat_type === 'specific_days') {
                    return collect($medication->days)->contains(function ($d) use ($day) {
                        return Str::lower(is_object($d) ? $d->day : $d) === $day;
                    });
                }

                if ($medication->repeat_type === 'once') {
                    $medicationDay = $medication->days->first();
                    if (!$medicationDay) return false;

                    $medicationDayName = is_object($medicationDay) ? Str::lower($medicationDay->day) : Str::lower($medicationDay);
                    if ($medicationDayName !== $day) return false;

                    $now = Carbon::now();
                    $weekStart = $now->copy()->startOfWeek(Carbon::SATURDAY);
                    $weekEnd = $now->copy()->endOfWeek(Carbon::FRIDAY);

                    if ($date->lt($weekStart) || $date->gt($weekEnd)) {
                        return false;
                    }

                    return true;
                }

                return false;
            })
            ->map(function ($medication) use ($user, $date, $weekDays) {
              $medication->times = $medication->times->map(function ($time) use ($medication, $user, $date) {
    $log = MedicationLog::where('user_id', $user->id)
        ->where('medication_id', $medication->id)
        ->whereDate('shown_date', $date->toDateString())
        ->where('time', '=', $time->time) // ← بدلن هنا فقط
        ->first();

    Log::info('Time comparison check', [
        'time_from_db' => $time->time,
        'formatted' => Carbon::parse($time->time)->format('H:i:s')
    ]);

    Log::info('Medication Time Check', [
        'user_id' => $user->id,
        'medication_id' => $medication->id,
        'date' => $date->toDateString(),
        'time' => $time->time,
        'found_status' => $log?->status,
        'log_exists' => $log ? true : false
    ]);

    $time->status = $log?->status ?? 'pending';
    return $time;
});


             if ($medication->repeat_type === 'every_day') {
                    $medication->days = collect($weekDays)->map(function ($day) {
                        return ['day' => $day];
                    });
                }


                return $medication;
            })->values();

        $result->push([
            'day' => $day,
            'medications' => MedicationResource::collection($dayMedications)
        ]);
    }

    return response()->json($result);
}



//    public function index(Request $request)
// {
//     $user = $request->user();

//     if (!$user) {
//         Log::error('Unauthenticated user attempt');
//         return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
//     }

//     $daysOfWeek = [
//         'saturday', 'sunday', 'monday',
//         'tuesday', 'wednesday', 'thursday', 'friday'
//     ];

//     $allMedications = Medication::with('times', 'days')
//         ->where('user_id', $user->id)
//         ->get();

//     $result = collect();

//     foreach ($daysOfWeek as $day) {
//         // نحسب تاريخ اليوم ف الأسبوع الاي بناءً على اسم يوم
//         $date = Carbon::now()->startOfWeek(); // ابداية الثنين افراضياً
//         $dayIndex = array_search($day, $daysOfWeek);
//         $date = $date->copy()->addDays($dayIndex);

//         // لو اتاريخ قبل الي، نخلي اليو (عشا الحاة لا تظهر كتت بتاريخ قدمة)
//         if ($date->lt(Carbon::today())) {
//             $date = Carbon::today();
//         }

//         $filteredMeds = $allMedications->filter(function ($medication) use ($day, $date, $user) {
//             // every_day -> نظهر ك يوم
//             if ($medication->repeat_type === 'every_day') {
//                 return true;
//             }

//             // specific_days -> نهر فقط إذ اليوم مجد في اليام المحددة
//             if ($medication->repeat_type === 'specific_days') {
//                 return $medication->days->contains(function ($d) use ($day) {
//                     if (is_object($d)) {
//                         return Str::lower($d->day) === $day;
//                     }
//                     return Str::lower($d) === $day;
//                 });
//             }

//             // once -> نظهر فقط في ليوم المحدد ولم يؤخذ كل مواعيده
//             if ($medication->repeat_type === 'once') {
//                 $medicationDay = $medication->days->first();
//                 if (!$medicationDay) return false;

//                 $medicationDayName = is_object($medicationDay) ? Str::lower($medicationDay->day) : Str::lower($medicationDay);

//                 if ($medicationDayName !== $day) {
//                     return false;
//                 }

//                 $allTaken = $medication->times->every(function ($time) use ($medication, $user, $date) {
//                     return MedicationLog::where('user_id', $user->id)
//                         ->where('medication_id', $medication->id)
//                         ->whereDate('shown_date', $date->toDateString())
//                         ->where('time', $time->time)
//                         ->where('status', 'taken')
//                         ->exists();
//                 });

//                 return !$allTaken;
//             }

//             return false;
//         })->map(function ($medication) use ($user, $date) {

//             // عشان نرجع كل ايد الدواء مع حالة taken/pending بناء على لتارخ واليوم
//             $medication->times = $medication->times->map(function ($time) use ($medication, $user, $date) {

//                 $log = MedicationLog::where('user_id', $user->id)
//                     ->where('medication_id', $medication->id)
//                     ->whereDate('shown_date', $date->toDateString())
//                     ->where('time', $time->time)
//                     ->first();

//                 $time->status = $log ? $log->status : 'pending';

//                 return $time;
//             });

//             return $medication;
//         })->values();

//         $result->push([
//             'day' => $day,
//             'medications' => MedicationResource::collection($filteredMeds)
//         ]);
//     }

//     return response()->json($result);
// }


// public function index(Request $request)
// {
//     $user = $request->user();

//     if (!$user) {
//         Log::error('Unauthenticated user attempt');
//         return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
//     }

//     $daysOfWeek = [
//         'saturday', 'sunday', 'monday',
//         'tuesday', 'wednesday', 'thursday', 'friday'
//     ];

//     // جب كل الأدوية م اعلاقات الضرري فعة واحة
//     $allMedications = Medication::with('times', 'days', 'logs')
//         ->where('user_id', $user->id)
//         ->get();

//     $result = collect();

//     foreach ($daysOfWeek as $day) {
//         // حساب تاري الو في الأبوع الحالي أو التالي
//         $date = Carbon::now()->startOfWeek(); // بدية الأسبوع (الإثنين)
//         $dayIndex = array_search($day, $daysOfWeek);

//         // إذا يوم البت هو لبداي حسب منطقتك تأكد م ضب startOfWeek
//         // ما لو أسبعك يبدأ سبت:
//         // $date = Carbon::now()->startOfWeek(Carbon::SATURDAY);

//         // إضافة عدد الأيا لصو لليوم امطل
//         $date = $date->copy()->addDays($dayIndex);

//         // إذ التاريخ أر من الوم (أي في لماضي)، د التاريخ لليوم التالي ن
//         if ($date->lt(Carbon::today())) {
//             $date = Carbon::today();
//         }

//         // لتر الأدوي حسب نوع اتكر اليوم
//         $filteredMeds = $allMedications->filter(function ($medication) use ($day, $date, $user) {

//             if ($medication->repeat_type === 'every_day') {
//                 return true;
//             }

//             if ($medication->repeat_type === 'specific_days') {
//                 // فرض علاقة days حوي كئنات أو أساء الأيام كخاصية 'day'
//                 return $medication->days->contains(function ($d) use ($day) {
//                     if (is_object($d)) {
//                         return Str::lower($d->day) === $day;
//                     }
//                     // لو ان مصفوفة و نص
//                     return Str::lower($d) === $day;
//                 });
//             }

//             if ($medication->repeat_type === 'once') {
//                 $medicationDay = $medication->days->first();

//                 if (!$medicationDay) return false;

//                 $medicationDayName = is_object($medicationDay) ? Str::lower($medicationDay->day) : Str::lower($medicationDay);

//                 if ($medicationDayName !== $day) {
//                     return false;
//                 }

//                 // إذا كل الأوا تم أخذها لهذا ليم لا نظهر الدواء
//                 $allTaken = $medication->times->every(function ($time) use ($medication, $user, $date) {
//                     return MedicationLog::where('user_id', $user->id)
//                         ->where('medication_id', $medication->id)
//                         ->whereDate('shown_date', $date->toDateString())
//                         ->where('time', $time->time)
//                         ->where('status', 'taken')
//                         ->exists();
//                 });

//                 return !$allTaken;
//             }

//             return false;
//         })->map(function ($medication) use ($user, $date) {

//             // تعيل كل الأوات ليشمل حة الدواء في هذا اق والتاريخ
//             $medication->times = $medication->times->map(function ($time) use ($medication, $user, $date) {

//                 $log = MedicationLog::where('user_id', $user->id)
//                     ->where('medication_id', $medication->id)
//                     ->whereDate('shown_date', $date->toDateString())
//                     ->where('time', $time->time)
//                     ->first();

//                 $time->status = $log ? $log->status : 'pending';

//                 return $time;
//             });

//             return $medication;
//         })->values();

//         $result->push([
//             'day' => $day,
//             'medications' => MedicationResource::collection($filteredMeds)
//         ]);
//     }

//     return response()->json($result);
// }

//     public function index(Request $request)
// {
//     $user = $request->user();

//     if (!$user) {
//         Log::error('Unauthenticated user attempt');
//         return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
//     }

//     $daysOfWeek = [
//         'saturday', 'sunday', 'monday',
//         'tuesday', 'wednesday', 'thursday', 'friday'
//     ];

//     $allMedications = Medication::with('times', 'days', 'logs')
//         ->where('user_id', $user->id)
//         ->get();

//     $result = collect();

//     foreach ($daysOfWeek as $day) {
//         $date = Carbon::parse("next $day")->startOfDay();
//         if (Carbon::now()->isSameDay($date)) {
//             $date = Carbon::today();
//         }

//         $filteredMeds = $allMedications->filter(function ($medication) use ($day, $date, $user) {
//             if ($medication->repeat_type === 'every_day') {
//                 return true;
//             }

//             if ($medication->repeat_type === 'specific_days') {
//                 return $medication->days->contains('day', $day);
//             }

//             if ($medication->repeat_type === 'once') {
//                 $medicationDay = $medication->days->first();
//                 if (!$medicationDay || Str::lower($medicationDay->day) !== $day) {
//                     return false;
//                 }

//                 $allTaken = $medication->times->every(function ($time) use ($medication, $user, $date) {
//                     return MedicationLog::where('user_id', $user->id)
//                         ->where('medication_id', $medication->id)
//                         ->whereDate('shown_date', $date)
//                         ->where('time', $time->time)
//                         ->where('status', 'taken')
//                         ->exists();
//                 });

//                 return !$allTaken;
//             }

//             return false;
//         })->map(function ($medication) use ($user, $date) {
//             $medication->times = $medication->times->map(function ($time) use ($medication, $user, $date) {
//                 $log = MedicationLog::where('user_id', $user->id)
//                     ->where('medication_id', $medication->id)
//                     ->whereDate('shown_date', $date)
//                     ->where('time', $time->time)
//                     ->first();

//                 // إضافة خاصة بدون تويل الكائن إلى مصفوفة
//                 $time->status = $log ? $log->status : 'pending';

//                 return $time;
//             });

//             return $medication;
//         })->values();

//         $result->push([
//             'day' => $day,
//             'medications' => MedicationResource::collection($filteredMeds)
//         ]);
//     }

//     return response()->json($result);
// }

  
    // public function index(Request $request)
    // {
    //     $user = $request->user();

    //     if (!$user) {
    //         Log::error('Unauthenticated user attempt');
    //         return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
    //     }

    //     $today = Carbon::today();
    //     $dayName = Str::lower($today->format('l'));

    //     $medications = Medication::with('times', 'days', 'logs')
    //         ->where('user_id', $user->id)
    //         ->get()
    //         ->filter(function ($medication) use ($dayName, $today, $user) {

    //             // 1. every_day
    //             if ($medication->repeat_type === 'every_day') {
    //                 return true;
    //             }

    //             // 2. specific_days
    //             if ($medication->repeat_type === 'specific_days') {
    //                 return $medication->days->contains('day', $dayName);
    //             }

    //             // 3. once
    //            if ($medication->repeat_type === 'once') {
    //     $medicationDay = $medication->days->first();
        
    //     if (!$medicationDay || Str::lower($medicationDay->day) !== $dayName) {
    //         return false; // م اليم المسوح
    //     }

    //     $allTaken = $medication->times->every(function ($time) use ($medication, $user, $today) {
    //         return MedicationLog::where('user_id', $user->id)
    //             ->where('medication_id', $medication->id)
    //             ->whereDate('shown_date', $today)
    //             ->where('time', $time->time)
    //             ->where('status', 'taken')
    //             ->exists();
    //     });

    //     return !$allTaken;
    // }

    //             return false;
    //         })
    //         ->map(function ($medication) use ($user, $today) {
    //             // إرفاق status لل موعد
    //             $medication->times = $medication->times->map(function ($time) use ($medication, $user, $today) {
    //                 $log = MedicationLog::where('user_id', $user->id)
    //                     ->where('medication_id', $medication->id)
    //                     ->whereDate('shown_date', $today)
    //                     ->where('time', $time->time)
    //                     ->first();

    //                 return [
    //                     'time' => $time->time,
    //                     'status' => $log ? $log->status : 'pending',
    //                 ];
    //             });

    //             return $medication;
    //         })
    //         ->values();

    //     return MedicationResource::collection($medications);
    // }

   

    public function store(StoreMedicationRequest $request)
    {
        $med = $this->service->create($request->validated(), auth()->user());
     return new MedicationResource($med->load('times', 'days'));
    }

    public function update(UpdateMedicationRequest $request, Medication $medication)
    {
      
        $updated = $this->service->update($medication, $request->validated());
        return response()->json($updated->load('times', 'days'));
    }

 public function updateLogStatus(Request $request, Medication $medication)
{
    $request->validate([
        'time' => 'required|date_format:H:i:s',
        'status' => 'required|in:taken,missed',
        'shown_date' => 'nullable|date_format:Y-m-d',
    ]);

    $user = $request->user();

    $shownDate = $request->input('shown_date') 
        ? Carbon::parse($request->input('shown_date'))->startOfDay() 
        : Carbon::today();

    // تحقق إذا التاريخ في المستقبل
    if ($shownDate->gt(Carbon::today())) {
        return response()->json([
            'success' => false,
            'message' => 'لا يمن تعديل حلة جرعة ي المستقبل',
        ], 422);
    }

    // إنشاء أو تحديث السجل
    $log = MedicationLog::firstOrCreate(
        [
            'user_id' => $user->id,
            'medication_id' => $medication->id,
            'shown_date' => $shownDate,
            'time' => $request->time,
        ],
        [
            'status' => $request->status,
        ]
    );

    if (!$log->wasRecentlyCreated) {
        $log->update(['status' => $request->status]);
    }

    return response()->json([
        'success' => true,
        'message' => 'تم تحديث حلة الجرعة بنجح',
    ]);
}
    public function destroy(Medication $medication)
    {
       
        $medication->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
 public function deleteDayFromMedication(Request $request)
{
    $request->validate([
        'medication_id' => 'required|exists:medications,id',
        'day' => 'required|in:saturday,sunday,monday,tuesday,wednesday,thursday,friday',
    ]);

    $medication = Medication::find($request->medication_id);

    if (!$medication) {
        return response()->json(['success' => false, 'message' => 'Medication not found.'], 404);
    }

    // لو لنوع once  نحذفه باكامل
    if ($medication->repeat_type === 'once') {
        $medication->delete();
        return response()->json(['success' => true, 'message' => 'Medication deleted because it was one-time only.']);
    }

    // ل النع every_day → نحو لـ specific_days مع باقي الأيام
    if ($medication->repeat_type === 'every_day') {
        $allDays = ['saturday','sunday','monday','tuesday','wednesday','thursday','friday'];
        $remainingDays = array_filter($allDays, fn($d) => $d !== $request->day);

       
        MedicationDay::where('medication_id', $medication->id)->delete();

       
        foreach ($remainingDays as $day) {
            MedicationDay::create([
                'medication_id' => $medication->id,
                'day' => $day
            ]);
        }

    
        $medication->repeat_type = 'specific_days';
        $medication->save();

        return response()->json([
            'success' => true,
            'message' => 'Medication changed from every_day to specific_days, day removed.'
        ]);
    }

  
    $deleted = MedicationDay::where('medication_id', $medication->id)
        ->where('day', $request->day)
        ->delete();

    if ($deleted) {
        return response()->json(['success' => true, 'message' => 'Day removed from medication.']);
    }

    return response()->json(['success' => false, 'message' => 'Day not found for this medication.'], 404);
}



}
