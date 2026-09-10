<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Contracts;

use AgentsFullDuplex\RealtimeAgent\Data\ToolDefinition;

interface ToolContract
{
    public function definition(): ToolDefinition;
}
