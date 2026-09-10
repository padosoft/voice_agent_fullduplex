<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Contracts;

use AgentsFullDuplex\RealtimeAgent\Data\ConversationMessage;

interface ConversationStoreContract
{
    /** @param array<string, mixed> $message */
    public function record(string $sessionId, array $message): ConversationMessage;

    /** @return list<ConversationMessage> */
    public function all(string $sessionId): array;
}
