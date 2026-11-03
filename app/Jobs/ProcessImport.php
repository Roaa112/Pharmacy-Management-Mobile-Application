<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Modules\DataTransfer\ImportManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs a confirmed import. Not retried automatically: records already written would be checked
 * again as updates, so a retry is safe, but it is the admin's decision (upload the file again).
 */
class ProcessImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $runId)
    {
    }

    public function handle(ImportManager $imports): void
    {
        if ($run = ImportRun::find($this->runId)) {
            $imports->process($run);
        }
    }
}
