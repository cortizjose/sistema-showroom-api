<?php
/**
 * =============================================================================
 * src/Controladores/ControladorBase.php
 * -----------------------------------------------------------------------------
 * Base comun de los controladores.
 *
 * Un controlador solo hace tres cosas:
 *   1. Toma los datos de la peticion HTTP.
 *   2. Llama al servicio de negocio que corresponde.
 *   3. Traduce el resultado a una respuesta HTTP.
 *
 * No valida, no consulta la base de datos y no decide reglas: para eso estan
 * los servicios. Esta separacion permite reutilizar el negocio desde otra
 * interfaz sin tocar una linea.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Controladores;

use App\Nucleo\Respuesta;
use App\Nucleo\Solicitud;

abstract class ControladorBase
{
    /**
     * Traduce el resultado de un servicio a una respuesta HTTP JSON.
     *
     * @param array<string,mixed> $resultado
     * @return void
     */
    protected function responder(array $resultado): void
    {
        Respuesta::enviar(
            (bool) $resultado['exito'],
            (string) $resultado['mensaje'],
            (int) $resultado['codigo'],
            (array) $resultado['datos'],
            (array) $resultado['errores']
        );
    }

    /**
     * Lee los parametros de consulta (?pagina=2&busqueda=mesa).
     *
     * @param Solicitud $solicitud
     * @return array<string,mixed>
     */
    protected function parametrosDeConsulta(Solicitud $solicitud): array
    {
        // En una peticion GET, Solicitud deja los parametros en el cuerpo.
        // Para POST y PUT hay que leerlos aparte de la cadena de consulta.
        $consulta = [];

        $cadena = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);

        if (is_string($cadena) && $cadena !== '') {
            parse_str($cadena, $consulta);
        }

        return $consulta === [] ? $solicitud->cuerpo() : $consulta;
    }

    /**
     * Convierte un parametro de ruta en entero.
     *
     * Devuelve 0 cuando no es numerico, lo que lleva al servicio a responder
     * "no encontrado", que es el comportamiento correcto para una ruta como
     * /api/productos/abc
     *
     * @param array<string,string> $parametros
     * @param string               $nombre
     * @return int
     */
    protected function entero(array $parametros, string $nombre = 'id'): int
    {
        $valor = $parametros[$nombre] ?? '';

        return ctype_digit((string) $valor) ? (int) $valor : 0;
    }
}
