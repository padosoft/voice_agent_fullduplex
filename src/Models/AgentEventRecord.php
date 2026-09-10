<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class AgentEventRecord extends Model
{
    use HasUlids;

    protected $table = 'realtime_agent_events';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
