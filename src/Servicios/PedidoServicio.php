<?php
/**
 * =============================================================================
 * src/Servicios/PedidoServicio.php
 * -----------------------------------------------------------------------------
 * Reglas de negocio de los pedidos.
 *
 * El punto delicado de este servicio es el EFECTO SOBRE EL INVENTARIO:
 *
 *   Al confirmar un pedido  -> se descuenta el stock de cada producto.
 *   Al anular un pedido     -> se devuelve el stock, si ya se habia descontado.
 *
 * Confirmar es el momento en que el showroom se compromete con la entrega,
 * asi que es ahi, y no antes, cuando la mercancia queda reservada.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Servicios;

use App\Modelos\Pedido;
use App\Repositorios\ClienteRepositorio;
use App\Repositorios\PedidoRepositorio;
use App\Repositorios\ProductoRepositorio;
use App\Validacion\Validador;
use Throwable;

class PedidoServicio extends ServicioBase
{
    /**
     * @param PedidoRepositorio    $repositorio
     * @param ClienteRepositorio   $clientes
     * @param ProductoRepositorio  $productos
     * @param CalculadoraDeTotales $calculadora
     * @param Validador            $validador
     * @param array<string,mixed>  $negocio
     */
    public function __construct(
        private PedidoRepositorio $repositorio,
        private ClienteRepositorio $clientes,
        private ProductoRepositorio $productos,
        private CalculadoraDeTotales $calculadora,
        private Validador $validador,
        private array $negocio
    ) {
    }

    /**
     * Listado paginado de pedidos.
     *
     * @param array<string,mixed> $filtros
     * @return array<string,mixed>
     */
    public function listar(array $filtros): array
    {
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

        $pedidos = array_map(static function (array $fila): array {
            $arreglo = Pedido::desdeFila($fila)->aArreglo();
            unset($arreglo['lineas']);

            return $arreglo;
        }, $resultado['datos']);

        return $this->exito('Pedidos consultados correctamente.', [
            'pedidos'    => $pedidos,
            'paginacion' => $resultado['paginacion'],
        ]);
    }

    /**
     * Consulta un pedido con su detalle y su historial de estados.
     *
     * @param int $id
     * @return array<string,mixed>
     */
    public function consultar(int $id): array
    {
        $pedido = $this->repositorio->buscarPorId($id);

        if ($pedido === null) {
            return $this->noEncontrado("el pedido con identificador $id");
        }

        return $this->exito('Pedido consultado correctamente.', [
            'pedido'    => $pedido->aArreglo(),
            'historial' => $this->repositorio->historialDeEstados('pedido', $id),
        ]);
    }

    /**
     * Crea un pedido directo, sin cotizacion previa.
     *
     * @param array<string,mixed> $datos
     * @param int                 $usuarioId
     * @return array<string,mixed>
     */
    public function crear(array $datos, int $usuarioId): array
    {
        $errores = $this->validador->validarDocumento($datos);

        if ($errores !== []) {
            return $this->errorDeValidacion($errores);
        }

        $cliente = $this->clientes->buscarPorId((int) $datos['cliente_id']);

        if ($cliente === null) {
            return $this->errorDeValidacion(['cliente_id' => 'El cliente indicado no existe.']);
        }

        if (!$cliente->estaActivo()) {
            return $this->conflicto('No se pueden emitir documentos a un cliente inactivo.');
        }

        // Un pedido si exige existencias: es un compromiso de entrega.
        $calculo = $this->calculadora->calcular($datos['lineas'], verificarStock: true);

        if (!$calculo['exito']) {
            return $this->conflicto(
                'No hay existencias suficientes para el pedido.',
                $calculo['errores']
            );
        }

        try {
            $pedido = $this->repositorio->crear(
                array_merge($calculo['totales'], [
                    'prefijo'                => $this->negocio['prefijoPedido'],
                    'cotizacion_id'          => null,
                    'cliente_id'             => $cliente->id,
                    'usuario_id'             => $usuarioId,
                    'fecha_pedido'           => date('Y-m-d H:i:s'),
                    'fecha_entrega_estimada' => $this->validador->texto($datos['fecha_entrega_estimada'] ?? '') ?: null,
                    'direccion_entrega'      => $this->validador->texto($datos['direccion_entrega'] ?? $cliente->direccion ?? '') ?: null,
                    'observaciones'          => $this->validador->texto($datos['observaciones'] ?? '') ?: null,
                ]),
                $calculo['lineas']
            );
        } catch (Throwable) {
            return $this->error('Ocurrio un error al crear el pedido. Intente nuevamente.', 500);
        }

        return $this->exito(
            "Pedido {$pedido->numero} creado satisfactoriamente. Confirmelo para reservar las existencias.",
            ['pedido' => $pedido->aArreglo()],
            201
        );
    }

    /**
     * Cambia el estado de un pedido y aplica su efecto sobre el inventario.
     *
     * @param int                 $id
     * @param array<string,mixed> $datos
     * @param int                 $usuarioId
     * @return array<string,mixed>
     */
    public function cambiarEstado(int $id, array $datos, int $usuarioId): array
    {
        $pedido = $this->repositorio->buscarPorId($id);

        if ($pedido === null) {
            return $this->noEncontrado("el pedido con identificador $id");
        }

        $posibles = $pedido->siguientesEstados();

        if ($posibles === []) {
            return $this->conflicto(
                "El pedido esta en estado '{$pedido->estado}' y ya no admite cambios."
            );
        }

        $errores = $this->validador->validarCambioDeEstado($datos, $posibles);

        if ($errores !== []) {
            return $this->errorDeValidacion($errores);
        }

        $nuevoEstado = $this->validador->texto($datos['estado']);

        if (!$pedido->puedeTransicionarA($nuevoEstado)) {
            return $this->conflicto(
                "No se puede pasar de '{$pedido->estado}' a '$nuevoEstado'.",
                ['estadosPermitidos' => $posibles]
            );
        }

        // ---------------------------------------------------------------
        // EFECTO SOBRE EL INVENTARIO
        // ---------------------------------------------------------------

        // Confirmar: se descuentan las existencias.
        if ($nuevoEstado === 'confirmado') {
            $faltantes = $this->verificarExistencias($pedido);

            if ($faltantes !== []) {
                return $this->conflicto(
                    'No hay existencias suficientes para confirmar el pedido.',
                    $faltantes
                );
            }

            $this->moverInventario($pedido, -1, 'salida', "Confirmacion del pedido {$pedido->numero}", $usuarioId);
        }

        // Anular: se devuelven las existencias, si ya se habian descontado.
        if ($nuevoEstado === 'anulado' && $pedido->afectoInventario()) {
            $this->moverInventario($pedido, 1, 'entrada', "Anulacion del pedido {$pedido->numero}", $usuarioId);
        }

        $this->repositorio->cambiarEstado(
            $id,
            $pedido->estado,
            $nuevoEstado,
            $usuarioId,
            $this->validador->texto($datos['observacion'] ?? '') ?: null
        );

        $mensaje = match ($nuevoEstado) {
            'confirmado' => "Pedido {$pedido->numero} confirmado. Se descontaron las existencias.",
            'anulado'    => $pedido->afectoInventario()
                            ? "Pedido {$pedido->numero} anulado. Se devolvieron las existencias."
                            : "Pedido {$pedido->numero} anulado.",
            default      => "Pedido {$pedido->numero} marcado como '$nuevoEstado'.",
        };

        return $this->exito($mensaje, [
            'pedido' => $this->repositorio->buscarPorId($id)?->aArreglo(),
        ]);
    }

    /**
     * Comprueba que haya existencias para todas las lineas del pedido.
     *
     * @param Pedido $pedido
     * @return array<string,string> Faltantes encontrados.
     */
    private function verificarExistencias(Pedido $pedido): array
    {
        $faltantes = [];

        foreach ($pedido->lineas as $linea) {
            $producto = $this->productos->buscarPorId((int) $linea['producto']['id']);

            if ($producto === null) {
                $faltantes[$linea['producto']['codigo'] ?? 'producto'] = 'El producto ya no existe.';
                continue;
            }

            if (!$producto->hayStockPara((int) $linea['cantidad'])) {
                $faltantes[$producto->codigo] =
                    "Disponible: {$producto->stock}, requerido: {$linea['cantidad']}.";
            }
        }

        return $faltantes;
    }

    /**
     * Aplica un movimiento de inventario a todas las lineas del pedido.
     *
     * @param Pedido $pedido
     * @param int    $signo   -1 descuenta, +1 devuelve.
     * @param string $tipo    salida | entrada
     * @param string $motivo
     * @param int    $usuarioId
     * @return void
     */
    private function moverInventario(
        Pedido $pedido,
        int $signo,
        string $tipo,
        string $motivo,
        int $usuarioId
    ): void {
        foreach ($pedido->lineas as $linea) {
            $this->productos->moverStock(
                (int) $linea['producto']['id'],
                $signo * (int) $linea['cantidad'],
                $tipo,
                $motivo,
                $pedido->numero,
                $usuarioId
            );
        }
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
