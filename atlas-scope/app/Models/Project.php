<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScanStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'name', 'source_type', 'source_ref', 'root_path', 'archive_size',
        'file_count', 'loc', 'framework_version', 'php_constraint', 'composer_name',
        'composer_description', 'package_count', 'meta', 'last_scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'last_scanned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $project) {
            $project->uuid ??= (string) Str::uuid();
        });
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class)->latest('id');
    }

    public function latestScan(): HasOne
    {
        return $this->hasOne(Scan::class)->latestOfMany();
    }

    public function latestCompletedScan(): HasOne
    {
        return $this->hasOne(Scan::class)->ofMany([
            'id' => 'max',
        ], fn ($query) => $query->where('status', ScanStatus::Completed->value));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Absolute path to where the sources live on disk. */
    public function sourcePath(string $append = ''): string
    {
        return rtrim($this->root_path, '/').($append !== '' ? '/'.ltrim($append, '/') : '');
    }

    /** Private working directory used for stage artefacts. */
    public function workspacePath(string $append = ''): string
    {
        $base = storage_path('app/atlas/'.$this->uuid);

        return $append !== '' ? $base.'/'.ltrim($append, '/') : $base;
    }

    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : 'Untitled project';
    }

    public function sizeForHumans(): string
    {
        $bytes = max($this->archive_size, 1);
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = (int) min(floor(log($bytes, 1024)), 3);

        return round($bytes / (1024 ** $power), $power > 1 ? 1 : 0).' '.$units[$power];
    }
}
