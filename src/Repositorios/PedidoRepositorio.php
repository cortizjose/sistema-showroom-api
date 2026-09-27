<?php
/**
 * =============================================================================
 * src/Repositorios/PedidoRepositorio.php
 * -----------------------------------------------------------------------------
 * Acceso a datos de los pedidos y su detalle.
 *
 * Igual que las cotizaciones, un pedido vive en dos tablas y se crea dentro
 * de una transaccion.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Repositorios;

use App\Modelos\Pedido;

class PedidoRepositorio extends RepositorioBase
{
    /** Consulta base con cliente, asesor y cotizacion de origen resueltos. */
    private const SELECT_BASE = '
        SELECT p.*, cl.nombre AS cliente_nombre, u.nombre_usuario AS usuario_nombre,
               co.numero AS cotizacion_numero
          FROM pedidos p
          JOIN clientes cl ON cl.id = p.cliente_id
          JOIN usuarios u  ON u.id  = p.usuario_id
     LEFT JOIN cotizaciones co ON co.id = p.cotizacion_id';

    /**
     * Crea un pedido con sus lineas.
     *
     * @param array<string,mixed>             $cabecera
     * @param array<int, array<string,mixed>> $lineas
     * @return Pedido
     */
    public function crear(array $cabecera, array $lineas): Pedido
    {
        $this->iniciarTransaccion();

        try {
            $numero = $this->siguienteNumero('pedidos', $cabecera['prefijo']);

            $sentencia = $this->pdo->prepare(
                'INSERT INTO pedidos
                    (numero, cotizacion_id, cliente_id, usuario_id, fecha_pedido,
                     fecha_entrega_estimada, estado, subtotal, valor_descuento,
                     valor_iva, total, direccion_entrega, observaciones)
                 VALUES
                    (:numero, :cotizacion_id, :cliente_id, :usuario_id, :fecha_pedido,
                     :entrega, :estado, :subtotal, :descuento,
                     :iva, :total, :direccion, :observaciones)'
            );

            $sentencia->execute([
                ':numero'        => $numero,
                ':cotizacion_id' => $cabecera['cotizacion_id'] ?? null,
                ':cliente_id'    => $cabecera['cliente_id'],
                ':usuario_id'    => $cabecera['usuario_id'],
                ':fecha_pedido'  => $cabecera['fecha_pedido'],
                ':entrega'       => $cabecera['fecha_entrega_estimada'] ?? null,
                ':estado'        => 'pendiente',
                ':subtotal'      => $cabecera['subtotal'],
                ':descuento'     => $cabecera['valor_descuento'],
                ':iva'           => $cabecera['valor_iva'],
                ':total'         => $cabecera['total'],
                ':direccion'     => $cabecera['direccion_entrega'] ?? null,
                ':observaciones' => $cabecera['observaciones'] ?? null,
            ]);

            $pedidoId = (int) $this->pdo->lastInsertId();

            $this->insertarLineas($pedidoId, $lineas);

            $this->registrarCambioDeEstado(
                'pedido',
                $pedidoId,
                null,
                'pendiente',
                (int) $cabecera['usuario_id'],
                $cabecera['cotizacion_id'] ?? null
                    ? 'Pedido generado desde cotizacion'
                    : 'Pedido creado directamente'
            );

            $this->confirmarTransaccion();

            return $this->buscarPorId($pedidoId);

        } catch (\Throwable $e) {
            $this->revertirTransaccion();
            throw $e;
        }
    }

    /**
     * Inserta las lineas de detalle de un pedido.
     *
     * @param int                             $pedidoId
     * @param array<int, array<string,mixed>> $lineas
     * @return void
     */
    private function insertarLineas(int $pedidoId, array $lineas): void
    {
        $sentencia = $this->pdo->prepare(
            'INSERT INTO pedido_lineas
                (pedido_id, producto_id, descripcion, cantidad,
                 precio_unitario, descuento_porcentaje, subtotal)
             VALUES
                (:pedido_id, :producto_id, :descripcion, :cantidad,
                 :precio, :descuento, :subtotal)'
        );

        foreach ($lineas as $linea) {
            $sentencia->execute([
                ':pedido_id'   => $pedidoId,
                ':producto_id' => $linea['producto_id'],
                ':descripcion' => $linea['descripcion'],
                ':cantidad'    => $linea['cantidad'],
                ':precio'      => $linea['precio_unitario'],
                ':descuento'   => $linea['descuento_porcentaje'],
                ':subtotal'    => $linea['subtotal'],
            ]);
        }
    }

    /**
     * Busca un pedido con todo su detalle.
     *
     * @param int $id
     * @return Pedido|null
     */
    public function buscarPorId(int $id): ?Pedido
    {
        $sentencia = $this->pdo->prepare(self::SELECT_BASE . ' WHERE p.id = :id LIMIT 1');
        $sentencia->execute([':id' => $id]);
        $fila = $sentencia->fetch();

        if ($fila === false) {
            return null;
        }

        $pedido = Pedido::desdeFila($fila);
        $pedido->lineas = $this->lineasDe($id);

        return $pedido;
    }

    /**
     * Devuelve las lineas de un pedido.
     *
     * @param int $pedidoId
     * @return array<int, array<string,mixed>>
     */
    public function lineasDe(int $pedidoId): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT l.id, l.producto_id, p.codigo AS producto_codigo, l.descripcion,
                    l.cantidad, l.precio_unitario, l.descuento_porcentaje, l.subtotal
               FROM pedido_lineas l
          LEFT JOIN productos p ON p.id = l.producto_id
              WHERE l.pedido_id = :id
           ORDER BY l.id'
        );

        $sentencia->execute([':id' => $pedidoId]);

        return array_map(static fn (array $l): array => [
            'id'             => (int) $l['id'],
            'producto'       => [
                'id'     => (int) $l['producto_id'],
                'codigo' => $l['producto_codigo'],
            ],
            'descripcion'    => $l['descripcion'],
            'cantidad'       => (int) $l['cantidad'],
            'precioUnitario' => round((float) $l['precio_unitario'], 2),
            'descuento'      => round((float) $l['descuento_porcentaje'], 2),
            'subtotal'       => round((float) $l['subtotal'], 2),
        ], $sentencia->fetchAll());
    }

    /**
     * Cambia el estado de un pedido y lo registra en la bitacora.
     *
     * @param int         $id
     * @param string      $estadoAnterior
     * @param string      $estadoNuevo
     * @param int|null    $usuarioId
     * @param string|null $observacion
     * @return void
     */
    public function cambiarEstado(
        int $id,
        string $estadoAnterior,
        string $estadoNuevo,
        ?int $usuarioId = null,
        ?string $observacion = null
    ): void {
        $sentencia = $this->pdo->prepare(
            'UPDATE pedidos SET estado = :estado, fecha_actualizacion = :fecha WHERE id = :id'
        );
        $sentencia->execute([
            ':estado' => $estadoNuevo,
            ':fecha'  => date('Y-m-d H:i:s'),
            ':id'     => $id,
        ]);

        $this->registrarCambioDeEstado('pedido', $id, $estadoAnterior, $estadoNuevo, $usuarioId, $observacion);
    }

    /**
     * Listado paginado con filtros.
     *
     * @param array<string,mixed> $filtros cliente_id, estado, desde, hasta
     * @param int                 $pagina
     * @param int                 $porPagina
     * @return array{datos: array<int,array<string,mixed>>, paginacion: array<string,int>}
     */
    public function listar(array $filtros, int $pagina, int $porPagina): array
    {
        $condiciones = [];
        $parametros  = [];

        if (!empty($filtros['cliente_id'])) {
            $condiciones[] = 'p.cliente_id = :cliente_id';
            $parametros[':cliente_id'] = (int) $filtros['cliente_id'];
        }

        if (!empty($filtros['estado'])) {
            $condiciones[] = 'p.estado = :estado';
            $parametros[':estado'] = $filtros['estado'];
        }

        if (!empty($filtros['desde'])) {
            $condiciones[] = 'p.fecha_pedido >= :desde';
            $parametros[':desde'] = $filtros['desde'] . ' 00:00:00';
        }

        if (!empty($filtros['hasta'])) {
            $condiciones[] = 'p.fecha_pedido <= :hasta';
            $parametros[':hasta'] = $filtros['hasta'] . ' 23:59:59';
        }

        $where = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);

        $conteo = 'SELECT COUNT(*) FROM pedidos p
                     JOIN clientes cl ON cl.id = p.cliente_id
                     JOIN usuarios u  ON u.id  = p.usuario_id
                LEFT JOIN cotizaciones co ON co.id = p.cotizacion_id' . $where;

        return $this->listarPaginado(
            self::SELECT_BASE . $where,
            $conteo,
            $parametros,
            'p.id DESC',
            $pagina,
            $porPagina
        );
    }

    /**
     * Indica si una cotizacion ya genero un pedido.
     * Evita convertir dos veces la misma cotizacion.
     *
     * @param int $cotizacionId
     * @return bool
     */
    public function existePedidoDeCotizacion(int $cotizacionId): bool
    {
        $sentencia = $this->pdo->prepare(
            'SELECT COUNT(*) FROM pedidos WHERE cotizacion_id = :id'
        );
        $sentencia->execute([':id' => $cotizacionId]);

        return (int) $sentencia->fetchColumn() > 0;
    }

    /** @return int Total de pedidos registrados. */
    public function contar(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM pedidos')->fetchColumn();
    }

    // =========================================================================
    // REPORTES
    // =========================================================================

    /**
     * Resumen de ventas en un rango de fechas.
     *
     * Solo cuenta los pedidos que no fueron anulados.
     *
     * @param string $desde Fecha en formato Y-m-d
     * @param string $hasta Fecha en formato Y-m-d
     * @return array<string,mixed>
     */
    public function resumenDeVentas(string $desde, string $hasta): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT COUNT(*)                AS total_pedidos,
                    COALESCE(SUM(subtotal), 0)        AS subtotal,
                    COALESCE(SUM(valor_descuento), 0) AS descuentos,
                    COALESCE(SUM(valor_iva), 0)       AS iva,
                    COALESCE(SUM(total), 0)           AS total
               FROM pedidos
              WHERE estado <> :anulado
                AND fecha_pedido BETWEEN :desde AND :hasta'
        );

        $sentencia->execute([
            ':anulado' => 'anulado',
            ':desde'   => $desde . ' 00:00:00',
            ':hasta'   => $hasta . ' 23:59:59',
        ]);

        $fila = $sentencia->fetch() ?: [];

        return [
            'totalPedidos' => (int) ($fila['total_pedidos'] ?? 0),
            'subtotal'     => round((float) ($fila['subtotal'] ?? 0), 2),
            'descuentos'   => round((float) ($fila['descuentos'] ?? 0), 2),
            'iva'          => round((float) ($fila['iva'] ?? 0), 2),
            'total'        => round((float) ($fila['total'] ?? 0), 2),
        ];
    }

    /**
     * Ventas agrupadas por estado, para ver el embudo comercial.
     *
     * @param string $desde
     * @param string $hasta
     * @return array<int, array<string,mixed>>
     */
    public function ventasPorEstado(string $desde, string $hasta): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT estado, COUNT(*) AS cantidad, COALESCE(SUM(total), 0) AS valor
               FROM pedidos
              WHERE fecha_pedido BETWEEN :desde AND :hasta
           GROUP BY estado
           ORDER BY cantidad DESC'
        );

        $sentencia->execute([
            ':desde' => $desde . ' 00:00:00',
            ':hasta' => $hasta . ' 23:59:59',
        ]);

        return array_map(static fn (array $f): array => [
            'estado'   => $f['estado'],
            'cantidad' => (int) $f['cantidad'],
            'valor'    => round((float) $f['valor'], 2),
        ], $sentencia->fetchAll());
    }

    /**
     * Productos mas solicitados en cotizaciones.
     *
     * @param int $limite
     * @return array<int, array<string,mixed>>
     */
    public function productosMasCotizados(int $limite = 10): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT p.id, p.codigo, p.nombre,
                    SUM(l.cantidad) AS unidades,
                    COUNT(DISTINCT l.cotizacion_id) AS cotizaciones
               FROM cotizacion_lineas l
               JOIN productos p ON p.id = l.producto_id
           GROUP BY p.id, p.codigo, p.nombre
           ORDER BY unidades DESC
              LIMIT :limite'
        );

        $sentencia->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $sentencia->execute();

        return array_map(static fn (array $f): array => [
            'id'           => (int) $f['id'],
            'codigo'       => $f['codigo'],
            'nombre'       => $f['nombre'],
            'unidades'     => (int) $f['unidades'],
            'cotizaciones' => (int) $f['cotizaciones'],
        ], $sentencia->fetchAll());
    }
}
