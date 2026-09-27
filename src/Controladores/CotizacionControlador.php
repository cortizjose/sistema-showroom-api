<?php
/**
 * =============================================================================
 * src/Controladores/CotizacionControlador.php
 * -----------------------------------------------------------------------------
 * Endpoints de cotizaciones.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Contexto;
use App\Nucleo\Solicitud;
use App\Servicios\CotizacionServicio;

class CotizacionControlador extends ControladorBase
{
    /**
     * @param CotizacionServicio $servicio
     */
    public function __construct(private CotizacionServicio $servicio)
    {
    }

    /**
     * GET /api/cotizaciones
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
     * GET /api/cotizaciones/{id}
     *
     * Devuelve la cotizacion con su detalle y su historial de estados.
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
     * POST /api/cotizaciones
     *
     * Cuerpo:
     * {
     *   "cliente_id": 1,
     *   "observaciones": "Entrega en obra",
     *   "lineas": [
     *     { "producto_id": 3, "cantidad": 2, "descuento_porcentaje": 5 }
     *   ]
     * }
     *
     * Los precios y los totales los calcula el servidor: no se aceptan
     * importes enviados por el cliente.
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
     * PATCH /api/cotizaciones/{id}/estado
     *
     * Cuerpo: { "estado": "enviada", "observacion": "..." }
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
     * POST /api/cotizaciones/{id}/convertir-pedido
     *
     * Convierte una cotizacion aprobada en pedido. Verifica existencias.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function convertirEnPedido(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder(
            $this->servicio->convertirEnPedido(
                $this->entero($parametros),
                $solicitud->cuerpo(),
                Contexto::usuarioId()
            )
        );
    }
}
