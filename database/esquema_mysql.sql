-- =============================================================================
-- database/esquema_mysql.sql
-- -----------------------------------------------------------------------------
-- Esquema de la base de datos del Sistema de Showroom y Ventas para MySQL.
--
-- Solo es necesario si en el archivo .env se define DB_DRIVER=mysql.
-- Con SQLite (valor por defecto) las tablas se crean solas al arrancar.
--
-- Ejecucion desde phpMyAdmin:
--   Importar > seleccionar este archivo > Continuar.
--
-- Ejecucion desde la consola de XAMPP:
--   C:\xampp\mysql\bin\mysql.exe -u root -p < database/esquema_mysql.sql
--
-- Evidencia GA7-220501096-AA5-EV03
-- =============================================================================

CREATE DATABASE IF NOT EXISTS sistema_showroom
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE sistema_showroom;

-- =============================================================================
-- BLOQUE 1: SEGURIDAD
-- =============================================================================

-- -----------------------------------------------------------------------------
-- roles: define que puede hacer cada tipo de usuario.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre      VARCHAR(30)  NOT NULL COMMENT 'administrador | asesor | consulta',
    descripcion VARCHAR(200) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Roles del sistema';

-- -----------------------------------------------------------------------------
-- usuarios: credenciales y rol de cada persona.
--
-- NOTA DE SEGURIDAD: la columna se llama "clave_hash" y no "contrasena" para
-- dejar explicito que jamas se guarda la contrasena en texto plano. El valor
-- almacenado es un hash bcrypt generado por password_hash() de PHP.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_usuario    VARCHAR(50)  NOT NULL,
    correo            VARCHAR(150) NOT NULL,
    clave_hash        VARCHAR(255) NOT NULL COMMENT 'Hash bcrypt, nunca texto plano',
    nombre_completo   VARCHAR(150) NULL,
    rol_id            INT UNSIGNED NOT NULL,
    estado            VARCHAR(20)  NOT NULL DEFAULT 'activo',
    intentos_fallidos INT          NOT NULL DEFAULT 0,
    bloqueado_hasta   DATETIME     NULL,
    ultimo_acceso     DATETIME     NULL,
    fecha_registro    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_nombre (nombre_usuario),
    UNIQUE KEY uq_usuarios_correo (correo),
    KEY idx_usuarios_rol (rol_id),
    CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Usuarios del sistema';

-- -----------------------------------------------------------------------------
-- auditoria_acceso: bitacora de los intentos de inicio de sesion.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auditoria_acceso (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    identificador VARCHAR(150) NOT NULL,
    exitoso       TINYINT(1)   NOT NULL DEFAULT 0,
    direccion_ip  VARCHAR(45)  NOT NULL,
    motivo        VARCHAR(100) NULL,
    fecha_intento DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_auditoria_fecha (fecha_intento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auditoria de intentos de acceso';

-- =============================================================================
-- BLOQUE 2: CATALOGO
-- =============================================================================

CREATE TABLE IF NOT EXISTS categorias (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre      VARCHAR(80)  NOT NULL,
    descripcion VARCHAR(250) NULL,
    activa      TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categorias_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Categorias del catalogo';

-- -----------------------------------------------------------------------------
-- productos: el catalogo del showroom.
--
-- "estado" permite dar de baja un producto sin borrarlo, porque seguira
-- apareciendo en cotizaciones y pedidos historicos.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS productos (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    codigo              VARCHAR(30)   NOT NULL,
    nombre              VARCHAR(150)  NOT NULL,
    descripcion         TEXT          NULL,
    categoria_id        INT UNSIGNED  NULL,
    precio_unitario     DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'DECIMAL evita errores de redondeo',
    stock               INT           NOT NULL DEFAULT 0,
    stock_minimo        INT           NOT NULL DEFAULT 0 COMMENT 'Umbral de reposicion',
    estado              VARCHAR(20)   NOT NULL DEFAULT 'activo' COMMENT 'activo | descontinuado',
    fecha_registro      DATETIME      NOT NULL,
    fecha_actualizacion DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_productos_codigo (codigo),
    KEY idx_productos_categoria (categoria_id),
    KEY idx_productos_estado (estado),
    CONSTRAINT fk_productos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catalogo de productos';

-- -----------------------------------------------------------------------------
-- movimientos_inventario: trazabilidad de cada cambio de existencias.
--
-- No basta con guardar el stock actual: hay que poder explicar por que cambio.
-- Cada fila registra el antes y el despues.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movimientos_inventario (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    producto_id    INT UNSIGNED NOT NULL,
    tipo           VARCHAR(20)  NOT NULL COMMENT 'entrada | salida | ajuste',
    cantidad       INT          NOT NULL COMMENT 'Positiva suma, negativa resta',
    stock_anterior INT          NOT NULL,
    stock_nuevo    INT          NOT NULL,
    motivo         VARCHAR(150) NULL,
    referencia     VARCHAR(50)  NULL COMMENT 'Numero de pedido u otro documento',
    usuario_id     INT UNSIGNED NULL,
    fecha          DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_movimientos_producto (producto_id),
    CONSTRAINT fk_movimientos_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kardex de inventario';

-- =============================================================================
-- BLOQUE 3: COMERCIAL
-- =============================================================================

CREATE TABLE IF NOT EXISTS clientes (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo_documento   VARCHAR(10)  NOT NULL DEFAULT 'CC' COMMENT 'CC | NIT | CE | PAS',
    numero_documento VARCHAR(30)  NOT NULL,
    nombre           VARCHAR(150) NOT NULL COMMENT 'Nombre completo o razon social',
    correo           VARCHAR(150) NULL,
    telefono         VARCHAR(30)  NULL,
    direccion        VARCHAR(200) NULL,
    ciudad           VARCHAR(80)  NULL,
    estado           VARCHAR(20)  NOT NULL DEFAULT 'activo',
    fecha_registro   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clientes_documento (numero_documento),
    KEY idx_clientes_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Clientes del showroom';

-- -----------------------------------------------------------------------------
-- cotizaciones: propuesta economica con vigencia limitada.
--
-- Los totales se guardan ya calculados para que el documento sea historico:
-- si manana cambia el precio de un producto, la cotizacion emitida no cambia.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cotizaciones (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    numero              VARCHAR(20)   NOT NULL COMMENT 'Consecutivo COT-000001',
    cliente_id          INT UNSIGNED  NOT NULL,
    usuario_id          INT UNSIGNED  NOT NULL COMMENT 'Asesor que la elaboro',
    fecha_emision       DATETIME      NOT NULL,
    fecha_vencimiento   DATETIME      NOT NULL,
    estado              VARCHAR(20)   NOT NULL DEFAULT 'borrador'
                        COMMENT 'borrador|enviada|aprobada|rechazada|vencida|convertida|anulada',
    subtotal            DECIMAL(14,2) NOT NULL DEFAULT 0,
    valor_descuento     DECIMAL(14,2) NOT NULL DEFAULT 0,
    valor_iva           DECIMAL(14,2) NOT NULL DEFAULT 0,
    total               DECIMAL(14,2) NOT NULL DEFAULT 0,
    observaciones       TEXT          NULL,
    fecha_actualizacion DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cotizaciones_numero (numero),
    KEY idx_cotizaciones_cliente (cliente_id),
    KEY idx_cotizaciones_estado (estado),
    CONSTRAINT fk_cotizaciones_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id),
    CONSTRAINT fk_cotizaciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cotizaciones emitidas';

CREATE TABLE IF NOT EXISTS cotizacion_lineas (
    id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    cotizacion_id        INT UNSIGNED  NOT NULL,
    producto_id          INT UNSIGNED  NOT NULL,
    descripcion          VARCHAR(150)  NOT NULL,
    cantidad             INT           NOT NULL,
    precio_unitario      DECIMAL(14,2) NOT NULL COMMENT 'Precio al momento de cotizar',
    descuento_porcentaje DECIMAL(14,2) NOT NULL DEFAULT 0,
    subtotal             DECIMAL(14,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cotlineas_cotizacion (cotizacion_id),
    CONSTRAINT fk_cotlineas_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id),
    CONSTRAINT fk_cotlineas_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Detalle de las cotizaciones';

-- -----------------------------------------------------------------------------
-- pedidos: la venta en firme.
--
-- "cotizacion_id" es opcional: un pedido puede nacer de una cotizacion
-- aprobada o crearse directamente en el showroom.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pedidos (
    id                     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    numero                 VARCHAR(20)   NOT NULL COMMENT 'Consecutivo PED-000001',
    cotizacion_id          INT UNSIGNED  NULL,
    cliente_id             INT UNSIGNED  NOT NULL,
    usuario_id             INT UNSIGNED  NOT NULL,
    fecha_pedido           DATETIME      NOT NULL,
    fecha_entrega_estimada DATETIME      NULL,
    estado                 VARCHAR(20)   NOT NULL DEFAULT 'pendiente'
                           COMMENT 'pendiente|confirmado|en_preparacion|despachado|entregado|anulado',
    subtotal               DECIMAL(14,2) NOT NULL DEFAULT 0,
    valor_descuento        DECIMAL(14,2) NOT NULL DEFAULT 0,
    valor_iva              DECIMAL(14,2) NOT NULL DEFAULT 0,
    total                  DECIMAL(14,2) NOT NULL DEFAULT 0,
    direccion_entrega      VARCHAR(200)  NULL,
    observaciones          TEXT          NULL,
    fecha_actualizacion    DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pedidos_numero (numero),
    KEY idx_pedidos_cliente (cliente_id),
    KEY idx_pedidos_estado (estado),
    CONSTRAINT fk_pedidos_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id),
    CONSTRAINT fk_pedidos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id),
    CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pedidos de venta';

CREATE TABLE IF NOT EXISTS pedido_lineas (
    id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    pedido_id            INT UNSIGNED  NOT NULL,
    producto_id          INT UNSIGNED  NOT NULL,
    descripcion          VARCHAR(150)  NOT NULL,
    cantidad             INT           NOT NULL,
    precio_unitario      DECIMAL(14,2) NOT NULL,
    descuento_porcentaje DECIMAL(14,2) NOT NULL DEFAULT 0,
    subtotal             DECIMAL(14,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pedlineas_pedido (pedido_id),
    CONSTRAINT fk_pedlineas_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id),
    CONSTRAINT fk_pedlineas_producto FOREIGN KEY (producto_id) REFERENCES productos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Detalle de los pedidos';

-- -----------------------------------------------------------------------------
-- historial_estados: trazabilidad comercial de cotizaciones y pedidos.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS historial_estados (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    documento_tipo  VARCHAR(20)  NOT NULL COMMENT 'cotizacion | pedido',
    documento_id    INT UNSIGNED NOT NULL,
    estado_anterior VARCHAR(20)  NULL,
    estado_nuevo    VARCHAR(20)  NOT NULL,
    observacion     VARCHAR(250) NULL,
    usuario_id      INT UNSIGNED NULL,
    fecha           DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_historial_documento (documento_tipo, documento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historial de cambios de estado';

-- =============================================================================
-- DATOS MINIMOS
-- =============================================================================
INSERT INTO roles (nombre, descripcion) VALUES
    ('administrador', 'Acceso total: administra usuarios, catalogo, clientes y documentos.'),
    ('asesor',        'Crea y gestiona clientes, cotizaciones y pedidos. No administra usuarios.'),
    ('consulta',      'Solo lectura: puede consultar catalogo, clientes y documentos.')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- =============================================================================
-- CONSULTAS DE VERIFICACION
-- =============================================================================
-- Usuarios y su rol:
--   SELECT u.nombre_usuario, u.correo, r.nombre AS rol, u.estado
--     FROM usuarios u JOIN roles r ON r.id = u.rol_id;
--
-- Cotizaciones con su cliente y su total:
--   SELECT c.numero, cl.nombre AS cliente, c.estado, c.total
--     FROM cotizaciones c JOIN clientes cl ON cl.id = c.cliente_id
--    ORDER BY c.id DESC;
--
-- Kardex de un producto:
--   SELECT m.fecha, m.tipo, m.cantidad, m.stock_anterior, m.stock_nuevo, m.motivo
--     FROM movimientos_inventario m
--    WHERE m.producto_id = 1 ORDER BY m.id;
