<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pedido>
 */
class PedidoFactory extends Factory
{
    protected $model = Pedido::class;

    public function definition(): array
    {
        return [
            'cliente_id'       => User::factory()->state(['role' => 'cliente']),
            'categoria_id'     => Categoria::factory(),
            'descripcion'      => fake()->sentence(12),
            'direccion'        => 'Zona ' . fake()->numberBetween(1, 21) . ', Ciudad de Guatemala',
            'urgencia'         => fake()->randomElement(['baja', 'media', 'alta']),
            'estado'           => 'abierto',
            'fecha_expiracion' => now()->addDays(7),
        ];
    }

    /** Estado: pedido ya expirado por fecha. */
    public function expirado(): static
    {
        return $this->state(fn () => [
            'estado'           => 'expirado',
            'fecha_expiracion' => now()->subDay(),
        ]);
    }
}
