<?php
/**
 * =============================================================================
 * src/Servicios/ServicioBase.php
 * -----------------------------------------------------------------------------
 * Base comun de los servicios de negocio.
 *
 * Todos los servicios devuelven un resultado con la misma forma, para que el
 * controlador pueda traducirlo a HTTP sin conocer el detalle de cada caso:
 *
 *   [
 *     'exito'   => bool,    resultado de la operacion
 *     'codigo'  => int,     codigo HTTP sugerido
 *     'mensaje' => string,  texto legible
 *     'datos'   => array,   informacion devuelta
 *     'errores' => array,   detalle de errores de validacion
 *   ]
 *
 * Los servicios no conocen HTTP ni SQL: solo aplican reglas de negocio.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Servicios;

abstract class ServicioBase
{
    /**
     * Construye un resultado satisfactorio.
     *
     * @param string              $mensaje
     * @param array<string,mixed> $datos
     * @param int                 $codigo
     * @return array<string,mixed>
     */
    protected function exito(string $mensaje, array $datos = [], int $codigo = 200): array
    {
        return [
            'exito'   => true,
            'codigo'  => $codigo,
            'mensaje' => $mensaje,
            'datos'   => $datos,
            'errores' => [],
        ];
    }

    /**
     * Construye un resultado de error.
     *
     * @param string              $mensaje
     * @param int                 $codigo
     * @param array<string,mixed> $errores
     * @return array<string,mixed>
     */
    protected function error(string $mensaje, int $codigo = 400, array $errores = []): array
    {
        return [
            'exito'   => false,
            'codigo'  => $codigo,
            'mensaje' => $mensaje,
            'datos'   => [],
            'errores' => $errores,
        ];
    }

    /**
     * Error de validacion. Codigo 422: la peticion esta bien formada pero
     * los datos no cumplen las reglas.
     *
     * @param array<string,mixed> $errores
     * @return array<string,mixed>
     */
    protected function errorDeValidacion(array $errores): array
    {
        return $this->error(
            'Los datos enviados no son validos. Verifique los campos indicados.',
            422,
            $errores
        );
    }

    /**
     * Error de recurso inexistente.
     *
     * @param string $recurso
     * @return array<string,mixed>
     */
    protected function noEncontrado(string $recurso): array
    {
        return $this->error("No se encontro $recurso.", 404);
    }

    /**
     * Error de conflicto: el recurso ya existe o la operacion choca con el
     * estado actual del sistema.
     *
     * @param string              $mensaje
     * @param array<string,mixed> $errores
     * @return array<string,mixed>
     */
    protected function conflicto(string $mensaje, array $errores = []): array
    {
        return $this->error($mensaje, 409, $errores);
    }
}
