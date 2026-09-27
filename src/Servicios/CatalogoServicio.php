<?php
/**
 * =============================================================================
 * src/Servicios/CatalogoServicio.php
 * -----------------------------------------------------------------------------
 * Reglas de negocio del catalogo: productos, categorias e inventario.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\Servicios;

use App\Repositorios\ProductoRepositorio;
use App\Validacion\Validador;

class CatalogoServicio extends ServicioBase
{
    /**
     * @param ProductoRepositorio $repositorio
     * @param Validador           $validador
     * @param array<string,mixed> $negocio
     */
    public function __construct(
        private ProductoRepositorio $repositorio,
        private Validador $validador,
        private array $negocio
    ) {
    }

    // =========================================================================
    // PRODUCTOS
    // =========================================================================

    /**
     * Listado paginado del catalogo.
     *
     * @param array<string,mixed> $filtros
     * @return array<string,mixed>
     */
    public function listarProductos(array $filtros): array
    {
        $resultado = $this->repositorio->listar(
            [
                'busqueda'     => $filtros['busqueda'] ?? null,
                'categoria_id' => $filtros['categoria'] ?? null,
                'estado'       => $filtros['estado'] ?? null,
                'stock_bajo'   => !empty($filtros['stockBajo']),
            ],
            (int) ($filtros['pagina'] ?? 1),
            $this->porPagina($filtros)
        );

        $productos = array_map(
            static fn (array $f): array => \App\Modelos\Producto::desdeFila($f)->aArreglo(),
            $resultado['datos']
        );

        return $this->exito('Catalogo consultado correctamente.', [
            'productos'  => $productos,
            'paginacion' => $resultado['paginacion'],
        ]);
    }

    /**
     * Consulta un producto por su identificador, con su kardex reciente.
     *
     * @param int $id
     * @return array<string,mixed>
     */
    public function consultarProducto(int $id): array
    {
        $producto = $this->repositorio->buscarPorId($id);

        if ($producto === null) {
            return $this->noEncontrado("el producto con identificador $id");
        }

        return $this->exito('Producto consultado correctamente.', [
            'producto'    => $producto->aArreglo(),
            'movimientos' => $this->repositorio->movimientos($id, 10),
        ]);
    }

    /**
     * Crea un producto en el catalogo.
     *
     * @param array<string,mixed> $datos
     * @param int|null            $usuarioId
     * @return array<string,mixed>
     */
    public function crearProducto(array $datos, ?int $usuarioId = null): array
    {
        $errores = $this->validador->validarProducto($datos);

        if ($errores !== []) {
            return $this->errorDeValidacion($errores);
        }

        $codigo = strtoupper($this->validador->texto($datos['codigo']));

        if ($this->repositorio->existeCodigo($codigo)) {
            return $this->conflicto('Ya existe un producto con ese codigo.', [
                'codigo' => 'El codigo ya esta en uso.',
            ]);
        }

        // La categoria es opcional, pero si se indica debe existir.
        $categoriaId = isset($datos['categoria_id']) && $datos['categoria_id'] !== ''
            ? (int) $datos['categoria_id']
            : null;

        if ($categoriaId !== null && !$this->repositorio->existeCategoriaPorId($categoriaId)) {
            return $this->errorDeValidacion(['categoria_id' => 'La categoria indicada no existe.']);
        }

        $stockInicial = (int) ($datos['stock'] ?? 0);

        $producto = $this->repositorio->crear([
            'codigo'          => $codigo,
            'nombre'          => $this->validador->texto($datos['nombre']),
            'descripcion'     => $this->validador->texto($datos['descripcion'] ?? '') ?: null,
            'categoria_id'    => $categoriaId,
            'precio_unitario' => (float) $datos['precio_unitario'],
            'stock'           => 0,
            'stock_minimo'    => (int) ($datos['stock_minimo'] ?? 0),
        ]);

        // El stock inicial entra como movimiento, para que el inventario
        // quede explicado desde el primer dia.
        if ($stockInicial > 0) {
            $this->repositorio->moverStock(
                (int) $producto->id,
                $stockInicial,
                'entrada',
                'Stock inicial del producto',
                $codigo,
                $usuarioId
            );

            $producto = $this->repositorio->buscarPorId((int) $producto->id);
        }

        return $this->exito(
            'Producto creado satisfactoriamente.',
            ['producto' => $producto->aArreglo()],
            201
        );
    }

    /**
     * Actualiza los datos de un producto.
     *
     * @param int                 $id
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    public function actualizarProducto(int $id, array $datos): array
    {
        $producto = $this->repositorio->buscarPorId($id);

        if ($producto === null) {
            return $this->noEncontrado("el producto con identificador $id");
        }

        // Se completan los campos no enviados con los valores actuales, para
        // que la validacion trabaje sobre el registro completo.
        $datos += [
            'codigo'          => $producto->codigo,
            'nombre'          => $producto->nombre,
            'precio_unitario' => $producto->precioUnitario,
            'stock_minimo'    => $producto->stockMinimo,
        ];

        $errores = $this->validador->validarProducto($datos);

        if ($errores !== []) {
            return $this->errorDeValidacion($errores);
        }

        $codigo = strtoupper($this->validador->texto($datos['codigo']));

        if ($this->repositorio->existeCodigo($codigo, $id)) {
            return $this->conflicto('Ya existe otro producto con ese codigo.', [
                'codigo' => 'El codigo ya esta en uso.',
            ]);
        }

        $categoriaId = isset($datos['categoria_id']) && $datos['categoria_id'] !== ''
            ? (int) $datos['categoria_id']
            : $producto->categoriaId;

        if ($categoriaId !== null && !$this->repositorio->existeCategoriaPorId($categoriaId)) {
            return $this->errorDeValidacion(['categoria_id' => 'La categoria indicada no existe.']);
        }

        $this->repositorio->actualizar($id, [
            'codigo'          => $codigo,
            'nombre'          => $this->validador->texto($datos['nombre']),
            'descripcion'     => $this->validador->texto($datos['descripcion'] ?? $producto->descripcion ?? '') ?: null,
            'categoria_id'    => $categoriaId,
            'precio_unitario' => (float) $datos['precio_unitario'],
            'stock_minimo'    => (int) $datos['stock_minimo'],
        ]);

        return $this->exito('Producto actualizado correctamente.', [
            'producto' => $this->repositorio->buscarPorId($id)?->aArreglo(),
        ]);
    }

    /**
     * Da de baja un producto.
     *
     * Es una baja logica: el producto sigue existiendo porque aparece en
     * cotizaciones y pedidos ya emitidos, que no deben alterarse.
     *
     * @param int $id
     * @return array<string,mixed>
     */
    public function descontinuarProducto(int $id): array
    {
        $producto = $this->repositorio->buscarPorId($id);

        if ($producto === null) {
            return $this->noEncontrado("el producto con identificador $id");
        }

        if ($producto->estado === 'descontinuado') {
            return $this->conflicto('El producto ya estaba descontinuado.');
        }

        $this->repositorio->cambiarEstado($id, 'descontinuado');

        return $this->exito('Producto descontinuado. Se conserva en los documentos historicos.', [
            'producto' => $this->repositorio->buscarPorId($id)?->aArreglo(),
        ]);
    }

    /**
     * Ajusta manualmente el stock de un producto.
     *
     * @param int                 $id
     * @param array<string,mixed> $datos
     * @param int|null            $usuarioId
     * @return array<string,mixed>
     */
    public function ajustarStock(int $id, array $datos, ?int $usuarioId = null): array
    {
        $producto = $this->repositorio->buscarPorId($id);

        if ($producto === null) {
            return $this->noEncontrado("el producto con identificador $id");
        }

        $cantidad = $datos['cantidad'] ?? null;

        if ($cantidad === null || !is_numeric($cantidad) || (int) $cantidad === 0) {
            return $this->errorDeValidacion([
                'cantidad' => 'Indique una cantidad distinta de cero. Positiva suma, negativa resta.',
            ]);
        }

        $cantidad = (int) $cantidad;

        // No se permite dejar el inventario en negativo.
        if ($cantidad < 0 && $producto->stock + $cantidad < 0) {
            return $this->conflicto(
                "No hay existencias suficientes. Stock actual: {$producto->stock}.",
                ['cantidad' => 'El ajuste dejaria el stock en negativo.']
            );
        }

        $motivo = $this->validador->texto($datos['motivo'] ?? '') ?: 'Ajuste manual de inventario';

        $nuevoStock = $this->repositorio->moverStock(
            $id,
            $cantidad,
            $cantidad > 0 ? 'entrada' : 'salida',
            $motivo,
            null,
            $usuarioId
        );

        return $this->exito('Stock ajustado correctamente.', [
            'producto'      => $this->repositorio->buscarPorId($id)?->aArreglo(),
            'stockAnterior' => $producto->stock,
            'stockNuevo'    => $nuevoStock,
        ]);
    }

    /**
     * Productos que alcanzaron su nivel minimo de existencias.
     *
     * @return array<string,mixed>
     */
    public function productosConStockBajo(): array
    {
        $productos = $this->repositorio->conStockBajo();

        return $this->exito('Consulta de reposicion generada correctamente.', [
            'total'     => count($productos),
            'productos' => $productos,
        ]);
    }

    // =========================================================================
    // CATEGORIAS
    // =========================================================================

    /**
     * @return array<string,mixed>
     */
    public function listarCategorias(): array
    {
        return $this->exito('Categorias consultadas correctamente.', [
            'categorias' => $this->repositorio->listarCategorias(),
        ]);
    }

    /**
     * Crea una categoria.
     *
     * @param array<string,mixed> $datos
     * @return array<string,mixed>
     */
    public function crearCategoria(array $datos): array
    {
        $errores = $this->validador->validarCategoria($datos);

        if ($errores !== []) {
            return $this->errorDeValidacion($errores);
        }

        $nombre = $this->validador->texto($datos['nombre']);

        if ($this->repositorio->existeCategoria($nombre)) {
            return $this->conflicto('Ya existe una categoria con ese nombre.', [
                'nombre' => 'El nombre ya esta en uso.',
            ]);
        }

        $id = $this->repositorio->crearCategoria(
            $nombre,
            $this->validador->texto($datos['descripcion'] ?? '') ?: null
        );

        return $this->exito(
            'Categoria creada satisfactoriamente.',
            ['categoria' => ['id' => $id, 'nombre' => $nombre]],
            201
        );
    }

    /**
     * Resuelve cuantos registros por pagina devolver.
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
