<?php
/**
 * =============================================================================
 * src/Modelos/DocumentoComercial.php
 * -----------------------------------------------------------------------------
 * Clase base de los documentos comerciales: cotizaciones y pedidos.
 *
 * Ambos comparten estructura (numero, cliente, asesor, lineas, totales) y
 * comportamiento (maquina de estados). Lo comun vive aqui; lo propio de cada
 * documento, en su subclase.
 *
 * MAQUINA DE ESTADOS
 * Un documento no puede pasar de cualquier estado a cualquier otro. Por
 * ejemplo, un pedido entregado no puede volver a "pendiente". Cada subclase
 * declara sus transiciones validas y esta clase las hace cumplir.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Modelos;

abstract class DocumentoComercial
{
    /** @var int|null Identificador en base de datos. */
    public ?int $id = null;

    /** @var string Numero consecutivo del documento (COT-000001). */
    public string $numero = '';

    /** @var int Cliente al que pertenece el documento. */
    public int $clienteId = 0;

    /** @var string Nombre del cliente, para las respuestas. */
    public string $clienteNombre = '';

    /** @var int Asesor que elaboro el documento. */
    public int $usuarioId = 0;

    /** @var string Nombre del asesor, para las respuestas. */
    public string $usuarioNombre = '';

    /** @var string Estado actual del documento. */
    public string $estado = '';

    /** @var float Suma de las lineas antes de descuentos e impuestos. */
    public float $subtotal = 0.0;

    /** @var float Valor total de los descuentos aplicados. */
    public float $valorDescuento = 0.0;

    /** @var float Valor del IVA calculado. */
    public float $valorIva = 0.0;

    /** @var float Valor final a pagar. */
    public float $total = 0.0;

    /** @var string|null Notas libres del asesor. */
    public ?string $observaciones = null;

    /** @var array<int, array<string,mixed>> Lineas de detalle. */
    public array $lineas = [];

    /**
     * Devuelve las transiciones de estado permitidas.
     *
     * Formato: ['estado_actual' => ['estado_destino', ...]]
     *
     * @return array<string, array<int,string>>
     */
    abstract public function transicionesValidas(): array;

    /**
     * Devuelve el nombre del tipo de documento, para la bitacora.
     *
     * @return string
     */
    abstract public function tipo(): string;

    /**
     * Indica si es posible pasar al estado indicado desde el estado actual.
     *
     * @param string $nuevoEstado
     * @return bool
     */
    public function puedeTransicionarA(string $nuevoEstado): bool
    {
        $permitidos = $this->transicionesValidas()[$this->estado] ?? [];

        return in_array($nuevoEstado, $permitidos, true);
    }

    /**
     * Lista los estados a los que se puede pasar desde el estado actual.
     * Se incluye en los mensajes de error para orientar a quien consume la API.
     *
     * @return array<int,string>
     */
    public function siguientesEstados(): array
    {
        return $this->transicionesValidas()[$this->estado] ?? [];
    }

    /**
     * Indica si el documento ya no admite modificaciones.
     *
     * @return bool
     */
    public function estaCerrado(): bool
    {
        return $this->siguientesEstados() === [];
    }

    /**
     * Parte comun de la representacion en las respuestas de la API.
     *
     * @return array<string,mixed>
     */
    protected function camposComunes(): array
    {
        return [
            'id'      => $this->id,
            'numero'  => $this->numero,
            'cliente' => [
                'id'     => $this->clienteId,
                'nombre' => $this->clienteNombre,
            ],
            'asesor'  => [
                'id'     => $this->usuarioId,
                'nombre' => $this->usuarioNombre,
            ],
            'estado'  => $this->estado,
            'totales' => [
                'subtotal'  => round($this->subtotal, 2),
                'descuento' => round($this->valorDescuento, 2),
                'iva'       => round($this->valorIva, 2),
                'total'     => round($this->total, 2),
            ],
            'observaciones'    => $this->observaciones,
            'lineas'           => $this->lineas,
            'siguientesEstados' => $this->siguientesEstados(),
        ];
    }
}
