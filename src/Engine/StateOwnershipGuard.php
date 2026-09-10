<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Exceptions\ToolCallRejected;

final class StateOwnershipGuard
{
    public function authorize(AgentSession $session, string $path, string $actor): void
    {
        $ownership = $session->definition->stateOwnership;
        $matchedPath = null;
        $expected = null;

        foreach ($ownership as $candidate => $owner) {
            $candidate = '/'.trim((string) $candidate, '/');

            if (($path === $candidate || str_starts_with($path, $candidate.'/'))
                && ($matchedPath === null || strlen($candidate) > strlen($matchedPath))) {
                $matchedPath = $candidate;
                $expected = $owner;
            }
        }

        if ($expected !== null && $expected !== $actor) {
            throw new ToolCallRejected("State path {$path} is owned by {$expected}, not {$actor}.");
        }
    }
}
