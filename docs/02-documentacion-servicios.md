# Documentación de los servicios de la API

**Evidencia GA7-220501096-AA5-EV03 — Diseño y desarrollo de servicios web (proyecto)**
Sistema de Showroom y Ventas · API REST v1.0.0

> Este documento describe **cada uno de los 37 servicios** publicados por la API:
> qué hace, quién puede invocarlo, qué recibe, qué devuelve y qué errores produce.

---

## 1. Convenciones generales

**URL base:** `http://localhost:8000`

**Formato:** JSON en ambos sentidos. Cabecera `Content-Type: application/json`.

**Codificación:** UTF-8.

### 1.1. Estructura única de respuesta

Todos los servicios responden con la misma envoltura:

```json
{
  "exito": true,
  "mensaje": "Texto legible para el usuario",
  "datos": { },
  "errores": { },
  "marcaTiempo": "2026-09-27T09:06:00-05:00"
}
```

| Campo | Presencia | Descripción |
|---|---|---|
| `exito` | Siempre | Resultado de la operación. |
| `mensaje` | Siempre | Mensaje para el usuario final. |
| `datos` | Solo si hay información que devolver. | Carga útil. |
| `errores` | Solo si hay errores de validación. | Detalle campo por campo. |
| `marcaTiempo` | Siempre | Momento de la respuesta, ISO-8601. |

### 1.2. Autenticación

Salvo los servicios marcados como públicos, todos exigen la cabecera:

```
Authorization: Bearer <token>
```

El token se obtiene en `POST /api/auth/login` y tiene una vigencia de 120 minutos.

### 1.3. Roles

| Rol | Puede |
|---|---|
| `administrador` | Todo: usuarios, catálogo, clientes, documentos y reportes. |
| `asesor` | Clientes, cotizaciones, pedidos y ajuste de inventario. Consulta el resto. |
| `consulta` | Solo lectura de catálogo, clientes, documentos y reportes. |

### 1.4. Paginación

Los listados aceptan `?pagina=` y `?porPagina=` (máximo 100) y devuelven:

```json
"paginacion": { "pagina": 1, "porPagina": 20, "total": 48, "totalPaginas": 3 }
```

### 1.5. Códigos de estado

| Código | Significado en esta API |
|---|---|
| `200` | Operación consultada o modificada correctamente. |
| `201` | Recurso creado. |
| `401` | Credenciales inválidas, o token ausente, inválido o expirado. |
| `403` | Autenticado pero sin el rol necesario; o cuenta inactiva. |
| `404` | El recurso solicitado no existe. |
| `405` | La ruta existe pero no admite ese verbo HTTP. |
| `409` | Conflicto: duplicado, transición de estado inválida o stock insuficiente. |
| `422` | Datos presentes pero inválidos. |
| `429` | Cuenta bloqueada por intentos fallidos. |
| `500` | Error interno no previsto. |

---

## 2. Índice de servicios

| # | Método | Ruta | Rol | Servicio |
|---|---|---|---|---|
| 1 | `GET` | `/api` | Público | Índice de la API |
| 2 | `GET` | `/api/salud` | Público | Estado del servicio |
| 3 | `POST` | `/api/auth/login` | Público | Iniciar sesión |
| 4 | `POST` | `/api/auth/registro` | Público | Registro público |
| 5 | `GET` | `/api/auth/perfil` | Autenticado | Perfil propio |
| 6 | `POST` | `/api/auth/cambiar-clave` | Autenticado | Cambiar contraseña |
| 7 | `GET` | `/api/usuarios` | Admin | Listar usuarios |
| 8 | `GET` | `/api/usuarios/{id}` | Admin | Consultar usuario |
| 9 | `POST` | `/api/usuarios` | Admin | Crear usuario |
| 10 | `PUT` | `/api/usuarios/{id}` | Admin | Actualizar usuario |
| 11 | `PATCH` | `/api/usuarios/{id}/estado` | Admin | Activar o desactivar usuario |
| 12 | `GET` | `/api/roles` | Admin | Listar roles |
| 13 | `GET` | `/api/categorias` | Lectura | Listar categorías |
| 14 | `POST` | `/api/categorias` | Admin | Crear categoría |
| 15 | `GET` | `/api/productos` | Lectura | Listar catálogo |
| 16 | `GET` | `/api/productos/{id}` | Lectura | Consultar producto |
| 17 | `POST` | `/api/productos` | Admin | Crear producto |
| 18 | `PUT` | `/api/productos/{id}` | Admin | Actualizar producto |
| 19 | `DELETE` | `/api/productos/{id}` | Admin | Descontinuar producto |
| 20 | `PATCH` | `/api/productos/{id}/stock` | Gestión | Ajustar inventario |
| 21 | `GET` | `/api/clientes` | Lectura | Listar clientes |
| 22 | `GET` | `/api/clientes/{id}` | Lectura | Consultar cliente |
| 23 | `POST` | `/api/clientes` | Gestión | Registrar cliente |
| 24 | `PUT` | `/api/clientes/{id}` | Gestión | Actualizar cliente |
| 25 | `PATCH` | `/api/clientes/{id}/estado` | Admin | Activar o desactivar cliente |
| 26 | `GET` | `/api/cotizaciones` | Lectura | Listar cotizaciones |
| 27 | `GET` | `/api/cotizaciones/{id}` | Lectura | Consultar cotización |
| 28 | `POST` | `/api/cotizaciones` | Gestión | Crear cotización |
| 29 | `PATCH` | `/api/cotizaciones/{id}/estado` | Gestión | Cambiar estado |
| 30 | `POST` | `/api/cotizaciones/{id}/convertir-pedido` | Gestión | Convertir en pedido |
| 31 | `GET` | `/api/pedidos` | Lectura | Listar pedidos |
| 32 | `GET` | `/api/pedidos/{id}` | Lectura | Consultar pedido |
| 33 | `POST` | `/api/pedidos` | Gestión | Crear pedido directo |
| 34 | `PATCH` | `/api/pedidos/{id}/estado` | Gestión | Cambiar estado |
| 35 | `GET` | `/api/reportes/ventas` | Lectura | Reporte de ventas |
| 36 | `GET` | `/api/reportes/productos-mas-cotizados` | Lectura | Productos más cotizados |
| 37 | `GET` | `/api/reportes/inventario-bajo` | Lectura | Reposición de inventario |

> **Gestión** = `administrador` o `asesor`. **Lectura** = cualquier rol autenticado.

---

## 3. Servicios del sistema

### 3.1. `GET /api` — Índice de la API

Devuelve la lista completa de endpoints publicados, con el rol que exige cada uno
y las reglas de negocio vigentes. Es la documentación en línea: se construye a
partir de la tabla de rutas, por lo que nunca se desactualiza respecto del código.

**Autenticación:** no requiere. **Parámetros:** ninguno.

**Respuesta `200`**

```json
{
  "exito": true,
  "mensaje": "API del Sistema de Showroom y Ventas.",
  "datos": {
    "version": "1.0.0",
    "totalEndpoints": 37,
    "reglas": { "porcentajeIva": 19, "diasVigenciaCotizacion": 15, "descuentoMaximo": 20 },
    "endpoints": [
      { "metodo": "GET", "ruta": "/api/productos", "descripcion": "...", "requiereToken": true, "roles": ["administrador","asesor","consulta"] }
    ]
  }
}
```

---

### 3.2. `GET /api/salud` — Estado del servicio

Comprobación de disponibilidad. Informa si la base de datos responde y cuántos
registros hay de cada entidad.

**Autenticación:** no requiere.

**Respuesta `200`**

```json
{
  "exito": true,
  "mensaje": "El servicio se encuentra operativo.",
  "datos": {
    "servicio": "API del Sistema de Showroom y Ventas",
    "version": "1.0.0",
    "entorno": "desarrollo",
    "motorBaseDatos": "sqlite",
    "baseDatos": "conectada",
    "registros": { "usuarios": 3, "clientes": 3, "productos": 8, "cotizaciones": 1, "pedidos": 0 },
    "phpVersion": "8.1.25"
  }
}
```

---

## 4. Servicios de autenticación

### 4.1. `POST /api/auth/login` — Iniciar sesión

Autentica al usuario y emite un token JWT que incluye su rol.

**Autenticación:** no requiere.

**Cuerpo**

| Campo | Tipo | Obligatorio | Regla |
|---|---|---|---|
| `usuario` | texto | Sí | Nombre de usuario **o** correo electrónico. |
| `contrasena` | texto | Sí | — |

```json
{ "usuario": "admin", "contrasena": "Admin2025" }
```

**Respuesta `200`**

```json
{
  "exito": true,
  "mensaje": "Autenticacion satisfactoria.",
  "datos": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "tipoToken": "Bearer",
    "expiraEnTexto": "2026-09-27T11:06:00-05:00",
    "usuario": { "id": 1, "nombreUsuario": "admin", "rol": "administrador" }
  }
}
```

**Errores**

| Código | Situación |
|---|---|
| `401` | Usuario inexistente o contraseña incorrecta. **El mensaje es idéntico en ambos casos**, para impedir la enumeración de usuarios. |
| `403` | La cuenta está inactiva. |
| `422` | Falta el usuario o la contraseña. |
| `429` | Cuenta bloqueada tras 5 intentos fallidos. Indica los minutos restantes. |

---

### 4.2. `POST /api/auth/registro` — Registro público

Crea una cuenta con el rol de menor privilegio (`consulta`).

> **Decisión de seguridad:** este servicio **ignora** el campo `rol` si se envía.
> De lo contrario cualquiera podría crearse una cuenta de administrador. Para
> crear usuarios con rol se usa `POST /api/usuarios`, que exige ser administrador.

**Cuerpo**

| Campo | Tipo | Obligatorio | Regla |
|---|---|---|---|
| `nombre_usuario` | texto | Sí | 4 a 50 caracteres; letras, números, `.`, `-`, `_`. |
| `correo` | texto | Sí | Formato válido, máximo 150 caracteres. |
| `contrasena` | texto | Sí | Mínimo 8 caracteres, al menos una letra y un número. |
| `confirmar_contrasena` | texto | No | Si se envía, debe coincidir. |
| `nombre_completo` | texto | No | Máximo 150 caracteres. |

**Respuesta `201`** — datos públicos del usuario creado. Nunca incluye el hash.

**Errores:** `422` datos inválidos · `409` usuario o correo ya registrados.

---

### 4.3. `GET /api/auth/perfil` — Perfil propio

Devuelve los datos del usuario dueño del token y la vigencia del mismo.

**Autenticación:** token válido.

**Respuesta `200`**

```json
{
  "datos": {
    "usuario": { "id": 1, "nombreUsuario": "admin", "rol": "administrador", "ultimoAcceso": "2026-09-27 09:05:12" },
    "tokenEmitido": "2026-09-27T09:06:00-05:00",
    "tokenExpira": "2026-09-27T11:06:00-05:00"
  }
}
```

**Errores:** `401` token ausente, inválido o expirado.

---

### 4.4. `POST /api/auth/cambiar-clave` — Cambiar contraseña

**Autenticación:** token válido.

**Cuerpo**

| Campo | Tipo | Obligatorio |
|---|---|---|
| `contrasena_actual` | texto | Sí |
| `contrasena_nueva` | texto | Sí |

> **Por qué se exige la contraseña actual:** sin ese requisito, quien robara un
> token podría apoderarse de la cuenta de forma permanente.

**Errores:** `401` la contraseña actual no es correcta · `422` la nueva no cumple
las reglas, o es igual a la actual.

---

## 5. Servicios de usuarios y roles

Todos exigen rol `administrador`.

### 5.1. `GET /api/usuarios` — Listar usuarios

**Parámetros de consulta:** `?busqueda=` (usuario, correo o nombre), `?rol=`,
`?pagina=`, `?porPagina=`.

**Respuesta `200`:** `datos.usuarios[]` y `datos.paginacion`.

### 5.2. `GET /api/usuarios/{id}` — Consultar usuario

**Errores:** `404` no existe.

### 5.3. `POST /api/usuarios` — Crear usuario

Mismos campos que el registro público, **más** `rol`
(`administrador`, `asesor` o `consulta`).

**Respuesta `201`.** **Errores:** `422`, `409`.

### 5.4. `PUT /api/usuarios/{id}` — Actualizar usuario

Permite cambiar `correo`, `nombre_completo` y `rol`. No cambia la contraseña:
para eso está `/api/auth/cambiar-clave`.

**Errores:** `404` no existe · `409` el correo pertenece a otro usuario · `422`.

### 5.5. `PATCH /api/usuarios/{id}/estado` — Activar o desactivar

**Cuerpo:** `{ "estado": "activo" | "inactivo" }`

> **Regla de negocio:** un administrador **no puede desactivarse a sí mismo**,
> porque quedaría sin forma de volver a entrar. Devuelve `409`.

### 5.6. `GET /api/roles` — Listar roles

Devuelve los tres roles con su descripción.

---

## 6. Servicios del catálogo

### 6.1. `GET /api/categorias` — Listar categorías

**Rol:** lectura. Devuelve cada categoría con su cantidad de productos.

### 6.2. `POST /api/categorias` — Crear categoría

**Rol:** administrador. **Cuerpo:** `nombre` (obligatorio, único, máx. 80),
`descripcion` (opcional).

**Errores:** `409` el nombre ya existe · `422`.

---

### 6.3. `GET /api/productos` — Listar catálogo

**Rol:** lectura.

**Parámetros de consulta**

| Parámetro | Descripción |
|---|---|
| `busqueda` | Texto libre sobre nombre, código y descripción. |
| `categoria` | Identificador de categoría. |
| `estado` | `activo` o `descontinuado`. |
| `stockBajo` | `1` devuelve solo los productos en o bajo su mínimo. |
| `pagina`, `porPagina` | Paginación. |

**Respuesta `200`**

```json
{
  "datos": {
    "productos": [{
      "id": 1, "codigo": "SOF-001", "nombre": "Sofa modular tres puestos",
      "categoria": { "id": 1, "nombre": "Salas" },
      "precioUnitario": 2850000, "stock": 6, "stockMinimo": 2,
      "stockBajo": false, "estado": "activo", "disponible": true
    }],
    "paginacion": { "pagina": 1, "porPagina": 20, "total": 8, "totalPaginas": 1 }
  }
}
```

### 6.4. `GET /api/productos/{id}` — Consultar producto

Devuelve el producto y sus **últimos 10 movimientos de inventario** (kardex).

**Errores:** `404`.

### 6.5. `POST /api/productos` — Crear producto

**Rol:** administrador.

| Campo | Tipo | Obligatorio | Regla |
|---|---|---|---|
| `codigo` | texto | Sí | Único, máximo 30 caracteres. |
| `nombre` | texto | Sí | Máximo 150 caracteres. |
| `descripcion` | texto | No | — |
| `categoria_id` | entero | No | Debe existir. |
| `precio_unitario` | número | Sí | No negativo. |
| `stock` | entero | No | Inicial, no negativo. Se registra como movimiento. |
| `stock_minimo` | entero | No | Umbral de reposición. |

> El stock inicial **no se escribe directamente** en la columna: entra como un
> movimiento de inventario, para que las existencias queden explicadas desde el
> primer día.

**Errores:** `409` código duplicado · `422`.

### 6.6. `PUT /api/productos/{id}` — Actualizar producto

Actualiza datos del producto. **No modifica el stock**: eso tiene su propio
servicio, para que todo cambio de existencias quede registrado.

### 6.7. `DELETE /api/productos/{id}` — Descontinuar producto

**Baja lógica.** El producto se marca como `descontinuado` pero se conserva,
porque aparece en cotizaciones y pedidos históricos que no deben alterarse.

**Errores:** `404` · `409` ya estaba descontinuado.

### 6.8. `PATCH /api/productos/{id}/stock` — Ajustar inventario

**Rol:** gestión.

**Cuerpo:** `{ "cantidad": 10, "motivo": "Compra a proveedor" }`
Cantidad **positiva suma, negativa resta**.

**Respuesta `200`:** producto actualizado, `stockAnterior` y `stockNuevo`.

**Errores:** `409` el ajuste dejaría el stock en negativo · `422` cantidad cero
o no numérica.

---

## 7. Servicios de clientes

### 7.1. `GET /api/clientes` — Listar clientes

**Rol:** lectura. **Filtros:** `?busqueda=` (nombre, documento o correo),
`?ciudad=`, `?estado=`, paginación.

### 7.2. `GET /api/clientes/{id}` — Consultar cliente

Devuelve el cliente e indica en `tieneDocumentos` si ya tiene cotizaciones o
pedidos asociados.

### 7.3. `POST /api/clientes` — Registrar cliente

**Rol:** gestión.

| Campo | Tipo | Obligatorio | Regla |
|---|---|---|---|
| `tipo_documento` | texto | No | `CC`, `NIT`, `CE` o `PAS`. Por defecto `CC`. |
| `numero_documento` | texto | Sí | Único, 5 a 30 caracteres alfanuméricos. |
| `nombre` | texto | Sí | Nombre completo o razón social, máx. 150. |
| `correo` | texto | No | Si se envía, debe tener formato válido. |
| `telefono` | texto | No | Máximo 30 caracteres. |
| `direccion`, `ciudad` | texto | No | — |

**Errores:** `409` documento ya registrado · `422`.

### 7.4. `PUT /api/clientes/{id}` — Actualizar cliente

Los campos ausentes conservan su valor actual.

### 7.5. `PATCH /api/clientes/{id}/estado` — Activar o desactivar

**Rol:** administrador. **Cuerpo:** `{ "estado": "activo" | "inactivo" }`

> No se elimina un cliente: debe seguir apareciendo en sus documentos históricos.
> Un cliente inactivo no puede recibir nuevas cotizaciones ni pedidos.

---

## 8. Servicios de cotizaciones

Una cotización es una propuesta económica con vigencia limitada. Su ciclo de vida:

```
borrador ──► enviada ──► aprobada ──► convertida
    │           │                     (se transforma en pedido)
    │           ├──► rechazada
    │           └──► vencida  (automático al expirar)
    └──► anulada
```

### 8.1. `GET /api/cotizaciones` — Listar cotizaciones

**Rol:** lectura.

**Filtros:** `?cliente=`, `?estado=`, `?desde=AAAA-MM-DD`, `?hasta=AAAA-MM-DD`,
paginación.

> Antes de responder, el servicio **marca como vencidas** las cotizaciones
> enviadas cuya fecha ya pasó, y lo informa en `datos.vencidasMarcadas`. Así el
> estado siempre está al día sin necesidad de una tarea programada.

El listado devuelve las cabeceras **sin el detalle de líneas**, para no cargar
innecesariamente la respuesta.

### 8.2. `GET /api/cotizaciones/{id}` — Consultar cotización

Devuelve la cotización completa —con sus líneas— y su **historial de estados**:
quién hizo cada cambio y cuándo.

**Respuesta `200`**

```json
{
  "datos": {
    "cotizacion": {
      "id": 3, "numero": "COT-000003",
      "cliente": { "id": 1, "nombre": "Laura Gomez Restrepo" },
      "asesor":  { "id": 2, "nombre": "asesor" },
      "estado": "aprobada",
      "totales": { "subtotal": 6852000, "descuento": 128000, "iva": 1301880, "total": 8153880 },
      "fechaVencimiento": "2026-10-12 09:06:00",
      "vigente": true, "diasParaVencer": 15,
      "lineas": [
        { "producto": { "id": 1, "codigo": "SOF-001" }, "descripcion": "Sofa modular tres puestos",
          "cantidad": 2, "precioUnitario": 2850000, "descuento": 0, "subtotal": 5700000 }
      ],
      "siguientesEstados": ["convertida", "anulada"]
    },
    "historial": [
      { "estado_anterior": null, "estado_nuevo": "borrador", "usuario": "asesor", "fecha": "2026-09-27 09:06:00" }
    ]
  }
}
```

### 8.3. `POST /api/cotizaciones` — Crear cotización

**Rol:** gestión.

**Cuerpo**

| Campo | Tipo | Obligatorio | Regla |
|---|---|---|---|
| `cliente_id` | entero | Sí | Debe existir y estar activo. |
| `observaciones` | texto | No | Notas libres. |
| `lineas` | arreglo | Sí | Entre 1 y 100 líneas. |
| `lineas[].producto_id` | entero | Sí | Debe existir y estar activo. |
| `lineas[].cantidad` | entero | Sí | Mayor que cero. |
| `lineas[].descuento_porcentaje` | número | No | Entre 0 y el máximo configurado (20 %). |

```json
{
  "cliente_id": 1,
  "observaciones": "Entrega en apartamento, piso 8",
  "lineas": [
    { "producto_id": 1, "cantidad": 2 },
    { "producto_id": 5, "cantidad": 4, "descuento_porcentaje": 10 }
  ]
}
```

> **REGLA CENTRAL DEL SISTEMA.** El precio y los totales **los calcula siempre el
> servidor**, tomando el precio del catálogo. Si el cliente envía
> `precio_unitario` o `subtotal`, se ignoran. Sin esta regla, cualquiera podría
> enviar un pedido de un sofá con `"total": 1` y el sistema lo aceptaría.

**Fórmula aplicada**

```
por línea:   bruto     = cantidad × precio_unitario
             descuento = bruto × (descuento_porcentaje / 100)
             subtotal  = bruto − descuento

documento:   subtotal  = suma de los subtotales de las líneas
             iva       = subtotal × 19 %
             total     = subtotal + iva
```

La cotización nace en estado `borrador`, con numeración consecutiva `COT-000001`
y vigencia de 15 días.

**Errores**

| Código | Situación |
|---|---|
| `422` | Datos inválidos, cliente inexistente, producto descontinuado, sin líneas, o descuento superior al máximo. |
| `409` | El cliente está inactivo. |

### 8.4. `PATCH /api/cotizaciones/{id}/estado` — Cambiar estado

**Rol:** gestión. **Cuerpo:** `{ "estado": "enviada", "observacion": "..." }`

El servicio hace cumplir la máquina de estados: solo acepta destinos alcanzables
desde el estado actual. El campo `siguientesEstados` de la consulta indica cuáles son.

**Errores**

| Código | Situación |
|---|---|
| `422` | El estado no es uno de los alcanzables. La respuesta lista las opciones válidas. |
| `409` | La cotización ya está cerrada, o se intentó pasar a `convertida` (use el servicio 8.5). |

### 8.5. `POST /api/cotizaciones/{id}/convertir-pedido` — Convertir en pedido

**Rol:** gestión.

Es la operación más delicada del sistema: toca dos documentos y debe dejarlos
coherentes, por lo que se ejecuta dentro de una transacción.

**Cuerpo (opcional):** `direccion_entrega`, `fecha_entrega_estimada`.

**Qué hace**

1. Verifica que la cotización esté **aprobada**.
2. Verifica que no haya generado ya un pedido.
3. **Recalcula exigiendo existencias**: al pasar a pedido, el showroom se
   compromete a entregar.
4. Crea el pedido **conservando los importes de la cotización**, porque el
   cliente aprobó esos valores. Si el precio del catálogo cambió entre tanto,
   se respeta lo cotizado.
5. Marca la cotización como `convertida`.

**Respuesta `201`:** el pedido creado y la cotización actualizada.

**Errores**

| Código | Situación |
|---|---|
| `409` | La cotización no está aprobada, ya fue convertida, o no hay existencias suficientes (la respuesta detalla qué falta). |
| `404` | La cotización no existe. |

---

## 9. Servicios de pedidos

Un pedido es la venta en firme. Puede nacer de una cotización aprobada o
crearse directamente en el showroom. Su ciclo de vida:

```
pendiente ──► confirmado ──► en_preparacion ──► despachado ──► entregado
     │            │                │                  │
     └──► anulado ◄────────────────┴──────────────────┘
```

### Efecto sobre el inventario

| Transición | Efecto |
|---|---|
| a `confirmado` | **Descuenta** el stock de cada producto del pedido. |
| a `anulado`, habiendo estado confirmado | **Devuelve** el stock. |
| resto de transiciones | Ninguno. |

Confirmar es el momento en que el showroom se compromete con la entrega, así
que es ahí —y no antes— cuando la mercancía queda reservada.

### 9.1. `GET /api/pedidos` — Listar pedidos

**Rol:** lectura. **Filtros:** `?cliente=`, `?estado=`, `?desde=`, `?hasta=`,
paginación. Devuelve las cabeceras sin el detalle de líneas.

### 9.2. `GET /api/pedidos/{id}` — Consultar pedido

Devuelve el pedido completo, la cotización de origen si la hubo, y su historial
de estados.

**Errores:** `404`.

### 9.3. `POST /api/pedidos` — Crear pedido directo

**Rol:** gestión. Mismo cuerpo que la cotización (`cliente_id` y `lineas`), más
`direccion_entrega` y `fecha_entrega_estimada` opcionales.

> A diferencia de la cotización, **este servicio sí exige existencias
> suficientes** desde el primer momento.

**Respuesta `201`.** El pedido nace en estado `pendiente`; hay que confirmarlo
para reservar las existencias.

**Errores**

| Código | Situación |
|---|---|
| `409` | Existencias insuficientes (detalla producto, disponible y requerido), o cliente inactivo. |
| `422` | Datos inválidos o cliente inexistente. |

### 9.4. `PATCH /api/pedidos/{id}/estado` — Cambiar estado

**Rol:** gestión. **Cuerpo:** `{ "estado": "confirmado", "observacion": "..." }`

**Respuesta `200`:** el pedido actualizado. El mensaje indica explícitamente si
hubo movimiento de inventario:

```json
{ "mensaje": "Pedido PED-000001 confirmado. Se descontaron las existencias." }
```

**Errores**

| Código | Situación |
|---|---|
| `409` | Transición no permitida; el pedido ya está cerrado; o no hay existencias para confirmar. |
| `422` | El estado no es uno de los alcanzables. |

---

## 10. Servicios de reportes

### 10.1. `GET /api/reportes/ventas` — Resumen de ventas

**Rol:** lectura. **Parámetros:** `?desde=AAAA-MM-DD&hasta=AAAA-MM-DD`.
Sin parámetros toma el mes en curso. Los pedidos anulados no se cuentan.

**Respuesta `200`**

```json
{
  "datos": {
    "periodo": { "desde": "2026-09-01", "hasta": "2026-09-27" },
    "resumen": { "totalPedidos": 12, "subtotal": 48200000, "descuentos": 1240000,
                 "iva": 9158000, "total": 57358000 },
    "porEstado": [ { "estado": "entregado", "cantidad": 7, "valor": 33100000 } ]
  }
}
```

**Errores:** `422` la fecha inicial es posterior a la final.

### 10.2. `GET /api/reportes/productos-mas-cotizados`

**Rol:** lectura. **Parámetros:** `?limite=` (1 a 50, por defecto 10).

Devuelve, por producto, las unidades solicitadas y en cuántas cotizaciones
distintas aparece. Sirve para decidir qué reponer y qué exhibir.

### 10.3. `GET /api/reportes/inventario-bajo`

**Rol:** lectura. Sin parámetros.

Lista los productos activos cuyo stock llegó a su mínimo, ordenados de menor a
mayor existencia. Es la orden de compra del showroom.

---

## 11. Seguridad aplicada en los servicios

| Riesgo (OWASP) | Control | Dónde |
|---|---|---|
| A01 Control de acceso roto | Middleware de rol declarado junto a cada ruta | `public/index.php`, `src/Middleware/Seguridad.php` |
| A02 Fallos criptográficos | bcrypt con sal automática | `AutenticacionServicio` |
| A03 Inyección SQL | PDO con consultas preparadas en todos los repositorios | `src/Repositorios/` |
| A03 XSS almacenado | `strip_tags()` al normalizar entradas | `Validador::texto()` |
| A04 Diseño inseguro | Totales calculados en el servidor; máquina de estados | `CalculadoraDeTotales`, modelos |
| A05 Configuración insegura | Solo `public/` expuesta; errores genéricos en producción | Estructura del proyecto |
| A07 Fallos de identificación | Bloqueo tras 5 intentos; mensajes uniformes | `AutenticacionServicio` |

### Tres decisiones que conviene poder explicar

**Por qué el precio no se acepta del cliente.** Es la diferencia entre una API y
un formulario. El cliente dice *qué* quiere y *cuánto*; el servidor decide
*cuánto cuesta*.

**Por qué existe una máquina de estados.** Sin ella, un `PATCH` podría devolver
un pedido entregado a `pendiente` y descuadrar el inventario. Las transiciones
válidas se declaran en el modelo y el servicio las hace cumplir.

**Por qué las bajas son lógicas.** Borrar un producto o un cliente rompería las
cotizaciones y pedidos que lo referencian. Se marcan como inactivos y dejan de
admitir documentos nuevos, pero los históricos siguen íntegros.

---

## 12. Trazabilidad: requisito → servicio → prueba

| Requisito del proyecto | Servicios | Casos de prueba |
|---|---|---|
| Autenticar usuarios con roles | 3, 5, 6, 12 | CP-01 a CP-06 |
| Administrar usuarios | 7 a 11 | CP-05, CP-30, CP-31 |
| Mantener el catálogo | 13 a 19 | CP-07, CP-08, CP-12 |
| Controlar el inventario | 16, 20, 37 | CP-09, CP-10, CP-11 |
| Registrar clientes | 21 a 25 | CP-27, CP-28 |
| Emitir cotizaciones | 26 a 29 | CP-13 a CP-19 |
| Convertir cotización en pedido | 30 | CP-20, CP-21, CP-22 |
| Gestionar pedidos y existencias | 31 a 34 | CP-23 a CP-26 |
| Reportes para la toma de decisiones | 35, 36, 37 | CP-34, CP-35 |
| Trazabilidad de los documentos | 27, 32 | CP-32, CP-33 |
