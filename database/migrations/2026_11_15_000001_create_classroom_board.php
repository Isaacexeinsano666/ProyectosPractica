<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Estado general de la pizarra de cada clase.
        Schema::table('classroom_sessions', function (Blueprint $table) {
            // Sube con cada trazo, borrado o cambio de permisos: los navegadores
            // solo vuelven a pedir la pizarra cuando cambia.
            $table->unsignedInteger('board_revision')->default(0);
            // "Pizarra libre": todos los alumnos pueden dibujar.
            $table->boolean('board_open')->default(false);
        });

        // Nombre y permisos de cada alumno en la pizarra.
        Schema::table('classroom_members', function (Blueprint $table) {
            $table->string('name', 40)->nullable();
            $table->boolean('can_draw')->default(false);
            $table->boolean('can_moderate')->default(false);
            $table->boolean('hand_raised')->default(false);
        });

        Schema::create('classroom_strokes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_session_id')->constrained()->cascadeOnDelete();
            // null = el profesor.
            $table->foreignId('classroom_member_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('author_name', 40);
            $table->string('type', 10);
            $table->string('color', 7);
            // Puntos normalizados (0..1) respecto del tamaño de la pizarra.
            $table->json('points');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_strokes');
        Schema::table('classroom_members', function (Blueprint $table) {
            $table->dropColumn(['name', 'can_draw', 'can_moderate', 'hand_raised']);
        });
        Schema::table('classroom_sessions', function (Blueprint $table) {
            $table->dropColumn(['board_revision', 'board_open']);
        });
    }
};
