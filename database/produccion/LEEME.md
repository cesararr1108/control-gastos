# Paso a producción — Control de gastos

Ejecutar en este orden (SSMS, conectado a la base indicada):

| # | Archivo | Base | Qué hace |
|---|---|---|---|
| 1 | `01_esquema_produccion.sql` | **Producción** | Verifica las tablas de la empresa que necesita el módulo y crea las 10 tablas `T_GAS_*` y los topes de viáticos 2026. No crea flujos. Re-ejecutable. |
| 2 | `02_exportar_flujos_CALIDAD.sql` | **Calidad** | Solo lee. Genera el script con los flujos activos y sus pasos (`T_GAS_FLUJOS` / `T_GAS_FLUJO_PASOS`). |
| 3 | `03_flujos_produccion.sql` | **Producción** | Es el resultado del paso 2 (lo pegas en un archivo nuevo). Inserta los flujos y pasos. Re-ejecutable: no duplica flujos que ya existen. |

## Detalles importantes

- **Los IDs no se copian.** `ROL_ID` y `USUARIO_ID` pueden ser distintos en producción; el script generado busca el rol por `T_ROLES.TITULO` y el usuario por `T_USUARIOS.EMAIL`. Al final del paso 3 hay una consulta que lista los pasos que quedaron sin responsable (si el rol/usuario no existe con ese título/email); asígnalos desde la pantalla **Flujos**.
- Solo se exportan flujos **activos**.
- No se copian solicitudes ni archivos (son datos de prueba de calidad).
- Antes de ejecutar en producción, haz respaldo de la base.

## Fuera de la base de datos

1. Copiar `models/gastos`, `controllers/gastos`, `views/gastos`, `lib/js/gastos` y `uploads/gastos` (con su `.htaccess`).
2. Dar permiso de escritura al usuario del servidor web sobre `uploads/gastos/`.
3. Revisar `models/gastos/GasConfig.php` (roles administradores, conexión, temas) y `$LOGIN_URL` en `views/gastos/_inicio.php`.
4. Confirmar que producción tenga `T_CENTROS_COSTOS` y `T_ROLES_GASTOS_INFO` con datos (alimentan el select de centros de costo y los topes de viáticos).
5. Las vistas cargan jQuery, SweetAlert2, select2 y Tailwind desde CDN: el servidor/equipos necesitan salida a internet o hay que alojarlos localmente.
