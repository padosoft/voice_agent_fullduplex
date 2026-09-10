<?php

declare(strict_types=1);

use AgentsFullDuplex\RealtimeAgent\Contracts\EventStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\StateStoreContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolBrokerContract;
use AgentsFullDuplex\RealtimeAgent\Contracts\ToolCallStoreContract;
use AgentsFullDuplex\RealtimeAgent\Data\ToolCall;
use AgentsFullDuplex\RealtimeAgent\Engine\AgentSessionManager;
use AgentsFullDuplex\RealtimeAgent\Engine\ToolBroker;
use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;
use AgentsFullDuplex\RealtimeAgent\Goal;
use AgentsFullDuplex\RealtimeAgent\State\ArrayEventStore;
use AgentsFullDuplex\RealtimeAgent\State\ArrayStateStore;
use AgentsFullDuplex\RealtimeAgent\State\ArrayToolCallStore;
use AgentsFullDuplex\RealtimeAgent\Tools\RuntimeStateGet;
use AgentsFullDuplex\RealtimeAgent\Tools\UpdateGoal;
use AgentsFullDuplex\RealtimeAgent\Tools\UpdateWorkingMemory;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;

require __DIR__.'/../vendor/autoload.php';

$container = new Container;
Container::setInstance($container);
Facade::setFacadeApplication($container);

$configuration = require __DIR__.'/../config/realtime-agent.php';
$configuration['default'] = 'fake';
$configuration['state']['driver'] = 'array';
$config = new Repository(['realtime-agent' => $configuration]);

$container->instance(ContainerContract::class, $container);
$container->instance(ConfigContract::class, $config);
$container->instance('config', $config);
$container->singleton('date', static fn (): DateFactory => new DateFactory);
$container->singleton(DispatcherContract::class, static fn (Container $container): Dispatcher => new Dispatcher($container));
$container->singleton(StateStoreContract::class, ArrayStateStore::class);
$container->singleton(EventStoreContract::class, ArrayEventStore::class);
$container->singleton(ToolCallStoreContract::class, ArrayToolCallStore::class);
$container->singleton(ToolBrokerContract::class, ToolBroker::class);

$manager = $container->make(AgentSessionManager::class);
$session = $manager->make('demo.lesson.teacher')
    ->instructions('Teach a short lesson about photosynthesis.')
    ->context(['source_text' => 'Plants convert light energy into chemical energy.'])
    ->goals([
        Goal::make('topic_1')->label('Explain chlorophyll')->required()->completionByAgent(true),
        Goal::make('topic_2')->label('Explain the light-dependent phase')->required()->completionByAgent(true),
        Goal::make('topic_3')->label('Explain the Calvin cycle')->required()->completionByAgent(true),
    ])
    ->tools([RuntimeStateGet::class, UpdateWorkingMemory::class, UpdateGoal::class])
    ->surface('lesson.show')
    ->interactionLevel(InteractionLevel::Guide)
    ->startFor((object) ['id' => 'demo-user']);

echo "Session {$session->id} started at revision {$session->state()->revision()}.\n";
echo 'Connection: '.json_encode($session->connection()->connection, JSON_THROW_ON_ERROR)."\n\n";

foreach (['topic_1', 'topic_2', 'topic_3'] as $goalId) {
    $result = $session->execute(new ToolCall(
        id: 'call_'.$goalId,
        name: 'goal.update',
        arguments: [
            'goal_id' => $goalId,
            'status' => 'completed',
            'evidence' => "The student correctly explained {$goalId}.",
        ],
        baseRevision: $session->state()->revision(),
        idempotencyKey: 'demo_'.$goalId,
    ));

    echo "Completed {$goalId}; revision {$result->stateRevision}; status {$session->state()->status()}.\n";
}

$events = $container->make(EventStoreContract::class)->forSession($session->id);

echo "\nFinal state: {$session->state()->status()}\n";
echo 'Canonical events: '.count($events)."\n";
echo json_encode($session->state(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
