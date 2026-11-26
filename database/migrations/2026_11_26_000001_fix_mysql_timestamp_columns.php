<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * En MySQL/MariaDB (sin explicit_defaults_for_timestamp, como en XAMPP), la primera
     * columna TIMESTAMP obligatoria de una tabla recibe "ON UPDATE CURRENT_TIMESTAMP":
     * cualquier UPDATE de la fila la cambia a la hora actual.
     *
     * - expires_at: la clase se cerraba en cuanto un alumno se unía o dibujaba, porque
     *   esos cambios actualizan la fila de la clase sin tocar expires_at.
     * - last_seen_at: cambiar el nombre o los permisos de un alumno lo marcaba conectado.
     *
     * DATETIME no tiene ese comportamiento. En SQLite (los tests) no hace falta cambiar nada.
     */
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('classroom_sessions', function (Blueprint $table) {
            $table->dateTime('expires_at')->change();
        });
        Schema::table('classroom_members', function (Blueprint $table) {
            $table->dateTime('last_seen_at')->change();
        });
    }

    public function down(): void
    {
        // Volver a TIMESTAMP reintroduciría el error, así que no se revierte.
    }
};
