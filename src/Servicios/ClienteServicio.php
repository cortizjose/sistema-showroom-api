<?php
/**
 * =============================================================================
 * src/Servicios/ClienteServicio.php
 * -----------------------------------------------------------------------------
 * Reglas de negocio de los clientes del showroom.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Servicios;

use App\Repositorios\ClienteRepositorio;
use App\Validacion\Validador;

class ClienteServicio extends ServicioBase
{
    /**
     * @param ClienteRepositorio  $repositorio
     * @param Validador           $validador
     * @param array<string,mixed> $negocio
     */
    public function __construct(
        private ClienteRepositorio $repositorio,
        private Validador $validador,
        private array $negocio
    ) {
    }

    /**
     * Listado paginado de clientes.
     *
     * @param array<string,mixed> $filtros
     * @return array<string,mixed>
     */
    public function listar(array $filtros): array
    {
        $resultado = $this->repositorio->listar(
            [
                'busqueda' => $filtros['busqueda'] ?? null,
                'ciudad'   => $filtros['ciudad'] ?? null,
                'estado'   => $filtros['estado'] ?? null,
            ],
            (int) ($filtros['pagina'] ?? 1),
            $this->porPagina($filtros)
        );

        $clientes = array_map(
            static fn (array $f): array => \App\Modelos\Cliente::desdeFila($f)->aArreglo(),
            $resultado['datos']
        );

        return $this->exito('Clientes consultados correctamente.', [
            'clientes'   => $clientes,
            'paginacion' => $resultado['paginacion'],
        ]);
    }

    /**
     * Consulta un cliente por su identificador.
     *
     * @param int $id
     * @return array<string,mixed>
     */
    public function consultar(int $id): array
    {
        $cliente = $this->repositorio->buscarPorId($id);

        if ($cliente === null) {
            return $this->noEncontrado("el cliente con identificador $id");
        }

        return $this->exito('Cliente consultado correctamente.', [
            'cliente'         => $cliente->aArreglo(),
            'tieneDocumentos' => $this->repositorio->tieneDocumentos($id),
        ]);
    }

    /**
     * Registra un cliente.
     *
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    public function crear(array $datos): array
    {
        $errores = $this->validador->validarCliente($datos);

        if ($errores !== []) {
            return $this->errorDeValidacion($errores);
        }

        $documento = $this->validador->texto($datos['numero_documento']);

        if ($this->repositorio->existeDocumento($documento)) {
            return $this->conflicto('Ya existe un cliente con ese numero de documento.', [
                'numero_documento' => 'El documento ya esta registrado.',
            ]);
        }

        $cliente = $this->repositorio->crear($this->normalizar($datos));

        return $this->exito(
            'Cliente registrado satisfactoriamente.',
            ['cliente' => $cliente->aArreglo()],
            201
        );
    }

    /**
     * Actualiza los datos de un cliente.
     *
     * @param int                 $id
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    public function actualizar(int $id, array $datos): array
    {
        $cliente = $this->repositorio->buscarPorId($id);

        if ($cliente === null) {
            return $this->noEncontrado("el cliente con identificador $id");
        }

        // Se completan los campos ausentes con los valores actuales.
        $datos += [
            'tipo_documento'   => $cliente->tipoDocumento,
            'numero_documento' => $cliente->numeroDocumento,
            'nombre'           => $cliente->nombre,
        ];

        $errores = $this->validador->validarCliente($datos);

        if ($errores !== []) {
            return $this->errorDeValidacion($errores);
        }

        $documento = $this->validador->texto($datos['numero_documento']);

        if ($this->repositorio->existeDocumento($documento, $id)) {
            return $this->conflicto('Ese numero de documento pertenece a otro cliente.', [
                'numero_documento' => 'El documento ya esta registrado.',
            ]);
        }

        $this->repositorio->actualizar($id, $this->normalizar($datos));

        return $this->exito('Cliente actualizado correctamente.', [
            'cliente' => $this->repositorio->buscarPorId($id)?->aArreglo(),
        ]);
    }

    /**
     * Activa o desactiva un cliente.
     *
     * No se elimina: un cliente inactivo debe seguir apareciendo en las
     * cotizaciones y pedidos historicos.
     *
     * @param int                 $id
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    public function cambiarEstado(int $id, array $datos): array
    {
        $estado = $this->validador->texto($datos['estado'] ?? '');

        if (!in_array($estado, ['activo', 'inactivo'], true)) {
            return $this->errorDeValidacion([
                'estado' => 'Estado no valido. Opciones: activo, inactivo.',
            ]);
        }

        if ($this->repositorio->buscarPorId($id) === null) {
            return $this->noEncontrado("el cliente con identificador $id");
        }

        $this->repositorio->cambiarEstado($id, $estado);

        return $this->exito("Cliente marcado como $estado.", [
            'cliente' => $this->repositorio->buscarPorId($id)?->aArreglo(),
        ]);
    }

    /**
     * Normaliza los campos de un cliente antes de persistirlos.
     *
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    private function normalizar(array $datos): array
    {
        $correo = $this->validador->texto($datos['correo'] ?? '');

        return [
            'tipo_documento'   => strtoupper($this->validador->texto($datos['tipo_documento'])),
            'numero_documento' => $this->validador->texto($datos['numero_documento']),
            'nombre'           => $this->validador->texto($datos['nombre']),
            'correo'           => $correo === '' ? null : mb_strtolower($correo),
            'telefono'         => $this->validador->texto($datos['telefono'] ?? '') ?: null,
            'direccion'        => $this->validador->texto($datos['direccion'] ?? '') ?: null,
            'ciudad'           => $this->validador->texto($datos['ciudad'] ?? '') ?: null,
        ];
    }

    /**
     * @param array<string,mixed> $filtros
     * @return int
     */
    private function porPagina(array $filtros): int
    {
        $solicitado = (int) ($filtros['porPagina'] ?? $this->negocio['registrosPorPagina']);

        return min(max(1, $solicitado), (int) $this->negocio['maximoRegistrosPorPagina']);
    }
}
