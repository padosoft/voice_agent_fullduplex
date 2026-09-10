<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Events;

use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use AgentsFullDuplex\RealtimeAgent\Data\ConversationMessage;

final readonly class AgentMessageRecorded
{
    public function __construct(
        public AgentSession $session,
        public ConversationMessage $message,
    ) {}
}
