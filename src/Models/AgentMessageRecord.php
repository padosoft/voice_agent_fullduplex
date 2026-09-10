<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $session_id
 * @property int $seq
 * @property string $provider
 * @property string|null $provider_event_id
 * @property string $role
 * @property string $direction
 * @property string $modality
 * @property string $status
 * @property string $content
 * @property array<string, mixed> $metadata
 * @property CarbonImmutable $occurred_at
 */
final class AgentMessageRecord extends Model
{
    use HasUlids;

    protected $table = 'realtime_agent_messages';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AgentSessionRecord, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AgentSessionRecord::class, 'session_id');
    }
}
