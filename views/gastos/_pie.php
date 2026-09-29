  </main>
  <script>
    // Ruta de la API relativa a esta carpeta y usuario actual, antes de cargar los scripts.
    window.GASTOS_API = '../../controllers/gastos/api.php';
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
</body>
</html>
