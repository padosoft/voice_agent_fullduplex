<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use Illuminate\Contracts\Config\Repository as Config;

final readonly class UsageCostCalculator
{
    public function __construct(private Config $config) {}

    /**
     * @param  array<string, int|float>  $units
     * @return array{amount: ?string, currency: string, status: string, pricing: ?array<string, mixed>}
     */
    public function estimate(string $provider, ?string $model, array $units): array
    {
        $catalog = (array) $this->config->get("realtime-agent.audit.pricing.{$provider}", []);
        $models = (array) ($catalog['models'] ?? []);
        $matchedModel = $model !== null && isset($models[$model]) ? $model : null;
        $aliases = (array) ($catalog['aliases'] ?? []);

        if ($matchedModel === null && $model !== null && isset($aliases[$model], $models[$aliases[$model]])) {
            $matchedModel = (string) $aliases[$model];
        }

        if ($model === null && isset($catalog['default_model'], $models[$catalog['default_model']])) {
            $matchedModel = (string) $catalog['default_model'];
        }

        if ($matchedModel === null) {
            return ['amount' => null, 'currency' => 'USD', 'status' => 'unpriced', 'pricing' => null];
        }

        $price = (array) $models[$matchedModel];
        $rates = (array) ($price['rates'] ?? []);
        $unitSize = max(1, (int) ($price['unit_size'] ?? 1_000_000));
        $billable = $units;

        foreach (['text', 'audio', 'image'] as $modality) {
            $input = "input_{$modality}_tokens";
            $cached = "cached_input_{$modality}_tokens";
            $billable[$input] = max(0, (float) ($units[$input] ?? 0) - (float) ($units[$cached] ?? 0));
        }

        $amount = 0.0;

        foreach ($rates as $unit => $rate) {
            $amount += ((float) ($billable[$unit] ?? 0) / $unitSize) * (float) $rate;
        }

        return [
            'amount' => number_format($amount, 8, '.', ''),
            'currency' => (string) ($price['currency'] ?? 'USD'),
            'status' => $provider === 'fake' ? 'final' : 'estimated',
            'pricing' => [
                'catalog_version' => (string) ($catalog['version'] ?? 'unknown'),
                'model' => $matchedModel,
                'observed_model' => $model,
                'effective_at' => $price['effective_at'] ?? null,
                'source' => $price['source'] ?? null,
                'unit_size' => $unitSize,
                'rates' => $rates,
            ],
        ];
    }
}
