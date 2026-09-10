<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $session_id
 * @property int $seq
 * @property string $type
 * @property string $source
 * @property array<string, mixed> $payload
 * @property int $state_revision
 * @property string|null $provider_event_id
 * @property Carbon $created_at
 */
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
