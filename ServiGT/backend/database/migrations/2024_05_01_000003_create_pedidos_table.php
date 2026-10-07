<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `pedidos` para el entorno de pruebas.
 * Refleja la definicion de database/init.sql (sin los CHECK de PostgreSQL,
 * que en las pruebas se validan a nivel de aplicacion via StorePedidoRequest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('users')->cascadeOnDelete();
            $table->text('descripcion');
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->string('direccion', 500)->nullable();
            $table->string('urgencia', 10)->default('media');
            $table->string('estado', 20)->default('abierto');
            $table->timestamp('fecha_expiracion');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
