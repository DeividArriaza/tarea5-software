<?php

namespace App\Policies;

use App\Models\Proveedor;
use App\Models\User;

class ProveedorPolicy
{
    /**
     * Dueno del perfil o administrador pueden gestionar el proveedor:
     * editar datos, foto y documentos.
     */
    public function manage(User $user, Proveedor $proveedor): bool
    {
        return $user->role === 'admin' || $user->id === $proveedor->user_id;
    }

    /**
     * Personalizacion de marca del perfil publico —portada y color de acento—
     * (task 2.4).
     *
     * Es una prestacion de Premium, no del perfil: antes cualquier proveedor
     * podia subir portada y fijar `color_acento` sin haber pagado nunca, asi
     * que la funcion se entregaba gratis y ademas quedaba desalineada con lo
     * que anuncia la pantalla de Premium.
     *
     * Se apoya en `premiumEstado()` para no duplicar la regla de vencimiento:
     * `nunca` y `vencido` no pueden modificar; solo `activo` puede. El admin
     * conserva acceso porque necesita poder retirar una portada inapropiada
     * sin depender del estado de pago del proveedor.
     *
     * Ojo: esto no borra nada al vencer. Los archivos y el color se conservan;
     * la decision de mostrarlos vive en la serializacion del perfil.
     */
    public function personalizarMarca(User $user, Proveedor $proveedor): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->id !== $proveedor->user_id) {
            return false;
        }

        return $proveedor->premiumEstado() === 'activo';
    }
}
