/* ============================================================================
   CONTROL DE GASTOS - Esquema SQL Server 2019
   Tablas con prefijo T_GAS_. Se apoya en las tablas existentes:
   T_USUARIOS, T_ROLES, T_TERCEROS, T_OFICINAS_VENTAS, T_CAL_PROCESOS.
   Ejecutar una sola vez. Es re-ejecutable: cada objeto se crea solo si no existe.
   ============================================================================ */

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

  /* Numeros que montan los responsables */
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

/* ----------------------------------------------------------------------------
   3. ARCHIVOS, COTIZACIONES Y VIATICOS
   ---------------------------------------------------------------------------- */

/* Todos los PDF. El archivo fisico va en uploads/gastos/ (fuera de acceso web directo). */
IF OBJECT_ID('dbo.T_GAS_ARCHIVOS') IS NULL
CREATE TABLE dbo.T_GAS_ARCHIVOS (
  ID              int IDENTITY(1,1) NOT NULL,
  SOLICITUD_ID    int          NOT NULL,
  PASO_ORDEN      int          NULL,
  CATEGORIA       varchar(20)  NOT NULL,   -- COTIZACION | SOPORTE_PAGO | PRELIMINAR | COMPROBANTE
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

/* ----------------------------------------------------------------------------
   4. FLUJOS PREDETERMINADOS (generales, para todas las organizaciones)
   Los responsables se buscan por el titulo del rol (T_ROLES.TITULO). Si no hay
   coincidencia queda NULL y se asigna desde la pantalla "Flujos". Una solicitud
   no se puede crear mientras un paso de rol no tenga responsable.
   ---------------------------------------------------------------------------- */
DECLARE @rolGA   int = (SELECT TOP 1 ID FROM dbo.T_ROLES WHERE TITULO LIKE '%GERENCIA%ADMIN%' ORDER BY ID);
DECLARE @rolCont int = (SELECT TOP 1 ID FROM dbo.T_ROLES WHERE TITULO LIKE '%CONTAB%' ORDER BY ID);
DECLARE @rolTes  int = (SELECT TOP 1 ID FROM dbo.T_ROLES WHERE TITULO LIKE '%TESOR%' ORDER BY ID);
DECLARE @f int;

/* COTIZACION: tras autorizar la cotizacion, el solicitante decide si necesita anticipo (paso 3).
   - Con anticipo: GA aprueba el anticipo -> preliminar -> GA aprueba preliminar -> contabiliza -> paga.
   - Con preliminar (sin anticipo): preliminar -> GA aprueba preliminar -> paga -> contabiliza.
   Los pasos 7-10 existen en los dos ordenes; solo aplica el de la rama elegida (los demas se omiten). */
IF NOT EXISTS (SELECT 1 FROM dbo.T_GAS_FLUJOS WHERE TIPO='COTIZACION' AND ORGANIZACION_VENTA IS NULL AND NOMBRE='Cotizacion (general)')
BEGIN
  INSERT dbo.T_GAS_FLUJOS (TIPO, NOMBRE) VALUES ('COTIZACION', 'Cotizacion (general)');
  SET @f = SCOPE_IDENTITY();
  INSERT dbo.T_GAS_FLUJO_PASOS (FLUJO_ID, ORDEN, ACCION, NOMBRE, RESPONSABLE_TIPO, ROL_ID, CONDICION) VALUES
    (@f,  1, 'SUBIR_COTIZACIONES',   'Subir cotizaciones',                 'SOLICITANTE', NULL,    'SIEMPRE'),
    (@f,  2, 'AUTORIZAR_COTIZACION', 'Autorizar cotizacion',               'ROL',         @rolGA,  'SIEMPRE'),
    (@f,  3, 'DECISION_ANTICIPO',    'Definir anticipo o preliminar',      'SOLICITANTE', NULL,    'SIEMPRE'),
    (@f,  4, 'APROBAR',              'Aprobar anticipo',                   'ROL',         @rolGA,  'ANTICIPO_SI'),
    (@f,  5, 'MONTAR_PRELIMINAR',    'Montar preliminar',                  'SOLICITANTE', NULL,    'SIEMPRE'),
    (@f,  6, 'APROBAR',              'Aprobar preliminar',                 'ROL',         @rolGA,  'SIEMPRE'),
    (@f,  7, 'PAGAR',                'Pagar y montar comprobante',         'ROL',         @rolTes, 'ANTICIPO_NO'),
    (@f,  8, 'CONTABILIZAR',         'Contabilizar (en SAP)',              'ROL',         @rolCont,'ANTICIPO_NO'),
    (@f,  9, 'CONTABILIZAR',         'Contabilizar',                       'ROL',         @rolCont,'ANTICIPO_SI'),
    (@f, 10, 'PAGAR',                'Pagar y montar comprobante',         'ROL',         @rolTes, 'ANTICIPO_SI');
END

IF NOT EXISTS (SELECT 1 FROM dbo.T_GAS_FLUJOS WHERE TIPO='ANTICIPO' AND ORGANIZACION_VENTA IS NULL AND NOMBRE='Anticipo (general)')
BEGIN
  INSERT dbo.T_GAS_FLUJOS (TIPO, NOMBRE) VALUES ('ANTICIPO', 'Anticipo (general)');
  SET @f = SCOPE_IDENTITY();
  INSERT dbo.T_GAS_FLUJO_PASOS (FLUJO_ID, ORDEN, ACCION, NOMBRE, RESPONSABLE_TIPO, ROL_ID, CONDICION) VALUES
    (@f, 1, 'SOLICITAR_ANTICIPO',   'Solicitar anticipo',                 'SOLICITANTE', NULL,    'SIEMPRE'),
    (@f, 2, 'APROBAR',              'Aprobar anticipo',                   'ROL',         @rolGA,  'SIEMPRE'),
    (@f, 3, 'MONTAR_PRELIMINAR',    'Montar preliminar',                  'SOLICITANTE', NULL,    'ANTICIPO_FACTURA'),
    (@f, 4, 'APROBAR',              'Aprobar preliminar',                 'ROL',         @rolGA,  'ANTICIPO_FACTURA'),
    (@f, 5, 'CONTABILIZAR',         'Contabilizar',                       'ROL',         @rolCont,'SIEMPRE'),
    (@f, 6, 'PAGAR',                'Pagar y montar comprobante',         'ROL',         @rolTes, 'SIEMPRE');
END

IF NOT EXISTS (SELECT 1 FROM dbo.T_GAS_FLUJOS WHERE TIPO='FACTURA' AND ORGANIZACION_VENTA IS NULL AND NOMBRE='Factura (general)')
BEGIN
  INSERT dbo.T_GAS_FLUJOS (TIPO, NOMBRE) VALUES ('FACTURA', 'Factura (general)');
  SET @f = SCOPE_IDENTITY();
  INSERT dbo.T_GAS_FLUJO_PASOS (FLUJO_ID, ORDEN, ACCION, NOMBRE, RESPONSABLE_TIPO, ROL_ID, CONDICION) VALUES
    (@f, 1, 'MONTAR_PRELIMINAR',    'Montar preliminar',                  'SOLICITANTE', NULL,    'SIEMPRE'),
    (@f, 2, 'APROBAR',              'Aprobar preliminar',                 'ROL',         @rolGA,  'SIEMPRE'),
    (@f, 3, 'CONTABILIZAR',         'Contabilizar',                       'ROL',         @rolCont,'SIEMPRE'),
    (@f, 4, 'PAGAR',                'Pagar y montar comprobante',         'ROL',         @rolTes, 'SIEMPRE');
END

/* MIGRACION (instalaciones anteriores). Es idempotente y no toca las solicitudes en curso
   (cada una conserva su propia copia de los pasos).
   1) La variante "Cotizacion - preliminar" se desactiva: ahora la eleccion anticipo/preliminar
      se hace dentro del flujo de cotizacion, despues de autorizar la cotizacion.
   2) Si "Cotizacion (general)" sigue con los 8 pasos originales, se reemplaza por el flujo con
      las dos ramas, conservando los roles que ya tenia asignados. Si alguien lo personalizo,
      no se toca. */
UPDATE dbo.T_GAS_FLUJOS SET ACTIVO = 0, FECHA_MODIFICACION = GETDATE()
 WHERE TIPO = 'COTIZACION' AND NOMBRE = 'Cotizacion - preliminar' AND ACTIVO = 1;

DECLARE @fg int = (SELECT TOP 1 ID FROM dbo.T_GAS_FLUJOS
                    WHERE TIPO = 'COTIZACION' AND ORGANIZACION_VENTA IS NULL AND NOMBRE = 'Cotizacion (general)');
-- Secuencia de acciones del flujo (FOR XML PATH en lugar de STRING_AGG: funciona con cualquier nivel de compatibilidad).
DECLARE @secuencia varchar(500) = STUFF((SELECT ',' + CAST(ACCION AS varchar(30))
                                           FROM dbo.T_GAS_FLUJO_PASOS WHERE FLUJO_ID = @fg ORDER BY ORDEN
                                            FOR XML PATH('')), 1, 1, '');
IF @fg IS NOT NULL
   AND @secuencia = 'SUBIR_COTIZACIONES,AUTORIZAR_COTIZACION,DECISION_ANTICIPO,APROBAR,MONTAR_PRELIMINAR,APROBAR,CONTABILIZAR,PAGAR'
BEGIN
  DECLARE @gaR int = COALESCE((SELECT ROL_ID FROM dbo.T_GAS_FLUJO_PASOS WHERE FLUJO_ID = @fg AND ORDEN = 2), @rolGA);
  DECLARE @coR int = COALESCE((SELECT ROL_ID FROM dbo.T_GAS_FLUJO_PASOS WHERE FLUJO_ID = @fg AND ORDEN = 7), @rolCont);
  DECLARE @teR int = COALESCE((SELECT ROL_ID FROM dbo.T_GAS_FLUJO_PASOS WHERE FLUJO_ID = @fg AND ORDEN = 8), @rolTes);
  DELETE FROM dbo.T_GAS_FLUJO_PASOS WHERE FLUJO_ID = @fg;
  INSERT dbo.T_GAS_FLUJO_PASOS (FLUJO_ID, ORDEN, ACCION, NOMBRE, RESPONSABLE_TIPO, ROL_ID, CONDICION) VALUES
    (@fg,  1, 'SUBIR_COTIZACIONES',   'Subir cotizaciones',                 'SOLICITANTE', NULL,  'SIEMPRE'),
    (@fg,  2, 'AUTORIZAR_COTIZACION', 'Autorizar cotizacion',               'ROL',         @gaR,  'SIEMPRE'),
    (@fg,  3, 'DECISION_ANTICIPO',    'Definir anticipo o preliminar',      'SOLICITANTE', NULL,  'SIEMPRE'),
    (@fg,  4, 'APROBAR',              'Aprobar anticipo',                   'ROL',         @gaR,  'ANTICIPO_SI'),
    (@fg,  5, 'MONTAR_PRELIMINAR',    'Montar preliminar',                  'SOLICITANTE', NULL,  'SIEMPRE'),
    (@fg,  6, 'APROBAR',              'Aprobar preliminar',                 'ROL',         @gaR,  'SIEMPRE'),
    (@fg,  7, 'PAGAR',                'Pagar y montar comprobante',         'ROL',         @teR,  'ANTICIPO_NO'),
    (@fg,  8, 'CONTABILIZAR',         'Contabilizar (en SAP)',              'ROL',         @coR,  'ANTICIPO_NO'),
    (@fg,  9, 'CONTABILIZAR',         'Contabilizar',                       'ROL',         @coR,  'ANTICIPO_SI'),
    (@fg, 10, 'PAGAR',                'Pagar y montar comprobante',         'ROL',         @teR,  'ANTICIPO_SI');
END
GO
