/* =====================================================================================
   Migración: registro de las notificaciones push enviadas (pantalla «Notificaciones»).
   Re-ejecutable.
   ===================================================================================== */
IF OBJECT_ID('dbo.T_GAS_NOTIFICACIONES') IS NULL
CREATE TABLE dbo.T_GAS_NOTIFICACIONES (
  ID            int IDENTITY(1,1) NOT NULL,
  SOLICITUD_ID  int           NOT NULL,
  PASO_NOMBRE   varchar(120)  NULL,        -- paso al que llegó la solicitud (o Finalizada / Rechazada)
  ORGANIZACION  varchar(4)    NULL,        -- organización enviada a la API
  DESTINATARIOS varchar(2000) NULL,        -- correos a los que se envió
  HTTP          int           NOT NULL,    -- respuesta de la API (0 = no se llamó)
  TOTAL         int           NULL,        -- dispositivos encontrados para esos correos
  EXITOSOS      int           NULL,
  RESULTADO     varchar(500)  NULL,
  FECHA         datetime      NOT NULL DEFAULT GETDATE(),
  CONSTRAINT PK_T_GAS_NOTIFICACIONES PRIMARY KEY CLUSTERED (ID)
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_T_GAS_NOTIFICACIONES_SOL')
  CREATE NONCLUSTERED INDEX IX_T_GAS_NOTIFICACIONES_SOL ON dbo.T_GAS_NOTIFICACIONES (SOLICITUD_ID);
GO
PRINT 'Migración lista.';
