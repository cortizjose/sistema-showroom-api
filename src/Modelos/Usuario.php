<?php
/**
 * =============================================================================
 * src/Modelos/Usuario.php
 * -----------------------------------------------------------------------------
 * Entidad que representa a un usuario del sistema.
 *
 * Es un objeto de transferencia de datos: agrupa la informacion y expone
 * reglas propias de la entidad, pero no habla con la base de datos. De eso
 * se encarga UsuarioRepositorio.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Modelos;

class Usuario
{
    /**
     * @param int|null    $id
     * @param string      $nombreUsuario    Nombre de acceso, unico.
     * @param string      $correo           Correo electronico, unico.
     * @param string      $claveHash        Hash bcrypt. Nunca texto plano.
     * @param string|null $nombreCompleto
     * @param int         $rolId            Llave del rol asignado.
     * @param string      $rolNombre        Nombre del rol, para las respuestas.
     * @param string      $estado           activo | inactivo
     * @param int         $intentosFallidos Fallos consecutivos de login.
     * @param string|null $bloqueadoHasta   Fin del bloqueo temporal.
     * @param string|null $ultimoAcceso
     * @param string|null $fechaRegistro
     */
    public function __construct(
        public ?int    $id = null,
        public string  $nombreUsuario = '',
        public string  $correo = '',
        public string  $claveHash = '',
        public ?string $nombreCompleto = null,
        public int     $rolId = 0,
        public string  $rolNombre = '',
        public string  $estado = 'activo',
        public int     $intentosFallidos = 0,
        public ?string $bloqueadoHasta = null,
        public ?string $ultimoAcceso = null,
        public ?string $fechaRegistro = null
    ) {
    }

    /**
     * Construye la entidad a partir de una fila de la consulta.
     *
     * @param array<string,mixed> $fila
     * @return self
     */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id:               isset($fila['id']) ? (int) $fila['id'] : null,
            nombreUsuario:    (string) ($fila['nombre_usuario'] ?? ''),
            correo:           (string) ($fila['correo'] ?? ''),
            claveHash:        (string) ($fila['clave_hash'] ?? ''),
            nombreCompleto:   $fila['nombre_completo'] ?? null,
            rolId:            (int) ($fila['rol_id'] ?? 0),
            rolNombre:        (string) ($fila['rol_nombre'] ?? ''),
            estado:           (string) ($fila['estado'] ?? 'activo'),
            intentosFallidos: (int) ($fila['intentos_fallidos'] ?? 0),
            bloqueadoHasta:   $fila['bloqueado_hasta'] ?? null,
            ultimoAcceso:     $fila['ultimo_acceso'] ?? null,
            fechaRegistro:    $fila['fecha_registro'] ?? null
        );
    }

    /**
     * Representacion publica del usuario.
     *
     * REGLA DE SEGURIDAD: el hash de la contrasena nunca sale del servidor.
     * Concentrar la salida en este metodo garantiza la regla en un solo sitio.
     *
     * @return array<string,mixed>
     */
    public function aArregloPublico(): array
    {
        return [
            'id'             => $this->id,
            'nombreUsuario'  => $this->nombreUsuario,
            'correo'         => $this->correo,
            'nombreCompleto' => $this->nombreCompleto,
            'rol'            => $this->rolNombre,
            'estado'         => $this->estado,
            'ultimoAcceso'   => $this->ultimoAcceso,
            'fechaRegistro'  => $this->fechaRegistro,
        ];
    }

    /** @return bool Indica si la cuenta esta bloqueada por intentos fallidos. */
    public function estaBloqueado(): bool
    {
        return $this->bloqueadoHasta !== null && strtotime($this->bloqueadoHasta) > time();
    }

    /** @return int Minutos que faltan para que expire el bloqueo. */
    public function minutosRestantesDeBloqueo(): int
    {
        if (!$this->estaBloqueado()) {
            return 0;
        }

        return (int) ceil((strtotime((string) $this->bloqueadoHasta) - time()) / 60);
    }

    /** @return bool Indica si la cuenta esta activa. */
    public function estaActivo(): bool
    {
        return $this->estado === 'activo';
    }

    /** @return bool Indica si el usuario tiene rol de administrador. */
    public function esAdministrador(): bool
    {
        return $this->rolNombre === 'administrador';
    }
}
