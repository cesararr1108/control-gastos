/* ============================================================================
   CONTROL DE GASTOS - PASO PRODUCCION 1 DE 3: ESQUEMA
   Crea las tablas T_GAS_* (y los topes de viaticos 2026). NO crea flujos: esos vienen del paso 3,
   copiados de calidad. Es re-ejecutable: cada objeto se crea solo si no existe.
   Base de datos: ejecutar conectado a la base de PRODUCCION (la que tiene T_USUARIOS, T_ROLES...).
   ============================================================================ */

/* Verifica que existan las tablas de la empresa de las que depende el modulo.
   Si falta alguna, avisa y NO ejecuta el resto (SET NOEXEC ON). */
DECLARE @faltan nvarchar(max) = N'';
SELECT @faltan = @faltan + t.n + N' '
  FROM (VALUES (N'T_USUARIOS'), (N'T_ROLES'), (N'T_TERCEROS'), (N'T_CAL_PROCESOS'), (N'T_CENTROS_COSTOS'), (N'T_ROLES_GASTOS_INFO')) AS t(n)
 WHERE OBJECT_ID(N'dbo.' + t.n) IS NULL;
IF @faltan <> N''
BEGIN
  PRINT N'ERROR: faltan tablas en esta base de datos: ' + @faltan;
  PRINT N'Conectate a la base de produccion correcta. No se ejecuto nada.';
  SET NOEXEC ON;
END
GO

/* ----------------------------------------------------------------------------
   1. CONFIGURACION DE FLUJOS (lo que arma el administrador)
   ---------------------------------------------------------------------------- */

/* Un flujo = tipo + organizacion (1000, 2000...; NULL = todas). Vale para todas las oficinas
   de la organizacion. Puede haber varias variantes activas del mismo tipo (p. ej. "Cotizacion"
   y "Cotizacion - preliminar"): al crear la solicitud el usuario elige. Un flujo de una
   organizacion con el MISMO NOMBRE que uno general lo reemplaza. OFICINA_VENTAS ya no se usa. */
IF OBJECT_ID('dbo.T_GAS_FLUJOS') IS NULL
CREATE TABLE dbo.T_GAS_FLUJOS (
  ID                 int IDENTITY(1,1) NOT NULL,
  TIPO               varchar(20)  NOT NULL,            -- COTIZACION | ANTICIPO | FACTURA
  NOMBRE             varchar(120) NOT NULL,
  ORGANIZACION_VENTA char(4)      NULL,
  OFICINA_VENTAS     char(4)      NULL,
  ACTIVO             bit          NOT NULL DEFAULT 1,
  FECHA_CREACION     datetime     NOT NULL DEFAULT getdate(),
  FECHA_MODIFICACION datetime     NULL,
  USUARIO_ID         int          NULL,                -- T_USUARIOS.ID de quien lo guardo
  CONSTRAINT PK_T_GAS_FLUJOS PRIMARY KEY CLUSTERED (ID),
  CONSTRAINT CK_T_GAS_FLUJOS_TIPO CHECK (TIPO IN ('COTIZACION','ANTICIPO','FACTURA'))
);
GO

/* Pasos ordenados de un flujo. ACCION define QUE hace el paso (formulario y efecto);
   RESPONSABLE_* define QUIEN lo ejecuta; CONDICION define CUANDO aplica. */
IF OBJECT_ID('dbo.T_GAS_FLUJO_PASOS') IS NULL
CREATE TABLE dbo.T_GAS_FLUJO_PASOS (
  ID               int IDENTITY(1,1) NOT NULL,
  FLUJO_ID         int          NOT NULL,
  ORDEN            int          NOT NULL,
  ACCION           varchar(30)  NOT NULL,   -- ver GasCatalogo::acciones()
  NOMBRE           varchar(120) NOT NULL,   -- texto que ve el usuario
  RESPONSABLE_TIPO varchar(12)  NOT NULL DEFAULT 'ROL',  -- SOLICITANTE | ROL | USUARIO
  ROL_ID           int          NULL,       -- T_ROLES.ID (si RESPONSABLE_TIPO = ROL)
  USUARIO_ID       int          NULL,       -- T_USUARIOS.ID (si RESPONSABLE_TIPO = USUARIO)
  CONDICION        varchar(30)  NOT NULL DEFAULT 'SIEMPRE',
  CONSTRAINT PK_T_GAS_FLUJO_PASOS PRIMARY KEY CLUSTERED (ID),
  CONSTRAINT FK_T_GAS_FLUJO_PASOS_FLUJO FOREIGN KEY (FLUJO_ID) REFERENCES dbo.T_GAS_FLUJOS (ID),
  CONSTRAINT UQ_T_GAS_FLUJO_PASOS_ORDEN UNIQUE (FLUJO_ID, ORDEN)
);
GO

/* ----------------------------------------------------------------------------
   2. SOLICITUDES
   ---------------------------------------------------------------------------- */

IF OBJECT_ID('dbo.T_GAS_SOLICITUDES') IS NULL
CREATE TABLE dbo.T_GAS_SOLICITUDES (
  ID                   int IDENTITY(1,1) NOT NULL,
  CODIGO               varchar(20)   NULL,                -- COT-000001 / ANT-000001 / FAC-000001
  TIPO                 varchar(20)   NOT NULL,
  FLUJO_ID             int           NULL,                -- flujo con el que se creo (referencia)
  USUARIO_ID           int           NOT NULL,            -- solicitante (T_USUARIOS.ID)
  ORGANIZACION_VENTA   char(4)       NULL,
  OFICINA_VENTAS       char(4)       NULL,
  ID_DPTO              int           NULL,
  ESTADO               varchar(12)   NOT NULL DEFAULT 'EN_CURSO',  -- EN_CURSO | FINALIZADA | RECHAZADA | CANCELADA
  PASO_ACTUAL          int           NULL,                -- ORDEN del paso en curso
  MOTIVO_CIERRE        varchar(500)  NULL,                -- comentario de rechazo / cancelacion

  /* Datos generales */
  PROCESO_ID           int           NULL,                -- T_CAL_PROCESOS.ID
  PROCESO              varchar(100)  NULL,                -- copia del nombre (historico)
  DESCRIPCION          varchar(500)  NULL,
  REQUIERE_SOPORTE_PAGO bit          NULL,
  COTIZACION_ELEGIDA   tinyint       NULL,                -- 1..3

  /* Anticipo */
  REQUIERE_ANTICIPO    bit           NULL,
  TIPO_ANTICIPO        varchar(12)   NULL,                -- COTIZACION | FACTURA | VIATICOS
  VALOR_ANTICIPO       numeric(15,2) NULL,
  VALOR_TOTAL          numeric(15,2) NULL,                -- valor del preliminar / factura

  /* Tercero */
  TERCERO_NIT          varchar(16)   NULL,
  TERCERO_NOMBRE       varchar(150)  NULL,                -- razon social
  TERCERO_CELULAR      varchar(20)   NULL,
  TERCERO_EMAIL        varchar(100)  NULL,
  CARGO                varchar(80)   NULL,
  CENTRO_COSTOS        varchar(40)   NULL,

  /* Cabecera del formulario de viaticos */
  VIATICOS_DESTINO      varchar(100) NULL,
  VIATICOS_FECHA_INICIO date         NULL,
  VIATICOS_FECHA_FIN    date         NULL,
  VIATICOS_MOTIVO       varchar(300) NULL,
  VIATICOS_TEL_FIJO     varchar(20)  NULL,
  VIATICOS_RUTA_SALIDA  varchar(150) NULL,
  VIATICOS_TIPO_SALIDA  varchar(10)  NULL,         -- AEREO | TERRESTRE
  VIATICOS_RUTA_REGRESO varchar(150) NULL,
  VIATICOS_TIPO_REGRESO varchar(10)  NULL,
  VIATICOS_ACEPTA_DESCUENTO bit      NULL,         -- autorizacion art. 150 y 151 CST (formato F-FR-023)

  /* Numeros que montan los responsables */
  FECHA_FACTURA        date          NULL,
  PAGO_FONDO           bit           NULL,                -- el pago del preliminar sale de un fondo
  FONDO                varchar(20)   NULL,                -- ROMA | FONDO | NOTA (antes FONDO_ROMA | FONDO_PROVEEDORES)
  FONDO_NUMERO         varchar(30)   NULL,                -- número del fondo o de la nota (cuando FONDO es FONDO o NOTA)
  VALOR_LEGALIZADO     numeric(15,2) NULL,                -- total legalizado (anticipo por viaticos)
  RETEFUENTE_LEGALIZACION numeric(15,2) NULL,             -- retefuente descontada en la legalizacion (F-FR-024)
  SALDO_LEGALIZACION   numeric(15,2) NULL,                -- anticipo - legalizado: >0 a favor de la empresa, <0 a favor del tercero
  NUM_PRELIMINAR       varchar(30)   NULL,
  NUM_CONTABILIZACION  varchar(30)   NULL,
  NUM_COMPENSACION     varchar(30)   NULL,
  NUM_COMPROBANTE_ZP   varchar(30)   NULL,
  FECHA_PAGO           date          NULL,

  FECHA_CREACION       datetime      NOT NULL DEFAULT getdate(),
  FECHA_MODIFICACION   datetime      NULL,
  FECHA_FIN            datetime      NULL,
  CONSTRAINT PK_T_GAS_SOLICITUDES PRIMARY KEY CLUSTERED (ID),
  CONSTRAINT CK_T_GAS_SOLICITUDES_ESTADO CHECK (ESTADO IN ('EN_CURSO','FINALIZADA','RECHAZADA','CANCELADA'))
);
GO
/* Instalaciones anteriores: fecha de la factura del preliminar. */
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'FECHA_FACTURA') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD FECHA_FACTURA date NULL;
GO
/* Instalaciones anteriores: legalizacion del anticipo por viaticos. */
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'VALOR_LEGALIZADO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD VALOR_LEGALIZADO numeric(15,2) NULL;
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'SALDO_LEGALIZACION') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD SALDO_LEGALIZACION numeric(15,2) NULL;
/* Instalaciones anteriores: fondo del que sale el pago del preliminar. */
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'PAGO_FONDO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD PAGO_FONDO bit NULL;
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'FONDO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD FONDO varchar(20) NULL;
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'FONDO_NUMERO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD FONDO_NUMERO varchar(30) NULL;
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'RETEFUENTE_LEGALIZACION') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD RETEFUENTE_LEGALIZACION numeric(15,2) NULL;
/* Formato F-FR-023 (solicitud de viaticos). */
IF COL_LENGTH('dbo.T_GAS_SOLICITUDES', 'VIATICOS_TEL_FIJO') IS NULL
  ALTER TABLE dbo.T_GAS_SOLICITUDES ADD VIATICOS_TEL_FIJO varchar(20) NULL, VIATICOS_RUTA_SALIDA varchar(150) NULL,
    VIATICOS_TIPO_SALIDA varchar(10) NULL, VIATICOS_RUTA_REGRESO varchar(150) NULL, VIATICOS_TIPO_REGRESO varchar(10) NULL,
    VIATICOS_ACEPTA_DESCUENTO bit NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_T_GAS_SOLICITUDES_USUARIO')
  CREATE NONCLUSTERED INDEX IX_T_GAS_SOLICITUDES_USUARIO ON dbo.T_GAS_SOLICITUDES (USUARIO_ID, ESTADO);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_T_GAS_SOLICITUDES_ESTADO')
  CREATE NONCLUSTERED INDEX IX_T_GAS_SOLICITUDES_ESTADO ON dbo.T_GAS_SOLICITUDES (ESTADO, TIPO);
GO

/* Copia (snapshot) de los pasos del flujo al crear la solicitud. Asi editar un flujo
   NO altera las solicitudes que ya estan en curso. Tambien guarda quien hizo cada paso. */
IF OBJECT_ID('dbo.T_GAS_SOLICITUD_PASOS') IS NULL
CREATE TABLE dbo.T_GAS_SOLICITUD_PASOS (
  ID               int IDENTITY(1,1) NOT NULL,
  SOLICITUD_ID     int          NOT NULL,
  ORDEN            int          NOT NULL,
  ACCION           varchar(30)  NOT NULL,
  NOMBRE           varchar(120) NOT NULL,
  RESPONSABLE_TIPO varchar(12)  NOT NULL,
  ROL_ID           int          NULL,
  USUARIO_ID       int          NULL,
  CONDICION        varchar(30)  NOT NULL DEFAULT 'SIEMPRE',
  ESTADO           varchar(12)  NOT NULL DEFAULT 'PENDIENTE',  -- PENDIENTE | ACTUAL | COMPLETADO | OMITIDO | RECHAZADO
  EJECUTADO_POR    int          NULL,      -- T_USUARIOS.ID
  DECISION         varchar(12)  NULL,      -- COMPLETADO | APROBADO | RECHAZADO
  COMENTARIO       varchar(500) NULL,
  FECHA_INICIO     datetime     NULL,
  FECHA_FIN        datetime     NULL,
  CONSTRAINT PK_T_GAS_SOLICITUD_PASOS PRIMARY KEY CLUSTERED (ID),
  CONSTRAINT FK_T_GAS_SOL_PASOS_SOL FOREIGN KEY (SOLICITUD_ID) REFERENCES dbo.T_GAS_SOLICITUDES (ID),
  CONSTRAINT UQ_T_GAS_SOL_PASOS_ORDEN UNIQUE (SOLICITUD_ID, ORDEN)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_T_GAS_SOL_PASOS_PENDIENTES')
  CREATE NONCLUSTERED INDEX IX_T_GAS_SOL_PASOS_PENDIENTES ON dbo.T_GAS_SOLICITUD_PASOS (ESTADO, ROL_ID)
  INCLUDE (SOLICITUD_ID, ORDEN, RESPONSABLE_TIPO, USUARIO_ID);
GO

/* Historial de devoluciones para corrección (Gerencia devuelve al solicitante con comentario). */
IF OBJECT_ID('dbo.T_GAS_SOLICITUD_EVENTOS') IS NULL
CREATE TABLE dbo.T_GAS_SOLICITUD_EVENTOS (
  ID             int IDENTITY(1,1) NOT NULL,
  SOLICITUD_ID   int          NOT NULL,
  TIPO           varchar(12)  NOT NULL,      -- DEVUELTO
  PASO_ORDEN     int          NOT NULL,      -- paso desde el que se devolvio
  PASO_NOMBRE    varchar(120) NULL,
  DESTINO_ORDEN  int          NULL,          -- paso del solicitante al que volvio
  DESTINO_NOMBRE varchar(120) NULL,
  USUARIO_ID     int          NOT NULL,      -- quien devolvio
  COMENTARIO     varchar(500) NULL,
  FECHA          datetime     NOT NULL DEFAULT GETDATE(),
  CONSTRAINT PK_T_GAS_SOLICITUD_EVENTOS PRIMARY KEY CLUSTERED (ID),
  CONSTRAINT FK_T_GAS_EVENTOS_SOL FOREIGN KEY (SOLICITUD_ID) REFERENCES dbo.T_GAS_SOLICITUDES (ID)
);
GO

/* Topes de viaticos por nivel (tabla "Auxilio de alimentacion y hospedaje"). NIVEL viene de
   T_ROLES_GASTOS_INFO (1 gerentes de proceso, 2 coordinadores, 3 auxiliares); NIVEL = 0 aplica a
   todos (tarifas de hotel). Para un ano nuevo basta insertar sus filas: se usa el ano mas reciente
   que no sea posterior al actual. */
IF OBJECT_ID('dbo.T_GAS_TOPES_VIATICOS') IS NULL
CREATE TABLE dbo.T_GAS_TOPES_VIATICOS (
  ID        int IDENTITY(1,1) NOT NULL,
  ANIO      int           NOT NULL,
  NIVEL     int           NOT NULL,     -- 0 = todos los niveles
  CONCEPTO  varchar(20)   NOT NULL,     -- DESAYUNO | ALMUERZO | CENA | HOTEL | HOTEL_DESAYUNO
  VALOR     numeric(15,2) NOT NULL,
  CONSTRAINT PK_T_GAS_TOPES_VIATICOS PRIMARY KEY CLUSTERED (ID),
  CONSTRAINT UQ_T_GAS_TOPES_VIATICOS UNIQUE (ANIO, NIVEL, CONCEPTO)
);
GO
IF NOT EXISTS (SELECT 1 FROM dbo.T_GAS_TOPES_VIATICOS WHERE ANIO = 2026)
INSERT dbo.T_GAS_TOPES_VIATICOS (ANIO, NIVEL, CONCEPTO, VALOR) VALUES
  (2026, 1, 'DESAYUNO', 10000), (2026, 1, 'ALMUERZO', 31000), (2026, 1, 'CENA', 25000),   -- gerentes de proceso: 66.000/dia
  (2026, 2, 'DESAYUNO', 10000), (2026, 2, 'ALMUERZO', 25000), (2026, 2, 'CENA', 20000),   -- coordinadores: 55.000/dia
  (2026, 3, 'DESAYUNO', 10000), (2026, 3, 'ALMUERZO', 21000), (2026, 3, 'CENA', 17000),   -- auxiliares: 48.000/dia
  (2026, 0, 'HOTEL', 110000), (2026, 0, 'HOTEL_DESAYUNO', 120000);                        -- tarifa unica de hotel
GO

/* ----------------------------------------------------------------------------
   3. ARCHIVOS, COTIZACIONES Y VIATICOS
   ---------------------------------------------------------------------------- */

/* Todos los PDF. El archivo fisico va en uploads/gastos/ (fuera de acceso web directo). */
IF OBJECT_ID('dbo.T_GAS_ARCHIVOS') IS NULL
CREATE TABLE dbo.T_GAS_ARCHIVOS (
  ID              int IDENTITY(1,1) NOT NULL,
  SOLICITUD_ID    int          NOT NULL,
  PASO_ORDEN      int          NULL,
  CATEGORIA       varchar(20)  NOT NULL,   -- COTIZACION | FACTURA | LEGALIZACION | SOPORTE_PAGO | PRELIMINAR | COMPROBANTE | CONTRATO | REINTEGRO | SOPORTE_CONTAB
  NOMBRE_ORIGINAL varchar(200) NOT NULL,
  RUTA            varchar(120) NOT NULL,   -- nombre del archivo dentro de uploads/gastos/
  TAMANO          int          NOT NULL,
  USUARIO_ID      int          NOT NULL,
  FECHA           datetime     NOT NULL DEFAULT getdate(),
  CONSTRAINT PK_T_GAS_ARCHIVOS PRIMARY KEY CLUSTERED (ID),
  CONSTRAINT FK_T_GAS_ARCHIVOS_SOL FOREIGN KEY (SOLICITUD_ID) REFERENCES dbo.T_GAS_SOLICITUDES (ID)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_T_GAS_ARCHIVOS_SOL')
  CREATE NONCLUSTERED INDEX IX_T_GAS_ARCHIVOS_SOL ON dbo.T_GAS_ARCHIVOS (SOLICITUD_ID);
GO

/* Las tres cotizaciones de un flujo de cotizacion. */
IF OBJECT_ID('dbo.T_GAS_COTIZACIONES') IS NULL
CREATE TABLE dbo.T_GAS_COTIZACIONES (
  ID           int IDENTITY(1,1) NOT NULL,
  SOLICITUD_ID int           NOT NULL,
  NUMERO       tinyint       NOT NULL,        -- 1, 2 o 3
  PROVEEDOR    varchar(150)  NULL,
  VALOR        numeric(15,2) NULL,
  ARCHIVO_ID   int           NOT NULL,
  CONSTRAINT PK_T_GAS_COTIZACIONES PRIMARY KEY CLUSTERED (ID),
  CONSTRAINT FK_T_GAS_COT_SOL FOREIGN KEY (SOLICITUD_ID) REFERENCES dbo.T_GAS_SOLICITUDES (ID),
  CONSTRAINT FK_T_GAS_COT_ARCH FOREIGN KEY (ARCHIVO_ID) REFERENCES dbo.T_GAS_ARCHIVOS (ID),
  CONSTRAINT UQ_T_GAS_COT UNIQUE (SOLICITUD_ID, NUMERO)
);
GO

/* Lineas del formulario de gastos de viaje (anticipo por viaticos). */
IF OBJECT_ID('dbo.T_GAS_VIATICOS_DETALLE') IS NULL
CREATE TABLE dbo.T_GAS_VIATICOS_DETALLE (
  ID             int IDENTITY(1,1) NOT NULL,
  SOLICITUD_ID   int           NOT NULL,
  CONCEPTO       varchar(30)   NOT NULL,   -- ver GasCatalogo::conceptosViaticos()
  DESCRIPCION    varchar(200)  NULL,
  CANTIDAD       numeric(9,2)  NOT NULL,   -- dias / noches / unidades
  VALOR_UNITARIO numeric(15,2) NOT NULL,
  VALOR_TOTAL    numeric(15,2) NOT NULL,
  CONSTRAINT PK_T_GAS_VIATICOS_DETALLE PRIMARY KEY CLUSTERED (ID),
  CONSTRAINT FK_T_GAS_VIAT_SOL FOREIGN KEY (SOLICITUD_ID) REFERENCES dbo.T_GAS_SOLICITUDES (ID)
);
GO

/* Lineas del formato de legalizacion de viaticos (F-FR-024). Cada gasto va en una columna del
   formato (TIPO_GASTO). Los soportes (facturas y, si hay reintegro, su comprobante) se suben en un
   solo PDF (T_GAS_ARCHIVOS, CATEGORIA = LEGALIZACION). */
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
GO

/* Fin del esquema. Verificacion: deben listarse las 10 tablas T_GAS_*. */
SET NOEXEC OFF;
SELECT name AS TABLA_CREADA FROM sys.tables WHERE name LIKE 'T_GAS[_]%' ORDER BY name;
GO
