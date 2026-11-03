<?php

namespace App\Jobs;

use App\Models\ExportRun;
use App\Modules\DataTransfer\ExportManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RunExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $runId)
    {
    }

    public function handle(ExportManager $exports): void
    {
        if ($run = ExportRun::find($this->runId)) {
            $exports->run($run);
        }
    }
}
