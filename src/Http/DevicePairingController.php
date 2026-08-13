<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Http;

use DougKusanagi\LaravelLanShare\Support\DevicePairingService;
use DougKusanagi\LaravelLanShare\Support\QrCodeRenderer;
use DougKusanagi\LaravelLanShare\Support\ShareLinkBuilder;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

final class DevicePairingController
{
    public function __construct(
        private readonly DevicePairingService $pairings,
        private readonly QrCodeRenderer $qrCodeRenderer,
        private readonly ShareLinkBuilder $links,
    ) {}

    public function issue(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'É necessário estar autenticado.'], 401);
        }

        $password = (string) $request->input('password', '');
        $rateLimitKey = $this->rateLimitKey($request, $user->getAuthIdentifier());
        $maxAttempts = max(1, (int) config('lan-share.pairing.max_password_attempts', 5));
        $decaySeconds = max(30, (int) config('lan-share.pairing.rate_limit_seconds', 60));

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            return response()->json([
                'message' => 'Muitas tentativas. Aguarde um instante e tente novamente.',
                'retry_after' => RateLimiter::availableIn($rateLimitKey),
            ], 429);
        }

        if ($password === '' || ! Hash::check($password, (string) $user->getAuthPassword())) {
            RateLimiter::hit($rateLimitKey, $decaySeconds);

            return response()->json(['message' => 'A senha não confere.'], 422);
        }

        RateLimiter::clear($rateLimitKey);

        $pairing = $this->pairings->issue($user->getAuthIdentifier());
        $connectUrl = $this->links->pairingConnectUrl($this->applicationUrl($request), $pairing['token']);

        return response()->json([
            'pairing_id' => $pairing['pairing_id'],
            'connect_url' => $connectUrl,
            'qr_svg' => $this->qrCodeRenderer->renderSvg($connectUrl),
            'expires_at' => $pairing['expires_at'],
            'ttl_seconds' => max(0, $pairing['expires_at'] - time()),
        ]);
    }

    public function status(Request $request, string $pairingId): JsonResponse
    {
        if ($request->user() === null) {
            return response()->json(['message' => 'É necessário estar autenticado.'], 401);
        }

        return response()->json($this->pairings->status($pairingId, $request->user()->getAuthIdentifier()));
    }

    public function revoke(Request $request, string $pairingId): JsonResponse
    {
        if ($request->user() === null) {
            return response()->json(['message' => 'É necessário estar autenticado.'], 401);
        }

        $this->pairings->revoke($pairingId, $request->user()->getAuthIdentifier());

        return response()->json([], 204);
    }

    public function connect(Request $request): JsonResponse
    {
        $token = trim((string) $request->input('token', ''));
        $rateLimitKey = 'lan-share:pairing:connect:'.$request->ip();
        $maxAttempts = max(10, (int) config('lan-share.pairing.max_connect_attempts', 30));
        $decaySeconds = max(30, (int) config('lan-share.pairing.connect_rate_limit_seconds', 60));

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            return response()->json([
                'message' => 'Muitas tentativas. Aguarde um instante e tente novamente.',
                'retry_after' => RateLimiter::availableIn($rateLimitKey),
            ], 429);
        }

        if ($token === '' || strlen($token) < 32) {
            RateLimiter::hit($rateLimitKey, $decaySeconds);

            return response()->json(['message' => 'Este QR Code é inválido ou expirou.'], 410);
        }

        $pairing = $this->pairings->consume($token);

        if ($pairing === null) {
            RateLimiter::hit($rateLimitKey, $decaySeconds);

            return response()->json(['message' => 'Este QR Code é inválido ou já foi utilizado.'], 410);
        }

        RateLimiter::clear($rateLimitKey);

        $guard = auth()->guard();
        $currentUserId = $guard->id();

        if (! $guard instanceof StatefulGuard) {
            return response()->json(['message' => 'O guard de sessão não está disponível.'], 503);
        }

        if ($currentUserId !== null && (string) $currentUserId !== $pairing['user_id']) {
            return response()->json([
                'message' => 'Este dispositivo já está conectado a outra conta. Saia da conta atual e tente novamente.',
            ], 409);
        }

        if ($currentUserId !== null) {
            return response()->json([
                'message' => 'Dispositivo conectado com sucesso.',
                'redirect_url' => '/',
            ]);
        }

        $user = $guard->loginUsingId($pairing['user_id']);

        if ($user === false) {
            return response()->json(['message' => 'Não foi possível localizar esta conta.'], 410);
        }

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Dispositivo conectado com sucesso.',
            'redirect_url' => '/',
        ]);
    }

    private function applicationUrl(Request $request): string
    {
        $configuredUrl = trim((string) config('app.url', ''));

        return rtrim($configuredUrl !== '' ? $configuredUrl : $request->getSchemeAndHttpHost(), '/');
    }

    private function rateLimitKey(Request $request, mixed $userId): string
    {
        return 'lan-share:pairing:password:'.(string) $userId.':'.$request->ip();
    }
}
