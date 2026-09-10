<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent;

use Stringable;

final readonly class Confirmation implements Stringable
{
    private function __construct(private string $policy) {}

    public static function never(): self
    {
        return new self('never');
    }

    public static function always(): self
    {
        return new self('always');
    }

    public static function required(): self
    {
        return self::always();
    }

    public function __toString(): string
    {
        return $this->policy;
    }
}
