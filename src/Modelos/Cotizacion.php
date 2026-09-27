<?php
/**
 * =============================================================================
 * src/Modelos/Cotizacion.php
 * -----------------------------------------------------------------------------
 * Propuesta economica con vigencia limitada que se entrega a un cliente.
 *
 * CICLO DE VIDA
 *
 *   borrador ---> enviada ---> aprobada ---> convertida
 *      |             |             (se transforma en pedido)
 *      |             +--> rechazada
 *      |             +--> vencida  (automatico al expirar)
 *      +--> anulada
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Modelos;

class Cotizacion extends DocumentoComercial
{
    /** @var string|null Fecha en que se emitio. */
    public ?string $fechaEmision = null;

    /** @var string|null Fecha hasta la que los precios se mantienen. */
    public ?string $fechaVencimiento = null;

    /**
     * Transiciones permitidas entre estados.
     *
     * Los estados sin salida (convertida, rechazada, vencida, anulada) cierran
     * el documento: ya no admite cambios.
     *
     * @return array<string, array<int,string>>
     */
    public function transicionesValidas(): array
    {
        return [
            'borrador'   => ['enviada', 'anulada'],
            'enviada'    => ['aprobada', 'rechazada', 'vencida', 'anulada'],
            'aprobada'   => ['convertida', 'anulada'],
            'rechazada'  => [],
            'vencida'    => [],
            'convertida' => [],
            'anulada'    => [],
        ];
    }

    /** @return string Tipo de documento, usado en la bitacora de estados. */
    public function tipo(): string
    {
        return 'cotizacion';
    }

    /**
     * Construye la entidad a partir de una fila de la consulta.
     *
     * @param array<string,mixed> $fila
     * @return self
     */
    public static function desdeFila(array $fila): self
    {
        $c = new self();

        $c->id               = isset($fila['id']) ? (int) $fila['id'] : null;
        $c->numero           = (string) ($fila['numero'] ?? '');
        $c->clienteId        = (int) ($fila['cliente_id'] ?? 0);
        $c->clienteNombre    = (string) ($fila['cliente_nombre'] ?? '');
        $c->usuarioId        = (int) ($fila['usuario_id'] ?? 0);
        $c->usuarioNombre    = (string) ($fila['usuario_nombre'] ?? '');
        $c->estado           = (string) ($fila['estado'] ?? 'borrador');
        $c->subtotal         = (float) ($fila['subtotal'] ?? 0);
        $c->valorDescuento   = (float) ($fila['valor_descuento'] ?? 0);
        $c->valorIva         = (float) ($fila['valor_iva'] ?? 0);
        $c->total            = (float) ($fila['total'] ?? 0);
        $c->observaciones    = $fila['observaciones'] ?? null;
        $c->fechaEmision     = $fila['fecha_emision'] ?? null;
        $c->fechaVencimiento = $fila['fecha_vencimiento'] ?? null;

        return $c;
    }

    /**
     * Indica si la cotizacion ya paso su fecha de vencimiento.
     *
     * Solo tiene sentido para una cotizacion enviada: una aprobada o
     * rechazada ya cumplio su proposito.
     *
     * @return bool
     */
    public function estaVencida(): bool
    {
        if ($this->fechaVencimiento === null) {
            return false;
        }

        return $this->estado === 'enviada' && strtotime($this->fechaVencimiento) < time();
    }

    /** @return int Dias que faltan para el vencimiento. Negativo si ya paso. */
    public function diasParaVencer(): int
    {
        if ($this->fechaVencimiento === null) {
            return 0;
        }

        return (int) ceil((strtotime($this->fechaVencimiento) - time()) / 86400);
    }

    /**
     * Indica si la cotizacion puede convertirse en pedido.
     * Solo una cotizacion aprobada y vigente puede hacerlo.
     *
     * @return bool
     */
    public function puedeConvertirseEnPedido(): bool
    {
        return $this->estado === 'aprobada';
    }

    /**
     * Representacion para las respuestas de la API.
     *
     * @return array<string,mixed>
     */
    public function aArreglo(): array
    {
        return array_merge($this->camposComunes(), [
            'fechaEmision'     => $this->fechaEmision,
            'fechaVencimiento' => $this->fechaVencimiento,
            'vigente'          => !$this->estaVencida(),
            'diasParaVencer'   => $this->diasParaVencer(),
        ]);
    }
}
