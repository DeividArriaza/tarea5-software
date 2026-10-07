<?php

namespace Tests\Feature;

use App\Models\DocumentoProveedor;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MigrarDocumentosAPrivadoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_migra_documento_legado_del_disco_publico_al_privado(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        [$proveedor] = $this->crearProveedorConUsuario();
        $rutaRelativa = 'documentos/' . $proveedor->id . '/legado.pdf';
        Storage::disk('public')->put($rutaRelativa, 'contenido-legado');

        $documento = DocumentoProveedor::create([
            'proveedor_id'      => $proveedor->id,
            'tipo_documento'    => 'dpi',
            'nombre_archivo'    => 'legado.pdf',
            'ruta_archivo'      => '/storage/' . $rutaRelativa,
            'estado_validacion' => 'pendiente',
        ]);

        $this->artisan('documentos:migrar-privado')->assertSuccessful();

        Storage::disk('local')->assertExists($rutaRelativa);
        $this->assertSame('contenido-legado', Storage::disk('local')->get($rutaRelativa));
        Storage::disk('public')->assertMissing($rutaRelativa);

        $this->assertDatabaseHas('documentos_proveedores', [
            'id'           => $documento->id,
            'ruta_archivo' => $rutaRelativa,
        ]);
    }

    public function test_documentos_ya_migrados_no_se_tocan_de_nuevo(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        [$proveedor] = $this->crearProveedorConUsuario();
        $rutaRelativa = 'documentos/' . $proveedor->id . '/nuevo.pdf';
        Storage::disk('local')->put($rutaRelativa, 'contenido-nuevo');

        DocumentoProveedor::create([
            'proveedor_id'      => $proveedor->id,
            'tipo_documento'    => 'dpi',
            'nombre_archivo'    => 'nuevo.pdf',
            'ruta_archivo'      => $rutaRelativa,
            'estado_validacion' => 'pendiente',
        ]);

        $this->artisan('documentos:migrar-privado')->assertSuccessful();

        Storage::disk('local')->assertExists($rutaRelativa);
        $this->assertDatabaseHas('documentos_proveedores', ['ruta_archivo' => $rutaRelativa]);
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
            'descripcion'  => 'Proveedor para pruebas de migracion de documentos.',
        ]);

        return [$proveedor, $user];
    }
}
