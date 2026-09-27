<?php
/**
 * =============================================================================
 * public/index.php
 * -----------------------------------------------------------------------------
 * PUNTO DE ENTRADA UNICO DE LA API (patron Front Controller).
 *
 * Aqui esta la TABLA DE RUTAS: la materializacion en codigo del contrato de
 * la API documentado en docs/02-documentacion-servicios.md
 *
 * Cada ruta declara junto a si misma que proteccion exige, de modo que ningun
 * endpoint puede quedar desprotegido por olvido.
 *
 *   sin middleware       ruta publica
 *   'autenticado'        exige token JWT valido
 *   'rol:a|b'            exige alguno de esos roles
 *
 * Solo esta carpeta debe quedar expuesta en el servidor web; el codigo fuente
 * y la configuracion permanecen fuera del alcance del navegador.
 *
 * @package SistemaShowroomApi
 * =============================================================================
 */

declare(strict_types=1);

use App\Controladores\AutenticacionControlador;
use App\Controladores\CatalogoControlador;
use App\Controladores\ClienteControlador;
use App\Controladores\CotizacionControlador;
use App\Controladores\PedidoControlador;
use App\Controladores\ReporteControlador;
use App\Controladores\SistemaControlador;
use App\Nucleo\Enrutador;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;

// -----------------------------------------------------------------------------
// Servidor embebido de PHP: entrega directa de archivos estaticos.
// -----------------------------------------------------------------------------
if (PHP_SAPI === 'cli-server') {
    $rutaSolicitada = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if ($rutaSolicitada !== '/' && is_file(__DIR__ . $rutaSolicitada)) {
        return false;
    }
}

// -----------------------------------------------------------------------------
// ARRANQUE
// -----------------------------------------------------------------------------
try {
    /** @var array<string,mixed> $app */
    $app = require dirname(__DIR__) . '/src/arranque.php';
} catch (Throwable $excepcion) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'exito'   => false,
        'mensaje' => 'No fue posible iniciar el servicio.',
        'detalle' => $excepcion->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}

$configuracion = $app['configuracion'];
$servicios     = $app['servicios'];

$enrutador = new Enrutador();

// -----------------------------------------------------------------------------
// MIDDLEWARE DISPONIBLE
// -----------------------------------------------------------------------------
$enrutador->middleware('autenticado', [$app['seguridad'], 'autenticado']);
$enrutador->middleware('rol', [$app['seguridad'], 'rol']);

// -----------------------------------------------------------------------------
// CONTROLADORES
// -----------------------------------------------------------------------------
$auth        = new AutenticacionControlador($servicios['autenticacion']);
$catalogo    = new CatalogoControlador($servicios['catalogo']);
$clientes    = new ClienteControlador($servicios['clientes']);
$cotizaciones = new CotizacionControlador($servicios['cotizaciones']);
$pedidos     = new PedidoControlador($servicios['pedidos']);
$reportes    = new ReporteControlador($servicios['reportes']);
$sistema     = new SistemaControlador($configuracion['app'], $configuracion['negocio'], $enrutador);

// Abreviaturas de los perfiles de acceso mas usados.
const GESTION  = 'rol:administrador|asesor';
const LECTURA  = 'rol:administrador|asesor|consulta';
const ADMIN    = 'rol:administrador';

// =============================================================================
// TABLA DE RUTAS
// =============================================================================

// --- 00. Sistema (publico) ---------------------------------------------------
$enrutador->get('/api', [$sistema, 'indice'], [],
    'Indice de la API con todos los endpoints publicados.');

$enrutador->get('/api/salud', [$sistema, 'salud'], [],
    'Estado del servicio y de la base de datos.');

// --- 01. Autenticacion -------------------------------------------------------
$enrutador->post('/api/auth/login', [$auth, 'iniciarSesion'], [],
    'Autentica al usuario y devuelve un token JWT.');

$enrutador->post('/api/auth/registro', [$auth, 'registro'], [],
    'Registro publico de una cuenta con rol de consulta.');

$enrutador->get('/api/auth/perfil', [$auth, 'perfil'], ['autenticado'],
    'Datos del usuario autenticado.');

$enrutador->post('/api/auth/cambiar-clave', [$auth, 'cambiarClave'], ['autenticado'],
    'Cambia la contrasena del usuario autenticado.');

// --- 02. Usuarios y roles (solo administrador) -------------------------------
$enrutador->get('/api/usuarios', [$auth, 'listar'], ['autenticado', ADMIN],
    'Listado paginado de usuarios.');

$enrutador->get('/api/usuarios/{id}', [$auth, 'consultar'], ['autenticado', ADMIN],
    'Consulta un usuario por su identificador.');

$enrutador->post('/api/usuarios', [$auth, 'crear'], ['autenticado', ADMIN],
    'Crea un usuario con el rol indicado.');

$enrutador->put('/api/usuarios/{id}', [$auth, 'actualizar'], ['autenticado', ADMIN],
    'Actualiza el correo, el nombre y el rol de un usuario.');

$enrutador->patch('/api/usuarios/{id}/estado', [$auth, 'cambiarEstado'], ['autenticado', ADMIN],
    'Activa o desactiva una cuenta de usuario.');

$enrutador->get('/api/roles', [$auth, 'roles'], ['autenticado', ADMIN],
    'Lista los roles del sistema y sus permisos.');

// --- 03. Catalogo ------------------------------------------------------------
$enrutador->get('/api/categorias', [$catalogo, 'listarCategorias'], ['autenticado', LECTURA],
    'Lista las categorias con su cantidad de productos.');

$enrutador->post('/api/categorias', [$catalogo, 'crearCategoria'], ['autenticado', ADMIN],
    'Crea una categoria de productos.');

$enrutador->get('/api/productos', [$catalogo, 'listar'], ['autenticado', LECTURA],
    'Listado paginado del catalogo, con filtros y busqueda.');

$enrutador->get('/api/productos/{id}', [$catalogo, 'consultar'], ['autenticado', LECTURA],
    'Consulta un producto y sus ultimos movimientos de inventario.');

$enrutador->post('/api/productos', [$catalogo, 'crear'], ['autenticado', ADMIN],
    'Crea un producto en el catalogo.');

$enrutador->put('/api/productos/{id}', [$catalogo, 'actualizar'], ['autenticado', ADMIN],
    'Actualiza los datos de un producto.');

$enrutador->delete('/api/productos/{id}', [$catalogo, 'descontinuar'], ['autenticado', ADMIN],
    'Da de baja logica un producto, conservandolo en el historico.');

$enrutador->patch('/api/productos/{id}/stock', [$catalogo, 'ajustarStock'], ['autenticado', GESTION],
    'Ajusta las existencias de un producto y registra el movimiento.');

// --- 04. Clientes ------------------------------------------------------------
$enrutador->get('/api/clientes', [$clientes, 'listar'], ['autenticado', LECTURA],
    'Listado paginado de clientes, con filtros y busqueda.');

$enrutador->get('/api/clientes/{id}', [$clientes, 'consultar'], ['autenticado', LECTURA],
    'Consulta un cliente por su identificador.');

$enrutador->post('/api/clientes', [$clientes, 'crear'], ['autenticado', GESTION],
    'Registra un cliente.');

$enrutador->put('/api/clientes/{id}', [$clientes, 'actualizar'], ['autenticado', GESTION],
    'Actualiza los datos de un cliente.');

$enrutador->patch('/api/clientes/{id}/estado', [$clientes, 'cambiarEstado'], ['autenticado', ADMIN],
    'Activa o desactiva un cliente.');

// --- 05. Cotizaciones --------------------------------------------------------
$enrutador->get('/api/cotizaciones', [$cotizaciones, 'listar'], ['autenticado', LECTURA],
    'Listado paginado de cotizaciones, con filtros.');

$enrutador->get('/api/cotizaciones/{id}', [$cotizaciones, 'consultar'], ['autenticado', LECTURA],
    'Consulta una cotizacion con su detalle y su historial de estados.');

$enrutador->post('/api/cotizaciones', [$cotizaciones, 'crear'], ['autenticado', GESTION],
    'Crea una cotizacion. Los importes los calcula el servidor.');

$enrutador->patch('/api/cotizaciones/{id}/estado', [$cotizaciones, 'cambiarEstado'], ['autenticado', GESTION],
    'Cambia el estado de una cotizacion respetando la maquina de estados.');

$enrutador->post('/api/cotizaciones/{id}/convertir-pedido', [$cotizaciones, 'convertirEnPedido'], ['autenticado', GESTION],
    'Convierte una cotizacion aprobada en pedido, verificando existencias.');

// --- 06. Pedidos -------------------------------------------------------------
$enrutador->get('/api/pedidos', [$pedidos, 'listar'], ['autenticado', LECTURA],
    'Listado paginado de pedidos, con filtros.');

$enrutador->get('/api/pedidos/{id}', [$pedidos, 'consultar'], ['autenticado', LECTURA],
    'Consulta un pedido con su detalle y su historial de estados.');

$enrutador->post('/api/pedidos', [$pedidos, 'crear'], ['autenticado', GESTION],
    'Crea un pedido directo. Exige existencias suficientes.');

$enrutador->patch('/api/pedidos/{id}/estado', [$pedidos, 'cambiarEstado'], ['autenticado', GESTION],
    'Cambia el estado de un pedido. Confirmar descuenta inventario.');

// --- 07. Reportes ------------------------------------------------------------
$enrutador->get('/api/reportes/ventas', [$reportes, 'ventas'], ['autenticado', LECTURA],
    'Resumen de ventas de un periodo, con desglose por estado.');

$enrutador->get('/api/reportes/productos-mas-cotizados', [$reportes, 'productosMasCotizados'], ['autenticado', LECTURA],
    'Productos mas solicitados en cotizaciones.');

$enrutador->get('/api/reportes/inventario-bajo', [$reportes, 'inventarioBajo'], ['autenticado', LECTURA],
    'Productos que alcanzaron su nivel minimo de existencias.');

// =============================================================================
// DESPACHO
// =============================================================================
try {
    $solicitud = Solicitud::desdeGlobales();

    // La raiz redirige al cliente de prueba, por comodidad del evaluador.
    if ($solicitud->ruta() === '/' && $solicitud->metodo() === 'GET') {
        header('Location: /cliente/index.html');
        exit;
    }

    $enrutador->despachar($solicitud);

} catch (Throwable $excepcion) {
    // Red de seguridad: ningun error inesperado debe llegar al cliente como
    // una pagina de error de PHP, porque revelaria rutas y codigo fuente.
    $esDesarrollo = ($configuracion['app']['entorno'] ?? 'produccion') === 'desarrollo';

    Respuesta::error(
        'Ocurrio un error interno en el servidor.',
        500,
        $esDesarrollo
            ? [
                'excepcion' => $excepcion->getMessage(),
                'archivo'   => basename($excepcion->getFile()),
                'linea'     => $excepcion->getLine(),
              ]
            : []
    );
}
