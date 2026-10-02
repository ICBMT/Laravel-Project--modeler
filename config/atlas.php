<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AtlasScope configuration
    |--------------------------------------------------------------------------
    */

    /*
     * Hard ceiling for an uploaded archive (bytes).
     *
     * 150 MB is the natural limit, and it is written down in exactly one place:
     * bin/limits.env. bin/php gives the dev server the same value, so the
     * ceiling the UI advertises is the ceiling actually enforced.
     */
    'max_archive_bytes' => env('ATLAS_MAX_ARCHIVE_BYTES', App\Support\Ini::devBytes('upload_max')),

    /*
     * Ceiling for the *uncompressed* source tree, enforced while unpacking.
     *
     * Deliberately not the archive ceiling: source compresses roughly three to
     * four times, so a legible 150 MB archive can expand well past 150 MB. This
     * is a zip-bomb guard, not a size policy.
     */
    'max_extracted_bytes' => env('ATLAS_MAX_EXTRACTED_BYTES', 2_147_483_648),

    // Run scans inline instead of on the queue — handy for shared hosting and
    // for this demo environment where no worker is running. Keep this false in
    // production and run `php artisan queue:work` for a perfectly smooth UI.
    // `sync_scans` defaults to whether the queue driver itself is synchronous:
    // with the default driver a scan runs inline, and as soon as a real queue
    // connection (database, redis, …) is configured it is dispatched instead.
    'sync_scans' => env('ATLAS_SYNC_SCANS', env('QUEUE_CONNECTION', 'sync') === 'sync'),

    // Stream every scan step to the console when running from the CLI.
    'verbose_scans' => env('ATLAS_VERBOSE_SCANS', false),

    // How aggressively the 3D renderer may draw before it starts thinning edges.
    'max_render_nodes' => env('ATLAS_MAX_RENDER_NODES', 2500),
    'max_render_edges' => env('ATLAS_MAX_RENDER_EDGES', 6000),

    // Default view filters offered in the explorer.
    'default_layers' => ['entry', 'http', 'application', 'domain', 'data', 'view'],

    'demo_enabled' => env('ATLAS_DEMO_ENABLED', true),
];
