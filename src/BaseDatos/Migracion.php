<?php
/**
 * =============================================================================
 * src/BaseDatos/Migracion.php
 * -----------------------------------------------------------------------------
 * Crea el esquema de la base de datos del sistema de showroom y ventas.
 *
 * Se ejecuta al arrancar la aplicacion. Todas las sentencias usan
 * CREATE TABLE IF NOT EXISTS, por lo que invocarla varias veces no causa dano.
 *
 * MODELO DE DATOS (10 tablas)
 *
 *   Seguridad      roles, usuarios, auditoria_acceso
 *   Catalogo       categorias, productos, movimientos_inventario
 *   Comercial      clientes, cotizaciones, cotizacion_lineas,
 *                  pedidos, pedido_lineas
 *
 * @package SistemaShowroomApi
 * =============================================================================
 */

declare(strict_types=1);

namespace App\BaseDatos;

class Migracion
{
    /**
     * Ejecuta la creacion del esquema completo.
     *
     * @return void
     */
    public static function ejecutar(): void
    {
        $pdo    = Conexion::obtener();
        $driver = Conexion::driver();

        // El tipo de la llave primaria autoincremental cambia segun el motor.
        $pk = $driver === 'mysql'
            ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';

        // Sufijo de motor y juego de caracteres (solo MySQL).
        $sufijo = $driver === 'mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : '';

        // Tipo para valores monetarios. DECIMAL evita los errores de redondeo
        // que tendria FLOAT al sumar precios.
        $dinero = $driver === 'mysql' ? 'DECIMAL(14,2)' : 'NUMERIC(14,2)';

        // =====================================================================
        // SEGURIDAD
        // =====================================================================

        // ---------------------------------------------------------------
        // roles: define que puede hacer cada tipo de usuario.
        // ---------------------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS roles (
                id          $pk,
                nombre      VARCHAR(30)  NOT NULL UNIQUE,
                descripcion VARCHAR(200) NULL
            )$sufijo
        ");

        // ---------------------------------------------------------------
        // usuarios: credenciales y rol de cada persona del sistema.
        //
        // La columna se llama "clave_hash" y no "contrasena" para dejar
        // explicito que nunca se almacena la contrasena en texto plano.
        // ---------------------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS usuarios (
                id                $pk,
                nombre_usuario    VARCHAR(50)  NOT NULL UNIQUE,
                correo            VARCHAR(150) NOT NULL UNIQUE,
                clave_hash        VARCHAR(255) NOT NULL,
                nombre_completo   VARCHAR(150) NULL,
                rol_id            INTEGER      NOT NULL,
                estado            VARCHAR(20)  NOT NULL DEFAULT 'activo',
                intentos_fallidos INTEGER      NOT NULL DEFAULT 0,
                bloqueado_hasta   DATETIME     NULL,
                ultimo_acceso     DATETIME     NULL,
                fecha_registro    DATETIME     NOT NULL
            )$sufijo
        ");

        // ---------------------------------------------------------------
        // auditoria_acceso: bitacora de los intentos de inicio de sesion.
        // Permite detectar ataques de fuerza bruta.
        // ---------------------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS auditoria_acceso (
                id            $pk,
                identificador VARCHAR(150) NOT NULL,
                exitoso       INTEGER      NOT NULL DEFAULT 0,
                direccion_ip  VARCHAR(45)  NOT NULL,
                motivo        VARCHAR(100) NULL,
                fecha_intento DATETIME     NOT NULL
            )$sufijo
        ");

        // =====================================================================
        // CATALOGO
        // =====================================================================

        // ---------------------------------------------------------------
        // categorias: agrupa los productos del showroom.
        // ---------------------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS categorias (
                id          $pk,
                nombre      VARCHAR(80)  NOT NULL UNIQUE,
                descripcion VARCHAR(250) NULL,
                activa      INTEGER      NOT NULL DEFAULT 1
            )$sufijo
        ");

        // ---------------------------------------------------------------
        // productos: el catalogo que se cotiza y se vende.
        //
        // "estado" permite dar de baja un producto sin borrarlo, porque
        // seguira apareciendo en cotizaciones y pedidos historicos.
        // ---------------------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS productos (
                id                   $pk,
                codigo               VARCHAR(30)  NOT NULL UNIQUE,
                nombre               VARCHAR(150) NOT NULL,
                descripcion          TEXT         NULL,
                categoria_id         INTEGER      NULL,
                precio_unitario      $dinero      NOT NULL DEFAULT 0,
                stock                INTEGER      NOT NULL DEFAULT 0,
                stock_minimo         INTEGER      NOT NULL DEFAULT 0,
                estado               VARCHAR(20)  NOT NULL DEFAULT 'activo',
                fecha_registro       DATETIME     NOT NULL,
                fecha_actualizacion  DATETIME     NULL
            )$sufijo
        ");

        // ---------------------------------------------------------------
        // movimientos_inventario: trazabilidad de cada cambio de stock.
        //
        // No basta con guardar la existencia actual: hay que poder explicar
        // por que cambio. Cada fila registra el antes y el despues.
        // ---------------------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS movimientos_inventario (
                id             $pk,
                producto_id    INTEGER      NOT NULL,
                tipo           VARCHAR(20)  NOT NULL,
                cantidad       INTEGER      NOT NULL,
                stock_anterior INTEGER      NOT NULL,
                stock_nuevo    INTEGER      NOT NULL,
                motivo         VARCHAR(150) NULL,
                referencia     VARCHAR(50)  NULL,
                usuario_id     INTEGER      NULL,
                fecha          DATETIME     NOT NULL
            )$sufijo
        ");

        // =====================================================================
        // COMERCIAL
        // =====================================================================

        // ---------------------------------------------------------------
        // clientes: personas o empresas que cotizan y compran.
        // ---------------------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS clientes (
                id               $pk,
                tipo_documento   VARCHAR(10)  NOT NULL DEFAULT 'CC',
                numero_documento VARCHAR(30)  NOT NULL UNIQUE,
                nombre           VARCHAR(150) NOT NULL,
                correo           VARCHAR(150) NULL,
                telefono         VARCHAR(30)  NULL,
                direccion        VARCHAR(200) NULL,
                ciudad           VARCHAR(80)  NULL,
                estado           VARCHAR(20)  NOT NULL DEFAULT 'activo',
                fecha_registro   DATETIME     NOT NULL
            )$sufijo
        ");

        // ---------------------------------------------------------------
        // cotizaciones: propuesta economica con vigencia limitada.
        //
        // Los totales se guardan calculados para que el documento sea
        // historico: si manana cambia el precio de un producto, la
        // cotizacion emitida no debe alterarse.
        // ---------------------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cotizaciones (
                id                 $pk,
                numero             VARCHAR(20)  NOT NULL UNIQUE,
                cliente_id         INTEGER      NOT NULL,
                usuario_id         INTEGER      NOT NULL,
                fecha_emision      DATETIME     NOT NULL,
                fecha_vencimiento  DATETIME     NOT NULL,
                estado             VARCHAR(20)  NOT NULL DEFAULT 'borrador',
                subtotal           $dinero      NOT NULL DEFAULT 0,
                valor_descuento    $dinero      NOT NULL DEFAULT 0,
                valor_iva          $dinero      NOT NULL DEFAULT 0,
                total              $dinero      NOT NULL DEFAULT 0,
                observaciones      TEXT         NULL,
                fecha_actualizacion DATETIME    NULL
            )$sufijo
        ");

        // ---------------------------------------------------------------
        // cotizacion_lineas: el detalle de cada cotizacion.
        //
        // Se copia el precio del producto al momento de cotizar, por la
        // misma razon historica explicada arriba.
        // ---------------------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cotizacion_lineas (
                id                   $pk,
                cotizacion_id        INTEGER      NOT NULL,
                producto_id          INTEGER      NOT NULL,
                descripcion          VARCHAR(150) NOT NULL,
                cantidad             INTEGER      NOT NULL,
                precio_unitario      $dinero      NOT NULL,
                descuento_porcentaje $dinero      NOT NULL DEFAULT 0,
                subtotal             $dinero      NOT NULL
            )$sufijo
        ");

        // ---------------------------------------------------------------
        // pedidos: la venta en firme.
        //
        // "cotizacion_id" es opcional: un pedido puede nacer de una
        // cotizacion aprobada o crearse directamente en el showroom.
        // ---------------------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pedidos (
                id                    $pk,
                numero                VARCHAR(20)  NOT NULL UNIQUE,
                cotizacion_id         INTEGER      NULL,
                cliente_id            INTEGER      NOT NULL,
                usuario_id            INTEGER      NOT NULL,
                fecha_pedido          DATETIME     NOT NULL,
                fecha_entrega_estimada DATETIME    NULL,
                estado                VARCHAR(20)  NOT NULL DEFAULT 'pendiente',
                subtotal              $dinero      NOT NULL DEFAULT 0,
                valor_descuento       $dinero      NOT NULL DEFAULT 0,
                valor_iva             $dinero      NOT NULL DEFAULT 0,
                total                 $dinero      NOT NULL DEFAULT 0,
                direccion_entrega     VARCHAR(200) NULL,
                observaciones         TEXT         NULL,
                fecha_actualizacion   DATETIME     NULL
            )$sufijo
        ");

        // ---------------------------------------------------------------
        // pedido_lineas: el detalle de cada pedido.
        // ---------------------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pedido_lineas (
                id                   $pk,
                pedido_id            INTEGER      NOT NULL,
                producto_id          INTEGER      NOT NULL,
                descripcion          VARCHAR(150) NOT NULL,
                cantidad             INTEGER      NOT NULL,
                precio_unitario      $dinero      NOT NULL,
                descuento_porcentaje $dinero      NOT NULL DEFAULT 0,
                subtotal             $dinero      NOT NULL
            )$sufijo
        ");

        // ---------------------------------------------------------------
        // historial_estados: registra cada cambio de estado de cotizaciones
        // y pedidos. Es la trazabilidad comercial del sistema.
        // ---------------------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS historial_estados (
                id             $pk,
                documento_tipo VARCHAR(20)  NOT NULL,
                documento_id   INTEGER      NOT NULL,
                estado_anterior VARCHAR(20) NULL,
                estado_nuevo   VARCHAR(20)  NOT NULL,
                observacion    VARCHAR(250) NULL,
                usuario_id     INTEGER      NULL,
                fecha          DATETIME     NOT NULL
            )$sufijo
        ");

        // =====================================================================
        // INDICES
        // =====================================================================
        // "CREATE INDEX IF NOT EXISTS" es valido en SQLite pero no en MySQL,
        // donde los indices se declaran en database/esquema_mysql.sql.
        if ($driver === 'sqlite') {
            $indices = [
                'CREATE INDEX IF NOT EXISTS idx_usuarios_correo   ON usuarios (correo)',
                'CREATE INDEX IF NOT EXISTS idx_usuarios_rol      ON usuarios (rol_id)',
                'CREATE INDEX IF NOT EXISTS idx_productos_cat     ON productos (categoria_id)',
                'CREATE INDEX IF NOT EXISTS idx_productos_estado  ON productos (estado)',
                'CREATE INDEX IF NOT EXISTS idx_clientes_nombre   ON clientes (nombre)',
                'CREATE INDEX IF NOT EXISTS idx_cot_cliente       ON cotizaciones (cliente_id)',
                'CREATE INDEX IF NOT EXISTS idx_cot_estado        ON cotizaciones (estado)',
                'CREATE INDEX IF NOT EXISTS idx_cotlin_cot        ON cotizacion_lineas (cotizacion_id)',
                'CREATE INDEX IF NOT EXISTS idx_ped_cliente       ON pedidos (cliente_id)',
                'CREATE INDEX IF NOT EXISTS idx_ped_estado        ON pedidos (estado)',
                'CREATE INDEX IF NOT EXISTS idx_pedlin_ped        ON pedido_lineas (pedido_id)',
                'CREATE INDEX IF NOT EXISTS idx_mov_producto      ON movimientos_inventario (producto_id)',
                'CREATE INDEX IF NOT EXISTS idx_hist_documento    ON historial_estados (documento_tipo, documento_id)',
            ];

            foreach ($indices as $sql) {
                $pdo->exec($sql);
            }
        }

        // =====================================================================
        // DATOS MINIMOS: los roles deben existir para poder crear usuarios.
        // =====================================================================
        self::sembrarRoles($pdo);
    }

    /**
     * Inserta los tres roles del sistema si aun no existen.
     *
     * @param \PDO $pdo
     * @return void
     */
    private static function sembrarRoles(\PDO $pdo): void
    {
        $existentes = (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();

        if ($existentes > 0) {
            return;
        }

        $sentencia = $pdo->prepare(
            'INSERT INTO roles (nombre, descripcion) VALUES (:nombre, :descripcion)'
        );

        $roles = [
            ['administrador', 'Acceso total: administra usuarios, catalogo, clientes y documentos.'],
            ['asesor',        'Crea y gestiona clientes, cotizaciones y pedidos. No administra usuarios.'],
            ['consulta',      'Solo lectura: puede consultar catalogo, clientes y documentos.'],
        ];

        foreach ($roles as [$nombre, $descripcion]) {
            $sentencia->execute([':nombre' => $nombre, ':descripcion' => $descripcion]);
        }
    }
}
