<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ServeCommand;

/**
 * `php artisan serve`, with upload limits that actually reach the server.
 *
 * Plain `php artisan serve` accepts the standard 2 MB upload because it hands
 * your request to a *child* `php -S` process whose ini settings are PHP's
 * defaults — and `php -d upload_max_filesize=150M artisan serve` does not fix it,
 * because those flags die with the parent.
 *
 * This command puts the flags on the child invocation itself, which is the only
 * place they matter. Being pure PHP (no bash, no shell wrapper) it behaves the
 * same on Linux, macOS and Windows:
 *
 *      php artisan atlas:serve
 *      php artisan atlas:serve --port=8080
 *      ATLAS_UPLOAD_MAX=1G php artisan atlas:serve
 */
class AtlasServe extends ServeCommand
{
    protected $signature = 'atlas:serve
                    {--host= : The host address to serve the application on}
                    {--port= : The port to serve the application on}
                    {--tries=10 : The max number of ports to attempt to serve from}
                    {--no-reload : Do not reload the development server on .env file changes}';

    protected $description = 'Serve AtlasScope on the PHP development server, with real upload limits';

    /**
     * PHP's multipart limits, taken from bin/limits.env so the number the UI
     * advertises and the number the server enforces can never drift apart.
     */
    protected function serverCommand(): array
    {
        $server = file_exists(base_path('server.php'))
            ? base_path('server.php')
            : __DIR__.'/../../../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';

        $ini = [
            'upload_max_filesize' => $this->limit('ATLAS_UPLOAD_MAX', 'upload_max'),
            'post_max_size' => $this->limit('ATLAS_POST_MAX', 'post_max'),
            'memory_limit' => $this->limit('ATLAS_MEMORY_LIMIT', 'memory'),
        ];

        $flags = [];

        foreach ($ini as $key => $value) {
            $flags[] = '-d';
            $flags[] = $key.'='.$value;
        }

        return array_merge(
            // PHP_BINARY, not php_binary(): the latter is a Foundation helper
            // Laravel 13 does not autoload, so calling it here is a fatal
            // "Call to undefined function". PHP_BINARY is the constant that
            // helper returns, and it is always defined in the CLI SAPI.
            [PHP_BINARY],
            $flags,
            ['-S', $this->host().':'.$this->port(), $server],
        );
    }

    /** Environment wins, then bin/limits.env, then a sane default. */
    private function limit(string $env, string $key): string
    {
        $value = $_ENV[$env] ?? $_SERVER[$env] ?? getenv($env);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return (string) (\App\Support\Ini::devLimits()[$key] ?? '150M');
    }
}
