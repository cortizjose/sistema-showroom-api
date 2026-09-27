<?php
/**
 * =============================================================================
 * src/Controladores/ReporteControlador.php
 * -----------------------------------------------------------------------------
 * Endpoints de reportes del showroom.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Solicitud;
use App\Servicios\ReporteServicio;

class ReporteControlador extends ControladorBase
{
    /**
     * @param ReporteServicio $servicio
     */
    public function __construct(private ReporteServicio $servicio)
    {
    }

    /**
     * GET /api/reportes/ventas
     *
     * Admite ?desde=2026-09-01&hasta=2026-09-30
     * Sin parametros toma el mes en curso.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function ventas(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->ventas($this->parametrosDeConsulta($solicitud)));
    }

    /**
     * GET /api/reportes/productos-mas-cotizados
     *
     * Admite ?limite=10
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function productosMasCotizados(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder(
            $this->servicio->productosMasCotizados($this->parametrosDeConsulta($solicitud))
        );
    }

    /**
     * GET /api/reportes/inventario-bajo
     *
     * Productos que alcanzaron su nivel minimo de existencias.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function inventarioBajo(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->inventarioBajo());
    }
}
