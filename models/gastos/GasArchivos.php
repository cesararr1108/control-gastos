<?php
/**
 * Manejo de PDF: validación, almacenamiento en uploads/gastos/ y lectura autorizada.
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

    /** Borra archivos físicos (se usa para deshacer cuando la transacción falla). */
    public static function borrar($rutas)
    {
        foreach ($rutas as $r) {
            $f = GasConfig::dirUploads() . '/' . basename($r);
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /** Envía el archivo (PDF o imagen) al navegador (inline). */
    public static function enviar($ruta, $nombre)
    {
        $f = GasConfig::dirUploads() . '/' . basename($ruta);
        if (!is_file($f)) {
            header('HTTP/1.1 404 Not Found');
            echo 'Archivo no encontrado.';
            return;
        }
        $tipos = array('pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp');
        $ext   = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        header('Content-Type: ' . (isset($tipos[$ext]) ? $tipos[$ext] : 'application/octet-stream'));
        header('Content-Length: ' . filesize($f));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $nombre) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($f);
    }

    private static function limpiarNombre($n)
    {
        $n = basename(str_replace('\\', '/', $n));
        return preg_replace('/[^\p{L}\p{N}\.\-_ ()]/u', '_', $n);
    }
}
