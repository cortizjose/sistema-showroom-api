<?php
/**
 * =============================================================================
 * src/Servicios/AutenticacionServicio.php
 * -----------------------------------------------------------------------------
 * Autenticacion y administracion de usuarios.
 *
 * Reutiliza la logica probada en la evidencia GA7-220501096-AA5-EV01 y la
 * amplia con roles, cambio de contrasena y administracion de cuentas.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Servicios;

use App\Repositorios\UsuarioRepositorio;
use App\Validacion\Validador;
use Throwable;

class AutenticacionServicio extends ServicioBase
{
    /**
     * @param UsuarioRepositorio  $repositorio
     * @param Validador           $validador
     * @param Jwt                 $jwt
     * @param array<string,mixed> $seguridad Politicas de seguridad.
     * @param array<string,mixed> $negocio   Parametros de paginacion.
     * @param array<int,string>   $roles     Nombres de rol validos.
     */
    public function __construct(
        private UsuarioRepositorio $repositorio,
        private Validador $validador,
        private Jwt $jwt,
        private array $seguridad,
        private array $negocio,
        private array $roles
    ) {
    }

    // =========================================================================
    // INICIO DE SESION
    // =========================================================================

    /**
     * Autentica a un usuario y emite un token JWT.
     *
     * @param array<string,mixed> $datos
     * @param string              $direccionIp
     * @return array<string,mixed>
     */
    public function iniciarSesion(array $datos, string $direccionIp = '0.0.0.0'): array
    {
        $errores = $this->validador->validarLogin($datos);

        if ($errores !== []) {
            return $this->error('Error en la autenticacion: faltan datos obligatorios.', 422, $errores);
        }

        $identificador = mb_strtolower($this->validador->texto(
            $datos['usuario'] ?? $datos['nombre_usuario'] ?? $datos['correo'] ?? ''
        ));
        $contrasena = (string) $datos['contrasena'];

        $usuario = $this->repositorio->buscarPorIdentificador($identificador);

        // PREVENCION DE ENUMERACION DE USUARIOS
        // Si la cuenta no existe se verifica igualmente contra un hash
        // ficticio, para que el tiempo de respuesta sea comparable y un
        // atacante no pueda deducir que cuentas estan registradas.
        if ($usuario === null) {
            password_verify($contrasena, '$2y$10$abcdefghijklmnopqrstuv0123456789ABCDEFGHIJKLMNOPQRSTU');
            $this->repositorio->registrarIntentoEnBitacora($identificador, false, $direccionIp, 'usuario_inexistente');

            return $this->credencialesInvalidas();
        }

        if ($usuario->estaBloqueado()) {
            $minutos = $usuario->minutosRestantesDeBloqueo();
            $this->repositorio->registrarIntentoEnBitacora($identificador, false, $direccionIp, 'cuenta_bloqueada');

            return $this->error(
                "Error en la autenticacion: la cuenta esta bloqueada temporalmente. "
                . "Intente nuevamente en $minutos minuto(s).",
                429
            );
        }

        if (!$usuario->estaActivo()) {
            $this->repositorio->registrarIntentoEnBitacora($identificador, false, $direccionIp, 'cuenta_inactiva');

            return $this->error('Error en la autenticacion: la cuenta se encuentra inactiva.', 403);
        }

        if (!password_verify($contrasena, $usuario->claveHash)) {
            $intentos = $this->repositorio->registrarIntentoFallido(
                (int) $usuario->id,
                (int) $this->seguridad['maximoIntentosFallidos'],
                (int) $this->seguridad['minutosBloqueo']
            );
            $this->repositorio->registrarIntentoEnBitacora($identificador, false, $direccionIp, 'clave_incorrecta');

            $restantes = max(0, ((int) $this->seguridad['maximoIntentosFallidos']) - $intentos);

            return $this->credencialesInvalidas($restantes);
        }

        // Autenticacion satisfactoria.
        $this->repositorio->registrarAccesoExitoso((int) $usuario->id);
        $this->repositorio->registrarIntentoEnBitacora($identificador, true, $direccionIp, null);

        // El rol viaja dentro del token: asi el middleware de autorizacion
        // puede resolver los permisos sin consultar la base de datos.
        $token = $this->jwt->generar(
            (int) $usuario->id,
            $usuario->nombreUsuario,
            ['rol' => $usuario->rolNombre]
        );

        $actualizado = $this->repositorio->buscarPorId((int) $usuario->id) ?? $usuario;

        return $this->exito('Autenticacion satisfactoria.', [
            'token'         => $token['token'],
            'tipoToken'     => 'Bearer',
            'expiraEn'      => $token['expiraEn'],
            'expiraEnTexto' => $token['expiraEnTexto'],
            'usuario'       => $actualizado->aArregloPublico(),
        ]);
    }

    /**
     * Devuelve el perfil del usuario dueno de un token.
     *
     * @param string|null $token
     * @return array<string,mixed>
     */
    public function obtenerPerfil(?string $token): array
    {
        if ($token === null || $token === '') {
            return $this->error('Acceso no autorizado: no se envio el token de autenticacion.', 401);
        }

        $cargaUtil = $this->jwt->verificar($token);

        if ($cargaUtil === null) {
            return $this->error('Acceso no autorizado: el token es invalido o ha expirado.', 401);
        }

        $usuario = $this->repositorio->buscarPorId((int) $cargaUtil['sub']);

        if ($usuario === null) {
            return $this->noEncontrado('el usuario del token');
        }

        return $this->exito('Perfil obtenido correctamente.', [
            'usuario'      => $usuario->aArregloPublico(),
            'tokenEmitido' => date('c', (int) $cargaUtil['iat']),
            'tokenExpira'  => date('c', (int) $cargaUtil['exp']),
        ]);
    }

    /**
     * Cambia la contrasena del usuario autenticado.
     *
     * Exige la contrasena actual: de lo contrario, quien robara un token
     * podria apoderarse de la cuenta de forma permanente.
     *
     * @param int                 $usuarioId
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    public function cambiarClave(int $usuarioId, array $datos): array
    {
        $errores = $this->validador->validarCambioDeClave($datos);

        if ($errores !== []) {
            return $this->errorDeValidacion($errores);
        }

        $usuario = $this->repositorio->buscarPorId($usuarioId);

        if ($usuario === null) {
            return $this->noEncontrado('el usuario');
        }

        if (!password_verify((string) $datos['contrasena_actual'], $usuario->claveHash)) {
            return $this->error('La contrasena actual no es correcta.', 401);
        }

        $nueva = (string) $datos['contrasena_nueva'];

        if (password_verify($nueva, $usuario->claveHash)) {
            return $this->errorDeValidacion([
                'contrasena_nueva' => 'La contrasena nueva debe ser distinta de la actual.',
            ]);
        }

        $this->repositorio->actualizarClave(
            $usuarioId,
            password_hash($nueva, $this->seguridad['algoritmoHash'])
        );

        return $this->exito('Contrasena actualizada correctamente.');
    }

    // =========================================================================
    // ADMINISTRACION DE USUARIOS
    // =========================================================================

    /**
     * Registra un usuario.
     *
     * @param array<string,mixed> $datos
     * @param string              $rolPorDefecto Rol si no se indica uno.
     * @return array<string,mixed>
     */
    public function registrar(array $datos, string $rolPorDefecto = 'consulta'): array
    {
        $errores = $this->validador->validarUsuario($datos, $this->roles);

        if ($errores !== []) {
            return $this->errorDeValidacion($errores);
        }

        $nombreUsuario  = mb_strtolower($this->validador->texto($datos['nombre_usuario']));
        $correo         = mb_strtolower($this->validador->texto($datos['correo']));
        $nombreCompleto = $this->validador->texto($datos['nombre_completo'] ?? '') ?: null;
        $rol            = $this->validador->texto($datos['rol'] ?? '') ?: $rolPorDefecto;

        $conflictos = [];

        if ($this->repositorio->existeNombreUsuario($nombreUsuario)) {
            $conflictos['nombre_usuario'] = 'El nombre de usuario ya se encuentra registrado.';
        }

        if ($this->repositorio->existeCorreo($correo)) {
            $conflictos['correo'] = 'El correo electronico ya se encuentra registrado.';
        }

        if ($conflictos !== []) {
            return $this->conflicto('Ya existe una cuenta con esos datos.', $conflictos);
        }

        $rolId = $this->repositorio->idDeRol($rol);

        if ($rolId === null) {
            return $this->errorDeValidacion(['rol' => "El rol '$rol' no existe."]);
        }

        try {
            $usuario = $this->repositorio->crear(
                $nombreUsuario,
                $correo,
                password_hash((string) $datos['contrasena'], $this->seguridad['algoritmoHash']),
                $rolId,
                $nombreCompleto
            );
        } catch (Throwable) {
            return $this->error('Ocurrio un error al registrar el usuario. Intente nuevamente.', 500);
        }

        return $this->exito(
            'Usuario registrado satisfactoriamente.',
            ['usuario' => $usuario->aArregloPublico()],
            201
        );
    }

    /**
     * Listado paginado de usuarios.
     *
     * @param array<string,mixed> $filtros
     * @return array<string,mixed>
     */
    public function listar(array $filtros): array
    {
        $resultado = $this->repositorio->listar(
            $filtros['busqueda'] ?? null,
            $filtros['rol'] ?? null,
            (int) ($filtros['pagina'] ?? 1),
            $this->porPagina($filtros)
        );

        // Se usa el modelo para garantizar que nunca salga el hash.
        $usuarios = array_map(
            static fn (array $f): array => \App\Modelos\Usuario::desdeFila($f)->aArregloPublico(),
            $resultado['datos']
        );

        return $this->exito('Usuarios consultados correctamente.', [
            'usuarios'   => $usuarios,
            'paginacion' => $resultado['paginacion'],
        ]);
    }

    /**
     * Consulta un usuario por su identificador.
     *
     * @param int $id
     * @return array<string,mixed>
     */
    public function consultar(int $id): array
    {
        $usuario = $this->repositorio->buscarPorId($id);

        if ($usuario === null) {
            return $this->noEncontrado("el usuario con identificador $id");
        }

        return $this->exito('Usuario consultado correctamente.', [
            'usuario' => $usuario->aArregloPublico(),
        ]);
    }

    /**
     * Actualiza el correo, el nombre y el rol de un usuario.
     *
     * @param int                 $id
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    public function actualizar(int $id, array $datos): array
    {
        $usuario = $this->repositorio->buscarPorId($id);

        if ($usuario === null) {
            return $this->noEncontrado("el usuario con identificador $id");
        }

        $correo = mb_strtolower($this->validador->texto($datos['correo'] ?? $usuario->correo));
        $rol    = $this->validador->texto($datos['rol'] ?? $usuario->rolNombre);

        $errores = [];

        if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            $errores['correo'] = 'El formato del correo electronico no es valido.';
        }

        if (!in_array($rol, $this->roles, true)) {
            $errores['rol'] = 'Rol no valido. Opciones: ' . implode(', ', $this->roles) . '.';
        }

        if ($errores !== []) {
            return $this->errorDeValidacion($errores);
        }

        if ($this->repositorio->existeCorreo($correo, $id)) {
            return $this->conflicto('El correo ya pertenece a otro usuario.', [
                'correo' => 'Ya registrado.',
            ]);
        }

        $rolId = $this->repositorio->idDeRol($rol);

        $this->repositorio->actualizar(
            $id,
            $correo,
            $this->validador->texto($datos['nombre_completo'] ?? $usuario->nombreCompleto ?? '') ?: null,
            (int) $rolId
        );

        return $this->exito('Usuario actualizado correctamente.', [
            'usuario' => $this->repositorio->buscarPorId($id)?->aArregloPublico(),
        ]);
    }

    /**
     * Activa o desactiva una cuenta.
     *
     * @param int                 $id
     * @param array<string,mixed> $datos
     * @param int                 $usuarioSolicitante Para impedir autobloqueo.
     * @return array<string,mixed>
     */
    public function cambiarEstado(int $id, array $datos, int $usuarioSolicitante): array
    {
        $estado = $this->validador->texto($datos['estado'] ?? '');

        if (!in_array($estado, ['activo', 'inactivo'], true)) {
            return $this->errorDeValidacion([
                'estado' => 'Estado no valido. Opciones: activo, inactivo.',
            ]);
        }

        if ($this->repositorio->buscarPorId($id) === null) {
            return $this->noEncontrado("el usuario con identificador $id");
        }

        // REGLA DE NEGOCIO: un administrador no puede desactivarse a si mismo,
        // porque quedaria sin forma de volver a entrar.
        if ($id === $usuarioSolicitante && $estado === 'inactivo') {
            return $this->conflicto('No puede desactivar su propia cuenta.');
        }

        $this->repositorio->cambiarEstado($id, $estado);

        return $this->exito("Usuario marcado como $estado.", [
            'usuario' => $this->repositorio->buscarPorId($id)?->aArregloPublico(),
        ]);
    }

    /**
     * Lista los roles disponibles.
     *
     * @return array<string,mixed>
     */
    public function listarRoles(): array
    {
        return $this->exito('Roles consultados correctamente.', [
            'roles' => $this->repositorio->listarRoles(),
        ]);
    }

    // =========================================================================
    // AUXILIARES
    // =========================================================================

    /**
     * Respuesta unica para credenciales invalidas.
     *
     * MEDIDA DE SEGURIDAD: el mensaje es el mismo tanto si el usuario no
     * existe como si la contrasena es incorrecta, para impedir la
     * enumeracion de cuentas.
     *
     * @param int|null $intentosRestantes
     * @return array<string,mixed>
     */
    private function credencialesInvalidas(?int $intentosRestantes = null): array
    {
        $mensaje = 'Error en la autenticacion: usuario o contrasena incorrectos.';

        if ($intentosRestantes !== null && $intentosRestantes > 0) {
            $mensaje .= " Le quedan $intentosRestantes intento(s) antes del bloqueo temporal.";
        }

        return $this->error($mensaje, 401);
    }

    /**
     * Resuelve cuantos registros por pagina devolver, respetando el maximo.
     *
     * @param array<string,mixed> $filtros
     * @return int
     */
    private function porPagina(array $filtros): int
    {
        $solicitado = (int) ($filtros['porPagina'] ?? $this->negocio['registrosPorPagina']);

        return min(max(1, $solicitado), (int) $this->negocio['maximoRegistrosPorPagina']);
    }
}
