/* =====================================================================================
   Migración: cambios del documento «cambios_modulos_de_gastos»
   Solo agrega lo nuevo / convierte lo anterior (se puede ejecutar más de una vez).
   No toca tus flujos salvo cambiar el NOMBRE de los pasos «Montar preliminar…» a «Legalización»
   (si ya los renombraste, no cambia nada).
   ===================================================================================== */
SET NOCOUNT ON;

/* 1. Fondo de la legalización: ROMA (por defecto) | FONDO | NOTA, con su número. */
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'FONDO_NUMERO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD FONDO_NUMERO varchar(30) NULL;
GO
UPDATE dbo.T_GAS_SOLICITUDES SET FONDO = 'ROMA'  WHERE FONDO = 'FONDO_ROMA';
UPDATE dbo.T_GAS_SOLICITUDES SET FONDO = 'FONDO' WHERE FONDO = 'FONDO_PROVEEDORES';
UPDATE dbo.T_GAS_SOLICITUDES SET FONDO = 'ROMA'  WHERE FONDO IS NULL AND NUM_PRELIMINAR IS NOT NULL;
GO

/* 2. «Montar preliminar» ahora se llama «Legalización» (en los flujos y en las solicitudes ya creadas). */
UPDATE dbo.T_GAS_FLUJO_PASOS
   SET NOMBRE = REPLACE(REPLACE(REPLACE(NOMBRE, 'Montar preliminar / legalizacion', 'Legalización'), 'Montar preliminar / legalización', 'Legalización'), 'Montar preliminar', 'Legalización')
 WHERE NOMBRE LIKE 'Montar preliminar%';
UPDATE dbo.T_GAS_SOLICITUD_PASOS
   SET NOMBRE = REPLACE(REPLACE(REPLACE(NOMBRE, 'Montar preliminar / legalizacion', 'Legalización'), 'Montar preliminar / legalización', 'Legalización'), 'Montar preliminar', 'Legalización')
 WHERE NOMBRE LIKE 'Montar preliminar%';
GO

/* 3. Nuevas categorías de archivo (CONTRATO, REINTEGRO, SOPORTE_CONTAB): caben en CATEGORIA varchar(20); no hay cambio. */
PRINT 'Migración lista.';
