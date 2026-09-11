<?php

declare(strict_types=1);

use AgentsFullDuplex\RealtimeAgent\Http\Controllers\RealtimeAgentController;
use Illuminate\Support\Facades\Route;

Route::get('/sessions/{session}', [RealtimeAgentController::class, 'state'])->name('realtime-agent.state');
Route::post('/sessions/{session}/connect', [RealtimeAgentController::class, 'connect'])->name('realtime-agent.connect');
Route::post('/sessions/{session}/text', [RealtimeAgentController::class, 'text'])->name('realtime-agent.text');
Route::get('/sessions/{session}/audit', [RealtimeAgentController::class, 'audit'])->name('realtime-agent.audit');
Route::post('/sessions/{session}/audit/reconcile', [RealtimeAgentController::class, 'reconcileAudit'])->name('realtime-agent.audit.reconcile');
Route::post('/sessions/{session}/messages', [RealtimeAgentController::class, 'message'])->name('realtime-agent.messages');
Route::post('/sessions/{session}/usage', [RealtimeAgentController::class, 'usage'])->name('realtime-agent.usage');
Route::put('/sessions/{session}/surface', [RealtimeAgentController::class, 'surface'])->name('realtime-agent.surface');
Route::patch('/sessions/{session}/surface', [RealtimeAgentController::class, 'patchSurface'])->name('realtime-agent.surface.patch');
Route::post('/sessions/{session}/tools', [RealtimeAgentController::class, 'tool'])->name('realtime-agent.tools');
Route::post('/sessions/{session}/confirmations/{confirmation}', [RealtimeAgentController::class, 'confirmation'])->name('realtime-agent.confirmations');
Route::post('/sessions/{session}/commands/{command}', [RealtimeAgentController::class, 'completeCommand'])->name('realtime-agent.commands');
Route::delete('/sessions/{session}', [RealtimeAgentController::class, 'finish'])->name('realtime-agent.finish');
