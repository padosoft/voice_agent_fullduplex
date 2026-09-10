<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Tests\Feature;

use AgentsFullDuplex\RealtimeAgent\Confirmation;
use AgentsFullDuplex\RealtimeAgent\Engine\AgentSessionManager;
use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;
use AgentsFullDuplex\RealtimeAgent\Tests\TestCase;
use AgentsFullDuplex\RealtimeAgent\Tool;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class ControlApiTest extends TestCase
{
    public function test_surface_updates_return_stale_revision_and_validation_responses(): void
    {
        $session = $this->app->make(AgentSessionManager::class)
            ->make('api.lesson')
            ->surface('lesson.show')
            ->startFor(null);

        $snapshot = [
            'surface' => 'lesson.show',
            'title' => 'Lesson',
            'components' => [
                'lesson.topic_1' => [
                    'type' => 'goal',
                    'label' => 'Topic one',
                    'state' => ['completed' => false],
                    'actions' => ['highlight'],
                ],
            ],
        ];

        $this->putJson("/realtime-agent/sessions/{$session->id}/surface", [
            'base_revision' => 1,
            'snapshot' => $snapshot,
        ])->assertOk()->assertJsonPath('state.session.revision', 2);

        $this->putJson("/realtime-agent/sessions/{$session->id}/surface", [
            'base_revision' => 1,
            'snapshot' => $snapshot,
        ])->assertConflict()
            ->assertJsonPath('error.code', 'stale_revision')
            ->assertJsonPath('state.session.revision', 2);

        $patched = $this->patchJson("/realtime-agent/sessions/{$session->id}/surface", [
            'base_revision' => 2,
            'patches' => [[
                'op' => 'replace',
                'path' => '/components/lesson.topic_1/state/completed',
                'value' => true,
            ]],
        ])->assertOk()
            ->assertJsonPath('state.session.revision', 3);
        self::assertTrue($patched->json('state.environment.ui.components')['lesson.topic_1']['state']['completed']);

        $snapshot['components']['#raw-selector'] = $snapshot['components']['lesson.topic_1'];
        $this->putJson("/realtime-agent/sessions/{$session->id}/surface", [
            'base_revision' => 3,
            'snapshot' => $snapshot,
        ])->assertUnprocessable();
    }

    public function test_confirmation_is_required_and_cannot_be_bypassed_by_browser_input(): void
    {
        $tool = Tool::make('lesson.publish')
            ->description('Publish the lesson.')
            ->input([])
            ->confirmation(Confirmation::always())
            ->handler(static fn (): array => ['published' => true]);
        $session = $this->app->make(AgentSessionManager::class)
            ->make('api.confirmation')
            ->tools([$tool])
            ->interactionLevel(InteractionLevel::Operate)
            ->startFor(null);

        $response = $this->postJson("/realtime-agent/sessions/{$session->id}/tools", [
            'id' => 'call_publish',
            'name' => 'lesson.publish',
            'arguments' => [],
            'base_revision' => 1,
            'idempotency_key' => 'publish-once',
            'confirmed' => true,
        ])->assertOk()->assertJsonPath('result.status', 'confirmation_required');

        $confirmation = $response->json('result.output.confirmation.id');
        self::assertIsString($confirmation);

        $this->postJson("/realtime-agent/sessions/{$session->id}/confirmations/{$confirmation}", [
            'accepted' => true,
        ])->assertOk()
            ->assertJsonPath('result.status', 'completed')
            ->assertJsonPath('result.output.published', true);

        $this->postJson("/realtime-agent/sessions/{$session->id}/confirmations/{$confirmation}", [
            'accepted' => true,
        ])->assertUnprocessable();
    }

    public function test_session_owner_is_authorized_again_inside_the_control_route(): void
    {
        config()->set('realtime-agent.security.require_authorization', true);
        $owner = new ControlApiUser;
        $owner->forceFill(['id' => 10]);
        $other = new ControlApiUser;
        $other->forceFill(['id' => 20]);
        $session = $this->app->make(AgentSessionManager::class)
            ->make('api.owner')
            ->startFor($owner);

        $this->actingAs($other)
            ->getJson("/realtime-agent/sessions/{$session->id}")
            ->assertForbidden();

        $this->actingAs($owner)
            ->getJson("/realtime-agent/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('state.session.id', $session->id);
    }
}

final class ControlApiUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;
}
