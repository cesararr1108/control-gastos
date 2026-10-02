/* =====================================================================================
   LIMPIEZA DE DATOS DE PRUEBA - Control de gastos
   Borra: TODAS las solicitudes y sus datos (pasos/rutas, eventos, archivos, cotizaciones,
          detalle de viáticos y legalización) y los FLUJOS INACTIVOS (con sus pasos).
   NO borra: flujos activos, topes de viáticos ni nada de las tablas de la empresa.
   Uso: 1) ejecútalo tal cual: muestra lo que borraría y hace ROLLBACK (no cambia nada).
        2) cambia  @CONFIRMAR = 0  por  @CONFIRMAR = 1  y vuelve a ejecutarlo para borrar de verdad.
   Después borra a mano los PDF de la carpeta uploads/gastos/ (la BD ya no los referencia).
   ===================================================================================== */
DECLARE @CONFIRMAR bit = 0;   -- <<< 1 = borrar de verdad

SET NOCOUNT ON;
BEGIN TRY
  BEGIN TRAN;

  -- Hijas primero (FK hacia T_GAS_SOLICITUDES / T_GAS_ARCHIVOS)
  DELETE FROM dbo.T_GAS_COTIZACIONES;
  DELETE FROM dbo.T_GAS_LEGALIZACION_DETALLE;
  DELETE FROM dbo.T_GAS_VIATICOS_DETALLE;
  DELETE FROM dbo.T_GAS_SOLICITUD_EVENTOS;
  DELETE FROM dbo.T_GAS_ARCHIVOS;
  DELETE FROM dbo.T_GAS_SOLICITUD_PASOS;      -- rutas de cada solicitud
  DELETE FROM dbo.T_GAS_SOLICITUDES;

  -- Flujos inactivos (pasos primero)
  DELETE FROM dbo.T_GAS_FLUJO_PASOS WHERE FLUJO_ID IN (SELECT ID FROM dbo.T_GAS_FLUJOS WHERE ACTIVO = 0);
  DELETE FROM dbo.T_GAS_FLUJOS WHERE ACTIVO = 0;

  -- Que los códigos vuelvan a empezar en COT-000001 / ANT-000001 / FAC-000001
  DBCC CHECKIDENT ('dbo.T_GAS_SOLICITUDES', RESEED, 0) WITH NO_INFOMSGS;

  -- Resumen de lo que queda
  SELECT 'Solicitudes' AS TABLA, COUNT(*) AS QUEDAN FROM dbo.T_GAS_SOLICITUDES
  UNION ALL SELECT 'Rutas de solicitudes', COUNT(*) FROM dbo.T_GAS_SOLICITUD_PASOS
  UNION ALL SELECT 'Archivos', COUNT(*) FROM dbo.T_GAS_ARCHIVOS
  UNION ALL SELECT 'Flujos (activos)', COUNT(*) FROM dbo.T_GAS_FLUJOS
  UNION ALL SELECT 'Pasos de flujos', COUNT(*) FROM dbo.T_GAS_FLUJO_PASOS;

  IF @CONFIRMAR = 1
  BEGIN
    COMMIT TRAN;
    PRINT 'LISTO: datos de prueba eliminados.';
  END
  ELSE
  BEGIN
    ROLLBACK TRAN;
    PRINT 'SIMULACION: no se borro nada. Cambia @CONFIRMAR a 1 para borrar de verdad.';
  END
END TRY
BEGIN CATCH
  IF @@TRANCOUNT > 0 ROLLBACK TRAN;
  PRINT 'ERROR, no se borro nada: ' + ERROR_MESSAGE();
END CATCH
