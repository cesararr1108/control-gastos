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

    /** Envía el PDF al navegador (inline). */
    public static function enviar($ruta, $nombre)
    {
        $f = GasConfig::dirUploads() . '/' . basename($ruta);
        if (!is_file($f)) {
            header('HTTP/1.1 404 Not Found');
            echo 'Archivo no encontrado.';
            return;
        }
        header('Content-Type: application/pdf');
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
