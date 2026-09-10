<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property array<string, mixed> $state
 * @property array<string, mixed>|null $definition
 * @property int $state_revision
 * @property int $event_sequence
 * @property int $message_sequence
 * @property int $usage_sequence
 * @property string|null $provider_session_id
 * @property Model|null $owner
 */
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
            'definition' => 'array',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<AgentMessageRecord, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessageRecord::class, 'session_id');
    }

    /** @return HasMany<AgentUsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(AgentUsageRecord::class, 'session_id');
    }

    /** @return HasMany<AgentToolCallRecord, $this> */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCallRecord::class, 'session_id');
    }
}
