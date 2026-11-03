<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Modules\DataTransfer\ImportManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Builds an import's preview (ImportManager::validate). Writes nothing but the run and its report. */
class ValidateImport implements ShouldQueue
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
            $imports->validate($run);
        }
    }
}
