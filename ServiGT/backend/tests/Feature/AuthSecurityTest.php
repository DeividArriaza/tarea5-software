<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Task 4.1 — registro y normalizacion compatible de correo.
 *
 * Cubre: politica de contrasena acordada (min 6, tope de 72 bytes por el
 * limite de bcrypt), normalizacion Unicode del correo, duplicados por
 * mayusculas/minusculas (deteccion sin fusionar), login generico y
 * compatibilidad de cuentas existentes con correo no normalizado.
 */
class AuthSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'     => 'Usuaria de Prueba',
            'email'    => 'nueva@servigt.test',
            'password' => 'Password123!',
            'role'     => 'cliente',
        ], $overrides);
    }

    // ── Contrasenas ──────────────────────────────────────────────────────

    public function test_registro_rechaza_contrasena_menor_a_6_caracteres(): void
    {
        $this->postJson('/api/register', $this->payload(['password' => '12345']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'nueva@servigt.test']);
    }

    public function test_registro_acepta_contrasena_de_exactamente_6_caracteres(): void
    {
        $this->postJson('/api/register', $this->payload(['password' => '123456']))
            ->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'nueva@servigt.test']);
    }

    public function test_registro_rechaza_contrasena_mayor_a_72_bytes(): void
    {
        $this->postJson('/api/register', $this->payload(['password' => str_repeat('a', 73)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'nueva@servigt.test']);
    }

    public function test_registro_acepta_contrasena_de_exactamente_72_bytes(): void
    {
        $this->postJson('/api/register', $this->payload(['password' => str_repeat('a', 72)]))
            ->assertCreated();
    }

    public function test_registro_cuenta_bytes_no_caracteres_para_password_unicode(): void
    {
        // 24 emoji de 4 bytes cada uno = 96 bytes UTF-8 pero solo 24
        // "caracteres" (code points). Si se contaran caracteres, esto
        // pasaria la validacion y bcrypt lo truncaria en silencio.
        $this->postJson('/api/register', $this->payload(['password' => str_repeat('😀', 24)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_login_no_bloquea_contrasena_existente_que_no_cumple_la_politica_nueva(): void
    {
        // Cuenta creada antes de esta task, con una contrasena mas corta
        // que el minimo actual. La politica de registro no se aplica
        // retroactivamente al login.
        $user = User::factory()->cliente()->create([
            'email'    => 'legado@servigt.test',
            'password' => '123',
        ]);

        $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => '123',
        ])->assertOk();
    }

    // ── Normalizacion y duplicados de correo ────────────────────────────

    public function test_registro_normaliza_el_correo_a_minusculas(): void
    {
        $this->postJson('/api/register', $this->payload(['email' => '  Nueva@ServiGT.test  ']))
            ->assertCreated()
            ->assertJsonPath('user.email', 'nueva@servigt.test');

        $this->assertDatabaseHas('users', ['email' => 'nueva@servigt.test']);
        $this->assertDatabaseMissing('users', ['email' => 'Nueva@ServiGT.test']);
    }

    public function test_registro_rechaza_correo_duplicado_que_solo_difiere_en_mayusculas(): void
    {
        User::factory()->cliente()->create(['email' => 'existente@servigt.test']);

        $this->postJson('/api/register', $this->payload(['email' => 'Existente@ServiGT.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // La colision se detecta y se rechaza; no se fusiona ni se
        // modifica la cuenta existente.
        $this->assertDatabaseCount('users', 1);
    }

    public function test_registro_no_migra_ni_fusiona_cuentas_en_colision(): void
    {
        $original = User::factory()->cliente()->create(['email' => 'Duplicado@ServiGT.test']);

        $this->postJson('/api/register', $this->payload(['email' => 'duplicado@servigt.test']))
            ->assertStatus(422);

        $original->refresh();
        $this->assertSame('Duplicado@ServiGT.test', $original->email);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_es_case_insensitive_para_compatibilidad_con_cuentas_existentes(): void
    {
        // Cuenta existente antes de la normalizacion: correo con
        // mayusculas tal como quedo guardado historicamente.
        $user = User::factory()->cliente()->create([
            'email'    => 'Legado.Mayus@ServiGT.test',
            'password' => 'Password123!',
        ]);

        $this->postJson('/api/login', [
            'email'    => 'legado.mayus@servigt.test',
            'password' => 'Password123!',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_registro_recorta_espacios_del_correo_antes_de_normalizar(): void
    {
        $this->postJson('/api/register', $this->payload(['email' => "  nueva@servigt.test\t"]))
            ->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'nueva@servigt.test']);
    }

    // ── Login generico (no enumerable) ──────────────────────────────────

    public function test_login_devuelve_el_mismo_mensaje_para_correo_inexistente_y_password_incorrecto(): void
    {
        $user = User::factory()->cliente()->create([
            'email'    => 'existe@servigt.test',
            'password' => 'Password123!',
        ]);

        $inexistente = $this->postJson('/api/login', [
            'email'    => 'no-existe@servigt.test',
            'password' => 'cualquiera',
        ])->assertStatus(401);

        $passwordIncorrecto = $this->postJson('/api/login', [
            'email'    => $user->email,
            'password' => 'incorrecta',
        ])->assertStatus(401);

        $this->assertSame(
            $inexistente->json('message'),
            $passwordIncorrecto->json('message')
        );
    }
}
