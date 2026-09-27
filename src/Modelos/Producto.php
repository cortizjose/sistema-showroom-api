<?php
/**
 * =============================================================================
 * src/Modelos/Producto.php
 * -----------------------------------------------------------------------------
 * Entidad del catalogo del showroom.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Modelos;

class Producto
{
    /**
     * @param int|null    $id
     * @param string      $codigo          Codigo interno, unico.
     * @param string      $nombre
     * @param string|null $descripcion
     * @param int|null    $categoriaId
     * @param string|null $categoriaNombre Para responder sin consulta extra.
     * @param float       $precioUnitario
     * @param int         $stock           Existencia actual.
     * @param int         $stockMinimo     Umbral de alerta de reposicion.
     * @param string      $estado          activo | descontinuado
     * @param string|null $fechaRegistro
     * @param string|null $fechaActualizacion
     */
    public function __construct(
        public ?int    $id = null,
        public string  $codigo = '',
        public string  $nombre = '',
        public ?string $descripcion = null,
        public ?int    $categoriaId = null,
        public ?string $categoriaNombre = null,
        public float   $precioUnitario = 0.0,
        public int     $stock = 0,
        public int     $stockMinimo = 0,
        public string  $estado = 'activo',
        public ?string $fechaRegistro = null,
        public ?string $fechaActualizacion = null
    ) {
    }

    /**
     * @param array<string,mixed> $fila
     * @return self
     */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id:                 isset($fila['id']) ? (int) $fila['id'] : null,
            codigo:             (string) ($fila['codigo'] ?? ''),
            nombre:             (string) ($fila['nombre'] ?? ''),
            descripcion:        $fila['descripcion'] ?? null,
            categoriaId:        isset($fila['categoria_id']) ? (int) $fila['categoria_id'] : null,
            categoriaNombre:    $fila['categoria_nombre'] ?? null,
            precioUnitario:     (float) ($fila['precio_unitario'] ?? 0),
            stock:              (int) ($fila['stock'] ?? 0),
            stockMinimo:        (int) ($fila['stock_minimo'] ?? 0),
            estado:             (string) ($fila['estado'] ?? 'activo'),
            fechaRegistro:      $fila['fecha_registro'] ?? null,
            fechaActualizacion: $fila['fecha_actualizacion'] ?? null
        );
    }

    /**
     * Representacion para las respuestas de la API.
     *
     * @return array<string,mixed>
     */
    public function aArreglo(): array
    {
        return [
            'id'             => $this->id,
            'codigo'         => $this->codigo,
            'nombre'         => $this->nombre,
            'descripcion'    => $this->descripcion,
            'categoria'      => [
                'id'     => $this->categoriaId,
                'nombre' => $this->categoriaNombre,
            ],
            'precioUnitario' => round($this->precioUnitario, 2),
            'stock'          => $this->stock,
            'stockMinimo'    => $this->stockMinimo,
            'stockBajo'      => $this->tieneStockBajo(),
            'estado'         => $this->estado,
            'disponible'     => $this->estaDisponible(),
            'fechaRegistro'  => $this->fechaRegistro,
        ];
    }

    /** @return bool El producto esta activo y tiene existencias. */
    public function estaDisponible(): bool
    {
        return $this->estado === 'activo' && $this->stock > 0;
    }

    /** @return bool La existencia llego al umbral de reposicion. */
    public function tieneStockBajo(): bool
    {
        return $this->stock <= $this->stockMinimo;
    }

    /**
     * Indica si hay existencia suficiente para la cantidad solicitada.
     *
     * @param int $cantidad
     * @return bool
     */
    public function hayStockPara(int $cantidad): bool
    {
        return $this->stock >= $cantidad;
    }
}
