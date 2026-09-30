# Control de gastos

Módulo para gestionar **cotizaciones, anticipos y facturas** con flujos de autorización configurables
por organización (1000, 2000…), con varias variantes por tipo. Solicitante → Gerencia administrativa → Contabilidad → Tesorería.

- PHP 5.5 orientado a objetos, extensión `mssql_`, SQL Server 2019.
- Interfaz con Tailwind CSS + JavaScript (jQuery/SweetAlert2, reutiliza `lib/js/servicios.js`).
- Detalle de los flujos, con diagramas: **[docs/FLUJOS.md](docs/FLUJOS.md)**.

## Estructura

```
database/gastos.sql            Tablas T_GAS_* y flujos predeterminados
models/gastos/                 Lógica: GasDb, GasMotor (avance del flujo), GasFlujoModel, GasSolicitudModel…
controllers/gastos/api.php     Único punto de entrada JSON (whitelist de operaciones)
controllers/gastos/Gas*Controller.php
views/gastos/                  index (bandeja), solicitud (detalle/acción), flujos (armador)
lib/js/gastos/                 gastos.js (núcleo), pasos.js (formularios), bandeja/solicitud/flujos.js
uploads/gastos/                PDF subidos (bloqueado por .htaccess; se descargan por la API)
tests/logica.php               Pruebas sin base de datos
```

## Instalación

1. **Copia** las carpetas al proyecto respetando las rutas (`models/`, `controllers/`, `views/`, `lib/js/`, `uploads/`).
   `lib/js/servicios.js` es el que ya tienes: las vistas lo cargan desde `../../lib/js/servicios.js`.
2. **Base de datos:** ejecuta `database/gastos.sql` en SQL Server (es re-ejecutable).
3. **Conexión:** `GasDb` usa la función que ya tienes: `conectar()` de `models/funciones.php` (se configura en
   `GasConfig::$FUNCIONES_CONEXION` y `GasConfig::ARCHIVO_CONEXION`). Si devuelve el enlace de `mssql_connect`, se usa;
   si no devuelve nada, se usa la última conexión abierta.
4. **Configuración** en `models/gastos/GasConfig.php`:
   - `$ROLES_ADMIN`: IDs de `T_ROLES` que pueden armar flujos y ver todas las solicitudes.
   - `DB_LATIN1`: `true` si la conexión trabaja en Latin1/cp1252 (lo normal con `Modern_Spanish_CI_AS`).
5. **Permisos:** el usuario del servidor web debe poder escribir en `uploads/gastos/`.
6. **Login:** en `views/gastos/_inicio.php` ajusta `$LOGIN_URL`. El módulo lee las variables de sesión existentes
   (`ses_Id`, `ses_RolesId`, `ses_NumOrg`, `ses_OfcVentas`, `ses_DepId`, `ses_Usuario`).
7. **Flujos:** entra a `views/gastos/flujos.php` y asigna el rol responsable de cada paso (por flujo y organización).
   Hasta que cada paso de rol tenga responsable no se podrán crear solicitudes con ese flujo.

Las vistas cargan Tailwind, jQuery y SweetAlert2 desde CDN. Si tu red no tiene internet, descárgalos y
cambia las etiquetas `<script>` en `views/gastos/_cabecera.php`.

## Seguridad

- `mssql_` no tiene consultas preparadas: **todo** valor pasa por `GasDb::str/int/num/fecha` antes de entrar a un SQL.
- Cada acción se valida en el servidor (el JavaScript solo ayuda al usuario): responsable del paso, formularios, PDF.
- Los PDF se validan (extensión, firma `%PDF`, MIME, tamaño) y se guardan con nombre aleatorio fuera de acceso directo.
  Se descargan por `api.php?recurso=archivos&accion=ver&id=…`, que comprueba que el usuario puede ver la solicitud.
- Las peticiones POST exigen `X-Requested-With: XMLHttpRequest` (defensa CSRF básica; jQuery lo envía solo).
- Los errores inesperados no llegan al navegador: se registran con `error_log` y el usuario ve un mensaje genérico.

## Pruebas

```
php tests/logica.php
```
Cubre escapes SQL, conversión de importes y fechas, condiciones de los pasos y que cada acción tenga manejador.
No requiere SQL Server; los flujos completos deben probarse contra la base real.
