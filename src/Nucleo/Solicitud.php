<?php
/**
 * =============================================================================
 * src/Nucleo/Solicitud.php
 * -----------------------------------------------------------------------------
 * Representa la peticion HTTP que llega al servicio web.
 *
 * Encapsular la peticion en un objeto evita que los controladores accedan
 * directamente a las superglobales ($_SERVER, $_POST, php://input), lo que
 * facilita las pruebas y mantiene el codigo ordenado.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Nucleo;

class Solicitud
{
    /** @var string Metodo HTTP en mayusculas: GET, POST, PUT, DELETE... */
    private string $metodo;

    /** @var string Ruta solicitada, sin parametros de consulta. Ej: /api/login */
    private string $ruta;

    /** @var array<string,mixed> Cuerpo de la peticion ya decodificado. */
    private array $cuerpo;

    /** @var array<string,string> Cabeceras HTTP normalizadas a minusculas. */
    private array $cabeceras;

    /**
     * @param string               $metodo    Metodo HTTP.
     * @param string               $ruta      Ruta solicitada.
     * @param array<string,mixed>  $cuerpo    Datos enviados por el cliente.
     * @param array<string,string> $cabeceras Cabeceras HTTP.
     */
    public function __construct(string $metodo, string $ruta, array $cuerpo, array $cabeceras)
    {
        $this->metodo    = strtoupper($metodo);
        $this->ruta      = $ruta;
        $this->cuerpo    = $cuerpo;
        $this->cabeceras = $cabeceras;
    }

    /**
     * Construye el objeto Solicitud a partir del entorno real de PHP.
     *
     * @return self
     */
    public static function desdeGlobales(): self
    {
        // 1. Metodo HTTP.
        $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // 2. Ruta: se elimina la cadena de consulta (?clave=valor) y la barra
        //    final para que "/api/login/" y "/api/login" sean equivalentes.
        $rutaCompleta = $_SERVER['REQUEST_URI'] ?? '/';
        $ruta = parse_url($rutaCompleta, PHP_URL_PATH) ?: '/';
        $ruta = rtrim($ruta, '/');
        if ($ruta === '') {
            $ruta = '/';
        }

        // 3. Cuerpo de la peticion.
        $cuerpo = self::leerCuerpo();

        // 4. Cabeceras HTTP.
        $cabeceras = self::leerCabeceras();

        return new self($metodo, $ruta, $cuerpo, $cabeceras);
    }

    /**
     * Lee y decodifica el cuerpo de la peticion.
     *
     * El servicio acepta dos formatos:
     *   - application/json            (formato principal de la API REST)
     *   - application/x-www-form-urlencoded / multipart-form-data (formularios)
     *
     * @return array<string,mixed>
     */
    private static function leerCuerpo(): array
    {
        $tipoContenido = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

        // Caso 1: JSON. Es el formato natural de un servicio web REST.
        if (str_contains(strtolower($tipoContenido), 'application/json')) {
            $crudo = file_get_contents('php://input') ?: '';

            if (trim($crudo) === '') {
                return [];
            }

            $decodificado = json_decode($crudo, true);

            // Si el JSON viene mal formado se devuelve un arreglo vacio; la
            // capa de validacion se encargara de reportar los campos faltantes.
            return is_array($decodificado) ? $decodificado : [];
        }

        // Caso 2: formulario HTML tradicional.
        if (!empty($_POST)) {
            return $_POST;
        }

        // Caso 3: sin cuerpo (tipico de GET). Se devuelven los parametros GET.
        return $_GET ?? [];
    }

    /**
     * Obtiene las cabeceras HTTP con los nombres en minusculas.
     *
     * @return array<string,string>
     */
    private static function leerCabeceras(): array
    {
        $cabeceras = [];

        // getallheaders() no existe en todos los SAPI (por ejemplo, CLI),
        // por eso se reconstruyen desde $_SERVER como alternativa.
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $nombre => $valor) {
                $cabeceras[strtolower($nombre)] = $valor;
            }
            return $cabeceras;
        }

        foreach ($_SERVER as $clave => $valor) {
            if (str_starts_with($clave, 'HTTP_')) {
                // HTTP_AUTHORIZATION  ->  authorization
                $nombre = str_replace('_', '-', strtolower(substr($clave, 5)));
                $cabeceras[$nombre] = (string) $valor;
            }
        }

        return $cabeceras;
    }

    /** @return string Metodo HTTP de la peticion. */
    public function metodo(): string
    {
        return $this->metodo;
    }

    /** @return string Ruta solicitada. */
    public function ruta(): string
    {
        return $this->ruta;
    }

    /** @return array<string,mixed> Cuerpo completo de la peticion. */
    public function cuerpo(): array
    {
        return $this->cuerpo;
    }

    /**
     * Devuelve un campo del cuerpo de la peticion.
     *
     * @param string $campo      Nombre del campo.
     * @param mixed  $porDefecto Valor si el campo no existe.
     * @return mixed
     */
    public function campo(string $campo, mixed $porDefecto = null): mixed
    {
        return $this->cuerpo[$campo] ?? $porDefecto;
    }

    /**
     * Devuelve una cabecera HTTP.
     *
     * @param string      $nombre Nombre de la cabecera (sin distinguir mayusculas).
     * @param string|null $porDefecto
     * @return string|null
     */
    public function cabecera(string $nombre, ?string $porDefecto = null): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? $porDefecto;
    }

    /**
     * Extrae el token del esquema "Authorization: Bearer <token>".
     *
     * @return string|null Token encontrado o null si no viene.
     */
    public function tokenPortador(): ?string
    {
        $autorizacion = $this->cabecera('authorization');

        if ($autorizacion === null) {
            return null;
        }

        // Se valida el formato exacto "Bearer <token>".
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($autorizacion), $coincidencias) === 1) {
            return $coincidencias[1];
        }

        return null;
    }

    /**
     * Direccion IP del cliente. Se utiliza para registrar los intentos de
     * autenticacion en la bitacora de seguridad.
     *
     * @return string
     */
    public function direccionIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
