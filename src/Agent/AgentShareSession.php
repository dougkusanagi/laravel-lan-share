<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\Agent;

use DougKusanagi\LaravelLanShare\Support\LanSharePlan;

final readonly class AgentShareSession
{
    public function __construct(
        public string $sessionId,
        public string $projectId,
        public LanSharePlan $plan,
        public string $lanIp,
        public string $wslIp,
    ) {}
}
