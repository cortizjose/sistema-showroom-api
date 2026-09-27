<?php
/**
 * =============================================================================
 * src/arranque.php
 * -----------------------------------------------------------------------------
 * Arranque de la aplicacion.
 *
 * Reune los pasos comunes que necesitan tanto el servidor web
 * (public/index.php) como los scripts de consola:
 *
 *   1. Registrar el cargador automatico de clases (PSR-4).
 *   2. Cargar la configuracion.
 *   3. Preparar la base de datos.
 *   4. Construir y conectar todas las dependencias.
 *
 * Devuelve un arreglo con los objetos ya ensamblados.
 *
 * @package SistemaShowroomApi
 * =============================================================================
 */

declare(strict_types=1);

use App\BaseDatos\Conexion;
use App\BaseDatos\Migracion;
use App\Middleware\Seguridad;
use App\Repositorios\ClienteRepositorio;
use App\Repositorios\CotizacionRepositorio;
use App\Repositorios\PedidoRepositorio;
use App\Repositorios\ProductoRepositorio;
use App\Repositorios\UsuarioRepositorio;
use App\Servicios\AutenticacionServicio;
use App\Servicios\CalculadoraDeTotales;
use App\Servicios\CatalogoServicio;
use App\Servicios\ClienteServicio;
use App\Servicios\CotizacionServicio;
use App\Servicios\Jwt;
use App\Servicios\PedidoServicio;
use App\Servicios\ReporteServicio;
use App\Validacion\Validador;

// -----------------------------------------------------------------------------
// 1. CARGADOR AUTOMATICO DE CLASES (PSR-4)
// -----------------------------------------------------------------------------
// Convierte el nombre de una clase en la ruta de su archivo:
//
//      App\Servicios\PedidoServicio  ->  src/Servicios/PedidoServicio.php
//
// Se implementa a mano para que el proyecto funcione sin Composer.
// -----------------------------------------------------------------------------
spl_autoload_register(static function (string $claseCompleta): void {
    $prefijo = 'App\\';

    if (!str_starts_with($claseCompleta, $prefijo)) {
        return;
    }

    $rutaRelativa = substr($claseCompleta, strlen($prefijo));
    $rutaArchivo  = __DIR__ . DIRECTORY_SEPARATOR
                  . str_replace('\\', DIRECTORY_SEPARATOR, $rutaRelativa) . '.php';

    if (is_readable($rutaArchivo)) {
        require_once $rutaArchivo;
    }
});

// -----------------------------------------------------------------------------
// 2. CONFIGURACION
// -----------------------------------------------------------------------------
$configuracion = require dirname(__DIR__) . '/config/config.php';

if ($configuracion['app']['entorno'] === 'desarrollo') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

date_default_timezone_set('America/Bogota');

// -----------------------------------------------------------------------------
// 3. BASE DE DATOS
// -----------------------------------------------------------------------------
Conexion::configurar($configuracion['baseDatos']);
Migracion::ejecutar();

// -----------------------------------------------------------------------------
// 4. DEPENDENCIAS
// -----------------------------------------------------------------------------
// Cada objeto recibe por constructor lo que necesita, en lugar de crearlo
// internamente. Esto permite sustituir cualquier pieza en las pruebas.
// -----------------------------------------------------------------------------

// --- Repositorios ---
$repoUsuarios    = new UsuarioRepositorio();
$repoClientes    = new ClienteRepositorio();
$repoProductos   = new ProductoRepositorio();
$repoCotizaciones = new CotizacionRepositorio();
$repoPedidos     = new PedidoRepositorio();

// --- Utilidades ---
$validador = new Validador(
    (int) $configuracion['seguridad']['longitudMinimaClave'],
    (float) $configuracion['negocio']['descuentoMaximoPorcentaje']
);

$jwt = new Jwt(
    (string) $configuracion['jwt']['secreto'],
    (int) $configuracion['jwt']['minutosVigencia'],
    (string) $configuracion['jwt']['emisor']
);

$calculadora = new CalculadoraDeTotales(
    $repoProductos,
    (float) $configuracion['negocio']['porcentajeIva']
);

// --- Servicios de negocio ---
$servicioAutenticacion = new AutenticacionServicio(
    $repoUsuarios,
    $validador,
    $jwt,
    $configuracion['seguridad'],
    $configuracion['negocio'],
    array_keys($configuracion['roles'])
);

$servicioCatalogo = new CatalogoServicio(
    $repoProductos,
    $validador,
    $configuracion['negocio']
);

$servicioClientes = new ClienteServicio(
    $repoClientes,
    $validador,
    $configuracion['negocio']
);

$servicioCotizaciones = new CotizacionServicio(
    $repoCotizaciones,
    $repoClientes,
    $repoPedidos,
    $calculadora,
    $validador,
    $configuracion['negocio']
);

$servicioPedidos = new PedidoServicio(
    $repoPedidos,
    $repoClientes,
    $repoProductos,
    $calculadora,
    $validador,
    $configuracion['negocio']
);

$servicioReportes = new ReporteServicio($repoPedidos, $repoProductos);

// --- Middleware ---
$seguridad = new Seguridad($jwt, $repoUsuarios);

return [
    'configuracion' => $configuracion,

    'repositorios' => [
        'usuarios'     => $repoUsuarios,
        'clientes'     => $repoClientes,
        'productos'    => $repoProductos,
        'cotizaciones' => $repoCotizaciones,
        'pedidos'      => $repoPedidos,
    ],

    'servicios' => [
        'autenticacion' => $servicioAutenticacion,
        'catalogo'      => $servicioCatalogo,
        'clientes'      => $servicioClientes,
        'cotizaciones'  => $servicioCotizaciones,
        'pedidos'       => $servicioPedidos,
        'reportes'      => $servicioReportes,
    ],

    'jwt'       => $jwt,
    'validador' => $validador,
    'seguridad' => $seguridad,
];
