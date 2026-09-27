<?php
/**
 * =============================================================================
 * src/Nucleo/Contexto.php
 * -----------------------------------------------------------------------------
 * Guarda los datos de la peticion en curso.
 *
 * El middleware de autenticacion deja aqui el usuario ya verificado, y los
 * controladores lo recuperan sin tener que volver a leer el token ni a
 * consultar la base de datos.
 *
 * Vive solo durante una peticion: PHP descarta todo al terminar de responder.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Nucleo;

use App\Modelos\Usuario;

class Contexto
{
    /** @var Usuario|null Usuario autenticado en la peticion actual. */
    private static ?Usuario $usuario = null;

    /**
     * Registra el usuario autenticado.
     *
     * @param Usuario $usuario
     * @return void
     */
    public static function establecerUsuario(Usuario $usuario): void
    {
        self::$usuario = $usuario;
    }

    /**
     * Devuelve el usuario autenticado, o null si la ruta es publica.
     *
     * @return Usuario|null
     */
    public static function usuario(): ?Usuario
    {
        return self::$usuario;
    }

    /**
     * Devuelve el identificador del usuario autenticado.
     *
     * @return int 0 si no hay usuario en la peticion.
     */
    public static function usuarioId(): int
    {
        return (int) (self::$usuario?->id ?? 0);
    }

    /**
     * Devuelve el rol del usuario autenticado.
     *
     * @return string Cadena vacia si no hay usuario.
     */
    public static function rol(): string
    {
        return self::$usuario?->rolNombre ?? '';
    }

    /**
     * Indica si el usuario autenticado tiene alguno de los roles indicados.
     *
     * @param array<int,string> $roles
     * @return bool
     */
    public static function tieneAlgunRol(array $roles): bool
    {
        return in_array(self::rol(), $roles, true);
    }

    /**
     * Limpia el contexto. Se usa en las pruebas, para que un caso no arrastre
     * el usuario del caso anterior.
     *
     * @return void
     */
    public static function limpiar(): void
    {
        self::$usuario = null;
    }
}
