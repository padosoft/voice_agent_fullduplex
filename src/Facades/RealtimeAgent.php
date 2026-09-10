<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Facades;

use AgentsFullDuplex\RealtimeAgent\Engine\AgentDefinitionBuilder;
use Illuminate\Support\Facades\Facade;

/**
 * @method static AgentDefinitionBuilder make(string $key)
 */
final class RealtimeAgent extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'realtime-agent';
    }
}
