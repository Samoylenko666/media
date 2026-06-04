<?php

namespace Jurager\Media;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Jurager\Media\Console\Commands\MediaCleanCommand;
use Jurager\Media\Console\Commands\MediaPruneConversionsCommand;
use Jurager\Media\Console\Commands\MediaRegenerateCommand;
use Jurager\Media\Models\Media;
use Jurager\Media\Support\ConverterRegistry;
use Jurager\Media\Support\FileAdder;
use Jurager\Media\Support\FileProcessorRegistry;
use Jurager\Media\Support\PathGenerator;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/media.php', 'media');

        $this->app->singleton(ConverterRegistry::class, function () {
            $registry = new ConverterRegistry;

            foreach (config('media.converters', []) as $mime => $class) {
                $registry->register($mime, $class);
            }

            return $registry;
        });

        $this->app->singleton(FileProcessorRegistry::class, function () {
            $registry = new FileProcessorRegistry;

            foreach (config('media.processors', []) as $mime => $class) {
                $registry->register($mime, $class);
            }

            return $registry;
        });

        $this->app->bind(FileAdder::class);

        // Bind to the configured subclass so every app(PathGenerator::class) call
        // returns the right implementation without repeating config() lookups.
        $this->app->bind(PathGenerator::class, config('media.path_generator', PathGenerator::class));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::model('media', config('media.models.media', Media::class));

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/media.php' => config_path('media.php'),
            ], 'media-config');

            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'media-migrations');

            $this->commands([
                MediaCleanCommand::class,
                MediaRegenerateCommand::class,
                MediaPruneConversionsCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $time = config('media.clean_schedule');

            if ($time) {
                $schedule->command('media:clean')->dailyAt($time);
            }
        });
    }
}
