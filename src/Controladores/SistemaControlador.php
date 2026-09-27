<?php
/**
 * =============================================================================
 * src/Controladores/SistemaControlador.php
 * -----------------------------------------------------------------------------
 * Endpoints informativos: indice de la API y comprobacion de estado.
 *
 * El indice se construye a partir de la tabla de rutas del enrutador, de modo
 * que la documentacion en linea nunca se desactualiza respecto del codigo.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Controladores;

use App\BaseDatos\Conexion;
use App\Nucleo\Enrutador;
use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;
use App\Repositorios\ClienteRepositorio;
use App\Repositorios\CotizacionRepositorio;
use App\Repositorios\PedidoRepositorio;
use App\Repositorios\ProductoRepositorio;
use App\Repositorios\UsuarioRepositorio;
use Throwable;

class SistemaControlador extends ControladorBase
{
    /**
     * @param array<string,mixed> $configuracionApp
     * @param array<string,mixed> $negocio
     * @param Enrutador           $enrutador
     */
    public function __construct(
        private array $configuracionApp,
        private array $negocio,
        private Enrutador $enrutador
    ) {
    }

    /**
     * GET /api
     *
     * Documentacion en linea: lista todos los endpoints publicados, con el
     * rol que exige cada uno.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function indice(Solicitud $solicitud, array $parametros = []): void
    {
        $catalogo = $this->enrutador->catalogo();

        Respuesta::exito('API del Sistema de Showroom y Ventas.', [
            'version'        => $this->configuracionApp['version'],
            'totalEndpoints' => count($catalogo),
            'reglas'         => [
                'porcentajeIva'          => $this->negocio['porcentajeIva'],
                'diasVigenciaCotizacion' => $this->negocio['diasVigenciaCotizacion'],
                'descuentoMaximo'        => $this->negocio['descuentoMaximoPorcentaje'],
            ],
            'endpoints'      => $catalogo,
        ]);
    }

    /**
     * GET /api/salud
     *
     * Comprobacion de disponibilidad del servicio y de la base de datos.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function salud(Solicitud $solicitud, array $parametros = []): void
    {
        $operativa = true;
        $conteos   = [];

        try {
            $conteos = [
                'usuarios'     => (new UsuarioRepositorio())->contar(),
                'clientes'     => (new ClienteRepositorio())->contar(),
                'productos'    => (new ProductoRepositorio())->contar(),
                'cotizaciones' => (new CotizacionRepositorio())->contar(),
                'pedidos'      => (new PedidoRepositorio())->contar(),
            ];
        } catch (Throwable) {
            $operativa = false;
        }

        Respuesta::exito('El servicio se encuentra operativo.', [
            'servicio'       => $this->configuracionApp['nombre'],
            'version'        => $this->configuracionApp['version'],
            'entorno'        => $this->configuracionApp['entorno'],
            'motorBaseDatos' => Conexion::driver(),
            'baseDatos'      => $operativa ? 'conectada' : 'sin conexion',
            'registros'      => $conteos,
            'phpVersion'     => PHP_VERSION,
        ]);
    }
}
