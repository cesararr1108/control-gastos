/* =====================================================================================
   Migración: ajustes de noviembre (fecha de factura, tarjeta, reparto por centros de costo,
   soporte de pago, contabilización 19/17, devolución del saldo al empleado, códigos de formatos).
   Re-ejecutable: solo agrega lo que falta.
   ===================================================================================== */
SET NOCOUNT ON;

/* 1. Pago con tarjeta: últimos 4 dígitos, titular y banco (FONDO = 'TARJETA'). */
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'TARJETA_ULTIMOS4') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD TARJETA_ULTIMOS4 char(4) NULL, TARJETA_TITULAR varchar(150) NULL, TARJETA_BANCO varchar(80) NULL;
GO

/* 2. Devolución del saldo a favor del empleado (legalización de viáticos): comprobante y fecha. */
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'NUM_COMPROBANTE_SALDO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD NUM_COMPROBANTE_SALDO varchar(30) NULL, FECHA_PAGO_SALDO date NULL;
GO

/* 3. Reparto del valor de la factura entre centros de costo (legalización con factura). */
IF OBJECT_ID('dbo.T_GAS_CENTROS_SOLICITUD') IS NULL
CREATE TABLE dbo.T_GAS_CENTROS_SOLICITUD (
  ID           int IDENTITY(1,1) NOT NULL,
  SOLICITUD_ID int           NOT NULL,
  CENTRO_COSTO varchar(40)   NOT NULL,      -- T_CENTROS_COSTOS.CENTRO
  VALOR        numeric(15,2) NOT NULL,
  CONSTRAINT PK_T_GAS_CENTROS_SOLICITUD PRIMARY KEY CLUSTERED (ID),
  CONSTRAINT FK_T_GAS_CENTROS_SOL FOREIGN KEY (SOLICITUD_ID) REFERENCES dbo.T_GAS_SOLICITUDES (ID)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_T_GAS_CENTROS_SOL')
  CREATE NONCLUSTERED INDEX IX_T_GAS_CENTROS_SOL ON dbo.T_GAS_CENTROS_SOLICITUD (SOLICITUD_ID);
GO
/* Las legalizaciones ya hechas quedan con un solo centro (el de la solicitud) por el valor de la factura. */
INSERT dbo.T_GAS_CENTROS_SOLICITUD (SOLICITUD_ID, CENTRO_COSTO, VALOR)
SELECT s.ID, LTRIM(RTRIM(s.CENTRO_COSTOS)), s.VALOR_TOTAL
  FROM dbo.T_GAS_SOLICITUDES s
 WHERE s.NUM_PRELIMINAR IS NOT NULL AND s.CENTRO_COSTOS IS NOT NULL AND s.VALOR_TOTAL IS NOT NULL
   AND ISNULL(s.TIPO_ANTICIPO, '') <> 'VIATICOS'
   AND NOT EXISTS (SELECT 1 FROM dbo.T_GAS_CENTROS_SOLICITUD c WHERE c.SOLICITUD_ID = s.ID);
GO

/* 4. Paso final de Tesorería «Devolver saldo al empleado» (acción PAGAR_SALDO, condición SALDO_EMPLEADO):
      solo aplica cuando la legalización de viáticos deja saldo a favor del empleado; si no, se omite.
      Se agrega al final de los flujos de ANTICIPO activos que no lo tengan, con el rol del último paso
      PAGAR del flujo (o el rol de Tesorería). */
DECLARE @rolTes int = (SELECT TOP 1 ID FROM dbo.T_ROLES WHERE TITULO LIKE '%TESOR%' ORDER BY ID);

INSERT dbo.T_GAS_FLUJO_PASOS (FLUJO_ID, ORDEN, ACCION, NOMBRE, RESPONSABLE_TIPO, ROL_ID, CONDICION)
SELECT f.ID,
       (SELECT MAX(ORDEN) FROM dbo.T_GAS_FLUJO_PASOS x WHERE x.FLUJO_ID = f.ID) + 1,
       'PAGAR_SALDO', 'Devolver saldo al empleado', 'ROL',
       COALESCE((SELECT TOP 1 ROL_ID FROM dbo.T_GAS_FLUJO_PASOS y WHERE y.FLUJO_ID = f.ID AND y.ACCION = 'PAGAR' ORDER BY y.ORDEN DESC), @rolTes),
       'SALDO_EMPLEADO'
  FROM dbo.T_GAS_FLUJOS f
 WHERE f.TIPO = 'ANTICIPO' AND f.ACTIVO = 1
   AND EXISTS (SELECT 1 FROM dbo.T_GAS_FLUJO_PASOS z WHERE z.FLUJO_ID = f.ID)
   AND NOT EXISTS (SELECT 1 FROM dbo.T_GAS_FLUJO_PASOS z WHERE z.FLUJO_ID = f.ID AND z.ACCION = 'PAGAR_SALDO');

/* Solicitudes de anticipo en curso (que no sean por factura): también reciben el paso al final,
   así las que hoy están en legalización o en contabilidad pasan por Tesorería si hay saldo a favor del empleado. */
INSERT dbo.T_GAS_SOLICITUD_PASOS (SOLICITUD_ID, ORDEN, ACCION, NOMBRE, RESPONSABLE_TIPO, ROL_ID, CONDICION)
SELECT s.ID,
       (SELECT MAX(ORDEN) FROM dbo.T_GAS_SOLICITUD_PASOS x WHERE x.SOLICITUD_ID = s.ID) + 1,
       'PAGAR_SALDO', 'Devolver saldo al empleado', 'ROL',
       COALESCE((SELECT TOP 1 ROL_ID FROM dbo.T_GAS_SOLICITUD_PASOS y WHERE y.SOLICITUD_ID = s.ID AND y.ACCION = 'PAGAR' ORDER BY y.ORDEN DESC), @rolTes),
       'SALDO_EMPLEADO'
  FROM dbo.T_GAS_SOLICITUDES s
 WHERE s.TIPO = 'ANTICIPO' AND s.ESTADO = 'EN_CURSO' AND ISNULL(s.TIPO_ANTICIPO, 'VIATICOS') = 'VIATICOS'
   AND EXISTS (SELECT 1 FROM dbo.T_GAS_SOLICITUD_PASOS z WHERE z.SOLICITUD_ID = s.ID)
   AND NOT EXISTS (SELECT 1 FROM dbo.T_GAS_SOLICITUD_PASOS z WHERE z.SOLICITUD_ID = s.ID AND z.ACCION = 'PAGAR_SALDO');
GO

/* 5. Nuevas categorías de archivo (FORMATO_FIRMADO, FINIQUITO, DEVOLUCION_SALDO): caben en CATEGORIA varchar(20). */
PRINT 'Migración lista.';
