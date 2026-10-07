<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicacionServicioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $esPropietario = $request->user()
            && $this->proveedor
            && $this->proveedor->user_id === $request->user()->id;

        $data = [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'descripcion' => $this->descripcion,
            'precio_referencial' => $this->precio_referencial !== null ? (float) $this->precio_referencial : null,
            'imagen' => $this->imagen,
            'estado' => $this->estado,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'categoria' => $this->whenLoaded('categoria', fn () => $this->categoria ? [
                'id' => $this->categoria->id,
                'nombre' => $this->categoria->nombre,
            ] : null),
            'proveedor' => $this->whenLoaded('proveedor', fn () => $this->proveedor ? [
                'id' => $this->proveedor->id,
                'nombre' => $this->proveedor->nombre,
                'telefono' => $this->proveedor->telefono,
                'foto_perfil' => $this->proveedor->foto_perfil,
                'calificacion_promedio' => (float) ($this->proveedor->calificacion_promedio ?? 0),
                'premium_estado' => $this->proveedor->premium_estado,
            ] : null),
        ];

        if (!$esPropietario && $this->estado !== 'activa') {
            unset($data['estado']);
        }

        return $data;
    }
}
