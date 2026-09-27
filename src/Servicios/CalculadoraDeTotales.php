<?php
/**
 * =============================================================================
 * src/Servicios/CalculadoraDeTotales.php
 * -----------------------------------------------------------------------------
 * Calcula los totales de un documento comercial a partir de sus lineas.
 *
 * REGLA CENTRAL DEL SISTEMA
 * Los importes SIEMPRE se calculan aqui, en el servidor, a partir del precio
 * que tiene el producto en la base de datos. Nunca se toma el precio ni el
 * total que envia el cliente.
 *
 * El motivo es de seguridad: si se confiara en el cliente, cualquiera podria
 * enviar un pedido de un televisor con "total": 1 y el sistema lo aceptaria.
 *
 * FORMULA APLICADA POR LINEA
 *
 *   bruto     = cantidad x precio_unitario
 *   descuento = bruto x (descuento_porcentaje / 100)
 *   subtotal  = bruto - descuento
 *
 * Y sobre el documento completo:
 *
 *   subtotal  = suma de los subtotales de las lineas
 *   iva       = subtotal x (porcentaje_iva / 100)
 *   total     = subtotal + iva
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Servicios;

use App\Repositorios\ProductoRepositorio;

class CalculadoraDeTotales
{
    /**
     * @param ProductoRepositorio $productos
     * @param float               $porcentajeIva
     */
    public function __construct(
        private ProductoRepositorio $productos,
        private float $porcentajeIva
    ) {
    }

    /**
     * Calcula las lineas y los totales de un documento.
     *
     * @param array<int, array<string,mixed>> $lineasSolicitadas Lineas del cliente.
     * @param bool                            $verificarStock    Exigir existencias.
     * @return array{
     *     exito: bool,
     *     errores: array<string,string>,
     *     lineas: array<int, array<string,mixed>>,
     *     totales: array<string,float>
     * }
     */
    public function calcular(array $lineasSolicitadas, bool $verificarStock = false): array
    {
        $lineas   = [];
        $errores  = [];
        $subtotal = 0.0;
        $descuentoTotal = 0.0;

        foreach ($lineasSolicitadas as $indice => $solicitada) {
            $posicion = $indice + 1;

            $productoId = (int) ($solicitada['producto_id'] ?? 0);
            $cantidad   = (int) ($solicitada['cantidad'] ?? 0);
            $descuento  = (float) ($solicitada['descuento_porcentaje'] ?? 0);

            // 1. El producto debe existir.
            $producto = $this->productos->buscarPorId($productoId);

            if ($producto === null) {
                $errores["lineas.$posicion.producto_id"] = "El producto $productoId no existe.";
                continue;
            }

            // 2. No se puede cotizar ni vender un producto descontinuado.
            if ($producto->estado !== 'activo') {
                $errores["lineas.$posicion.producto_id"] =
                    "El producto {$producto->codigo} esta descontinuado.";
                continue;
            }

            // 3. Para un pedido se exige existencia suficiente.
            //    Para una cotizacion no, porque es solo una propuesta.
            if ($verificarStock && !$producto->hayStockPara($cantidad)) {
                $errores["lineas.$posicion.cantidad"] =
                    "Existencias insuficientes de {$producto->codigo}. "
                    . "Disponible: {$producto->stock}, solicitado: $cantidad.";
                continue;
            }

            // 4. El precio se toma SIEMPRE del catalogo, nunca del cliente.
            $precio = $producto->precioUnitario;

            $bruto          = $cantidad * $precio;
            $valorDescuento = $bruto * ($descuento / 100);
            $subtotalLinea  = $bruto - $valorDescuento;

            $lineas[] = [
                'producto_id'          => $producto->id,
                'descripcion'          => $producto->nombre,
                'cantidad'             => $cantidad,
                'precio_unitario'      => round($precio, 2),
                'descuento_porcentaje' => round($descuento, 2),
                'subtotal'             => round($subtotalLinea, 2),
            ];

            $subtotal       += $subtotalLinea;
            $descuentoTotal += $valorDescuento;
        }

        if ($errores !== []) {
            return [
                'exito'   => false,
                'errores' => $errores,
                'lineas'  => [],
                'totales' => [],
            ];
        }

        $iva   = $subtotal * ($this->porcentajeIva / 100);
        $total = $subtotal + $iva;

        return [
            'exito'   => true,
            'errores' => [],
            'lineas'  => $lineas,
            'totales' => [
                'subtotal'        => round($subtotal, 2),
                'valor_descuento' => round($descuentoTotal, 2),
                'valor_iva'       => round($iva, 2),
                'total'           => round($total, 2),
            ],
        ];
    }

    /**
     * Devuelve el porcentaje de IVA configurado.
     * Se expone para poder mostrarlo en las respuestas y en la documentacion.
     *
     * @return float
     */
    public function porcentajeIva(): float
    {
        return $this->porcentajeIva;
    }
}
