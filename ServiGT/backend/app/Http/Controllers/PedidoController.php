<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePedidoRequest;
use App\Http\Resources\PedidoResource;
use App\Models\Pedido;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PedidoController extends Controller
{
    use ApiResponse;

    /**
     * POST /api/pedidos
     * Solo clientes autenticados pueden publicar pedidos.
     */
    public function store(StorePedidoRequest $request): JsonResponse
    {
        $pedido = Pedido::create([
            'cliente_id'       => $request->user()->id,
            'categoria_id'     => $request->categoria_id,
            'descripcion'      => $request->descripcion,
            'direccion'        => $request->direccion,
            'urgencia'         => $request->urgencia,
            'estado'           => 'abierto',
            'fecha_expiracion' => now()->addDays(7),
        ]);

        $pedido->load(['cliente', 'categoria']);

        return $this->success(
            'Pedido publicado correctamente',
            ['pedido' => new PedidoResource($pedido)],
            201
        );
    }

    /**
     * GET /api/pedidos/{id}
     * Detalle público de un pedido con cotizaciones anonimizadas.
     */
    public function show(int $id): JsonResponse
    {
        $pedido = Pedido::with(['cliente', 'categoria', 'cotizaciones.proveedor'])
            ->findOrFail($id);

        $authUser  = Auth::guard('sanctum')->user();
        $esCliente = $authUser?->id === $pedido->cliente_id;

        $cotizaciones = $pedido->cotizaciones
            ->sortBy('created_at')
            ->values()
            ->map(function ($c, $i) use ($esCliente) {
                if ($esCliente) {
                    return [
                        'id'         => $c->id,
                        'indice'     => $i,
                        'monto'      => (float) $c->monto,
                        'mensaje'    => $c->mensaje,
                        'estado'     => $c->estado,
                        'created_at' => $c->created_at?->toIso8601String(),
                        'proveedor'  => $c->proveedor ? [
                            'id'                    => $c->proveedor->id,
                            'nombre'                => $c->proveedor->nombre,
                            'foto_perfil'           => $c->proveedor->foto_perfil,
                            'calificacion_promedio' => (float) ($c->proveedor->calificacion_promedio ?? 0),
                        ] : null,
                    ];
                }
                return [
                    'indice'      => $i,
                    'monto'       => (float) $c->monto,
                    'calificacion'=> (float) ($c->proveedor?->calificacion_promedio ?? 0),
                    'estado'      => $c->estado,
                    'created_at'  => $c->created_at?->toIso8601String(),
                ];
            });

        $slotsTotal           = 3;
        $slotsUsados          = $pedido->cotizaciones->count();
        $slotsGratisRestantes = max(0, $slotsTotal - $slotsUsados);

        // Para proveedores autenticados: devolver su propia cotización si ya enviaron una
        $miCotizacion = null;
        if ($authUser && !$esCliente) {
            $proveedor = \App\Models\Proveedor::where('user_id', $authUser->id)->first();
            if ($proveedor) {
                $cot = $pedido->cotizaciones->firstWhere('proveedor_id', $proveedor->id);
                if ($cot) {
                    $miCotizacion = [
                        'id'      => $cot->id,
                        'monto'   => (float) $cot->monto,
                        'mensaje' => $cot->mensaje,
                        'estado'  => $cot->estado,
                    ];
                }
            }
        }

        return response()->json([
            'success'        => true,
            'message'        => 'OK',
            'pedido'         => new PedidoResource($pedido),
            'cotizaciones'   => $cotizaciones,
            'mi_cotizacion'  => $miCotizacion,
            'slot_info'      => [
                'slots_total'    => $slotsTotal,
                'slots_usados'   => $slotsUsados,
                'slots_gratis'   => $slotsGratisRestantes,
                'costo_creditos' => $slotsGratisRestantes > 0 ? 0 : 1,
            ],
        ]);
    }

    /**
     * GET /api/pedidos/abiertos
     * Listado público paginado. Filtros: categoria_id, page.
     */
    public function abiertos(Request $request): JsonResponse
    {
        $query = Pedido::query()
            ->with(['cliente', 'categoria'])
            ->withCount('cotizaciones')
            ->where('estado', 'abierto')
            ->where('fecha_expiracion', '>', now())
            ->orderByDesc('created_at');

        if ($request->filled('categoria_id')) {
            $query->where('categoria_id', (int) $request->categoria_id);
        }

        $pedidos = $query->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'pedidos' => PedidoResource::collection($pedidos->items()),
            'meta'    => [
                'total'        => $pedidos->total(),
                'per_page'     => $pedidos->perPage(),
                'current_page' => $pedidos->currentPage(),
                'last_page'    => $pedidos->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/pedidos/mios
     * Pedidos del usuario autenticado (cualquier estado).
     */
    public function mios(Request $request): JsonResponse
    {
        $pedidos = Pedido::query()
            ->with(['categoria'])
            ->withCount('cotizaciones')
            ->where('cliente_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'pedidos' => PedidoResource::collection($pedidos->items()),
            'meta'    => [
                'total'        => $pedidos->total(),
                'per_page'     => $pedidos->perPage(),
                'current_page' => $pedidos->currentPage(),
                'last_page'    => $pedidos->lastPage(),
            ],
        ]);
    }
}
