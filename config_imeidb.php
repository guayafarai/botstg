<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * CONFIGURACIÓN DE API IMEIDB.XYZ
 * ═══════════════════════════════════════════════════════════════
 * 
 * API: https://imeidb.xyz
 * Documentación: https://imeidb.xyz/docs
 * 
 * ═══════════════════════════════════════════════════════════════
 */

// Tu API Key de imeidb.xyz
define('IMEIDB_API_KEY', 'XdjQg-NF1Bke1_BIj1Vr');

// URL de la API
define('IMEIDB_API_URL', 'https://imeidb.xyz/api/imei');

// Configuración de caché
define('IMEIDB_CACHE_TIME', 2592000); // 30 días en segundos

// Rate limiting (segundos entre peticiones)
define('IMEIDB_RATE_LIMIT', 1);

// Timeout de peticiones (segundos)
define('IMEIDB_TIMEOUT', 15);

?>
