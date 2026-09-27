<?php
/**
 * =============================================================================
 * src/Modelos/Cliente.php
 * -----------------------------------------------------------------------------
 * Entidad que representa a un cliente del showroom: persona natural o empresa.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Modelos;

class Cliente
{
    /**
     * @param int|null    $id
     * @param string      $tipoDocumento   CC, NIT, CE o PAS.
     * @param string      $numeroDocumento Unico en el sistema.
     * @param string      $nombre          Nombre completo o razon social.
     * @param string|null $correo
     * @param string|null $telefono
     * @param string|null $direccion
     * @param string|null $ciudad
     * @param string      $estado          activo | inactivo
     * @param string|null $fechaRegistro
     */
    public function __construct(
        public ?int    $id = null,
        public string  $tipoDocumento = 'CC',
        public string  $numeroDocumento = '',
        public string  $nombre = '',
        public ?string $correo = null,
        public ?string $telefono = null,
        public ?string $direccion = null,
        public ?string $ciudad = null,
        public string  $estado = 'activo',
        public ?string $fechaRegistro = null
    ) {
    }

    /**
     * @param array<string,mixed> $fila
     * @return self
     */
    public static function desdeFila(array $fila): self
    {
        return new self(
            id:              isset($fila['id']) ? (int) $fila['id'] : null,
            tipoDocumento:   (string) ($fila['tipo_documento'] ?? 'CC'),
            numeroDocumento: (string) ($fila['numero_documento'] ?? ''),
            nombre:          (string) ($fila['nombre'] ?? ''),
            correo:          $fila['correo'] ?? null,
            telefono:        $fila['telefono'] ?? null,
            direccion:       $fila['direccion'] ?? null,
            ciudad:          $fila['ciudad'] ?? null,
            estado:          (string) ($fila['estado'] ?? 'activo'),
            fechaRegistro:   $fila['fecha_registro'] ?? null
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function aArreglo(): array
    {
        return [
            'id'              => $this->id,
            'tipoDocumento'   => $this->tipoDocumento,
            'numeroDocumento' => $this->numeroDocumento,
            'nombre'          => $this->nombre,
            'correo'          => $this->correo,
            'telefono'        => $this->telefono,
            'direccion'       => $this->direccion,
            'ciudad'          => $this->ciudad,
            'estado'          => $this->estado,
            'fechaRegistro'   => $this->fechaRegistro,
        ];
    }

    /** @return bool Indica si el cliente puede recibir nuevos documentos. */
    public function estaActivo(): bool
    {
        return $this->estado === 'activo';
    }
}
