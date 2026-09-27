<?php
/**
 * =============================================================================
 * src/Repositorios/ProductoRepositorio.php
 * -----------------------------------------------------------------------------
 * Acceso a datos del catalogo: categorias, productos y movimientos de stock.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Repositorios;

use App\Modelos\Producto;

class ProductoRepositorio extends RepositorioBase
{
    /** Consulta base: trae el nombre de la categoria junto al producto. */
    private const SELECT_BASE = '
        SELECT p.*, c.nombre AS categoria_nombre
          FROM productos p
     LEFT JOIN categorias c ON c.id = p.categoria_id';

    // =========================================================================
    // PRODUCTOS
    // =========================================================================

    /**
     * Crea un producto.
     *
     * @param array<string,mixed> $datos
     * @return Producto
     */
    public function crear(array $datos): Producto
    {
        $sentencia = $this->pdo->prepare(
            'INSERT INTO productos
                (codigo, nombre, descripcion, categoria_id, precio_unitario,
                 stock, stock_minimo, estado, fecha_registro)
             VALUES
                (:codigo, :nombre, :descripcion, :categoria_id, :precio,
                 :stock, :stock_minimo, :estado, :fecha)'
        );

        $sentencia->execute([
            ':codigo'       => $datos['codigo'],
            ':nombre'       => $datos['nombre'],
            ':descripcion'  => $datos['descripcion'] ?? null,
            ':categoria_id' => $datos['categoria_id'] ?? null,
            ':precio'       => $datos['precio_unitario'],
            ':stock'        => $datos['stock'] ?? 0,
            ':stock_minimo' => $datos['stock_minimo'] ?? 0,
            ':estado'       => 'activo',
            ':fecha'        => date('Y-m-d H:i:s'),
        ]);

        return $this->buscarPorId((int) $this->pdo->lastInsertId());
    }

    /**
     * Actualiza los datos de un producto.
     *
     * El stock NO se modifica aqui: tiene su propia operacion, para que todo
     * cambio de existencias quede registrado en movimientos_inventario.
     *
     * @param int                 $id
     * @param array<string,mixed> $datos
     * @return void
     */
    public function actualizar(int $id, array $datos): void
    {
        $sentencia = $this->pdo->prepare(
            'UPDATE productos
                SET codigo              = :codigo,
                    nombre              = :nombre,
                    descripcion         = :descripcion,
                    categoria_id        = :categoria_id,
                    precio_unitario     = :precio,
                    stock_minimo        = :stock_minimo,
                    fecha_actualizacion = :fecha
              WHERE id = :id'
        );

        $sentencia->execute([
            ':codigo'       => $datos['codigo'],
            ':nombre'       => $datos['nombre'],
            ':descripcion'  => $datos['descripcion'] ?? null,
            ':categoria_id' => $datos['categoria_id'] ?? null,
            ':precio'       => $datos['precio_unitario'],
            ':stock_minimo' => $datos['stock_minimo'] ?? 0,
            ':fecha'        => date('Y-m-d H:i:s'),
            ':id'           => $id,
        ]);
    }

    /**
     * Da de baja o reactiva un producto.
     *
     * Es una baja logica: el producto sigue existiendo porque aparece en
     * cotizaciones y pedidos ya emitidos.
     *
     * @param int    $id
     * @param string $estado activo | descontinuado
     * @return void
     */
    public function cambiarEstado(int $id, string $estado): void
    {
        $sentencia = $this->pdo->prepare(
            'UPDATE productos SET estado = :estado, fecha_actualizacion = :fecha WHERE id = :id'
        );
        $sentencia->execute([':estado' => $estado, ':fecha' => date('Y-m-d H:i:s'), ':id' => $id]);
    }

    /**
     * @param int $id
     * @return Producto|null
     */
    public function buscarPorId(int $id): ?Producto
    {
        $sentencia = $this->pdo->prepare(self::SELECT_BASE . ' WHERE p.id = :id LIMIT 1');
        $sentencia->execute([':id' => $id]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : Producto::desdeFila($fila);
    }

    /**
     * @param string   $codigo
     * @param int|null $exceptoId
     * @return bool
     */
    public function existeCodigo(string $codigo, ?int $exceptoId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM productos WHERE codigo = :codigo';
        $parametros = [':codigo' => $codigo];

        if ($exceptoId !== null) {
            $sql .= ' AND id <> :excepto';
            $parametros[':excepto'] = $exceptoId;
        }

        $sentencia = $this->pdo->prepare($sql);
        $sentencia->execute($parametros);

        return (int) $sentencia->fetchColumn() > 0;
    }

    /**
     * Listado paginado del catalogo, con filtros.
     *
     * @param array<string,mixed> $filtros busqueda, categoria_id, estado, stock_bajo
     * @param int                 $pagina
     * @param int                 $porPagina
     * @return array{datos: array<int,array<string,mixed>>, paginacion: array<string,int>}
     */
    public function listar(array $filtros, int $pagina, int $porPagina): array
    {
        $condiciones = [];
        $parametros  = [];

        if (!empty($filtros['busqueda'])) {
            $condiciones[] = '(p.nombre LIKE :busqueda OR p.codigo LIKE :busqueda OR p.descripcion LIKE :busqueda)';
            $parametros[':busqueda'] = '%' . $filtros['busqueda'] . '%';
        }

        if (!empty($filtros['categoria_id'])) {
            $condiciones[] = 'p.categoria_id = :categoria_id';
            $parametros[':categoria_id'] = (int) $filtros['categoria_id'];
        }

        if (!empty($filtros['estado'])) {
            $condiciones[] = 'p.estado = :estado';
            $parametros[':estado'] = $filtros['estado'];
        }

        // Filtro util para reposicion: solo productos en o bajo el minimo.
        if (!empty($filtros['stock_bajo'])) {
            $condiciones[] = 'p.stock <= p.stock_minimo';
        }

        $where = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);

        return $this->listarPaginado(
            self::SELECT_BASE . $where,
            'SELECT COUNT(*) FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id' . $where,
            $parametros,
            'p.nombre ASC',
            $pagina,
            $porPagina
        );
    }

    /** @return int Total de productos del catalogo. */
    public function contar(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM productos')->fetchColumn();
    }

    // =========================================================================
    // INVENTARIO
    // =========================================================================

    /**
     * Ajusta el stock de un producto y deja constancia del movimiento.
     *
     * Toda variacion de existencias pasa por aqui: no hay ningun UPDATE suelto
     * sobre la columna stock. Asi el inventario siempre es explicable.
     *
     * @param int         $productoId
     * @param int         $cantidad   Positiva suma, negativa resta.
     * @param string      $tipo       entrada | salida | ajuste
     * @param string|null $motivo
     * @param string|null $referencia Numero de pedido u otro documento.
     * @param int|null    $usuarioId
     * @return int Stock resultante.
     */
    public function moverStock(
        int $productoId,
        int $cantidad,
        string $tipo,
        ?string $motivo = null,
        ?string $referencia = null,
        ?int $usuarioId = null
    ): int {
        // 1. Stock actual.
        $consulta = $this->pdo->prepare('SELECT stock FROM productos WHERE id = :id');
        $consulta->execute([':id' => $productoId]);
        $anterior = (int) $consulta->fetchColumn();

        $nuevo = $anterior + $cantidad;

        // 2. Nuevo stock. Nunca se permite dejarlo en negativo.
        $actualizar = $this->pdo->prepare(
            'UPDATE productos SET stock = :stock, fecha_actualizacion = :fecha WHERE id = :id'
        );
        $actualizar->execute([
            ':stock' => max(0, $nuevo),
            ':fecha' => date('Y-m-d H:i:s'),
            ':id'    => $productoId,
        ]);

        // 3. Registro del movimiento.
        $movimiento = $this->pdo->prepare(
            'INSERT INTO movimientos_inventario
                (producto_id, tipo, cantidad, stock_anterior, stock_nuevo,
                 motivo, referencia, usuario_id, fecha)
             VALUES
                (:producto_id, :tipo, :cantidad, :anterior, :nuevo,
                 :motivo, :referencia, :usuario_id, :fecha)'
        );

        $movimiento->execute([
            ':producto_id' => $productoId,
            ':tipo'        => $tipo,
            ':cantidad'    => $cantidad,
            ':anterior'    => $anterior,
            ':nuevo'       => max(0, $nuevo),
            ':motivo'      => $motivo,
            ':referencia'  => $referencia,
            ':usuario_id'  => $usuarioId,
            ':fecha'       => date('Y-m-d H:i:s'),
        ]);

        return max(0, $nuevo);
    }

    /**
     * Kardex: movimientos de un producto, del mas reciente al mas antiguo.
     *
     * @param int $productoId
     * @param int $limite
     * @return array<int, array<string,mixed>>
     */
    public function movimientos(int $productoId, int $limite = 50): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT m.tipo, m.cantidad, m.stock_anterior, m.stock_nuevo,
                    m.motivo, m.referencia, m.fecha, u.nombre_usuario AS usuario
               FROM movimientos_inventario m
          LEFT JOIN usuarios u ON u.id = m.usuario_id
              WHERE m.producto_id = :id
           ORDER BY m.id DESC
              LIMIT :limite'
        );

        $sentencia->bindValue(':id', $productoId, \PDO::PARAM_INT);
        $sentencia->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $sentencia->execute();

        return $sentencia->fetchAll();
    }

    /**
     * Productos que alcanzaron su nivel minimo.
     *
     * @return array<int, array<string,mixed>>
     */
    public function conStockBajo(): array
    {
        return $this->pdo->query(
            'SELECT id, codigo, nombre, stock, stock_minimo
               FROM productos
              WHERE estado = \'activo\' AND stock <= stock_minimo
           ORDER BY stock ASC'
        )->fetchAll();
    }

    // =========================================================================
    // CATEGORIAS
    // =========================================================================

    /**
     * @return array<int, array<string,mixed>>
     */
    public function listarCategorias(): array
    {
        return $this->pdo->query(
            'SELECT c.id, c.nombre, c.descripcion, c.activa,
                    (SELECT COUNT(*) FROM productos p WHERE p.categoria_id = c.id) AS total_productos
               FROM categorias c
           ORDER BY c.nombre'
        )->fetchAll();
    }

    /**
     * @param string      $nombre
     * @param string|null $descripcion
     * @return int Identificador de la categoria creada.
     */
    public function crearCategoria(string $nombre, ?string $descripcion): int
    {
        $sentencia = $this->pdo->prepare(
            'INSERT INTO categorias (nombre, descripcion, activa) VALUES (:nombre, :descripcion, 1)'
        );
        $sentencia->execute([':nombre' => $nombre, ':descripcion' => $descripcion]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param string $nombre
     * @return bool
     */
    public function existeCategoria(string $nombre): bool
    {
        $sentencia = $this->pdo->prepare('SELECT COUNT(*) FROM categorias WHERE nombre = :nombre');
        $sentencia->execute([':nombre' => $nombre]);

        return (int) $sentencia->fetchColumn() > 0;
    }

    /**
     * @param int $id
     * @return bool
     */
    public function existeCategoriaPorId(int $id): bool
    {
        $sentencia = $this->pdo->prepare('SELECT COUNT(*) FROM categorias WHERE id = :id');
        $sentencia->execute([':id' => $id]);

        return (int) $sentencia->fetchColumn() > 0;
    }
}
