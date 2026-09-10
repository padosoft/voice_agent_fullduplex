<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Enums;

enum GoalStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';
}
