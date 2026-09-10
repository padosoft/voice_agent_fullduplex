<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Contracts;

use AgentsFullDuplex\RealtimeAgent\Data\AgentDefinition;
use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ClientConnectionDescriptor;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderCapabilities;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderSession;
use AgentsFullDuplex\RealtimeAgent\Data\ProviderToolSet;

interface RealtimeProviderContract
{
    public function capabilities(): ProviderCapabilities;

    public function prepare(AgentSession $session, AgentDefinition $definition): ProviderSession;

    public function clientConnection(AgentSession $session): ClientConnectionDescriptor;

    /** @param iterable<\AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition> $tools */
    public function materializeTools(AgentSession $session, iterable $tools): ProviderToolSet;
}
