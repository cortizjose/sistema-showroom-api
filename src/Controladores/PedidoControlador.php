<?php
/**
 * =============================================================================
 * src/Controladores/PedidoControlador.php
 * -----------------------------------------------------------------------------
 * Endpoints de pedidos.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Contexto;
use App\Nucleo\Solicitud;
use App\Servicios\PedidoServicio;

class PedidoControlador extends ControladorBase
{
    /**
     * @param PedidoServicio $servicio
     */
    public function __construct(private PedidoServicio $servicio)
    {
    }

    /**
     * GET /api/pedidos
     *
     * Admite ?cliente=, ?estado=, ?desde=, ?hasta=, ?pagina=, ?porPagina=
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
     * GET /api/pedidos/{id}
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
     * POST /api/pedidos
     *
     * Pedido directo, sin cotizacion previa. Exige existencias suficientes.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function crear(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->crear($solicitud->cuerpo(), Contexto::usuarioId()));
    }

    /**
     * PATCH /api/pedidos/{id}/estado
     *
     * Cuerpo: { "estado": "confirmado", "observacion": "..." }
     *
     * Confirmar descuenta el inventario; anular lo devuelve.
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
}
