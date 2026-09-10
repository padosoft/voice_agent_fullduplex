<?php

declare(strict_types=1);

use AgentsFullDuplex\RealtimeAgent\Http\Controllers\RealtimeAgentController;
use Illuminate\Support\Facades\Route;

Route::get('/sessions/{session}', [RealtimeAgentController::class, 'state'])->name('realtime-agent.state');
Route::post('/sessions/{session}/connect', [RealtimeAgentController::class, 'connect'])->name('realtime-agent.connect');
Route::put('/sessions/{session}/surface', [RealtimeAgentController::class, 'surface'])->name('realtime-agent.surface');
Route::patch('/sessions/{session}/surface', [RealtimeAgentController::class, 'patchSurface'])->name('realtime-agent.surface.patch');
Route::post('/sessions/{session}/tools', [RealtimeAgentController::class, 'tool'])->name('realtime-agent.tools');
Route::post('/sessions/{session}/confirmations/{confirmation}', [RealtimeAgentController::class, 'confirmation'])->name('realtime-agent.confirmations');
Route::post('/sessions/{session}/commands/{command}', [RealtimeAgentController::class, 'completeCommand'])->name('realtime-agent.commands');
Route::delete('/sessions/{session}', [RealtimeAgentController::class, 'finish'])->name('realtime-agent.finish');
