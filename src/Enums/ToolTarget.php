<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Enums;

enum ToolTarget: string
{
    case Server = 'server';
    case Client = 'client';
    case Ui = 'ui';
    case State = 'state';
    case Goal = 'goal';
}
