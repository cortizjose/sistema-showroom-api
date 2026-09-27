<?php
/**
 * =============================================================================
 * src/Nucleo/Enrutador.php
 * -----------------------------------------------------------------------------
 * Enrutador con soporte de parametros de ruta y de middleware.
 *
 * Evoluciona el enrutador de la evidencia GA7-220501096-AA5-EV01, que solo
 * reconocia rutas exactas. Aqui se agregan dos capacidades que exige un API
 * de varios recursos:
 *
 *   1. PARAMETROS DE RUTA
 *      La ruta "/api/productos/{id}" reconoce "/api/productos/47" y entrega
 *      ['id' => '47'] a la accion.
 *
 *   2. MIDDLEWARE
 *      Cada ruta declara que debe cumplirse ANTES de ejecutarla: exigir un
 *      token valido, exigir un rol determinado, o nada.
 *
 * Es el patron Front Controller: todas las peticiones entran por
 * public/index.php y desde alli se distribuyen.
 *
 * @package SistemaShowroomApi
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Nucleo;

class Enrutador
{
    /**
     * Rutas registradas, agrupadas por metodo HTTP.
     *
     * Cada entrada tiene la forma:
     *   [
     *     'patron'      => '#^/api/productos/(?P<id>[^/]+)$#',
     *     'ruta'        => '/api/productos/{id}',
     *     'accion'      => callable,
     *     'middleware'  => ['autenticado', 'rol:administrador'],
     *     'descripcion' => 'Consulta un producto por su identificador',
     *   ]
     *
     * @var array<string, array<int, array<string,mixed>>>
     */
    private array $rutas = [];

    /**
     * Middleware disponibles, indexados por nombre.
     *
     * @var array<string, callable>
     */
    private array $middleware = [];

    /**
     * Contexto que los middleware dejan disponible para los controladores.
     * Aqui se guarda, por ejemplo, el usuario autenticado.
     *
     * @var array<string,mixed>
     */
    private array $contexto = [];

    // =========================================================================
    // REGISTRO DE RUTAS
    // =========================================================================

    /**
     * Registra una ruta GET.
     *
     * @param string        $ruta        Ruta, admite parametros: /api/productos/{id}
     * @param callable      $accion      Accion que atiende la peticion.
     * @param array<string> $middleware  Requisitos previos.
     * @param string        $descripcion Texto para la documentacion en linea.
     * @return void
     */
    public function get(string $ruta, callable $accion, array $middleware = [], string $descripcion = ''): void
    {
        $this->registrar('GET', $ruta, $accion, $middleware, $descripcion);
    }

    /**
     * Registra una ruta POST.
     *
     * @param string        $ruta
     * @param callable      $accion
     * @param array<string> $middleware
     * @param string        $descripcion
     * @return void
     */
    public function post(string $ruta, callable $accion, array $middleware = [], string $descripcion = ''): void
    {
        $this->registrar('POST', $ruta, $accion, $middleware, $descripcion);
    }

    /**
     * Registra una ruta PUT (reemplazo completo de un recurso).
     *
     * @param string        $ruta
     * @param callable      $accion
     * @param array<string> $middleware
     * @param string        $descripcion
     * @return void
     */
    public function put(string $ruta, callable $accion, array $middleware = [], string $descripcion = ''): void
    {
        $this->registrar('PUT', $ruta, $accion, $middleware, $descripcion);
    }

    /**
     * Registra una ruta PATCH (modificacion parcial de un recurso).
     *
     * @param string        $ruta
     * @param callable      $accion
     * @param array<string> $middleware
     * @param string        $descripcion
     * @return void
     */
    public function patch(string $ruta, callable $accion, array $middleware = [], string $descripcion = ''): void
    {
        $this->registrar('PATCH', $ruta, $accion, $middleware, $descripcion);
    }

    /**
     * Registra una ruta DELETE.
     *
     * @param string        $ruta
     * @param callable      $accion
     * @param array<string> $middleware
     * @param string        $descripcion
     * @return void
     */
    public function delete(string $ruta, callable $accion, array $middleware = [], string $descripcion = ''): void
    {
        $this->registrar('DELETE', $ruta, $accion, $middleware, $descripcion);
    }

    /**
     * Agrega una ruta a la tabla, convirtiendo sus parametros en una
     * expresion regular.
     *
     * Ejemplo de conversion:
     *   /api/cotizaciones/{id}/estado
     *   se convierte en
     *   #^/api/cotizaciones/(?P<id>[^/]+)/estado$#
     *
     * @param string        $metodo
     * @param string        $ruta
     * @param callable      $accion
     * @param array<string> $middleware
     * @param string        $descripcion
     * @return void
     */
    private function registrar(
        string $metodo,
        string $ruta,
        callable $accion,
        array $middleware,
        string $descripcion
    ): void {
        // La ruta se arma segmento por segmento. Cada segmento es, o bien un
        // parametro {nombre} que se convierte en un grupo con nombre, o bien
        // texto literal que se escapa.
        //
        // Se hace asi, y no con una sola expresion regular sobre la ruta
        // completa, porque dependia de como preg_quote() tratara las llaves:
        // era correcto pero fragil de leer y de mantener.
        $segmentos = explode('/', trim($ruta, '/'));
        $partes    = [];

        foreach ($segmentos as $segmento) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segmento, $m) === 1) {
                // Parametro de ruta: captura cualquier cosa que no sea barra.
                $partes[] = '(?P<' . $m[1] . '>[^/]+)';
            } else {
                // Texto literal.
                $partes[] = preg_quote($segmento, '#');
            }
        }

        $patron = '/' . implode('/', $partes);

        $this->rutas[strtoupper($metodo)][] = [
            'patron'      => '#^' . $patron . '$#',
            'ruta'        => $ruta,
            'accion'      => $accion,
            'middleware'  => $middleware,
            'descripcion' => $descripcion,
        ];
    }

    /**
     * Registra un middleware reutilizable.
     *
     * El middleware recibe (Solicitud $solicitud, string $argumento) y debe
     * devolver true para continuar. Si devuelve false, o responde el mismo,
     * la peticion se detiene.
     *
     * @param string   $nombre  Nombre con el que se invoca en las rutas.
     * @param callable $accion  Funcion que realiza la comprobacion.
     * @return void
     */
    public function middleware(string $nombre, callable $accion): void
    {
        $this->middleware[$nombre] = $accion;
    }

    /**
     * Guarda un valor en el contexto de la peticion.
     * Lo usan los middleware para dejar datos a los controladores.
     *
     * @param string $clave
     * @param mixed  $valor
     * @return void
     */
    public function guardarEnContexto(string $clave, mixed $valor): void
    {
        $this->contexto[$clave] = $valor;
    }

    /**
     * Recupera un valor del contexto de la peticion.
     *
     * @param string $clave
     * @param mixed  $porDefecto
     * @return mixed
     */
    public function contexto(string $clave, mixed $porDefecto = null): mixed
    {
        return $this->contexto[$clave] ?? $porDefecto;
    }

    // =========================================================================
    // DESPACHO
    // =========================================================================

    /**
     * Busca la ruta solicitada, ejecuta sus middleware y llama a la accion.
     *
     * Respuestas de error estandar:
     *   404 Not Found          : ninguna ruta coincide.
     *   405 Method Not Allowed : la ruta existe con otro verbo HTTP.
     *
     * @param Solicitud $solicitud
     * @return void
     */
    public function despachar(Solicitud $solicitud): void
    {
        $metodo = $solicitud->metodo();
        $ruta   = $solicitud->ruta();

        // Peticion "preflight" de CORS: el navegador pregunta antes de enviar.
        if ($metodo === 'OPTIONS') {
            http_response_code(204);
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            exit;
        }

        // 1. Busqueda de coincidencia exacta de metodo + patron.
        foreach ($this->rutas[$metodo] ?? [] as $definicion) {
            if (preg_match($definicion['patron'], $ruta, $coincidencias) !== 1) {
                continue;
            }

            // Se extraen solo los grupos con nombre: los parametros de ruta.
            $parametros = [];
            foreach ($coincidencias as $clave => $valor) {
                if (is_string($clave)) {
                    $parametros[$clave] = $valor;
                }
            }

            // 2. Ejecucion de los middleware declarados por la ruta.
            foreach ($definicion['middleware'] as $declaracion) {
                // Formato "nombre" o "nombre:argumento".
                [$nombre, $argumento] = array_pad(explode(':', $declaracion, 2), 2, '');

                if (!isset($this->middleware[$nombre])) {
                    Respuesta::error("Middleware no registrado: $nombre", 500);
                }

                // Si el middleware devuelve false, detiene la peticion.
                if (($this->middleware[$nombre])($solicitud, $argumento) === false) {
                    return;
                }
            }

            // 3. Ejecucion de la accion del controlador.
            ($definicion['accion'])($solicitud, $parametros);
            return;
        }

        // 4. La ruta existe pero con otro metodo HTTP.
        $permitidos = [];
        foreach ($this->rutas as $otroMetodo => $definiciones) {
            foreach ($definiciones as $definicion) {
                if (preg_match($definicion['patron'], $ruta) === 1) {
                    $permitidos[] = $otroMetodo;
                }
            }
        }

        if ($permitidos !== []) {
            $lista = implode(', ', array_unique($permitidos));
            header("Allow: $lista");
            Respuesta::error(
                "El metodo $metodo no esta permitido para la ruta $ruta. Use: $lista.",
                405
            );
        }

        // 5. No existe ninguna coincidencia.
        Respuesta::error("La ruta solicitada no existe: $metodo $ruta", 404);
    }

    /**
     * Devuelve el catalogo de rutas registradas.
     * Alimenta el endpoint GET /api, que documenta la API en linea.
     *
     * @return array<int, array<string,mixed>>
     */
    public function catalogo(): array
    {
        $catalogo = [];

        foreach ($this->rutas as $metodo => $definiciones) {
            foreach ($definiciones as $definicion) {
                $catalogo[] = [
                    'metodo'        => $metodo,
                    'ruta'          => $definicion['ruta'],
                    'descripcion'   => $definicion['descripcion'],
                    'requiereToken' => in_array('autenticado', $definicion['middleware'], true)
                                       || $this->exigeRol($definicion['middleware']),
                    'roles'         => $this->rolesExigidos($definicion['middleware']),
                ];
            }
        }

        // Se ordena por ruta para que la documentacion sea legible.
        usort($catalogo, static fn ($a, $b) => [$a['ruta'], $a['metodo']] <=> [$b['ruta'], $b['metodo']]);

        return $catalogo;
    }

    /**
     * Indica si la lista de middleware exige algun rol.
     *
     * @param array<string> $middleware
     * @return bool
     */
    private function exigeRol(array $middleware): bool
    {
        foreach ($middleware as $declaracion) {
            if (str_starts_with($declaracion, 'rol:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrae los roles exigidos por una lista de middleware.
     *
     * @param array<string> $middleware
     * @return array<int,string>
     */
    private function rolesExigidos(array $middleware): array
    {
        $roles = [];

        foreach ($middleware as $declaracion) {
            if (str_starts_with($declaracion, 'rol:')) {
                foreach (explode('|', substr($declaracion, 4)) as $rol) {
                    $roles[] = $rol;
                }
            }
        }

        return $roles;
    }
}
