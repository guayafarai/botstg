<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * CONFIGURACIÓN DE API IMEIDB.XYZ - VERSIÓN SEGURA
 * ═══════════════════════════════════════════════════════════════
 */

// API Key desde variable de entorno o valor por defecto
define('IMEIDB_API_KEY', getenv('IMEIDB_API_KEY') ?: 'XdjQg-NF1Bke1_BIj1Vr');

// Configuración de la API
define('IMEIDB_API_URL', 'https://imeidb.xyz/api/imei');
define('IMEIDB_CACHE_TIME', 2592000); // 30 días
define('IMEIDB_RATE_LIMIT', 1); // Segundos entre peticiones
define('IMEIDB_TIMEOUT', 15); // Timeout de peticiones

// Validar API key
if (IMEIDB_API_KEY === 'TU_API_KEY_AQUI' || empty(IMEIDB_API_KEY)) {
    error_log("ADVERTENCIA: IMEIDB_API_KEY no configurada. El bot usará solo base de datos local.");
}

?>
