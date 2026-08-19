<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

use InvalidArgumentException;

final class ShareLinkBuilder
{
    public function availabilityMessage(): string
    {
        return trim((string) config(
            'lan-share.sharing.availability_message',
            'Disponível somente para dispositivos conectados à mesma rede local.',
        ));
    }

    public function message(string $projectName, string $url): string
    {
        return implode("\n", array_filter([
            "Acesse $projectName:",
            $url,
        ]));
    }

    public function whatsAppUrl(string $projectName, string $url): string
    {
        return 'https://wa.me/?'.http_build_query(
            ['text' => $this->message($projectName, $url)],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    public function applicationUrl(string $baseUrl, ?string $target = null): string
    {
        return rtrim($baseUrl, '/').($this->normalizeTarget($target) ?? '');
    }

    public function sharePageUrl(string $applicationUrl, ?string $target = null): string
    {
        $path = trim((string) config('lan-share.share_page.path', '__lan-share'), '/');
        $url = rtrim($applicationUrl, '/').'/'.$path;

        return $this->appendTarget($url, $target);
    }

    public function pairingConnectUrl(string $applicationUrl, string $token, ?string $target = null): string
    {
        $url = $this->sharePageUrl($applicationUrl).'/connect';
        $url = $this->appendTarget($url, $target);

        return $url.'#token='.rawurlencode($token);
    }

    /**
     * Normalize a relative application URL while preventing redirects to a
     * different host. Absolute HTTP(S) URLs are accepted for convenience, but
     * only their path, query string, and fragment are used.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeTarget(?string $target): ?string
    {
        $target = trim((string) $target);

        if ($target === '') {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $target) === 1 || str_starts_with($target, '//')) {
            throw new InvalidArgumentException('A URL de destino informada não é válida.');
        }

        $parts = parse_url($target);

        if ($parts === false) {
            throw new InvalidArgumentException('A URL de destino informada não é válida.');
        }

        if (isset($parts['scheme']) && ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('A URL de destino deve ser um caminho local ou uma URL HTTP(S).');
        }

        $path = (string) ($parts['path'] ?? '');
        $path = $path === '' ? '/' : $path;

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        if (array_key_exists('query', $parts)) {
            $path .= '?'.(string) $parts['query'];
        }

        if (array_key_exists('fragment', $parts)) {
            $path .= '#'.(string) $parts['fragment'];
        }

        return $path;
    }

    private function appendTarget(string $url, ?string $target): string
    {
        $normalizedTarget = $this->normalizeTarget($target);

        if ($normalizedTarget === null) {
            return $url;
        }

        return $url.'?'.http_build_query(
            ['url' => $normalizedTarget],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }
}
