<?php

namespace App\Modules\Setting\Repositories;

use App\Models\Setting;
use App\Modules\Shared\Repositories\BaseRepository;

class SettingsRepository extends BaseRepository
{
    public function __construct(private Setting $model)
    {
        parent::__construct($model);
    }
}