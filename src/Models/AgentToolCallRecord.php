<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class AgentToolCallRecord extends Model
{
    use HasUlids;

    protected $table = 'realtime_agent_tool_calls';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result' => 'array',
            'error' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
