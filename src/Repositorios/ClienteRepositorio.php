<?php
/**
 * =============================================================================
 * src/Repositorios/ClienteRepositorio.php
 * -----------------------------------------------------------------------------
 * Acceso a datos de los clientes del showroom.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Repositorios;

use App\Modelos\Cliente;

class ClienteRepositorio extends RepositorioBase
{
    /**
     * Crea un cliente.
     *
     * @param array<string,mixed> $datos Datos ya validados y normalizados.
     * @return Cliente
     */
    public function crear(array $datos): Cliente
    {
        $sentencia = $this->pdo->prepare(
            'INSERT INTO clientes
                (tipo_documento, numero_documento, nombre, correo, telefono,
                 direccion, ciudad, estado, fecha_registro)
             VALUES
                (:tipo_documento, :numero_documento, :nombre, :correo, :telefono,
                 :direccion, :ciudad, :estado, :fecha)'
        );

        $sentencia->execute([
            ':tipo_documento'   => $datos['tipo_documento'],
            ':numero_documento' => $datos['numero_documento'],
            ':nombre'           => $datos['nombre'],
            ':correo'           => $datos['correo'] ?? null,
            ':telefono'         => $datos['telefono'] ?? null,
            ':direccion'        => $datos['direccion'] ?? null,
            ':ciudad'           => $datos['ciudad'] ?? null,
            ':estado'           => 'activo',
            ':fecha'            => date('Y-m-d H:i:s'),
        ]);

        return $this->buscarPorId((int) $this->pdo->lastInsertId());
    }

    /**
     * Actualiza los datos de un cliente.
     *
     * @param int                 $id
     * @param array<string,mixed> $datos
     * @return void
     */
    public function actualizar(int $id, array $datos): void
    {
        $sentencia = $this->pdo->prepare(
            'UPDATE clientes
                SET tipo_documento   = :tipo_documento,
                    numero_documento = :numero_documento,
                    nombre           = :nombre,
                    correo           = :correo,
                    telefono         = :telefono,
                    direccion        = :direccion,
                    ciudad           = :ciudad
              WHERE id = :id'
        );

        $sentencia->execute([
            ':tipo_documento'   => $datos['tipo_documento'],
            ':numero_documento' => $datos['numero_documento'],
            ':nombre'           => $datos['nombre'],
            ':correo'           => $datos['correo'] ?? null,
            ':telefono'         => $datos['telefono'] ?? null,
            ':direccion'        => $datos['direccion'] ?? null,
            ':ciudad'           => $datos['ciudad'] ?? null,
            ':id'               => $id,
        ]);
    }

    /**
     * Cambia el estado de un cliente.
     *
     * No se elimina fisicamente: un cliente inactivo debe seguir apareciendo
     * en las cotizaciones y pedidos historicos.
     *
     * @param int    $id
     * @param string $estado activo | inactivo
     * @return void
     */
    public function cambiarEstado(int $id, string $estado): void
    {
        $sentencia = $this->pdo->prepare('UPDATE clientes SET estado = :estado WHERE id = :id');
        $sentencia->execute([':estado' => $estado, ':id' => $id]);
    }

    /**
     * @param int $id
     * @return Cliente|null
     */
    public function buscarPorId(int $id): ?Cliente
    {
        $sentencia = $this->pdo->prepare('SELECT * FROM clientes WHERE id = :id LIMIT 1');
        $sentencia->execute([':id' => $id]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : Cliente::desdeFila($fila);
    }

    /**
     * @param string $numeroDocumento
     * @return Cliente|null
     */
    public function buscarPorDocumento(string $numeroDocumento): ?Cliente
    {
        $sentencia = $this->pdo->prepare(
            'SELECT * FROM clientes WHERE numero_documento = :documento LIMIT 1'
        );
        $sentencia->execute([':documento' => $numeroDocumento]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : Cliente::desdeFila($fila);
    }

    /**
     * Indica si ya existe un cliente con ese documento.
     *
     * @param string   $numeroDocumento
     * @param int|null $exceptoId
     * @return bool
     */
    public function existeDocumento(string $numeroDocumento, ?int $exceptoId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM clientes WHERE numero_documento = :documento';
        $parametros = [':documento' => $numeroDocumento];

        if ($exceptoId !== null) {
            $sql .= ' AND id <> :excepto';
            $parametros[':excepto'] = $exceptoId;
        }

        $sentencia = $this->pdo->prepare($sql);
        $sentencia->execute($parametros);

        return (int) $sentencia->fetchColumn() > 0;
    }

    /**
     * Listado paginado con filtros por texto, ciudad y estado.
     *
     * @param array<string,mixed> $filtros
     * @param int                 $pagina
     * @param int                 $porPagina
     * @return array{datos: array<int,array<string,mixed>>, paginacion: array<string,int>}
     */
    public function listar(array $filtros, int $pagina, int $porPagina): array
    {
        $condiciones = [];
        $parametros  = [];

        if (!empty($filtros['busqueda'])) {
            $condiciones[] = '(nombre LIKE :busqueda
                            OR numero_documento LIKE :busqueda
                            OR correo LIKE :busqueda)';
            $parametros[':busqueda'] = '%' . $filtros['busqueda'] . '%';
        }

        if (!empty($filtros['ciudad'])) {
            $condiciones[] = 'ciudad = :ciudad';
            $parametros[':ciudad'] = $filtros['ciudad'];
        }

        if (!empty($filtros['estado'])) {
            $condiciones[] = 'estado = :estado';
            $parametros[':estado'] = $filtros['estado'];
        }

        $where = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);

        return $this->listarPaginado(
            'SELECT * FROM clientes' . $where,
            'SELECT COUNT(*) FROM clientes' . $where,
            $parametros,
            'nombre ASC',
            $pagina,
            $porPagina
        );
    }

    /** @return int Total de clientes registrados. */
    public function contar(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();
    }

    /**
     * Indica si el cliente tiene documentos asociados.
     * Se consulta antes de permitir cambios delicados.
     *
     * @param int $id
     * @return bool
     */
    public function tieneDocumentos(int $id): bool
    {
        $sentencia = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM cotizaciones WHERE cliente_id = :id)
                  + (SELECT COUNT(*) FROM pedidos      WHERE cliente_id = :id)'
        );
        $sentencia->execute([':id' => $id]);

        return (int) $sentencia->fetchColumn() > 0;
    }
}
