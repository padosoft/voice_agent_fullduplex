<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Enums;

enum InteractionLevel: string
{
    case Observe = 'observe';
    case Guide = 'guide';
    case Operate = 'operate';
}
