<?php

namespace App\Observers;

use App\Models\Calificacion;
use App\Models\Proveedor;

class CalificacionObserver
{
    public function created(Calificacion $calificacion): void
    {
        $this->recalcularProveedor($calificacion);
    }

    public function updated(Calificacion $calificacion): void
    {
        $this->recalcularProveedor($calificacion);
    }

    public function deleted(Calificacion $calificacion): void
    {
        $this->recalcularProveedor($calificacion);
    }

    private function recalcularProveedor(Calificacion $calificacion): void
    {
        $proveedor = Proveedor::where('user_id', $calificacion->destinatario_id)->first();

        if (!$proveedor) {
            return;
        }

        $stats = Calificacion::where('destinatario_id', $calificacion->destinatario_id)
            ->selectRaw('COALESCE(AVG(puntuacion), 0) as promedio, COUNT(*) as total')
            ->first();

        $proveedor->forceFill([
            'calificacion_promedio' => round((float) $stats->promedio, 2),
            'total_calificaciones'  => (int) $stats->total,
        ])->save();
    }
}
