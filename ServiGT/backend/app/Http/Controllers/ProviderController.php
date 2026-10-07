<?php

namespace App\Http\Controllers;

use App\Models\DocumentoProveedor;
use App\Http\Resources\ProveedorResource;
use App\Models\Proveedor;
use App\Traits\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProviderController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function index(): JsonResponse
    {
        $proveedores = Proveedor::with('categoria')
            ->orderBy('calificacion_promedio', 'desc')
            ->get();

        return $this->success('OK', [
            'proveedores' => ProveedorResource::coleccion($proveedores, ProveedorResource::CATALOGO),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        // Sin `documentos`: son privados y ya tienen su propia ruta autorizada.
        $proveedor = Proveedor::with(['categoria', 'categorias', 'disponibilidad'])
            ->find($id);

        if (!$proveedor) {
            return $this->error('Proveedor no encontrado', 404);
        }

        return $this->success('OK', [
            'proveedor' => (new ProveedorResource($proveedor, ProveedorResource::DETALLE))->resolve(),
        ]);
    }

    /**
     * Perfil propio derivado de la sesion. Reemplaza a `showByUser` como via
     * normal: el consumidor real siempre fue el usuario autenticado mirandose
     * a si mismo, asi que pasar su id por la URL solo agregaba un parametro
     * manipulable sin ganar nada.
     */
    public function me(Request $request): JsonResponse
    {
        $proveedor = Proveedor::with(['categoria', 'categorias', 'disponibilidad'])
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$proveedor) {
            return $this->error('Perfil de proveedor no encontrado', 404);
        }

        return $this->success('OK', [
            'proveedor' => (new ProveedorResource($proveedor, ProveedorResource::PROPIO))->resolve(),
        ]);
    }

    /**
     * Lookup legado por id de usuario. Se conserva por compatibilidad, pero
     * ahora exige ser el duenno o administrador: antes cualquier autenticado
     * podia leer el perfil ajeno con sus documentos.
     */
    public function showByUser(Request $request, int $userId): JsonResponse
    {
        $proveedor = Proveedor::with(['categoria', 'categorias', 'disponibilidad'])
            ->where('user_id', $userId)
            ->first();

        if (!$proveedor) {
            return $this->error('Perfil de proveedor no encontrado', 404);
        }

        $this->authorize('manage', $proveedor);

        return $this->success('OK', [
            'proveedor' => (new ProveedorResource($proveedor, ProveedorResource::PROPIO))->resolve(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (Proveedor::where('user_id', $request->user()->id)->exists()) {
            return $this->error('Ya tienes un perfil de proveedor.', 422);
        }

        $validated = $request->validate([
            'nombre'            => 'required|string|max:255',
            'email'             => 'required|email|unique:proveedores,email',
            'telefono'          => 'nullable|string|max:20',
            'descripcion'       => 'nullable|string',
            'departamento'      => 'required|string|max:100',
            'municipio'         => 'nullable|string|max:100',
            'categoria_id'      => 'nullable|exists:categorias,id',
            'categoria_ids'     => 'sometimes|array|min:1',
            'categoria_ids.*'   => 'exists:categorias,id',
            'tarifa_hora'       => 'nullable|numeric|min:0',
            'tarifa_proyecto'   => 'nullable|numeric|min:0',
            'nivel'             => 'nullable|in:novato,intermedio,experto',
        ]);

        $categoriaIds = $validated['categoria_ids'] ?? [];
        unset($validated['categoria_ids']);

        // Derive primary categoria_id from first selection when not explicitly set
        if (empty($validated['categoria_id']) && !empty($categoriaIds)) {
            $validated['categoria_id'] = $categoriaIds[0];
        }

        // La identidad se deriva de la sesion, nunca del payload: evita que un
        // cliente cree un perfil de proveedor a nombre de otro usuario.
        $validated['user_id'] = $request->user()->id;

        try {
            // Transaccion propia: si el insert choca contra el indice unico,
            // Laravel hace ROLLBACK TO SAVEPOINT solo de este bloque en vez de
            // dejar abortada la transaccion completa de la peticion.
            $proveedor = DB::transaction(fn () => Proveedor::create($validated));
        } catch (QueryException $e) {
            // El chequeo de arriba no cierra la carrera: si otra peticion del
            // mismo usuario gano entre el exists() y este insert, el arbitro
            // final es el indice unico de la BD (idx_proveedores_user_id_unique).
            if (!$this->esViolacionDeUnicidad($e)) {
                throw $e;
            }

            return $this->error('Ya tienes un perfil de proveedor.', 422);
        }

        if (!empty($categoriaIds)) {
            $proveedor->categorias()->sync($categoriaIds);
        } elseif (!empty($validated['categoria_id'])) {
            $proveedor->categorias()->sync([$validated['categoria_id']]);
        }

        $proveedor->load(['categoria', 'categorias']);

        return $this->success('Proveedor creado exitosamente', [
            'proveedor' => (new ProveedorResource($proveedor, ProveedorResource::PROPIO))->resolve(),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $proveedor = Proveedor::find($id);
        if (!$proveedor) {
            return $this->error('Proveedor no encontrado', 404);
        }

        $this->authorize('manage', $proveedor);

        $validated = $request->validate([
            'nombre'            => 'sometimes|required|string|max:255',
            'telefono'          => 'nullable|string|max:20',
            'descripcion'       => 'nullable|string',
            'departamento'      => 'sometimes|required|string|max:100',
            'municipio'         => 'nullable|string|max:100',
            'categoria_id'      => 'sometimes|nullable|exists:categorias,id',
            'categoria_ids'     => 'sometimes|array|min:1',
            'categoria_ids.*'   => 'exists:categorias,id',
            'tarifa_hora'       => 'nullable|numeric|min:0',
            'tarifa_proyecto'   => 'nullable|numeric|min:0',
            'nivel'             => 'nullable|in:novato,intermedio,experto',
            // Hex de 6 digitos con almohadilla. Se valida en el servidor para
            // que no entre nada que luego se inyecte como color en la UI.
            'color_acento'      => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        // Personalizacion de marca reservada a Premium activo (task 2.4). Se
        // valida aqui y no en el request para poder distinguir "no mando color"
        // de "mando color sin Premium": lo primero es una edicion normal de
        // perfil, lo segundo es un intento de usar una prestacion que no tiene.
        if (array_key_exists('color_acento', $validated)
            && !$request->user()->can('personalizarMarca', $proveedor)) {
            return $this->error(
                'El color de acento es una prestacion de Premium activo.',
                403
            );
        }

        $categoriaIds = $validated['categoria_ids'] ?? null;
        unset($validated['categoria_ids']);

        if ($categoriaIds !== null) {
            $validated['categoria_id'] = $categoriaIds[0];
            $proveedor->categorias()->sync($categoriaIds);
        }

        $proveedor->update($validated);
        $proveedor->load(['categoria', 'categorias', 'disponibilidad']);

        return $this->success('Perfil actualizado correctamente', [
            'proveedor' => (new ProveedorResource($proveedor, ProveedorResource::PROPIO))->resolve(),
        ]);
    }

    public function getDocumentos(Request $request, int $id): JsonResponse
    {
        $proveedor = Proveedor::find($id);
        if (!$proveedor) {
            return $this->error('Proveedor no encontrado', 404);
        }

        $this->authorize('manage', $proveedor);

        return $this->success('OK', [
            'documentos' => DocumentoProveedor::where('proveedor_id', $id)
                ->orderBy('created_at', 'desc')
                ->get(),
        ]);
    }

    public function uploadFoto(Request $request, int $id): JsonResponse
    {
        $proveedor = Proveedor::find($id);
        if (!$proveedor) {
            return $this->error('Proveedor no encontrado', 404);
        }

        $this->authorize('manage', $proveedor);

        $request->validate([
            'foto' => 'required|image|mimes:jpg,jpeg,png,webp|max:3072',
        ]);

        $file   = $request->file('foto');
        $nombre = time() . '_' . $file->getClientOriginalName();
        $ruta   = $file->storeAs('fotos/' . $id, $nombre, 'public');

        // Guardar ruta relativa (sin dominio) para que funcione en cualquier entorno.
        // El frontend prefija con la URL base del API.
        $relativePath = '/storage/' . $ruta;

        $proveedor->update(['foto_perfil' => $relativePath]);

        return $this->success('Foto de perfil actualizada', ['foto_perfil' => $relativePath]);
    }

    /**
     * Imagen de portada del perfil publico. Mismo contrato que uploadFoto: se
     * guarda la ruta relativa y el frontend la prefija con la URL base del
     * API. Se separa en su propia carpeta para que borrar una no toque la
     * otra.
     */
    public function uploadPortada(Request $request, int $id): JsonResponse
    {
        $proveedor = Proveedor::find($id);
        if (!$proveedor) {
            return $this->error('Proveedor no encontrado', 404);
        }

        // Portada = personalizacion de marca: exige Premium activo (task 2.4),
        // no solo ser dueno del perfil.
        $this->authorize('personalizarMarca', $proveedor);

        // La portada se muestra a lo ancho, asi que admite mas peso que el
        // avatar: 6 MB contra los 3 MB de la foto de perfil.
        $request->validate([
            'portada' => 'required|image|mimes:jpg,jpeg,png,webp|max:6144',
        ]);

        $file   = $request->file('portada');
        $nombre = time() . '_' . $file->getClientOriginalName();
        $ruta   = $file->storeAs('portadas/' . $id, $nombre, 'public');

        $relativePath = '/storage/' . $ruta;

        $proveedor->update(['portada' => $relativePath]);

        return $this->success('Portada actualizada', ['portada' => $relativePath]);
    }

    /**
     * Quita la portada y devuelve el perfil al degradado de marca. Es una
     * accion propia y no un update con null porque el formulario manda campos
     * parciales: sin esto no habria forma de distinguir "no toques la portada"
     * de "borrala".
     */
    public function deletePortada(Request $request, int $id): JsonResponse
    {
        $proveedor = Proveedor::find($id);
        if (!$proveedor) {
            return $this->error('Proveedor no encontrado', 404);
        }

        $this->authorize('personalizarMarca', $proveedor);

        $proveedor->update(['portada' => null]);

        return $this->success('Portada eliminada', ['portada' => null]);
    }

    public function uploadDocumento(Request $request, int $id): JsonResponse
    {
        $proveedor = Proveedor::find($id);
        if (!$proveedor) {
            return $this->error('Proveedor no encontrado', 404);
        }

        $this->authorize('manage', $proveedor);

        $request->validate([
            'documento'      => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'tipo_documento' => 'required|string|max:100',
        ]);

        $file          = $request->file('documento');
        $nombreArchivo = time() . '_' . $file->getClientOriginalName();
        // Disco privado: los documentos de identidad nunca deben quedar
        // accesibles por una URL publica. Se sirven solo via descargarDocumento().
        $ruta          = $file->storeAs('documentos/' . $id, $nombreArchivo, 'local');

        $documento = DocumentoProveedor::create([
            'proveedor_id'      => $id,
            'tipo_documento'    => $request->tipo_documento,
            'nombre_archivo'    => $file->getClientOriginalName(),
            'ruta_archivo'      => $ruta,
            'estado_validacion' => 'pendiente',
        ]);

        return $this->success('Documento subido correctamente', ['documento' => $documento], 201);
    }

    public function descargarDocumento(Request $request, int $id, int $documentoId): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        // La autorizacion se deriva del dueno real del documento, no del {id}
        // de la ruta: un id de proveedor manipulado en la URL no debe alterar
        // el resultado.
        $documento = DocumentoProveedor::with('proveedor')->find($documentoId);
        if (!$documento || !$documento->proveedor) {
            return $this->error('Documento no encontrado', 404);
        }

        $this->authorize('manage', $documento->proveedor);

        return Storage::disk('local')->download($documento->ruta_archivo, $documento->nombre_archivo);
    }

    /**
     * 23505 es el SQLSTATE de unique_violation en PostgreSQL.
     */
    private function esViolacionDeUnicidad(QueryException $e): bool
    {
        return (string) $e->getCode() === '23505';
    }
}
