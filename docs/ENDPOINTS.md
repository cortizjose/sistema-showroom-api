# ENDPOINTS DE LA API

**Evidencia GA7-220501096-AA5-EV04 — API del proyecto**
Sistema de Showroom y Ventas · API REST v1.0.0

> Entregable explícito de la evidencia: *"Entregar los ENDPOINT de las API."*
> Este archivo es la referencia rápida de los **37 servicios**. La descripción
> detallada de cada uno (cuerpo, respuesta y errores) está en
> `docs/02-documentacion-servicios.md`.

---

## Datos de conexión

| | |
|---|---|
| **URL base** | `http://localhost:8000` |
| **Formato** | JSON (`Content-Type: application/json`) |
| **Autenticación** | `Authorization: Bearer <token>` |
| **Token** | Se obtiene en `POST /api/auth/login`, vigencia 120 minutos |

### Roles

| Rol | Alcance |
|---|---|
| `administrador` | Todo: usuarios, catálogo, clientes, documentos y reportes. |
| `asesor` | Clientes, cotizaciones, pedidos e inventario. Lee el resto. |
| `consulta` | Solo lectura. |

### Cuentas de prueba

| Usuario | Contraseña | Rol |
|---|---|---|
| `admin` | `Admin2025` | administrador |
| `asesor` | `Asesor2025` | asesor |
| `consulta` | `Consulta2025` | consulta |

---

## Tabla completa de endpoints

**Rol requerido:** `—` público · `Aut.` cualquier autenticado · `Adm` administrador ·
`Ges` administrador o asesor · `Lec` cualquier rol autenticado.

### 1. Sistema

| # | Método | Endpoint | Rol | Descripción | Éxito | Errores |
|---|---|---|---|---|---|---|
| 1 | `GET` | `/api` | — | Índice de la API con todos los endpoints. | `200` | — |
| 2 | `GET` | `/api/salud` | — | Estado del servicio y de la base de datos. | `200` | — |

### 2. Autenticación

| # | Método | Endpoint | Rol | Descripción | Éxito | Errores |
|---|---|---|---|---|---|---|
| 3 | `POST` | `/api/auth/login` | — | Autentica y emite un token JWT. | `200` | `401` `403` `422` `429` |
| 4 | `POST` | `/api/auth/registro` | — | Registro público con rol de consulta. | `201` | `409` `422` |
| 5 | `GET` | `/api/auth/perfil` | Aut. | Datos del usuario autenticado. | `200` | `401` |
| 6 | `POST` | `/api/auth/cambiar-clave` | Aut. | Cambia la contraseña propia. | `200` | `401` `422` |

### 3. Usuarios y roles

| # | Método | Endpoint | Rol | Descripción | Éxito | Errores |
|---|---|---|---|---|---|---|
| 7 | `GET` | `/api/usuarios` | Adm | Listado paginado de usuarios. | `200` | `401` `403` |
| 8 | `GET` | `/api/usuarios/{id}` | Adm | Consulta un usuario. | `200` | `404` |
| 9 | `POST` | `/api/usuarios` | Adm | Crea un usuario con rol. | `201` | `409` `422` |
| 10 | `PUT` | `/api/usuarios/{id}` | Adm | Actualiza correo, nombre y rol. | `200` | `404` `409` `422` |
| 11 | `PATCH` | `/api/usuarios/{id}/estado` | Adm | Activa o desactiva una cuenta. | `200` | `404` `409` `422` |
| 12 | `GET` | `/api/roles` | Adm | Lista los roles del sistema. | `200` | `403` |

### 4. Catálogo e inventario

| # | Método | Endpoint | Rol | Descripción | Éxito | Errores |
|---|---|---|---|---|---|---|
| 13 | `GET` | `/api/categorias` | Lec | Categorías con su conteo de productos. | `200` | `401` |
| 14 | `POST` | `/api/categorias` | Adm | Crea una categoría. | `201` | `409` `422` |
| 15 | `GET` | `/api/productos` | Lec | Catálogo paginado con filtros. | `200` | `401` |
| 16 | `GET` | `/api/productos/{id}` | Lec | Producto con sus movimientos de stock. | `200` | `404` |
| 17 | `POST` | `/api/productos` | Adm | Crea un producto. | `201` | `409` `422` |
| 18 | `PUT` | `/api/productos/{id}` | Adm | Actualiza un producto. | `200` | `404` `409` `422` |
| 19 | `DELETE` | `/api/productos/{id}` | Adm | Baja lógica (descontinuar). | `200` | `404` `409` |
| 20 | `PATCH` | `/api/productos/{id}/stock` | Ges | Ajusta existencias con trazabilidad. | `200` | `404` `409` `422` |

**Filtros de `/api/productos`:** `?busqueda=` `?categoria=` `?estado=` `?stockBajo=1`
`?pagina=` `?porPagina=`

### 5. Clientes

| # | Método | Endpoint | Rol | Descripción | Éxito | Errores |
|---|---|---|---|---|---|---|
| 21 | `GET` | `/api/clientes` | Lec | Listado paginado con filtros. | `200` | `401` |
| 22 | `GET` | `/api/clientes/{id}` | Lec | Consulta un cliente. | `200` | `404` |
| 23 | `POST` | `/api/clientes` | Ges | Registra un cliente. | `201` | `409` `422` |
| 24 | `PUT` | `/api/clientes/{id}` | Ges | Actualiza un cliente. | `200` | `404` `409` `422` |
| 25 | `PATCH` | `/api/clientes/{id}/estado` | Adm | Activa o desactiva un cliente. | `200` | `404` `422` |

**Filtros de `/api/clientes`:** `?busqueda=` `?ciudad=` `?estado=` `?pagina=` `?porPagina=`

### 6. Cotizaciones

| # | Método | Endpoint | Rol | Descripción | Éxito | Errores |
|---|---|---|---|---|---|---|
| 26 | `GET` | `/api/cotizaciones` | Lec | Listado paginado con filtros. | `200` | `401` |
| 27 | `GET` | `/api/cotizaciones/{id}` | Lec | Cotización con detalle e historial. | `200` | `404` |
| 28 | `POST` | `/api/cotizaciones` | Ges | Crea una cotización. | `201` | `409` `422` |
| 29 | `PATCH` | `/api/cotizaciones/{id}/estado` | Ges | Cambia el estado. | `200` | `404` `409` `422` |
| 30 | `POST` | `/api/cotizaciones/{id}/convertir-pedido` | Ges | Convierte en pedido. | `201` | `404` `409` |

**Filtros:** `?cliente=` `?estado=` `?desde=` `?hasta=` `?pagina=` `?porPagina=`

**Estados:** `borrador` → `enviada` → `aprobada` → `convertida`
(con salidas a `rechazada`, `vencida` y `anulada`)

### 7. Pedidos

| # | Método | Endpoint | Rol | Descripción | Éxito | Errores |
|---|---|---|---|---|---|---|
| 31 | `GET` | `/api/pedidos` | Lec | Listado paginado con filtros. | `200` | `401` |
| 32 | `GET` | `/api/pedidos/{id}` | Lec | Pedido con detalle e historial. | `200` | `404` |
| 33 | `POST` | `/api/pedidos` | Ges | Crea un pedido directo. | `201` | `409` `422` |
| 34 | `PATCH` | `/api/pedidos/{id}/estado` | Ges | Cambia el estado. | `200` | `404` `409` `422` |

**Estados:** `pendiente` → `confirmado` → `en_preparacion` → `despachado` → `entregado`
(con salida a `anulado` desde cualquiera)

> **Efecto sobre el inventario:** pasar a `confirmado` **descuenta** el stock;
> pasar a `anulado` desde un estado confirmado lo **devuelve**.

### 8. Reportes

| # | Método | Endpoint | Rol | Descripción | Éxito | Errores |
|---|---|---|---|---|---|---|
| 35 | `GET` | `/api/reportes/ventas` | Lec | Resumen de ventas de un periodo. | `200` | `422` |
| 36 | `GET` | `/api/reportes/productos-mas-cotizados` | Lec | Demanda por producto. | `200` | `401` |
| 37 | `GET` | `/api/reportes/inventario-bajo` | Lec | Productos bajo su mínimo. | `200` | `401` |

**Parámetros:** `/ventas` acepta `?desde=AAAA-MM-DD&hasta=AAAA-MM-DD`;
`/productos-mas-cotizados` acepta `?limite=` (1 a 50).

---

## Estructura de las respuestas

Todos los endpoints responden con la misma envoltura:

```json
{
  "exito": true,
  "mensaje": "Texto legible para el usuario",
  "datos": { },
  "errores": { },
  "marcaTiempo": "2026-09-27T15:45:00-05:00"
}
```

Los listados añaden paginación dentro de `datos`:

```json
"paginacion": { "pagina": 1, "porPagina": 20, "total": 48, "totalPaginas": 3 }
```

---

## Códigos de estado

| Código | Significado en esta API |
|---|---|
| `200` | Consulta o modificación correcta. |
| `201` | Recurso creado. |
| `401` | Credenciales inválidas, o token ausente, inválido o expirado. |
| `403` | Autenticado pero sin el rol necesario, o cuenta inactiva. |
| `404` | El recurso no existe. |
| `405` | La ruta existe pero no admite ese verbo HTTP. |
| `409` | Conflicto: duplicado, transición inválida o stock insuficiente. |
| `422` | Datos presentes pero inválidos. |
| `429` | Cuenta bloqueada por intentos fallidos. |
| `500` | Error interno no previsto. |

---

## Ejemplos de uso

### Obtener un token

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d "{\"usuario\":\"admin\",\"contrasena\":\"Admin2025\"}"
```

### Consultar el catálogo con el token

```bash
curl http://localhost:8000/api/productos?busqueda=sofa \
  -H "Authorization: Bearer <token>"
```

### Crear una cotización

```bash
curl -X POST http://localhost:8000/api/cotizaciones \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d "{\"cliente_id\":1,\"lineas\":[{\"producto_id\":1,\"cantidad\":2}]}"
```

> Los precios y totales **los calcula el servidor** tomando el precio del
> catálogo. Cualquier `precio_unitario` o `subtotal` que envíe el cliente
> se ignora.

---

## La API se documenta a sí misma

`GET /api` devuelve esta misma lista generada desde la tabla de rutas del
enrutador, por lo que nunca queda desactualizada respecto del código:

```bash
curl http://localhost:8000/api
```
