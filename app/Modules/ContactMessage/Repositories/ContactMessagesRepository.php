<?php

namespace App\Modules\ContactMessage\Repositories;

use App\Models\ContactMessage;
use App\Modules\Shared\Repositories\BaseRepository;

class ContactMessagesRepository extends BaseRepository
{
    public function __construct(private ContactMessage $model)
    {
        parent::__construct($model);
    }

  
}