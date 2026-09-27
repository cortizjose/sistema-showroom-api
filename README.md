# API del Sistema de Showroom y Ventas

**Evidencia GA7-220501096-AA5-EV03 — Diseño y desarrollo de servicios web (proyecto)**
Actividad de aprendizaje GA7-220501096-AA5: *Crear servicios web, de acuerdo con el diseño.*
Programa: Análisis y Desarrollo de Software — SENA.

---

## 1. Qué hace este sistema

Administra la operación comercial de un **showroom de muebles**: el catálogo que
se exhibe, los clientes que lo visitan, las cotizaciones que se les entregan y
los pedidos en que algunas de esas cotizaciones se convierten.

Está construido como una **API REST** para que la misma lógica pueda consumirse
desde una aplicación web, una app móvil para los asesores en sala o un panel
administrativo.

Parte del servicio de autenticación de la evidencia **AA5-EV01** y lo amplía
hasta un sistema completo: **37 servicios** organizados en siete módulos.

---

## 2. Puesta en marcha en tres pasos

**Requisito:** PHP 8.0 o superior. Si tiene XAMPP, ya lo tiene.

```bash
# 1. Cargar los datos de ejemplo (productos, clientes y usuarios)
php scripts/sembrar-datos.php

# 2. Levantar el servicio
php -S localhost:8000 -t public public/index.php

# 3. Abrir el cliente de demostración
#    http://localhost:8000/cliente/index.html
```

En Windows también sirve hacer doble clic en `scripts\iniciar-servidor.bat`.

La base de datos SQLite y sus tablas **se crean solas** en el primer arranque.
No hay que instalar dependencias ni configurar MySQL.

### Cuentas de prueba

| Usuario | Contraseña | Rol | Puede |
|---|---|---|---|
| `admin` | `Admin2025` | administrador | Todo |
| `asesor` | `Asesor2025` | asesor | Clientes, cotizaciones, pedidos, inventario |
| `consulta` | `Consulta2025` | consulta | Solo lectura |

---

## 3. Los 37 servicios, de un vistazo

| Módulo | Servicios | Ejemplos |
|---|---|---|
| Sistema | 2 | `GET /api`, `GET /api/salud` |
| Autenticación | 4 | `POST /api/auth/login`, `GET /api/auth/perfil` |
| Usuarios y roles | 6 | `GET /api/usuarios`, `PATCH /api/usuarios/{id}/estado` |
| Catálogo e inventario | 8 | `GET /api/productos`, `PATCH /api/productos/{id}/stock` |
| Clientes | 5 | `POST /api/clientes`, `PUT /api/clientes/{id}` |
| Cotizaciones | 5 | `POST /api/cotizaciones`, `POST /api/cotizaciones/{id}/convertir-pedido` |
| Pedidos | 4 | `POST /api/pedidos`, `PATCH /api/pedidos/{id}/estado` |
| Reportes | 3 | `GET /api/reportes/ventas` |

La documentación completa de **cada uno** está en
[`docs/02-documentacion-servicios.md`](docs/02-documentacion-servicios.md).

La API también se documenta a sí misma: `GET /api` devuelve la lista de
endpoints con el rol que exige cada uno, generada desde la tabla de rutas.

---

## 4. El flujo central del sistema

```
  Cliente visita el showroom
            │
            ▼
   ┌─────────────────┐   POST /api/cotizaciones
   │   COTIZACIÓN    │   El servidor calcula precios, descuentos, IVA y total
   │   (borrador)    │   Vigencia: 15 días
   └────────┬────────┘
            │ PATCH .../estado
            ▼
   ┌─────────────────┐
   │     enviada     │──► vencida (automático a los 15 días)
   └────────┬────────┘──► rechazada
            │ el cliente acepta
            ▼
   ┌─────────────────┐   POST .../convertir-pedido
   │    aprobada     │   Verifica existencias
   └────────┬────────┘
            ▼
   ┌─────────────────┐   PATCH /api/pedidos/{id}/estado
   │     PEDIDO      │   confirmado ──► DESCUENTA INVENTARIO
   │   (pendiente)   │   anulado    ──► DEVUELVE INVENTARIO
   └─────────────────┘
```

---

## 5. Cinco decisiones de diseño que conviene poder explicar

**Los precios los pone el servidor, no el cliente.** Es la diferencia entre una
API y un formulario. El cliente dice *qué* quiere y *cuánto*; el servidor decide
*cuánto cuesta*. Si el cliente envía `"precio_unitario": 1`, se ignora.

**Los documentos tienen máquina de estados.** Sin ella, un `PATCH` podría
devolver un pedido entregado a `pendiente` y descuadrar el inventario. Las
transiciones válidas se declaran en el modelo y el servicio las hace cumplir.

**El inventario se mueve con trazabilidad.** No hay ningún `UPDATE` suelto sobre
la columna `stock`: cada cambio pasa por un método que registra el antes, el
después, el motivo y el documento que lo causó.

**Las bajas son lógicas.** Borrar un producto rompería las cotizaciones que lo
referencian. Se marca como descontinuado: deja de admitir documentos nuevos,
pero los históricos siguen íntegros.

**El permiso se declara junto a la ruta.** Así ningún endpoint queda
desprotegido por olvido:

```php
$enrutador->post('/api/productos', [$catalogo, 'crear'], ['autenticado', ADMIN]);
```

---

## 6. Arquitectura

```
Cliente HTTP
     │
     ▼
public/index.php ──► Enrutador ──► Middleware ──► Controlador
                     (rutas y      (token y        (traduce HTTP
                      parámetros)   rol)            ↔ negocio)
                                                        │
                                                        ▼
                                                    Servicio
                                              (reglas del showroom)
                                                        │
                                                        ▼
                                                   Repositorio
                                             (consultas preparadas)
                                                        │
                                                        ▼
                                                  Base de datos
```

Cada capa solo conoce a la inmediatamente inferior. La lógica de cotizar es la
misma se invoque desde la API, desde un script o desde una futura app de
escritorio.

```
sistema-showroom-api/
├── config/config.php              Configuración y reglas de negocio
├── database/esquema_mysql.sql     DDL para MySQL
├── docs/                          Diseño, servicios, pruebas, versionado
├── postman/                       Colección de 47 peticiones
├── public/                        Única carpeta expuesta
│   ├── index.php                  Front Controller y tabla de rutas
│   └── cliente/index.html         Cliente de demostración
├── scripts/                       Pruebas, datos de ejemplo, arranque
└── src/
    ├── arranque.php               Autoloader PSR-4 e inyección de dependencias
    ├── Nucleo/                    Solicitud, Respuesta, Enrutador, Contexto
    ├── Middleware/                Autenticación y autorización por rol
    ├── BaseDatos/                 Conexión PDO y migraciones
    ├── Modelos/                   Entidades del dominio
    ├── Repositorios/              Acceso a datos (todo el SQL)
    ├── Servicios/                 Reglas de negocio
    ├── Validacion/                Validación de entradas
    └── Controladores/             Traducción HTTP ↔ negocio
```

---

## 7. Pruebas

### 7.1. Automatizadas

```bash
php scripts/pruebas.php
```

**36 casos de prueba · 46 verificaciones · 0 fallos.** Trabajan sobre una base
SQLite temporal, por lo que no alteran los datos reales.

### 7.2. Con Postman

Importar los dos archivos de `postman/` y ejecutar **Run collection**.

**47 peticiones · 87 aserciones · 0 fallos.** La carpeta *04 - Flujo comercial
completo* recorre cotización → aprobación → pedido → confirmación y **verifica
que el inventario baje y vuelva a subir** al anular.

Salida de una ejecución real en
[`postman/resultado-ejecucion-newman.txt`](postman/resultado-ejecucion-newman.txt).

### 7.3. Ejemplo con cURL

```bash
curl -X POST http://localhost:8000/api/auth/login -H "Content-Type: application/json" -d "{\"usuario\":\"admin\",\"contrasena\":\"Admin2025\"}"
```

---

## 8. Usar MySQL en lugar de SQLite

1. `copy .env.example .env`
2. En `.env`, cambiar `DB_DRIVER=sqlite` por `DB_DRIVER=mysql` y ajustar credenciales.
3. Importar `database/esquema_mysql.sql` desde phpMyAdmin.

---

## 9. Seguridad

| Riesgo (OWASP) | Control | Dónde |
|---|---|---|
| A01 Control de acceso roto | Middleware de rol junto a cada ruta | `public/index.php`, `Middleware/Seguridad.php` |
| A02 Fallos criptográficos | bcrypt con sal automática | `AutenticacionServicio` |
| A03 Inyección SQL | PDO con consultas preparadas | `src/Repositorios/` |
| A03 XSS almacenado | `strip_tags()` al normalizar | `Validador::texto()` |
| A04 Diseño inseguro | Totales en servidor; máquina de estados | `CalculadoraDeTotales`, modelos |
| A05 Configuración insegura | Solo `public/` expuesta | Estructura |
| A07 Fallos de identificación | Bloqueo tras 5 intentos; mensajes uniformes | `AutenticacionServicio` |

---

## 10. Documentación

| Documento | Contenido |
|---|---|
| [`docs/01-diseno-del-sistema.md`](docs/01-diseno-del-sistema.md) | Requisitos, casos de uso, arquitectura, modelo ER, diagramas de secuencia y máquinas de estado. |
| [`docs/02-documentacion-servicios.md`](docs/02-documentacion-servicios.md) | **Documentación de cada uno de los 37 servicios.** |
| [`docs/03-plan-de-pruebas.md`](docs/03-plan-de-pruebas.md) | 36 casos de prueba con resultados. |
| [`docs/04-control-de-versiones.md`](docs/04-control-de-versiones.md) | Flujo de trabajo con Git e historial. |

---

## 11. Tecnologías

| Componente | Tecnología |
|---|---|
| Lenguaje | PHP 8.1 |
| Estilo | API REST sobre HTTP, JSON |
| Base de datos | SQLite (predeterminada) / MySQL |
| Acceso a datos | PDO con consultas preparadas |
| Contraseñas | bcrypt (`password_hash`) |
| Sesión | Token JWT (HS256), implementado sin librerías |
| Dependencias externas | **Ninguna** |
| Control de versiones | Git |
