<?php
/**
 * =============================================================================
 * src/Repositorios/UsuarioRepositorio.php
 * -----------------------------------------------------------------------------
 * Acceso a datos de usuarios, roles y auditoria de acceso.
 *
 * Reutiliza y amplia el repositorio de la evidencia GA7-220501096-AA5-EV01:
 * lo de autenticacion se conserva y se agrega la administracion de usuarios
 * y su relacion con los roles.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Repositorios;

use App\Modelos\Usuario;
use PDO;

class UsuarioRepositorio extends RepositorioBase
{
    /**
     * Consulta base: siempre se trae el nombre del rol junto al usuario, para
     * no tener que hacer una segunda consulta en cada respuesta.
     */
    private const SELECT_BASE = '
        SELECT u.*, r.nombre AS rol_nombre
          FROM usuarios u
          JOIN roles r ON r.id = u.rol_id';

    // =========================================================================
    // AUTENTICACION
    // =========================================================================

    /**
     * Busca un usuario por su nombre de usuario o por su correo.
     *
     * @param string $identificador
     * @return Usuario|null
     */
    public function buscarPorIdentificador(string $identificador): ?Usuario
    {
        $sentencia = $this->pdo->prepare(
            self::SELECT_BASE . '
             WHERE u.nombre_usuario = :identificador
                OR u.correo         = :identificador
             LIMIT 1'
        );

        $sentencia->execute([':identificador' => $identificador]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : Usuario::desdeFila($fila);
    }

    /**
     * Busca un usuario por su identificador numerico.
     *
     * @param int $id
     * @return Usuario|null
     */
    public function buscarPorId(int $id): ?Usuario
    {
        $sentencia = $this->pdo->prepare(self::SELECT_BASE . ' WHERE u.id = :id LIMIT 1');
        $sentencia->execute([':id' => $id]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : Usuario::desdeFila($fila);
    }

    /**
     * Reinicia el contador de fallos y actualiza la fecha de ultimo acceso.
     *
     * @param int $idUsuario
     * @return void
     */
    public function registrarAccesoExitoso(int $idUsuario): void
    {
        $sentencia = $this->pdo->prepare(
            'UPDATE usuarios
                SET intentos_fallidos = 0,
                    bloqueado_hasta   = NULL,
                    ultimo_acceso     = :fecha
              WHERE id = :id'
        );

        $sentencia->execute([':fecha' => date('Y-m-d H:i:s'), ':id' => $idUsuario]);
    }

    /**
     * Incrementa los intentos fallidos y bloquea la cuenta al llegar al limite.
     *
     * @param int $idUsuario
     * @param int $maximoIntentos
     * @param int $minutosBloqueo
     * @return int Intentos acumulados tras la operacion.
     */
    public function registrarIntentoFallido(int $idUsuario, int $maximoIntentos, int $minutosBloqueo): int
    {
        $incremento = $this->pdo->prepare(
            'UPDATE usuarios SET intentos_fallidos = intentos_fallidos + 1 WHERE id = :id'
        );
        $incremento->execute([':id' => $idUsuario]);

        $consulta = $this->pdo->prepare('SELECT intentos_fallidos FROM usuarios WHERE id = :id');
        $consulta->execute([':id' => $idUsuario]);
        $intentos = (int) $consulta->fetchColumn();

        if ($intentos >= $maximoIntentos) {
            $bloqueo = $this->pdo->prepare('UPDATE usuarios SET bloqueado_hasta = :hasta WHERE id = :id');
            $bloqueo->execute([
                ':hasta' => date('Y-m-d H:i:s', time() + ($minutosBloqueo * 60)),
                ':id'    => $idUsuario,
            ]);
        }

        return $intentos;
    }

    /**
     * Guarda un intento de inicio de sesion en la bitacora de auditoria.
     *
     * @param string      $identificador
     * @param bool        $exitoso
     * @param string      $direccionIp
     * @param string|null $motivo
     * @return void
     */
    public function registrarIntentoEnBitacora(
        string $identificador,
        bool $exitoso,
        string $direccionIp,
        ?string $motivo = null
    ): void {
        $sentencia = $this->pdo->prepare(
            'INSERT INTO auditoria_acceso
                (identificador, exitoso, direccion_ip, motivo, fecha_intento)
             VALUES
                (:identificador, :exitoso, :ip, :motivo, :fecha)'
        );

        $sentencia->execute([
            ':identificador' => $identificador,
            ':exitoso'       => $exitoso ? 1 : 0,
            ':ip'            => $direccionIp,
            ':motivo'        => $motivo,
            ':fecha'         => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Cambia la contrasena de un usuario.
     *
     * @param int    $idUsuario
     * @param string $nuevoHash
     * @return void
     */
    public function actualizarClave(int $idUsuario, string $nuevoHash): void
    {
        $sentencia = $this->pdo->prepare('UPDATE usuarios SET clave_hash = :hash WHERE id = :id');
        $sentencia->execute([':hash' => $nuevoHash, ':id' => $idUsuario]);
    }

    // =========================================================================
    // ADMINISTRACION DE USUARIOS
    // =========================================================================

    /**
     * Crea un usuario con el rol indicado.
     *
     * @param string      $nombreUsuario
     * @param string      $correo
     * @param string      $claveHash
     * @param int         $rolId
     * @param string|null $nombreCompleto
     * @return Usuario
     */
    public function crear(
        string $nombreUsuario,
        string $correo,
        string $claveHash,
        int $rolId,
        ?string $nombreCompleto = null
    ): Usuario {
        $sentencia = $this->pdo->prepare(
            'INSERT INTO usuarios
                (nombre_usuario, correo, clave_hash, nombre_completo, rol_id,
                 estado, intentos_fallidos, fecha_registro)
             VALUES
                (:nombre_usuario, :correo, :clave_hash, :nombre_completo, :rol_id,
                 :estado, 0, :fecha)'
        );

        $sentencia->execute([
            ':nombre_usuario'  => $nombreUsuario,
            ':correo'          => $correo,
            ':clave_hash'      => $claveHash,
            ':nombre_completo' => $nombreCompleto,
            ':rol_id'          => $rolId,
            ':estado'          => 'activo',
            ':fecha'           => date('Y-m-d H:i:s'),
        ]);

        return $this->buscarPorId((int) $this->pdo->lastInsertId());
    }

    /**
     * Actualiza los datos editables de un usuario.
     *
     * @param int         $id
     * @param string      $correo
     * @param string|null $nombreCompleto
     * @param int         $rolId
     * @return void
     */
    public function actualizar(int $id, string $correo, ?string $nombreCompleto, int $rolId): void
    {
        $sentencia = $this->pdo->prepare(
            'UPDATE usuarios
                SET correo          = :correo,
                    nombre_completo = :nombre_completo,
                    rol_id          = :rol_id
              WHERE id = :id'
        );

        $sentencia->execute([
            ':correo'          => $correo,
            ':nombre_completo' => $nombreCompleto,
            ':rol_id'          => $rolId,
            ':id'              => $id,
        ]);
    }

    /**
     * Activa o desactiva un usuario.
     *
     * @param int    $id
     * @param string $estado activo | inactivo
     * @return void
     */
    public function cambiarEstado(int $id, string $estado): void
    {
        $sentencia = $this->pdo->prepare('UPDATE usuarios SET estado = :estado WHERE id = :id');
        $sentencia->execute([':estado' => $estado, ':id' => $id]);
    }

    /**
     * Listado paginado de usuarios, con filtro opcional por texto y por rol.
     *
     * @param string|null $busqueda  Texto libre sobre usuario, correo o nombre.
     * @param string|null $rol       Nombre del rol.
     * @param int         $pagina
     * @param int         $porPagina
     * @return array{datos: array<int,array<string,mixed>>, paginacion: array<string,int>}
     */
    public function listar(?string $busqueda, ?string $rol, int $pagina, int $porPagina): array
    {
        $condiciones = [];
        $parametros  = [];

        if ($busqueda !== null && $busqueda !== '') {
            $condiciones[] = '(u.nombre_usuario LIKE :busqueda
                            OR u.correo LIKE :busqueda
                            OR u.nombre_completo LIKE :busqueda)';
            $parametros[':busqueda'] = '%' . $busqueda . '%';
        }

        if ($rol !== null && $rol !== '') {
            $condiciones[] = 'r.nombre = :rol';
            $parametros[':rol'] = $rol;
        }

        $where = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);

        return $this->listarPaginado(
            self::SELECT_BASE . $where,
            'SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id' . $where,
            $parametros,
            'u.id DESC',
            $pagina,
            $porPagina
        );
    }

    // =========================================================================
    // COMPROBACIONES Y ROLES
    // =========================================================================

    /**
     * @param string   $nombreUsuario
     * @param int|null $exceptoId Id a excluir, util al actualizar.
     * @return bool
     */
    public function existeNombreUsuario(string $nombreUsuario, ?int $exceptoId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM usuarios WHERE nombre_usuario = :valor';
        $parametros = [':valor' => $nombreUsuario];

        if ($exceptoId !== null) {
            $sql .= ' AND id <> :excepto';
            $parametros[':excepto'] = $exceptoId;
        }

        $sentencia = $this->pdo->prepare($sql);
        $sentencia->execute($parametros);

        return (int) $sentencia->fetchColumn() > 0;
    }

    /**
     * @param string   $correo
     * @param int|null $exceptoId
     * @return bool
     */
    public function existeCorreo(string $correo, ?int $exceptoId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM usuarios WHERE correo = :valor';
        $parametros = [':valor' => $correo];

        if ($exceptoId !== null) {
            $sql .= ' AND id <> :excepto';
            $parametros[':excepto'] = $exceptoId;
        }

        $sentencia = $this->pdo->prepare($sql);
        $sentencia->execute($parametros);

        return (int) $sentencia->fetchColumn() > 0;
    }

    /**
     * Devuelve el identificador de un rol a partir de su nombre.
     *
     * @param string $nombre
     * @return int|null
     */
    public function idDeRol(string $nombre): ?int
    {
        $sentencia = $this->pdo->prepare('SELECT id FROM roles WHERE nombre = :nombre LIMIT 1');
        $sentencia->execute([':nombre' => $nombre]);
        $id = $sentencia->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Lista los roles disponibles.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listarRoles(): array
    {
        return $this->pdo->query('SELECT id, nombre, descripcion FROM roles ORDER BY id')->fetchAll();
    }

    /** @return int Total de usuarios registrados. */
    public function contar(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    }

    /**
     * Ultimos intentos de acceso registrados.
     *
     * @param int $limite
     * @return array<int, array<string,mixed>>
     */
    public function ultimosIntentos(int $limite = 20): array
    {
        $sentencia = $this->pdo->prepare(
            'SELECT identificador, exitoso, direccion_ip, motivo, fecha_intento
               FROM auditoria_acceso
           ORDER BY id DESC
              LIMIT :limite'
        );

        $sentencia->bindValue(':limite', $limite, PDO::PARAM_INT);
        $sentencia->execute();

        return $sentencia->fetchAll();
    }
}
