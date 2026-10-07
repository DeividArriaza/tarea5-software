<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `role` a la tabla users.
 *
 * En produccion el esquema lo crea database/init.sql (PostgreSQL). Esta
 * migracion existe para que el entorno de pruebas (SQLite en memoria +
 * RefreshDatabase) reproduzca la columna `role` que usan los tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role', 20)->default('cliente');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
