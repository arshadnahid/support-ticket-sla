<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A history of what happened to a ticket. Escalations write here; later modules
        // (assignment, status changes) can use the same table.
        Schema::create('ticket_activities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            // Null = done by the system (for example the SLA sweep).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 50);       // e.g. sla_warning, sla_breached
            $table->string('description');    // readable sentence for the timeline
            $table->json('properties')->nullable();

            $table->timestamps();

            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_activities');
    }
};
