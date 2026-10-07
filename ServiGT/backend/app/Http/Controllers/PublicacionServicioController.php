<?php

namespace App\Http\Controllers;

use App\Http\Resources\PublicacionServicioResource;
use App\Models\Proveedor;
use App\Models\PublicacionServicio;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PublicacionServicioController extends Controller
{
    use ApiResponse;

    private const LIMITE_GRATIS = 1;
    private const LIMITE_PREMIUM = 3;

    /**
     * GET /api/publicaciones
     * Catalogo publico paginado. Solo muestra publicaciones activas.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'categoria_id' => 'sometimes|integer|exists:categorias,id',
            'proveedor_id' => 'sometimes|integer|exists:proveedores,id',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        $query = PublicacionServicio::query()
            ->with(['proveedor', 'categoria'])
            ->where('estado', 'activa')
            ->orderByDesc('created_at');

        if (isset($validated['categoria_id'])) {
            $query->where('categoria_id', $validated['categoria_id']);
        }

        if (isset($validated['proveedor_id'])) {
            $query->where('proveedor_id', $validated['proveedor_id']);
        }

        // La ventana de visibilidad (limite gratis/Premium) se aplica en PHP,
        // asi que la paginacion tambien se resuelve aqui: paginar en la BD
        // antes de filtrar dejaria un meta.total/last_page que no corresponde
        // a lo que realmente se devuelve.
        $perPage = (int) ($validated['per_page'] ?? 15);
        $page = (int) $request->input('page', 1);

        $visiblesTotal = $this->filtrarVisiblesPorLimite($query->get());
        $itemsPagina = $visiblesTotal->forPage($page, $perPage)->values();

        $publicaciones = new \Illuminate\Pagination\LengthAwarePaginator(
            $itemsPagina,
            $visiblesTotal->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return $this->success('OK', [
            'publicaciones' => PublicacionServicioResource::collection($publicaciones->items()),
            'meta' => [
                'total' => $publicaciones->total(),
                'per_page' => $publicaciones->perPage(),
                'current_page' => $publicaciones->currentPage(),
                'last_page' => $publicaciones->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/publicaciones/{id}
     * Detalle publico de una publicacion visible.
     */
    public function show(int $id): JsonResponse
    {
        $publicacion = PublicacionServicio::with(['proveedor', 'categoria'])
            ->where('estado', 'activa')
            ->findOrFail($id);

        $limite = $this->limiteEfectivo($publicacion->proveedor);

        $esVisible = PublicacionServicio::where('proveedor_id', $publicacion->proveedor_id)
            ->where('estado', 'activa')
            ->orderBy('created_at')
            ->limit($limite)
            ->pluck('id')
            ->contains($publicacion->id);

        if (!$esVisible) {
            abort(404);
        }

        return $this->success('OK', ['publicacion' => new PublicacionServicioResource($publicacion)]);
    }

    /**
     * GET /api/publicaciones/mias
     * Listado propio del proveedor autenticado, incluyendo inactivas.
     */
    public function mias(Request $request): JsonResponse
    {
        $proveedor = $this->proveedorAutenticado($request);
        if ($proveedor instanceof JsonResponse) {
            return $proveedor;
        }

        $publicaciones = PublicacionServicio::with(['proveedor', 'categoria'])
            ->where('proveedor_id', $proveedor->id)
            ->orderByDesc('created_at')
            ->get();

        $limite = $this->limiteEfectivo($proveedor);
        $activas = $publicaciones->where('estado', 'activa')->count();

        return $this->success('OK', [
            'publicaciones' => PublicacionServicioResource::collection($publicaciones),
            'total' => $publicaciones->count(),
            // Cupos: el frontend solo pinta este bloque, la regla 1 gratis / 3
            // Premium vive unicamente aqui (ver limiteEfectivo/verificarLimite).
            'cupos' => [
                'activas' => $activas,
                'limite' => $limite,
                'disponibles' => max(0, $limite - $activas),
            ],
        ]);
    }

    /**
     * POST /api/publicaciones
     * Crea una publicacion propia. El proveedor se deriva del token.
     */
    public function store(Request $request): JsonResponse
    {
        $proveedor = $this->proveedorAutenticado($request);
        if ($proveedor instanceof JsonResponse) {
            return $proveedor;
        }

        $validated = $this->validarPublicacion($request);
        unset($validated['imagen'], $validated['eliminar_imagen']);

        return DB::transaction(function () use ($request, $proveedor, $validated) {
            // Bloquea al proveedor antes de contar: serializa creaciones
            // concurrentes para que el limite gratis/Premium no se pueda superar.
            $proveedorLocked = Proveedor::query()->lockForUpdate()->find($proveedor->id);
            $this->normalizarExcedentes($proveedorLocked);

            $estadoDeseado = $validated['estado'] ?? 'activa';

            if ($estadoDeseado === 'activa') {
                $limiteError = $this->verificarLimite($proveedorLocked);
                if ($limiteError) {
                    return $limiteError;
                }
            }

            $validated['proveedor_id'] = $proveedorLocked->id;
            $validated['estado'] = $estadoDeseado;

            $publicacion = PublicacionServicio::create($validated);
            $this->guardarImagenSiViene($request, $publicacion);
            $publicacion->load(['proveedor', 'categoria']);

            return $this->success('Publicacion creada correctamente.', [
                'publicacion' => new PublicacionServicioResource($publicacion),
            ], 201);
        });
    }

    /**
     * PUT /api/publicaciones/{id}
     * Edita una publicacion propia. No acepta proveedor_id desde frontend.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $publicacion = $this->publicacionPropia($request, $id);
        if ($publicacion instanceof JsonResponse) {
            return $publicacion;
        }

        $validated = $this->validarPublicacion($request, parcial: true);
        unset($validated['imagen'], $validated['eliminar_imagen']);

        return DB::transaction(function () use ($request, $publicacion, $validated) {
            $proveedor = Proveedor::query()->lockForUpdate()->find($publicacion->proveedor_id);
            $this->normalizarExcedentes($proveedor);
            $publicacion->refresh();

            $estadoDeseado = $validated['estado'] ?? $publicacion->estado;

            if ($estadoDeseado === 'activa' && $publicacion->estado !== 'activa') {
                $limiteError = $this->verificarLimite($proveedor);
                if ($limiteError) {
                    return $limiteError;
                }
            }

            $publicacion->fill($validated);

            if ($request->boolean('eliminar_imagen')) {
                $this->eliminarImagenActual($publicacion);
                $publicacion->imagen = null;
            }

            $publicacion->save();
            $this->guardarImagenSiViene($request, $publicacion);
            $publicacion->load(['proveedor', 'categoria']);

            return $this->success('Publicacion actualizada correctamente.', [
                'publicacion' => new PublicacionServicioResource($publicacion),
            ]);
        });
    }

    /**
     * POST /api/publicaciones/{id}/activar
     */
    public function activar(Request $request, int $id): JsonResponse
    {
        return $this->cambiarEstado($request, $id, 'activa', 'Publicacion activada correctamente.');
    }

    /**
     * POST /api/publicaciones/{id}/desactivar
     */
    public function desactivar(Request $request, int $id): JsonResponse
    {
        return $this->cambiarEstado($request, $id, 'inactiva', 'Publicacion desactivada correctamente.');
    }

    /**
     * DELETE /api/publicaciones/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $publicacion = $this->publicacionPropia($request, $id);
        if ($publicacion instanceof JsonResponse) {
            return $publicacion;
        }

        $this->eliminarImagenActual($publicacion);
        $publicacion->delete();

        return $this->success('Publicacion eliminada correctamente.');
    }

    private function cambiarEstado(Request $request, int $id, string $estado, string $mensaje): JsonResponse
    {
        $publicacion = $this->publicacionPropia($request, $id);
        if ($publicacion instanceof JsonResponse) {
            return $publicacion;
        }

        return DB::transaction(function () use ($publicacion, $estado, $mensaje) {
            $proveedor = Proveedor::query()->lockForUpdate()->find($publicacion->proveedor_id);
            $this->normalizarExcedentes($proveedor);
            $publicacion->refresh();

            if ($estado === 'activa' && $publicacion->estado !== 'activa') {
                $limiteError = $this->verificarLimite($proveedor);
                if ($limiteError) {
                    return $limiteError;
                }
            }

            $publicacion->update(['estado' => $estado]);
            $publicacion->load(['proveedor', 'categoria']);

            return $this->success($mensaje, [
                'publicacion' => new PublicacionServicioResource($publicacion),
            ]);
        });
    }

    /**
     * Limite efectivo de publicaciones activas: 1 gratis, 3 con Premium
     * activo (Sprint 7). Se calcula siempre a partir de premiumEstado(), sin
     * un flag propio, para no desincronizarse del vencimiento real.
     */
    private function limiteEfectivo(Proveedor $proveedor): int
    {
        return $proveedor->premiumEstado() === 'activo'
            ? self::LIMITE_PREMIUM
            : self::LIMITE_GRATIS;
    }

    private function verificarLimite(Proveedor $proveedor): ?JsonResponse
    {
        $limite = $this->limiteEfectivo($proveedor);
        $activas = PublicacionServicio::where('proveedor_id', $proveedor->id)
            ->where('estado', 'activa')
            ->count();

        if ($activas >= $limite) {
            return $this->error("Alcanzaste el limite de publicaciones activas ({$limite}).", 422);
        }

        return null;
    }

    /**
     * Si el proveedor no tiene Premium activo (nunca o vencido) y quedaron
     * mas activas que el limite gratis -- por ejemplo tras vencer Premium --
     * desactiva las mas nuevas y deja solo la mas antigua. Se llama bajo el
     * lock del proveedor, antes de aplicar la operacion pedida, nunca desde
     * una lectura publica.
     */
    private function normalizarExcedentes(Proveedor $proveedor): void
    {
        if ($proveedor->premiumEstado() === 'activo') {
            return;
        }

        $excedentes = PublicacionServicio::where('proveedor_id', $proveedor->id)
            ->where('estado', 'activa')
            ->orderBy('created_at')
            ->skip(self::LIMITE_GRATIS)
            ->pluck('id');

        if ($excedentes->isNotEmpty()) {
            PublicacionServicio::whereIn('id', $excedentes)->update(['estado' => 'inactiva']);
        }
    }

    /**
     * Ventana de solo lectura para catalogo/detalle publico: si un proveedor
     * quedo con mas activas que su limite (p. ej. justo tras vencer Premium)
     * solo se muestran las mas antiguas hasta el limite, sin persistir nada.
     */
    private function filtrarVisiblesPorLimite(\Illuminate\Support\Collection $publicaciones): \Illuminate\Support\Collection
    {
        return $publicaciones
            ->groupBy('proveedor_id')
            ->flatMap(function ($grupo) {
                $proveedor = $grupo->first()->proveedor;
                $limite = $proveedor ? $this->limiteEfectivo($proveedor) : self::LIMITE_GRATIS;

                return $grupo->sortBy('created_at')->take($limite);
            })
            ->sortByDesc('created_at')
            ->values();
    }

    private function proveedorAutenticado(Request $request): Proveedor|JsonResponse
    {
        if ($request->user()?->role !== 'proveedor') {
            return $this->error('Solo los proveedores pueden administrar publicaciones.', 403);
        }

        $proveedor = Proveedor::where('user_id', $request->user()->id)->first();

        if (!$proveedor) {
            return $this->error('Debes completar tu perfil de proveedor antes de publicar.', 403);
        }

        return $proveedor;
    }

    private function publicacionPropia(Request $request, int $id): PublicacionServicio|JsonResponse
    {
        $proveedor = $this->proveedorAutenticado($request);
        if ($proveedor instanceof JsonResponse) {
            return $proveedor;
        }

        $publicacion = PublicacionServicio::with(['proveedor', 'categoria'])->find($id);

        if (!$publicacion) {
            return $this->error('Publicacion no encontrada.', 404);
        }

        if ($publicacion->proveedor_id !== $proveedor->id) {
            return $this->error('No tienes permiso para administrar esta publicacion.', 403);
        }

        return $publicacion;
    }

    private function validarPublicacion(Request $request, bool $parcial = false): array
    {
        $required = $parcial ? ['sometimes', 'required'] : ['required'];

        return $request->validate([
            'titulo' => [...$required, 'string', 'min:5', 'max:120'],
            'descripcion' => [...$required, 'string', 'min:20', 'max:1000'],
            'categoria_id' => ['sometimes', 'nullable', 'integer', 'exists:categorias,id'],
            'precio_referencial' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'estado' => ['sometimes', 'in:activa,inactiva'],
            'imagen' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'eliminar_imagen' => ['sometimes', 'boolean'],
        ]);
    }

    private function guardarImagenSiViene(Request $request, PublicacionServicio $publicacion): void
    {
        if (!$request->hasFile('imagen')) {
            return;
        }

        $this->eliminarImagenActual($publicacion);

        $file = $request->file('imagen');
        $nombre = $publicacion->id.'_'.time().'.'.$file->getClientOriginalExtension();
        $ruta = $file->storeAs('publicaciones/'.$publicacion->proveedor_id, $nombre, 'public');

        $publicacion->update(['imagen' => '/storage/'.$ruta]);
    }

    private function eliminarImagenActual(PublicacionServicio $publicacion): void
    {
        if (!$publicacion->imagen || !str_starts_with($publicacion->imagen, '/storage/')) {
            return;
        }

        Storage::disk('public')->delete(substr($publicacion->imagen, strlen('/storage/')));
    }
}
