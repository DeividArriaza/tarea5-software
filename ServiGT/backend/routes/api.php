<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ServicioController;
use App\Http\Controllers\DisponibilidadController;
use App\Http\Controllers\CalificacionController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\CotizacionController;
use App\Http\Controllers\CreditoController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\PremiumController;
use App\Http\Controllers\PublicacionServicioController;

/*
|--------------------------------------------------------------------------
| API Routes - ServiGT Guatemala
|--------------------------------------------------------------------------
|
| Rutas publicas: health, login, register, listado de proveedores/categorias
| Rutas protegidas (auth:sanctum): modificar perfil, documentos, logout
|
*/

// ── Rutas publicas ─────────────────────────────────────────────────────────

Route::get('/health', [HealthController::class, 'check']);

// Autenticacion
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login',    [AuthController::class, 'login'])->middleware('throttle:login');

// Consultas publicas
Route::get('/categorias',              [CategoriaController::class, 'index']);
Route::get('/pedidos/abiertos',        [PedidoController::class, 'abiertos']);
Route::get('/pedidos/{id}',            [PedidoController::class, 'show'])->where('id', '[0-9]+');
Route::get('/providers',      [ProviderController::class, 'index']);
Route::get('/providers/{id}', [ProviderController::class, 'show'])->where('id', '[0-9]+');
Route::get('/publicaciones', [PublicacionServicioController::class, 'index']);
Route::get('/publicaciones/{id}', [PublicacionServicioController::class, 'show'])->where('id', '[0-9]+');

// Calificaciones publicas de un proveedor
Route::get('/providers/{id}/calificaciones', [CalificacionController::class, 'porProveedor'])->where('id', '[0-9]+');

// Disponibilidad publica de un proveedor
Route::get('/providers/{id}/disponibilidad', [DisponibilidadController::class, 'index'])->where('id', '[0-9]+');

// ── Rutas protegidas (requieren token Bearer) ──────────────────────────────

Route::middleware('auth:sanctum')->group(function () {

    // Sesion del usuario autenticado
    Route::get('/me',      [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Perfil de proveedor
    Route::get('/providers/me',           [ProviderController::class, 'me']);
    Route::get('/providers/user/{userId}', [ProviderController::class, 'showByUser'])->where('userId', '[0-9]+');
    Route::post('/providers',              [ProviderController::class, 'store']);
    Route::put('/providers/{id}',          [ProviderController::class, 'update'])->where('id', '[0-9]+');

    // Foto de perfil del proveedor
    Route::post('/providers/{id}/foto', [ProviderController::class, 'uploadFoto'])->where('id', '[0-9]+')->middleware('throttle:uploads');
    Route::post('/providers/{id}/portada', [ProviderController::class, 'uploadPortada'])->where('id', '[0-9]+')->middleware('throttle:uploads');
    Route::delete('/providers/{id}/portada', [ProviderController::class, 'deletePortada'])->where('id', '[0-9]+');

    // Documentos del proveedor
    Route::get('/providers/{id}/documentos',  [ProviderController::class, 'getDocumentos']);
    Route::post('/providers/{id}/documentos', [ProviderController::class, 'uploadDocumento'])->middleware('throttle:uploads');
    Route::get('/providers/{id}/documentos/{documentoId}/descargar', [ProviderController::class, 'descargarDocumento'])
        ->where(['id' => '[0-9]+', 'documentoId' => '[0-9]+']);

    // ── Servicios ────────────────────────────────────────────────────────────
    // Cliente solicita un servicio
    Route::post('/servicios', [ServicioController::class, 'store']);
    // Proveedor ve sus solicitudes entrantes
    Route::get('/servicios/proveedor', [ServicioController::class, 'solicitudesProveedor']);
    // Cliente ve sus solicitudes
    Route::get('/servicios/cliente',   [ServicioController::class, 'solicitudesCliente']);
    // Ver detalle de un servicio
    Route::get('/servicios/{id}', [ServicioController::class, 'show'])->where('id', '[0-9]+');
    // Proveedor acepta/rechaza
    Route::post('/servicios/{id}/aceptar',  [ServicioController::class, 'aceptar'])->where('id', '[0-9]+');
    Route::post('/servicios/{id}/rechazar', [ServicioController::class, 'rechazar'])->where('id', '[0-9]+');
    // Proveedor inicia el servicio validando el codigo de inicio del cliente
    Route::post('/servicios/{id}/iniciar',  [ServicioController::class, 'iniciar'])->where('id', '[0-9]+');
    // Proveedor finaliza el servicio: genera codigo_fin y estado pasa a por_confirmar
    Route::post('/servicios/{id}/finalizar', [ServicioController::class, 'finalizar'])->where('id', '[0-9]+');
    // Cliente confirma fin del servicio validando el codigo_fin del proveedor
    Route::post('/servicios/{id}/confirmar-fin', [ServicioController::class, 'confirmarFin'])->where('id', '[0-9]+');
    // Cancelacion unilateral exclusiva del cliente propietario.
    Route::post('/servicios/{id}/cancelar', [ServicioController::class, 'cancelar'])->where('id', '[0-9]+');
    // Actualizar estado general (en_camino, en_progreso, completado, cancelado)
    Route::put('/servicios/{id}/estado', [ServicioController::class, 'actualizarEstado'])->where('id', '[0-9]+');

    // ── Disponibilidad ───────────────────────────────────────────────────────
    // Ver mi disponibilidad (proveedor autenticado)
    Route::get('/disponibilidad/mia',  [DisponibilidadController::class, 'miDisponibilidad']);
    // Guardar/actualizar mi disponibilidad
    Route::post('/disponibilidad',     [DisponibilidadController::class, 'guardar']);

    // ── Calificaciones ───────────────────────────────────────────────────────
    Route::post('/calificaciones', [CalificacionController::class, 'store']);
    Route::post('/servicios/{id}/calificar', [CalificacionController::class, 'calificarServicio'])->where('id', '[0-9]+');

    // ── Mensajes / Chat ──────────────────────────────────────────────────────
    // Enviar mensaje
    Route::post('/mensajes', [MessageController::class, 'store'])->middleware('throttle:messages');
    // Ver conversacion con otro usuario
    Route::get('/mensajes/conversacion/{userId}', [MessageController::class, 'conversacion'])->where('userId', '[0-9]+');
    // Listar todas mis conversaciones
    Route::get('/mensajes/conversaciones', [MessageController::class, 'misConversaciones']);

    // ── Pedidos (Marketplace de Demanda) ─────────────────────────────────────
    Route::post('/pedidos',                              [PedidoController::class, 'store']);
    Route::get('/pedidos/mios',                          [PedidoController::class, 'mios']);
    Route::post('/pedidos/{pedidoId}/cotizaciones',                       [CotizacionController::class, 'store'])->where('pedidoId', '[0-9]+');
    Route::put('/pedidos/{pedidoId}/cotizaciones/{cotizacionId}',         [CotizacionController::class, 'update'])->where(['pedidoId' => '[0-9]+', 'cotizacionId' => '[0-9]+']);
    Route::post('/pedidos/{pedidoId}/cotizaciones/{cotizacionId}/aceptar', [CotizacionController::class, 'aceptar'])->where(['pedidoId' => '[0-9]+', 'cotizacionId' => '[0-9]+']);

    // ── Creditos del proveedor ───────────────────────────────────────────────
    // Saldo del proveedor autenticado. Se consume desde el modal de cotizacion.
    Route::get('/mi-credito', [CreditoController::class, 'mio']);
    // Catalogo de paquetes comprables
    Route::get('/creditos/paquetes', [CreditoController::class, 'paquetes']);
    // Compra simulada e inmediata de un paquete
    Route::post('/creditos/comprar', [CreditoController::class, 'comprar'])->middleware('throttle:purchase');
    // Historial paginado de movimientos de creditos
    Route::get('/creditos/transacciones', [CreditoController::class, 'transacciones']);

    // ── Premium del proveedor ────────────────────────────────────────────────
    Route::post('/premium/activar',  [PremiumController::class, 'activar'])->middleware('throttle:premium');
    Route::get('/premium/mi-estado', [PremiumController::class, 'miEstado']);

    // Publicaciones de servicios ofrecidos
    Route::get('/publicaciones/mias', [PublicacionServicioController::class, 'mias']);
    Route::post('/publicaciones', [PublicacionServicioController::class, 'store'])->middleware('throttle:uploads');
    Route::put('/publicaciones/{id}', [PublicacionServicioController::class, 'update'])->where('id', '[0-9]+')->middleware('throttle:uploads');
    Route::post('/publicaciones/{id}/activar', [PublicacionServicioController::class, 'activar'])->where('id', '[0-9]+');
    Route::post('/publicaciones/{id}/desactivar', [PublicacionServicioController::class, 'desactivar'])->where('id', '[0-9]+');
    Route::delete('/publicaciones/{id}', [PublicacionServicioController::class, 'destroy'])->where('id', '[0-9]+');

    // ── Notificaciones ───────────────────────────────────────────────────────
    Route::get('/notificaciones',                [NotificacionController::class, 'index']);
    Route::put('/notificaciones/{id}/leer',      [NotificacionController::class, 'marcarLeida'])->where('id', '[0-9]+');
    Route::put('/notificaciones/leer-todas',     [NotificacionController::class, 'marcarTodasLeidas']);

    // ── Admin (requiere rol admin) ──────────────────────────────────────────
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/stats',       [AdminController::class, 'stats']);
        Route::get('/usuarios',    [AdminController::class, 'listUsers']);
        Route::get('/proveedores', [AdminController::class, 'listProviders']);
        Route::get('/creditos-premium', [AdminController::class, 'creditosPremium']);
        Route::post('/proveedores/{proveedorId}/creditos', [AdminController::class, 'recargarCreditos'])
            ->where('proveedorId', '[0-9]+');
    });
});
