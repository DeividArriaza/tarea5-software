<?php

namespace App\Console\Commands;

use App\Models\DocumentoProveedor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrarDocumentosAPrivado extends Command
{
    protected $signature = 'documentos:migrar-privado';

    protected $description = 'Mueve al disco privado los documentos de proveedor subidos antes del cambio a almacenamiento privado';

    public function handle(): int
    {
        // Los documentos legados guardan ruta_archivo como URL publica
        // ("/storage/documentos/..."); los nuevos guardan solo el path
        // relativo dentro del disco 'local'. Solo migramos los primeros.
        $legados = DocumentoProveedor::where('ruta_archivo', 'like', '/storage/%')->get();

        $migrados = 0;
        $faltantes = 0;

        foreach ($legados as $documento) {
            $rutaRelativa = substr($documento->ruta_archivo, strlen('/storage/'));

            if (!Storage::disk('public')->exists($rutaRelativa)) {
                $faltantes++;
                continue;
            }

            Storage::disk('local')->put($rutaRelativa, Storage::disk('public')->get($rutaRelativa));
            Storage::disk('public')->delete($rutaRelativa);

            $documento->update(['ruta_archivo' => $rutaRelativa]);
            $migrados++;
        }

        $this->info("{$migrados} documento(s) migrado(s) a almacenamiento privado. {$faltantes} sin archivo fisico encontrado.");

        return self::SUCCESS;
    }
}
