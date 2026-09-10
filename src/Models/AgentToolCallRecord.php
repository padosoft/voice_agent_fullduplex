<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $status
 * @property array<string, mixed>|null $result
 * @property string|null $provider_call_id
 * @property string $tool
 * @property array<string, mixed> $arguments
 * @property int $base_revision
 * @property int|null $state_revision_before
 * @property int|null $state_revision_after
 * @property string $authorization_status
 * @property string $confirmation_status
 * @property array<string, mixed>|null $error
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 */
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

    /** @return BelongsTo<AgentSessionRecord, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AgentSessionRecord::class, 'session_id');
    }
}
