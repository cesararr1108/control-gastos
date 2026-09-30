/* ============================================================================
   MIGRACION: formatos de viaticos F-FR-023 (solicitud) y F-FR-024 (legalizacion)
   Solo cambia ESTRUCTURA: no toca T_GAS_FLUJOS ni T_GAS_FLUJO_PASOS (tus flujos).
   Es re-ejecutable: cada cambio se aplica solo si no existe.
   ============================================================================ */

/* 1. Campos nuevos en T_GAS_SOLICITUDES ----------------------------------- */

-- Fecha de la factura del preliminar (si ya ejecutaste la version anterior, ya existe)
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'FECHA_FACTURA') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD FECHA_FACTURA date NULL;

-- F-FR-023: solicitud de viaticos
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'VIATICOS_TEL_FIJO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD VIATICOS_TEL_FIJO varchar(20) NULL;
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'VIATICOS_RUTA_SALIDA') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD VIATICOS_RUTA_SALIDA varchar(150) NULL;
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'VIATICOS_TIPO_SALIDA') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD VIATICOS_TIPO_SALIDA varchar(10) NULL;        -- AEREO | TERRESTRE
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'VIATICOS_RUTA_REGRESO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD VIATICOS_RUTA_REGRESO varchar(150) NULL;
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'VIATICOS_TIPO_REGRESO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD VIATICOS_TIPO_REGRESO varchar(10) NULL;       -- AEREO | TERRESTRE
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'VIATICOS_ACEPTA_DESCUENTO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD VIATICOS_ACEPTA_DESCUENTO bit NULL;          -- art. 150 y 151 CST

-- F-FR-024: totales de la legalizacion
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'VALOR_LEGALIZADO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD VALOR_LEGALIZADO numeric(15,2) NULL;         -- total cuenta de gastos
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'RETEFUENTE_LEGALIZACION') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD RETEFUENTE_LEGALIZACION numeric(15,2) NULL;  -- retefuente descontada
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'SALDO_LEGALIZACION') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD SALDO_LEGALIZACION numeric(15,2) NULL;       -- + a favor empresa / - a favor empleado
GO

/* 2. Tabla nueva: lineas de la legalizacion (F-FR-024) --------------------- */
IF OBJECT_ID('dbo.T_GAS_LEGALIZACION_DETALLE') IS NULL
CREATE TABLE dbo.T_GAS_LEGALIZACION_DETALLE (
  ID             int IDENTITY(1,1) NOT NULL,
  SOLICITUD_ID   int           NOT NULL,
  FECHA          date          NOT NULL,
  CENTRO_COSTO   varchar(40)   NOT NULL,
  NUM_DOCUMENTO  varchar(30)   NULL,       -- "Doc": n.o de factura o recibo
  DETALLE        varchar(200)  NOT NULL,   -- "Ciudad y detalles"
  TIPO_GASTO     varchar(12)   NOT NULL,   -- TRANSP | BUS_TAXIS | HOTEL | ALIMENT | ATENCION | GASOLINA | SERVICIOS | OTROS
  VALOR          numeric(15,2) NOT NULL,
  RETEFUENTE     numeric(15,2) NOT NULL DEFAULT 0,
  CONSTRAINT PK_T_GAS_LEGALIZACION_DETALLE PRIMARY KEY CLUSTERED (ID),
  CONSTRAINT FK_T_GAS_LEGAL_SOL FOREIGN KEY (SOLICITUD_ID) REFERENCES dbo.T_GAS_SOLICITUDES (ID)
);
GO
