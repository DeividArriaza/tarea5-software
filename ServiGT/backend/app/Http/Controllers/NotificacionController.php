<?php

namespace App\Http\Controllers;

use App\Models\Notificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notificaciones = Notificacion::where('destinatario_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        $noLeidas = $notificaciones->where('leida', false)->count();

        return response()->json([
            'notificaciones' => $notificaciones,
            'no_leidas'      => $noLeidas,
        ]);
    }

    public function marcarLeida(int $id, Request $request): JsonResponse
    {
        $notif = Notificacion::where('id', $id)
            ->where('destinatario_id', $request->user()->id)
            ->first();

        if (!$notif) {
            return response()->json(['message' => 'Notificacion no encontrada'], 404);
        }

        $notif->update(['leida' => true]);
        return response()->json(['message' => 'Marcada como leida']);
    }

    public function marcarTodasLeidas(Request $request): JsonResponse
    {
        Notificacion::where('destinatario_id', $request->user()->id)
            ->where('leida', false)
            ->update(['leida' => true]);

        return response()->json(['message' => 'Todas marcadas como leidas']);
    }
}
