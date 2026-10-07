<?php

namespace App\Http\Controllers;

use App\Models\Disponibilidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisponibilidadController extends Controller
{
    public function index(int $proveedorId): JsonResponse
    {
        $disponibilidad = Disponibilidad::where('proveedor_id', $proveedorId)
            ->orderBy('dia_semana')
            ->get();

        return response()->json(['disponibilidad' => $disponibilidad]);
    }

    public function miDisponibilidad(Request $request): JsonResponse
    {
        $proveedor = $request->user()->proveedor;
        if (!$proveedor) {
            return response()->json(['message' => 'No tienes perfil de proveedor'], 404);
        }

        $disponibilidad = Disponibilidad::where('proveedor_id', $proveedor->id)
            ->orderBy('dia_semana')
            ->get();

        return response()->json(['disponibilidad' => $disponibilidad]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $proveedor = $request->user()->proveedor;
        if (!$proveedor) {
            return response()->json(['message' => 'No tienes perfil de proveedor'], 404);
        }

        $request->validate([
            'disponibilidad'              => 'required|array',
            'disponibilidad.*.dia_semana' => 'required|integer|between:0,6',
            'disponibilidad.*.hora_inicio'=> 'required|date_format:H:i',
            'disponibilidad.*.hora_fin'   => 'required|date_format:H:i',
            'disponibilidad.*.disponible' => 'boolean',
        ]);

        $registros = [];
        foreach ($request->disponibilidad as $item) {
            $registro = Disponibilidad::updateOrCreate(
                ['proveedor_id' => $proveedor->id, 'dia_semana' => $item['dia_semana']],
                [
                    'hora_inicio' => $item['hora_inicio'],
                    'hora_fin'    => $item['hora_fin'],
                    'disponible'  => $item['disponible'] ?? true,
                ]
            );
            $registros[] = $registro;
        }

        return response()->json([
            'message'       => 'Disponibilidad guardada',
            'disponibilidad' => $registros,
        ]);
    }
}
