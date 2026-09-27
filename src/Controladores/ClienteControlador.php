<?php
/**
 * =============================================================================
 * src/Controladores/ClienteControlador.php
 * -----------------------------------------------------------------------------
 * Endpoints de gestion de clientes.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Solicitud;
use App\Servicios\ClienteServicio;

class ClienteControlador extends ControladorBase
{
    /**
     * @param ClienteServicio $servicio
     */
    public function __construct(private ClienteServicio $servicio)
    {
    }

    /**
     * GET /api/clientes
     *
     * Admite ?busqueda=, ?ciudad=, ?estado=, ?pagina=, ?porPagina=
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
     * GET /api/clientes/{id}
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
     * POST /api/clientes
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function crear(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->crear($solicitud->cuerpo()));
    }

    /**
     * PUT /api/clientes/{id}
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
     * PATCH /api/clientes/{id}/estado
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function cambiarEstado(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder(
            $this->servicio->cambiarEstado($this->entero($parametros), $solicitud->cuerpo())
        );
    }
}
