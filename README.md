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
views/gastos/                  index (bandeja, tarjetas, historial + Excel), solicitud (detalle/acción), dashboard, flujos (armador)
lib/js/gastos/                 gastos.js (núcleo), pasos.js (formularios), bandeja/solicitud/dashboard/flujos.js, push.js
uploads/gastos/                Archivos locales (solo si GasConfig::FTP_ACTIVO = false, y los anteriores al FTP)
tests/logica.php               Pruebas sin base de datos
```

## Instalación

1. **Copia** las carpetas al proyecto respetando las rutas (`models/`, `controllers/`, `views/`, `lib/js/`, `uploads/`).
   `lib/js/servicios.js` es el que ya tienes: las vistas lo cargan desde `../../lib/js/servicios.js`.
2. **Base de datos:** ejecuta `database/gastos.sql` en SQL Server (es re-ejecutable). En una instalación existente
   ejecuta además `database/migracion_ftp_tarjeta_push.sql` (soporte por fila del F-FR-024 y rutas del FTP).
3. **Conexión:** `GasDb` usa la función que ya tienes: `conectar()` de `models/funciones.php` (se configura en
   `GasConfig::$FUNCIONES_CONEXION` y `GasConfig::ARCHIVO_CONEXION`). Si devuelve el enlace de `mssql_connect`, se usa;
   si no devuelve nada, se usa la última conexión abierta.
4. **Configuración** en `models/gastos/GasConfig.php`:
   - `$ROLES_ADMIN`: IDs de `T_ROLES` con permisos de administrador (flujos, historial, dashboard).
   - `$ROLES_ADMIN_TITULOS`: títulos de rol con los mismos permisos (por defecto `GERENCIA ADMINISTRATIVA`).
   - `$TEMAS`: color de la interfaz por organización (`1000` amarillo suave, `2000` verde azulado).
   - `DB_LATIN1`: `true` si la conexión trabaja en Latin1/cp1252 (lo normal con `Modern_Spanish_CI_AS`).
5. **Soportes en el FTP:** los archivos se suben desde el navegador con `subirArchivos()` de `lib/js/servicios.js`
   (`models/ServiceUploadFile.php`) a `GasConfig::FTP_RUTA` (`/web/gastos`; la carpeta debe existir en el FTP).
   Ajusta `GasConfig::FTP_URL` a la URL pública de esa carpeta (abre un archivo subido para comprobarla).
   Con `FTP_ACTIVO = false` se vuelve a usar `uploads/gastos/` (el usuario del servidor web debe poder escribir ahí).
6. **Notificaciones push (opcional):** con la API `notificaciones-push-roma`, llena `GasConfig::PUSH_URL` y
   `URL_MODULO` y define la variable de entorno `GASTOS_PUSH_API_KEY` (llave con scopes `notificaciones` y `tokens`).
   Cada vez que una solicitud avanza se avisa, por su correo de `T_USUARIOS`, a quien debe hacer el paso siguiente
   (solicitante, usuarios del rol o usuario asignado); al finalizar o rechazar se avisa al solicitante.
   Para que el módulo registre el navegador/celular de cada usuario, llena `GasConfig::$FIREBASE_WEB` (configuración
   web de Firebase + `vapidKey`); si tu intranet ya registra los tokens con el correo del usuario, déjalo vacío.
   Para revisar la configuración y enviarte una notificación de prueba entra a `views/gastos/notificaciones.php`
   (menú «Notificaciones», solo administradores). Ejecuta `database/migracion_notificaciones_log.sql` para que
   esa pantalla muestre cada aviso enviado y lo que respondió la API. En los pasos de aprobación también se avisa
   a Gerencia administrativa (`$ROLES_ADMIN_TITULOS`), que puede aprobar aunque el paso esté asignado a otro rol.
7. **Login:** en `views/gastos/_inicio.php` ajusta `$LOGIN_URL`. El módulo lee las variables de sesión existentes
   (`ses_Id`, `ses_RolesId`, `ses_NumOrg`, `ses_OfcVentas`, `ses_DepId`, `ses_Usuario`).
8. **Flujos:** entra a `views/gastos/flujos.php` y asigna el rol responsable de cada paso (por flujo y organización).
   Hasta que cada paso de rol tenga responsable no se podrán crear solicitudes con ese flujo.

Las vistas cargan Tailwind, jQuery y SweetAlert2 desde CDN; la descarga a Excel carga SheetJS (`xlsx`) desde jsDelivr al pulsar el botón. Si tu red no tiene internet, descárgalos y
cambia las etiquetas `<script>` en `views/gastos/_cabecera.php`.

## Seguridad

- `mssql_` no tiene consultas preparadas: **todo** valor pasa por `GasDb::str/int/num/fecha` antes de entrar a un SQL.
- Cada acción se valida en el servidor (el JavaScript solo ayuda al usuario): responsable del paso, formularios, PDF.
- Los archivos del FTP: el servidor solo registra rutas dentro de `FTP_RUTA`, con nombre simple y extensión permitida
  para cada campo (`GasConfig::$EXT_ARCHIVOS`). Se abren por `api.php?recurso=archivos&accion=ver&id=…`, que comprueba
  que el usuario puede ver la solicitud y redirige a la URL pública (nombre aleatorio). Ten en cuenta que esa URL del FTP es
  pública para quien la conozca, igual que en los demás módulos que usan `ServiceUploadFile.php`.
- Sin FTP, los PDF se validan (extensión, firma `%PDF`, MIME, tamaño) y se guardan con nombre aleatorio fuera de acceso directo.
- De la tarjeta de crédito solo se guardan los últimos 4 dígitos (`**** 1234`).
- La llave de la API de notificaciones vive solo en el servidor (variable de entorno); el navegador registra su token a través de `api.php`.
- Las peticiones POST exigen `X-Requested-With: XMLHttpRequest` (defensa CSRF básica; jQuery lo envía solo).
- Los errores inesperados no llegan al navegador: se registran con `error_log` y el usuario ve un mensaje genérico.

## Pruebas

```
php tests/logica.php
```
Cubre escapes SQL, conversión de importes y fechas, condiciones de los pasos y que cada acción tenga manejador.
No requiere SQL Server; los flujos completos deben probarse contra la base real.
