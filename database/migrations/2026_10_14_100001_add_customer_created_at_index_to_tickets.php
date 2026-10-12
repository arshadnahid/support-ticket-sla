<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // A customer's own list is "created_by = ? ORDER BY created_at DESC, id DESC": this index
            // returns it already in order, so there is no sort however many tickets they have.
            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['created_by', 'created_at']);
        });
    }
};
