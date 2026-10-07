<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `cotizaciones` para el entorno de pruebas.
 * Version minima suficiente para que Pedido::withCount('cotizaciones')
 * funcione en los tests (la FK a proveedores queda opcional).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->unsignedBigInteger('proveedor_id')->nullable();
            $table->decimal('monto', 10, 2)->default(0);
            $table->text('mensaje')->nullable();
            $table->string('estado', 20)->default('enviada');
            $table->integer('costo_creditos')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizaciones');
    }
};
