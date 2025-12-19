<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * CLASE PARA INTEGRACIÓN CON API DE IMEIDB.XYZ
 * ═══════════════════════════════════════════════════════════════
 * 
 * VERSIÓN 3.0 - Integración con imeidb.xyz
 * 
 * API: https://imeidb.xyz
 * Documentación: https://imeidb.xyz/docs
 * 
 * ═══════════════════════════════════════════════════════════════
 */

class IMEIDbAPI {
    private $db;
    private $apiKey;
    private $apiUrl;
    private $cacheTime;
    private $rateLimitDelay;
    
    public function __construct($database, $apiKey = null) {
        $this->db = $database;
        
        // Cargar configuración
        if ($apiKey !== null) {
            $this->apiKey = $apiKey;
        } else {
            $this->apiKey = defined('IMEIDB_API_KEY') ? IMEIDB_API_KEY : '';
        }
        
        $this->apiUrl = defined('IMEIDB_API_URL') ? IMEIDB_API_URL : 'https://imeidb.xyz/api/imei';
        $this->cacheTime = defined('IMEIDB_CACHE_TIME') ? IMEIDB_CACHE_TIME : 2592000;
        $this->rateLimitDelay = defined('IMEIDB_RATE_LIMIT') ? IMEIDB_RATE_LIMIT : 1;
    }
    
    /**
     * Consulta información de un IMEI/TAC usando la API
     * 
     * @param string $imei IMEI completo o TAC de 8 dígitos
     * @return array|false Datos del dispositivo o false si falla
     */
    public function consultarIMEI($imei) {
        // Validar entrada
        $imei = preg_replace('/[^0-9]/', '', $imei);
        
        if (strlen($imei) < 8) {
            return false;
        }
        
        // Si es TAC (8 dígitos), completar con ceros
        if (strlen($imei) == 8) {
            $imei = $imei . '0000000';
        }
        
        $tac = substr($imei, 0, 8);
        
        // Verificar caché primero
        $cached = $this->obtenerDeCache($imei);
        if ($cached !== false) {
            $cached['desde_cache'] = true;
            return $cached;
        }
        
        // Consultar API
        $resultado = $this->consultarAPI($imei);
        
        // Si la API falla, usar base de datos local
        if ($resultado === false) {
            $resultado = $this->consultarBaseDatosLocal($tac);
        }
        
        if ($resultado !== false) {
            // Guardar en caché
            $this->guardarEnCache($imei, $resultado);
            
            // Actualizar base de datos de modelos si es exitoso
            if (isset($resultado['modelo']) && isset($resultado['marca'])) {
                $this->db->guardarModelo($tac, $resultado['modelo'], $resultado['marca'], 'imeidb_api');
            }
        }
        
        return $resultado;
    }
    
    /**
     * Realiza la consulta a la API de imeidb.xyz
     * 
     * @param string $imei IMEI de 15 dígitos
     * @return array|false Datos procesados o false
     */
    private function consultarAPI($imei) {
        try {
            // Verificar que tengamos API key
            if (empty($this->apiKey)) {
                error_log("IMEIDb API: No se ha configurado la API Key");
                return false;
            }
            
            // Rate limiting
            sleep($this->rateLimitDelay);
            
            // Construir URL con parámetros
            $url = $this->apiUrl . '?' . http_build_query([
                'imei' => $imei,
                'token' => $this->apiKey
            ]);
            
            // Configurar contexto de la petición
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: TelegramBot/1.0',
                        'Accept: application/json'
                    ],
                    'timeout' => defined('IMEIDB_TIMEOUT') ? IMEIDB_TIMEOUT : 15,
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
                error_log("IMEIDb API: Error en la petición");
                return false;
            }
            
            // Decodificar respuesta
            $data = json_decode($response, true);
            
            if (!$data) {
                error_log("IMEIDb API: Respuesta inválida - " . substr($response, 0, 200));
                return false;
            }
            
            // Verificar si hay error
            if (isset($data['error']) || (isset($data['status']) && $data['status'] === 'error')) {
                $errorMsg = isset($data['error']) ? $data['error'] : (isset($data['message']) ? $data['message'] : 'Error desconocido');
                error_log("IMEIDb API Error: " . $errorMsg);
                return false;
            }
            
            // Verificar si la respuesta indica éxito
            if (isset($data['status']) && $data['status'] !== 'success' && $data['status'] !== 'ok') {
                return false;
            }
            
            // Procesar respuesta exitosa
            return $this->procesarRespuestaAPI($data, $imei);
            
        } catch (Exception $e) {
            error_log("Error en IMEIDb API: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Procesa la respuesta de la API y extrae datos relevantes
     * 
     * @param array $data Respuesta de la API
     * @param string $imei IMEI consultado
     * @return array Datos procesados
     */
    private function procesarRespuestaAPI($data, $imei) {
        $resultado = [
            'exito' => true,
            'fuente' => 'imeidb_api',
            'tac' => substr($imei, 0, 8)
        ];
        
        // Buscar datos en diferentes estructuras posibles
        // La API puede devolver los datos en 'data' o directamente en el root
        $info = isset($data['data']) ? $data['data'] : $data;
        
        // Extraer marca (brand, manufacturer, make)
        $camposMarca = ['brand', 'manufacturer', 'make', 'Brand', 'Manufacturer'];
        foreach ($camposMarca as $campo) {
            if (isset($info[$campo]) && !empty($info[$campo])) {
                $resultado['marca'] = trim($info[$campo]);
                break;
            }
        }
        
        // Extraer modelo (model, modelName, device)
        $camposModelo = ['model', 'modelName', 'device', 'deviceName', 'name', 'Model', 'ModelName'];
        foreach ($camposModelo as $campo) {
            if (isset($info[$campo]) && !empty($info[$campo])) {
                $resultado['modelo'] = trim($info[$campo]);
                break;
            }
        }
        
        // Información adicional disponible
        $camposExtra = [
            // Sistema operativo
            'os' => 'sistema',
            'operatingSystem' => 'sistema',
            'operating_system' => 'sistema',
            
            // País
            'country' => 'pais',
            'origin' => 'pais',
            
            // Especificaciones
            'display' => 'pantalla',
            'screen' => 'pantalla',
            'dimensions' => 'dimensiones',
            'weight' => 'peso',
            
            // Tecnología
            'technology' => 'tecnologia',
            'networkTechnology' => 'tecnologia',
            
            // Bandas
            'bands2g' => 'bandas_2g',
            'bands3g' => 'bandas_3g',
            'bands4g' => 'bandas_4g',
            'bands5g' => 'bandas_5g',
            '2g_bands' => 'bandas_2g',
            '3g_bands' => 'bandas_3g',
            '4g_bands' => 'bandas_4g',
            '5g_bands' => 'bandas_5g',
            
            // Fecha
            'releaseDate' => 'fecha_lanzamiento',
            'release_date' => 'fecha_lanzamiento',
            'announced' => 'fecha_anuncio',
            
            // Otros
            'description' => 'descripcion',
            'type' => 'tipo'
        ];
        
        foreach ($camposExtra as $campoBuscar => $campoResultado) {
            if (isset($info[$campoBuscar]) && !empty($info[$campoBuscar])) {
                $resultado[$campoResultado] = $info[$campoBuscar];
            }
        }
        
        // Si hay especificaciones adicionales en un objeto anidado
        if (isset($info['specs']) && is_array($info['specs'])) {
            foreach ($info['specs'] as $key => $value) {
                if (!empty($value) && !isset($resultado[$key])) {
                    $resultado[$key] = $value;
                }
            }
        }
        
        // Validar que tengamos al menos modelo o marca
        if (!isset($resultado['modelo']) && !isset($resultado['marca'])) {
            return false;
        }
        
        return $resultado;
    }
    
    /**
     * Consulta la base de datos local como fallback
     */
    private function consultarBaseDatosLocal($tac) {
        $modeloData = $this->db->buscarModelo($tac);
        
        if ($modeloData) {
            return [
                'modelo' => $modeloData['modelo'],
                'marca' => $modeloData['marca'] ?? null,
                'tac' => $tac,
                'fuente' => 'base_datos_local',
                'exito' => true
            ];
        }
        
        return false;
    }
    
    /**
     * Obtiene información formateada para mostrar al usuario
     * 
     * @param string $imei IMEI a consultar
     * @return string|false Mensaje formateado o false si falla
     */
    public function obtenerInformacionFormateada($imei) {
        $datos = $this->consultarIMEI($imei);
        
        if ($datos === false) {
            return false;
        }
        
        $mensaje = "📱 *INFORMACIÓN DEL DISPOSITIVO*\n\n";
        
        // Información básica
        if (isset($datos['marca'])) {
            $mensaje .= "🏷️ *Marca:* " . $datos['marca'] . "\n";
        }
        
        if (isset($datos['modelo'])) {
            $mensaje .= "📱 *Modelo:* " . $datos['modelo'] . "\n";
        }
        
        $mensaje .= "\n";
        
        // TAC
        if (isset($datos['tac'])) {
            $mensaje .= "🔢 *TAC:* `" . $datos['tac'] . "`\n";
        }
        
        // Tipo de dispositivo
        if (isset($datos['tipo'])) {
            $mensaje .= "📦 *Tipo:* " . $datos['tipo'] . "\n";
        }
        
        // País
        if (isset($datos['pais'])) {
            $mensaje .= "🌍 *País:* " . $datos['pais'] . "\n";
        }
        
        // Sistema operativo
        if (isset($datos['sistema'])) {
            $mensaje .= "💻 *Sistema:* " . $datos['sistema'] . "\n";
        }
        
        // Pantalla
        if (isset($datos['pantalla'])) {
            $mensaje .= "🖥️ *Pantalla:* " . $datos['pantalla'] . "\n";
        }
        
        // Descripción
        if (isset($datos['descripcion'])) {
            $mensaje .= "📝 *Descripción:* " . $datos['descripcion'] . "\n";
        }
        
        // Tecnología
        if (isset($datos['tecnologia'])) {
            $mensaje .= "\n📡 *Tecnología:* " . $datos['tecnologia'] . "\n";
        }
        
        // Bandas (si existen)
        $tieneBandas = false;
        if (isset($datos['bandas_2g']) || isset($datos['bandas_3g']) || 
            isset($datos['bandas_4g']) || isset($datos['bandas_5g'])) {
            $mensaje .= "\n*Bandas soportadas:*\n";
            $tieneBandas = true;
        }
        
        if ($tieneBandas) {
            if (isset($datos['bandas_2g'])) {
                $mensaje .= "• 2G: " . $datos['bandas_2g'] . "\n";
            }
            if (isset($datos['bandas_3g'])) {
                $mensaje .= "• 3G: " . $datos['bandas_3g'] . "\n";
            }
            if (isset($datos['bandas_4g'])) {
                $mensaje .= "• 4G: " . $datos['bandas_4g'] . "\n";
            }
            if (isset($datos['bandas_5g'])) {
                $mensaje .= "• 5G: " . $datos['bandas_5g'] . "\n";
            }
        }
        
        // Dimensiones
        if (isset($datos['dimensiones'])) {
            $mensaje .= "\n📏 *Dimensiones:* " . $datos['dimensiones'] . "\n";
        }
        
        if (isset($datos['peso'])) {
            $mensaje .= "⚖️ *Peso:* " . $datos['peso'] . "\n";
        }
        
        // Fecha de lanzamiento
        if (isset($datos['fecha_lanzamiento'])) {
            $mensaje .= "📅 *Lanzamiento:* " . $datos['fecha_lanzamiento'] . "\n";
        }
        
        $mensaje .= "\n";
        
        // Fuente de información
        if (isset($datos['fuente'])) {
            $fuenteTexto = [
                'imeidb_api' => '🌐 Información de IMEIDb API',
                'base_datos_local' => '💾 Información de base de datos local'
            ];
            
            if (isset($fuenteTexto[$datos['fuente']])) {
                $mensaje .= "_" . $fuenteTexto[$datos['fuente']] . "_";
            }
        }
        
        if (isset($datos['desde_cache']) && $datos['desde_cache']) {
            $mensaje .= "\n_⚡ Recuperado del caché_";
        }
        
        return $mensaje;
    }
    
    /**
     * Obtiene datos del caché
     */
    private function obtenerDeCache($imei) {
        $sql = "SELECT * FROM api_cache 
                WHERE imei = :imei 
                AND TIMESTAMPDIFF(SECOND, fecha_consulta, NOW()) < :cache_time";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([
                ':imei' => $imei,
                ':cache_time' => $this->cacheTime
            ]);
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                return json_decode($row['datos'], true);
            }
            
            return false;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    /**
     * Guarda datos en el caché
     */
    private function guardarEnCache($imei, $datos) {
        $sql = "INSERT INTO api_cache (imei, datos, fecha_consulta)
                VALUES (:imei, :datos, NOW())
                ON DUPLICATE KEY UPDATE 
                    datos = :datos2,
                    fecha_consulta = NOW()";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $datosJson = json_encode($datos);
            
            $stmt->execute([
                ':imei' => $imei,
                ':datos' => $datosJson,
                ':datos2' => $datosJson
            ]);
            
            return true;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    /**
     * Limpia el caché antiguo
     * 
     * @param int $diasAntiguedad Días de antigüedad para limpiar
     * @return int Número de registros eliminados
     */
    public function limpiarCacheAntiguo($diasAntiguedad = 60) {
        $sql = "DELETE FROM api_cache 
                WHERE TIMESTAMPDIFF(DAY, fecha_consulta, NOW()) > :dias";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([':dias' => $diasAntiguedad]);
            return $stmt->rowCount();
        } catch(PDOException $e) {
            return 0;
        }
    }
    
    /**
     * Obtiene estadísticas del uso de la API
     * 
     * @return array Estadísticas
     */
    public function obtenerEstadisticas() {
        $sql = "SELECT 
                    COUNT(*) as total_consultas,
                    COUNT(DISTINCT imei) as imeis_unicos,
                    MAX(fecha_consulta) as ultima_consulta
                FROM api_cache";
        
        try {
            $stmt = $this->db->conn->query($sql);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [
                'total_consultas' => 0,
                'imeis_unicos' => 0,
                'ultima_consulta' => null
            ];
        }
    }
    
    /**
     * Verifica si la API está configurada correctamente
     * 
     * @return bool True si está configurada
     */
    public function estaConfigurada() {
        return !empty($this->apiKey);
    }
    
    /**
     * Test de conectividad con la API
     * 
     * @return array Resultado del test con detalles
     */
    public function testConexion() {
        $imeiTest = '352033100000000'; // IMEI de prueba
        
        $resultado = [
            'api_configurada' => $this->estaConfigurada(),
            'api_key' => substr($this->apiKey, 0, 10) . '...',
            'url' => $this->apiUrl
        ];
        
        if (!$this->estaConfigurada()) {
            $resultado['error'] = 'API Key no configurada';
            $resultado['exito'] = false;
            return $resultado;
        }
        
        // Intentar consulta de prueba
        $datos = $this->consultarAPI($imeiTest);
        
        if ($datos !== false) {
            $resultado['exito'] = true;
            $resultado['mensaje'] = 'Conexión exitosa con imeidb.xyz';
            $resultado['datos_prueba'] = $datos;
        } else {
            $resultado['exito'] = false;
            $resultado['mensaje'] = 'Error al conectar con la API';
        }
        
        return $resultado;
    }
}
?>
