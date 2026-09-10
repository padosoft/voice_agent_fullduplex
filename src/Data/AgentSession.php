<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

use AgentsFullDuplex\RealtimeAgent\Contracts\StateStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolBrokerContract;

class AgentSession
{
    public function __construct(
        public readonly string $id,
        public readonly AgentDefinition $definition,
        public readonly mixed $owner,
        private readonly StateStoreContract $states,
        private readonly ToolBrokerContract $tools,
        private ?ClientConnectionDescriptor $connection = null,
    ) {}

    public function state(): AgentState
    {
        return $this->states->get($this->id);
    }

    public function setConnection(ClientConnectionDescriptor $connection): void
    {
        $this->connection = $connection;
    }

    public function connection(): ClientConnectionDescriptor
    {
        if ($this->connection === null) {
            throw new \LogicException('The provider connection has not been prepared.');
        }

        return $this->connection;
    }

    public function execute(ToolCall $call): ToolResult
    {
        return $this->tools->execute($this, $call);
    }
}
