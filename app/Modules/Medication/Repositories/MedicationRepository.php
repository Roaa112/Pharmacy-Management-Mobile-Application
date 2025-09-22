<?php

namespace App\Modules\Medication\Repositories;

use App\Models\Medication;
use Illuminate\Database\Eloquent\Model;
use App\Modules\Shared\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
class MedicationRepository extends BaseRepository
{
    public function __construct(private Medication $model)
    {
        parent::__construct($model);
    }


    
public function create($attributes): Model
    {
        return Medication::create($attributes);
    }

    public function addTimes(Medication $medication, array $times)
    {
        foreach ($times as $time) {
            $medication->times()->create(['time' => $time]);
        }
    }

    public function addDays(Medication $medication, array $days)
    {
        foreach ($days as $day) {
            $medication->days()->create(['day' => $day]);
        }
    }

 public function update($id, array $attributes)
{
    $medication = Medication::findOrFail($id);
    $medication->update($attributes);
    return $medication;
}
    public function delete($id)
{
    $medication = Medication::findOrFail($id);
    return $medication->delete();
}


}