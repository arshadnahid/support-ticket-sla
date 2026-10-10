<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();

            // Public identifier, e.g. TKT-2026-000001. Used in URLs instead of the numeric id.
            $table->string('number', 32)->unique();

            $table->string('subject');
            $table->text('description');

            // Strings (not DB enums) so adding a priority or status needs no ALTER TABLE.
            $table->string('priority', 20)->default(TicketPriority::Medium->value);
            $table->string('status', 30)->default(TicketStatus::Open->value);

            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // SLA deadline. Filled in by the SLA module; null until then.
            $table->timestamp('due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            // Each index serves a specific query (see tec-doc/2.tickets.md).
            $table->index(['created_by', 'status']);   // a customer's own tickets
            $table->index(['assigned_to', 'status']);  // an agent's queue and workload
            $table->index(['status', 'due_at']);       // active list by deadline, SLA escalation scan
            $table->index(['status', 'priority']);     // dashboard counts
            $table->index('created_at');               // newest first, date filters
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
