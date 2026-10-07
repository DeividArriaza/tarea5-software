<?php

namespace Tests\Feature;

use App\Models\DocumentoProveedor;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProviderDocumentPrivacyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_documento_subido_se_guarda_en_disco_privado_y_no_en_publico(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$dueno, $user] = $this->crearProveedorConUsuario();
        Sanctum::actingAs($user);

        $archivo = UploadedFile::fake()->create('dpi.pdf', 200, 'application/pdf');

        $this->post("/api/providers/{$dueno->id}/documentos", [
            'documento'      => $archivo,
            'tipo_documento' => 'dpi',
        ], ['Accept' => 'application/json'])->assertCreated();

        $documento = DocumentoProveedor::where('proveedor_id', $dueno->id)->firstOrFail();

        Storage::disk('local')->assertExists($documento->ruta_archivo);
        Storage::disk('public')->assertDirectoryEmpty('documentos');
    }

    public function test_respuesta_de_documento_no_expone_ruta_publica(): void
    {
        Storage::fake('local');
        [$dueno, $user] = $this->crearProveedorConUsuario();
        Sanctum::actingAs($user);

        $archivo = UploadedFile::fake()->create('dpi.pdf', 200, 'application/pdf');

        $this->post("/api/providers/{$dueno->id}/documentos", [
            'documento'      => $archivo,
            'tipo_documento' => 'dpi',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonMissingPath('documento.ruta_archivo')
            ->assertJsonPath('documento.tipo_documento', 'dpi');
    }

    public function test_descarga_de_documento_respeta_ownership_y_roles(): void
    {
        Storage::fake('local');
        [$dueno, $user] = $this->crearProveedorConUsuario();
        $ajeno = User::factory()->proveedor()->create();
        $admin = User::factory()->admin()->create();

        $ruta = 'documentos/' . $dueno->id . '/dpi.pdf';
        Storage::disk('local')->put($ruta, 'contenido-del-documento');

        $documento = DocumentoProveedor::create([
            'proveedor_id'      => $dueno->id,
            'tipo_documento'    => 'dpi',
            'nombre_archivo'    => 'dpi.pdf',
            'ruta_archivo'      => $ruta,
            'estado_validacion' => 'pendiente',
        ]);

        $url = "/api/providers/{$dueno->id}/documentos/{$documento->id}/descargar";

        $this->get($url, ['Accept' => 'application/json'])->assertUnauthorized();

        Sanctum::actingAs($ajeno);
        $this->get($url)->assertForbidden();

        Sanctum::actingAs($user);
        $this->get($url)->assertOk();

        Sanctum::actingAs($admin);
        $this->get($url)->assertOk();
    }

    public function test_descarga_de_documento_inexistente_responde_404(): void
    {
        Storage::fake('local');
        [$dueno, $user] = $this->crearProveedorConUsuario();
        Sanctum::actingAs($user);

        $this->get("/api/providers/{$dueno->id}/documentos/999999/descargar")
            ->assertNotFound();
    }

    public function test_descarga_con_documento_de_otro_proveedor_responde_403(): void
    {
        Storage::fake('local');
        [$dueno] = $this->crearProveedorConUsuario();
        [$otroDueno, $otroUser] = $this->crearProveedorConUsuario();

        $ruta = 'documentos/' . $dueno->id . '/dpi.pdf';
        Storage::disk('local')->put($ruta, 'contenido-del-documento');

        $documento = DocumentoProveedor::create([
            'proveedor_id'      => $dueno->id,
            'tipo_documento'    => 'dpi',
            'nombre_archivo'    => 'dpi.pdf',
            'ruta_archivo'      => $ruta,
            'estado_validacion' => 'pendiente',
        ]);

        Sanctum::actingAs($otroUser);
        $this->get("/api/providers/{$otroDueno->id}/documentos/{$documento->id}/descargar")
            ->assertForbidden();
    }

    public function test_documento_con_mime_invalido_falla_validacion(): void
    {
        Storage::fake('local');
        [$dueno, $user] = $this->crearProveedorConUsuario();
        Sanctum::actingAs($user);

        $archivo = UploadedFile::fake()->create('malicioso.exe', 200, 'application/x-msdownload');

        $this->post("/api/providers/{$dueno->id}/documentos", [
            'documento'      => $archivo,
            'tipo_documento' => 'dpi',
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_documento_que_excede_tamano_maximo_falla_validacion(): void
    {
        Storage::fake('local');
        [$dueno, $user] = $this->crearProveedorConUsuario();
        Sanctum::actingAs($user);

        $archivo = UploadedFile::fake()->create('dpi.pdf', 6000, 'application/pdf');

        $this->post("/api/providers/{$dueno->id}/documentos", [
            'documento'      => $archivo,
            'tipo_documento' => 'dpi',
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    /**
     * @return array{0: Proveedor, 1: User}
     */
    private function crearProveedorConUsuario(): array
    {
        $user = User::factory()->proveedor()->create();

        $proveedor = Proveedor::create([
            'user_id'      => $user->id,
            'nombre'       => $user->name,
            'email'        => $user->email,
            'departamento' => 'Guatemala',
            'municipio'    => 'Guatemala',
            'descripcion'  => 'Proveedor para pruebas de documentos.',
        ]);

        return [$proveedor, $user];
    }
}
