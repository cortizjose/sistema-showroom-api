<?php
/**
 * =============================================================================
 * src/Modelos/Pedido.php
 * -----------------------------------------------------------------------------
 * Venta en firme. Puede nacer de una cotizacion aprobada o crearse directo.
 *
 * CICLO DE VIDA
 *
 *   pendiente ---> confirmado ---> en_preparacion ---> despachado ---> entregado
 *       |              |                 |                 |
 *       +--> anulado <-+-----------------+-----------------+
 *
 * EFECTO SOBRE EL INVENTARIO
 *   Al confirmar    se descuenta el stock de cada producto.
 *   Al anular       se devuelve el stock, si ya habia sido descontado.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Modelos;

class Pedido extends DocumentoComercial
{
    /** @var int|null Cotizacion de origen, si el pedido nacio de una. */
    public ?int $cotizacionId = null;

    /** @var string|null Numero de la cotizacion de origen. */
    public ?string $cotizacionNumero = null;

    /** @var string|null Fecha en que se registro el pedido. */
    public ?string $fechaPedido = null;

    /** @var string|null Fecha comprometida de entrega. */
    public ?string $fechaEntregaEstimada = null;

    /** @var string|null Direccion a la que se despacha. */
    public ?string $direccionEntrega = null;

    /**
     * Transiciones permitidas entre estados.
     *
     * @return array<string, array<int,string>>
     */
    public function transicionesValidas(): array
    {
        return [
            'pendiente'      => ['confirmado', 'anulado'],
            'confirmado'     => ['en_preparacion', 'anulado'],
            'en_preparacion' => ['despachado', 'anulado'],
            'despachado'     => ['entregado', 'anulado'],
            'entregado'      => [],
            'anulado'        => [],
        ];
    }

    /** @return string Tipo de documento, usado en la bitacora de estados. */
    public function tipo(): string
    {
        return 'pedido';
    }

    /**
     * Construye la entidad a partir de una fila de la consulta.
     *
     * @param array<string,mixed> $fila
     * @return self
     */
    public static function desdeFila(array $fila): self
    {
        $p = new self();

        $p->id                   = isset($fila['id']) ? (int) $fila['id'] : null;
        $p->numero               = (string) ($fila['numero'] ?? '');
        $p->cotizacionId         = isset($fila['cotizacion_id']) ? (int) $fila['cotizacion_id'] : null;
        $p->cotizacionNumero     = $fila['cotizacion_numero'] ?? null;
        $p->clienteId            = (int) ($fila['cliente_id'] ?? 0);
        $p->clienteNombre        = (string) ($fila['cliente_nombre'] ?? '');
        $p->usuarioId            = (int) ($fila['usuario_id'] ?? 0);
        $p->usuarioNombre        = (string) ($fila['usuario_nombre'] ?? '');
        $p->estado               = (string) ($fila['estado'] ?? 'pendiente');
        $p->subtotal             = (float) ($fila['subtotal'] ?? 0);
        $p->valorDescuento       = (float) ($fila['valor_descuento'] ?? 0);
        $p->valorIva             = (float) ($fila['valor_iva'] ?? 0);
        $p->total                = (float) ($fila['total'] ?? 0);
        $p->observaciones        = $fila['observaciones'] ?? null;
        $p->fechaPedido          = $fila['fecha_pedido'] ?? null;
        $p->fechaEntregaEstimada = $fila['fecha_entrega_estimada'] ?? null;
        $p->direccionEntrega     = $fila['direccion_entrega'] ?? null;

        return $p;
    }

    /**
     * Indica si el pedido ya descontó existencias del inventario.
     * Ocurre a partir de la confirmacion.
     *
     * @return bool
     */
    public function afectoInventario(): bool
    {
        return in_array($this->estado, ['confirmado', 'en_preparacion', 'despachado', 'entregado'], true);
    }

    /**
     * Representacion para las respuestas de la API.
     *
     * @return array<string,mixed>
     */
    public function aArreglo(): array
    {
        return array_merge($this->camposComunes(), [
            'cotizacionOrigen' => $this->cotizacionId === null ? null : [
                'id'     => $this->cotizacionId,
                'numero' => $this->cotizacionNumero,
            ],
            'fechaPedido'          => $this->fechaPedido,
            'fechaEntregaEstimada' => $this->fechaEntregaEstimada,
            'direccionEntrega'     => $this->direccionEntrega,
        ]);
    }
}
