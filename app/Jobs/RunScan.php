<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Services\Scan\ScanContext;
use App\Services\Scan\ScanPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs the whole scanning pipeline for one project.
 *
 * The scan itself is chunked and idempotent: progress is written to the scan
 * row after every stage, so the browser can watch it live, and a failure leaves
 * enough breadcrumbs to see exactly which stage broke.
 */
class RunScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly int $scanId,
        public readonly bool $verbose = false,
    ) {
        $this->onQueue('atlas');
    }

    public function handle(ScanPipeline $pipeline): void
    {
        $scan = Scan::with('project')->find($this->scanId);

        if ($scan === null) {
            return;
        }

        $project = $scan->project;

        $context = new ScanContext(
            scan: $scan,
            project: $project,
            root: $project->sourcePath(),
            verbose: $this->verbose,
        );

        $pipeline->run($context);
    }

    public function failed(Throwable $exception): void
    {
        Scan::where('id', $this->scanId)->update([
            'status' => ScanStatus::Failed->value,
            'error' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
