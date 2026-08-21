<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class UseLocalViteOrigin
{
    public function __construct(private readonly Vite $vite) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldUseLocalOrigin($request, $response)) {
            return $response;
        }

        $viteOrigin = $this->viteOrigin();
        $content = $response->getContent();

        if ($viteOrigin === null || ! is_string($content)) {
            return $response;
        }

        $response->setContent(Str::replace($viteOrigin, $this->localOrigin($viteOrigin), $content));

        return $response;
    }

    private function shouldUseLocalOrigin(Request $request, Response $response): bool
    {
        return Str::is(['localhost', '127.0.0.1', '::1', '*.localhost'], Str::lower($request->getHost()))
            && Str::contains((string) $response->headers->get('Content-Type'), 'text/html')
            && $this->vite->isRunningHot();
    }

    private function viteOrigin(): ?string
    {
        $origin = file_get_contents($this->vite->hotFile());

        return is_string($origin) && filter_var(trim($origin), FILTER_VALIDATE_URL) !== false
            ? rtrim(trim($origin), '/')
            : null;
    }

    private function localOrigin(string $viteOrigin): string
    {
        $scheme = parse_url($viteOrigin, PHP_URL_SCHEME) ?: 'http';
        $port = parse_url($viteOrigin, PHP_URL_PORT);

        return $scheme.'://localhost'.(is_int($port) ? ":{$port}" : '');
    }
}
