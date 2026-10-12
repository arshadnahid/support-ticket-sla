<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_replies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->text('body');

            // Internal agent note. Customers must never see these (enforced in the queries).
            $table->boolean('is_internal')->default(false);

            $table->timestamps();

            // Conversation of one ticket, oldest first; customers filter on is_internal.
            $table->index(['ticket_id', 'is_internal', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_replies');
    }
};
