<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato de salida del proveedor. Task 2.5 — hallazgos 21 y 22.
 *
 * Antes los controladores devolvian el modelo crudo, asi que el directorio
 * publico entregaba correo, user_id y los campos internos de Premium de todos
 * los proveedores a cualquier visitante anonimo. Aqui la lista de campos es
 * explicita: agregar una columna al modelo ya no la publica sola.
 *
 * Hay tres vistas y se eligen por endpoint, no por quien mira:
 *
 *  - CATALOGO: lo minimo que necesita el listado publico y su buscador.
 *  - DETALLE:  el perfil publico completo, sin datos operativos.
 *  - PROPIO:   lo que el duenno del perfil (o un admin) necesita para editarse.
 *
 * `telefono` se mantiene en las tres porque el catalogo, el detalle y el
 * resumen de solicitud ya lo muestran y el buscador filtra por el: quitarlo es
 * una decision de producto, no de seguridad, y no se toma en esta task.
 *
 * De Premium solo sale `premium_estado`, que es el indicador derivado que
 * necesita el badge. La fecha de vencimiento, los dias restantes, el conteo de
 * renovaciones y `premium_ciclo_key` son datos operativos del proveedor y no
 * viajan al publico.
 */
class ProveedorResource extends JsonResource
{
    public const CATALOGO = 'catalogo';
    public const DETALLE  = 'detalle';
    public const PROPIO   = 'propio';

    protected string $vista;

    public function __construct($resource, string $vista = self::DETALLE)
    {
        parent::__construct($resource);
        $this->vista = $vista;
    }

    /**
     * Serializa una lista con la misma vista. `collection()` no deja pasar el
     * segundo argumento, asi que se resuelve aqui en vez de forzarlo.
     */
    public static function coleccion($proveedores, string $vista = self::CATALOGO): array
    {
        return collect($proveedores)
            ->map(fn ($proveedor) => (new static($proveedor, $vista))->resolve())
            ->all();
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id'                    => $this->id,
            'nombre'                => $this->nombre,
            'descripcion'           => $this->descripcion,
            'departamento'          => $this->departamento,
            'municipio'             => $this->municipio,
            'telefono'              => $this->telefono,
            'foto_perfil'           => $this->foto_perfil,
            'calificacion_promedio' => (float) ($this->calificacion_promedio ?? 0),
            'tarifa_hora'           => $this->tarifa_hora,
            'tarifa_proyecto'       => $this->tarifa_proyecto,
            'categoria_id'          => $this->categoria_id,
            'categoria'             => $this->categoriaResumida(),
            'premium_estado'        => $this->premium_estado,
        ];

        if ($this->vista === self::CATALOGO) {
            return $data;
        }

        // Personalizacion de marca (task 2.4). En el detalle publico solo viaja
        // si el Premium esta activo: al vencer, el perfil vuelve al degradado y
        // al color de marca sin que se borre nada en disco, asi que renovar
        // restaura la portada anterior tal cual estaba.
        $marcaActiva = $this->premiumEstado() === 'activo';

        $data += [
            'nivel'                => $this->nivel,
            'total_calificaciones' => (int) ($this->total_calificaciones ?? 0),
            'portada'              => $marcaActiva ? $this->portada : null,
            'color_acento'         => $marcaActiva ? $this->color_acento : null,
            'categorias'           => $this->categoriasResumidas(),
            'disponibilidad'       => $this->whenLoaded('disponibilidad'),
        ];

        if ($this->vista === self::DETALLE) {
            return $data;
        }

        // Vista propia: solo la ve el duenno del perfil o un administrador.
        // Aqui si se devuelven la portada y el color guardados aunque el
        // Premium este vencido, para que el proveedor vea que su
        // personalizacion sigue ahi y que renovar la reactiva. Se usa
        // array_merge y no `+` porque `+` conserva la clave de la izquierda:
        // con `+` la portada seguiria llegando en null.
        return array_merge($data, [
            'portada'                => $this->portada,
            'color_acento'           => $this->color_acento,
            'marca_activa'           => $marcaActiva,
            'user_id'                => $this->user_id,
            'email'                  => $this->email,
            'premium_inicio_at'      => $this->premium_inicio_at?->toIso8601String(),
            'premium_vence_at'       => $this->premium_vence_at?->toIso8601String(),
            'premium_dias_restantes' => $this->premium_dias_restantes,
            'premium_renovaciones'   => (int) ($this->premium_renovaciones ?? 0),
            'created_at'             => $this->created_at?->toIso8601String(),
            'updated_at'             => $this->updated_at?->toIso8601String(),
        ]);
    }

    private function categoriaResumida(): ?array
    {
        if (!$this->relationLoaded('categoria') || !$this->categoria) {
            return null;
        }

        return ['id' => $this->categoria->id, 'nombre' => $this->categoria->nombre];
    }

    private function categoriasResumidas(): ?array
    {
        if (!$this->relationLoaded('categorias')) {
            return null;
        }

        return $this->categorias
            ->map(fn ($categoria) => ['id' => $categoria->id, 'nombre' => $categoria->nombre])
            ->all();
    }
}
