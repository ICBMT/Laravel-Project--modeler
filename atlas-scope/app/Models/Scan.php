<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScanStage;
use App\Enums\ScanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scan extends Model
{
    protected $fillable = [
        'project_id', 'status', 'stage', 'progress', 'stages', 'metrics', 'error',
        'file_count', 'node_count', 'edge_count', 'duration_ms', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'stages' => 'array',
            'metrics' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => ScanStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $scan) {
            if ($scan->stages === null) {
                $scan->stages = collect(ScanStage::pipeline())
                    ->mapWithKeys(fn (ScanStage $stage) => [$stage->value => [
                        'status' => 'pending',
                        'ms' => 0,
                        'summary' => null,
                    ]])
                    ->all();
                $scan->saveQuietly();
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ScanEvent::class)->orderBy('id');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(GraphNode::class);
    }

    public function edges(): HasMany
    {
        return $this->hasMany(GraphEdge::class);
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function stageEnum(): ScanStage
    {
        return ScanStage::tryFrom($this->stage) ?? ScanStage::Extract;
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    public function isRunning(): bool
    {
        return $this->status === ScanStatus::Running;
    }

    public function stageStatus(string $stage): array
    {
        return $this->stages[$stage] ?? ['status' => 'pending', 'ms' => 0, 'summary' => null];
    }

    public function markStage(ScanStage $stage, string $status, array|string|null $summary = null, int $ms = 0): void
    {
        $stages = $this->stages ?? [];
        $stages[$stage->value] = [
            'status' => $status,
            'ms' => $ms ?: ($stages[$stage->value]['ms'] ?? 0),
            'summary' => $summary ?? ($stages[$stage->value]['summary'] ?? null),
        ];

        $this->stages = $stages;
        $this->stage = $stage->value;
        $this->progress = $this->computeProgress($stages);
        $this->saveQuietly();
    }

    private function computeProgress(array $stages): int
    {
        $earned = 0;
        $total = 0;

        foreach (ScanStage::pipeline() as $stage) {
            $weight = $stage->weight();
            $total += $weight;

            $status = $stages[$stage->value]['status'] ?? 'pending';

            if ($status === 'done') {
                $earned += $weight;
            } elseif ($status === 'running') {
                $earned += (int) ($weight * 0.45);
            }
        }

        return (int) min(100, round($earned / max($total, 1) * 100));
    }

    /** Human readable payload consumed by the scanning UI. */
    public function toScanPayload(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'stage' => $this->stage,
            // A finished scan keeps saying "Projecting into 3D space" from its
            // last stage, which reads oddly on a report page — the status is
            // what matters once the pipeline has stopped.
            'stage_label' => match ($this->status) {
                ScanStatus::Completed => 'Complete',
                ScanStatus::Failed => 'Failed',
                ScanStatus::Queued => 'Waiting for a worker',
                default => $this->stageEnum()->label(),
            },
            'progress' => $this->status === ScanStatus::Completed ? 100 : (int) $this->progress,
            'stages' => collect(ScanStage::pipeline())->map(fn (ScanStage $stage) => [
                'key' => $stage->value,
                'label' => $stage->label(),
                'description' => $stage->description(),
                'icon' => $stage->icon(),
                'status' => $this->stageStatus($stage->value)['status'],
                'ms' => $this->stageStatus($stage->value)['ms'],
                'summary' => $this->stageStatus($stage->value)['summary'],
            ])->values(),
            'counts' => [
                'files' => (int) $this->file_count,
                'nodes' => (int) $this->node_count,
                'edges' => (int) $this->edge_count,
            ],
            'metrics' => $this->metrics,
            'error' => $this->error,
            'duration_ms' => (int) $this->duration_ms,
            'project' => $this->relationLoaded('project') ? [
                'uuid' => $this->project->uuid,
                'name' => $this->project->displayName(),
                'framework_version' => $this->project->framework_version,
            ] : null,
        ];
    }
}
