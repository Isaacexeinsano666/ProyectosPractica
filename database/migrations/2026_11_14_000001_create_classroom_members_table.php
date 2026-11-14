<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un registro por navegador de alumno que se unió a la clase.
        Schema::create('classroom_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_session_id')->constrained()->cascadeOnDelete();
            $table->string('member_key', 40);
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['classroom_session_id', 'member_key']);
            $table->index(['classroom_session_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_members');
    }
};
