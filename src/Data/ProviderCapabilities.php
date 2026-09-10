<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Data;

final readonly class ProviderCapabilities
{
    public function __construct(
        public bool $audioFullDuplex,
        public bool $clientWebRtc,
        public bool $clientWebSocket,
        public bool $dynamicInlineTools,
        public bool $nativeContextUpdates,
        public bool $clientTools,
        public bool $serverSideControl,
    ) {
    }
}
