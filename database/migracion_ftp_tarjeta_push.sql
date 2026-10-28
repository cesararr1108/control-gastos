/* =====================================================================================
   Migración: soportes en el FTP, tarjeta de crédito, soporte por fila en la legalización
   de viáticos y notificaciones push. Re-ejecutable; no toca los flujos.
   ===================================================================================== */
SET NOCOUNT ON;

/* 1. Soporte (PDF o foto) de cada fila del F-FR-024. Todas las líneas de una fila apuntan al
      mismo archivo (T_GAS_ARCHIVOS, CATEGORIA = SOPORTE_GASTO). */
IF COL_LENGTH('dbo.T_GAS_LEGALIZACION_DETALLE', 'ARCHIVO_ID') IS NULL
  ALTER TABLE dbo.T_GAS_LEGALIZACION_DETALLE ADD ARCHIVO_ID int NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_T_GAS_LEGAL_ARCH')
  ALTER TABLE dbo.T_GAS_LEGALIZACION_DETALLE ADD CONSTRAINT FK_T_GAS_LEGAL_ARCH FOREIGN KEY (ARCHIVO_ID) REFERENCES dbo.T_GAS_ARCHIVOS (ID);
GO

/* 2. Los archivos del FTP se guardan con su carpeta (p. ej. web/gastos/gas_xxx.pdf): más espacio en RUTA.
      Los archivos anteriores (sin carpeta) siguen en uploads/gastos/ y se abren igual que antes. */
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.T_GAS_ARCHIVOS') AND name = 'RUTA' AND max_length < 255)
  ALTER TABLE dbo.T_GAS_ARCHIVOS ALTER COLUMN RUTA varchar(255) NOT NULL;
GO

/* 3. Tarjeta de crédito como origen del pago: FONDO = 'TARJETA' y FONDO_NUMERO = '**** 1234'
      (solo los últimos 4 dígitos). Caben en las columnas actuales: no hay cambio. */
PRINT 'Migración lista.';
