<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // The moment the ticket starts to count as "approaching breach" (due_at already exists).
            $table->timestamp('sla_warning_at')->nullable()->after('due_at');

            // 0 = not escalated, 1 = escalated as approaching, 2 = escalated as breached.
            // Each level is escalated once, so the sweep never notifies twice for the same thing.
            $table->unsignedTinyInteger('escalation_level')->default(0)->after('sla_warning_at');
            $table->timestamp('escalated_at')->nullable()->after('escalation_level');

            // The escalation sweep: "active tickets whose warning time has passed".
            $table->index(['status', 'sla_warning_at']);
        });

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

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['status', 'sla_warning_at']);
            $table->dropColumn(['sla_warning_at', 'escalation_level', 'escalated_at']);
        });
    }
};
