<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // When the current assignee got the ticket.
            $table->timestamp('assigned_at')->nullable()->after('assigned_to');
        });

        Schema::table('users', function (Blueprint $table) {
            // Last time this agent was given a ticket. Automatic assignment uses it as the
            // tie-break when agents have the same workload (longest wait goes first).
            $table->timestamp('last_assigned_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('assigned_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('last_assigned_at'));
    }
};
