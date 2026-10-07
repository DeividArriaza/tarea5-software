<?php

namespace App\Http\Controllers;

use App\Models\Mensaje;
use App\Models\Servicio;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    use ApiResponse;

    // Enviar mensaje
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'receptor_id' => 'required|exists:users,id',
            'contenido'   => 'required|string|max:2000',
            'servicio_id' => 'nullable|exists:servicios,id',
        ]);

        $validated['emisor_id'] = $request->user()->id;

        if ($validated['emisor_id'] === (int) $validated['receptor_id']) {
            return response()->json(['message' => 'No puedes enviarte mensajes a ti mismo'], 422);
        }

        // Regla de producto ratificada: el canal solo se abre a traves de un
        // servicio, originado por una solicitud directa o por la publicacion
        // de un proveedor. Una cotizacion a un pedido todavia no adjudicado no
        // habilita el chat, porque en esa fase el pedido oculta a proposito la
        // identidad del proveedor.
        if (!$this->compartenServicio($validated['emisor_id'], (int) $validated['receptor_id'])) {
            return $this->error(
                'Solo puedes escribir a alguien con quien tengas un servicio en curso o pasado.',
                403
            );
        }

        $mensaje = Mensaje::create($validated);
        $mensaje->load(['emisor:id,name,role,foto_perfil', 'receptor:id,name,role,foto_perfil']);

        return response()->json([
            'message' => 'Mensaje enviado',
            'mensaje' => $mensaje,
        ], 201);
    }

    /**
     * Existe un servicio que vincule a los dos usuarios, en cualquiera de los
     * dos sentidos: uno como cliente y el otro como duenno del perfil
     * proveedor. No filtra por estado a proposito, para no cortar la
     * conversacion cuando el trabajo termina.
     */
    private function compartenServicio(int $unUsuario, int $otroUsuario): bool
    {
        return Servicio::where(function ($query) use ($unUsuario, $otroUsuario) {
            $query->where('cliente_id', $unUsuario)
                ->whereHas('proveedor', fn ($p) => $p->where('user_id', $otroUsuario));
        })->orWhere(function ($query) use ($unUsuario, $otroUsuario) {
            $query->where('cliente_id', $otroUsuario)
                ->whereHas('proveedor', fn ($p) => $p->where('user_id', $unUsuario));
        })->exists();
    }

    // Obtener conversacion entre dos usuarios
    public function conversacion(int $otroUsuarioId, Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $lastId = $request->query('last_id');

        $query = Mensaje::with([
                'emisor:id,name,role,foto_perfil',
                'receptor:id,name,role,foto_perfil',
            ])
            ->where(function ($q) use ($userId, $otroUsuarioId) {
                $q->where(function ($q2) use ($userId, $otroUsuarioId) {
                    $q2->where('emisor_id', $userId)->where('receptor_id', $otroUsuarioId);
                })->orWhere(function ($q2) use ($userId, $otroUsuarioId) {
                    $q2->where('emisor_id', $otroUsuarioId)->where('receptor_id', $userId);
                });
            });

        if ($lastId) {
            $query->where('id', '>', $lastId);
        }

        $mensajes = $query->orderBy('created_at', 'asc')->get();

        // Marcar como leidos los mensajes recibidos
        if ($mensajes->isNotEmpty()) {
            Mensaje::where('emisor_id', $otroUsuarioId)
                ->where('receptor_id', $userId)
                ->where('leido', false)
                ->update(['leido' => true]);
        }

        return response()->json(['mensajes' => $mensajes]);
    }

    // Listar conversaciones del usuario autenticado
    public function misConversaciones(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $mensajes = Mensaje::with([
                'emisor:id,name,role,foto_perfil',
                'receptor:id,name,role,foto_perfil',
            ])
            ->where('emisor_id', $userId)
            ->orWhere('receptor_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy(function ($m) use ($userId) {
                return $m->emisor_id === $userId ? $m->receptor_id : $m->emisor_id;
            })
            ->map(fn ($grupo) => $grupo->first());

        return response()->json(['conversaciones' => $mensajes->values()]);
    }
}
