<?php

namespace App\Modules\Medication\Services;

use App\Models\User;

use App\Models\Medication;
use App\Modules\Medication\Resources\MedicationCollection;
use App\Modules\Medication\Repositories\MedicationRepository;
use App\Modules\Medication\Repositories\MedicationsRepository;
use App\Modules\Medication\Requests\ListAllMedicationsRequest;

class MedicationService
{
    protected $repo;

    public function __construct(MedicationRepository $repo)
    {
        $this->repo = $repo;
    }

    public function create(array $data, User $user)
    {
        $medication = $this->repo->create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'notes' => $data['notes'] ?? null,
            'quantity_per_time' => $data['quantity_per_time'],
            'repeat_type' => $data['repeat_type'],
            'image_path' => isset($data['image']) ? $data['image']->store('medications', 'public') : null,
        ]);

        $this->repo->addTimes($medication, $data['times']);

        if (isset($data['days'])) {
            $this->repo->addDays($medication, $data['days']);
        }

        return $medication;
    }

   public function update(Medication $medication, array $data)
{
    // حذف العلاقات القديمة
    $medication->times()->delete();
    $medication->days()->delete();

    // تحديث بيانات الدواء نفسه
    $medication->update([
        'name' => $data['name'],
        'notes' => $data['notes'] ?? null,
        'quantity_per_time' => $data['quantity_per_time'],
        'repeat_type' => $data['repeat_type'],
        'image_path' => isset($data['image']) 
            ? $data['image']->store('medications', 'public') 
            : $medication->image_path, // لو ما في صورة جديدة، خلّي القديمة
    ]);

    // إضافة الأوقات الجديدة
    $this->repo->addTimes($medication, $data['times']);

    // إضافة الأيام الجديدة (لو موجودة)
    if (isset($data['days'])) {
        $this->repo->addDays($medication, $data['days']);
    }

    return $medication;
}

}
