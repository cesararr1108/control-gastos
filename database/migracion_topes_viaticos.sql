/* ============================================================================
   MIGRACION: topes de viaticos por nivel (auxilio de alimentacion y hospedaje).
   Usa la tabla existente T_ROLES_GASTOS_INFO (ROL, NIVEL). Re-ejecutable.
   ============================================================================ */
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
