<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Models\CreditoProveedor;
use App\Models\Notificacion;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\Servicio;
use App\Models\TransaccionCredito;
use App\Http\Resources\ServicioResource;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CotizacionController extends Controller
{
    use ApiResponse;

    private const SLOTS_GRATIS = 3;
    private const MAX_COTIZACIONES = 6;
    private const COSTO_CREDITO = 1;

    /**
     * POST /api/pedidos/{pedidoId}/cotizaciones
     * El proveedor autenticado envía una cotización a un pedido abierto.
     *
     * Reglas de negocio (Sprint 5): las primeras 3 cotizaciones de un pedido son
     * gratuitas, de la 4ta a la 6ta cuestan 1 crédito, y no se aceptan más de 6.
     */
    public function store(Request $request, int $pedidoId): JsonResponse
    {
        $validated = $request->validate([
            'monto'   => 'required|numeric|min:1|max:999999.99',
            'mensaje' => 'required|string|min:20|max:500',
        ]);

        $proveedor = Proveedor::where('user_id', $request->user()->id)->firstOrFail();

        $pedido = Pedido::where('estado', 'abierto')
            ->where('fecha_expiracion', '>', now())
            ->findOrFail($pedidoId);

        $existe = Cotizacion::where('pedido_id', $pedidoId)
            ->where('proveedor_id', $proveedor->id)
            ->exists();

        if ($existe) {
            return $this->error('Ya enviaste una cotización para este pedido.', 422);
        }

        $resultado = DB::transaction(function () use ($pedidoId, $proveedor, $validated) {
            // Bloquea la fila del pedido para serializar el conteo de slots
            // cuando llegan cotizaciones concurrentes de distintos proveedores.
            Pedido::query()->lockForUpdate()->find($pedidoId);

            $numeroSlot = Cotizacion::where('pedido_id', $pedidoId)->count() + 1;

            if ($numeroSlot > self::MAX_COTIZACIONES) {
                return ['error' => 'Este pedido ya alcanzó el máximo de 6 cotizaciones.', 'status' => 422];
            }

            $esCobrable = $numeroSlot > self::SLOTS_GRATIS;
            $nuevoSaldo = null;

            if ($esCobrable) {
                $credito = CreditoProveedor::where('proveedor_id', $proveedor->id)
                    ->lockForUpdate()
                    ->first();

                if (!$credito) {
                    $credito = CreditoProveedor::create([
                        'proveedor_id' => $proveedor->id,
                        'saldo'        => 0,
                        'updated_at'   => now(),
                    ]);
                }

                if ($credito->saldo < self::COSTO_CREDITO) {
                    return ['error' => 'Saldo insuficiente para enviar esta cotización. Recarga créditos para continuar.', 'status' => 422];
                }

                $credito->decrement('saldo', self::COSTO_CREDITO);
                $nuevoSaldo = $credito->fresh()->saldo;

                TransaccionCredito::create([
                    'proveedor_id'  => $proveedor->id,
                    'tipo'          => 'gasto',
                    'monto'         => self::COSTO_CREDITO,
                    'motivo'        => "Cotización #{$numeroSlot} en pedido #{$pedidoId}",
                    'referencia_id' => $pedidoId,
                ]);
            }

            $cotizacion = Cotizacion::create([
                'pedido_id'      => $pedidoId,
                'proveedor_id'   => $proveedor->id,
                'monto'          => $validated['monto'],
                'mensaje'        => $validated['mensaje'],
                'estado'         => 'enviada',
                'costo_creditos' => $esCobrable ? self::COSTO_CREDITO : 0,
            ]);

            return ['cotizacion' => $cotizacion, 'nuevo_saldo' => $nuevoSaldo];
        });

        if (isset($resultado['error'])) {
            return $this->error($resultado['error'], $resultado['status']);
        }

        return $this->success('Cotización enviada correctamente.', $resultado, 201);
    }

    /**
     * PUT /api/pedidos/{pedidoId}/cotizaciones/{cotizacionId}
     * El proveedor edita su propia cotización. Cuesta 1 crédito por edición.
     */
    public function update(Request $request, int $pedidoId, int $cotizacionId): JsonResponse
    {
        $validated = $request->validate([
            'monto'   => 'required|numeric|min:1|max:999999.99',
            'mensaje' => 'required|string|min:20|max:500',
        ]);

        $proveedor = Proveedor::where('user_id', $request->user()->id)->firstOrFail();

        $cotizacion = Cotizacion::where('id', $cotizacionId)
            ->where('pedido_id', $pedidoId)
            ->where('proveedor_id', $proveedor->id)
            ->firstOrFail();

        $credito     = CreditoProveedor::where('proveedor_id', $proveedor->id)->first();
        $saldoActual = $credito?->saldo ?? 0;

        if ($saldoActual < 1) {
            return $this->error('Saldo insuficiente. Recarga créditos para editar tu cotización.', 422);
        }

        $nuevoSaldo = null;

        DB::transaction(function () use ($proveedor, $pedidoId, $cotizacionId, $credito, $cotizacion, $validated, &$nuevoSaldo) {
            $credito->decrement('saldo', 1);
            $nuevoSaldo = $credito->fresh()->saldo;

            TransaccionCredito::create([
                'proveedor_id'  => $proveedor->id,
                'tipo'          => 'gasto',
                'monto'         => 1,
                'motivo'        => "Edición de cotización #{$cotizacionId} en pedido #{$pedidoId}",
                'referencia_id' => $pedidoId,
            ]);

            $cotizacion->update([
                'monto'   => $validated['monto'],
                'mensaje' => $validated['mensaje'],
            ]);
        });

        return $this->success('Cotización actualizada correctamente.', [
            'cotizacion'  => $cotizacion->fresh(),
            'nuevo_saldo' => $nuevoSaldo,
        ]);
    }

    /**
     * POST /api/pedidos/{pedidoId}/cotizaciones/{cotizacionId}/aceptar
     * El cliente propietario adjudica su pedido a una cotización enviada.
     */
    public function aceptar(Request $request, int $pedidoId, int $cotizacionId): JsonResponse
    {
        $resultado = DB::transaction(function () use ($request, $pedidoId, $cotizacionId) {
            $pedido = Pedido::query()
                ->lockForUpdate()
                ->find($pedidoId);

            if (!$pedido) {
                return ['error' => 'Pedido no encontrado.', 'status' => 404];
            }

            if ($pedido->cliente_id !== $request->user()->id) {
                return ['error' => 'No tienes permiso para aceptar cotizaciones de este pedido.', 'status' => 403];
            }

            $cotizacion = Cotizacion::query()
                ->where('pedido_id', $pedido->id)
                ->lockForUpdate()
                ->find($cotizacionId);

            if (!$cotizacion) {
                return ['error' => 'Cotización no encontrada para este pedido.', 'status' => 404];
            }

            if ($pedido->estado !== 'abierto') {
                return ['error' => 'Solo se pueden adjudicar pedidos abiertos.', 'status' => 422];
            }

            if ($pedido->fecha_expiracion->isPast()) {
                $pedido->update(['estado' => 'expirado']);

                return ['error' => 'El pedido ya expiró.', 'status' => 422];
            }

            if ($cotizacion->estado !== 'enviada') {
                return ['error' => 'Solo se pueden aceptar cotizaciones enviadas.', 'status' => 422];
            }

            $servicio = Servicio::create([
                'cliente_id'     => $pedido->cliente_id,
                'proveedor_id'   => $cotizacion->proveedor_id,
                'categoria_id'   => $pedido->categoria_id,
                'direccion'      => $pedido->direccion,
                'descripcion'    => $pedido->descripcion,
                'monto_acordado' => $cotizacion->monto,
                'estado'         => 'aceptado',
                'codigo_inicio'  => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            ]);

            // Se capturan las perdedoras antes del update masivo: despues todas
            // quedan en `rechazada` y ya no se distingue cuales competian de
            // verdad de las que el proveedor habia retirado.
            $perdedoras = Cotizacion::query()
                ->where('pedido_id', $pedido->id)
                ->where('id', '!=', $cotizacion->id)
                ->where('estado', 'enviada')
                ->with('proveedor')
                ->get();

            $cotizacion->update(['estado' => 'aceptada']);

            Cotizacion::query()
                ->where('pedido_id', $pedido->id)
                ->where('id', '!=', $cotizacion->id)
                ->where('estado', 'enviada')
                ->update(['estado' => 'rechazada']);

            $pedido->update(['estado' => 'adjudicado']);

            $this->notificarAdjudicacion($pedido, $cotizacion, $servicio, $perdedoras);

            return [
                'pedido'     => $pedido->fresh(),
                'cotizacion' => $cotizacion->fresh()->load('proveedor'),
                'servicio'   => new ServicioResource($servicio->load(['cliente', 'proveedor.categoria', 'categoria'])),
            ];
        });

        if (isset($resultado['error'])) {
            return $this->error($resultado['error'], $resultado['status']);
        }

        return $this->success('Cotización aceptada y servicio creado correctamente.', $resultado);
    }

    /**
     * Notifica la adjudicación de un pedido: al proveedor ganador y a los que
     * cotizaron y no fueron seleccionados.
     *
     * `destinatario_id` es un id de users, no de proveedores; por eso se resuelve
     * a través de proveedor->user_id, igual que en ServicioController.
     *
     * @param  \Illuminate\Support\Collection<int, Cotizacion>  $perdedoras
     */
    private function notificarAdjudicacion(
        Pedido $pedido,
        Cotizacion $ganadora,
        Servicio $servicio,
        $perdedoras,
    ): void {
        $monto = number_format((float) $ganadora->monto, 2);

        $ganador = $ganadora->proveedor;
        if ($ganador?->user_id) {
            Notificacion::create([
                'destinatario_id' => $ganador->user_id,
                'tipo'            => 'cotizacion_aceptada',
                'titulo'          => 'Tu cotización fue aceptada',
                'mensaje'         => "El cliente adjudicó el pedido a tu cotización de Q{$monto}. "
                                     . 'Pídele el código de inicio para arrancar el servicio.',
                'datos'           => [
                    'pedido_id'     => $pedido->id,
                    'cotizacion_id' => $ganadora->id,
                    'servicio_id'   => $servicio->id,
                ],
            ]);
        }

        foreach ($perdedoras as $perdedora) {
            $proveedor = $perdedora->proveedor;

            if (!$proveedor?->user_id) {
                continue;
            }

            Notificacion::create([
                'destinatario_id' => $proveedor->user_id,
                'tipo'            => 'cotizacion_rechazada',
                'titulo'          => 'El pedido se adjudicó a otro proveedor',
                'mensaje'         => 'El cliente eligió otra cotización para este pedido. '
                                     . 'Sigue revisando oportunidades abiertas.',
                'datos'           => [
                    'pedido_id'     => $pedido->id,
                    'cotizacion_id' => $perdedora->id,
                ],
            ]);
        }
    }
}
