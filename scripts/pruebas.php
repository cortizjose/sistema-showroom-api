<?php
/**
 * =============================================================================
 * scripts/pruebas.php
 * -----------------------------------------------------------------------------
 * Bateria de pruebas automatizadas de la API del showroom.
 *
 * Se ejecuta desde la consola:
 *      php scripts/pruebas.php
 *
 * No requiere PHPUnit: se implementa un micro-verificador para que el
 * proyecto siga siendo ejecutable sin instalar dependencias.
 *
 * Las pruebas trabajan sobre una base SQLite temporal e independiente, de
 * modo que nunca alteran los datos reales del sistema.
 * =============================================================================
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// ENTORNO AISLADO DE PRUEBAS
// -----------------------------------------------------------------------------
$rutaBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pruebas_showroom_' . getmypid() . '.sqlite';

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_RUTA=' . $rutaBase);
putenv('JWT_SECRETO=clave-de-pruebas-1234567890');
putenv('APP_ENTORNO=desarrollo');

if (is_file($rutaBase)) {
    unlink($rutaBase);
}

/** @var array<string,mixed> $app */
$app = require dirname(__DIR__) . '/src/arranque.php';

$repos     = $app['repositorios'];
$servicios = $app['servicios'];
$config    = $app['configuracion'];

/** @var \App\Servicios\AutenticacionServicio $auth */
$auth = $servicios['autenticacion'];
/** @var \App\Servicios\CatalogoServicio $catalogo */
$catalogo = $servicios['catalogo'];
/** @var \App\Servicios\ClienteServicio $clientes */
$clientes = $servicios['clientes'];
/** @var \App\Servicios\CotizacionServicio $cotizaciones */
$cotizaciones = $servicios['cotizaciones'];
/** @var \App\Servicios\PedidoServicio $pedidos */
$pedidos = $servicios['pedidos'];
/** @var \App\Servicios\ReporteServicio $reportes */
$reportes = $servicios['reportes'];

// -----------------------------------------------------------------------------
// MICRO-VERIFICADOR
// -----------------------------------------------------------------------------
$total = 0;
$exitosas = 0;
$fallidas = [];

/**
 * Verifica una condicion e imprime el resultado.
 *
 * @param string $descripcion
 * @param bool   $condicion
 * @param string $detalle
 * @return void
 */
function verificar(string $descripcion, bool $condicion, string $detalle = ''): void
{
    global $total, $exitosas, $fallidas;

    $total++;

    if ($condicion) {
        $exitosas++;
        echo "  [OK]    $descripcion" . PHP_EOL;
        return;
    }

    $fallidas[] = $descripcion;
    echo "  [FALLA] $descripcion" . ($detalle !== '' ? "  -> $detalle" : '') . PHP_EOL;
}

/**
 * @param string $titulo
 * @return void
 */
function bloque(string $titulo): void
{
    echo PHP_EOL . str_repeat('-', 72) . PHP_EOL . $titulo . PHP_EOL . str_repeat('-', 72) . PHP_EOL;
}

echo PHP_EOL;
echo "======================================================================" . PHP_EOL;
echo " PRUEBAS DE LA API DEL SISTEMA DE SHOWROOM Y VENTAS" . PHP_EOL;
echo " Evidencia GA7-220501096-AA5-EV03" . PHP_EOL;
echo "======================================================================" . PHP_EOL;

// =============================================================================
// PREPARACION: usuarios, categoria, productos y cliente
// =============================================================================
bloque('PREPARACION DEL ESCENARIO');

$rAdmin = $auth->registrar([
    'nombre_usuario' => 'admin',
    'correo'         => 'admin@showroom.com',
    'contrasena'     => 'Admin2025',
    'rol'            => 'administrador',
], 'administrador');

verificar('Se crea el usuario administrador', $rAdmin['exito'], $rAdmin['mensaje']);
$idAdmin = (int) ($rAdmin['datos']['usuario']['id'] ?? 0);

$rAsesor = $auth->registrar([
    'nombre_usuario' => 'asesor',
    'correo'         => 'asesor@showroom.com',
    'contrasena'     => 'Asesor2025',
    'rol'            => 'asesor',
]);
$idAsesor = (int) ($rAsesor['datos']['usuario']['id'] ?? 0);
verificar('Se crea el usuario asesor', $rAsesor['exito']);

$rCat = $catalogo->crearCategoria(['nombre' => 'Salas', 'descripcion' => 'Sofas y sillones']);
verificar('Se crea una categoria', $rCat['exito']);
$idCategoria = (int) ($rCat['datos']['categoria']['id'] ?? 0);

$rProd = $catalogo->crearProducto([
    'codigo'          => 'SOF-001',
    'nombre'          => 'Sofa modular tres puestos',
    'categoria_id'    => $idCategoria,
    'precio_unitario' => 1000000,
    'stock'           => 10,
    'stock_minimo'    => 2,
], $idAdmin);
verificar('Se crea un producto con stock inicial', $rProd['exito'], $rProd['mensaje']);
$idProducto = (int) ($rProd['datos']['producto']['id'] ?? 0);

$rCli = $clientes->crear([
    'tipo_documento'   => 'CC',
    'numero_documento' => '1020304050',
    'nombre'           => 'Laura Gomez',
    'correo'           => 'laura@correo.com',
]);
verificar('Se crea un cliente', $rCli['exito'], $rCli['mensaje']);
$idCliente = (int) ($rCli['datos']['cliente']['id'] ?? 0);

// =============================================================================
// AUTENTICACION Y ROLES
// =============================================================================
bloque('CP-01 a CP-06  AUTENTICACION Y ROLES');

$r = $auth->iniciarSesion(['usuario' => 'admin', 'contrasena' => 'Admin2025'], '127.0.0.1');
verificar(
    'CP-01 Credenciales correctas devuelven autenticacion satisfactoria',
    $r['exito'] && str_contains(mb_strtolower($r['mensaje']), 'satisfactoria'),
    $r['mensaje']
);

$tokenAdmin = $r['datos']['token'] ?? '';
verificar('CP-01 Se emite un token JWT de tres segmentos', count(explode('.', $tokenAdmin)) === 3);

verificar(
    'CP-02 El token incluye el rol del usuario',
    ($app['jwt']->verificar($tokenAdmin)['rol'] ?? '') === 'administrador'
);

$r = $auth->iniciarSesion(['usuario' => 'admin', 'contrasena' => 'Incorrecta1'], '127.0.0.1');
verificar(
    'CP-03 Contrasena incorrecta devuelve error en la autenticacion (401)',
    !$r['exito'] && $r['codigo'] === 401
);

$r = $auth->iniciarSesion(['usuario' => 'noexiste', 'contrasena' => 'Clave2025'], '127.0.0.1');
verificar(
    'CP-04 El mensaje no revela si la cuenta existe (anti-enumeracion)',
    str_contains($r['mensaje'], 'usuario o contrasena incorrectos')
);

$r = $auth->registrar([
    'nombre_usuario' => 'otro',
    'correo'         => 'admin@showroom.com',
    'contrasena'     => 'Clave2025',
]);
verificar('CP-05 Correo duplicado devuelve conflicto (409)', !$r['exito'] && $r['codigo'] === 409);

$r = $auth->cambiarClave($idAsesor, [
    'contrasena_actual' => 'Asesor2025',
    'contrasena_nueva'  => 'Asesor2025',
]);
verificar(
    'CP-06 La contrasena nueva no puede ser igual a la actual',
    !$r['exito'] && isset($r['errores']['contrasena_nueva'])
);

// =============================================================================
// CATALOGO E INVENTARIO
// =============================================================================
bloque('CP-07 a CP-12  CATALOGO E INVENTARIO');

$r = $catalogo->crearProducto([
    'codigo'          => 'SOF-001',
    'nombre'          => 'Duplicado',
    'precio_unitario' => 500000,
], $idAdmin);
verificar('CP-07 Codigo de producto duplicado devuelve 409', !$r['exito'] && $r['codigo'] === 409);

$r = $catalogo->crearProducto([
    'codigo'          => 'XX-001',
    'nombre'          => 'Precio negativo',
    'precio_unitario' => -100,
], $idAdmin);
verificar('CP-08 Precio negativo es rechazado (422)', !$r['exito'] && $r['codigo'] === 422);

$r = $catalogo->consultarProducto($idProducto);
verificar(
    'CP-09 El stock inicial quedo registrado como movimiento de inventario',
    $r['exito'] && count($r['datos']['movimientos']) === 1
);

$r = $catalogo->ajustarStock($idProducto, ['cantidad' => 5, 'motivo' => 'Compra'], $idAdmin);
verificar(
    'CP-10 Ajuste positivo de stock: 10 + 5 = 15',
    $r['exito'] && $r['datos']['stockNuevo'] === 15,
    'stock=' . ($r['datos']['stockNuevo'] ?? '?')
);

$r = $catalogo->ajustarStock($idProducto, ['cantidad' => -100], $idAdmin);
verificar(
    'CP-11 Un ajuste que dejaria el stock negativo se rechaza (409)',
    !$r['exito'] && $r['codigo'] === 409
);

$r = $catalogo->listarProductos(['busqueda' => 'Sofa']);
verificar(
    'CP-12 La busqueda del catalogo encuentra el producto',
    $r['exito'] && $r['datos']['paginacion']['total'] === 1
);

// =============================================================================
// COTIZACIONES
// =============================================================================
bloque('CP-13 a CP-19  COTIZACIONES');

$r = $cotizaciones->crear([
    'cliente_id' => $idCliente,
    'lineas'     => [['producto_id' => $idProducto, 'cantidad' => 2]],
], $idAsesor);

verificar('CP-13 Se crea una cotizacion (201)', $r['exito'] && $r['codigo'] === 201, $r['mensaje']);
$cot = $r['datos']['cotizacion'] ?? [];
$idCotizacion = (int) ($cot['id'] ?? 0);

// 2 x 1.000.000 = 2.000.000 ; IVA 19 % = 380.000 ; total = 2.380.000
verificar(
    'CP-14 Los totales se calculan en el servidor con IVA del 19 %',
    ($cot['totales']['subtotal'] ?? 0) == 2000000
    && ($cot['totales']['iva'] ?? 0) == 380000
    && ($cot['totales']['total'] ?? 0) == 2380000,
    json_encode($cot['totales'] ?? [])
);

verificar('CP-15 La cotizacion nace en estado borrador', ($cot['estado'] ?? '') === 'borrador');

verificar(
    'CP-15 Se asigna numeracion consecutiva con prefijo COT',
    str_starts_with((string) ($cot['numero'] ?? ''), 'COT-')
);

// El precio lo pone el servidor, no el cliente.
$r = $cotizaciones->crear([
    'cliente_id' => $idCliente,
    'lineas'     => [[
        'producto_id'     => $idProducto,
        'cantidad'        => 1,
        'precio_unitario' => 1,       // intento de manipulacion
        'subtotal'        => 1,
    ]],
], $idAsesor);
verificar(
    'CP-16 SEGURIDAD: se ignora el precio enviado por el cliente',
    $r['exito'] && ($r['datos']['cotizacion']['totales']['subtotal'] ?? 0) == 1000000,
    'subtotal=' . ($r['datos']['cotizacion']['totales']['subtotal'] ?? '?')
);

$r = $cotizaciones->crear([
    'cliente_id' => $idCliente,
    'lineas'     => [['producto_id' => $idProducto, 'cantidad' => 1, 'descuento_porcentaje' => 50]],
], $idAsesor);
verificar(
    'CP-17 Un descuento superior al maximo permitido se rechaza (422)',
    !$r['exito'] && $r['codigo'] === 422
);

$r = $cotizaciones->crear(['cliente_id' => $idCliente, 'lineas' => []], $idAsesor);
verificar('CP-18 Una cotizacion sin lineas se rechaza (422)', !$r['exito'] && $r['codigo'] === 422);

$r = $cotizaciones->cambiarEstado($idCotizacion, ['estado' => 'aprobada'], $idAsesor);
verificar(
    'CP-19 No se puede saltar de borrador a aprobada (maquina de estados)',
    !$r['exito']
);

// =============================================================================
// CONVERSION A PEDIDO E INVENTARIO
// =============================================================================
bloque('CP-20 a CP-26  PEDIDOS E INVENTARIO');

$r = $cotizaciones->convertirEnPedido($idCotizacion, [], $idAsesor);
verificar(
    'CP-20 Una cotizacion en borrador no se puede convertir en pedido',
    !$r['exito'] && $r['codigo'] === 409
);

$cotizaciones->cambiarEstado($idCotizacion, ['estado' => 'enviada'], $idAsesor);
$cotizaciones->cambiarEstado($idCotizacion, ['estado' => 'aprobada'], $idAsesor);

$r = $cotizaciones->convertirEnPedido($idCotizacion, ['direccion_entrega' => 'Calle 45'], $idAsesor);
verificar('CP-21 Una cotizacion aprobada se convierte en pedido (201)', $r['exito'] && $r['codigo'] === 201, $r['mensaje']);
$idPedido = (int) ($r['datos']['pedido']['id'] ?? 0);

verificar(
    'CP-21 La cotizacion queda marcada como convertida',
    ($r['datos']['cotizacion']['estado'] ?? '') === 'convertida'
);

$r = $cotizaciones->convertirEnPedido($idCotizacion, [], $idAsesor);
verificar('CP-22 La misma cotizacion no se puede convertir dos veces', !$r['exito'] && $r['codigo'] === 409);

$stockAntes = $repos['productos']->buscarPorId($idProducto)->stock;

$r = $pedidos->cambiarEstado($idPedido, ['estado' => 'confirmado'], $idAsesor);
$stockDespues = $repos['productos']->buscarPorId($idProducto)->stock;

verificar(
    'CP-23 Confirmar el pedido descuenta el inventario (2 unidades)',
    $r['exito'] && $stockDespues === $stockAntes - 2,
    "antes=$stockAntes despues=$stockDespues"
);

$r = $pedidos->cambiarEstado($idPedido, ['estado' => 'anulado'], $idAsesor);
$stockFinal = $repos['productos']->buscarPorId($idProducto)->stock;

verificar(
    'CP-24 Anular el pedido devuelve el inventario',
    $r['exito'] && $stockFinal === $stockAntes,
    "esperado=$stockAntes obtenido=$stockFinal"
);

$r = $pedidos->cambiarEstado($idPedido, ['estado' => 'confirmado'], $idAsesor);
verificar('CP-25 Un pedido anulado ya no admite cambios de estado', !$r['exito'] && $r['codigo'] === 409);

$r = $pedidos->crear([
    'cliente_id' => $idCliente,
    'lineas'     => [['producto_id' => $idProducto, 'cantidad' => 9999]],
], $idAsesor);
verificar(
    'CP-26 Un pedido sin existencias suficientes se rechaza (409)',
    !$r['exito'] && $r['codigo'] === 409
);

// =============================================================================
// REGLAS TRANSVERSALES
// =============================================================================
bloque('CP-27 a CP-31  REGLAS TRANSVERSALES');

$r = $cotizaciones->crear([
    'cliente_id' => 99999,
    'lineas'     => [['producto_id' => $idProducto, 'cantidad' => 1]],
], $idAsesor);
verificar('CP-27 Cotizar a un cliente inexistente se rechaza (422)', !$r['exito'] && $r['codigo'] === 422);

$clientes->cambiarEstado($idCliente, ['estado' => 'inactivo']);
$r = $cotizaciones->crear([
    'cliente_id' => $idCliente,
    'lineas'     => [['producto_id' => $idProducto, 'cantidad' => 1]],
], $idAsesor);
verificar('CP-28 No se emiten documentos a un cliente inactivo (409)', !$r['exito'] && $r['codigo'] === 409);
$clientes->cambiarEstado($idCliente, ['estado' => 'activo']);

$catalogo->descontinuarProducto($idProducto);
$r = $cotizaciones->crear([
    'cliente_id' => $idCliente,
    'lineas'     => [['producto_id' => $idProducto, 'cantidad' => 1]],
], $idAsesor);
verificar('CP-29 No se cotiza un producto descontinuado (422)', !$r['exito'] && $r['codigo'] === 422);

$r = $auth->cambiarEstado($idAdmin, ['estado' => 'inactivo'], $idAdmin);
verificar('CP-30 Un administrador no puede desactivarse a si mismo (409)', !$r['exito'] && $r['codigo'] === 409);

$r = $auth->listar([]);
$json = json_encode($r);
verificar(
    'CP-31 SEGURIDAD: el listado de usuarios nunca expone el hash',
    !str_contains($json, 'clave_hash') && !str_contains($json, 'claveHash')
);

// =============================================================================
// TRAZABILIDAD Y REPORTES
// =============================================================================
bloque('CP-32 a CP-35  TRAZABILIDAD Y REPORTES');

$r = $cotizaciones->consultar($idCotizacion);
verificar(
    'CP-32 La cotizacion conserva su historial de estados',
    $r['exito'] && count($r['datos']['historial']) >= 4,
    'registros=' . count($r['datos']['historial'] ?? [])
);

$r = $pedidos->consultar($idPedido);
verificar('CP-33 El pedido conserva su historial de estados', $r['exito'] && count($r['datos']['historial']) >= 3);

$r = $reportes->ventas([]);
verificar('CP-34 El reporte de ventas se genera correctamente', $r['exito'] && isset($r['datos']['resumen']['total']));

$r = $reportes->ventas(['desde' => '2026-12-31', 'hasta' => '2026-01-01']);
verificar('CP-34 Un rango de fechas invertido se rechaza (422)', !$r['exito'] && $r['codigo'] === 422);

$r = $reportes->productosMasCotizados(['limite' => 5]);
verificar('CP-35 El reporte de productos mas cotizados devuelve datos', $r['exito'] && count($r['datos']['productos']) > 0);

// =============================================================================
// INYECCION SQL
// =============================================================================
bloque('CP-36  SEGURIDAD: INYECCION SQL');

$r = $auth->iniciarSesion(['usuario' => "admin' OR '1'='1", 'contrasena' => 'x'], '127.0.0.1');
verificar('CP-36 Inyeccion SQL en el login es rechazada (401)', !$r['exito'] && $r['codigo'] === 401);

$r = $clientes->listar(['busqueda' => "'; DROP TABLE clientes; --"]);
verificar(
    'CP-36 Inyeccion SQL en un filtro de busqueda no dana la base de datos',
    $r['exito'] && $repos['clientes']->contar() === 1
);

// =============================================================================
// RESUMEN
// =============================================================================
echo PHP_EOL . str_repeat('=', 72) . PHP_EOL;
echo " RESUMEN DE LA EJECUCION" . PHP_EOL;
echo str_repeat('=', 72) . PHP_EOL;
echo " Pruebas ejecutadas : $total" . PHP_EOL;
echo " Pruebas exitosas   : $exitosas" . PHP_EOL;
echo " Pruebas fallidas   : " . count($fallidas) . PHP_EOL;

if ($fallidas !== []) {
    echo PHP_EOL . " Detalle de las fallidas:" . PHP_EOL;
    foreach ($fallidas as $f) {
        echo "   - $f" . PHP_EOL;
    }
}

echo str_repeat('=', 72) . PHP_EOL;
echo $fallidas === []
    ? ' RESULTADO: TODAS LAS PRUEBAS FUERON SATISFACTORIAS.' . PHP_EOL
    : ' RESULTADO: EXISTEN PRUEBAS FALLIDAS. REVISE EL DETALLE.' . PHP_EOL;
echo str_repeat('=', 72) . PHP_EOL . PHP_EOL;

// -----------------------------------------------------------------------------
// LIMPIEZA
// -----------------------------------------------------------------------------
\App\BaseDatos\Conexion::cerrar();

if (is_file($rutaBase)) {
    @unlink($rutaBase);
}

exit($fallidas === [] ? 0 : 1);
