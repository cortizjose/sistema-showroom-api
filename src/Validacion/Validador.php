<?php
/**
 * =============================================================================
 * src/Validacion/Validador.php
 * -----------------------------------------------------------------------------
 * Valida y normaliza los datos que envia el cliente.
 *
 * Principio aplicado: nunca confiar en la entrada del usuario. Antes de que
 * un dato llegue a la base de datos se comprueba su presencia, su tipo, su
 * longitud y su formato.
 *
 * Cada metodo devuelve un arreglo ['campo' => 'mensaje']. Vacio significa
 * que la validacion fue satisfactoria.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Validacion;

class Validador
{
    /** @var int Longitud minima exigida a las contrasenas. */
    private int $longitudMinimaClave;

    /** @var float Descuento maximo permitido por linea. */
    private float $descuentoMaximo;

    /**
     * @param int   $longitudMinimaClave
     * @param float $descuentoMaximo
     */
    public function __construct(int $longitudMinimaClave = 8, float $descuentoMaximo = 20.0)
    {
        $this->longitudMinimaClave = $longitudMinimaClave;
        $this->descuentoMaximo     = $descuentoMaximo;
    }

    // =========================================================================
    // SEGURIDAD
    // =========================================================================

    /**
     * Valida los datos de registro de un usuario.
     *
     * @param array<string,mixed> $datos
     * @param array<int,string>   $rolesValidos
     * @return array<string,string>
     */
    public function validarUsuario(array $datos, array $rolesValidos): array
    {
        $errores = [];

        $usuario = $this->texto($datos['nombre_usuario'] ?? '');
        if ($usuario === '') {
            $errores['nombre_usuario'] = 'El nombre de usuario es obligatorio.';
        } elseif (mb_strlen($usuario) < 4 || mb_strlen($usuario) > 50) {
            $errores['nombre_usuario'] = 'Debe tener entre 4 y 50 caracteres.';
        } elseif (preg_match('/^[a-zA-Z0-9._-]+$/', $usuario) !== 1) {
            $errores['nombre_usuario'] = 'Solo admite letras, numeros, punto, guion y guion bajo.';
        }

        $errores += $this->validarCorreo($datos['correo'] ?? '', 'correo', true);

        $clave = (string) ($datos['contrasena'] ?? '');
        if ($clave === '') {
            $errores['contrasena'] = 'La contrasena es obligatoria.';
        } else {
            $problema = $this->fortalezaDeClave($clave);
            if ($problema !== null) {
                $errores['contrasena'] = $problema;
            }
        }

        if (array_key_exists('confirmar_contrasena', $datos)
            && (string) $datos['confirmar_contrasena'] !== $clave) {
            $errores['confirmar_contrasena'] = 'La confirmacion no coincide con la contrasena.';
        }

        // El rol es obligatorio al crear usuarios desde la administracion.
        $rol = $this->texto($datos['rol'] ?? '');
        if ($rol !== '' && !in_array($rol, $rolesValidos, true)) {
            $errores['rol'] = 'Rol no valido. Opciones: ' . implode(', ', $rolesValidos) . '.';
        }

        return $errores;
    }

    /**
     * Valida los datos de inicio de sesion.
     *
     * Deliberadamente laxa: solo comprueba presencia. Validar el formato
     * permitiria a un atacante deducir como son las credenciales validas.
     *
     * @param array<string,mixed> $datos
     * @return array<string,string>
     */
    public function validarLogin(array $datos): array
    {
        $errores = [];

        $identificador = $this->texto(
            $datos['usuario'] ?? $datos['nombre_usuario'] ?? $datos['correo'] ?? ''
        );

        if ($identificador === '') {
            $errores['usuario'] = 'Debe indicar el nombre de usuario o el correo electronico.';
        }

        if ((string) ($datos['contrasena'] ?? '') === '') {
            $errores['contrasena'] = 'La contrasena es obligatoria.';
        }

        return $errores;
    }

    /**
     * Valida un cambio de contrasena.
     *
     * @param array<string,mixed> $datos
     * @return array<string,string>
     */
    public function validarCambioDeClave(array $datos): array
    {
        $errores = [];

        if ((string) ($datos['contrasena_actual'] ?? '') === '') {
            $errores['contrasena_actual'] = 'Debe indicar la contrasena actual.';
        }

        $nueva = (string) ($datos['contrasena_nueva'] ?? '');
        if ($nueva === '') {
            $errores['contrasena_nueva'] = 'Debe indicar la contrasena nueva.';
        } else {
            $problema = $this->fortalezaDeClave($nueva);
            if ($problema !== null) {
                $errores['contrasena_nueva'] = $problema;
            }
        }

        return $errores;
    }

    // =========================================================================
    // CATALOGO
    // =========================================================================

    /**
     * Valida los datos de un producto.
     *
     * @param array<string,mixed> $datos
     * @return array<string,string>
     */
    public function validarProducto(array $datos): array
    {
        $errores = [];

        $codigo = $this->texto($datos['codigo'] ?? '');
        if ($codigo === '') {
            $errores['codigo'] = 'El codigo del producto es obligatorio.';
        } elseif (mb_strlen($codigo) > 30) {
            $errores['codigo'] = 'El codigo no puede superar los 30 caracteres.';
        }

        $nombre = $this->texto($datos['nombre'] ?? '');
        if ($nombre === '') {
            $errores['nombre'] = 'El nombre del producto es obligatorio.';
        } elseif (mb_strlen($nombre) > 150) {
            $errores['nombre'] = 'El nombre no puede superar los 150 caracteres.';
        }

        $errores += $this->validarNumero(
            $datos['precio_unitario'] ?? null,
            'precio_unitario',
            'El precio unitario',
            minimo: 0,
            obligatorio: true
        );

        if (array_key_exists('stock', $datos)) {
            $errores += $this->validarEntero($datos['stock'], 'stock', 'El stock', minimo: 0);
        }

        if (array_key_exists('stock_minimo', $datos)) {
            $errores += $this->validarEntero($datos['stock_minimo'], 'stock_minimo', 'El stock minimo', minimo: 0);
        }

        return $errores;
    }

    /**
     * Valida los datos de una categoria.
     *
     * @param array<string,mixed> $datos
     * @return array<string,string>
     */
    public function validarCategoria(array $datos): array
    {
        $errores = [];

        $nombre = $this->texto($datos['nombre'] ?? '');
        if ($nombre === '') {
            $errores['nombre'] = 'El nombre de la categoria es obligatorio.';
        } elseif (mb_strlen($nombre) > 80) {
            $errores['nombre'] = 'El nombre no puede superar los 80 caracteres.';
        }

        return $errores;
    }

    // =========================================================================
    // CLIENTES
    // =========================================================================

    /**
     * Valida los datos de un cliente.
     *
     * @param array<string,mixed> $datos
     * @return array<string,string>
     */
    public function validarCliente(array $datos): array
    {
        $errores = [];

        $tiposValidos = ['CC', 'NIT', 'CE', 'PAS'];
        $tipo = strtoupper($this->texto($datos['tipo_documento'] ?? 'CC'));
        if (!in_array($tipo, $tiposValidos, true)) {
            $errores['tipo_documento'] = 'Tipo de documento no valido. Opciones: ' . implode(', ', $tiposValidos) . '.';
        }

        $documento = $this->texto($datos['numero_documento'] ?? '');
        if ($documento === '') {
            $errores['numero_documento'] = 'El numero de documento es obligatorio.';
        } elseif (preg_match('/^[0-9A-Za-z-]{5,30}$/', $documento) !== 1) {
            $errores['numero_documento'] = 'Debe tener entre 5 y 30 caracteres alfanumericos.';
        }

        $nombre = $this->texto($datos['nombre'] ?? '');
        if ($nombre === '') {
            $errores['nombre'] = 'El nombre o razon social es obligatorio.';
        } elseif (mb_strlen($nombre) > 150) {
            $errores['nombre'] = 'El nombre no puede superar los 150 caracteres.';
        }

        // El correo es opcional, pero si viene debe tener formato valido.
        if (!empty($datos['correo'])) {
            $errores += $this->validarCorreo($datos['correo'], 'correo', false);
        }

        if (!empty($datos['telefono']) && mb_strlen($this->texto($datos['telefono'])) > 30) {
            $errores['telefono'] = 'El telefono no puede superar los 30 caracteres.';
        }

        return $errores;
    }

    // =========================================================================
    // DOCUMENTOS COMERCIALES
    // =========================================================================

    /**
     * Valida la cabecera y las lineas de una cotizacion o un pedido.
     *
     * @param array<string,mixed> $datos
     * @return array<string,string>
     */
    public function validarDocumento(array $datos): array
    {
        $errores = [];

        $errores += $this->validarEntero(
            $datos['cliente_id'] ?? null,
            'cliente_id',
            'El cliente',
            minimo: 1
        );

        // Las lineas son obligatorias: un documento sin detalle no tiene sentido.
        $lineas = $datos['lineas'] ?? null;

        if (!is_array($lineas) || $lineas === []) {
            $errores['lineas'] = 'Debe incluir al menos una linea de detalle.';
            return $errores;
        }

        if (count($lineas) > 100) {
            $errores['lineas'] = 'Un documento no puede tener mas de 100 lineas.';
            return $errores;
        }

        // Cada linea se valida por separado y el error indica su posicion.
        foreach ($lineas as $indice => $linea) {
            $posicion = $indice + 1;

            if (!is_array($linea)) {
                $errores["lineas.$posicion"] = 'La linea no tiene el formato esperado.';
                continue;
            }

            if (($linea['producto_id'] ?? null) === null
                || (int) $linea['producto_id'] < 1) {
                $errores["lineas.$posicion.producto_id"] = 'Debe indicar un producto valido.';
            }

            $cantidad = $linea['cantidad'] ?? null;
            if ($cantidad === null || !is_numeric($cantidad) || (int) $cantidad < 1) {
                $errores["lineas.$posicion.cantidad"] = 'La cantidad debe ser un entero mayor que cero.';
            }

            // El descuento es opcional; si viene, se limita por politica.
            if (array_key_exists('descuento_porcentaje', $linea)) {
                $descuento = $linea['descuento_porcentaje'];

                if (!is_numeric($descuento) || (float) $descuento < 0) {
                    $errores["lineas.$posicion.descuento_porcentaje"] = 'El descuento no puede ser negativo.';
                } elseif ((float) $descuento > $this->descuentoMaximo) {
                    $errores["lineas.$posicion.descuento_porcentaje"] =
                        "El descuento no puede superar el {$this->descuentoMaximo} %.";
                }
            }
        }

        return $errores;
    }

    /**
     * Valida un cambio de estado.
     *
     * @param array<string,mixed> $datos
     * @param array<int,string>   $estadosValidos
     * @return array<string,string>
     */
    public function validarCambioDeEstado(array $datos, array $estadosValidos): array
    {
        $errores = [];

        $estado = $this->texto($datos['estado'] ?? '');

        if ($estado === '') {
            $errores['estado'] = 'Debe indicar el nuevo estado.';
        } elseif (!in_array($estado, $estadosValidos, true)) {
            $errores['estado'] = 'Estado no valido. Opciones: ' . implode(', ', $estadosValidos) . '.';
        }

        return $errores;
    }

    // =========================================================================
    // AUXILIARES
    // =========================================================================

    /**
     * Valida un correo electronico.
     *
     * @param mixed  $valor
     * @param string $campo
     * @param bool   $obligatorio
     * @return array<string,string>
     */
    private function validarCorreo(mixed $valor, string $campo, bool $obligatorio): array
    {
        $correo = $this->texto($valor);

        if ($correo === '') {
            return $obligatorio ? [$campo => 'El correo electronico es obligatorio.'] : [];
        }

        if (mb_strlen($correo) > 150) {
            return [$campo => 'El correo no puede superar los 150 caracteres.'];
        }

        // filter_var es la forma estandar de validar correos en PHP y evita
        // expresiones regulares propensas a errores.
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            return [$campo => 'El formato del correo electronico no es valido.'];
        }

        return [];
    }

    /**
     * Valida un numero decimal.
     *
     * @param mixed      $valor
     * @param string     $campo
     * @param string     $etiqueta
     * @param float|null $minimo
     * @param bool       $obligatorio
     * @return array<string,string>
     */
    private function validarNumero(
        mixed $valor,
        string $campo,
        string $etiqueta,
        ?float $minimo = null,
        bool $obligatorio = false
    ): array {
        if ($valor === null || $valor === '') {
            return $obligatorio ? [$campo => "$etiqueta es obligatorio."] : [];
        }

        if (!is_numeric($valor)) {
            return [$campo => "$etiqueta debe ser un numero."];
        }

        if ($minimo !== null && (float) $valor < $minimo) {
            return [$campo => "$etiqueta no puede ser menor que $minimo."];
        }

        return [];
    }

    /**
     * Valida un numero entero.
     *
     * @param mixed    $valor
     * @param string   $campo
     * @param string   $etiqueta
     * @param int|null $minimo
     * @return array<string,string>
     */
    private function validarEntero(mixed $valor, string $campo, string $etiqueta, ?int $minimo = null): array
    {
        if ($valor === null || $valor === '') {
            return [$campo => "$etiqueta es obligatorio."];
        }

        if (!is_numeric($valor) || (float) $valor != (int) $valor) {
            return [$campo => "$etiqueta debe ser un numero entero."];
        }

        if ($minimo !== null && (int) $valor < $minimo) {
            return [$campo => "$etiqueta no puede ser menor que $minimo."];
        }

        return [];
    }

    /**
     * Comprueba la fortaleza de una contrasena.
     *
     * @param string $clave
     * @return string|null Mensaje de error, o null si es valida.
     */
    private function fortalezaDeClave(string $clave): ?string
    {
        if (mb_strlen($clave) < $this->longitudMinimaClave) {
            return "La contrasena debe tener al menos {$this->longitudMinimaClave} caracteres.";
        }

        // bcrypt solo considera los primeros 72 bytes.
        if (mb_strlen($clave) > 72) {
            return 'La contrasena no puede superar los 72 caracteres.';
        }

        if (preg_match('/[A-Za-z]/', $clave) !== 1) {
            return 'La contrasena debe incluir al menos una letra.';
        }

        if (preg_match('/[0-9]/', $clave) !== 1) {
            return 'La contrasena debe incluir al menos un numero.';
        }

        return null;
    }

    /**
     * Normaliza un valor: lo convierte a cadena, recorta espacios y retira
     * etiquetas HTML para prevenir XSS cuando el dato se muestre despues.
     *
     * @param mixed $valor
     * @return string
     */
    public function texto(mixed $valor): string
    {
        if (!is_scalar($valor)) {
            return '';
        }

        return trim(strip_tags((string) $valor));
    }
}
