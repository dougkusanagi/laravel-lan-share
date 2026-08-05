<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Support;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class PortAllocator
{
    /**
     * @param  Closure(int): bool|null  $availabilityProbe
     */
    public function __construct(private readonly ?Closure $availabilityProbe = null) {}

    /**
     * @param  list<int>  $reservedPorts
     */
    public function find(int $preferredPort, int $searchLimit = 20, array $reservedPorts = []): int
    {
        $this->validatePort($preferredPort);

        if ($searchLimit < 1) {
            throw new InvalidArgumentException('O limite de busca de portas deve ser maior que zero.');
        }

        for ($offset = 0; $offset < $searchLimit; $offset++) {
            $port = $preferredPort + $offset;

            if ($port > 65535 || in_array($port, $reservedPorts, true)) {
                continue;
            }

            if ($this->isAvailable($port)) {
                return $port;
            }
        }

        throw new RuntimeException(sprintf(
            'Não foi encontrada uma porta livre entre %d e %d.',
            $preferredPort,
            min(65535, $preferredPort + $searchLimit - 1),
        ));
    }

    private function isAvailable(int $port): bool
    {
        if ($this->availabilityProbe !== null) {
            return ($this->availabilityProbe)($port);
        }

        $server = @stream_socket_server(
            "tcp://127.0.0.1:{$port}",
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );

        if (! is_resource($server)) {
            return false;
        }

        fclose($server);

        return true;
    }

    private function validatePort(int $port): void
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("A porta {$port} não é válida.");
        }
    }
}
