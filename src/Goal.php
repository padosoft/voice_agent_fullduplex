<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent;

use AgentsFullDuplex\RealtimeAgent\Data\GoalDefinition;

final class Goal
{
    public static function make(string $id): GoalDefinition
    {
        return GoalDefinition::make($id);
    }
}
