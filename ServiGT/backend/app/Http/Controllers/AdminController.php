<?php

namespace App\Http\Controllers;

use App\Models\CompraCredito;
use App\Models\Proveedor;
use App\Models\CreditoProveedor;
use App\Models\Servicio;
use App\Models\TransaccionCredito;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    use ApiResponse;

    public function stats(): JsonResponse
    {
        $totalUsuarios   = User::count();
        $totalClientes   = User::where('role', 'cliente')->count();
        $totalProveedores = User::where('role', 'proveedor')->count();
        $totalAdmins     = User::where('role', 'admin')->count();

        $proveedoresVerificados = User::where('role', 'proveedor')
            ->where('documento_verificado', true)
            ->count();

        $serviciosPorEstado = Servicio::select('estado', DB::raw('COUNT(*) as total'))
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $calificacionPromedioGlobal = (float) Proveedor::avg('calificacion_promedio');
        $creditosDisponibles = (int) CreditoProveedor::sum('saldo');
        $recargasManuales = (int) TransaccionCredito::where('tipo', 'recarga')->sum('monto');

        return response()->json([
            'usuarios' => [
                'total'        => $totalUsuarios,
                'clientes'     => $totalClientes,
                'proveedores'  => $totalProveedores,
                'admins'       => $totalAdmins,
            ],
            'proveedores' => [
                'total'       => Proveedor::count(),
                'verificados' => $proveedoresVerificados,
                'calificacion_promedio_global' => round($calificacionPromedioGlobal, 2),
                'creditos_disponibles' => $creditosDisponibles,
                'creditos_recargados' => $recargasManuales,
            ],
            'servicios' => [
                'total'      => Servicio::count(),
                'por_estado' => $serviciosPorEstado,
            ],
        ]);
    }

    public function listUsers(Request $request): JsonResponse
    {
        $role = $request->query('role');

        $query = User::query()->orderBy('created_at', 'desc');

        if (in_array($role, ['cliente', 'proveedor', 'admin'], true)) {
            $query->where('role', $role);
        }

        return response()->json([
            'usuarios' => $query->get([
                'id', 'name', 'email', 'role', 'telefono',
                'documento_verificado', 'created_at',
            ]),
        ]);
    }

    public function listProviders(): JsonResponse
    {
        $proveedores = Proveedor::with(['categoria', 'credito', 'user:id,email,documento_verificado'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'proveedores' => $proveedores,
        ]);
    }

    public function recargarCreditos(Request $request, int $proveedorId): JsonResponse
    {
        $validated = $request->validate([
            'monto'  => 'required|integer|min:1|max:10000',
            'motivo' => 'required|string|min:5|max:255',
        ]);

        $resultado = DB::transaction(function () use ($proveedorId, $validated) {
            $proveedor = Proveedor::query()
                ->lockForUpdate()
                ->findOrFail($proveedorId);

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

            $credito->saldo += (int) $validated['monto'];
            $credito->updated_at = now();
            $credito->save();

            $transaccion = TransaccionCredito::create([
                'proveedor_id'  => $proveedor->id,
                'tipo'          => 'recarga',
                'monto'         => (int) $validated['monto'],
                'motivo'        => $validated['motivo'],
                'referencia_id' => null,
            ]);

            return [
                'proveedor'   => $proveedor->fresh(['categoria', 'credito', 'user:id,email,documento_verificado']),
                'credito'     => $credito->fresh(),
                'transaccion' => $transaccion,
            ];
        });

        return response()->json([
            'success'     => true,
            'message'     => 'Creditos agregados correctamente.',
            'proveedor'   => $resultado['proveedor'],
            'credito'     => $resultado['credito'],
            'transaccion' => $resultado['transaccion'],
        ]);
    }

    /**
     * GET /api/admin/creditos-premium
     * Superficie administrativa de compras auto-acreditadas y estado Premium.
     */
    public function creditosPremium(Request $request): JsonResponse
    {
        $estado = $request->query('estado');
        $estadoValido = in_array($estado, ['pendiente', 'completada', 'fallida', 'cancelada'], true)
            ? $estado
            : null;

        $comprasQuery = CompraCredito::with([
                'paquete:id,nombre',
                'proveedor:id,user_id,nombre,email,categoria_id,premium_inicio_at,premium_vence_at,premium_renovaciones',
                'proveedor.user:id,name,email,documento_verificado',
            ])
            ->orderByDesc('created_at');

        if ($estadoValido) {
            $comprasQuery->where('estado', $estadoValido);
        }

        $compras = $comprasQuery->limit(50)->get()->map(fn (CompraCredito $compra) => [
            'id'                 => $compra->id,
            'proveedor_id'       => $compra->proveedor_id,
            'proveedor_nombre'   => $compra->proveedor?->nombre,
            'proveedor_email'    => $compra->proveedor?->email,
            'proveedor_verificado' => (bool) ($compra->proveedor?->user?->documento_verificado ?? false),
            'paquete'            => $compra->paquete?->nombre,
            'monto_gtq'          => (float) $compra->monto_gtq,
            'creditos_otorgados' => $compra->creditos_otorgados,
            'estado'             => $compra->estado,
            'referencia'         => $compra->referencia,
            'completada_at'      => $compra->completada_at,
            'created_at'         => $compra->created_at,
            'premium'            => $compra->proveedor ? $this->premiumResumen($compra->proveedor) : null,
        ]);

        $proveedores = Proveedor::with(['categoria', 'credito', 'user:id,email,documento_verificado'])
            ->orderBy('nombre')
            ->get()
            ->map(fn (Proveedor $proveedor) => [
                'id'            => $proveedor->id,
                'nombre'        => $proveedor->nombre,
                'email'         => $proveedor->email,
                'categoria'     => $proveedor->categoria?->nombre,
                'verificado'    => (bool) ($proveedor->user?->documento_verificado ?? false),
                'saldo'         => (int) ($proveedor->credito?->saldo ?? 0),
                'premium'       => $this->premiumResumen($proveedor),
            ]);

        $inicioMes = now()->startOfMonth();
        $comprasMes = CompraCredito::where('created_at', '>=', $inicioMes);
        $comprasCompletadasMes = (clone $comprasMes)->where('estado', 'completada');

        return $this->success('Creditos y Premium obtenidos correctamente.', [
            'kpis' => [
                'compras_mes'        => (clone $comprasMes)->count(),
                'creditos_vendidos'  => (int) (clone $comprasCompletadasMes)->sum('creditos_otorgados'),
                'ingreso_mes_gtq'    => (float) (clone $comprasCompletadasMes)->sum('monto_gtq'),
                'premium_activos'    => Proveedor::whereNotNull('premium_vence_at')->where('premium_vence_at', '>', now())->count(),
                'bonos_premium_mes'  => (int) TransaccionCredito::where('tipo', 'bono')
                    ->where('motivo', 'like', 'Bono mensual Premium%')
                    ->where('created_at', '>=', $inicioMes)
                    ->sum('monto'),
            ],
            'compras'     => $compras,
            'proveedores' => $proveedores,
            'filtro_estado' => $estadoValido,
        ]);
    }

    private function premiumResumen(Proveedor $proveedor): array
    {
        $estado = $proveedor->premiumEstado();

        return [
            'estado'         => $estado,
            'activo'         => $estado === 'activo',
            'inicio_at'      => $proveedor->premium_inicio_at,
            'vence_at'       => $proveedor->premium_vence_at,
            'dias_restantes' => $estado === 'activo'
                ? max(0, (int) ceil(now()->diffInDays($proveedor->premium_vence_at, false)))
                : 0,
            'renovaciones'   => (int) ($proveedor->premium_renovaciones ?? 0),
        ];
    }
}
