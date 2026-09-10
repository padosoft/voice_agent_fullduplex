<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Contracts;

use AgentsFullDuplex\RealtimeAgent\Data\AgentState;

interface FinishPolicyContract
{
    public function shouldFinish(AgentState $state): bool;
}
