<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare;

use DougKusanagi\LaravelLanShare\Console\Commands\LanShareCleanupCommand;
use DougKusanagi\LaravelLanShare\Console\Commands\LanShareCommand;
use DougKusanagi\LaravelLanShare\PowerShell\PowerShellScriptRenderer;
use DougKusanagi\LaravelLanShare\Support\ClipboardWriter;
use DougKusanagi\LaravelLanShare\Support\LanHostResolver;
use DougKusanagi\LaravelLanShare\Support\PortAllocator;
use DougKusanagi\LaravelLanShare\Support\PortAvailabilityProbe;
use DougKusanagi\LaravelLanShare\Support\QrCodeRenderer;
use DougKusanagi\LaravelLanShare\Support\ScriptFileWriter;
use DougKusanagi\LaravelLanShare\Support\WindowsClipboardWriter;
use DougKusanagi\LaravelLanShare\Support\WindowsPortAvailabilityProbe;
use Illuminate\Support\ServiceProvider;

final class LanShareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lan-share.php', 'lan-share');

        $this->app->singleton(LanHostResolver::class);
        $this->app->singleton(PortAvailabilityProbe::class, WindowsPortAvailabilityProbe::class);
        $this->app->singleton(PortAllocator::class);
        $this->app->singleton(PowerShellScriptRenderer::class);
        $this->app->singleton(QrCodeRenderer::class);
        $this->app->singleton(ScriptFileWriter::class);
        $this->app->singleton(ClipboardWriter::class, WindowsClipboardWriter::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            LanShareCommand::class,
            LanShareCleanupCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/lan-share.php' => config_path('lan-share.php'),
        ], 'lan-share-config');
    }
}
