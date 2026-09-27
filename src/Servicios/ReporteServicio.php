<?php
/**
 * =============================================================================
 * src/Servicios/ReporteServicio.php
 * -----------------------------------------------------------------------------
 * Consultas agregadas para la toma de decisiones del showroom.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Servicios;

use App\Repositorios\PedidoRepositorio;
use App\Repositorios\ProductoRepositorio;

class ReporteServicio extends ServicioBase
{
    /**
     * @param PedidoRepositorio   $pedidos
     * @param ProductoRepositorio $productos
     */
    public function __construct(
        private PedidoRepositorio $pedidos,
        private ProductoRepositorio $productos
    ) {
    }

    /**
     * Resumen de ventas de un periodo.
     *
     * Si no se indican fechas, se toma el mes en curso.
     *
     * @param array<string,mixed> $filtros
     * @return array<string,mixed>
     */
    public function ventas(array $filtros): array
    {
        $desde = $this->fecha($filtros['desde'] ?? null, date('Y-m-01'));
        $hasta = $this->fecha($filtros['hasta'] ?? null, date('Y-m-d'));

        if ($desde > $hasta) {
            return $this->errorDeValidacion([
                'desde' => 'La fecha inicial no puede ser posterior a la final.',
            ]);
        }

        return $this->exito('Reporte de ventas generado correctamente.', [
            'periodo'   => ['desde' => $desde, 'hasta' => $hasta],
            'resumen'   => $this->pedidos->resumenDeVentas($desde, $hasta),
            'porEstado' => $this->pedidos->ventasPorEstado($desde, $hasta),
        ]);
    }

    /**
     * Productos mas solicitados en cotizaciones.
     *
     * @param array<string,mixed> $filtros
     * @return array<string,mixed>
     */
    public function productosMasCotizados(array $filtros): array
    {
        $limite = (int) ($filtros['limite'] ?? 10);
        $limite = min(max(1, $limite), 50);

        return $this->exito('Reporte de productos mas cotizados generado correctamente.', [
            'limite'    => $limite,
            'productos' => $this->pedidos->productosMasCotizados($limite),
        ]);
    }

    /**
     * Productos que alcanzaron su nivel minimo de existencias.
     *
     * @return array<string,mixed>
     */
    public function inventarioBajo(): array
    {
        $productos = $this->productos->conStockBajo();

        return $this->exito('Reporte de reposicion generado correctamente.', [
            'total'     => count($productos),
            'productos' => $productos,
        ]);
    }

    /**
     * Normaliza una fecha recibida como texto.
     *
     * @param mixed  $valor
     * @param string $porDefecto
     * @return string Fecha en formato Y-m-d.
     */
    private function fecha(mixed $valor, string $porDefecto): string
    {
        if (!is_string($valor) || $valor === '') {
            return $porDefecto;
        }

        $marca = strtotime($valor);

        return $marca === false ? $porDefecto : date('Y-m-d', $marca);
    }
}
