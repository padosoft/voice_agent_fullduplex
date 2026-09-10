<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_agent_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('agent_key');
            $table->nullableMorphs('owner');
            $table->string('provider');
            $table->string('provider_session_id')->nullable();
            $table->string('status')->index();
            $table->json('state');
            $table->unsignedBigInteger('state_revision');
            $table->json('metadata')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_agent_sessions');
    }
};
