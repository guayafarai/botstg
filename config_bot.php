<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * CONFIGURACIÓN DEL BOT - VERSIÓN SEGURA CORREGIDA
 * ═══════════════════════════════════════════════════════════════
 * 
 * IMPORTANTE: 
 * 1. Crea un archivo .env en el mismo directorio
 * 2. NO subas este archivo a repositorios públicos
 * 3. Cambia todas las credenciales por defecto
 */

// ═══════════════════════════════════════════════════════════════
// CARGAR VARIABLES DE ENTORNO
// ═══════════════════════════════════════════════════════════════

function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        // Remover comillas si existen
        $value = trim($value, '"\'');
        
        if (!array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
    
    return true;
}

// Intentar cargar .env
loadEnv(__DIR__ . '/.env');

// ═══════════════════════════════════════════════════════════════
// CONFIGURACIÓN PRINCIPAL
// ═══════════════════════════════════════════════════════════════

define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '');
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: '');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');

// ═══════════════════════════════════════════════════════════════
// VALIDACIÓN DE CONFIGURACIÓN CRÍTICA
// ═══════════════════════════════════════════════════════════════

$missing_config = [];

if (empty(BOT_TOKEN)) {
    $missing_config[] = 'BOT_TOKEN';
}
if (empty(DB_NAME)) {
    $missing_config[] = 'DB_NAME';
}
if (empty(DB_USER)) {
    $missing_config[] = 'DB_USER';
}
if (empty(DB_PASS)) {
    $missing_config[] = 'DB_PASS';
}

if (!empty($missing_config)) {
    $error_msg = "ERROR CRÍTICO: Configuración incompleta.\n\n";
    $error_msg .= "Variables faltantes: " . implode(', ', $missing_config) . "\n\n";
    $error_msg .= "SOLUCIÓN:\n";
    $error_msg .= "1. Crea un archivo .env en " . __DIR__ . "\n";
    $error_msg .= "2. Agrega las siguientes líneas:\n\n";
    $error_msg .= "BOT_TOKEN=tu_token_aqui\n";
    $error_msg .= "DB_HOST=localhost\n";
    $error_msg .= "DB_NAME=tu_base_datos\n";
    $error_msg .= "DB_USER=tu_usuario\n";
    $error_msg .= "DB_PASS=tu_contraseña\n";
    
    if (php_sapi_name() === 'cli') {
        die($error_msg);
    } else {
        die("<pre>" . htmlspecialchars($error_msg) . "</pre>");
    }
}

// ═══════════════════════════════════════════════════════════════
// CONFIGURACIÓN DE SEGURIDAD
// ═══════════════════════════════════════════════════════════════

define('ENABLE_DEBUG', filter_var(getenv('DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN));
define('MAX_LOG_SIZE', 10485760); // 10MB
define('LOG_RETENTION_DAYS', 30);

// Configuración de errores según modo
if (ENABLE_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// ═══════════════════════════════════════════════════════════════
// FUNCIÓN DE LOGGING SEGURO
// ═══════════════════════════════════════════════════════════════

function logSecure($message, $level = 'INFO') {
    if (!ENABLE_DEBUG && $level === 'DEBUG') {
        return;
    }
    
    $logFile = __DIR__ . '/logs/bot.log';
    $logDir = dirname($logFile);
    
    // Crear directorio de logs si no existe
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    // Rotar log si es muy grande
    if (file_exists($logFile) && filesize($logFile) > MAX_LOG_SIZE) {
        $backupFile = $logFile . '.' . date('Y-m-d-His') . '.log';
        rename($logFile, $backupFile);
        
        // Comprimir log antiguo
        if (function_exists('gzencode')) {
            $content = file_get_contents($backupFile);
            file_put_contents($backupFile . '.gz', gzencode($content, 9));
            unlink($backupFile);
        }
    }
    
    // Limpiar logs antiguos
    cleanOldLogs($logDir);
    
    // Sanitizar mensaje (ocultar datos sensibles)
    $sanitizedMessage = sanitizeLogMessage($message);
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$sanitizedMessage}\n";
    
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Sanitizar mensaje de log ocultando datos sensibles
 */
function sanitizeLogMessage($message) {
    $sensibleData = [
        BOT_TOKEN => '[TOKEN]',
        DB_PASS => '[DB_PASS]',
    ];
    
    return str_replace(array_keys($sensibleData), array_values($sensibleData), $message);
}

/**
 * Limpiar logs antiguos
 */
function cleanOldLogs($logDir) {
    // Solo limpiar una vez al día
    $lastCleanFile = $logDir . '/.last_clean';
    
    if (file_exists($lastCleanFile)) {
        $lastClean = (int)file_get_contents($lastCleanFile);
        if (time() - $lastClean < 86400) { // 24 horas
            return;
        }
    }
    
    $files = glob($logDir . '/bot.log.*');
    $cutoffTime = time() - (LOG_RETENTION_DAYS * 86400);
    
    foreach ($files as $file) {
        if (filemtime($file) < $cutoffTime) {
            @unlink($file);
        }
    }
    
    file_put_contents($lastCleanFile, time());
}

// ═══════════════════════════════════════════════════════════════
// CONSTANTES DE LA APLICACIÓN
// ═══════════════════════════════════════════════════════════════

define('TIMEZONE', getenv('TIMEZONE') ?: 'America/Lima');
date_default_timezone_set(TIMEZONE);

define('APP_NAME', 'IMEI Generator Bot');
define('APP_VERSION', '2.5.0');
define('APP_ENV', getenv('APP_ENV') ?: 'production');

// ═══════════════════════════════════════════════════════════════
// VERIFICACIÓN DE EXTENSIONES REQUERIDAS
// ═══════════════════════════════════════════════════════════════

$required_extensions = ['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring'];
$missing_extensions = [];

foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        $missing_extensions[] = $ext;
    }
}

if (!empty($missing_extensions)) {
    $error = "ERROR: Extensiones PHP faltantes: " . implode(', ', $missing_extensions);
    logSecure($error, 'ERROR');
    die($error . "\n");
}

// ═══════════════════════════════════════════════════════════════
// LOG DE INICIO
// ═══════════════════════════════════════════════════════════════

if (php_sapi_name() === 'cli') {
    logSecure("Bot iniciado - Versión " . APP_VERSION . " - Modo: " . APP_ENV, 'INFO');
}

?>