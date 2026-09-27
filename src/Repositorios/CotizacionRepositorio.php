<?php
/**
 * =============================================================================
 * src/Repositorios/CotizacionRepositorio.php
 * -----------------------------------------------------------------------------
 * Acceso a datos de las cotizaciones y su detalle.
 *
 * Una cotizacion se guarda en dos tablas (cabecera y lineas), por lo que su
 * creacion se hace dentro de una TRANSACCION: o se guarda completa, o no se
 * guarda nada. Nunca debe quedar una cabecera sin lineas.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Repositorios;

use App\Modelos\Cotizacion;

class CotizacionRepositorio extends RepositorioBase
{
    /** Consulta base con los nombres de cliente y asesor ya resueltos. */
    private const SELECT_BASE = '
        SELECT c.*, cl.nombre AS cliente_nombre, u.nombre_usuario AS usuario_nombre
          FROM cotizaciones c
          JOIN clientes cl ON cl.id = c.cliente_id
          JOIN usuarios u  ON u.id  = c.usuario_id';

    /**
     * Crea una cotizacion con sus lineas.
     *
     * @param array<string,mixed>            $cabecera Datos ya calculados.
     * @param array<int, array<string,mixed>> $lineas  Lineas ya calculadas.
     * @return Cotizacion
     */
    public function crear(array $cabecera, array $lineas): Cotizacion
    {
        $this->iniciarTransaccion();

        try {
            $numero = $this->siguienteNumero('cotizaciones', $cabecera['prefijo']);

            $sentencia = $this->pdo->prepare(
                'INSERT INTO cotizaciones
                    (numero, cliente_id, usuario_id, fecha_emision, fecha_vencimiento,
                     estado, subtotal, valor_descuento, valor_iva, total, observaciones)
                 VALUES
                    (:numero, :cliente_id, :usuario_id, :emision, :vencimiento,
                     :estado, :subtotal, :descuento, :iva, :total, :observaciones)'
            );

            $sentencia->execute([
                ':numero'        => $numero,
                ':cliente_id'    => $cabecera['cliente_id'],
                ':usuario_id'    => $cabecera['usuario_id'],
                ':emision'       => $cabecera['fecha_emision'],
                ':vencimiento'   => $cabecera['fecha_vencimiento'],
                ':estado'        => 'borrador',
                ':subtotal'      => $cabecera['subtotal'],
                ':descuento'     => $cabecera['valor_descuento'],
                ':iva'           => $cabecera['valor_iva'],
                ':total'         => $cabecera['total'],
                ':observaciones' => $cabecera['observaciones'] ?? null,
            ]);

            $cotizacionId = (int) $this->pdo->lastInsertId();

            $this->insertarLineas($cotizacionId, $lineas);

            // Estado inicial en la bitacora de trazabilidad.
            $this->registrarCambioDeEstado(
                'cotizacion',
                $cotizacionId,
                null,
                'borrador',
                (int) $cabecera['usuario_id'],
                'Cotizacion creada'
            );

            $this->confirmarTransaccion();

            return $this->buscarPorId($cotizacionId);

        } catch (\Throwable $e) {
            // Si algo falla, no queda una cotizacion a medias.
            $this->revertirTransaccion();
            throw $e;
        }
    }

    /**
     * Inserta las lineas de detalle de una cotizacion.
     *
     * @param int                             $cotizacionId
     * @param array<int, array<string,mixed>> $lineas
     * @return void
     */
    private function insertarLineas(int $cotizacionId, array $lineas): void
    {
        $sentencia = $this->pdo->prepare(
            'INSERT INTO cotizacion_lineas
                (cotizacion_id, producto_id, descripcion, cantidad,
                 precio_unitario, descuento_porcentaje, subtotal)
             VALUES
                (:cotizacion_id, :producto_id, :descripcion, :cantidad,
                 :precio, :descuento, :subtotal)'
        );

        foreach ($lineas as $linea) {
            $sentencia->execute([
                ':cotizacion_id' => $cotizacionId,
                ':producto_id'   => $linea['producto_id'],
                ':descripcion'   => $linea['descripcion'],
                ':cantidad'      => $linea['cantidad'],
                ':precio'        => $linea['precio_unitario'],
                ':descuento'     => $linea['descuento_porcentaje'],
                ':subtotal'      => $linea['subtotal'],
            ]);
        }
    }

    /**
     * Busca una cotizacion con todo su detalle.
     *
     * @param int $id
     * @return Cotizacion|null
     */
    public function buscarPorId(int $id): ?Cotizacion
    {
        $sentencia = $this->pdo->prepare(self::SELECT_BASE . ' WHERE c.id = :id LIMIT 1');
        $sentencia->execute([':id' => $id]);
        $fila = $sentencia->fetch();

        if ($fila === false) {
            return null;
        }

        $cotizacion = Cotizacion::desdeFila($fila);
        $cotizacion->lineas = $this->lineasDe($id);

        return $cotizacion;
    }

    /**
     * Devuelve las lineas de una cotizacion.
     *
     * @param int $cotizacionId
     * @return array<int, array<string,mixed>>
     */
    public function lineasDe(int $cotizacionId): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT l.id, l.producto_id, p.codigo AS producto_codigo, l.descripcion,
                    l.cantidad, l.precio_unitario, l.descuento_porcentaje, l.subtotal
               FROM cotizacion_lineas l
          LEFT JOIN productos p ON p.id = l.producto_id
              WHERE l.cotizacion_id = :id
           ORDER BY l.id'
        );

        $sentencia->execute([':id' => $cotizacionId]);

        // Se normalizan los tipos para que el JSON salga con numeros, no textos.
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
     * Cambia el estado de una cotizacion y lo registra en la bitacora.
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
            'UPDATE cotizaciones SET estado = :estado, fecha_actualizacion = :fecha WHERE id = :id'
        );
        $sentencia->execute([
            ':estado' => $estadoNuevo,
            ':fecha'  => date('Y-m-d H:i:s'),
            ':id'     => $id,
        ]);

        $this->registrarCambioDeEstado(
            'cotizacion',
            $id,
            $estadoAnterior,
            $estadoNuevo,
            $usuarioId,
            $observacion
        );
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
            $condiciones[] = 'c.cliente_id = :cliente_id';
            $parametros[':cliente_id'] = (int) $filtros['cliente_id'];
        }

        if (!empty($filtros['estado'])) {
            $condiciones[] = 'c.estado = :estado';
            $parametros[':estado'] = $filtros['estado'];
        }

        if (!empty($filtros['desde'])) {
            $condiciones[] = 'c.fecha_emision >= :desde';
            $parametros[':desde'] = $filtros['desde'] . ' 00:00:00';
        }

        if (!empty($filtros['hasta'])) {
            $condiciones[] = 'c.fecha_emision <= :hasta';
            $parametros[':hasta'] = $filtros['hasta'] . ' 23:59:59';
        }

        $where = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);

        $conteo = 'SELECT COUNT(*) FROM cotizaciones c
                     JOIN clientes cl ON cl.id = c.cliente_id
                     JOIN usuarios u  ON u.id  = c.usuario_id' . $where;

        return $this->listarPaginado(
            self::SELECT_BASE . $where,
            $conteo,
            $parametros,
            'c.id DESC',
            $pagina,
            $porPagina
        );
    }

    /**
     * Marca como vencidas las cotizaciones enviadas cuya fecha ya paso.
     *
     * Se invoca al listar y al consultar, de modo que el estado que ve el
     * usuario siempre esta al dia sin necesidad de una tarea programada.
     *
     * @return int Cantidad de cotizaciones marcadas.
     */
    public function marcarVencidas(): int
    {
        $ahora = date('Y-m-d H:i:s');

        // 1. Se identifican antes de actualizar, para poder registrarlas.
        $consulta = $this->pdo->prepare(
            'SELECT id FROM cotizaciones WHERE estado = :estado AND fecha_vencimiento < :ahora'
        );
        $consulta->execute([':estado' => 'enviada', ':ahora' => $ahora]);
        $ids = $consulta->fetchAll(\PDO::FETCH_COLUMN);

        if ($ids === []) {
            return 0;
        }

        foreach ($ids as $id) {
            $this->cambiarEstado(
                (int) $id,
                'enviada',
                'vencida',
                null,
                'Vencimiento automatico por fecha'
            );
        }

        return count($ids);
    }

    /** @return int Total de cotizaciones emitidas. */
    public function contar(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM cotizaciones')->fetchColumn();
    }
}
