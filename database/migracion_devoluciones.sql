/* ============================================================================
   MIGRACION: devolver para correccion. Solo estructura; no toca los flujos.
   Re-ejecutable.
   ============================================================================ */
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
