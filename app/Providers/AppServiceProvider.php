<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Scan\ClassClassifier;
use App\Services\Scan\Parsers\BladeParser;
use App\Services\Scan\Parsers\MigrationParser;
use App\Services\Scan\Parsers\PhpFileAnalyzer;
use App\Services\Scan\Parsers\RouteParser;
use App\Services\Scan\ScanPipeline;
use App\Services\Scan\Stages\ClassParseStage;
use App\Services\Scan\Stages\ExtractStage;
use App\Services\Scan\Stages\FileIndexStage;
use App\Services\Scan\Stages\InsightsStage;
use App\Services\Scan\Stages\LayoutStage;
use App\Services\Scan\Stages\LinkStage;
use App\Services\Scan\Stages\ManifestStage;
use App\Services\Scan\Stages\ModelStage;
use App\Services\Scan\Stages\RouteStage;
use App\Services\Scan\Stages\SchemaStage;
use App\Services\Scan\Stages\ViewStage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The scanning pipeline is assembled explicitly so the order of stages
        // is impossible to get wrong and easy to read.
        $this->app->singleton(ScanPipeline::class, function ($app) {
            $classifier = new ClassClassifier;

            return new ScanPipeline([
                new ExtractStage,
                new ManifestStage,
                new FileIndexStage($classifier),
                new ClassParseStage(new PhpFileAnalyzer, $classifier),
                new RouteStage(new RouteParser),
                new ModelStage,
                new SchemaStage(new MigrationParser),
                new ViewStage(new BladeParser),
                new LinkStage,
                new InsightsStage,
                new LayoutStage,
            ]);
        });
    }

    public function boot(): void
    {
        // Scans can run for minutes; losing the worker to a memory limit is the
        // usual cause of a "stuck" atlas, so give them room.
        if ($this->app->runningInConsole()) {
            @ini_set('memory_limit', '1024M');
            @set_time_limit(0);
        }

        // Behind the sandbox proxy the app is served from a different host than
        // APP_URL, so trust the forwarded headers the proxy sends.
        if (! $this->app->runningInConsole()) {
            URL::forceScheme(str_starts_with((string) config('app.url'), 'https') ? 'https' : 'http');
        }
    }
}
