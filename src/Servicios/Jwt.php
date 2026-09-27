<?php
/**
 * =============================================================================
 * src/Servicios/Jwt.php
 * -----------------------------------------------------------------------------
 * Generacion y verificacion de tokens JWT (JSON Web Token) con algoritmo HS256.
 *
 * Se implementa sin librerias externas para que el mecanismo quede a la vista
 * y pueda explicarse durante la sustentacion de la evidencia.
 *
 * Un JWT esta formado por tres partes separadas por punto:
 *
 *      encabezado . carga_util . firma
 *      xxxxx      . yyyyy      . zzzzz
 *
 *   1. Encabezado (header): algoritmo y tipo de token.
 *   2. Carga util (payload): datos del usuario y fechas de validez.
 *   3. Firma (signature): HMAC-SHA256 de las dos partes anteriores usando una
 *      clave secreta que solo conoce el servidor.
 *
 * IMPORTANTE: el contenido de un JWT esta CODIFICADO en Base64Url, no cifrado.
 * Cualquiera puede leerlo. Por eso nunca deben incluirse datos sensibles como
 * contrasenas. Lo que la firma garantiza es que el token no fue ALTERADO.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Servicios;

class Jwt
{
    /** @var string Clave secreta con la que se firma el token. */
    private string $secreto;

    /** @var int Minutos de vigencia del token. */
    private int $minutosVigencia;

    /** @var string Identificador del emisor del token. */
    private string $emisor;

    /**
     * @param string $secreto         Clave secreta (debe ser larga y aleatoria).
     * @param int    $minutosVigencia Duracion del token en minutos.
     * @param string $emisor          Nombre del sistema que emite el token.
     */
    public function __construct(string $secreto, int $minutosVigencia = 60, string $emisor = 'servicio-web')
    {
        $this->secreto         = $secreto;
        $this->minutosVigencia = $minutosVigencia;
        $this->emisor          = $emisor;
    }

    /**
     * Genera un token firmado para un usuario.
     *
     * @param int                 $idUsuario     Identificador del usuario.
     * @param string              $nombreUsuario Nombre de usuario.
     * @param array<string,mixed> $extra         Datos adicionales opcionales.
     * @return array{token:string, expiraEn:int, expiraEnTexto:string}
     */
    public function generar(int $idUsuario, string $nombreUsuario, array $extra = []): array
    {
        $ahora   = time();
        $expira  = $ahora + ($this->minutosVigencia * 60);

        // 1. Encabezado: algoritmo de firma y tipo de token.
        $encabezado = [
            'alg' => 'HS256',  // HMAC con SHA-256
            'typ' => 'JWT',
        ];

        // 2. Carga util. Se emplean los nombres estandar definidos en el
        //    RFC 7519 para que el token sea interoperable:
        //      iss (issuer)     : quien emite el token
        //      sub (subject)    : a quien pertenece
        //      iat (issued at)  : momento de emision
        //      exp (expiration) : momento de expiracion
        $cargaUtil = array_merge([
            'iss'           => $this->emisor,
            'sub'           => $idUsuario,
            'nombreUsuario' => $nombreUsuario,
            'iat'           => $ahora,
            'exp'           => $expira,
        ], $extra);

        // 3. Se codifican las dos primeras partes en Base64Url.
        $encabezadoCodificado = $this->base64UrlCodificar(json_encode($encabezado, JSON_UNESCAPED_UNICODE));
        $cargaUtilCodificada  = $this->base64UrlCodificar(json_encode($cargaUtil, JSON_UNESCAPED_UNICODE));

        // 4. Se calcula la firma sobre "encabezado.cargaUtil".
        $firma = $this->firmar($encabezadoCodificado . '.' . $cargaUtilCodificada);

        return [
            'token'         => $encabezadoCodificado . '.' . $cargaUtilCodificada . '.' . $firma,
            'expiraEn'      => $expira,
            'expiraEnTexto' => date('c', $expira),
        ];
    }

    /**
     * Verifica un token y devuelve su carga util.
     *
     * Comprobaciones realizadas:
     *   1. El token tiene exactamente tres partes.
     *   2. La firma recalculada coincide con la recibida.
     *   3. El token no ha expirado.
     *
     * @param string $token Token recibido del cliente.
     * @return array<string,mixed>|null Carga util, o null si el token no es valido.
     */
    public function verificar(string $token): ?array
    {
        $partes = explode('.', $token);

        // 1. Estructura: debe haber tres segmentos.
        if (count($partes) !== 3) {
            return null;
        }

        [$encabezadoCodificado, $cargaUtilCodificada, $firmaRecibida] = $partes;

        // 2. Firma: se recalcula con la clave secreta del servidor.
        $firmaEsperada = $this->firmar($encabezadoCodificado . '.' . $cargaUtilCodificada);

        // hash_equals() compara en tiempo constante. Una comparacion normal
        // con "===" permitiria un ataque de temporizacion (timing attack).
        if (!hash_equals($firmaEsperada, $firmaRecibida)) {
            return null;
        }

        // 3. Carga util: se decodifica y se valida la expiracion.
        $cargaUtil = json_decode($this->base64UrlDecodificar($cargaUtilCodificada), true);

        if (!is_array($cargaUtil)) {
            return null;
        }

        if (!isset($cargaUtil['exp']) || (int) $cargaUtil['exp'] < time()) {
            return null;  // Token expirado.
        }

        return $cargaUtil;
    }

    /**
     * Calcula la firma HMAC-SHA256 de una cadena.
     *
     * @param string $contenido Cadena "encabezado.cargaUtil".
     * @return string Firma en Base64Url.
     */
    private function firmar(string $contenido): string
    {
        // El cuarto parametro en true devuelve la firma en binario crudo,
        // que luego se codifica en Base64Url.
        $firmaBinaria = hash_hmac('sha256', $contenido, $this->secreto, true);

        return $this->base64UrlCodificar($firmaBinaria);
    }

    /**
     * Codifica en Base64Url (variante de Base64 segura para URLs).
     *
     * Diferencias con Base64 estandar:
     *   "+" se reemplaza por "-", "/" por "_" y se eliminan los "=" finales.
     *
     * @param string $datos
     * @return string
     */
    private function base64UrlCodificar(string $datos): string
    {
        return rtrim(strtr(base64_encode($datos), '+/', '-_'), '=');
    }

    /**
     * Decodifica una cadena Base64Url.
     *
     * @param string $datos
     * @return string
     */
    private function base64UrlDecodificar(string $datos): string
    {
        // Se restauran los caracteres originales y el relleno "=".
        $relleno = strlen($datos) % 4;

        if ($relleno !== 0) {
            $datos .= str_repeat('=', 4 - $relleno);
        }

        return base64_decode(strtr($datos, '-_', '+/')) ?: '';
    }
}
