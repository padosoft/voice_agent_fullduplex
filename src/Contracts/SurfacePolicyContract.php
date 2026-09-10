<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Contracts;

use AgentsFullDuplex\RealtimeAgent\Data\UiCommand;

interface SurfacePolicyContract
{
    public function canObserve(mixed $user, string $surface, string $component): bool;

    public function canAct(mixed $user, UiCommand $action): bool;
}
