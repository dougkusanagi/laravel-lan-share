<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare;

use DougKusanagi\LaravelLanShare\Agent\WindowsAgentClient;
use DougKusanagi\LaravelLanShare\Console\Commands\LanShareCleanupCommand;
use DougKusanagi\LaravelLanShare\Console\Commands\LanShareCommand;
use DougKusanagi\LaravelLanShare\Console\Commands\LanShareAgentCommand;
use DougKusanagi\LaravelLanShare\Console\Commands\LanInstallCommand;
use DougKusanagi\LaravelLanShare\Console\Commands\LanUninstallCommand;
use DougKusanagi\LaravelLanShare\Http\SharePageController;
use DougKusanagi\LaravelLanShare\PowerShell\PowerShellScriptRenderer;
use DougKusanagi\LaravelLanShare\Support\ClipboardWriter;
use DougKusanagi\LaravelLanShare\Support\LanHostResolver;
use DougKusanagi\LaravelLanShare\Support\ManagedShareProcessMatcher;
use DougKusanagi\LaravelLanShare\Support\PortAllocator;
use DougKusanagi\LaravelLanShare\Support\PortAvailabilityProbe;
use DougKusanagi\LaravelLanShare\Support\PreviousShareProcessKiller;
use DougKusanagi\LaravelLanShare\Support\QrCodeRenderer;
use DougKusanagi\LaravelLanShare\Support\ScriptFileWriter;
use DougKusanagi\LaravelLanShare\Support\ShareLinkBuilder;
use DougKusanagi\LaravelLanShare\Support\StateKeyResolver;
use DougKusanagi\LaravelLanShare\Support\ViteConfigResolver;
use DougKusanagi\LaravelLanShare\Support\WindowsClipboardWriter;
use DougKusanagi\LaravelLanShare\Support\WindowsPortAvailabilityProbe;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class LanShareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lan-share.php', 'lan-share');

        $this->app->singleton(LanHostResolver::class);
        $this->app->singleton(PortAvailabilityProbe::class, WindowsPortAvailabilityProbe::class);
        $this->app->singleton(PortAllocator::class);
        $this->app->singleton(ManagedShareProcessMatcher::class);
        $this->app->singleton(PreviousShareProcessKiller::class);
        $this->app->singleton(PowerShellScriptRenderer::class);
        $this->app->singleton(QrCodeRenderer::class);
        $this->app->singleton(ScriptFileWriter::class);
        $this->app->singleton(StateKeyResolver::class);
        $this->app->singleton(ShareLinkBuilder::class);
        $this->app->singleton(WindowsAgentClient::class);
        $this->app->singleton(ClipboardWriter::class, WindowsClipboardWriter::class);
        $this->app->singleton(ViteConfigResolver::class);
    }

    public function boot(): void
    {
        if ((bool) config('lan-share.share_page.enabled', true) && $this->app->environment(['local', 'development', 'testing'])) {
            $path = trim((string) config('lan-share.share_page.path', '__lan-share'), '/');

            if ($path !== '') {
                Route::get($path, SharePageController::class)->name('lan-share.page');
            }
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            LanShareCommand::class,
            LanShareCleanupCommand::class,
            LanShareAgentCommand::class,
            LanInstallCommand::class,
            LanUninstallCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/lan-share.php' => config_path('lan-share.php'),
        ], 'lan-share-config');
    }
}
