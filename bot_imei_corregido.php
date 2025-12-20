<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * BOT TELEGRAM - GENERADOR DE IMEI CON SISTEMA DE CRÉDITOS
 * VERSIÓN 2.2 - COMPLETAMENTE CORREGIDA
 * ═══════════════════════════════════════════════════════════════
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
 * Validar TAC
 */
function validarTAC($tac) {
    $tac = preg_replace('/[^0-9]/', '', $tac);
    
    if (strlen($tac) != 8 || !ctype_digit($tac)) {
        return false;
    }
    
    // Rechazar TACs con todos los dígitos iguales
    if (preg_match('/^(.)\1{7}$/', $tac)) {
        return false;
    }
    
    return true;
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
 * Extraer TAC de un IMEI
 */
function extraerTAC($imei) {
    $imei = preg_replace('/[^0-9]/', '', $imei);
    if (strlen($imei) >= 8) {
        return substr($imei, 0, 8);
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
        [['text' => '💸 Pagos Pendientes'], ['text' => '➕ Agregar Créditos']],
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
    $respuesta .= "║  📜 TU HISTORIAL 📜       ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    
    foreach ($historial as $i => $uso) {
        $num = $i + 1;
        $fecha = date('d/m H:i', strtotime($uso['fecha']));
        $modelo = $uso['modelo'] ?: 'Desconocido';
        
        $respuesta .= "🔹 *#{$num}* - {$modelo}\n";
        $respuesta .= "   TAC: `{$uso['tac']}` | {$fecha}\n\n";
    }
    
    enviarMensaje($chatId, $respuesta);
}

/**
 * Comando Ayuda
 */
function comandoAyuda($chatId) {
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║      ❓ AYUDA ❓          ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    $respuesta .= "🎯 *¿CÓMO USAR EL BOT?*\n\n";
    $respuesta .= "1️⃣ Presiona *📱 Generar IMEI*\n";
    $respuesta .= "2️⃣ Envía TAC de 8 dígitos\n";
    $respuesta .= "   Ejemplo: `35203310`\n\n";
    $respuesta .= "💰 *CRÉDITOS*\n";
    $respuesta .= "💎 Costo: *" . COSTO_GENERACION . " crédito*\n";
    $respuesta .= "🎁 Registro: *" . CREDITOS_REGISTRO . " créditos*\n\n";
    $respuesta .= "📞 Soporte: @CHAMOGSM";
    
    enviarMensaje($chatId, $respuesta);
}

/**
 * Comando Info (consultar TAC/IMEI)
 */
function comandoInfo($chatId, $texto, $db) {
    $partes = explode(' ', trim($texto));
    
    if (count($partes) < 2) {
        enviarMensaje($chatId, "❌ Uso: `/info [TAC o IMEI]`\n\nEjemplo: `/info 35203310`");
        return;
    }
    
    $input = preg_replace('/[^0-9]/', '', $partes[1]);
    
    if (strlen($input) < 8) {
        enviarMensaje($chatId, "❌ Debe tener al menos 8 dígitos");
        return;
    }
    
    $tac = substr($input, 0, 8);
    
    enviarMensaje($chatId, "🔍 Consultando...");
    
    $api = new IMEIDbAPI($db, IMEIDB_API_KEY);
    $info = $api->obtenerInformacionFormateada($input);
    
    if ($info === false) {
        $modeloData = $db->buscarModelo($tac);
        
        if ($modeloData) {
            $respuesta = "📱 *INFORMACIÓN*\n\n";
            $respuesta .= "🏷️ Marca: " . ($modeloData['marca'] ?: 'No especificada') . "\n";
            $respuesta .= "📱 Modelo: " . $modeloData['modelo'] . "\n";
            $respuesta .= "🔢 TAC: `{$tac}`";
            enviarMensaje($chatId, $respuesta);
        } else {
            enviarMensaje($chatId, "❌ No se encontró información");
        }
    } else {
        enviarMensaje($chatId, $info);
    }
}

/**
 * Procesar TAC para generar IMEI
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
    
    // Extraer TAC del texto
    $tac = extraerTAC($texto);
    if (!$tac) {
        $tac = preg_replace('/[^0-9]/', '', $texto);
    }
    
    // Validar TAC
    if (!validarTAC($tac)) {
        enviarMensaje($chatId, "❌ TAC inválido\n\nDebe tener 8 dígitos\nEjemplo: `35203310`");
        return;
    }
    
    // Verificar créditos
    if ($usuario['creditos'] < COSTO_GENERACION && !$usuario['es_premium']) {
        $respuesta = "⚠️ *SIN CRÉDITOS*\n\n";
        $respuesta .= "💰 Saldo: *{$usuario['creditos']}*\n";
        $respuesta .= "💎 Necesitas: *" . COSTO_GENERACION . "*\n\n";
        $respuesta .= "🛒 → *💰 Comprar Créditos*";
        enviarMensaje($chatId, $respuesta);
        return;
    }
    
    // Buscar información del modelo
    $modeloData = $db->buscarModelo($tac);
    
    if (!$modeloData) {
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
    
    // Respuesta
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║  ✅ GENERACIÓN EXITOSA    ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    $respuesta .= "📱 Modelo: *{$nombreModelo}*\n\n";
    $respuesta .= "📋 *2 IMEIS GENERADOS*\n\n";
    
    foreach ($imeis as $index => $imei) {
        $numero = $index + 1;
        $respuesta .= "🔹 IMEI {$numero}:\n";
        $respuesta .= "`{$imei['imei_completo']}`\n\n";
    }
    
    // Mostrar créditos restantes
    $usuario = $db->getUsuario($telegramId);
    if (!$usuario['es_premium']) {
        $respuesta .= "💰 Restantes: *{$usuario['creditos']}*";
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
    
    $respuesta = "📊 *ESTADÍSTICAS*\n\n";
    $respuesta .= "👥 Usuarios: {$stats['total_usuarios']}\n";
    $respuesta .= "💰 Créditos: {$stats['total_creditos']}\n";
    $respuesta .= "📱 Generaciones: {$stats['total_generaciones']}\n";
    $respuesta .= "👤 Activos hoy: {$stats['usuarios_hoy']}\n";
    $respuesta .= "⭐ Premium: {$stats['usuarios_premium']}\n";
    $respuesta .= "💸 Pagos pendientes: {$stats['pagos_pendientes']}";
    
    enviarMensaje($chatId, $respuesta);
}

/**
 * Top usuarios
 */
function comandoTopUsuarios($chatId, $db) {
    $top = $db->getTopUsuarios(10);
    
    if (empty($top)) {
        enviarMensaje($chatId, "No hay usuarios");
        return;
    }
    
    $respuesta = "👥 *TOP 10 USUARIOS*\n\n";
    
    foreach ($top as $i => $usuario) {
        $pos = $i + 1;
        $emoji = $pos == 1 ? "🥇" : ($pos == 2 ? "🥈" : ($pos == 3 ? "🥉" : "{$pos}."));
        $username = $usuario['username'] ? "@{$usuario['username']}" : $usuario['first_name'];
        
        $respuesta .= "{$emoji} {$username}\n";
        $respuesta .= "   📊 {$usuario['total_generaciones']} | 💰 {$usuario['creditos']}\n\n";
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
    
    $respuesta = "💸 *PAGOS PENDIENTES*\n\n";
    
    foreach ($pagos as $pago) {
        $username = $pago['username'] ? "@{$pago['username']}" : $pago['first_name'];
        $fecha = date('d/m H:i', strtotime($pago['fecha_solicitud']));
        
        $respuesta .= "ID: #{$pago['id']}\n";
        $respuesta .= "👤 {$username}\n";
        $respuesta .= "📦 {$pago['paquete']}\n";
        $respuesta .= "💰 {$pago['creditos']} créditos\n";
        $respuesta .= "💵 {$pago['monto']} {$pago['moneda']}\n";
        $respuesta .= "📅 {$fecha}\n\n";
    }
    
    $respuesta .= "`/detalle [ID]` - Ver detalles\n";
    $respuesta .= "`/aprobar [ID]` - Aprobar\n";
    $respuesta .= "`/rechazar [ID] motivo` - Rechazar";
    
    enviarMensaje($chatId, $respuesta);
}

/**
 * Agregar créditos a usuario
 */
function comandoAgregarCreditos($chatId, $texto, $adminId, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) != 3) {
        enviarMensaje($chatId, "❌ Formato: `/addcredits [USER_ID] [CANTIDAD]`");
        return;
    }
    
    $targetUserId = (int)$partes[1];
    $cantidad = (int)$partes[2];
    
    if ($cantidad <= 0) {
        enviarMensaje($chatId, "❌ La cantidad debe ser positiva");
        return;
    }
    
    $usuario = $db->getUsuario($targetUserId);
    if (!$usuario) {
        enviarMensaje($chatId, "❌ Usuario no encontrado");
        return;
    }
    
    if ($db->actualizarCreditos($targetUserId, $cantidad, 'add')) {
        $db->registrarTransaccion($targetUserId, 'admin_add', $cantidad, "Créditos por admin", $adminId);
        
        $nuevoSaldo = $usuario['creditos'] + $cantidad;
        enviarMensaje($chatId, "✅ +{$cantidad} créditos a {$usuario['first_name']}\nNuevo saldo: {$nuevoSaldo}");
        
        enviarMensaje($targetUserId, "🎉 Has recibido *{$cantidad} créditos*\nNuevo saldo: {$nuevoSaldo}");
    } else {
        enviarMensaje($chatId, "❌ Error al agregar créditos");
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
        $estados->limpiarEstado($chatId);
        enviarMensaje($chatId, "Envía un TAC de 8 dígitos\n\nEjemplo: `35203310`\n\n💳 Costo: " . COSTO_GENERACION . " crédito");
    }
    // Comandos de administración
    elseif ($texto == '👑 Panel Admin' && $esAdminUser) {
        enviarMensaje($chatId, "👑 *PANEL ADMIN*", 'Markdown', getTecladoAdmin());
    }
    elseif ($texto == '🔙 Volver al Menú' && $esAdminUser) {
        enviarMensaje($chatId, "Menú principal", 'Markdown', getTecladoPrincipal($esAdminUser));
    }
    elseif ($texto == '📊 Estadísticas' && $esAdminUser) {
        comandoEstadisticasAdmin($chatId, $db);
    }
    elseif ($texto == '👥 Top Usuarios' && $esAdminUser) {
        comandoTopUsuarios($chatId, $db);
    }
    elseif ($texto == '💸 Pagos Pendientes' && $esAdminUser) {
        comandoPagosPendientesAdmin($chatId, $db);
    }
    elseif (strpos($texto, '/addcredits') === 0 && $esAdminUser) {
        comandoAgregarCreditos($chatId, $texto, $telegramId, $db);
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
