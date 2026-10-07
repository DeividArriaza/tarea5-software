<?php

/*
 * Task 4.1 — CORS por ambiente.
 *
 * Antes este archivo fijaba `allowed_origins => ['*']` para todos los
 * ambientes. Con `supports_credentials => false` y autenticacion por Bearer eso
 * no entrega la sesion a un origen ajeno, pero si deja que cualquier pagina
 * lea respuestas publicas de la API desde el navegador de la victima, y no hay
 * forma de restringirlo sin editar la imagen.
 *
 * Ahora la lista viene de `CORS_ALLOWED_ORIGINS` (separada por comas) y el
 * comodin solo sobrevive como default en local/testing, donde el frontend corre
 * en un puerto distinto y cambia de host seguido. Fuera de ahi, si nadie
 * declara origenes, la lista queda vacia: el navegador bloquea el cross-origin
 * en vez de permitirlo por omision.
 */

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

$esEntornoLocal = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

return [
    'paths' => ['api/*'],

    // Se enumeran en vez de usar '*' para que un metodo nuevo sea una decision
    // explicita y no algo que la API acepte por omision.
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins !== [] ? $origins : ($esEntornoLocal ? ['*'] : []),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-Correlation-Id'],

    // El correlation id de la task 4.3 debe poder leerse desde el navegador
    // para que un reporte de error del usuario se pueda cruzar con el log.
    'exposed_headers' => ['X-Correlation-Id'],

    'max_age' => 0,

    // Bearer token en header, no cookie de sesion: no hay credenciales que
    // compartir cross-origin. Activarlo obligaria a abandonar el comodin.
    'supports_credentials' => false,
];
