<?php
/**
 * =============================================================================
 * src/Repositorios/RepositorioBase.php
 * -----------------------------------------------------------------------------
 * Base comun de todos los repositorios.
 *
 * Concentra lo que de otro modo se repetiria en cada uno: la conexion, el
 * armado de listados paginados y la obtencion de consecutivos.
 *
 * SEGURIDAD: todas las consultas del sistema usan marcadores de posicion
 * (:parametro) y execute(). Ningun valor recibido del cliente se concatena
 * dentro de una sentencia SQL. Asi se previene la inyeccion SQL.
 *
 * @package SistemaShowroomApi
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Repositorios;

use App\BaseDatos\Conexion;
use PDO;

abstract class RepositorioBase
{
    /** @var PDO Conexion activa. */
    protected PDO $pdo;

    public function __construct()
    {
        $this->pdo = Conexion::obtener();
    }

    /**
     * Ejecuta un listado paginado y devuelve los datos junto con la
     * informacion de paginacion.
     *
     * @param string              $sqlBase    SELECT sin LIMIT ni ORDER BY.
     * @param string              $sqlConteo  SELECT COUNT(*) equivalente.
     * @param array<string,mixed> $parametros Parametros de los filtros.
     * @param string              $orden      Clausula ORDER BY, sin el prefijo.
     * @param int                 $pagina     Pagina solicitada, desde 1.
     * @param int                 $porPagina  Registros por pagina.
     * @return array{datos: array<int,array<string,mixed>>, paginacion: array<string,int>}
     */
    protected function listarPaginado(
        string $sqlBase,
        string $sqlConteo,
        array $parametros,
        string $orden,
        int $pagina,
        int $porPagina
    ): array {
        // 1. Total de registros que cumplen el filtro.
        $conteo = $this->pdo->prepare($sqlConteo);
        $conteo->execute($parametros);
        $total = (int) $conteo->fetchColumn();

        // 2. Se calculan los limites de la pagina solicitada.
        $pagina    = max(1, $pagina);
        $porPagina = max(1, $porPagina);
        $desde     = ($pagina - 1) * $porPagina;

        // 3. Consulta de la pagina. El orden y los limites son valores
        //    controlados por el servidor, nunca texto recibido del cliente.
        $sentencia = $this->pdo->prepare("$sqlBase ORDER BY $orden LIMIT :limite OFFSET :desde");

        foreach ($parametros as $clave => $valor) {
            $sentencia->bindValue($clave, $valor);
        }

        $sentencia->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $sentencia->bindValue(':desde', $desde, PDO::PARAM_INT);
        $sentencia->execute();

        return [
            'datos'      => $sentencia->fetchAll(),
            'paginacion' => [
                'pagina'         => $pagina,
                'porPagina'      => $porPagina,
                'total'          => $total,
                'totalPaginas'   => $porPagina > 0 ? (int) ceil($total / $porPagina) : 0,
            ],
        ];
    }

    /**
     * Genera el siguiente numero consecutivo de un documento.
     *
     * Ejemplo: si el ultimo es COT-000007, devuelve COT-000008.
     *
     * NOTA TECNICA: en un sistema con alta concurrencia esto deberia
     * resolverse con una secuencia del motor o un bloqueo, porque dos
     * peticiones simultaneas podrian obtener el mismo numero. Para el alcance
     * de este proyecto la restriccion UNIQUE de la columna evita el duplicado.
     *
     * @param string $tabla   Tabla del documento.
     * @param string $prefijo Prefijo del consecutivo (COT, PED).
     * @return string
     */
    protected function siguienteNumero(string $tabla, string $prefijo): string
    {
        // El nombre de la tabla lo fija el codigo, no el cliente.
        $sentencia = $this->pdo->query("SELECT COUNT(*) FROM $tabla");
        $siguiente = ((int) $sentencia->fetchColumn()) + 1;

        return sprintf('%s-%06d', $prefijo, $siguiente);
    }

    /**
     * Registra un cambio de estado en la bitacora de trazabilidad.
     *
     * @param string      $tipo           cotizacion | pedido
     * @param int         $documentoId
     * @param string|null $estadoAnterior
     * @param string      $estadoNuevo
     * @param int|null    $usuarioId
     * @param string|null $observacion
     * @return void
     */
    public function registrarCambioDeEstado(
        string $tipo,
        int $documentoId,
        ?string $estadoAnterior,
        string $estadoNuevo,
        ?int $usuarioId = null,
        ?string $observacion = null
    ): void {
        $sentencia = $this->pdo->prepare(
            'INSERT INTO historial_estados
                (documento_tipo, documento_id, estado_anterior, estado_nuevo,
                 observacion, usuario_id, fecha)
             VALUES
                (:tipo, :documento_id, :estado_anterior, :estado_nuevo,
                 :observacion, :usuario_id, :fecha)'
        );

        $sentencia->execute([
            ':tipo'            => $tipo,
            ':documento_id'    => $documentoId,
            ':estado_anterior' => $estadoAnterior,
            ':estado_nuevo'    => $estadoNuevo,
            ':observacion'     => $observacion,
            ':usuario_id'      => $usuarioId,
            ':fecha'           => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Devuelve el historial de estados de un documento.
     *
     * @param string $tipo
     * @param int    $documentoId
     * @return array<int, array<string,mixed>>
     */
    public function historialDeEstados(string $tipo, int $documentoId): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT h.estado_anterior, h.estado_nuevo, h.observacion, h.fecha,
                    u.nombre_usuario AS usuario
               FROM historial_estados h
          LEFT JOIN usuarios u ON u.id = h.usuario_id
              WHERE h.documento_tipo = :tipo AND h.documento_id = :id
           ORDER BY h.id ASC'
        );

        $sentencia->execute([':tipo' => $tipo, ':id' => $documentoId]);

        return $sentencia->fetchAll();
    }

    /**
     * Inicia una transaccion.
     * Se usa en las operaciones que tocan varias tablas a la vez, como crear
     * una cotizacion con sus lineas.
     *
     * @return void
     */
    public function iniciarTransaccion(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    /** @return void Confirma la transaccion en curso. */
    public function confirmarTransaccion(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    /** @return void Revierte la transaccion en curso. */
    public function revertirTransaccion(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
