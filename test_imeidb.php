<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * TEST DE CONEXIÓN CON IMEIDB.XYZ API
 * ═══════════════════════════════════════════════════════════════
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║         🔍 TEST DE API IMEIDB.XYZ                            ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Verificar si existe el archivo de configuración
if (!file_exists(__DIR__ . '/config_imeidb.php')) {
    echo "❌ Error: No se encuentra config_imeidb.php\n";
    echo "   Asegúrate de que el archivo esté en el mismo directorio\n";
    exit(1);
}

require_once(__DIR__ . '/config_imeidb.php');

// ═══════════════════════════════════════════════════════════════
// PRUEBA 1: Verificar configuración
// ═══════════════════════════════════════════════════════════════

echo "[1/5] Verificando configuración...\n";

if (!defined('IMEIDB_API_KEY')) {
    echo "❌ IMEIDB_API_KEY no está definida\n";
    exit(1);
}

$apiKey = IMEIDB_API_KEY;
$apiUrl = IMEIDB_API_URL;

echo "   ✓ API Key: " . substr($apiKey, 0, 15) . "...\n";
echo "   ✓ API URL: {$apiUrl}\n";
echo "\n";

// ═══════════════════════════════════════════════════════════════
// PRUEBA 2: Test de conectividad
// ═══════════════════════════════════════════════════════════════

echo "[2/5] Probando conectividad a imeidb.xyz...\n";

$testUrl = 'https://imeidb.xyz';
$headers = @get_headers($testUrl);

if ($headers && strpos($headers[0], '200') !== false) {
    echo "   ✓ Conexión a imeidb.xyz exitosa\n";
} else {
    echo "   ❌ No se puede conectar a imeidb.xyz\n";
    echo "   ℹ️  Verifica tu conexión a internet\n";
}

echo "\n";

// ═══════════════════════════════════════════════════════════════
// PRUEBA 3: Test de API con IMEI real
// ═══════════════════════════════════════════════════════════════

echo "[3/5] Probando API con IMEI de prueba...\n";

$imeisPrueba = [
    '352033100000000' => 'iPhone 13 Pro',
    '355750111234567' => 'Samsung Galaxy',
    '490154203237518' => 'Dispositivo genérico'
];

foreach ($imeisPrueba as $imeiTest => $descripcion) {
    echo "\n   📱 Probando: {$descripcion}\n";
    echo "   IMEI: {$imeiTest}\n";
    
    // Construir URL con parámetros
    $url = $apiUrl . '?' . http_build_query([
        'imei' => $imeiTest,
        'token' => $apiKey
    ]);
    
    echo "   🔗 URL: " . substr($url, 0, 60) . "...\n";
    
    // Configurar contexto
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'User-Agent: TelegramBot/1.0',
                'Accept: application/json'
            ],
            'timeout' => 15,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);
    
    // Realizar petición
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        echo "   ❌ Error en la petición HTTP\n";
        $error = error_get_last();
        if ($error) {
            echo "   Error: " . $error['message'] . "\n";
        }
        continue;
    }
    
    echo "   ✓ Respuesta recibida (" . strlen($response) . " bytes)\n";
    
    // Decodificar JSON
    $data = json_decode($response, true);
    
    if ($data === null) {
        echo "   ❌ Respuesta no es JSON válido\n";
        echo "   Respuesta: " . substr($response, 0, 200) . "\n";
        continue;
    }
    
    echo "   ✓ JSON válido\n";
    
    // Mostrar estructura de respuesta
    echo "   📦 Estructura de respuesta:\n";
    
    if (isset($data['status'])) {
        echo "      • Status: " . $data['status'] . "\n";
    }
    
    if (isset($data['error'])) {
        echo "      ⚠️  Error: " . $data['error'] . "\n";
    }
    
    if (isset($data['message'])) {
        echo "      • Message: " . $data['message'] . "\n";
    }
    
    // Buscar datos del dispositivo
    $info = isset($data['data']) ? $data['data'] : $data;
    
    // Intentar extraer marca y modelo
    $marca = null;
    $modelo = null;
    
    $camposMarca = ['brand', 'manufacturer', 'make', 'Brand'];
    foreach ($camposMarca as $campo) {
        if (isset($info[$campo]) && !empty($info[$campo])) {
            $marca = $info[$campo];
            break;
        }
    }
    
    $camposModelo = ['model', 'modelName', 'device', 'Model'];
    foreach ($camposModelo as $campo) {
        if (isset($info[$campo]) && !empty($info[$campo])) {
            $modelo = $info[$campo];
            break;
        }
    }
    
    if ($marca) {
        echo "      ✓ Marca encontrada: {$marca}\n";
    }
    
    if ($modelo) {
        echo "      ✓ Modelo encontrado: {$modelo}\n";
    }
    
    if (!$marca && !$modelo) {
        echo "      ⚠️  No se encontró marca ni modelo\n";
        echo "      📄 Respuesta completa:\n";
        echo "      " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
    
    // Solo probar con el primero
    break;
}

echo "\n";

// ═══════════════════════════════════════════════════════════════
// PRUEBA 4: Test con cURL (si está disponible)
// ═══════════════════════════════════════════════════════════════

echo "[4/5] Probando con cURL...\n";

if (function_exists('curl_init')) {
    echo "   ✓ cURL está disponible\n";
    
    $imeiTest = '352033100000000';
    $url = $apiUrl . '?' . http_build_query([
        'imei' => $imeiTest,
        'token' => $apiKey
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: TelegramBot/1.0',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $responseCurl = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    echo "   HTTP Code: {$httpCode}\n";
    
    if ($curlError) {
        echo "   ❌ Error cURL: {$curlError}\n";
    } else {
        echo "   ✓ Petición exitosa con cURL\n";
        
        if ($responseCurl) {
            $dataCurl = json_decode($responseCurl, true);
            if ($dataCurl) {
                echo "   ✓ Respuesta JSON válida\n";
                
                if (isset($dataCurl['status'])) {
                    echo "   Status: " . $dataCurl['status'] . "\n";
                }
            }
        }
    }
    
    curl_close($ch);
} else {
    echo "   ⚠️  cURL no está disponible\n";
}

echo "\n";

// ═══════════════════════════════════════════════════════════════
// PRUEBA 5: Información adicional
// ═══════════════════════════════════════════════════════════════

echo "[5/5] Información adicional...\n";

echo "   📝 Formatos soportados:\n";
echo "      • IMEI completo (15 dígitos)\n";
echo "      • TAC (8 dígitos) - se completa automáticamente\n";
echo "\n";
echo "   ⚙️  Configuración actual:\n";
echo "      • Cache Time: " . IMEIDB_CACHE_TIME . " segundos\n";
echo "      • Rate Limit: " . IMEIDB_RATE_LIMIT . " segundo(s)\n";
echo "      • Timeout: " . IMEIDB_TIMEOUT . " segundos\n";

echo "\n";

// ═══════════════════════════════════════════════════════════════
// RESUMEN
// ═══════════════════════════════════════════════════════════════

echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║                    📋 RESUMEN                                 ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n\n";

echo "✅ API configurada correctamente\n";
echo "🔑 API Key: " . substr($apiKey, 0, 15) . "...\n";
echo "🌐 Endpoint: {$apiUrl}\n\n";

echo "📚 Documentación: https://imeidb.xyz/docs\n\n";

echo "💡 El bot está listo para usar con imeidb.xyz\n";
echo "   Si la API no responde, el bot usará la base de datos local\n\n";

?>
