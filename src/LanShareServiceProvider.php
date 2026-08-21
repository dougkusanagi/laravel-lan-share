<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare;

use DougKusanagi\LaravelLanShare\Agent\WindowsAgentClient;
use DougKusanagi\LaravelLanShare\Console\Commands\LanInstallCommand;
use DougKusanagi\LaravelLanShare\Console\Commands\LanShareAgentCommand;
use DougKusanagi\LaravelLanShare\Console\Commands\LanShareCleanupCommand;
use DougKusanagi\LaravelLanShare\Console\Commands\LanShareCommand;
use DougKusanagi\LaravelLanShare\Console\Commands\LanUninstallCommand;
use DougKusanagi\LaravelLanShare\Http\DevicePairingController;
use DougKusanagi\LaravelLanShare\Http\Middleware\UseLocalViteOrigin;
use DougKusanagi\LaravelLanShare\Http\PairingConnectPageController;
use DougKusanagi\LaravelLanShare\Http\SharePageController;
use DougKusanagi\LaravelLanShare\PowerShell\PowerShellScriptRenderer;
use DougKusanagi\LaravelLanShare\Support\ClipboardWriter;
use DougKusanagi\LaravelLanShare\Support\DevicePairingService;
use DougKusanagi\LaravelLanShare\Support\LanHostResolver;
use DougKusanagi\LaravelLanShare\Support\ManagedShareProcessMatcher;
use DougKusanagi\LaravelLanShare\Support\PackageManagerResolver;
use DougKusanagi\LaravelLanShare\Support\PortAllocator;
use DougKusanagi\LaravelLanShare\Support\PortAvailabilityProbe;
use DougKusanagi\LaravelLanShare\Support\PreviousShareProcessKiller;
use DougKusanagi\LaravelLanShare\Support\QrCodeRenderer;
use DougKusanagi\LaravelLanShare\Support\ScriptFileWriter;
use DougKusanagi\LaravelLanShare\Support\ShareLinkBuilder;
use DougKusanagi\LaravelLanShare\Support\StateKeyResolver;
use DougKusanagi\LaravelLanShare\Support\ViteConfigResolver;
use DougKusanagi\LaravelLanShare\Support\ViteLanConfigInstaller;
use DougKusanagi\LaravelLanShare\Support\WindowsClipboardWriter;
use DougKusanagi\LaravelLanShare\Support\WindowsPortAvailabilityProbe;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
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
        $this->app->singleton(PackageManagerResolver::class);
        $this->app->singleton(ManagedShareProcessMatcher::class);
        $this->app->singleton(PreviousShareProcessKiller::class);
        $this->app->singleton(PowerShellScriptRenderer::class);
        $this->app->singleton(QrCodeRenderer::class);
        $this->app->singleton(ScriptFileWriter::class);
        $this->app->singleton(StateKeyResolver::class);
        $this->app->singleton(ShareLinkBuilder::class);
        $this->app->singleton(WindowsAgentClient::class);
        $this->app->singleton(ClipboardWriter::class, WindowsClipboardWriter::class);
        $this->app->singleton(DevicePairingService::class);
        $this->app->singleton(ViteConfigResolver::class);
        $this->app->singleton(ViteLanConfigInstaller::class);
    }

    public function boot(): void
    {
        if ($this->app->environment(['local', 'development', 'testing'])) {
            $this->app->make(HttpKernel::class)->appendMiddlewareToGroup('web', UseLocalViteOrigin::class);
        }

        if ((bool) config('lan-share.share_page.enabled', true) && $this->app->environment(['local', 'development', 'testing'])) {
            $path = trim((string) config('lan-share.share_page.path', '__lan-share'), '/');

            if ($path !== '') {
                Route::middleware('web')->group(function () use ($path): void {
                    Route::get($path, SharePageController::class)->name('lan-share.page');

                    if ((bool) config('lan-share.pairing.enabled', true)) {
                        Route::post($path.'/pairing', [DevicePairingController::class, 'issue'])
                            ->middleware('auth')
                            ->name('lan-share.pairing.issue');
                        Route::get($path.'/pairing/{pairingId}/status', [DevicePairingController::class, 'status'])
                            ->middleware('auth')
                            ->name('lan-share.pairing.status');
                        Route::delete($path.'/pairing/{pairingId}', [DevicePairingController::class, 'revoke'])
                            ->middleware('auth')
                            ->name('lan-share.pairing.revoke');
                        Route::get($path.'/connect', PairingConnectPageController::class)
                            ->name('lan-share.pairing.connect');
                        Route::post($path.'/connect', [DevicePairingController::class, 'connect'])
                            ->name('lan-share.pairing.consume');
                    }
                });
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
