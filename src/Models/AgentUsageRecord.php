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
 * @property string $kind
 * @property string|null $model
 * @property array<string, int|float> $units
 * @property array<string, mixed> $raw
 * @property array<string, mixed>|null $pricing
 * @property string|null $amount
 * @property string $currency
 * @property string $status
 * @property CarbonImmutable $occurred_at
 */
final class AgentUsageRecord extends Model
{
    use HasUlids;

    protected $table = 'realtime_agent_usage';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'units' => 'array',
            'raw' => 'array',
            'pricing' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AgentSessionRecord, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AgentSessionRecord::class, 'session_id');
    }
}
