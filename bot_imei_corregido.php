<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * BOT TELEGRAM - GENERADOR DE IMEI CON SISTEMA DE CRÉDITOS
 * VERSIÓN 2.3.1 - CORREGIDO (comandoStatsAPI fixed)
 * ═══════════════════════════════════════════════════════════════
 * 
 * CORRECCIONES IMPLEMENTADAS:
 * 1. Validación de TAC mejorada - acepta cualquier entrada numérica
 * 2. Manejo de estados corregido
 * 3. Botones del menú funcionando correctamente
 * 4. Gestión de modelos mejorada
 * 5. Sistema de pagos optimizado
 * 6. comandoStatsAPI corregido - usa ultima_consulta en lugar de fecha_agregado
 */

// ============================================
// CONFIGURACIÓN Y DEPENDENCIAS
// ============================================

require_once(__DIR__ . '/config_bot.php');
require_once(__DIR__ . '/config_imeidb.php');
require_once(__DIR__ . '/Database.php');
require_once(__DIR__ . '/imeidb_api.php');
require_once(__DIR__ . '/sistema_pagos.php');
require_once(__DIR__ . '/comandos_pagos.php');

define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// Configuración del sistema de créditos
define('CREDITOS_REGISTRO', 10);
define('COSTO_GENERACION', 1);
define('ADMIN_IDS', [7334970766]);

// ============================================
// CLASE PARA GESTIÓN DE ESTADOS
// ============================================

class EstadosUsuario {
    private $cacheFile = '/tmp/bot_estados.json';
    private $estados = [];
    private $loaded = false;
    
    public function __construct() {
        $this->cargarEstados();
    }
    
    /**
     * Establecer estado de usuario
     */
    public function setEstado($chatId, $estado, $datos = []) {
        $this->estados[(string)$chatId] = [
            'estado' => $estado,
            'datos' => $datos,
            'timestamp' => time()
        ];
        $this->guardarEstados();
    }
    
    /**
     * Obtener estado de usuario
     */
    public function getEstado($chatId) {
        $chatId = (string)$chatId;
        
        if (isset($this->estados[$chatId])) {
            // Verificar si el estado ha expirado (10 minutos)
            if (time() - $this->estados[$chatId]['timestamp'] > 600) {
                unset($this->estados[$chatId]);
                $this->guardarEstados();
                return null;
            }
            return $this->estados[$chatId];
        }
        return null;
    }
    
    /**
     * Limpiar estado de usuario
     */
    public function limpiarEstado($chatId) {
        $chatId = (string)$chatId;
        if (isset($this->estados[$chatId])) {
            unset($this->estados[$chatId]);
            $this->guardarEstados();
        }
    }
    
    /**
     * Cargar estados desde archivo
     */
    private function cargarEstados() {
        if ($this->loaded) {
            return;
        }
        
        if (file_exists($this->cacheFile)) {
            $contenido = @file_get_contents($this->cacheFile);
            if ($contenido !== false) {
                $decoded = json_decode($contenido, true);
                $this->estados = is_array($decoded) ? $decoded : [];
            }
        }
        
        $this->loaded = true;
    }
    
    /**
     * Guardar estados en archivo
     */
    private function guardarEstados() {
        $encoded = json_encode($this->estados);
        @file_put_contents($this->cacheFile, $encoded, LOCK_EX);
    }
    
    /**
     * Limpiar estados expirados
     */
    public function limpiarExpirados() {
        $now = time();
        $cambios = false;
        
        foreach ($this->estados as $chatId => $estado) {
            if ($now - $estado['timestamp'] > 600) {
                unset($this->estados[$chatId]);
                $cambios = true;
            }
        }
        
        if ($cambios) {
            $this->guardarEstados();
        }
    }
}

// ============================================
// FUNCIONES IMEI
// ============================================

/**
 * Validar IMEI usando algoritmo de Luhn
 */
function validarIMEI($imei) {
    $imei = preg_replace('/[^0-9]/', '', $imei);
    
    if (strlen($imei) != 15 || !ctype_digit($imei)) {
        return false;
    }
    
    // Rechazar IMEIs con todos los dígitos iguales
    if (preg_match('/^(.)\1{14}$/', $imei)) {
        return false;
    }
    
    // Algoritmo de Luhn
    $suma = 0;
    for ($i = 0; $i < 14; $i++) {
        $digito = (int)$imei[$i];
        
        if ($i % 2 === 1) {
            $digito *= 2;
            if ($digito > 9) {
                $digito -= 9;
            }
        }
        
        $suma += $digito;
    }
    
    $checkCalculado = (10 - ($suma % 10)) % 10;
    $checkReal = (int)$imei[14];
    
    return $checkCalculado === $checkReal;
}

/**
 * Generar número de serie aleatorio
 */
function generarSerial() {
    return str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Calcular dígito verificador
 */
function calcularDigitoVerificador($imei14) {
    $suma = 0;
    
    for ($i = 0; $i < 14; $i++) {
        $digito = (int)$imei14[$i];
        
        if ($i % 2 === 1) {
            $digito *= 2;
            if ($digito > 9) {
                $digito -= 9;
            }
        }
        
        $suma += $digito;
    }
    
    return (10 - ($suma % 10)) % 10;
}

/**
 * Validar TAC - MEJORADO
 */
function validarTAC($tac) {
    // Limpiar entrada
    $tac = preg_replace('/[^0-9]/', '', $tac);
    
    // Verificar longitud
    if (strlen($tac) < 8) {
        return false;
    }
    
    // Si es más largo, tomar los primeros 8 dígitos
    if (strlen($tac) > 8) {
        $tac = substr($tac, 0, 8);
    }
    
    // Verificar que sea numérico
    if (!ctype_digit($tac)) {
        return false;
    }
    
    // Rechazar TACs con todos los dígitos iguales (00000000, 11111111, etc.)
    if (preg_match('/^(.)\1{7}$/', $tac)) {
        return false;
    }
    
    // Rechazar secuencias obvias
    $secuenciasInvalidas = [
        '12345678', '87654321', '11111111', '22222222', '33333333',
        '44444444', '55555555', '66666666', '77777777', '88888888',
        '99999999', '00000000'
    ];
    
    if (in_array($tac, $secuenciasInvalidas)) {
        return false;
    }
    
    return $tac;
}

/**
 * Generar IMEI completo
 */
function generarIMEI($tac) {
    $serial = generarSerial();
    $imei14 = $tac . $serial;
    $digitoVerificador = calcularDigitoVerificador($imei14);
    $imeiCompleto = $imei14 . $digitoVerificador;
    
    return [
        'imei_completo' => $imeiCompleto,
        'tac' => $tac,
        'serial' => $serial,
        'digito_verificador' => (string)$digitoVerificador
    ];
}

/**
 * Generar múltiples IMEIs
 */
function generarMultiplesIMEIs($tac, $cantidad = 2) {
    $imeis = [];
    $cantidad = max(1, min(10, (int)$cantidad)); // Entre 1 y 10
    
    for ($i = 0; $i < $cantidad; $i++) {
        $imeis[] = generarIMEI($tac);
    }
    
    return $imeis;
}

/**
 * Extraer TAC de un IMEI o texto - MEJORADO
 */
function extraerTAC($texto) {
    // Limpiar el texto de cualquier carácter no numérico
    $numeros = preg_replace('/[^0-9]/', '', $texto);
    
    // Si tiene al menos 8 dígitos, tomar los primeros 8
    if (strlen($numeros) >= 8) {
        return substr($numeros, 0, 8);
    }
    
    return false;
}

// ============================================
// FUNCIONES TELEGRAM
// ============================================

/**
 * Enviar mensaje con manejo de errores mejorado
 */
function enviarMensaje($chatId, $texto, $parseMode = 'Markdown', $replyMarkup = null) {
    $url = API_URL . 'sendMessage';
    $data = [
        'chat_id' => $chatId,
        'text' => $texto,
        'parse_mode' => $parseMode
    ];
    
    if ($replyMarkup) {
        $data['reply_markup'] = $replyMarkup;
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        logSecure("Error al enviar mensaje a {$chatId}: {$error}", 'ERROR');
        return false;
    }
    
    if ($httpCode !== 200) {
        logSecure("HTTP {$httpCode} al enviar mensaje a {$chatId}", 'WARN');
        return false;
    }
    
    $result = json_decode($response, true);
    if (!isset($result['ok']) || !$result['ok']) {
        logSecure("Telegram API error: " . ($result['description'] ?? 'Unknown'), 'ERROR');
        return false;
    }
    
    return true;
}

/**
 * Responder a callback query
 */
function answerCallbackQuery($callbackQueryId, $texto = '', $showAlert = false) {
    $url = API_URL . 'answerCallbackQuery';
    $data = [
        'callback_query_id' => $callbackQueryId,
        'text' => $texto,
        'show_alert' => $showAlert
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 5
    ]);
    
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Crear teclado personalizado
 */
function crearTeclado($botones) {
    return json_encode([
        'keyboard' => $botones,
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ]);
}

/**
 * Obtener teclado principal
 */
function getTecladoPrincipal($esAdmin = false) {
    $teclado = [
        [['text' => '📱 Generar IMEI'], ['text' => '💳 Mis Créditos']],
        [['text' => '📊 Mi Perfil'], ['text' => '💰 Comprar Créditos']],
        [['text' => '📜 Historial'], ['text' => '❓ Ayuda']]
    ];
    
    if ($esAdmin) {
        $teclado[] = [['text' => '👑 Panel Admin']];
    }
    
    return crearTeclado($teclado);
}

/**
 * Obtener teclado de administración
 */
function getTecladoAdmin() {
    return crearTeclado([
        [['text' => '📊 Estadísticas'], ['text' => '👥 Top Usuarios']],
        [['text' => '💸 Pagos Pendientes'], ['text' => '🚨 Ver Fraudes']], // 👈 NUEVO
        [['text' => '🚫 Bloquear Usuario'], ['text' => '⭐ Hacer Premium']],
        [['text' => '📱 Gestionar Modelos'], ['text' => '📡 Stats API']],
        [['text' => '🔙 Volver al Menú']]
    ]);
}


/**
 * Verificar si es administrador
 */
function esAdmin($telegramId) {
    return in_array((int)$telegramId, ADMIN_IDS);
}

// ============================================
// COMANDOS DEL BOT
// ============================================

/**
 * Comando /start
 */
function comandoStart($chatId, $message, $db) {
    $telegramId = (int)$message['from']['id'];
    $username = $message['from']['username'] ?? '';
    $firstName = $message['from']['first_name'] ?? 'Usuario';
    $lastName = $message['from']['last_name'] ?? '';
    
    $esNuevo = $db->registrarUsuario($telegramId, $username, $firstName, $lastName);
    $usuario = $db->getUsuario($telegramId);
    $esAdminUser = esAdmin($telegramId);
    
    if ($esNuevo) {
        $respuesta = "╔═══════════════════════════╗\n";
        $respuesta .= "║   🎉 ¡BIENVENIDO! 🎉      ║\n";
        $respuesta .= "╚═══════════════════════════╝\n\n";
        $respuesta .= "👋 Hola *{$firstName}*\n\n";
        $respuesta .= "┏━━━━━━━━━━━━━━━━━━━━━━━┓\n";
        $respuesta .= "┃  🎁 REGALO DE BIENVENIDA  ┃\n";
        $respuesta .= "┗━━━━━━━━━━━━━━━━━━━━━━━┛\n\n";
        $respuesta .= "💎 Has recibido *" . CREDITOS_REGISTRO . " créditos* de regalo\n";
        $respuesta .= "🚀 ¡Ya puedes empezar a generar IMEIs!\n\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $respuesta .= "📱 *¿CÓMO FUNCIONA?*\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "1️⃣ Presiona *📱 Generar IMEI*\n";
        $respuesta .= "2️⃣ Envía un TAC de 8 dígitos\n";
        $respuesta .= "3️⃣ Recibe 2 IMEIs válidos\n";
        $respuesta .= "4️⃣ Costo: " . COSTO_GENERACION . " crédito\n\n";
        $respuesta .= "✨ Usa el menú para navegar";
    } else {
        $statusEmoji = $usuario['es_premium'] ? '⭐' : '👤';
        
        $respuesta = "╔═══════════════════════════╗\n";
        $respuesta .= "║  {$statusEmoji} BIENVENIDO DE VUELTA {$statusEmoji}  ║\n";
        $respuesta .= "╚═══════════════════════════╝\n\n";
        $respuesta .= "👋 Hola *{$firstName}*\n\n";
        $respuesta .= "💰 Créditos: *{$usuario['creditos']}*\n";
        $respuesta .= "📊 Generaciones: *{$usuario['total_generaciones']}*\n\n";
        $respuesta .= "🎯 Selecciona una opción del menú";
    }
    
    enviarMensaje($chatId, $respuesta, 'Markdown', getTecladoPrincipal($esAdminUser));
}

/**
 * Comando Mis Créditos
 */
function comandoMisCreditos($chatId, $telegramId, $db) {
    $usuario = $db->getUsuario($telegramId);
    
    if (!$usuario) {
        enviarMensaje($chatId, "❌ Usuario no encontrado. Usa /start");
        return;
    }
    
    $creditos = (int)$usuario['creditos'];
    $iconoCreditos = $creditos > 50 ? '💎' : ($creditos > 20 ? '💰' : ($creditos > 5 ? '🪙' : '⚠️'));
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║    {$iconoCreditos} TUS CRÉDITOS {$iconoCreditos}     ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    $respuesta .= "💰 *{$creditos}* créditos\n\n";
    $respuesta .= "🔢 Generaciones restantes: *{$creditos}*\n";
    $respuesta .= "📱 Total generados: *{$usuario['total_generaciones']}*\n";
    
    if ($creditos < 5) {
        $respuesta .= "\n⚠️ *¡SALDO BAJO!*\n";
        $respuesta .= "🛒 → *💰 Comprar Créditos*";
    }
    
    enviarMensaje($chatId, $respuesta);
}

/**
 * Comando Mi Perfil
 */
function comandoPerfil($chatId, $telegramId, $db) {
    $usuario = $db->getUsuario($telegramId);
    
    if (!$usuario) {
        enviarMensaje($chatId, "❌ Usuario no encontrado. Usa /start");
        return;
    }
    
    $statusEmoji = $usuario['es_premium'] ? '⭐' : '👤';
    $statusTexto = $usuario['es_premium'] ? 'Premium' : 'Estándar';
    
    $fechaRegistro = date('d/m/Y', strtotime($usuario['fecha_registro']));
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║   {$statusEmoji} TU PERFIL {$statusEmoji}        ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    $respuesta .= "👤 Usuario: " . ($usuario['username'] ? "@{$usuario['username']}" : "Sin usuario") . "\n";
    $respuesta .= "💰 Créditos: *{$usuario['creditos']}*\n";
    $respuesta .= "📊 Generaciones: *{$usuario['total_generaciones']}*\n";
    $respuesta .= "{$statusEmoji} Tipo: *{$statusTexto}*\n";
    $respuesta .= "📆 Registro: {$fechaRegistro}";
    
    enviarMensaje($chatId, $respuesta);
}

/**
 * Comando Historial
 */
function comandoHistorial($chatId, $telegramId, $db) {
    $historial = $db->getHistorialUsuario($telegramId, 10);
    
    if (empty($historial)) {
        $respuesta = "📭 *Sin historial aún*\n\n";
        $respuesta .= "💡 Genera tu primer IMEI\n";
        $respuesta .= "🎯 → *📱 Generar IMEI*";
        
        enviarMensaje($chatId, $respuesta);
        return;
    }
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║    📜 TU HISTORIAL        ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    
    foreach ($historial as $item) {
        $fecha = date('d/m H:i', strtotime($item['fecha']));
        $modelo = $item['modelo'] != 'Desconocido' ? $item['modelo'] : 'Modelo desconocido';
        
        $respuesta .= "📱 {$modelo}\n";
        $respuesta .= "🔢 TAC: `{$item['tac']}`\n";
        $respuesta .= "📅 {$fecha}\n\n";
    }
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "Últimas 10 generaciones";
    
    enviarMensaje($chatId, $respuesta);
}

/**
 * Comando Ayuda
 */
function comandoAyuda($chatId) {
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║       ❓ AYUDA            ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    $respuesta .= "🤖 *¿CÓMO USAR EL BOT?*\n\n";
    $respuesta .= "1️⃣ *Generar IMEI:*\n";
    $respuesta .= "   • Presiona 📱 Generar IMEI\n";
    $respuesta .= "   • Envía un TAC de 8 dígitos\n";
    $respuesta .= "   • Ejemplo: `35203310`\n\n";
    $respuesta .= "2️⃣ *Consultar créditos:*\n";
    $respuesta .= "   • Presiona 💳 Mis Créditos\n\n";
    $respuesta .= "3️⃣ *Comprar créditos:*\n";
    $respuesta .= "   • Presiona 💰 Comprar Créditos\n";
    $respuesta .= "   • Selecciona un paquete\n";
    $respuesta .= "   • Sigue las instrucciones\n\n";
    $respuesta .= "💡 *¿QUÉ ES UN TAC?*\n";
    $respuesta .= "Es el código de 8 dígitos que identifica el modelo del dispositivo.\n\n";
    $respuesta .= "📝 *EJEMPLO:*\n";
    $respuesta .= "`35203310` → iPhone 13 Pro\n\n";
    $respuesta .= "💳 *COSTO:*\n";
    $respuesta .= "• " . COSTO_GENERACION . " crédito por generación\n";
    $respuesta .= "• 2 IMEIs por generación\n\n";
    $respuesta .= "🎁 *REGISTRO:*\n";
    $respuesta .= "• " . CREDITOS_REGISTRO . " créditos gratis";
    
    enviarMensaje($chatId, $respuesta);
}

/**
 * Comando Info
 */
function comandoInfo($chatId, $texto, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) < 2) {
        enviarMensaje($chatId, "❌ Uso: `/info [TAC]`\n\nEjemplo: `/info 35203310`");
        return;
    }
    
    $tac = validarTAC($partes[1]);
    
    if (!$tac) {
        enviarMensaje($chatId, "❌ TAC inválido\n\nDebe tener 8 dígitos");
        return;
    }
    
    // Buscar en base de datos local
    $modeloData = $db->buscarModelo($tac);
    
    if (!$modeloData) {
        // Buscar en API
        $api = new IMEIDbAPI($db, IMEIDB_API_KEY);
        $datosAPI = $api->consultarIMEI($tac);
        
        if ($datosAPI && isset($datosAPI['modelo'])) {
            $modeloData = [
                'tac' => $tac,
                'modelo' => $datosAPI['modelo'],
                'marca' => $datosAPI['marca'] ?? 'Desconocida',
                'fuente' => 'api'
            ];
            
            // Guardar en BD local
            $db->guardarModelo($tac, $modeloData['modelo'], $modeloData['marca'], 'imeidb_api');
        } else {
            enviarMensaje($chatId, "❌ No se encontró información para este TAC");
            return;
        }
    }
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║  📱 INFORMACIÓN DEL TAC   ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    $respuesta .= "🔢 TAC: `{$tac}`\n";
    $respuesta .= "📱 Modelo: *{$modeloData['modelo']}*\n";
    
    if (isset($modeloData['marca']) && $modeloData['marca']) {
        $respuesta .= "🏭 Marca: {$modeloData['marca']}\n";
    }
    
    $fuente = $modeloData['fuente'] ?? 'local';
    $iconoFuente = $fuente == 'api' || $fuente == 'imeidb_api' ? '🌐' : '💾';
    $respuesta .= "{$iconoFuente} Fuente: " . ucfirst($fuente);
    
    enviarMensaje($chatId, $respuesta);
}

/**
 * Procesar TAC para generar IMEI - VERSIÓN MEJORADA
 */
function procesarTAC($chatId, $texto, $telegramId, $db, $estados) {
    $usuario = $db->getUsuario($telegramId);
    
    if (!$usuario) {
        enviarMensaje($chatId, "❌ No estás registrado. Usa /start");
        $estados->limpiarEstado($chatId);
        return;
    }
    
    if ($usuario['bloqueado']) {
        enviarMensaje($chatId, "🚫 Tu cuenta está suspendida");
        $estados->limpiarEstado($chatId);
        return;
    }
    
    // Extraer TAC del texto - MEJORADO
    $tacExtraido = extraerTAC($texto);
    
    if (!$tacExtraido) {
        // Si no se pudo extraer, limpiar y validar directamente
        $tacExtraido = preg_replace('/[^0-9]/', '', $texto);
    }
    
    // Validar TAC - ahora devuelve el TAC válido o false
    $tac = validarTAC($tacExtraido);
    
    if (!$tac) {
        $respuesta = "❌ *TAC INVÁLIDO*\n\n";
        $respuesta .= "El TAC debe tener *8 dígitos numéricos*\n\n";
        $respuesta .= "📝 *EJEMPLOS VÁLIDOS:*\n";
        $respuesta .= "• `35203310` → iPhone 13 Pro\n";
        $respuesta .= "• `35289311` → Samsung Galaxy\n";
        $respuesta .= "• `35665810` → Xiaomi\n\n";
        $respuesta .= "💡 *CONSEJO:*\n";
        $respuesta .= "Envía solo los 8 dígitos del TAC\n\n";
        $respuesta .= "❓ ¿No sabes tu TAC? Usa:\n";
        $respuesta .= "`/info [TAC]` para consultar";
        
        enviarMensaje($chatId, $respuesta);
        return;
    }
    
    // Verificar créditos
    if ($usuario['creditos'] < COSTO_GENERACION && !$usuario['es_premium']) {
        $respuesta = "⚠️ *SIN CRÉDITOS*\n\n";
        $respuesta .= "💰 Saldo actual: *{$usuario['creditos']}*\n";
        $respuesta .= "💎 Necesitas: *" . COSTO_GENERACION . "* crédito\n\n";
        $respuesta .= "🛒 Presiona *💰 Comprar Créditos*\n";
        $respuesta .= "para recargar tu saldo";
        enviarMensaje($chatId, $respuesta);
        return;
    }
    
    // Buscar información del modelo
    $modeloData = $db->buscarModelo($tac);
    
    if (!$modeloData) {
        // Intentar buscar en API
        try {
            $api = new IMEIDbAPI($db, IMEIDB_API_KEY);
            $datosAPI = $api->consultarIMEI($tac);
            
            if ($datosAPI && isset($datosAPI['modelo'])) {
                $modeloData = [
                    'tac' => $tac,
                    'modelo' => $datosAPI['modelo'],
                    'marca' => $datosAPI['marca'] ?? null,
                    'fuente' => 'api'
                ];
                
                // Guardar en BD local
                $db->guardarModelo($tac, $modeloData['modelo'], $modeloData['marca'], 'imeidb_api');
            }
        } catch (Exception $e) {
            logSecure("Error al consultar API: " . $e->getMessage(), 'WARN');
        }
    }
    
    // Generar IMEIs
    $imeis = generarMultiplesIMEIs($tac, 2);
    
    // Descontar créditos (si no es premium)
    if (!$usuario['es_premium']) {
        $descontado = $db->actualizarCreditos($telegramId, COSTO_GENERACION, 'subtract');
        
        if (!$descontado) {
            enviarMensaje($chatId, "❌ Error al descontar créditos. Intenta nuevamente.");
            return;
        }
        
        $db->registrarTransaccion($telegramId, 'uso', COSTO_GENERACION, "Generación de IMEIs - TAC: {$tac}");
    }
    
    // Incrementar contador
    $db->incrementarGeneraciones($telegramId);
    
    // Registrar uso
    $nombreModelo = $modeloData ? $modeloData['modelo'] : 'Desconocido';
    $db->registrarUso($telegramId, $tac, $nombreModelo);
    
    // Construir respuesta
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║  ✅ GENERACIÓN EXITOSA    ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    
    if ($modeloData && isset($modeloData['marca'])) {
        $respuesta .= "🏭 Marca: *{$modeloData['marca']}*\n";
    }
    
    $respuesta .= "📱 Modelo: *{$nombreModelo}*\n";
    $respuesta .= "🔢 TAC: `{$tac}`\n\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "📋 *2 IMEIS GENERADOS*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    foreach ($imeis as $index => $imei) {
        $numero = $index + 1;
        $respuesta .= "🔹 *IMEI {$numero}:*\n";
        $respuesta .= "`{$imei['imei_completo']}`\n\n";
    }
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    // Mostrar créditos restantes
    $usuario = $db->getUsuario($telegramId);
    if (!$usuario['es_premium']) {
        $iconoCred = $usuario['creditos'] > 5 ? '💰' : '⚠️';
        $respuesta .= "{$iconoCred} Créditos restantes: *{$usuario['creditos']}*";
    } else {
        $respuesta .= "⭐ Usuario Premium - Créditos ilimitados";
    }
    
    enviarMensaje($chatId, $respuesta);
    
    // Limpiar estado
    $estados->limpiarEstado($chatId);
}

// ============================================
// COMANDOS DE ADMINISTRACIÓN
// ============================================

/**
 * Estadísticas para administradores
 */
function comandoEstadisticasAdmin($chatId, $db) {
    $stats = $db->getEstadisticasGenerales();
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║  📊 ESTADÍSTICAS ADMIN    ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    $respuesta .= "👥 Total usuarios: *{$stats['total_usuarios']}*\n";
    $respuesta .= "💰 Créditos en sistema: *{$stats['total_creditos']}*\n";
    $respuesta .= "📱 Total generaciones: *{$stats['total_generaciones']}*\n";
    $respuesta .= "👤 Activos hoy: *{$stats['usuarios_hoy']}*\n";
    $respuesta .= "⭐ Usuarios Premium: *{$stats['usuarios_premium']}*\n";
    $respuesta .= "💸 Pagos pendientes: *{$stats['pagos_pendientes']}*";
    
    enviarMensaje($chatId, $respuesta);
}

/**
 * Top usuarios
 */
function comandoTopUsuarios($chatId, $db) {
    $top = $db->getTopUsuarios(10);
    
    if (empty($top)) {
        enviarMensaje($chatId, "📭 No hay usuarios registrados");
        return;
    }
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║   👥 TOP 10 USUARIOS      ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    
    foreach ($top as $i => $usuario) {
        $pos = $i + 1;
        $emoji = $pos == 1 ? "🥇" : ($pos == 2 ? "🥈" : ($pos == 3 ? "🥉" : "{$pos}."));
        $username = $usuario['username'] ? "@{$usuario['username']}" : $usuario['first_name'];
        
        $respuesta .= "{$emoji} {$username}\n";
        $respuesta .= "   📊 {$usuario['total_generaciones']} generaciones\n";
        $respuesta .= "   💰 {$usuario['creditos']} créditos\n\n";
    }
    
    enviarMensaje($chatId, $respuesta);
}

/**
 * Pagos pendientes
 */
function comandoPagosPendientesAdmin($chatId, $db) {
    $pagos = $db->getPagosPendientes(10);
    
    if (empty($pagos)) {
        enviarMensaje($chatId, "✅ No hay pagos pendientes");
        return;
    }
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║  💸 PAGOS PENDIENTES      ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    
    foreach ($pagos as $pago) {
        $username = $pago['username'] ? "@{$pago['username']}" : $pago['first_name'];
        $fecha = date('d/m H:i', strtotime($pago['fecha_solicitud']));
        
        $respuesta .= "🆔 ID: *#{$pago['id']}*\n";
        $respuesta .= "👤 {$username}\n";
        $respuesta .= "📦 {$pago['paquete']}\n";
        $respuesta .= "💰 {$pago['creditos']} créditos\n";
        $respuesta .= "💵 {$pago['monto']} {$pago['moneda']}\n";
        $respuesta .= "📅 {$fecha}\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    }
    
    $respuesta .= "📝 *COMANDOS:*\n";
    $respuesta .= "`/detalle [ID]` - Ver detalles\n";
    $respuesta .= "`/aprobar [ID]` - Aprobar pago\n";
    $respuesta .= "`/rechazar [ID] motivo` - Rechazar";
    
    enviarMensaje($chatId, $respuesta);
}

/**
 * Agregar créditos a usuario
 */
function comandoAgregarCreditos($chatId, $texto, $adminId, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) != 3) {
        enviarMensaje($chatId, "❌ Formato incorrecto\n\nUso: `/addcredits [USER_ID] [CANTIDAD]`\n\nEjemplo: `/addcredits 123456789 50`");
        return;
    }
    
    $targetUserId = (int)$partes[1];
    $cantidad = (int)$partes[2];
    
    if ($cantidad <= 0) {
        enviarMensaje($chatId, "❌ La cantidad debe ser mayor a 0");
        return;
    }
    
    $usuario = $db->getUsuario($targetUserId);
    if (!$usuario) {
        enviarMensaje($chatId, "❌ Usuario no encontrado");
        return;
    }
    
    if ($db->actualizarCreditos($targetUserId, $cantidad, 'add')) {
        $db->registrarTransaccion($targetUserId, 'admin_add', $cantidad, "Créditos agregados por admin", $adminId);
        
        $nuevoSaldo = $usuario['creditos'] + $cantidad;
        enviarMensaje($chatId, "✅ Agregados *{$cantidad} créditos* a {$usuario['first_name']}\n\nNuevo saldo: *{$nuevoSaldo}*");
        
        // Notificar al usuario
        enviarMensaje($targetUserId, "🎉 *¡HAS RECIBIDO CRÉDITOS!*\n\n💎 Cantidad: *{$cantidad}*\n💰 Nuevo saldo: *{$nuevoSaldo}*");
    } else {
        enviarMensaje($chatId, "❌ Error al agregar créditos");
    }
}

/**
 * Bloquear usuario
 */
function comandoBloquearUsuario($chatId, $texto, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) != 2) {
        enviarMensaje($chatId, "❌ Formato incorrecto\n\nUso: `/bloquear [USER_ID]`\n\nEjemplo: `/bloquear 123456789`");
        return;
    }
    
    $targetUserId = (int)$partes[1];
    
    $usuario = $db->getUsuario($targetUserId);
    if (!$usuario) {
        enviarMensaje($chatId, "❌ Usuario no encontrado");
        return;
    }
    
    if ($db->bloquearUsuario($targetUserId, true)) {
        $respuesta = "✅ *USUARIO BLOQUEADO*\n\n";
        $respuesta .= "👤 Usuario: {$usuario['first_name']}\n";
        $respuesta .= "🆔 ID: `{$targetUserId}`\n";
        $respuesta .= "🚫 Estado: Bloqueado\n\n";
        $respuesta .= "El usuario ya no podrá usar el bot";
        
        enviarMensaje($chatId, $respuesta);
        
        // Notificar al usuario
        enviarMensaje($targetUserId, "🚫 *TU CUENTA HA SIDO SUSPENDIDA*\n\nContacta al administrador para más información.");
    } else {
        enviarMensaje($chatId, "❌ Error al bloquear usuario");
    }
}

/**
 * Desbloquear usuario
 */
function comandoDesbloquearUsuario($chatId, $texto, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) != 2) {
        enviarMensaje($chatId, "❌ Formato incorrecto\n\nUso: `/desbloquear [USER_ID]`\n\nEjemplo: `/desbloquear 123456789`");
        return;
    }
    
    $targetUserId = (int)$partes[1];
    
    $usuario = $db->getUsuario($targetUserId);
    if (!$usuario) {
        enviarMensaje($chatId, "❌ Usuario no encontrado");
        return;
    }
    
    if ($db->bloquearUsuario($targetUserId, false)) {
        $respuesta = "✅ *USUARIO DESBLOQUEADO*\n\n";
        $respuesta .= "👤 Usuario: {$usuario['first_name']}\n";
        $respuesta .= "🆔 ID: `{$targetUserId}`\n";
        $respuesta .= "✅ Estado: Activo\n\n";
        $respuesta .= "El usuario puede usar el bot nuevamente";
        
        enviarMensaje($chatId, $respuesta);
        
        // Notificar al usuario
        enviarMensaje($targetUserId, "✅ *TU CUENTA HA SIDO REACTIVADA*\n\n¡Ya puedes usar el bot nuevamente! Usa /start");
    } else {
        enviarMensaje($chatId, "❌ Error al desbloquear usuario");
    }
}

/**
 * Hacer premium
 */
function comandoHacerPremium($chatId, $texto, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) != 2) {
        enviarMensaje($chatId, "❌ Formato incorrecto\n\nUso: `/premium [USER_ID]`\n\nEjemplo: `/premium 123456789`");
        return;
    }
    
    $targetUserId = (int)$partes[1];
    
    $usuario = $db->getUsuario($targetUserId);
    if (!$usuario) {
        enviarMensaje($chatId, "❌ Usuario no encontrado");
        return;
    }
    
    if ($db->setPremium($targetUserId, true)) {
        $respuesta = "✅ *USUARIO PREMIUM ACTIVADO*\n\n";
        $respuesta .= "👤 Usuario: {$usuario['first_name']}\n";
        $respuesta .= "🆔 ID: `{$targetUserId}`\n";
        $respuesta .= "⭐ Estado: Premium\n\n";
        $respuesta .= "✨ Beneficios:\n";
        $respuesta .= "• Créditos ilimitados\n";
        $respuesta .= "• Sin costos por generación";
        
        enviarMensaje($chatId, $respuesta);
        
        // Notificar al usuario
        $notif = "⭐ *¡FELICIDADES!*\n\n";
        $notif .= "Has sido promovido a *USUARIO PREMIUM*\n\n";
        $notif .= "✨ *BENEFICIOS:*\n";
        $notif .= "• 💎 Créditos ilimitados\n";
        $notif .= "• 🆓 Generaciones gratis\n";
        $notif .= "• 🚀 Acceso prioritario\n\n";
        $notif .= "¡Disfruta del servicio premium!";
        
        enviarMensaje($targetUserId, $notif);
    } else {
        enviarMensaje($chatId, "❌ Error al activar premium");
    }
}

/**
 * Quitar premium
 */
function comandoQuitarPremium($chatId, $texto, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) != 2) {
        enviarMensaje($chatId, "❌ Formato incorrecto\n\nUso: `/nopremium [USER_ID]`\n\nEjemplo: `/nopremium 123456789`");
        return;
    }
    
    $targetUserId = (int)$partes[1];
    
    $usuario = $db->getUsuario($targetUserId);
    if (!$usuario) {
        enviarMensaje($chatId, "❌ Usuario no encontrado");
        return;
    }
    
    if ($db->setPremium($targetUserId, false)) {
        $respuesta = "✅ *PREMIUM DESACTIVADO*\n\n";
        $respuesta .= "👤 Usuario: {$usuario['first_name']}\n";
        $respuesta .= "🆔 ID: `{$targetUserId}`\n";
        $respuesta .= "👥 Estado: Estándar\n\n";
        $respuesta .= "El usuario volverá a usar créditos normalmente";
        
        enviarMensaje($chatId, $respuesta);
        
        // Notificar al usuario
        enviarMensaje($targetUserId, "ℹ️ Tu cuenta Premium ha expirado.\n\nAhora usas el sistema de créditos normal.\n💰 Saldo actual: *{$usuario['creditos']}*");
    } else {
        enviarMensaje($chatId, "❌ Error al quitar premium");
    }
}

/**
 * Gestionar modelos
 */
function comandoGestionarModelos($chatId, $db) {
    try {
        $conn = $db->getConnection();
        $stmt = $conn->query("SELECT COUNT(*) as total FROM tac_modelos");
        $result = $stmt->fetch();
        $totalModelos = $result['total'] ?? 0;
        
        $stmt = $conn->query("SELECT * FROM tac_modelos ORDER BY veces_usado DESC LIMIT 10");
        $topModelos = $stmt->fetchAll();
        
        $respuesta = "╔═══════════════════════════╗\n";
        $respuesta .= "║  📱 GESTIÓN DE MODELOS    ║\n";
        $respuesta .= "╚═══════════════════════════╝\n\n";
        $respuesta .= "📊 Total en base de datos: *{$totalModelos}*\n\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $respuesta .= "🔝 *TOP 10 MÁS USADOS:*\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        if (!empty($topModelos)) {
            foreach ($topModelos as $i => $modelo) {
                $pos = $i + 1;
                $emoji = $pos == 1 ? "🥇" : ($pos == 2 ? "🥈" : ($pos == 3 ? "🥉" : "{$pos}."));
                
                $marca = $modelo['marca'] ? $modelo['marca'] : 'N/A';
                $fuente = $modelo['fuente'] ?? 'local';
                $iconoFuente = ($fuente == 'api' || $fuente == 'imeidb_api') ? '🌐' : '💾';
                
                $respuesta .= "{$emoji} *{$modelo['modelo']}*\n";
                $respuesta .= "   🔢 TAC: `{$modelo['tac']}`\n";
                $respuesta .= "   🏭 Marca: {$marca}\n";
                $respuesta .= "   {$iconoFuente} Fuente: {$fuente}\n";
                $respuesta .= "   📊 Usado: {$modelo['veces_usado']} veces\n\n";
            }
        } else {
            $respuesta .= "📭 No hay modelos registrados\n\n";
        }
        
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $respuesta .= "📝 *COMANDOS:*\n";
        $respuesta .= "`/delmodelo [TAC]` - Eliminar modelo";
        
        enviarMensaje($chatId, $respuesta);
        
    } catch (Exception $e) {
        enviarMensaje($chatId, "❌ Error al obtener modelos");
        logSecure("Error en comandoGestionarModelos: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * Eliminar modelo
 */
function comandoEliminarModelo($chatId, $texto, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) != 2) {
        enviarMensaje($chatId, "❌ Formato incorrecto\n\nUso: `/delmodelo [TAC]`\n\nEjemplo: `/delmodelo 35203310`");
        return;
    }
    
    $tac = $partes[1];
    
    $modelo = $db->buscarModelo($tac);
    
    if (!$modelo) {
        enviarMensaje($chatId, "❌ Modelo no encontrado con ese TAC");
        return;
    }
    
    if ($db->eliminarModelo($tac)) {
        $respuesta = "✅ *MODELO ELIMINADO*\n\n";
        $respuesta .= "🔢 TAC: `{$tac}`\n";
        $respuesta .= "📱 Modelo: {$modelo['modelo']}\n";
        $respuesta .= "🗑️ Eliminado de la base de datos";
        
        enviarMensaje($chatId, $respuesta);
    } else {
        enviarMensaje($chatId, "❌ Error al eliminar modelo");
    }
}

/**
 * Stats API - ✅ CORREGIDO
 */
function comandoStatsAPI($chatId, $db) {
    try {
        $conn = $db->getConnection();
        
        // Contar modelos por fuente
        $stmt = $conn->query("
            SELECT fuente, COUNT(*) as total 
            FROM tac_modelos 
            GROUP BY fuente
        ");
        $fuentesData = $stmt->fetchAll();
        
        // Total de consultas a la API
        $stmt = $conn->query("
            SELECT SUM(veces_usado) as total_consultas
            FROM tac_modelos 
            WHERE fuente IN ('api', 'imeidb_api')
        ");
        $consultasAPI = $stmt->fetch();
        $totalConsultasAPI = $consultasAPI['total_consultas'] ?? 0;
        
        // ✅ CORREGIDO: Usar ultima_consulta en lugar de fecha_agregado
        $stmt = $conn->query("
            SELECT COUNT(*) as nuevos
            FROM tac_modelos 
            WHERE ultima_consulta >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $nuevos24h = $stmt->fetch();
        $modelosNuevos = $nuevos24h['nuevos'] ?? 0;
        
        $respuesta = "╔═══════════════════════════╗\n";
        $respuesta .= "║   📡 ESTADÍSTICAS API     ║\n";
        $respuesta .= "╚═══════════════════════════╝\n\n";
        
        $respuesta .= "🌐 *API IMEIDB.XYZ*\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $respuesta .= "🔑 Estado: " . (defined('IMEIDB_API_KEY') && IMEIDB_API_KEY ? "✅ Configurada" : "❌ Sin configurar") . "\n";
        $respuesta .= "📊 Consultas totales: *{$totalConsultasAPI}*\n";
        $respuesta .= "📅 Actualizados (24h): *{$modelosNuevos}*\n\n";
        
        $respuesta .= "📚 *MODELOS POR FUENTE:*\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        if (!empty($fuentesData)) {
            foreach ($fuentesData as $fuente) {
                $nombreFuente = $fuente['fuente'];
                $total = $fuente['total'];
                $icono = ($nombreFuente == 'api' || $nombreFuente == 'imeidb_api') ? '🌐' : '💾';
                
                $respuesta .= "{$icono} {$nombreFuente}: *{$total}*\n";
            }
        } else {
            $respuesta .= "📭 Sin datos\n";
        }
        
        $respuesta .= "\n━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $respuesta .= "⚙️ *CONFIGURACIÓN:*\n";
        if (defined('IMEIDB_CACHE_TIME')) {
            $respuesta .= "⏱️ Cache: " . (IMEIDB_CACHE_TIME / 86400) . " días\n";
        }
        if (defined('IMEIDB_TIMEOUT')) {
            $respuesta .= "⏰ Timeout: " . IMEIDB_TIMEOUT . "s\n";
        }
        
        enviarMensaje($chatId, $respuesta);
        
    } catch (Exception $e) {
        enviarMensaje($chatId, "❌ Error al obtener estadísticas");
        logSecure("Error en comandoStatsAPI: " . $e->getMessage(), 'ERROR');
    }
}

// ============================================
// PROCESAMIENTO DE ACTUALIZACIONES
// ============================================

/**
 * Procesar actualización de Telegram
 */
function procesarActualizacion($update, $db, $estados, $sistemaPagos) {
    // Procesar callback queries (botones inline)
    if (isset($update['callback_query'])) {
        $callbackQuery = $update['callback_query'];
        
        // VALIDACIÓN CRÍTICA: Verificar que 'message' existe
        if (!isset($callbackQuery['message'])) {
            logSecure("Callback query sin mensaje - callback antiguo o inválido", 'WARN');
            if (isset($callbackQuery['id'])) {
                answerCallbackQuery($callbackQuery['id'], 'Acción no disponible', true);
            }
            return;
        }
        
        $chatId = (int)$callbackQuery['message']['chat']['id'];
        $telegramId = (int)$callbackQuery['from']['id'];
        $data = $callbackQuery['data'];
        $callbackQueryId = $callbackQuery['id'];
        
        // Responder al callback
        answerCallbackQuery($callbackQueryId);
        
        // Procesar según el tipo de callback
        if (strpos($data, 'paquete_') === 0) {
            $paqueteId = str_replace('paquete_', '', $data);
            procesarSeleccionPaquete($chatId, $telegramId, $paqueteId, $db, $sistemaPagos, $estados);
        }
        elseif (strpos($data, 'metodo_') === 0) {
            $partes = explode('_', $data);
            if (count($partes) >= 3) {
                $metodo = $partes[1];
                $moneda = $partes[2];
                procesarSeleccionMetodoPago($chatId, $telegramId, $metodo, $moneda, $db, $sistemaPagos, $estados);
            }
        }
        elseif ($data === 'comprar_creditos') {
            comandoComprarCreditosMejorado($chatId, $telegramId, $db, $sistemaPagos, $estados);
        }
        
        return;
    }
    
    if (!isset($update['message'])) {
        return;
    }
    
    $message = $update['message'];
    $chatId = (int)$message['chat']['id'];
    $telegramId = (int)$message['from']['id'];
    
    // Verificar si es una foto (captura de pago)
    if (isset($message['photo']) && !empty($message['photo'])) {
        if (procesarCapturaPago($chatId, $telegramId, $message, $db, $sistemaPagos, $estados)) {
            return; // Captura procesada
        }
    }
    
    $texto = isset($message['text']) ? trim($message['text']) : '';
    
    if (empty($texto)) {
        return;
    }
    
    $usuario = $db->getUsuario($telegramId);
    $esAdminUser = esAdmin($telegramId);
    
    // Comandos principales
    if ($texto == '/start') {
        $estados->limpiarEstado($chatId);
        comandoStart($chatId, $message, $db);
    }
    elseif ($texto == '💳 Mis Créditos') {
        comandoMisCreditos($chatId, $telegramId, $db);
    }
    elseif ($texto == '📊 Mi Perfil') {
        comandoPerfil($chatId, $telegramId, $db);
    }
    elseif ($texto == '📜 Historial') {
        comandoHistorial($chatId, $telegramId, $db);
    }
    elseif ($texto == '💰 Comprar Créditos') {
        comandoComprarCreditosMejorado($chatId, $telegramId, $db, $sistemaPagos, $estados);
    }
    elseif ($texto == '❓ Ayuda') {
        comandoAyuda($chatId);
    }
    elseif (strpos($texto, '/info') === 0) {
        comandoInfo($chatId, $texto, $db);
    }
    elseif ($texto == '📱 Generar IMEI') {
        $estados->setEstado($chatId, 'esperando_tac');
        $respuesta = "╔═══════════════════════════╗\n";
        $respuesta .= "║  📱 GENERAR IMEI          ║\n";
        $respuesta .= "╚═══════════════════════════╝\n\n";
        $respuesta .= "Envía un *TAC de 8 dígitos*\n\n";
        $respuesta .= "📝 *EJEMPLOS:*\n";
        $respuesta .= "• `35203310` → iPhone 13 Pro\n";
        $respuesta .= "• `35289311` → Samsung Galaxy\n";
        $respuesta .= "• `35665810` → Xiaomi\n\n";
        $respuesta .= "💳 Costo: *" . COSTO_GENERACION . "* crédito\n";
        $respuesta .= "📊 Generarás: *2 IMEIs* válidos";
        enviarMensaje($chatId, $respuesta);
    }
    // Comandos de administración
    elseif ($texto == '👑 Panel Admin' && $esAdminUser) {
        enviarMensaje($chatId, "👑 *PANEL DE ADMINISTRACIÓN*\n\nSelecciona una opción:", 'Markdown', getTecladoAdmin());
    }
    elseif ($texto == '🔙 Volver al Menú') {
        $estados->limpiarEstado($chatId);
        enviarMensaje($chatId, "🏠 Menú principal", 'Markdown', getTecladoPrincipal($esAdminUser));
    }
    elseif ($texto == '📊 Estadísticas' && $esAdminUser) {
        comandoEstadisticasAdmin($chatId, $db);
    }

elseif ($texto == '🚨 Ver Fraudes' && $esAdminUser) {

    try {
        $conn = $db->getConnection();

        // Verificar si la vista existe
        $check = $conn->prepare("
            SELECT COUNT(*) 
            FROM information_schema.views
            WHERE table_schema = DATABASE()
            AND table_name = 'vista_intentos_fraude'
        ");
        $check->execute();

        if ($check->fetchColumn() == 0) {
            // Crear vista automáticamente
            $conn->exec("
                CREATE VIEW vista_intentos_fraude AS
                SELECT 
                    cd.telegram_id,
                    u.username,
                    COUNT(*) AS total_intentos,
                    MAX(cd.fecha) AS ultimo_intento
                FROM capturas_duplicadas cd
                LEFT JOIN usuarios u ON cd.telegram_id = u.telegram_id
                GROUP BY cd.telegram_id, u.username
            ");
        }

        $stmt = $conn->query("
            SELECT * 
            FROM vista_intentos_fraude
            ORDER BY ultimo_intento DESC
            LIMIT 20
        ");

        $fraudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$fraudes) {
            enviarMensaje($chatId, "✅ No hay intentos de fraude");
        }

        $msg = "🚨 *INTENTOS DE FRAUDE DETECTADOS*\n\n";

        foreach ($fraudes as $f) {
            $msg .= "👤 Usuario: `{$f['telegram_id']}`\n";
            if (!empty($f['username'])) {
                $msg .= "🔖 @{$f['username']}\n";
            }
            $msg .= "⚠️ Intentos: *{$f['total_intentos']}*\n";
            $msg .= "🕒 Último intento: {$f['ultimo_intento']}\n";
            $msg .= "──────────────\n";
        }

        enviarMensaje($chatId, $msg);

    } catch (Throwable $e) {

        enviarMensaje(
            $chatId,
            "❌ Error al obtener fraudes\n\n" .
            "📛 " . $e->getMessage()
        );
    }
}



    elseif ($texto == '👥 Top Usuarios' && $esAdminUser) {
        comandoTopUsuarios($chatId, $db);
    }
    elseif ($texto == '💸 Pagos Pendientes' && $esAdminUser) {
        comandoPagosPendientesAdmin($chatId, $db);
    }
    elseif ($texto == '➕ Agregar Créditos' && $esAdminUser) {
        $estados->setEstado($chatId, 'esperando_addcredits');
        $respuesta = "➕ *AGREGAR CRÉDITOS*\n\n";
        $respuesta .= "Envía el comando en este formato:\n";
        $respuesta .= "`/addcredits [USER_ID] [CANTIDAD]`\n\n";
        $respuesta .= "📝 *EJEMPLO:*\n";
        $respuesta .= "`/addcredits 123456789 50`\n\n";
        $respuesta .= "💡 Esto agregará 50 créditos al usuario 123456789";
        enviarMensaje($chatId, $respuesta);
    }
    elseif ($texto == '🚫 Bloquear Usuario' && $esAdminUser) {
        $estados->setEstado($chatId, 'esperando_bloquear');
        $respuesta = "🚫 *BLOQUEAR USUARIO*\n\n";
        $respuesta .= "Envía el comando en este formato:\n";
        $respuesta .= "`/bloquear [USER_ID]` - Bloquear\n";
        $respuesta .= "`/desbloquear [USER_ID]` - Desbloquear\n\n";
        $respuesta .= "📝 *EJEMPLO:*\n";
        $respuesta .= "`/bloquear 123456789`\n\n";
        $respuesta .= "⚠️ El usuario bloqueado no podrá usar el bot";
        enviarMensaje($chatId, $respuesta);
    }
    elseif ($texto == '⭐ Hacer Premium' && $esAdminUser) {
        $estados->setEstado($chatId, 'esperando_premium');
        $respuesta = "⭐ *GESTIÓN PREMIUM*\n\n";
        $respuesta .= "Envía el comando en este formato:\n";
        $respuesta .= "`/premium [USER_ID]` - Activar Premium\n";
        $respuesta .= "`/nopremium [USER_ID]` - Quitar Premium\n\n";
        $respuesta .= "📝 *EJEMPLO:*\n";
        $respuesta .= "`/premium 123456789`\n\n";
        $respuesta .= "✨ Beneficios Premium:\n";
        $respuesta .= "• Créditos ilimitados\n";
        $respuesta .= "• Sin costos por generación\n";
        $respuesta .= "• Acceso prioritario";
        enviarMensaje($chatId, $respuesta);
    }
    elseif ($texto == '📱 Gestionar Modelos' && $esAdminUser) {
        comandoGestionarModelos($chatId, $db);
    }
    elseif ($texto == '📡 Stats API' && $esAdminUser) {
        comandoStatsAPI($chatId, $db);
    }
    elseif (strpos($texto, '/addcredits') === 0 && $esAdminUser) {
        comandoAgregarCreditos($chatId, $texto, $telegramId, $db);
    }
    elseif (strpos($texto, '/bloquear') === 0 && $esAdminUser) {
        comandoBloquearUsuario($chatId, $texto, $db);
    }
    elseif (strpos($texto, '/desbloquear') === 0 && $esAdminUser) {
        comandoDesbloquearUsuario($chatId, $texto, $db);
    }
    elseif (strpos($texto, '/premium') === 0 && $esAdminUser) {
        comandoHacerPremium($chatId, $texto, $db);
    }
    elseif (strpos($texto, '/nopremium') === 0 && $esAdminUser) {
        comandoQuitarPremium($chatId, $texto, $db);
    }
    elseif (strpos($texto, '/delmodelo') === 0 && $esAdminUser) {
        comandoEliminarModelo($chatId, $texto, $db);
    }
    elseif (strpos($texto, '/detalle') === 0 && $esAdminUser) {
        $partes = explode(' ', $texto);
        if (isset($partes[1])) {
            comandoDetallePago($chatId, (int)$partes[1], $db, $sistemaPagos);
        }
    }
    elseif (strpos($texto, '/aprobar') === 0 && $esAdminUser) {
        comandoAprobarPagoMejorado($chatId, $texto, $telegramId, $db, $sistemaPagos);
    }
    elseif (strpos($texto, '/rechazar') === 0 && $esAdminUser) {
        comandoRechazarPagoMejorado($chatId, $texto, $telegramId, $db, $sistemaPagos);
    }
    // Procesamiento de texto genérico (TAC)
    elseif (!empty($texto) && $texto[0] != '/') {
        procesarTAC($chatId, $texto, $telegramId, $db, $estados);
    }
}

// ============================================
// MODOS DE EJECUCIÓN
// ============================================

/**
 * Modo Webhook
 */
function modoWebhook($db, $estados, $sistemaPagos) {
    $content = file_get_contents("php://input");
    
    if (empty($content)) {
        logSecure("Webhook recibido sin contenido", 'WARN');
        http_response_code(200);
        exit;
    }
    
    $update = json_decode($content, true);
    
    if ($update) {
        try {
            procesarActualizacion($update, $db, $estados, $sistemaPagos);
        } catch (Exception $e) {
            logSecure("Error al procesar actualización: " . $e->getMessage(), 'ERROR');
        }
    }
    
    http_response_code(200);
}

/**
 * Modo Polling (para desarrollo)
 */
function modoPolling($db, $estados, $sistemaPagos) {
    $offset = 0;
    
    echo "🤖 Bot iniciado en modo polling\n";
    logSecure("Bot iniciado en modo polling", 'INFO');
    
    while (true) {
        try {
            $url = API_URL . "getUpdates?offset={$offset}&timeout=30";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 35,
                CURLOPT_CONNECTTIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($response === false || $httpCode !== 200) {
                logSecure("Error en polling: HTTP {$httpCode}", 'ERROR');
                sleep(5);
                continue;
            }
            
            $updates = json_decode($response, true);
            
            if (isset($updates['result']) && is_array($updates['result'])) {
                foreach ($updates['result'] as $update) {
                    try {
                        procesarActualizacion($update, $db, $estados, $sistemaPagos);
                        $offset = $update['update_id'] + 1;
                    } catch (Exception $e) {
                        logSecure("Error al procesar update: " . $e->getMessage(), 'ERROR');
                    }
                }
            }
            
            // Limpiar estados expirados cada cierto tiempo
            if (mt_rand(1, 100) == 1) {
                $estados->limpiarExpirados();
            }
            
            usleep(100000); // 0.1 segundos
            
        } catch (Exception $e) {
            logSecure("Error crítico en polling: " . $e->getMessage(), 'ERROR');
            sleep(5);
        }
    }
}

// ============================================
// PUNTO DE ENTRADA
// ============================================

try {
    // Inicializar componentes
    $db = new Database();
    $estados = new EstadosUsuario();
    $sistemaPagos = new SistemaPagos($db, BOT_TOKEN, ADMIN_IDS);
    
    // Determinar modo de ejecución
    if (php_sapi_name() == 'cli') {
        // Modo CLI (línea de comandos)
        if (isset($argv[1]) && $argv[1] == 'polling') {
            modoPolling($db, $estados, $sistemaPagos);
        } else {
            echo "Uso: php bot_imei_corregido.php polling\n";
            exit(1);
        }
    } else {
        // Modo Webhook (servidor web)
        modoWebhook($db, $estados, $sistemaPagos);
    }
    
} catch (Exception $e) {
    logSecure("Error fatal: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    exit(1);
}

?>
