<?php

namespace App\Console\Commands;

use App\Jobs\ProcessImportJob;
use App\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class RecoverStaleImportsCommand extends Command implements Isolatable
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:recover-stale
                            {--timeout= : Inactivity threshold in minutes before an import is considered stale (default from config)}
                            {--fail-only : Mark stale imports as Failed instead of attempting resumption}
                            {--limit=50 : Maximum number of stale imports to inspect per execution}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect and recover stuck Excel imports after queue worker interruptions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $timeoutMinutes = (int) ($this->option('timeout') ?: config('excel_import.stale_timeout_minutes', 15));
        if ($timeoutMinutes < 1) {
            $timeoutMinutes = 15;
        }

        $limit = max(1, (int) $this->option('limit'));
        $cutoff = now()->subMinutes($timeoutMinutes);

        $this->info("Scanning for imports in Processing state inactive since {$cutoff->toDateTimeString()} (timeout: {$timeoutMinutes}m)...");

        // Query only in-progress imports that have had no updates since the cutoff
        $staleImports = Import::where('status', Import::STATUS_PROCESSING)
            ->where('updated_at', '<', $cutoff)
            ->limit($limit)
            ->get();

        if ($staleImports->isEmpty()) {
            $this->info('No stale imports detected.');

            return self::SUCCESS;
        }

        $resumedCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        $importDisk = config('excel_import.disk', 'local');

        foreach ($staleImports as $import) {
            // Check if there is an active running job in the queue
            if ($this->hasActiveQueueJob($import->id)) {
                $this->line("Import [{$import->id}] has an active queue reservation. Skipping.");
                $skippedCount++;
                continue;
            }

            // Verify if spreadsheet file still exists on disk
            $fileExists = Storage::disk($importDisk)->exists($import->file_path);

            if (! $fileExists) {
                // Cannot resume without source file; mark failed with accurate duration
                $reason = 'Worker process terminated unexpectedly; spreadsheet file missing from disk.';
                $import->markFailed($reason);

                Log::warning("RecoverStaleImports: Import [{$import->id}] marked as Failed because source file is missing.");
                $this->warn("Import [{$import->id}] marked as Failed (file missing).");
                $failedCount++;
                continue;
            }

            if ($this->option('fail-only')) {
                $reason = 'Worker process terminated unexpectedly; marked as failed by recovery policy.';
                $import->markFailed($reason);

                Log::info("RecoverStaleImports: Import [{$import->id}] marked as Failed via --fail-only policy.");
                $this->warn("Import [{$import->id}] marked as Failed (policy).");
                $failedCount++;
                continue;
            }

            // Safe to resume: update timestamp atomically and re-dispatch
            // Chunk idempotency in ProcessImportJob guarantees completed chunks are skipped without double counting
            $import->touch();
            ProcessImportJob::dispatch($import->id);

            Log::info("RecoverStaleImports: Import [{$import->id}] re-dispatched for processing resumption.");
            $this->info("Import [{$import->id}] re-dispatched successfully.");
            $resumedCount++;
        }

        $this->info("Recovery complete. Resumed: {$resumedCount}, Failed: {$failedCount}, Skipped: {$skippedCount}.");

        return self::SUCCESS;
    }

    /**
     * Check if a database queue job is currently actively reserved and within its retry window.
     */
    protected function hasActiveQueueJob(int $importId): bool
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return false;
        }

        $retryAfter = (int) config('queue.connections.database.retry_after', 90);
        $recentReservationCutoff = time() - $retryAfter;

        // Check if job exists in database jobs table and was reserved recently
        return DB::table('jobs')
            ->where(function ($query) use ($importId) {
                $query->where('payload', 'like', '%"importId":'.$importId.'%')
                    ->orWhere('payload', 'like', '%"importId":'.$importId.',%')
                    ->orWhere('payload', 'like', '%"importId";i:'.$importId.';%');
            })
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '>', $recentReservationCutoff)
            ->exists();
    }
}
