<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property string $provider_tool_id */
final class ProviderToolRecord extends Model
{
    use HasUlids;

    protected $table = 'realtime_agent_provider_tools';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
