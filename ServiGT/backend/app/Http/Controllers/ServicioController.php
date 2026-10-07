<?php

namespace App\Http\Controllers;

use App\Models\Notificacion;
use App\Models\Proveedor;
use App\Models\PublicacionServicio;
use App\Models\Servicio;
use App\Http\Resources\ServicioResource;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServicioController extends Controller
{
    use ApiResponse;

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'proveedor_id'   => 'required_without:publicacion_id|integer|exists:proveedores,id',
            'publicacion_id' => 'sometimes|nullable|integer',
            'categoria_id'   => 'sometimes|nullable|integer|exists:categorias,id',
            'descripcion'    => 'required|string|max:1000',
            'fecha_agendada' => 'nullable|date|after:now',
            'direccion'      => 'nullable|string|max:500',
            'monto_acordado' => 'nullable|numeric|min:0',
        ]);

        $user = $request->user();

        // Solo un cliente contrata. Antes bastaba con estar autenticado, asi
        // que un proveedor podia crear servicios y aparecer en ambos lados.
        if ($user->role !== 'cliente') {
            return $this->error('Solo un cliente puede solicitar un servicio.', 403);
        }

        if (!empty($validated['publicacion_id'])) {
            $publicacion = $this->publicacionContratable((int) $validated['publicacion_id']);

            if (!$publicacion) {
                return $this->error('Publicacion no encontrada.', 404);
            }

            if (!$this->publicacionEstaVisible($publicacion)) {
                return $this->error('La publicacion no esta disponible para contratacion.', 422);
            }

            $validated['proveedor_id'] = $publicacion->proveedor_id;
            $validated['categoria_id'] = $publicacion->categoria_id;
            $validated['publicacion_titulo'] = $publicacion->titulo;
            $validated['publicacion_precio_referencial'] = $publicacion->precio_referencial;
            $validated['monto_acordado'] = $publicacion->precio_referencial;
        } else {
            unset($validated['publicacion_id']);
            $validated['categoria_id'] = $validated['categoria_id'] ?? null;
        }

        $proveedorSolicitado = Proveedor::find($validated['proveedor_id']);
        if ($proveedorSolicitado && $proveedorSolicitado->user_id === $user->id) {
            return $this->error('No puedes solicitarte un servicio a ti mismo.', 403);
        }

        $validated['cliente_id']    = $user->id;
        $validated['estado']        = 'pendiente';
        $validated['codigo_inicio'] = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $servicio = DB::transaction(function () use ($request, $validated) {
            $servicio = Servicio::create($validated);
            $servicio->load(['cliente', 'proveedor', 'categoria', 'publicacion']);

            $proveedor = $servicio->proveedor;
            if ($proveedor?->user_id) {
                Notificacion::create([
                    'destinatario_id' => $proveedor->user_id,
                    'tipo'            => 'nueva_solicitud',
                    'titulo'          => 'Nueva solicitud de servicio',
                    'mensaje'         => "{$request->user()->name} solicito tu servicio: {$validated['descripcion']}",
                    'datos'           => ['servicio_id' => $servicio->id],
                ]);
            }

            return $servicio;
        });

        return $this->success('Solicitud enviada exitosamente', [
            'servicio' => new ServicioResource($servicio),
        ], 201);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $servicio = Servicio::with(['cliente', 'proveedor.categoria', 'categoria', 'calificaciones.autor:id,name', 'pago'])
            ->find($id);

        if (!$servicio) {
            return $this->error('Servicio no encontrado', 404);
        }

        $userId          = $request->user()->id;
        $proveedorUserId = $servicio->proveedor?->user_id;

        if ($servicio->cliente_id !== $userId && $proveedorUserId !== $userId) {
            return $this->error('No tienes permiso para ver este servicio', 403);
        }

        return $this->success('OK', ['servicio' => new ServicioResource($servicio)]);
    }

    public function solicitudesProveedor(Request $request): JsonResponse
    {
        $user      = $request->user();
        $proveedor = $user->proveedor;

        if (!$proveedor) {
            return $this->error('No tienes perfil de proveedor', 404);
        }

        $estado = $request->query('estado');
        $query  = Servicio::with(['cliente', 'categoria'])
            ->where('proveedor_id', $proveedor->id);

        if ($estado) {
            $query->where('estado', $estado);
        }

        $servicios = $query->orderBy('created_at', 'desc')->get();

        return $this->success('OK', [
            'servicios' => ServicioResource::collection($servicios),
            'total' => $servicios->count(),
        ]);
    }

    public function solicitudesCliente(Request $request): JsonResponse
    {
        $estado = $request->query('estado');
        $query  = Servicio::with(['proveedor.categoria', 'categoria', 'calificaciones.autor:id,name'])
            ->where('cliente_id', $request->user()->id);

        if ($estado) {
            $query->where('estado', $estado);
        }

        $servicios = $query->orderBy('created_at', 'desc')->get();

        return $this->success('OK', ['servicios' => ServicioResource::collection($servicios)]);
    }

    public function aceptar(int $id, Request $request): JsonResponse
    {
        $servicio = DB::transaction(function () use ($id, $request) {
            $servicio = $this->lockedServicioProveedor($id, $request);
            if ($servicio instanceof JsonResponse) return $servicio;
            if ($servicio->estado !== 'pendiente') {
                return $this->error('Solo se pueden aceptar solicitudes pendientes', 422);
            }
            $servicio->update(['estado' => 'aceptado']);
            Notificacion::create([
                'destinatario_id' => $servicio->cliente_id,
                'tipo'            => 'solicitud_aceptada',
                'titulo'          => 'Solicitud aceptada',
                'mensaje'         => 'El proveedor acepto tu solicitud de servicio.',
                'datos'           => ['servicio_id' => $servicio->id],
            ]);
            return $servicio;
        });
        if ($servicio instanceof JsonResponse) return $servicio;

        $servicio->loadMissing(['cliente', 'proveedor.categoria', 'categoria']);

        return $this->success('Solicitud aceptada', ['servicio' => new ServicioResource($servicio)]);
    }

    public function rechazar(int $id, Request $request): JsonResponse
    {
        $request->validate(['motivo' => 'nullable|string|max:500']);
        $servicio = DB::transaction(function () use ($id, $request) {
            $servicio = $this->lockedServicioProveedor($id, $request);
            if ($servicio instanceof JsonResponse) return $servicio;
            if ($servicio->estado !== 'pendiente') {
                return $this->error('Solo se pueden rechazar solicitudes pendientes', 422);
            }
            $servicio->update(['estado' => 'rechazado', 'motivo_cancelacion' => $request->motivo]);
            Notificacion::create([
                'destinatario_id' => $servicio->cliente_id,
                'tipo'            => 'solicitud_rechazada',
                'titulo'          => 'Solicitud rechazada',
                'mensaje'         => 'El proveedor no pudo aceptar tu solicitud.',
                'datos'           => ['servicio_id' => $servicio->id],
            ]);
            return $servicio;
        });
        if ($servicio instanceof JsonResponse) return $servicio;

        $servicio->loadMissing(['cliente', 'proveedor.categoria', 'categoria']);

        return $this->success('Solicitud rechazada', ['servicio' => new ServicioResource($servicio)]);
    }

    public function actualizarEstado(int $id, Request $request): JsonResponse
    {
        $servicio = DB::transaction(function () use ($id, $request) {
            $servicio = $this->lockedServicioProveedor($id, $request);
            if ($servicio instanceof JsonResponse) return $servicio;
            $request->validate(['estado' => 'required|in:en_camino']);
            if ($servicio->estado !== 'aceptado') {
                return $this->error('Solo un servicio aceptado puede pasar a en camino', 422);
            }
            $servicio->update(['estado' => 'en_camino']);
            return $servicio;
        });
        if ($servicio instanceof JsonResponse) return $servicio;

        $servicio->loadMissing(['cliente', 'proveedor.categoria', 'categoria']);

        return $this->success('Estado actualizado', ['servicio' => new ServicioResource($servicio)]);
    }

    public function iniciar(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'codigo' => 'required|string|size:6',
        ]);

        $servicio = DB::transaction(function () use ($id, $request) {
            $servicio = $this->lockedServicioProveedor($id, $request);
            if ($servicio instanceof JsonResponse) return $servicio;
            if (!in_array($servicio->estado, ['aceptado', 'en_camino'], true)) {
                return $this->error('Solo se puede iniciar un servicio aceptado o en camino', 422);
            }
            if (!hash_equals((string) $servicio->codigo_inicio, (string) $request->codigo)) {
                return $this->error('Codigo de inicio invalido', 422);
            }
            $servicio->update(['estado' => 'en_progreso']);
            Notificacion::create([
                'destinatario_id' => $servicio->cliente_id,
                'tipo'            => 'servicio_iniciado',
                'titulo'          => 'Servicio iniciado',
                'mensaje'         => 'El proveedor inicio el servicio.',
                'datos'           => ['servicio_id' => $servicio->id],
            ]);
            return $servicio;
        });
        if ($servicio instanceof JsonResponse) return $servicio;

        $servicio->loadMissing(['cliente', 'proveedor.categoria', 'categoria']);

        return $this->success('Servicio iniciado', ['servicio' => new ServicioResource($servicio)]);
    }

    public function finalizar(int $id, Request $request): JsonResponse
    {
        $resultado = DB::transaction(function () use ($id, $request) {
            $servicio = $this->lockedServicioProveedor($id, $request);
            if ($servicio instanceof JsonResponse) return $servicio;
            if ($servicio->estado !== 'en_progreso') {
                return $this->error('Solo se puede finalizar un servicio en progreso', 422);
            }
            $codigoFin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $servicio->update(['codigo_fin' => $codigoFin, 'estado' => 'por_confirmar']);
            Notificacion::create([
                'destinatario_id' => $servicio->cliente_id,
                'tipo'            => 'servicio_por_confirmar',
                'titulo'          => 'Confirma la finalizacion del servicio',
                'mensaje'         => 'El proveedor termino el trabajo. Pide el codigo de 6 digitos para confirmar la finalizacion.',
                'datos'           => ['servicio_id' => $servicio->id],
            ]);
            return [$servicio, $codigoFin];
        });
        if ($resultado instanceof JsonResponse) return $resultado;
        [$servicio, $codigoFin] = $resultado;

        $request->attributes->set('exponer_codigo_fin_servicio', $servicio->id);

        return $this->success('Servicio listo para confirmar', [
            'servicio'   => new ServicioResource($servicio->loadMissing(['cliente', 'proveedor.categoria', 'categoria'])),
            'codigo_fin' => $codigoFin,
        ]);
    }

    public function confirmarFin(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'codigo' => 'required|string|size:6',
        ]);

        $servicio = DB::transaction(function () use ($id, $request) {
            $servicio = $this->lockedServicioCliente($id, $request);
            if ($servicio instanceof JsonResponse) return $servicio;
            if ($servicio->estado !== 'por_confirmar') {
                return $this->error('Este servicio no esta esperando confirmacion', 422);
            }
            if (!hash_equals((string) $servicio->codigo_fin, (string) $request->codigo)) {
                return $this->error('Codigo incorrecto', 422);
            }
            $servicio->update(['estado' => 'completado']);
            $servicio->loadMissing('proveedor');
            if ($servicio->proveedor?->user_id) {
                Notificacion::create([
                    'destinatario_id' => $servicio->proveedor->user_id,
                    'tipo'            => 'servicio_completado',
                    'titulo'          => 'Servicio confirmado',
                    'mensaje'         => 'El cliente confirmo la finalizacion del servicio.',
                    'datos'           => ['servicio_id' => $servicio->id],
                ]);
            }
            $this->notificarServicioCalificable($servicio);
            return $servicio;
        });
        if ($servicio instanceof JsonResponse) return $servicio;

        $servicio->loadMissing(['cliente', 'proveedor.categoria', 'categoria']);

        return $this->success('Servicio completado', ['servicio' => new ServicioResource($servicio)]);
    }

    /**
     * Cancela un servicio únicamente desde la cuenta cliente propietaria.
     * El pedido/cotización de origen y los créditos no se modifican.
     */
    public function cancelar(int $id, Request $request): JsonResponse
    {
        $request->validate(['motivo' => 'nullable|string|max:500']);
        $servicio = DB::transaction(function () use ($id, $request) {
            $servicio = $this->lockedServicioCliente($id, $request);
            if ($servicio instanceof JsonResponse) return $servicio;
            if (!in_array($servicio->estado, ['pendiente', 'aceptado', 'en_camino'], true)) {
                return $this->error('Este servicio ya no puede cancelarse en su estado actual', 422);
            }
            $servicio->update([
                'estado' => 'cancelado',
                'motivo_cancelacion' => $request->input('motivo'),
            ]);
            $servicio->loadMissing('proveedor');
            if ($servicio->proveedor?->user_id) {
                Notificacion::create([
                    'destinatario_id' => $servicio->proveedor->user_id,
                    'tipo'            => 'servicio_cancelado',
                    'titulo'          => 'Servicio cancelado por el cliente',
                    'mensaje'         => 'El cliente cancelo la solicitud de servicio.',
                    'datos'           => ['servicio_id' => $servicio->id],
                ]);
            }
            return $servicio;
        });
        if ($servicio instanceof JsonResponse) return $servicio;

        $servicio->loadMissing(['cliente', 'proveedor.categoria', 'categoria']);
        return $this->success('Servicio cancelado', ['servicio' => new ServicioResource($servicio)]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function getServicioProveedor(int $id, Request $request)
    {
        $servicio = Servicio::find($id);
        if (!$servicio) {
            return $this->error('Servicio no encontrado', 404);
        }

        $proveedor = $request->user()->proveedor;
        if (!$proveedor || $servicio->proveedor_id !== $proveedor->id) {
            return $this->error('No tienes permiso para gestionar este servicio', 403);
        }

        return $servicio;
    }

    private function getServicioCliente(int $id, Request $request)
    {
        $servicio = Servicio::find($id);
        if (!$servicio) {
            return $this->error('Servicio no encontrado', 404);
        }

        if ($servicio->cliente_id !== $request->user()->id) {
            return $this->error('No tienes permiso para confirmar este servicio', 403);
        }

        return $servicio;
    }

    private function lockedServicioProveedor(int $id, Request $request)
    {
        $servicio = Servicio::whereKey($id)->lockForUpdate()->first();
        if (!$servicio) return $this->error('Servicio no encontrado', 404);
        $proveedor = $request->user()->proveedor;
        if (!$proveedor || $servicio->proveedor_id !== $proveedor->id) {
            return $this->error('No tienes permiso para gestionar este servicio', 403);
        }
        return $servicio;
    }

    private function lockedServicioCliente(int $id, Request $request)
    {
        $servicio = Servicio::whereKey($id)->lockForUpdate()->first();
        if (!$servicio) return $this->error('Servicio no encontrado', 404);
        if ($servicio->cliente_id !== $request->user()->id) {
            return $this->error('No tienes permiso para gestionar este servicio', 403);
        }
        return $servicio;
    }

    private function notificarServicioCalificable(Servicio $servicio): void
    {
        $yaExiste = Notificacion::where('destinatario_id', $servicio->cliente_id)
            ->where('tipo', 'servicio_calificable')
            ->where('datos->servicio_id', $servicio->id)
            ->exists();

        if ($yaExiste) {
            return;
        }

        $servicio->loadMissing('proveedor');

        Notificacion::create([
            'destinatario_id' => $servicio->cliente_id,
            'tipo'            => 'servicio_calificable',
            'titulo'          => 'Servicio listo para calificar',
            'mensaje'         => 'El servicio fue completado. Ya puedes calificar a ' . ($servicio->proveedor?->nombre ?? 'tu proveedor') . '.',
            'datos'           => ['servicio_id' => $servicio->id],
        ]);
    }

    private function publicacionContratable(int $id): ?PublicacionServicio
    {
        return PublicacionServicio::with(['proveedor', 'categoria'])->find($id);
    }

    private function publicacionEstaVisible(PublicacionServicio $publicacion): bool
    {
        if ($publicacion->estado !== 'activa' || !$publicacion->proveedor) {
            return false;
        }

        $limite = $publicacion->proveedor->premiumEstado() === 'activo' ? 3 : 1;

        return PublicacionServicio::where('proveedor_id', $publicacion->proveedor_id)
            ->where('estado', 'activa')
            ->orderBy('created_at')
            ->limit($limite)
            ->pluck('id')
            ->contains($publicacion->id);
    }
}
