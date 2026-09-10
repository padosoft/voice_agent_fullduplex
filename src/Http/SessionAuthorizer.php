<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Http;

use AgentsFullDuplex\RealtimeAgent\Data\AgentSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;

final readonly class SessionAuthorizer
{
    public function __construct(
        private Config $config,
        private Gate $gate,
    ) {}

    public function authorize(AgentSession $session, ?Authenticatable $user): void
    {
        if (! $this->config->get('realtime-agent.security.require_authorization', true)) {
            return;
        }

        if ($user === null) {
            throw new AuthorizationException('Authentication is required for this realtime session.');
        }

        $ability = $this->config->get('realtime-agent.security.authorization_ability');

        if (is_string($ability) && $ability !== '') {
            if (! $this->gate->forUser($user)->allows($ability, $session)) {
                throw new AuthorizationException('This realtime session is not authorized.');
            }

            return;
        }

        if (! $user instanceof Model
            || ! $session->owner instanceof Model
            || $session->owner->getMorphClass() !== $user->getMorphClass()
            || (string) $session->owner->getKey() !== (string) $user->getAuthIdentifier()) {
            throw new AuthorizationException('This realtime session does not belong to the authenticated user.');
        }
    }
}
