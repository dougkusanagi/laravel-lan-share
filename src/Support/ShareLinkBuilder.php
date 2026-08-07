<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

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

    public function sharePageUrl(string $applicationUrl): string
    {
        $path = trim((string) config('lan-share.share_page.path', '__lan-share'), '/');

        return rtrim($applicationUrl, '/').'/'.$path;
    }
}
