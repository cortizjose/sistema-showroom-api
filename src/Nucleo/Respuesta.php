<?php
/**
 * =============================================================================
 * src/Nucleo/Respuesta.php
 * -----------------------------------------------------------------------------
 * Construye y envia las respuestas HTTP en formato JSON.
 *
 * Todas las respuestas del servicio usan la misma estructura (contrato unico),
 * lo que facilita el consumo desde cualquier cliente (web, movil, escritorio):
 *
 *   {
 *     "exito":   true | false,
 *     "mensaje": "texto legible para el usuario",
 *     "datos":   { ... }   // presente solo cuando hay informacion que devolver
 *     "errores": { ... }   // presente solo cuando hay errores de validacion
 *   }
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Nucleo;

class Respuesta
{
    /**
     * Envia una respuesta JSON al cliente y finaliza la ejecucion.
     *
     * @param bool                $exito       Indica si la operacion fue satisfactoria.
     * @param string              $mensaje     Mensaje legible para el usuario.
     * @param int                 $codigoHttp  Codigo de estado HTTP (200, 401, 422...).
     * @param array<string,mixed> $datos       Informacion adicional a devolver.
     * @param array<string,mixed> $errores     Detalle de errores de validacion.
     * @return void
     */
    public static function enviar(
        bool $exito,
        string $mensaje,
        int $codigoHttp = 200,
        array $datos = [],
        array $errores = []
    ): void {
        // Se define el codigo de estado HTTP antes de imprimir cualquier salida.
        http_response_code($codigoHttp);

        // Cabeceras: tipo de contenido y politica CORS para permitir que el
        // cliente HTML de prueba consuma el servicio desde otro origen.
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

        // Cabeceras de seguridad basicas.
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        // Se arma el cuerpo respetando el contrato definido en la documentacion.
        $cuerpo = [
            'exito'   => $exito,
            'mensaje' => $mensaje,
        ];

        // Las llaves opcionales solo se incluyen cuando tienen contenido.
        if ($datos !== []) {
            $cuerpo['datos'] = $datos;
        }

        if ($errores !== []) {
            $cuerpo['errores'] = $errores;
        }

        // Marca de tiempo util para depuracion y trazabilidad.
        $cuerpo['marcaTiempo'] = date('c');

        // JSON_UNESCAPED_UNICODE conserva las tildes y la letra "n" con virgulilla.
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        exit;
    }

    /**
     * Atajo para respuestas satisfactorias (codigo 200 por defecto).
     *
     * @param string              $mensaje
     * @param array<string,mixed> $datos
     * @param int                 $codigoHttp
     * @return void
     */
    public static function exito(string $mensaje, array $datos = [], int $codigoHttp = 200): void
    {
        self::enviar(true, $mensaje, $codigoHttp, $datos);
    }

    /**
     * Atajo para respuestas de error.
     *
     * @param string              $mensaje
     * @param int                 $codigoHttp
     * @param array<string,mixed> $errores
     * @return void
     */
    public static function error(string $mensaje, int $codigoHttp = 400, array $errores = []): void
    {
        self::enviar(false, $mensaje, $codigoHttp, [], $errores);
    }
}
