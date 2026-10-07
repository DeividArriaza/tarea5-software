<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServicioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $proveedorUserId = $this->proveedor?->user_id;

        $esCliente = $user && $this->cliente_id === $user->id;
        $esProveedor = $user && $proveedorUserId === $user->id;

        $data = [
            'id' => $this->id,
            'cliente_id' => $this->cliente_id,
            'proveedor_id' => $this->proveedor_id,
            'publicacion_id' => $this->publicacion_id,
            'publicacion_titulo' => $this->publicacion_titulo,
            'publicacion_precio_referencial' => $this->publicacion_precio_referencial !== null
                ? (float) $this->publicacion_precio_referencial
                : null,
            'categoria_id' => $this->categoria_id,
            'descripcion' => $this->descripcion,
            'estado' => $this->estado,
            'fecha_agendada' => $this->fecha_agendada?->toIso8601String(),
            'direccion' => $this->direccion,
            'monto_acordado' => $this->monto_acordado,
            'motivo_cancelacion' => $this->motivo_cancelacion,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'cliente' => $this->whenLoaded('cliente', fn () => [
                'id' => $this->cliente->id,
                'name' => $this->cliente->name,
                'role' => $this->cliente->role,
                'foto_perfil' => $this->cliente->foto_perfil,
            ]),
            'proveedor' => $this->whenLoaded('proveedor', fn () => [
                'id' => $this->proveedor->id,
                'nombre' => $this->proveedor->nombre,
                'telefono' => $this->proveedor->telefono,
                'foto_perfil' => $this->proveedor->foto_perfil,
                'calificacion_promedio' => (float) ($this->proveedor->calificacion_promedio ?? 0),
                'premium_estado' => $this->proveedor->premium_estado,
                'categoria' => $this->proveedor->relationLoaded('categoria') && $this->proveedor->categoria ? [
                    'id' => $this->proveedor->categoria->id,
                    'nombre' => $this->proveedor->categoria->nombre,
                ] : null,
            ]),
            'categoria' => $this->whenLoaded('categoria', fn () => $this->categoria ? [
                'id' => $this->categoria->id,
                'nombre' => $this->categoria->nombre,
            ] : null),
            'calificaciones' => $this->whenLoaded('calificaciones'),
            'pago' => $this->whenLoaded('pago'),
        ];

        if ($esCliente && in_array($this->estado, ['aceptado', 'en_progreso', 'por_confirmar', 'completado'], true)) {
            $data['codigo_inicio'] = $this->codigo_inicio;
        }

        $puedeVerCodigoFin = $request->attributes->get('exponer_codigo_fin_servicio') === $this->id;

        if ($esProveedor && $puedeVerCodigoFin && $this->estado === 'por_confirmar') {
            $data['codigo_fin'] = $this->codigo_fin;
        }

        return $data;
    }
}
