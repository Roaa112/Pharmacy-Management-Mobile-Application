<?php

namespace App\Console\Commands;

use App\Support\PrivateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Copies payment receipts, return photos and medication photos uploaded before they became
 * private from the public disk to the private disk. Paths in the database do not change.
 *
 * By default the public copies are left in place (run once, check, then run again with
 * --delete-public to remove them). --dry-run only reports.
 */
class MakeFilesPrivate extends Command
{
    protected $signature = 'files:make-private {--delete-public : remove the public copy after it was copied} {--dry-run}';

    protected $description = 'Move sensitive uploads from the public disk to the private disk';

    public function handle(): int
    {
        $paths = DB::table('orders')->whereNotNull('payment_image')->pluck('payment_image')
            ->merge(DB::table('return_request_images')->whereNotNull('path')->pluck('path'))
            ->merge(DB::table('medications')->whereNotNull('image_path')->pluck('image_path'))
            ->filter()->unique();

        $public = Storage::disk(PrivateFiles::LEGACY_DISK);
        $private = Storage::disk(PrivateFiles::PRIVATE_DISK);
        $copied = $removed = $missing = 0;

        foreach ($paths as $path) {
            if (!$public->exists($path)) {
                $private->exists($path) || $missing++;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("would move: {$path}");
                continue;
            }

            if (!$private->exists($path)) {
                $private->put($path, $public->get($path));
                $copied++;
            }

            if ($this->option('delete-public') && $private->exists($path) && $private->size($path) === $public->size($path)) {
                $public->delete($path);
                $removed++;
            }
        }

        $this->info("Copied {$copied}, removed from public {$removed}, missing {$missing}.");

        return self::SUCCESS;
    }
}
