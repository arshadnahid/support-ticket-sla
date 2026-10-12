<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();

            // Public identifier used in download URLs (the numeric id is not exposed).
            $table->uuid('uuid')->unique();

            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            // Null = attached to the ticket itself; set = attached to a reply or internal note.
            $table->foreignId('reply_id')->nullable()->constrained('ticket_replies')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();

            $table->string('disk', 30);
            $table->string('path');           // random server-side name, never the uploaded name
            $table->string('original_name');  // shown to users, sanitised, never used as a path
            $table->string('mime_type', 100); // detected from the file content, not from the client
            $table->unsignedBigInteger('size');

            $table->timestamps();

            $table->index(['ticket_id', 'reply_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
