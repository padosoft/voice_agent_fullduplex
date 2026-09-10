<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Exceptions;

final class RevisionConflict extends RealtimeAgentException
{
    public static function between(int $expected, int $actual): self
    {
        return new self("State revision conflict: expected {$expected}, current revision is {$actual}.");
    }
}
