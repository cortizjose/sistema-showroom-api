<?php
/**
 * =============================================================================
 * scripts/sembrar-datos.php
 * -----------------------------------------------------------------------------
 * Carga datos de ejemplo para poder probar la API de inmediato.
 *
 * Crea:
 *   - Tres usuarios, uno por cada rol.
 *   - Cuatro categorias y ocho productos del showroom.
 *   - Tres clientes.
 *
 * Se ejecuta desde la consola:
 *      php scripts/sembrar-datos.php
 *
 * Es idempotente: si los datos ya existen, no los duplica.
 * =============================================================================
 */

declare(strict_types=1);

/** @var array<string,mixed> $app */
$app = require dirname(__DIR__) . '/src/arranque.php';

/** @var \App\Repositorios\UsuarioRepositorio $usuarios */
$usuarios = $app['repositorios']['usuarios'];

/** @var \App\Repositorios\ProductoRepositorio $productos */
$productos = $app['repositorios']['productos'];

/** @var \App\Repositorios\ClienteRepositorio $clientes */
$clientes = $app['repositorios']['clientes'];

$algoritmo = $app['configuracion']['seguridad']['algoritmoHash'];

echo PHP_EOL;
echo "======================================================================" . PHP_EOL;
echo " CARGA DE DATOS DE EJEMPLO" . PHP_EOL;
echo "======================================================================" . PHP_EOL . PHP_EOL;

// -----------------------------------------------------------------------------
// USUARIOS
// -----------------------------------------------------------------------------
echo "USUARIOS" . PHP_EOL;

$cuentas = [
    ['admin',  'admin@showroom.com',  'Admin2025',  'administrador', 'Administrador del sistema'],
    ['asesor', 'asesor@showroom.com', 'Asesor2025', 'asesor',        'Asesor comercial'],
    ['consulta', 'consulta@showroom.com', 'Consulta2025', 'consulta', 'Usuario de consulta'],
];

foreach ($cuentas as [$nombre, $correo, $clave, $rol, $nombreCompleto]) {
    if ($usuarios->existeNombreUsuario($nombre)) {
        echo "  ya existe   $nombre" . PHP_EOL;
        continue;
    }

    $rolId = $usuarios->idDeRol($rol);
    $usuarios->crear($nombre, $correo, password_hash($clave, $algoritmo), (int) $rolId, $nombreCompleto);

    echo "  creado      $nombre / $clave   (rol: $rol)" . PHP_EOL;
}

// -----------------------------------------------------------------------------
// CATEGORIAS
// -----------------------------------------------------------------------------
echo PHP_EOL . "CATEGORIAS" . PHP_EOL;

$categorias = [
    'Salas'      => 'Sofas, sillones y mesas de centro',
    'Comedores'  => 'Mesas de comedor y sillas',
    'Dormitorio' => 'Camas, colchones y mesas de noche',
    'Decoracion' => 'Lamparas, espejos y accesorios',
];

$idsCategorias = [];

foreach ($categorias as $nombre => $descripcion) {
    if ($productos->existeCategoria($nombre)) {
        echo "  ya existe   $nombre" . PHP_EOL;

        // Se recupera el id para poder asociar los productos.
        foreach ($productos->listarCategorias() as $c) {
            if ($c['nombre'] === $nombre) {
                $idsCategorias[$nombre] = (int) $c['id'];
            }
        }

        continue;
    }

    $idsCategorias[$nombre] = $productos->crearCategoria($nombre, $descripcion);
    echo "  creada      $nombre" . PHP_EOL;
}

// -----------------------------------------------------------------------------
// PRODUCTOS
// -----------------------------------------------------------------------------
echo PHP_EOL . "PRODUCTOS" . PHP_EOL;

$catalogo = [
    ['SOF-001', 'Sofa modular tres puestos',  'Salas',      2850000, 6,  2, 'Tapizado en lino, estructura en madera de cedro'],
    ['SOF-002', 'Sillon reclinable individual', 'Salas',    1290000, 10, 3, 'Mecanismo reclinable manual'],
    ['MES-001', 'Mesa de centro en cristal',  'Salas',       680000, 12, 4, 'Vidrio templado de 10 mm y base metalica'],
    ['COM-001', 'Comedor seis puestos',       'Comedores',  3450000, 4,  2, 'Mesa en madera maciza con seis sillas tapizadas'],
    ['COM-002', 'Silla de comedor tapizada',  'Comedores',   320000, 24, 8, 'Tapizado en pana, patas en roble'],
    ['CAM-001', 'Cama king con base cama',    'Dormitorio', 2100000, 5,  2, 'Incluye base cama y cabecero tapizado'],
    ['CAM-002', 'Mesa de noche dos cajones',  'Dormitorio',  450000, 14, 4, 'Acabado en nogal'],
    ['DEC-001', 'Lampara de piso arco',       'Decoracion',  520000, 3,  5, 'Pantalla en tela, base en marmol'],
];

foreach ($catalogo as [$codigo, $nombre, $categoria, $precio, $stock, $minimo, $descripcion]) {
    if ($productos->existeCodigo($codigo)) {
        echo "  ya existe   $codigo" . PHP_EOL;
        continue;
    }

    $producto = $productos->crear([
        'codigo'          => $codigo,
        'nombre'          => $nombre,
        'descripcion'     => $descripcion,
        'categoria_id'    => $idsCategorias[$categoria] ?? null,
        'precio_unitario' => $precio,
        'stock'           => 0,
        'stock_minimo'    => $minimo,
    ]);

    // El stock inicial entra como movimiento, para dejar trazabilidad.
    $productos->moverStock(
        (int) $producto->id,
        $stock,
        'entrada',
        'Stock inicial del producto',
        $codigo,
        null
    );

    printf("  creado      %-9s %-32s %10s  stock: %d%s", $codigo, $nombre,
        number_format($precio, 0, ',', '.'), $stock, PHP_EOL);
}

// -----------------------------------------------------------------------------
// CLIENTES
// -----------------------------------------------------------------------------
echo PHP_EOL . "CLIENTES" . PHP_EOL;

$listaClientes = [
    ['CC',  '1020304050', 'Laura Gomez Restrepo',   'laura.gomez@correo.com',  '3101234567', 'Calle 45 # 12-30', 'Medellin'],
    ['NIT', '900123456-7', 'Distribuciones Andinas SAS', 'compras@andinas.com', '6045551020', 'Carrera 50 # 10-15', 'Medellin'],
    ['CC',  '7080910111', 'Carlos Rueda Pineda',    'carlos.rueda@correo.com', '3159876543', 'Avenida 30 # 5-60', 'Bogota'],
];

foreach ($listaClientes as [$tipo, $documento, $nombre, $correo, $telefono, $direccion, $ciudad]) {
    if ($clientes->existeDocumento($documento)) {
        echo "  ya existe   $documento" . PHP_EOL;
        continue;
    }

    $clientes->crear([
        'tipo_documento'   => $tipo,
        'numero_documento' => $documento,
        'nombre'           => $nombre,
        'correo'           => $correo,
        'telefono'         => $telefono,
        'direccion'        => $direccion,
        'ciudad'           => $ciudad,
    ]);

    echo "  creado      $documento  $nombre" . PHP_EOL;
}

// -----------------------------------------------------------------------------
// RESUMEN
// -----------------------------------------------------------------------------
echo PHP_EOL;
echo "======================================================================" . PHP_EOL;
echo " DATOS DISPONIBLES" . PHP_EOL;
echo "======================================================================" . PHP_EOL;
printf("  Usuarios   : %d%s", $usuarios->contar(), PHP_EOL);
printf("  Productos  : %d%s", $productos->contar(), PHP_EOL);
printf("  Clientes   : %d%s", $clientes->contar(), PHP_EOL);
echo PHP_EOL;
echo " CUENTAS PARA PROBAR LA API" . PHP_EOL;
echo "   admin    / Admin2025      administrador (acceso total)" . PHP_EOL;
echo "   asesor   / Asesor2025     asesor (clientes, cotizaciones, pedidos)" . PHP_EOL;
echo "   consulta / Consulta2025   consulta (solo lectura)" . PHP_EOL;
echo "======================================================================" . PHP_EOL . PHP_EOL;
