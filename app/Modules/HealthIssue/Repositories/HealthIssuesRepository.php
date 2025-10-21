<?php

namespace App\Modules\HealthIssue\Repositories;

use App\Models\HealthIssue;
use App\Modules\Shared\Repositories\BaseRepository;

class HealthIssuesRepository extends BaseRepository
{
    public function __construct(private HealthIssue $model)
    {
        parent::__construct($model);
    }
}