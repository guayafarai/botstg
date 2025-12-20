<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * CONFIGURACIÓN DEL BOT - VERSIÓN SEGURA
 * ═══════════════════════════════════════════════════════════════
 * 
 * IMPORTANTE: En producción, usar variables de entorno
 * No subir este archivo a repositorios públicos
 */

// Cargar desde variables de entorno si están disponibles
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '7476359440:AAGFOKj66X8ayhPEuRdtFuQEAIJp2a6ilgk');
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'chamotvx_f4mobile');
define('DB_USER', getenv('DB_USER') ?: 'chamotvx_mobilef4');
define('DB_PASS', getenv('DB_PASS') ?: 'Guayaba123!!@');

// Configuración de seguridad
define('ENABLE_DEBUG', false); // Cambiar a false en producción
define('MAX_LOG_SIZE', 10485760); // 10MB

// Validar configuración
if (BOT_TOKEN === 'TU_TOKEN_AQUI') {
    die("ERROR: Debes configurar BOT_TOKEN en config_bot.php\n");
}

// Función de logging seguro
function logSecure($message, $level = 'INFO') {
    if (!ENABLE_DEBUG && $level === 'DEBUG') {
        return;
    }
    
    $logFile = __DIR__ . '/logs/bot.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    // Rotar log si es muy grande
    if (file_exists($logFile) && filesize($logFile) > MAX_LOG_SIZE) {
        rename($logFile, $logFile . '.' . date('Y-m-d-His'));
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $sanitizedMessage = str_replace([BOT_TOKEN, DB_PASS], ['[TOKEN]', '[PASS]'], $message);
    $logEntry = "[{$timestamp}] [{$level}] {$sanitizedMessage}\n";
    
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

?>
