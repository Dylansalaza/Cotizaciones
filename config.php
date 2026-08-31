<?php
session_start();
header_remove('X-Powered-By');
date_default_timezone_set('America/Guayaquil');
define('BASE_PATH', __DIR__);
define('UPLOAD_DIR', BASE_PATH . '/data/uploads/');

// Crear directorio de uploads si no existe
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}
?>