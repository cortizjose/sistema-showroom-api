<?php
/**
 * =============================================================================
 * src/Controladores/AutenticacionControlador.php
 * -----------------------------------------------------------------------------
 * Endpoints de autenticacion y de administracion de usuarios.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Contexto;
use App\Nucleo\Solicitud;
use App\Servicios\AutenticacionServicio;

class AutenticacionControlador extends ControladorBase
{
    /**
     * @param AutenticacionServicio $servicio
     */
    public function __construct(private AutenticacionServicio $servicio)
    {
    }

    // =========================================================================
    // AUTENTICACION
    // =========================================================================

    /**
     * POST /api/auth/login
     *
     * Autentica al usuario y devuelve un token JWT.
     *
     * Cuerpo: { "usuario": "...", "contrasena": "..." }
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function iniciarSesion(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder(
            $this->servicio->iniciarSesion($solicitud->cuerpo(), $solicitud->direccionIp())
        );
    }

    /**
     * POST /api/auth/registro
     *
     * Registro publico. Crea la cuenta con el rol de menor privilegio.
     * La creacion de usuarios con rol se hace desde /api/usuarios.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function registro(Solicitud $solicitud, array $parametros = []): void
    {
        $datos = $solicitud->cuerpo();

        // En el registro publico el rol no se acepta desde el cliente: de lo
        // contrario cualquiera podria crearse una cuenta de administrador.
        unset($datos['rol']);

        $this->responder($this->servicio->registrar($datos, 'consulta'));
    }

    /**
     * GET /api/auth/perfil
     *
     * Devuelve los datos del usuario autenticado.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function perfil(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->obtenerPerfil($solicitud->tokenPortador()));
    }

    /**
     * POST /api/auth/cambiar-clave
     *
     * Cambia la contrasena del usuario autenticado.
     *
     * Cuerpo: { "contrasena_actual": "...", "contrasena_nueva": "..." }
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function cambiarClave(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder(
            $this->servicio->cambiarClave(Contexto::usuarioId(), $solicitud->cuerpo())
        );
    }

    // =========================================================================
    // ADMINISTRACION DE USUARIOS
    // =========================================================================

    /**
     * GET /api/usuarios
     *
     * Listado paginado. Admite ?busqueda=, ?rol=, ?pagina=, ?porPagina=
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function listar(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->listar($this->parametrosDeConsulta($solicitud)));
    }

    /**
     * GET /api/usuarios/{id}
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function consultar(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->consultar($this->entero($parametros)));
    }

    /**
     * POST /api/usuarios
     *
     * Crea un usuario con el rol indicado. Solo administradores.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function crear(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->registrar($solicitud->cuerpo(), 'consulta'));
    }

    /**
     * PUT /api/usuarios/{id}
     *
     * Actualiza el correo, el nombre y el rol de un usuario.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function actualizar(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder(
            $this->servicio->actualizar($this->entero($parametros), $solicitud->cuerpo())
        );
    }

    /**
     * PATCH /api/usuarios/{id}/estado
     *
     * Activa o desactiva una cuenta.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function cambiarEstado(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder(
            $this->servicio->cambiarEstado(
                $this->entero($parametros),
                $solicitud->cuerpo(),
                Contexto::usuarioId()
            )
        );
    }

    /**
     * GET /api/roles
     *
     * Lista los roles disponibles y lo que puede hacer cada uno.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function roles(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->listarRoles());
    }
}
