<?php

namespace App\Console\Commands;

use App\Modules\Admin\Authorization\RbacSynchronizer;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Copy the permissions the code checks into the database and make sure the system roles exist (run after deploying)';

    public function handle(RbacSynchronizer $synchronizer): int
    {
        $result = $synchronizer->sync();
        $this->info("Permissions created: {$result['created']}, refreshed: {$result['updated']}, deactivated: {$result['deactivated']}.");

        return self::SUCCESS;
    }
}
