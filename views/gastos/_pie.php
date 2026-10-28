  </main>
  <script>
    // Ruta de la API relativa a esta carpeta y usuario actual, antes de cargar los scripts.
    window.GASTOS_API = '../../controllers/gastos/api.php';
    // Soportes al FTP con subirArchivos() de servicios.js (ver GasConfig::FTP_*).
    window.GASTOS_FTP = <?php echo json_encode(array(
        'activo'   => GasConfig::FTP_ACTIVO,
        'ruta'     => GasConfig::FTP_RUTA,
        'servicio' => '../../models/ServiceUploadFile.php',
        'maxMb'    => GasConfig::MAX_PDF_MB,
        'ext'      => GasConfig::$EXT_ARCHIVOS,
    )); ?>;
  </script>
  <script src="../../lib/js/servicios.js"></script>
  <script src="../../lib/js/gastos/gastos.js"></script>
  <?php
  // Cada vista declara sus scripts propios en $GAS_SCRIPTS (archivos dentro de lib/js/gastos/).
  if (!empty($GAS_SCRIPTS)) {
      foreach ($GAS_SCRIPTS as $s) {
          echo '<script src="../../lib/js/gastos/' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n  ";
      }
  }
  ?>
  <script>
    // Insignia con las solicitudes que esperan al usuario.
    Gastos.api('solicitudes', 'contador').then(function (d) {
      if (d.pendientes > 0) {
        const b = document.getElementById('badgePendientes');
        b.textContent = d.pendientes;
        b.classList.remove('hidden');
      }
    }).catch(function () {});
  </script>
  <?php
  // Registro del dispositivo para las notificaciones push (solo si Firebase está configurado).
  if (GasConfig::$FIREBASE_WEB && GasConfig::PUSH_URL !== '') { ?>
  <script>window.GASTOS_FIREBASE = <?php echo json_encode(GasConfig::$FIREBASE_WEB); ?>;</script>
  <script src="../../lib/js/gastos/push.js"></script>
  <?php } ?>
</body>
</html>
