<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * BOT TELEGRAM - GENERADOR DE IMEI CON SISTEMA DE CRÉDITOS
 * VERSIÓN 2.0 - CON SISTEMA DE PAGOS COMPLETO
 * ═══════════════════════════════════════════════════════════════
 */

// ============================================
// CONFIGURACIÓN
// ============================================

require_once(__DIR__ . '/config_bot.php');
require_once(__DIR__ . '/config_imeidb.php');
require_once(__DIR__ . '/imeidb_api.php');
require_once(__DIR__ . '/sistema_pagos.php');
require_once(__DIR__ . '/comandos_pagos.php');

define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// Configuración del sistema de créditos
define('CREDITOS_REGISTRO', 10);
define('COSTO_GENERACION', 1);
define('ADMIN_IDS', [7334970766]);

// ============================================
// CLASE DATABASE MEJORADA
// ============================================

class Database {
    public $conn;
    
    public function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch(PDOException $e) {
            die("Error de conexión: " . $e->getMessage());
        }
    }
    
    public function registrarUsuario($telegramId, $username, $firstName, $lastName) {
        $sql = "INSERT INTO usuarios (telegram_id, username, first_name, last_name, creditos)
                VALUES (:telegram_id, :username, :first_name, :last_name, :creditos)
                ON DUPLICATE KEY UPDATE 
                    username = :username2,
                    first_name = :first_name2,
                    last_name = :last_name2,
                    ultima_actividad = CURRENT_TIMESTAMP";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $creditos = CREDITOS_REGISTRO;
            
            $stmt->execute([
                ':telegram_id' => $telegramId,
                ':username' => $username,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':creditos' => $creditos,
                ':username2' => $username,
                ':first_name2' => $firstName,
                ':last_name2' => $lastName
            ]);
            
            if ($stmt->rowCount() > 0) {
                $this->registrarTransaccion($telegramId, 'registro', $creditos, 'Créditos de bienvenida');
                return true;
            }
            return false;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function getUsuario($telegramId) {
        $sql = "SELECT * FROM usuarios WHERE telegram_id = :telegram_id";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':telegram_id' => $telegramId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function actualizarCreditos($telegramId, $cantidad, $operacion = 'add') {
        if ($operacion == 'add') {
            $sql = "UPDATE usuarios SET creditos = creditos + :cantidad WHERE telegram_id = :telegram_id";
        } else {
            $sql = "UPDATE usuarios SET creditos = creditos - :cantidad WHERE telegram_id = :telegram_id AND creditos >= :cantidad";
        }
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':cantidad' => $cantidad,
                ':telegram_id' => $telegramId
            ]);
            return $stmt->rowCount() > 0;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function incrementarGeneraciones($telegramId) {
        $sql = "UPDATE usuarios SET total_generaciones = total_generaciones + 1 WHERE telegram_id = :telegram_id";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':telegram_id' => $telegramId]);
            return true;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function bloquearUsuario($telegramId, $bloquear = true) {
        $sql = "UPDATE usuarios SET bloqueado = :bloqueado WHERE telegram_id = :telegram_id";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':bloqueado' => $bloquear ? 1 : 0,
                ':telegram_id' => $telegramId
            ]);
            return $stmt->rowCount() > 0;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function setPremium($telegramId, $premium = true) {
        $sql = "UPDATE usuarios SET es_premium = :premium WHERE telegram_id = :telegram_id";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':premium' => $premium ? 1 : 0,
                ':telegram_id' => $telegramId
            ]);
            return $stmt->rowCount() > 0;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function registrarTransaccion($telegramId, $tipo, $cantidad, $descripcion, $adminId = null) {
        $sql = "INSERT INTO transacciones (telegram_id, tipo, cantidad, descripcion, admin_id)
                VALUES (:telegram_id, :tipo, :cantidad, :descripcion, :admin_id)";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':telegram_id' => $telegramId,
                ':tipo' => $tipo,
                ':cantidad' => $cantidad,
                ':descripcion' => $descripcion,
                ':admin_id' => $adminId
            ]);
            return true;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function registrarUso($telegramId, $tac, $modelo) {
        $sql = "INSERT INTO historial_uso (telegram_id, tac, modelo, creditos_usados)
                VALUES (:telegram_id, :tac, :modelo, :creditos_usados)";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':telegram_id' => $telegramId,
                ':tac' => $tac,
                ':modelo' => $modelo,
                ':creditos_usados' => COSTO_GENERACION
            ]);
            return true;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function getHistorialUsuario($telegramId, $limite = 10) {
        $sql = "SELECT * FROM historial_uso 
                WHERE telegram_id = :telegram_id 
                ORDER BY fecha DESC 
                LIMIT :limite";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':telegram_id', $telegramId, PDO::PARAM_INT);
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }
    
    public function guardarModelo($tac, $modelo, $marca = '', $fuente = 'usuario') {
        $sql = "INSERT INTO tac_modelos (tac, modelo, marca, fuente, veces_usado) 
                VALUES (:tac, :modelo, :marca, :fuente, 1)
                ON DUPLICATE KEY UPDATE 
                    modelo = :modelo2,
                    marca = :marca2,
                    veces_usado = veces_usado + 1,
                    ultima_consulta = CURRENT_TIMESTAMP";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':tac' => $tac,
                ':modelo' => $modelo,
                ':marca' => $marca,
                ':fuente' => $fuente,
                ':modelo2' => $modelo,
                ':marca2' => $marca
            ]);
            return true;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function buscarModelo($tac) {
        $sql = "SELECT * FROM tac_modelos WHERE tac = :tac";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':tac' => $tac]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function eliminarModelo($tac) {
        $sql = "DELETE FROM tac_modelos WHERE tac = :tac";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $resultado = $stmt->execute([':tac' => $tac]);
            return $resultado && $stmt->rowCount() > 0;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function getEstadisticasGenerales() {
        $stats = [];
        
        try {
            $stmt = $this->conn->query("SELECT COUNT(*) as total FROM usuarios");
            $stats['total_usuarios'] = $stmt->fetch()['total'];
            
            $stmt = $this->conn->query("SELECT SUM(creditos) as total FROM usuarios");
            $stats['total_creditos'] = $stmt->fetch()['total'];
            
            $stmt = $this->conn->query("SELECT SUM(total_generaciones) as total FROM usuarios");
            $stats['total_generaciones'] = $stmt->fetch()['total'];
            
            $stmt = $this->conn->query("SELECT COUNT(*) as total FROM usuarios WHERE DATE(ultima_actividad) = CURDATE()");
            $stats['usuarios_hoy'] = $stmt->fetch()['total'];
            
            $stmt = $this->conn->query("SELECT COUNT(*) as total FROM pagos_pendientes WHERE estado = 'pendiente'");
            $stats['pagos_pendientes'] = $stmt->fetch()['total'];
            
            $stmt = $this->conn->query("SELECT COUNT(*) as total FROM usuarios WHERE es_premium = 1");
            $stats['usuarios_premium'] = $stmt->fetch()['total'];
            
            return $stats;
        } catch(PDOException $e) {
            return [];
        }
    }
    
    public function getTopUsuarios($limite = 10) {
        $sql = "SELECT telegram_id, username, first_name, creditos, total_generaciones 
                FROM usuarios 
                ORDER BY total_generaciones DESC 
                LIMIT :limite";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }
    
    public function getPagosPendientes($limite = 20) {
        $sql = "SELECT p.*, u.username, u.first_name 
                FROM pagos_pendientes p
                LEFT JOIN usuarios u ON p.telegram_id = u.telegram_id
                WHERE p.estado IN ('pendiente', 'captura_enviada', 'esperando_captura')
                ORDER BY p.fecha_solicitud DESC
                LIMIT :limite";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }
}

// ============================================
// GESTIÓN DE ESTADOS
// ============================================

class EstadosUsuario {
    private $cacheFile = '/tmp/bot_estados.json';
    
    public function setEstado($chatId, $estado, $datos = []) {
        $estados = $this->cargarEstados();
        $estados[$chatId] = [
            'estado' => $estado,
            'datos' => $datos,
            'timestamp' => time()
        ];
        $this->guardarEstados($estados);
    }
    
    public function getEstado($chatId) {
        $estados = $this->cargarEstados();
        
        if (isset($estados[$chatId])) {
            if (time() - $estados[$chatId]['timestamp'] > 600) {
                unset($estados[$chatId]);
                $this->guardarEstados($estados);
                return null;
            }
            return $estados[$chatId];
        }
        return null;
    }
    
    public function limpiarEstado($chatId) {
        $estados = $this->cargarEstados();
        unset($estados[$chatId]);
        $this->guardarEstados($estados);
    }
    
    private function cargarEstados() {
        if (file_exists($this->cacheFile)) {
            $contenido = file_get_contents($this->cacheFile);
            return json_decode($contenido, true) ?: [];
        }
        return [];
    }
    
    private function guardarEstados($estados) {
        file_put_contents($this->cacheFile, json_encode($estados));
    }
}

// ============================================
// FUNCIONES IMEI
// ============================================

function validarIMEI($imei) {
    $imei = preg_replace('/[^0-9]/', '', $imei);
    
    if (strlen($imei) != 15 || !ctype_digit($imei)) {
        return false;
    }
    
    if (preg_match('/^(.)\1{14}$/', $imei)) {
        return false;
    }
    
    $suma = 0;
    
    for ($i = 0; $i < 14; $i++) {
        $digito = intval($imei[$i]);
        
        if ($i % 2 === 1) {
            $digito *= 2;
            if ($digito > 9) {
                $digito -= 9;
            }
        }
        
        $suma += $digito;
    }
    
    $checkCalculado = (10 - ($suma % 10)) % 10;
    $checkReal = intval($imei[14]);
    
    return $checkCalculado === $checkReal;
}

function generarSerial() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

function calcularDigitoVerificador($imei14) {
    $suma = 0;
    
    for ($i = 0; $i < 14; $i++) {
        $digito = intval($imei14[$i]);
        
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

function validarTAC($tac) {
    $tac = preg_replace('/[^0-9]/', '', $tac);
    
    if (strlen($tac) != 8 || !ctype_digit($tac)) {
        return false;
    }
    
    if (preg_match('/^(.)\1{7}$/', $tac)) {
        return false;
    }
    
    return true;
}

function generarIMEI($tac) {
    $serial = generarSerial();
    $imei14 = $tac . $serial;
    $digitoVerificador = calcularDigitoVerificador($imei14);
    $imeiCompleto = $imei14 . $digitoVerificador;
    
    return [
        'imei_completo' => $imeiCompleto,
        'tac' => $tac,
        'serial' => $serial,
        'digito_verificador' => $digitoVerificador
    ];
}

function generarMultiplesIMEIs($tac, $cantidad = 2) {
    $imeis = [];
    for ($i = 0; $i < $cantidad; $i++) {
        $imeis[] = generarIMEI($tac);
    }
    return $imeis;
}

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
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data)
        ]
    ];
    
    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

function answerCallbackQuery($callbackQueryId, $texto = '', $showAlert = false) {
    $url = API_URL . 'answerCallbackQuery';
    $data = [
        'callback_query_id' => $callbackQueryId,
        'text' => $texto,
        'show_alert' => $showAlert
    ];
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data)
        ]
    ];
    
    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

function crearTeclado($botones) {
    return json_encode([
        'keyboard' => $botones,
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ]);
}

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

function getTecladoAdmin() {
    return crearTeclado([
        [['text' => '📊 Estadísticas'], ['text' => '👥 Top Usuarios']],
        [['text' => '💸 Pagos Pendientes'], ['text' => '➕ Agregar Créditos']],
        [['text' => '🚫 Bloquear Usuario'], ['text' => '⭐ Hacer Premium']],
        [['text' => '📱 Gestionar Modelos'], ['text' => '📡 Stats API']],
        [['text' => '🔙 Volver al Menú']]
    ]);
}

function esAdmin($telegramId) {
    return in_array($telegramId, ADMIN_IDS);
}

// ============================================
// COMANDOS DEL BOT
// ============================================

function comandoStart($chatId, $message, $db) {
    $telegramId = $message['from']['id'];
    $username = $message['from']['username'] ?? '';
    $firstName = $message['from']['first_name'] ?? '';
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

function comandoMisCreditos($chatId, $telegramId, $db) {
    $usuario = $db->getUsuario($telegramId);
    
    if (!$usuario) {
        enviarMensaje($chatId, "❌ Usuario no encontrado. Usa /start");
        return;
    }
    
    $creditos = $usuario['creditos'];
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

function comandoPerfil($chatId, $telegramId, $db) {
    $usuario = $db->getUsuario($telegramId);
    
    if (!$usuario) {
        enviarMensaje($chatId, "❌ Usuario no encontrado. Usa /start");
        return;
    }
    
    $statusEmoji = $usuario['es_premium'] ? '⭐' : '👤';
    $statusTexto = $usuario['es_premium'] ? 'Premium' : 'Estándar';
    $bloqueadoEmoji = $usuario['bloqueado'] ? '🚫' : '✅';
    
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

function procesarTAC($chatId, $texto, $telegramId, $db, $estados) {
    $usuario = $db->getUsuario($telegramId);
    
    if (!$usuario) {
        enviarMensaje($chatId, "❌ No estás registrado. Usa /start");
        return;
    }
    
    if ($usuario['bloqueado']) {
        enviarMensaje($chatId, "🚫 Tu cuenta está suspendida");
        return;
    }
    
    $tac = extraerTAC($texto);
    if (!$tac) {
        $tac = preg_replace('/[^0-9]/', '', $texto);
    }
    
    if (!validarTAC($tac)) {
        enviarMensaje($chatId, "❌ TAC inválido\n\nDebe tener 8 dígitos\nEjemplo: `35203310`");
        return;
    }
    
    if ($usuario['creditos'] < COSTO_GENERACION && !$usuario['es_premium']) {
        $respuesta = "⚠️ *SIN CRÉDITOS*\n\n";
        $respuesta .= "💰 Saldo: *{$usuario['creditos']}*\n";
        $respuesta .= "💎 Necesitas: *" . COSTO_GENERACION . "*\n\n";
        $respuesta .= "🛒 → *💰 Comprar Créditos*";
        enviarMensaje($chatId, $respuesta);
        return;
    }
    
    $modeloData = $db->buscarModelo($tac);
    
    if (!$modeloData) {
        $api = new IMEIDbAPI($db, IMEIDB_API_KEY);
        $datosAPI = $api->consultarIMEI($tac);
        
        if ($datosAPI && isset($datosAPI['modelo'])) {
            $modeloData = [
                'tac' => $tac,
                'modelo' => $datosAPI['modelo'],
                'marca' => isset($datosAPI['marca']) ? $datosAPI['marca'] : null,
                'fuente' => 'api'
            ];
        }
    }
    
    $imeis = generarMultiplesIMEIs($tac, 2);
    
    if (!$usuario['es_premium']) {
        $db->actualizarCreditos($telegramId, COSTO_GENERACION, 'subtract');
        $db->registrarTransaccion($telegramId, 'uso', COSTO_GENERACION, "Generación de IMEIs - TAC: {$tac}");
    }
    
    $db->incrementarGeneraciones($telegramId);
    
    $nombreModelo = $modeloData ? $modeloData['modelo'] : 'Desconocido';
    $db->registrarUso($telegramId, $tac, $nombreModelo);
    
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
    
    $usuario = $db->getUsuario($telegramId);
    if (!$usuario['es_premium']) {
        $respuesta .= "💰 Restantes: *{$usuario['creditos']}*";
    }
    
    enviarMensaje($chatId, $respuesta);
}

// ============================================
// COMANDOS DE ADMINISTRACIÓN
// ============================================

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
    $respuesta .= "`/rechazar [ID]` - Rechazar";
    
    enviarMensaje($chatId, $respuesta);
}

function comandoAgregarCreditos($chatId, $texto, $adminId, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) != 3) {
        enviarMensaje($chatId, "❌ Formato: `/addcredits [USER_ID] [CANTIDAD]`");
        return;
    }
    
    $targetUserId = intval($partes[1]);
    $cantidad = intval($partes[2]);
    
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
        enviarMensaje($chatId, "❌ Error");
    }
}

// ============================================
// PROCESAMIENTO DE ACTUALIZACIONES
// ============================================

function procesarActualizacion($update, $db, $estados, $sistemaPagos) {
    // Procesar callback queries (botones inline)
    if (isset($update['callback_query'])) {
        $callbackQuery = $update['callback_query'];
        $chatId = $callbackQuery['message']['chat']['id'];
        $telegramId = $callbackQuery['from']['id'];
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
            $metodo = $partes[1];
            $moneda = $partes[2];
            procesarSeleccionMetodoPago($chatId, $telegramId, $metodo, $moneda, $db, $sistemaPagos, $estados);
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
    $chatId = $message['chat']['id'];
    $telegramId = $message['from']['id'];
    
    // Verificar si es una foto (captura de pago)
    if (isset($message['photo'])) {
        if (procesarCapturaPago($chatId, $telegramId, $message, $db, $sistemaPagos, $estados)) {
            return; // Captura procesada
        }
    }
    
    $texto = isset($message['text']) ? trim($message['text']) : '';
    
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
            comandoDetallePago($chatId, intval($partes[1]), $db, $sistemaPagos);
        }
    }
    elseif (strpos($texto, '/aprobar') === 0 && $esAdminUser) {
        comandoAprobarPagoMejorado($chatId, $texto, $telegramId, $db, $sistemaPagos);
    }
    elseif (strpos($texto, '/rechazar') === 0 && $esAdminUser) {
        comandoRechazarPagoMejorado($chatId, $texto, $telegramId, $db, $sistemaPagos);
    }
    elseif (!empty($texto) && $texto[0] != '/') {
        procesarTAC($chatId, $texto, $telegramId, $db, $estados);
    }
}

// ============================================
// MODOS DE EJECUCIÓN
// ============================================

function modoWebhook($db, $estados, $sistemaPagos) {
    $content = file_get_contents("php://input");
    $update = json_decode($content, true);
    
    if ($update) {
        procesarActualizacion($update, $db, $estados, $sistemaPagos);
    }
}

function modoPolling($db, $estados, $sistemaPagos) {
    $offset = 0;
    
    echo "🤖 Bot iniciado en modo polling\n";
    
    while (true) {
        $url = API_URL . "getUpdates?offset=$offset&timeout=30";
        $response = @file_get_contents($url);
        $updates = json_decode($response, true);
        
        if (isset($updates['result'])) {
            foreach ($updates['result'] as $update) {
                procesarActualizacion($update, $db, $estados, $sistemaPagos);
                $offset = $update['update_id'] + 1;
            }
        }
        
        usleep(100000);
    }
}

// ============================================
// PUNTO DE ENTRADA
// ============================================

if (php_sapi_name() == 'cli') {
    if (isset($argv[1]) && $argv[1] == 'polling') {
        $db = new Database();
        $estados = new EstadosUsuario();
        $sistemaPagos = new SistemaPagos($db, BOT_TOKEN, ADMIN_IDS);
        modoPolling($db, $estados, $sistemaPagos);
    } else {
        echo "Uso: php bot_imei_corregido.php polling\n";
    }
} else {
    $db = new Database();
    $estados = new EstadosUsuario();
    $sistemaPagos = new SistemaPagos($db, BOT_TOKEN, ADMIN_IDS);
    modoWebhook($db, $estados, $sistemaPagos);
}
?>
