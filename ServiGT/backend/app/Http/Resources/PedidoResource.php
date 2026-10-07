<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'descripcion'        => $this->descripcion,
            'direccion'          => $this->direccion,
            'urgencia'           => $this->urgencia,
            'estado'             => $this->estado,
            'fecha_expiracion'   => $this->fecha_expiracion?->toIso8601String(),
            'created_at'         => $this->created_at?->toIso8601String(),
            'cotizaciones_count' => $this->whenCounted('cotizaciones'),
            'cliente'            => $this->whenLoaded('cliente', fn() => [
                'id'   => $this->cliente->id,
                'name' => $this->cliente->name,
            ]),
            'categoria'          => $this->whenLoaded('categoria', fn() => [
                'id'     => $this->categoria->id,
                'nombre' => $this->categoria->nombre,
            ]),
        ];
    }
}
