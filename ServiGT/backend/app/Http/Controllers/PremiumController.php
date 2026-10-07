<?php

namespace App\Http\Controllers;

use App\Models\CreditoProveedor;
use App\Models\Proveedor;
use App\Models\TransaccionCredito;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PremiumController extends Controller
{
    use ApiResponse;

    private const PRECIO_GTQ = 115;
    private const VIGENCIA_DIAS = 30;
    private const CREDITOS_POR_CICLO = 10;
    private const MOTIVO_BONO = 'Bono mensual Premium';

    private const BENEFICIOS = [
        [
            'clave' => 'badge',
            'titulo' => 'Badge Premium',
            'descripcion' => 'Distintivo Premium separado del sello de verificacion.',
        ],
        [
            'clave' => 'visibilidad',
            'titulo' => 'Impulso de visibilidad',
            'descripcion' => 'Mayor presencia visual limitada sin alterar reputacion ni relevancia.',
        ],
        [
            'clave' => 'creditos',
            'titulo' => '10 creditos por ciclo',
            'descripcion' => 'Bono de creditos en cada activacion o reactivacion Premium.',
        ],
        [
            'clave' => 'perfil',
            'titulo' => 'Perfil mejorado',
            'descripcion' => 'Preparacion para portada y personalizacion basica del perfil.',
        ],
    ];

    /**
     * POST /api/premium/activar
     * Activacion simulada de Premium por Q115. No se procesa ningun pago real.
     * Si el proveedor ya tiene un ciclo activo, la llamada es idempotente:
     * devuelve ese ciclo sin extender vigencia ni acreditar de nuevo.
     */
    public function activar(Request $request): JsonResponse
    {
        $proveedor = Proveedor::where('user_id', $request->user()->id)->first();

        if (!$proveedor) {
            return $this->error('Solo los proveedores pueden activar Premium.', 403);
        }

        $resultado = DB::transaction(function () use ($proveedor) {
            $proveedorLock = Proveedor::query()->lockForUpdate()->findOrFail($proveedor->id);
            $cicloNuevo = false;

            if (!$proveedorLock->premium_vence_at || $proveedorLock->premium_vence_at->isPast()) {
                $inicioAt = now();

                $proveedorLock->premium_inicio_at = $inicioAt;
                $proveedorLock->premium_vence_at = $inicioAt->copy()->addDays(self::VIGENCIA_DIAS);
                $proveedorLock->premium_renovaciones = ((int) $proveedorLock->premium_renovaciones) + 1;
                $proveedorLock->premium_ciclo_key = $this->generarCicloKey($proveedorLock, $inicioAt);
                $cicloNuevo = true;
            } elseif (!$proveedorLock->premium_ciclo_key) {
                $inicioAt = $proveedorLock->premium_inicio_at ?? now();
                $proveedorLock->premium_inicio_at = $inicioAt;
                $proveedorLock->premium_ciclo_key = $this->generarCicloKey($proveedorLock, $inicioAt);
            }

            $proveedorLock->save();

            $creditosAcreditados = $this->acreditarBonoPremiumSiFalta($proveedorLock);
            $credito = CreditoProveedor::where('proveedor_id', $proveedorLock->id)->first();

            return [
                'proveedor'             => $proveedorLock->fresh(),
                'ciclo_nuevo'           => $cicloNuevo,
                'creditos_acreditados'  => $creditosAcreditados,
                'saldo'                 => (int) ($credito?->saldo ?? 0),
            ];
        });

        $mensaje = $resultado['ciclo_nuevo']
            ? 'Premium activado correctamente.'
            : 'Premium ya estaba activo; no se duplico la acreditacion.';

        return $this->success($mensaje, array_merge(
            $this->formatearEstadoPremium($resultado['proveedor']),
            [
                'creditos_acreditados' => $resultado['creditos_acreditados'],
                'saldo'                => $resultado['saldo'],
                'ciclo_nuevo'          => $resultado['ciclo_nuevo'],
            ]
        ));
    }

    /**
     * GET /api/premium/mi-estado
     * Estado Premium del proveedor autenticado: nunca, activo o vencido.
     */
    public function miEstado(Request $request): JsonResponse
    {
        $proveedor = Proveedor::where('user_id', $request->user()->id)->first();

        if (!$proveedor) {
            return $this->error('Solo los proveedores tienen estado Premium.', 403);
        }

        return $this->success('Estado Premium obtenido correctamente.', $this->formatearEstadoPremium($proveedor));
    }

    private function acreditarBonoPremiumSiFalta(Proveedor $proveedor): int
    {
        $motivo = $this->motivoDelCiclo($proveedor);

        $yaAcreditado = TransaccionCredito::where('proveedor_id', $proveedor->id)
            ->where('tipo', 'bono')
            ->where('motivo', $motivo)
            ->exists();

        if ($yaAcreditado) {
            return 0;
        }

        $credito = CreditoProveedor::query()
            ->where('proveedor_id', $proveedor->id)
            ->lockForUpdate()
            ->first();

        if (!$credito) {
            $credito = CreditoProveedor::create([
                'proveedor_id' => $proveedor->id,
                'saldo'        => 0,
                'updated_at'   => now(),
            ]);
        }

        $credito->saldo += self::CREDITOS_POR_CICLO;
        $credito->updated_at = now();
        $credito->save();

        TransaccionCredito::create([
            'proveedor_id'  => $proveedor->id,
            'tipo'          => 'bono',
            'monto'         => self::CREDITOS_POR_CICLO,
            'motivo'        => $motivo,
            'referencia_id' => null,
        ]);

        return self::CREDITOS_POR_CICLO;
    }

    private function formatearEstadoPremium(Proveedor $proveedor): array
    {
        $proveedor->refresh();

        $estado = $proveedor->premiumEstado();
        $diasRestantes = $estado === 'activo'
            ? max(0, (int) ceil(now()->diffInDays($proveedor->premium_vence_at, false)))
            : 0;

        $ultimaAcreditacion = $proveedor->premium_ciclo_key
            ? TransaccionCredito::where('proveedor_id', $proveedor->id)
                ->where('tipo', 'bono')
                ->where('motivo', $this->motivoDelCiclo($proveedor))
                ->latest('created_at')
                ->first()
            : null;

        return [
            'estado'                => $estado,
            'activo'                => $estado === 'activo',
            'inicio_at'             => $proveedor->premium_inicio_at,
            'vence_at'              => $proveedor->premium_vence_at,
            'dias_restantes'        => $diasRestantes,
            'renovaciones'          => (int) ($proveedor->premium_renovaciones ?? 0),
            'precio_gtq'            => self::PRECIO_GTQ,
            'vigencia_dias'         => self::VIGENCIA_DIAS,
            'creditos_por_ciclo'    => self::CREDITOS_POR_CICLO,
            'ciclo_actual'          => $proveedor->premium_ciclo_key,
            'ultima_acreditacion_at'=> $ultimaAcreditacion?->created_at,
            'beneficios'            => self::BENEFICIOS,
            'nota_slots'            => 'Premium no modifica los slots de cotizacion durante Sprint 6.',
        ];
    }

    private function generarCicloKey(Proveedor $proveedor, Carbon $inicioAt): string
    {
        return sprintf('premium-%d-%s', $proveedor->id, $inicioAt->format('YmdHis'));
    }

    private function motivoDelCiclo(Proveedor $proveedor): string
    {
        return self::MOTIVO_BONO . ' (' . $proveedor->premium_ciclo_key . ')';
    }
}
