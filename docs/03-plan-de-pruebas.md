# Plan de pruebas

**Evidencia GA7-220501096-AA5-EV03**
Sistema de Showroom y Ventas · API REST

---

## 1. Objetivo

Verificar que los 37 servicios de la API cumplen el contrato documentado en
[`02-documentacion-servicios.md`](02-documentacion-servicios.md) y que las reglas
de negocio del showroom se hacen cumplir en todos los caminos, incluidos los de
error.

## 2. Estrategia

| Tipo | Herramienta | Cobertura |
|---|---|---|
| Integración automatizada | `scripts/pruebas.php` | Servicios + repositorios + base de datos. |
| API manual | Postman | Contrato HTTP, códigos de estado, permisos. |
| Funcional | `public/cliente/index.html` | Flujo de extremo a extremo. |

Las pruebas automatizadas se ejecutan sobre una base **SQLite temporal**, por lo
que nunca alteran los datos reales.

## 3. Ejecución

```bash
php scripts/pruebas.php
```

Devuelve código de salida `0` si todas pasan, útil para integración continua.

---

## 4. Casos de prueba

### 4.1. Autenticación y roles

| ID | Caso | Esperado | Resultado |
|---|---|---|---|
| CP-01 | Credenciales correctas | `200` + mensaje de autenticación satisfactoria + token JWT | ✅ |
| CP-02 | El token transporta el rol | La carga útil contiene `rol: administrador` | ✅ |
| CP-03 | Contraseña incorrecta | `401` error en la autenticación | ✅ |
| CP-04 | Usuario inexistente | `401` con **mensaje idéntico** al de CP-03 (anti-enumeración) | ✅ |
| CP-05 | Correo duplicado al registrar | `409` conflicto | ✅ |
| CP-06 | Contraseña nueva igual a la actual | `422` rechazado | ✅ |

### 4.2. Catálogo e inventario

| ID | Caso | Esperado | Resultado |
|---|---|---|---|
| CP-07 | Código de producto duplicado | `409` | ✅ |
| CP-08 | Precio negativo | `422` | ✅ |
| CP-09 | El stock inicial genera un movimiento de inventario | 1 movimiento registrado | ✅ |
| CP-10 | Ajuste positivo de stock (10 + 5) | Stock resultante 15 | ✅ |
| CP-11 | Ajuste que dejaría el stock negativo | `409` rechazado | ✅ |
| CP-12 | Búsqueda en el catálogo | Encuentra el producto | ✅ |

### 4.3. Cotizaciones

| ID | Caso | Esperado | Resultado |
|---|---|---|---|
| CP-13 | Crear cotización válida | `201` | ✅ |
| CP-14 | Cálculo de totales con IVA del 19 % | 2.000.000 + 380.000 = 2.380.000 | ✅ |
| CP-15 | Estado inicial y numeración | `borrador`, número `COT-000001` | ✅ |
| CP-16 | **Seguridad:** el cliente envía `precio_unitario: 1` | Se ignora; se usa el precio del catálogo | ✅ |
| CP-17 | Descuento del 50 %, superior al máximo del 20 % | `422` | ✅ |
| CP-18 | Cotización sin líneas | `422` | ✅ |
| CP-19 | Saltar de `borrador` a `aprobada` | Rechazado por la máquina de estados | ✅ |

### 4.4. Pedidos e inventario

| ID | Caso | Esperado | Resultado |
|---|---|---|---|
| CP-20 | Convertir una cotización en borrador | `409` | ✅ |
| CP-21 | Convertir una cotización aprobada | `201` + cotización marcada `convertida` | ✅ |
| CP-22 | Convertir la misma cotización dos veces | `409` | ✅ |
| CP-23 | Confirmar el pedido | Descuenta 2 unidades del inventario | ✅ |
| CP-24 | Anular el pedido confirmado | Devuelve las 2 unidades | ✅ |
| CP-25 | Cambiar el estado de un pedido anulado | `409`, documento cerrado | ✅ |
| CP-26 | Pedido de 9.999 unidades sin existencias | `409` con el detalle del faltante | ✅ |

### 4.5. Reglas transversales

| ID | Caso | Esperado | Resultado |
|---|---|---|---|
| CP-27 | Cotizar a un cliente inexistente | `422` | ✅ |
| CP-28 | Cotizar a un cliente inactivo | `409` | ✅ |
| CP-29 | Cotizar un producto descontinuado | `422` | ✅ |
| CP-30 | Un administrador se desactiva a sí mismo | `409` | ✅ |
| CP-31 | **Seguridad:** el listado de usuarios nunca expone el hash | Ausente en la respuesta | ✅ |

### 4.6. Trazabilidad y reportes

| ID | Caso | Esperado | Resultado |
|---|---|---|---|
| CP-32 | Historial de estados de la cotización | Al menos 4 registros | ✅ |
| CP-33 | Historial de estados del pedido | Al menos 3 registros | ✅ |
| CP-34 | Reporte de ventas y rango de fechas invertido | Se genera / `422` | ✅ |
| CP-35 | Reporte de productos más cotizados | Devuelve datos | ✅ |

### 4.7. Seguridad: inyección SQL

| ID | Caso | Esperado | Resultado |
|---|---|---|---|
| CP-36 | `admin' OR '1'='1` en el login | `401`, tratado como texto literal | ✅ |
| CP-36 | `'; DROP TABLE clientes; --` en un filtro de búsqueda | La tabla permanece intacta | ✅ |

---

## 5. Resultado de la ejecución

```
========================================================================
 RESUMEN DE LA EJECUCION
========================================================================
 Pruebas ejecutadas : 46
 Pruebas exitosas   : 46
 Pruebas fallidas   : 0
========================================================================
 RESULTADO: TODAS LAS PRUEBAS FUERON SATISFACTORIAS.
========================================================================
```

**36 casos de prueba · 46 verificaciones · 0 fallos.**

La salida completa está en [`scripts/resultado-pruebas.txt`](../scripts/resultado-pruebas.txt).

---

## 6. Prueba manual del flujo comercial completo

Ejecutada contra el servidor real con cURL. Secuencia y resultados obtenidos:

| # | Operación | Resultado |
|---|---|---|
| 1 | `GET /api` | `200` — 37 endpoints publicados |
| 2 | `GET /api/productos` sin token | `401` |
| 3 | `POST /api/auth/login` (admin) | `200` — token emitido con rol |
| 4 | `POST /api/cotizaciones` (2 sofás + 4 sillas con 10 % dto.) | `201` — COT-000003, total 8.153.880 |
| 5 | `PATCH .../estado` saltando a `aprobada` | `422` — transición inválida |
| 6 | `PATCH .../estado` → `enviada` → `aprobada` | `200` cada una |
| 7 | `POST .../convertir-pedido` | `201` — PED-000001 |
| 8 | `POST .../convertir-pedido` de nuevo | `409` — ya convertida |
| 9 | `PATCH /api/pedidos/1/estado` → `confirmado` | `200` — stock 6→4 y 24→20 |
| 10 | `PATCH /api/pedidos/1/estado` → `anulado` | `200` — stock 4→6 y 20→24 |
| 11 | `POST /api/cotizaciones` con rol `consulta` | `403` — rol insuficiente |
| 12 | `GET /api/productos` con rol `consulta` | `200` — lectura permitida |

**Verificación del cálculo del paso 4**

```
2 × 2.850.000                      = 5.700.000
4 ×   320.000 − 10 %               = 1.152.000   (descuento 128.000)
                          subtotal = 6.852.000
                     IVA 19 %      = 1.301.880
                             total = 8.153.880
```

---

## 7. Conclusión

Se ejecutaron **36 casos de prueba** que produjeron **46 verificaciones**, todas
satisfactorias, más **12 comprobaciones manuales** del flujo comercial completo
contra el servidor real.

Las pruebas cubren los tres aspectos que definen la calidad de esta API:

- **Funcional:** el ciclo cotización → aprobación → pedido → despacho funciona
  de extremo a extremo, y el inventario cuadra en cada paso.
- **De reglas:** las 14 reglas de negocio se hacen cumplir, incluidas las que
  impiden operaciones aparentemente inocentes como convertir dos veces la misma
  cotización.
- **De seguridad:** los importes no son manipulables desde el cliente, los
  permisos por rol se respetan, el hash nunca sale del servidor y la inyección
  SQL no tiene efecto.
