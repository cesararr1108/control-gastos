/* ============================================================================
   MIGRACION: fondo del que sale el pago del preliminar con factura
   (Fondo Roma / Fondo proveedores). Solo estructura; no toca los flujos.
   Re-ejecutable.
   ============================================================================ */
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'PAGO_FONDO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD PAGO_FONDO bit NULL;            -- 1 = el pago sale de un fondo
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'FONDO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD FONDO varchar(20) NULL;          -- FONDO_ROMA | FONDO_PROVEEDORES
GO
