<?php

use Illuminate\Support\Facades\Route;

/*
 * ServiGT es una API: el frontend es una app Expo servida aparte (task 4.2).
 *
 * Antes esta ruta devolvia la pagina de bienvenida de Laravel. Eso abria una
 * sesion —con su cookie sin HttpOnly— y servia HTML con estilos inline en un
 * servicio que no tiene ninguna vista que mostrar; el escaneo ZAP Baseline lo
 * reportaba como CSP ausente y cookie insegura sobre `/`.
 *
 * Ahora responde un JSON minimo, sin sesion y sin datos: suficiente para
 * confirmar a mano que el contenedor esta arriba, sin superficie que proteger.
 */
Route::get('/', fn () => response()->json([
    'service' => 'ServiGT API',
    'docs' => '/api/health',
]));
