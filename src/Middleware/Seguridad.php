<?php
/**
 * =============================================================================
 * src/Middleware/Seguridad.php
 * -----------------------------------------------------------------------------
 * Comprobaciones que se ejecutan ANTES de llegar al controlador.
 *
 * Son dos:
 *
 *   autenticado   Exige un token JWT valido. Deja el usuario en el Contexto.
 *   rol:a|b       Exige que el usuario tenga alguno de los roles indicados.
 *
 * Tenerlas aqui, y no dentro de cada controlador, evita que un endpoint quede
 * desprotegido por olvido: la proteccion se declara junto a la ruta.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Nucleo\Contexto;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Repositorios\UsuarioRepositorio;
use App\Servicios\Jwt;

class Seguridad
{
    /**
     * @param Jwt                $jwt
     * @param UsuarioRepositorio $usuarios
     */
    public function __construct(
        private Jwt $jwt,
        private UsuarioRepositorio $usuarios
    ) {
    }

    /**
     * Exige un token valido y carga el usuario en el contexto.
     *
     * Responde 401 y devuelve false cuando la comprobacion falla, con lo que
     * el enrutador detiene la peticion antes de llegar al controlador.
     *
     * @param Solicitud $solicitud
     * @param string    $argumento No se usa.
     * @return bool
     */
    public function autenticado(Solicitud $solicitud, string $argumento = ''): bool
    {
        $token = $solicitud->tokenPortador();

        if ($token === null) {
            Respuesta::error(
                'Acceso no autorizado: debe enviar la cabecera Authorization: Bearer <token>.',
                401
            );

            return false;
        }

        $cargaUtil = $this->jwt->verificar($token);

        if ($cargaUtil === null) {
            Respuesta::error('Acceso no autorizado: el token es invalido o ha expirado.', 401);

            return false;
        }

        // Se recarga el usuario desde la base de datos y no se confia solo en
        // el token: si la cuenta fue desactivada despues de emitirlo, el
        // acceso debe cortarse de inmediato.
        $usuario = $this->usuarios->buscarPorId((int) $cargaUtil['sub']);

        if ($usuario === null) {
            Respuesta::error('Acceso no autorizado: el usuario del token ya no existe.', 401);

            return false;
        }

        if (!$usuario->estaActivo()) {
            Respuesta::error('Acceso denegado: la cuenta se encuentra inactiva.', 403);

            return false;
        }

        Contexto::establecerUsuario($usuario);

        return true;
    }

    /**
     * Exige que el usuario tenga alguno de los roles indicados.
     *
     * Se declara en la ruta como "rol:administrador" o "rol:administrador|asesor".
     * Siempre se ejecuta despues de "autenticado".
     *
     * @param Solicitud $solicitud
     * @param string    $argumento Roles separados por barra vertical.
     * @return bool
     */
    public function rol(Solicitud $solicitud, string $argumento = ''): bool
    {
        // Si la ruta declaro rol pero no autenticado, se resuelve aqui.
        if (Contexto::usuario() === null && !$this->autenticado($solicitud)) {
            return false;
        }

        $permitidos = array_filter(explode('|', $argumento));

        if ($permitidos === []) {
            return true;
        }

        if (!Contexto::tieneAlgunRol($permitidos)) {
            Respuesta::error(
                'Acceso denegado: esta operacion requiere el rol '
                . implode(' o ', $permitidos) . '. Su rol es ' . Contexto::rol() . '.',
                403
            );

            return false;
        }

        return true;
    }
}
