<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class DevicePairingService
{
    private const TOKEN_PREFIX = 'lan-share:pairing:token:';

    private const STATUS_PREFIX = 'lan-share:pairing:status:';

    /**
     * @return array{pairing_id: string, token: string, expires_at: int}
     */
    public function issue(int|string $userId): array
    {
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);
        $pairingId = (string) Str::uuid();
        $ttl = max(0, (int) config('lan-share.pairing.token_ttl', 180));
        $expiresAt = $ttl > 0 ? time() + $ttl : 0;
        $tokenPayload = [
            'user_id' => (string) $userId,
            'pairing_id' => $pairingId,
        ];

        if ($ttl === 0) {
            Cache::forever(self::tokenKey($tokenHash), $tokenPayload);
        } else {
            Cache::put(self::tokenKey($tokenHash), $tokenPayload, $ttl);
        }

        $statusPayload = [
            'state' => 'pending',
            'expires_at' => $expiresAt,
            'user_id' => (string) $userId,
            'token_hash' => $tokenHash,
        ];

        if ($ttl === 0) {
            Cache::forever(self::statusKey($pairingId), $statusPayload);
        } else {
            Cache::put(
                self::statusKey($pairingId),
                $statusPayload,
                max($ttl, (int) config('lan-share.pairing.status_ttl', 300)),
            );
        }

        return [
            'pairing_id' => $pairingId,
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{user_id: string, pairing_id: string}|null
     */
    public function consume(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);
        $tokenKey = self::tokenKey($tokenHash);

        /** @var array{user_id: string, pairing_id: string}|false|null $payload */
        $payload = Cache::lock(self::consumeLockKey($tokenHash), 5)->get(
            fn (): ?array => Cache::pull($tokenKey),
        );

        if ($payload === false || $payload === null) {
            return null;
        }

        Cache::put(self::statusKey($payload['pairing_id']), [
            'state' => 'consumed',
            'expires_at' => time(),
            'user_id' => $payload['user_id'],
        ], max(60, (int) config('lan-share.pairing.status_ttl', 300)));

        return $payload;
    }

    /**
     * @return array{state: 'pending'|'consumed'|'expired', expires_at?: int}
     */
    public function status(string $pairingId, int|string|null $userId = null): array
    {
        /** @var array{state: 'pending'|'consumed', expires_at?: int, user_id?: string, token_hash?: string}|null $status */
        $status = Cache::get(self::statusKey($pairingId));

        if ($status === null) {
            return ['state' => 'expired'];
        }

        if ($userId !== null && (string) ($status['user_id'] ?? '') !== (string) $userId) {
            return ['state' => 'expired'];
        }

        if ($status['state'] === 'pending'
            && isset($status['expires_at'])
            && $status['expires_at'] > 0
            && $status['expires_at'] <= time()) {
            Cache::forget(self::statusKey($pairingId));

            return ['state' => 'expired'];
        }

        unset($status['token_hash']);
        unset($status['user_id']);

        return $status;
    }

    public function revoke(string $pairingId, int|string|null $userId = null): void
    {
        /** @var array{state: 'pending'|'consumed', expires_at?: int, user_id?: string, token_hash?: string}|null $status */
        $status = Cache::get(self::statusKey($pairingId));

        if ($status === null || ($userId !== null && (string) ($status['user_id'] ?? '') !== (string) $userId)) {
            return;
        }

        $status = Cache::pull(self::statusKey($pairingId));

        if ($status === null) {
            return;
        }

        if (isset($status['token_hash'])) {
            Cache::forget(self::tokenKey($status['token_hash']));
        }
    }

    private static function tokenKey(string $tokenHash): string
    {
        return self::TOKEN_PREFIX.$tokenHash;
    }

    private static function consumeLockKey(string $tokenHash): string
    {
        return self::TOKEN_PREFIX.'consume:'.$tokenHash;
    }

    private static function statusKey(string $pairingId): string
    {
        return self::STATUS_PREFIX.$pairingId;
    }
}
