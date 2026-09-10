<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class AgentSessionRecord extends Model
{
    use HasUlids;

    protected $table = 'realtime_agent_sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'metadata' => 'array',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
