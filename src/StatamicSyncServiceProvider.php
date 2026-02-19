<?php

namespace MarceliTo\StatamicSync;

use Illuminate\Support\ServiceProvider;
use MarceliTo\StatamicSync\Console\PullCommand;

class StatamicSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/statamic-sync.php', 'statamic-sync');
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/statamic-sync.php' => config_path('statamic-sync.php'),
        ], 'statamic-sync-config');

        // Register routes (serving side)
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        // Register commands (pulling side)
        if ($this->app->runningInConsole()) {
            $this->commands([
                PullCommand::class,
            ]);
        }
    }
}
