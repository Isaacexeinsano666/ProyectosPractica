<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('title', 100);
            $table->string('owner_hash', 64);
            $table->json('state');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_sessions');
    }
};
