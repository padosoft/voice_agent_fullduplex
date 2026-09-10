<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Policies;

use AgentsFullDuplex\RealtimeAgent\Contracts\FinishPolicyContract;
use AgentsFullDuplex\RealtimeAgent\Data\AgentState;

final class AllRequiredGoalsCompleted implements FinishPolicyContract
{
    public function shouldFinish(AgentState $state): bool
    {
        $required = array_filter(
            $state->goals(),
            static fn (array $goal): bool => ($goal['required'] ?? false) === true,
        );

        if ($required === []) {
            return false;
        }

        foreach ($required as $goal) {
            if (($goal['status'] ?? null) !== 'completed') {
                return false;
            }
        }

        return true;
    }
}
