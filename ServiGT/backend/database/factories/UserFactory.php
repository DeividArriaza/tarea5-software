<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Reemplaza al UserFactory que trae el esqueleto de Laravel.
 *
 * El factory por defecto escribe `email_verified_at` y `remember_token`, que no
 * existen en la tabla `users` de database/init.sql (fuente unica del esquema del
 * proyecto). Este factory se limita a las columnas reales.
 *
 * El modelo User hashea la contrasena en setPasswordAttribute, por lo que aqui
 * se pasa en texto plano.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name'     => fake()->name(),
            'email'    => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role'     => 'cliente',
        ];
    }

    public function cliente(): static
    {
        return $this->state(fn () => ['role' => 'cliente']);
    }

    public function proveedor(): static
    {
        return $this->state(fn () => ['role' => 'proveedor']);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }
}
