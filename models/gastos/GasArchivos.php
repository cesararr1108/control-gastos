<?php
/**
 * Soportes de las solicitudes. Con GasConfig::FTP_ACTIVO el navegador los sube al FTP y aquí solo
 * se valida y registra la ruta (desdeFtp); sin FTP se validan y guardan en uploads/gastos/.
 * Los archivos se guardan con nombre aleatorio; el nombre original solo vive en la BD.
 */
class GasArchivos
{
    /**
     * Valida y mueve un archivo subido ($_FILES[x]). Devuelve array(ruta, nombre, tamano).
     * Lanza Exception si no es un PDF válido.
     */
    public static function guardarPdf($file, $etiqueta)
    {
        if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            throw new GasError("Falta el PDF: $etiqueta.");
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new GasError("No se pudo subir el PDF ($etiqueta), código {$file['error']}.");
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new GasError("Archivo inválido: $etiqueta.");
        }
        if ($file['size'] > GasConfig::MAX_PDF_MB * 1024 * 1024) {
            throw new GasError("$etiqueta supera el máximo de " . GasConfig::MAX_PDF_MB . ' MB.');
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            throw new GasError("$etiqueta debe ser un archivo PDF.");
        }
        // Verificar el contenido real, no solo la extensión.
        $fh   = fopen($file['tmp_name'], 'rb');
        $head = $fh ? fread($fh, 5) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($head !== '%PDF-') {
            throw new GasError("$etiqueta no es un PDF válido.");
        }
        if (function_exists('finfo_open')) {
            $fi   = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($fi, $file['tmp_name']);
            finfo_close($fi);
            if ($mime !== 'application/pdf') {
                throw new GasError("$etiqueta no es un PDF válido.");
            }
        }

        $dir = GasConfig::dirUploads();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new GasError('No se pudo crear la carpeta de archivos.');
        }
        $ruta = bin2hex(openssl_random_pseudo_bytes(16)) . '.pdf';
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $ruta)) {
            throw new GasError("No se pudo guardar $etiqueta.");
        }
        return array(
            'ruta'   => $ruta,
            'nombre' => self::limpiarNombre($file['name']),
            'tamano' => (int) $file['size'],
        );
    }

    /**
     * Soporte que puede ser PDF o imagen (PNG / JPG / WEBP), p. ej. un pantallazo pegado con Ctrl+V.
     * Se verifica el contenido real (firma del archivo), no solo la extensión.
     */
    public static function guardarSoporte($file, $etiqueta)
    {
        if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            throw new GasError("Falta el archivo: $etiqueta.");
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new GasError("No se pudo subir el archivo ($etiqueta), código {$file['error']}.");
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new GasError("Archivo inválido: $etiqueta.");
        }
        if ($file['size'] > GasConfig::MAX_PDF_MB * 1024 * 1024) {
            throw new GasError("$etiqueta supera el máximo de " . GasConfig::MAX_PDF_MB . ' MB.');
        }
        $fh   = fopen($file['tmp_name'], 'rb');
        $head = $fh ? fread($fh, 12) : '';
        if ($fh) {
            fclose($fh);
        }
        if (substr($head, 0, 5) === '%PDF-') {
            $ext = 'pdf';
        } elseif (substr($head, 0, 8) === "\x89PNG\r\n\x1a\n") {
            $ext = 'png';
        } elseif (substr($head, 0, 3) === "\xFF\xD8\xFF") {
            $ext = 'jpg';
        } elseif (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            $ext = 'webp';
        } else {
            throw new GasError("$etiqueta debe ser un PDF o una imagen (PNG, JPG o WEBP).");
        }
        $dir = GasConfig::dirUploads();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new GasError('No se pudo crear la carpeta de archivos.');
        }
        $ruta = bin2hex(openssl_random_pseudo_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $ruta)) {
            throw new GasError("No se pudo guardar $etiqueta.");
        }
        $nombre = self::limpiarNombre($file['name']);
        if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== $ext && $ext !== 'jpg') {
            $nombre .= '.' . $ext; // p. ej. «image» pegado del portapapeles
        }
        return array('ruta' => $ruta, 'nombre' => $nombre, 'tamano' => (int) $file['size']);
    }

    /**
     * Archivo ya subido al FTP por el navegador (subirArchivos de servicios.js).
     * $meta = array(ruta, nombre, tamano) tal como lo envía el formulario en ftp[campo].
     * No se confía en el cliente: la ruta debe estar dentro de GasConfig::FTP_RUTA, con un nombre
     * de archivo simple, y la extensión debe estar permitida para el grupo ($EXT_ARCHIVOS).
     * Devuelve array(ruta, nombre, tamano) igual que guardarPdf().
     */
    public static function desdeFtp($meta, $etiqueta, $grupo)
    {
        $permitidas = GasConfig::$EXT_ARCHIVOS[$grupo];
        $ruta   = isset($meta['ruta']) ? ltrim(trim((string) $meta['ruta']), '/') : '';
        $nombre = isset($meta['nombre']) ? self::limpiarNombre(trim((string) $meta['nombre'])) : '';
        $tamano = isset($meta['tamano']) ? (int) $meta['tamano'] : 0;
        $prefijo = trim(GasConfig::FTP_RUTA, '/') . '/';
        $archivo = substr($ruta, strlen($prefijo));
        if ($ruta === '' || $nombre === '') {
            throw new GasError("Falta el archivo: $etiqueta.");
        }
        if (strpos($ruta, $prefijo) !== 0 || strpos($ruta, '..') !== false || !preg_match('/^[A-Za-z0-9._-]+$/', (string) $archivo)) {
            throw new GasError("Ubicación de archivo no válida: $etiqueta.");
        }
        $ext    = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $extFtp = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
        if (!in_array($ext, $permitidas, true) || ($extFtp !== '' && !in_array($extFtp, $permitidas, true))) {
            throw new GasError("$etiqueta debe ser " . self::textoTipos($permitidas) . '.');
        }
        if ($tamano > GasConfig::MAX_PDF_MB * 1024 * 1024) {
            throw new GasError("$etiqueta supera el máximo de " . GasConfig::MAX_PDF_MB . ' MB.');
        }
        return array('ruta' => $ruta, 'nombre' => $nombre, 'tamano' => max(0, $tamano));
    }

    /**
     * Archivo de un campo del formulario, venga del FTP (ftp[campo]) o subido al servidor ($_FILES).
     * $grupo: 'pdf' | 'imagen' (PDF o imagen) | 'documento' (PDF, imagen, Excel, Word…).
     * Devuelve null si no vino y no es obligatorio.
     */
    public static function recibir($in, $files, $campo, $etiqueta, $grupo, $obligatorio)
    {
        $ftp = isset($in['ftp'][$campo]) && is_array($in['ftp'][$campo]) ? $in['ftp'][$campo] : null;
        $Etq = ucfirst($etiqueta); // «Adjunta el soporte…» / «El soporte… debe ser…»
        if ($ftp && !empty($ftp['ruta'])) {
            return self::desdeFtp($ftp, $Etq, $grupo);
        }
        $f   = isset($files[$campo]) ? $files[$campo] : null;
        $hay = $f && isset($f['error']) && $f['error'] !== UPLOAD_ERR_NO_FILE;
        if (!$hay) {
            if ($obligatorio) {
                throw new GasError("Adjunta $etiqueta.");
            }
            return null;
        }
        if ($grupo === 'pdf') {
            return self::guardarPdf($f, $Etq);
        }
        if ($grupo === 'imagen') {
            return self::guardarSoporte($f, $Etq);
        }
        return self::guardarDocumento($f, $Etq);
    }

    /** Soporte local que puede ser PDF, imagen, Excel, Word, CSV o texto (sin FTP). */
    public static function guardarDocumento($file, $etiqueta)
    {
        $ext = strtolower(pathinfo(isset($file['name']) ? $file['name'] : '', PATHINFO_EXTENSION));
        if (in_array($ext, GasConfig::$EXT_ARCHIVOS['imagen'], true)) {
            return self::guardarSoporte($file, $etiqueta);
        }
        if (!in_array($ext, GasConfig::$EXT_ARCHIVOS['documento'], true)) {
            throw new GasError("$etiqueta debe ser " . self::textoTipos(GasConfig::$EXT_ARCHIVOS['documento']) . '.');
        }
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new GasError("No se pudo subir el archivo ($etiqueta).");
        }
        if ($file['size'] > GasConfig::MAX_PDF_MB * 1024 * 1024) {
            throw new GasError("$etiqueta supera el máximo de " . GasConfig::MAX_PDF_MB . ' MB.');
        }
        $dir = GasConfig::dirUploads();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new GasError('No se pudo crear la carpeta de archivos.');
        }
        $ruta = bin2hex(openssl_random_pseudo_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $ruta)) {
            throw new GasError("No se pudo guardar $etiqueta.");
        }
        return array('ruta' => $ruta, 'nombre' => self::limpiarNombre($file['name']), 'tamano' => (int) $file['size']);
    }

    /** ¿El archivo está en el FTP? (los locales se guardan sin carpeta). */
    public static function esFtp($ruta)
    {
        return strpos((string) $ruta, '/') !== false;
    }

    /** «PDF», «PDF o imagen (PNG, JPG…)», etc. */
    private static function textoTipos($ext)
    {
        return 'uno de estos tipos: ' . strtoupper(implode(', ', $ext));
    }

    /** Borra archivos físicos (se usa para deshacer cuando la transacción falla). Los del FTP no se tocan. */
    public static function borrar($rutas)
    {
        foreach ($rutas as $r) {
            if (self::esFtp($r)) {
                continue;
            }
            $f = GasConfig::dirUploads() . '/' . basename($r);
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /**
     * Envía el archivo al navegador (inline). Los del FTP se abren desde su URL pública
     * (la API ya comprobó que el usuario puede ver la solicitud).
     */
    public static function enviar($ruta, $nombre)
    {
        if (self::esFtp($ruta)) {
            header('Location: ' . rtrim(GasConfig::FTP_URL, '/') . '/' . rawurlencode(basename($ruta)));
            return;
        }
        $f = GasConfig::dirUploads() . '/' . basename($ruta);
        if (!is_file($f)) {
            header('HTTP/1.1 404 Not Found');
            echo 'Archivo no encontrado.';
            return;
        }
        $tipos = array('pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp');
        $ext   = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        // PDF e imágenes se ven en el navegador; Excel, Word, etc. se descargan.
        header('Content-Type: ' . (isset($tipos[$ext]) ? $tipos[$ext] : 'application/octet-stream'));
        header('Content-Length: ' . filesize($f));
        header('Content-Disposition: ' . (isset($tipos[$ext]) ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $nombre) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($f);
    }

    private static function limpiarNombre($n)
    {
        $n = basename(str_replace('\\', '/', $n));
        return preg_replace('/[^\p{L}\p{N}\.\-_ ()]/u', '_', $n);
    }
}
