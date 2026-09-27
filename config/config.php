<?php
/**
 * =============================================================================
 * config/config.php
 * -----------------------------------------------------------------------------
 * Configuracion centralizada del sistema.
 *
 * Toma los valores del archivo .env cuando existe, y en caso contrario usa
 * valores por defecto razonables. Asi el mismo codigo funciona en el equipo
 * del aprendiz, en un servidor de pruebas y en produccion.
 *
 * Ademas de los parametros tecnicos (base de datos, JWT), aqui se declaran
 * las REGLAS DE NEGOCIO del showroom: porcentaje de IVA, vigencia de las
 * cotizaciones y prefijos de numeracion. Tenerlas en un solo sitio evita
 * que queden repartidas como numeros sueltos por el codigo.
 *
 * @package SistemaShowroomApi
 * =============================================================================
 */

declare(strict_types=1);

/**
 * Lee un archivo .env con formato CLAVE=valor y lo vuelca en el entorno.
 *
 * @param string $rutaArchivo Ruta absoluta del archivo .env
 * @return void
 */
function cargarVariablesDeEntorno(string $rutaArchivo): void
{
    if (!is_readable($rutaArchivo)) {
        return;
    }

    foreach (file($rutaArchivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);

        // Se ignoran comentarios y lineas sin separador.
        if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
            continue;
        }

        [$clave, $valor] = explode('=', $linea, 2);
        $clave = trim($clave);
        $valor = trim(trim($valor), "\"'");

        putenv("$clave=$valor");
        $_ENV[$clave] = $valor;
    }
}

/**
 * Obtiene una variable de entorno, convirtiendo los literales de texto
 * "true", "false" y "null" a sus tipos nativos.
 *
 * @param string $clave
 * @param mixed  $porDefecto
 * @return mixed
 */
function env(string $clave, mixed $porDefecto = null): mixed
{
    $valor = getenv($clave);

    if ($valor === false || $valor === '') {
        return $porDefecto;
    }

    return match (strtolower($valor)) {
        'true'  => true,
        'false' => false,
        'null'  => null,
        default => $valor,
    };
}

$rutaRaiz = dirname(__DIR__);

cargarVariablesDeEntorno($rutaRaiz . DIRECTORY_SEPARATOR . '.env');

return [

    // -----------------------------------------------------------------------
    // Aplicacion
    // -----------------------------------------------------------------------
    'app' => [
        'nombre'   => 'API del Sistema de Showroom y Ventas',
        'version'  => '1.0.0',
        'entorno'  => env('APP_ENTORNO', 'desarrollo'),
        'rutaRaiz' => $rutaRaiz,
    ],

    // -----------------------------------------------------------------------
    // Base de datos
    // -----------------------------------------------------------------------
    'baseDatos' => [
        // "sqlite" no requiere instalar nada; "mysql" usa el servidor de XAMPP.
        'driver' => env('DB_DRIVER', 'sqlite'),

        'rutaSqlite' => (string) env(
            'DB_SQLITE_RUTA',
            $rutaRaiz . DIRECTORY_SEPARATOR . 'almacenamiento'
                      . DIRECTORY_SEPARATOR . 'showroom.sqlite'
        ),

        'host'    => env('DB_HOST', '127.0.0.1'),
        'puerto'  => (int) env('DB_PUERTO', 3306),
        'nombre'  => env('DB_NOMBRE', 'sistema_showroom'),
        'usuario' => env('DB_USUARIO', 'root'),
        'clave'   => (string) env('DB_CLAVE', ''),
        'charset' => 'utf8mb4',
    ],

    // -----------------------------------------------------------------------
    // Token JWT
    // -----------------------------------------------------------------------
    'jwt' => [
        'secreto'         => (string) env('JWT_SECRETO', 'clave-secreta-de-desarrollo'),
        'minutosVigencia' => (int) env('JWT_MINUTOS_VIGENCIA', 120),
        'emisor'          => 'sistema-showroom-api',
    ],

    // -----------------------------------------------------------------------
    // Seguridad
    // -----------------------------------------------------------------------
    'seguridad' => [
        'longitudMinimaClave'    => 8,
        'maximoIntentosFallidos' => 5,
        'minutosBloqueo'         => 15,
        'algoritmoHash'          => PASSWORD_DEFAULT,
    ],

    // -----------------------------------------------------------------------
    // REGLAS DE NEGOCIO DEL SHOWROOM
    // -----------------------------------------------------------------------
    'negocio' => [
        // Porcentaje de IVA aplicado a cotizaciones y pedidos (Colombia: 19 %).
        'porcentajeIva' => (float) env('NEGOCIO_IVA', 19),

        // Dias que una cotizacion permanece vigente desde su emision.
        'diasVigenciaCotizacion' => (int) env('NEGOCIO_DIAS_COTIZACION', 15),

        // Descuento maximo que un asesor puede aplicar sin aprobacion.
        'descuentoMaximoPorcentaje' => (float) env('NEGOCIO_DESCUENTO_MAXIMO', 20),

        // Prefijos de la numeracion consecutiva de documentos.
        'prefijoCotizacion' => 'COT',
        'prefijoPedido'     => 'PED',

        // Cantidad de registros por pagina en los listados.
        'registrosPorPagina'       => (int) env('NEGOCIO_POR_PAGINA', 20),
        'maximoRegistrosPorPagina' => 100,
    ],

    // -----------------------------------------------------------------------
    // Roles del sistema y lo que puede hacer cada uno
    // -----------------------------------------------------------------------
    'roles' => [
        'administrador' => 'Acceso total: administra usuarios, catalogo, clientes y documentos.',
        'asesor'        => 'Crea y gestiona clientes, cotizaciones y pedidos. No administra usuarios.',
        'consulta'      => 'Solo lectura: puede consultar catalogo, clientes y documentos.',
    ],
];
