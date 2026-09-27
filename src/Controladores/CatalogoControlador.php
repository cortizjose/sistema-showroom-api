<?php
/**
 * =============================================================================
 * src/Controladores/CatalogoControlador.php
 * -----------------------------------------------------------------------------
 * Endpoints del catalogo: productos, categorias e inventario.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Contexto;
use App\Nucleo\Solicitud;
use App\Servicios\CatalogoServicio;

class CatalogoControlador extends ControladorBase
{
    /**
     * @param CatalogoServicio $servicio
     */
    public function __construct(private CatalogoServicio $servicio)
    {
    }

    /**
     * GET /api/productos
     *
     * Admite ?busqueda=, ?categoria=, ?estado=, ?stockBajo=1, ?pagina=, ?porPagina=
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function listar(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->listarProductos($this->parametrosDeConsulta($solicitud)));
    }

    /**
     * GET /api/productos/{id}
     *
     * Devuelve el producto y sus ultimos movimientos de inventario.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function consultar(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->consultarProducto($this->entero($parametros)));
    }

    /**
     * POST /api/productos
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function crear(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->crearProducto($solicitud->cuerpo(), Contexto::usuarioId()));
    }

    /**
     * PUT /api/productos/{id}
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function actualizar(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder(
            $this->servicio->actualizarProducto($this->entero($parametros), $solicitud->cuerpo())
        );
    }

    /**
     * DELETE /api/productos/{id}
     *
     * Baja logica: el producto se marca como descontinuado pero se conserva,
     * porque aparece en documentos historicos.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function descontinuar(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->descontinuarProducto($this->entero($parametros)));
    }

    /**
     * PATCH /api/productos/{id}/stock
     *
     * Cuerpo: { "cantidad": 10, "motivo": "Compra a proveedor" }
     * Cantidad positiva suma, negativa resta.
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function ajustarStock(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder(
            $this->servicio->ajustarStock(
                $this->entero($parametros),
                $solicitud->cuerpo(),
                Contexto::usuarioId()
            )
        );
    }

    /**
     * GET /api/categorias
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function listarCategorias(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->listarCategorias());
    }

    /**
     * POST /api/categorias
     *
     * @param Solicitud            $solicitud
     * @param array<string,string> $parametros
     * @return void
     */
    public function crearCategoria(Solicitud $solicitud, array $parametros = []): void
    {
        $this->responder($this->servicio->crearCategoria($solicitud->cuerpo()));
    }
}
