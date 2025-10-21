<?php

namespace App\Modules\Address\Repositories;
use Illuminate\Support\Facades\Storage;
use App\Models\Address;
use App\Modules\Shared\Repositories\BaseRepository;

class AddressesRepository extends BaseRepository
{
    public function __construct(private Address $model)
    {
        parent::__construct($model);
    }
    
 public function findAllBy($queryCriteria = [])
{
    $result = $this->executeGetMany($this->model->newQuery(), $queryCriteria);

    $addresses = collect($result['data']);

    $pharmacyUrl = \App\Models\Setting::first()?->map_location_url;

    if ($pharmacyUrl) {
        // تحقق إذا كان فيه عنوان default فعلاً
        $hasDefault = $addresses->contains(function ($address) {
            return $address->is_default == true;
        });

        $pharmacyAddress = new \App\Models\Address([
            'address'     => $pharmacyUrl,
            'type'        => 'pharmacy',
            'is_default'  => !$hasDefault, // لو مفيش أي default، نخلي ده هو
        ]);

        $pharmacyAddress->is_pharmacy = true;

        $addresses->push($pharmacyAddress);
    }

    return [
        'count' => $addresses->count(),
        'data'  => $addresses
    ];
}

    public function executeGetMany($query, $queryCriteria = [])
    {
        // $query هنا هو model أو query builder
        foreach ($queryCriteria['filters'] ?? [] as $filter) {
            $query->where(
                $filter['field'],
                $filter['operator'],
                $filter['value']
            );
        }

        foreach ($queryCriteria['sort'] ?? [] as $sort) {
            $query->orderBy(
                $sort['field'],
                $sort['direction'] ?? 'asc'
            );
        }

        $results = $query->get();

        return [
            'data' => $results,
            'count' => $results->count(),
        ];
    }



    
}