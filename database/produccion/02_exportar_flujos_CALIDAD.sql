/* ============================================================================
   CONTROL DE GASTOS - PASO PRODUCCION 2 DE 3: EXPORTAR LOS FLUJOS DE CALIDAD
   EJECUTAR EN LA BASE DE CALIDAD (donde ya tienes los flujos configurados).
   Solo LEE; no modifica nada. Genera el script que se ejecuta en produccion (paso 3).

   Como usarlo:
     1) Ejecutalo en CALIDAD. Devuelve una columna "SCRIPT_PARA_PRODUCCION" con el script, linea por linea.
     2) Copia TODAS las filas de esa columna (en SSMS: clic en el encabezado de la columna, Ctrl+C;
        mejor aun: Resultados en texto -> Ctrl+T antes de ejecutar) y pegalas en un archivo nuevo
        llamado 03_flujos_produccion.sql (guardalo en UTF-8 para conservar tildes).
     3) Ejecuta ese archivo en PRODUCCION.

   Por que no se copian los IDs: ROL_ID y USUARIO_ID pueden ser distintos en produccion. El script
   generado busca el rol por su TITULO (T_ROLES.TITULO) y el usuario por su EMAIL (T_USUARIOS.EMAIL).
   Solo se exportan los flujos ACTIVOS. Para exportar tambien los inactivos, quita el filtro "ACTIVO = 1".
   ============================================================================ */
SET NOCOUNT ON;
DECLARE @out TABLE (n int IDENTITY(1,1), linea nvarchar(max));
DECLARE @q nvarchar(2) = N'''';

INSERT @out (linea) VALUES
 (N'/* CONTROL DE GASTOS - PASO PRODUCCION 3 DE 3: FLUJOS Y PASOS (generado desde calidad el ' + CONVERT(nvarchar(16), GETDATE(), 120) + N') */'),
 (N'/* Es re-ejecutable: si un flujo (tipo + nombre + organizacion) ya existe en produccion, no se toca. */'),
 (N'SET NOCOUNT ON;'), (N'SET XACT_ABORT ON;'), (N'BEGIN TRAN;'), (N'DECLARE @f int;'), (N'');

DECLARE @fid int, @tipo varchar(20), @nom varchar(120), @org char(4);
DECLARE cf CURSOR LOCAL FAST_FORWARD FOR
  SELECT ID, TIPO, NOMBRE, NULLIF(LTRIM(RTRIM(ORGANIZACION_VENTA)), '') FROM dbo.T_GAS_FLUJOS WHERE ACTIVO = 1 ORDER BY ID;
OPEN cf; FETCH NEXT FROM cf INTO @fid, @tipo, @nom, @org;
WHILE @@FETCH_STATUS = 0
BEGIN
  DECLARE @lnom nvarchar(300), @lorg nvarchar(20);
  SET @lnom = N'N' + @q + REPLACE(@nom, @q, @q + @q) + @q;
  SET @lorg = CASE WHEN @org IS NULL THEN N'NULL' ELSE @q + @org + @q END;
  INSERT @out (linea) VALUES
   (N'IF NOT EXISTS (SELECT 1 FROM dbo.T_GAS_FLUJOS WHERE TIPO = ' + @q + @tipo + @q + N' AND NOMBRE = ' + @lnom
      + N' AND ISNULL(ORGANIZACION_VENTA, ' + @q + @q + N') = ' + @q + ISNULL(@org, N'') + @q + N')'),
   (N'BEGIN'),
   (N'  INSERT dbo.T_GAS_FLUJOS (TIPO, NOMBRE, ORGANIZACION_VENTA, ACTIVO) VALUES (' + @q + @tipo + @q + N', ' + @lnom + N', ' + @lorg + N', 1);'),
   (N'  SET @f = SCOPE_IDENTITY();'),
   (N'  INSERT dbo.T_GAS_FLUJO_PASOS (FLUJO_ID, ORDEN, ACCION, NOMBRE, RESPONSABLE_TIPO, ROL_ID, USUARIO_ID, CONDICION) VALUES');

  INSERT @out (linea)
  SELECT N'    (@f, ' + CAST(p.ORDEN AS nvarchar(10)) + N', ' + @q + p.ACCION + @q
       + N', N' + @q + REPLACE(p.NOMBRE, @q, @q + @q) + @q + N', ' + @q + p.RESPONSABLE_TIPO + @q + N', '
       + CASE WHEN r.ID IS NULL THEN N'NULL'
              ELSE N'(SELECT TOP 1 ID FROM dbo.T_ROLES WHERE LTRIM(RTRIM(TITULO)) = N' + @q + REPLACE(LTRIM(RTRIM(r.TITULO)), @q, @q + @q) + @q + N' ORDER BY ID)' END + N', '
       + CASE WHEN u.ID IS NULL THEN N'NULL'
              ELSE N'(SELECT TOP 1 ID FROM dbo.T_USUARIOS WHERE LTRIM(RTRIM(EMAIL)) = N' + @q + REPLACE(LTRIM(RTRIM(u.EMAIL)), @q, @q + @q) + @q + N' ORDER BY ID)' END + N', '
       + @q + p.CONDICION + @q + N')'
       + CASE WHEN ROW_NUMBER() OVER (ORDER BY p.ORDEN DESC) = 1 THEN N';' ELSE N',' END
    FROM dbo.T_GAS_FLUJO_PASOS p
    LEFT JOIN dbo.T_ROLES r ON r.ID = p.ROL_ID
    LEFT JOIN dbo.T_USUARIOS u ON u.ID = p.USUARIO_ID
   WHERE p.FLUJO_ID = @fid
   ORDER BY p.ORDEN;

  INSERT @out (linea) VALUES (N'END'), (N'ELSE PRINT N' + @q + N'Ya existe, no se toca: ' + REPLACE(@tipo + N' / ' + @nom, @q, @q + @q) + @q + N';'), (N'');
  FETCH NEXT FROM cf INTO @fid, @tipo, @nom, @org;
END
CLOSE cf; DEALLOCATE cf;

INSERT @out (linea) VALUES
 (N'COMMIT TRAN;'), (N''),
 (N'/* Verificacion: pasos de rol/usuario SIN responsable en produccion (si salen filas, el rol o el usuario no existe con ese titulo/email: asignalo en la pantalla Flujos). */'),
 (N'SELECT f.TIPO, f.NOMBRE AS FLUJO, p.ORDEN, p.NOMBRE AS PASO, p.RESPONSABLE_TIPO FROM dbo.T_GAS_FLUJO_PASOS p JOIN dbo.T_GAS_FLUJOS f ON f.ID = p.FLUJO_ID'),
 (N' WHERE f.ACTIVO = 1 AND ((p.RESPONSABLE_TIPO = ' + @q + N'ROL' + @q + N' AND p.ROL_ID IS NULL) OR (p.RESPONSABLE_TIPO = ' + @q + N'USUARIO' + @q + N' AND p.USUARIO_ID IS NULL)) ORDER BY f.ID, p.ORDEN;'),
 (N'SELECT f.TIPO, f.NOMBRE AS FLUJO, f.ORGANIZACION_VENTA, COUNT(p.ID) AS PASOS FROM dbo.T_GAS_FLUJOS f LEFT JOIN dbo.T_GAS_FLUJO_PASOS p ON p.FLUJO_ID = f.ID GROUP BY f.ID, f.TIPO, f.NOMBRE, f.ORGANIZACION_VENTA ORDER BY f.ID;');

SELECT linea AS SCRIPT_PARA_PRODUCCION FROM @out ORDER BY n;

/* Resumen de lo que se va a exportar (para comparar con lo que quede en produccion). */
SELECT f.TIPO, f.NOMBRE AS FLUJO, f.ORGANIZACION_VENTA, COUNT(p.ID) AS PASOS
  FROM dbo.T_GAS_FLUJOS f LEFT JOIN dbo.T_GAS_FLUJO_PASOS p ON p.FLUJO_ID = f.ID
 WHERE f.ACTIVO = 1 GROUP BY f.ID, f.TIPO, f.NOMBRE, f.ORGANIZACION_VENTA ORDER BY f.ID;
