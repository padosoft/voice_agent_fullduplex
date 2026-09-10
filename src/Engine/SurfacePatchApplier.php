<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Engine;

use AgentsFullDuplex\RealtimeAgent\Exceptions\ToolCallRejected;

final class SurfacePatchApplier
{
    /**
     * @param  array<string, mixed>  $surface
     * @param  list<array<string, mixed>>  $patches
     * @return array<string, mixed>
     */
    public function apply(array $surface, array $patches): array
    {
        foreach ($patches as $patch) {
            $operation = (string) ($patch['op'] ?? '');
            $path = (string) ($patch['path'] ?? '');

            if (! in_array($operation, ['add', 'replace', 'remove'], true)) {
                throw new ToolCallRejected('A Surface patch operation is invalid.');
            }

            $segments = $this->segments($path);
            $this->guardPath($segments);

            if ($operation !== 'remove') {
                if (! array_key_exists('value', $patch)) {
                    throw new ToolCallRejected('A Surface patch value is required.');
                }

                $this->guardValue($patch['value']);
            }

            $cursor = &$surface;
            $last = array_pop($segments);

            foreach ($segments as $segment) {
                if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                    $cursor[$segment] = [];
                }

                $cursor = &$cursor[$segment];
            }

            if (! is_string($last) || $last === '') {
                throw new ToolCallRejected('A Surface patch path is invalid.');
            }

            if ($operation === 'remove') {
                unset($cursor[$last]);
            } else {
                $cursor[$last] = $patch['value'];
            }

            unset($cursor);
        }

        return $surface;
    }

    /** @return list<string> */
    private function segments(string $path): array
    {
        if (! str_starts_with($path, '/')) {
            throw new ToolCallRejected('A Surface patch must use a JSON Pointer path.');
        }

        return array_map(
            static fn (string $part): string => str_replace(['~1', '~0'], ['/', '~'], $part),
            explode('/', ltrim($path, '/')),
        );
    }

    /** @param list<string> $segments */
    private function guardPath(array $segments): void
    {
        if ($segments === ['title']) {
            return;
        }

        if (($segments[0] ?? null) !== 'components'
            || ! isset($segments[1])
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $segments[1])) {
            throw new ToolCallRejected('A Surface patch may only update semantic components or the title.');
        }

        if (isset($segments[2]) && ! in_array($segments[2], ['type', 'label', 'state', 'actions'], true)) {
            throw new ToolCallRejected('A Surface patch component field is not exposed.');
        }

        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), ['selector', 'script', 'code', 'html', 'javascript'], true)) {
                throw new ToolCallRejected('Executable Surface patch paths are not allowed.');
            }
        }
    }

    private function guardValue(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), ['selector', 'script', 'code', 'html', 'javascript'], true)) {
                throw new ToolCallRejected('Executable Surface patch values are not allowed.');
            }

            $this->guardValue($child);
        }
    }
}
