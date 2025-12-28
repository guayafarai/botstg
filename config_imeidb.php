<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * CONFIGURACIÓN DE API IMEIDB.XYZ - VERSIÓN SEGURA
 * ═══════════════════════════════════════════════════════════════
 */

// API Key desde variable de entorno
define('IMEIDB_API_KEY', getenv('IMEIDB_API_KEY') ?: '');

// Configuración de la API
define('IMEIDB_API_URL', 'https://imeidb.xyz/api/imei');
define('IMEIDB_CACHE_TIME', 2592000); // 30 días
define('IMEIDB_RATE_LIMIT', 1); // Segundos entre peticiones
define('IMEIDB_TIMEOUT', 15); // Timeout de peticiones
define('IMEIDB_MAX_RETRIES', 3); // Intentos de reconexión

// Validar API key
if (empty(IMEIDB_API_KEY)) {
    logSecure("ADVERTENCIA: IMEIDB_API_KEY no configurada. Se usará solo base de datos local.", 'WARN');
}

?>