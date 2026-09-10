<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tests\Unit;

use AgentsFullDuplex\RealtimeAgent\Engine\JsonSchemaValidator;
use AgentsFullDuplex\RealtimeAgent\Exceptions\ToolCallRejected;
use PHPUnit\Framework\TestCase;

final class JsonSchemaValidatorTest extends TestCase
{
    public function test_rejects_missing_required_arguments(): void
    {
        $this->expectException(ToolCallRejected::class);

        (new JsonSchemaValidator())->validate([
            'type' => 'object',
            'properties' => ['goal_id' => ['type' => 'string']],
            'required' => ['goal_id'],
        ], []);
    }
}
