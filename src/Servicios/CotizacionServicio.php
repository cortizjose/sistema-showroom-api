<?php
/**
 * =============================================================================
 * src/Servicios/CotizacionServicio.php
 * -----------------------------------------------------------------------------
 * Reglas de negocio de las cotizaciones.
 *
 * Responsabilidades:
 *   - Crear cotizaciones calculando sus importes en el servidor.
 *   - Hacer cumplir la maquina de estados del documento.
 *   - Marcar como vencidas las que pasaron su fecha.
 *   - Convertir una cotizacion aprobada en pedido.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Servicios;

use App\Modelos\Cotizacion;
use App\Repositorios\ClienteRepositorio;
use App\Repositorios\CotizacionRepositorio;
use App\Repositorios\PedidoRepositorio;
use App\Validacion\Validador;
use Throwable;

class CotizacionServicio extends ServicioBase
{
    /**
     * @param CotizacionRepositorio $repositorio
     * @param ClienteRepositorio    $clientes
     * @param PedidoRepositorio     $pedidos
     * @param CalculadoraDeTotales  $calculadora
     * @param Validador             $validador
     * @param array<string,mixed>   $negocio
     */
    public function __construct(
        private CotizacionRepositorio $repositorio,
        private ClienteRepositorio $clientes,
        private PedidoRepositorio $pedidos,
        private CalculadoraDeTotales $calculadora,
        private Validador $validador,
        private array $negocio
    ) {
    }

    /**
     * Listado paginado de cotizaciones.
     *
     * Antes de listar se actualizan las vencidas, para que el estado que ve
     * el usuario siempre este al dia sin necesidad de una tarea programada.
     *
     * @param array<string,mixed> $filtros
     * @return array<string,mixed>
     */
    public function listar(array $filtros): array
    {
        $vencidas = $this->repositorio->marcarVencidas();

        $resultado = $this->repositorio->listar(
            [
                'cliente_id' => $filtros['cliente'] ?? null,
                'estado'     => $filtros['estado'] ?? null,
                'desde'      => $filtros['desde'] ?? null,
                'hasta'      => $filtros['hasta'] ?? null,
            ],
            (int) ($filtros['pagina'] ?? 1),
            $this->porPagina($filtros)
        );

        // En el listado se devuelve la cabecera sin el detalle de lineas,
        // para no cargar innecesariamente la respuesta.
        $cotizaciones = array_map(static function (array $fila): array {
            $c = Cotizacion::desdeFila($fila);
            $arreglo = $c->aArreglo();
            unset($arreglo['lineas']);

            return $arreglo;
        }, $resultado['datos']);

        return $this->exito('Cotizaciones consultadas correctamente.', [
            'cotizaciones'      => $cotizaciones,
            'paginacion'        => $resultado['paginacion'],
            'vencidasMarcadas'  => $vencidas,
        ]);
    }

    /**
     * Consulta una cotizacion con su detalle y su historial de estados.
     *
     * @param int $id
     * @return array<string,mixed>
     */
    public function consultar(int $id): array
    {
        $this->repositorio->marcarVencidas();

        $cotizacion = $this->repositorio->buscarPorId($id);

        if ($cotizacion === null) {
            return $this->noEncontrado("la cotizacion con identificador $id");
        }

        return $this->exito('Cotizacion consultada correctamente.', [
            'cotizacion' => $cotizacion->aArreglo(),
            'historial'  => $this->repositorio->historialDeEstados('cotizacion', $id),
        ]);
    }

    /**
     * Crea una cotizacion.
     *
     * @param array<string,mixed> $datos
     * @param int                 $usuarioId Asesor que la elabora.
     * @return array<string,mixed>
     */
    public function crear(array $datos, int $usuarioId): array
    {
        // 1. Formato de los datos.
        $errores = $this->validador->validarDocumento($datos);

        if ($errores !== []) {
            return $this->errorDeValidacion($errores);
        }

        // 2. El cliente debe existir y estar activo.
        $cliente = $this->clientes->buscarPorId((int) $datos['cliente_id']);

        if ($cliente === null) {
            return $this->errorDeValidacion(['cliente_id' => 'El cliente indicado no existe.']);
        }

        if (!$cliente->estaActivo()) {
            return $this->conflicto('No se pueden emitir documentos a un cliente inactivo.');
        }

        // 3. Calculo de lineas y totales. Para una cotizacion no se exige
        //    stock: es una propuesta, no un compromiso de entrega.
        $calculo = $this->calculadora->calcular($datos['lineas'], verificarStock: false);

        if (!$calculo['exito']) {
            return $this->errorDeValidacion($calculo['errores']);
        }

        // 4. Vigencia del documento.
        $emision     = date('Y-m-d H:i:s');
        $vencimiento = date(
            'Y-m-d H:i:s',
            strtotime("+{$this->negocio['diasVigenciaCotizacion']} days")
        );

        try {
            $cotizacion = $this->repositorio->crear(
                array_merge($calculo['totales'], [
                    'prefijo'           => $this->negocio['prefijoCotizacion'],
                    'cliente_id'        => $cliente->id,
                    'usuario_id'        => $usuarioId,
                    'fecha_emision'     => $emision,
                    'fecha_vencimiento' => $vencimiento,
                    'observaciones'     => $this->validador->texto($datos['observaciones'] ?? '') ?: null,
                ]),
                $calculo['lineas']
            );
        } catch (Throwable) {
            return $this->error('Ocurrio un error al crear la cotizacion. Intente nuevamente.', 500);
        }

        return $this->exito(
            "Cotizacion {$cotizacion->numero} creada satisfactoriamente.",
            ['cotizacion' => $cotizacion->aArreglo()],
            201
        );
    }

    /**
     * Cambia el estado de una cotizacion respetando la maquina de estados.
     *
     * @param int                 $id
     * @param array<string,mixed> $datos
     * @param int                 $usuarioId
     * @return array<string,mixed>
     */
    public function cambiarEstado(int $id, array $datos, int $usuarioId): array
    {
        $this->repositorio->marcarVencidas();

        $cotizacion = $this->repositorio->buscarPorId($id);

        if ($cotizacion === null) {
            return $this->noEncontrado("la cotizacion con identificador $id");
        }

        // Estados alcanzables desde el estado actual.
        $posibles = $cotizacion->siguientesEstados();

        if ($posibles === []) {
            return $this->conflicto(
                "La cotizacion esta en estado '{$cotizacion->estado}' y ya no admite cambios."
            );
        }

        $errores = $this->validador->validarCambioDeEstado($datos, $posibles);

        if ($errores !== []) {
            return $this->errorDeValidacion($errores);
        }

        $nuevoEstado = $this->validador->texto($datos['estado']);

        // REGLA DE NEGOCIO: a pedido solo se llega por la conversion, que
        // tiene su propio endpoint porque afecta el inventario.
        if ($nuevoEstado === 'convertida') {
            return $this->conflicto(
                'Para convertir la cotizacion en pedido use POST /api/cotizaciones/' . $id . '/convertir-pedido.'
            );
        }

        if (!$cotizacion->puedeTransicionarA($nuevoEstado)) {
            return $this->conflicto(
                "No se puede pasar de '{$cotizacion->estado}' a '$nuevoEstado'.",
                ['estadosPermitidos' => $posibles]
            );
        }

        $this->repositorio->cambiarEstado(
            $id,
            $cotizacion->estado,
            $nuevoEstado,
            $usuarioId,
            $this->validador->texto($datos['observacion'] ?? '') ?: null
        );

        return $this->exito("Cotizacion marcada como '$nuevoEstado'.", [
            'cotizacion' => $this->repositorio->buscarPorId($id)?->aArreglo(),
        ]);
    }

    /**
     * Convierte una cotizacion aprobada en un pedido.
     *
     * Es la operacion mas delicada del sistema: toca dos documentos y debe
     * dejarlos coherentes. Se ejecuta dentro de una transaccion.
     *
     * @param int                 $id
     * @param array<string,mixed> $datos
     * @param int                 $usuarioId
     * @return array<string,mixed>
     */
    public function convertirEnPedido(int $id, array $datos, int $usuarioId): array
    {
        $this->repositorio->marcarVencidas();

        $cotizacion = $this->repositorio->buscarPorId($id);

        if ($cotizacion === null) {
            return $this->noEncontrado("la cotizacion con identificador $id");
        }

        // 1. Solo una cotizacion aprobada puede convertirse.
        if (!$cotizacion->puedeConvertirseEnPedido()) {
            return $this->conflicto(
                "Solo una cotizacion aprobada puede convertirse en pedido. "
                . "Estado actual: '{$cotizacion->estado}'."
            );
        }

        // 2. No se puede convertir dos veces.
        if ($this->pedidos->existePedidoDeCotizacion($id)) {
            return $this->conflicto('Esta cotizacion ya genero un pedido.');
        }

        // 3. Se recalculan las lineas exigiendo stock: al pasar a pedido el
        //    showroom se compromete a entregar.
        $lineasParaCalculo = array_map(static fn (array $l): array => [
            'producto_id'          => $l['producto']['id'],
            'cantidad'             => $l['cantidad'],
            'descuento_porcentaje' => $l['descuento'],
        ], $cotizacion->lineas);

        $calculo = $this->calculadora->calcular($lineasParaCalculo, verificarStock: true);

        if (!$calculo['exito']) {
            return $this->conflicto(
                'No es posible generar el pedido con las existencias actuales.',
                $calculo['errores']
            );
        }

        // 4. Se crea el pedido conservando los importes de la cotizacion,
        //    porque el cliente aprobo esos valores. Si el precio del catalogo
        //    cambio entre tanto, se respeta lo cotizado.
        try {
            $pedido = $this->pedidos->crear([
                'prefijo'                => $this->negocio['prefijoPedido'],
                'cotizacion_id'          => $cotizacion->id,
                'cliente_id'             => $cotizacion->clienteId,
                'usuario_id'             => $usuarioId,
                'fecha_pedido'           => date('Y-m-d H:i:s'),
                'fecha_entrega_estimada' => $this->validador->texto($datos['fecha_entrega_estimada'] ?? '') ?: null,
                'subtotal'               => $cotizacion->subtotal,
                'valor_descuento'        => $cotizacion->valorDescuento,
                'valor_iva'              => $cotizacion->valorIva,
                'total'                  => $cotizacion->total,
                'direccion_entrega'      => $this->validador->texto($datos['direccion_entrega'] ?? '') ?: null,
                'observaciones'          => "Generado desde la cotizacion {$cotizacion->numero}.",
            ], array_map(static fn (array $l): array => [
                'producto_id'          => $l['producto']['id'],
                'descripcion'          => $l['descripcion'],
                'cantidad'             => $l['cantidad'],
                'precio_unitario'      => $l['precioUnitario'],
                'descuento_porcentaje' => $l['descuento'],
                'subtotal'             => $l['subtotal'],
            ], $cotizacion->lineas));

            // 5. La cotizacion queda cerrada como convertida.
            $this->repositorio->cambiarEstado(
                $id,
                $cotizacion->estado,
                'convertida',
                $usuarioId,
                "Convertida en el pedido {$pedido->numero}"
            );

        } catch (Throwable) {
            return $this->error('Ocurrio un error al generar el pedido. Intente nuevamente.', 500);
        }

        return $this->exito(
            "Cotizacion {$cotizacion->numero} convertida en el pedido {$pedido->numero}.",
            [
                'pedido'     => $pedido->aArreglo(),
                'cotizacion' => $this->repositorio->buscarPorId($id)?->aArreglo(),
            ],
            201
        );
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
