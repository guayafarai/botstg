<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * BOT TELEGRAM - GENERADOR DE IMEI CON SISTEMA DE CRÉDITOS
 * Y SISTEMA DE PAGOS COMPLETO
 * ═══════════════════════════════════════════════════════════════
 * 
 * CARACTERÍSTICAS:
 * ✓ Sistema de usuarios con créditos
 * ✓ Generación de IMEIs (cuesta 1 crédito)
 * ✓ Registro automático con créditos gratis
 * ✓ Sistema de pagos completo con capturas
 * ✓ Múltiples métodos de pago
 * ✓ Comandos de administración
 * ✓ Historial de uso
 * ✓ Sistema de usuarios premium
 * ✓ Bloqueo de usuarios
 * ✓ Sistema de cupones
 * ✓ Notificaciones automáticas
 * 
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
define('CREDITOS_REGISTRO', 10);          // Créditos al registrarse
define('COSTO_GENERACION', 1);           // Créditos por generar IMEIs
define('ADMIN_IDS', [7334970766]);        // IDs de administradores (CAMBIAR)

// ============================================
// CLASE DATABASE MEJORADA
// ============================================

class Database {
    public $conn;  // Cambiado a público para acceso desde IMEIDbAPI
    
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
    
    // ═══════════════════════════════════════
    // GESTIÓN DE USUARIOS
    // ═══════════════════════════════════════
    
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
            
            // Registrar transacción solo si es nuevo usuario
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
    
    // ═══════════════════════════════════════
    // TRANSACCIONES Y HISTORIAL
    // ═══════════════════════════════════════
    
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
    
    // ═══════════════════════════════════════
    // PAGOS Y RECARGAS
    // ═══════════════════════════════════════
    
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
    
    // ═══════════════════════════════════════
    // TAC Y MODELOS (del bot original)
    // ═══════════════════════════════════════
    
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
    
    // ═══════════════════════════════════════
    // ESTADÍSTICAS
    // ═══════════════════════════════════════
    
    public function getEstadisticasGenerales() {
        $stats = [];
        
        try {
            // Total usuarios
            $stmt = $this->conn->query("SELECT COUNT(*) as total FROM usuarios");
            $stats['total_usuarios'] = $stmt->fetch()['total'];
            
            // Total créditos en circulación
            $stmt = $this->conn->query("SELECT SUM(creditos) as total FROM usuarios");
            $stats['total_creditos'] = $stmt->fetch()['total'];
            
            // Total generaciones
            $stmt = $this->conn->query("SELECT SUM(total_generaciones) as total FROM usuarios");
            $stats['total_generaciones'] = $stmt->fetch()['total'];
            
            // Usuarios activos hoy
            $stmt = $this->conn->query("SELECT COUNT(*) as total FROM usuarios WHERE DATE(ultima_actividad) = CURDATE()");
            $stats['usuarios_hoy'] = $stmt->fetch()['total'];
            
            // Pagos pendientes
            $stmt = $this->conn->query("SELECT COUNT(*) as total FROM pagos_pendientes WHERE estado IN ('pendiente', 'captura_enviada')");
            $stats['pagos_pendientes'] = $stmt->fetch()['total'];
            
            // Usuarios premium
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
            // Limpiar estados viejos (más de 10 minutos)
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
// FUNCIONES IMEI (del bot original)
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
        [['text' => '💸 Panel de Pagos'], ['text' => '➕ Agregar Créditos']],
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
    
    // Registrar o actualizar usuario
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
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $respuesta .= "💡 *EJEMPLOS*\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "• TAC: `35203310`\n";
        $respuesta .= "• IMEI: `352033101234567`\n\n";
        $respuesta .= "✨ Usa el menú para navegar\n";
        $respuesta .= "📞 ¿Dudas? → *❓ Ayuda*";
    } else {
        $statusEmoji = $usuario['es_premium'] ? '⭐' : '👤';
        
        $respuesta = "╔═══════════════════════════╗\n";
        $respuesta .= "║  {$statusEmoji} BIENVENIDO DE VUELTA {$statusEmoji}  ║\n";
        $respuesta .= "╚═══════════════════════════╝\n\n";
        $respuesta .= "👋 Hola *{$firstName}*\n\n";
        $respuesta .= "┏━━━━━━━━━━━━━━━━━━━━━━━┓\n";
        $respuesta .= "┃     💼 TU CUENTA        ┃\n";
        $respuesta .= "┗━━━━━━━━━━━━━━━━━━━━━━━┛\n\n";
        $respuesta .= "💰 Créditos: *{$usuario['creditos']}*\n";
        $respuesta .= "📊 Generaciones: *{$usuario['total_generaciones']}*\n";
        
        if ($usuario['es_premium']) {
            $respuesta .= "⭐ Estado: *Premium*\n";
        }
        
        $respuesta .= "\n━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "🎯 Selecciona una opción del menú\n";
        $respuesta .= "🚀 ¡Genera tus IMEIs!";
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
    
    $respuesta .= "┏━━━━━━━━━━━━━━━━━━━━━━━┓\n";
    $respuesta .= "┃   SALDO DISPONIBLE      ┃\n";
    $respuesta .= "┗━━━━━━━━━━━━━━━━━━━━━━━┛\n\n";
    
    $respuesta .= "💰 *{$creditos}* créditos\n\n";
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "📊 *ESTADÍSTICAS*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $respuesta .= "🔢 Generaciones restantes: *{$creditos}*\n";
    $respuesta .= "📱 Total generados: *{$usuario['total_generaciones']}*\n";
    $respuesta .= "💎 Costo: *" . COSTO_GENERACION . "* crédito\n\n";
    
    if ($creditos < 5) {
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $respuesta .= "⚠️ *¡SALDO BAJO!*\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "🛒 Te recomendamos recargar\n";
        $respuesta .= "💳 → *Comprar Créditos*";
    } else {
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "✨ ¡Saldo suficiente!\n";
        $respuesta .= "🚀 Genera sin problema";
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
    $bloqueadoTexto = $usuario['bloqueado'] ? 'Bloqueado' : 'Activo';
    
    $fechaRegistro = date('d/m/Y', strtotime($usuario['fecha_registro']));
    $ultimaActividad = date('d/m/Y H:i', strtotime($usuario['ultima_actividad']));
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║   {$statusEmoji} TU PERFIL {$statusEmoji}        ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    
    $respuesta .= "👤 *INFORMACIÓN PERSONAL*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $respuesta .= "🆔 ID: `{$usuario['telegram_id']}`\n";
    $respuesta .= "📝 Usuario: " . ($usuario['username'] ? "@{$usuario['username']}" : "Sin usuario") . "\n";
    $respuesta .= "👨 Nombre: {$usuario['first_name']} " . ($usuario['last_name'] ?: '') . "\n\n";
    
    $respuesta .= "💼 *CUENTA Y ESTADO*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $respuesta .= "💰 Créditos: *{$usuario['creditos']}*\n";
    $respuesta .= "📊 Generaciones: *{$usuario['total_generaciones']}*\n";
    $respuesta .= "{$statusEmoji} Tipo: *{$statusTexto}*\n";
    $respuesta .= "{$bloqueadoEmoji} Estado: *{$bloqueadoTexto}*\n\n";
    
    $respuesta .= "📅 *FECHAS*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $respuesta .= "📆 Registro: {$fechaRegistro}\n";
    $respuesta .= "🕐 Actividad: {$ultimaActividad}";
    
    if ($usuario['es_premium']) {
        $respuesta .= "\n\n━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $respuesta .= "⭐ *CUENTA PREMIUM*\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "✨ Beneficios exclusivos\n";
        $respuesta .= "🎁 Acceso prioritario";
    }
    
    enviarMensaje($chatId, $respuesta);
}

function comandoHistorial($chatId, $telegramId, $db) {
    $historial = $db->getHistorialUsuario($telegramId, 10);
    
    if (empty($historial)) {
        $respuesta = "╔═══════════════════════════╗\n";
        $respuesta .= "║     📜 HISTORIAL          ║\n";
        $respuesta .= "╚═══════════════════════════╝\n\n";
        $respuesta .= "📭 *Sin historial aún*\n\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "💡 Genera tu primer IMEI\n";
        $respuesta .= "🎯 → *📱 Generar IMEI*\n";
        $respuesta .= "🚀 ¡Comienza ahora!";
        
        enviarMensaje($chatId, $respuesta);
        return;
    }
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║  📜 TU HISTORIAL 📜       ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    $respuesta .= "📊 *Últimas " . count($historial) . " generaciones*\n\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    foreach ($historial as $i => $uso) {
        $num = $i + 1;
        $fecha = date('d/m H:i', strtotime($uso['fecha']));
        $modelo = $uso['modelo'] ?: 'Desconocido';
        
        $respuesta .= "🔹 *Generación #{$num}*\n";
        $respuesta .= "├ 📱 {$modelo}\n";
        $respuesta .= "├ 📡 TAC: `{$uso['tac']}`\n";
        $respuesta .= "├ 💰 {$uso['creditos_usados']} crédito\n";
        $respuesta .= "└ 🕐 {$fecha}\n\n";
    }
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $respuesta .= "💡 Mostrando últimas 10\n";
    $respuesta .= "🔄 Genera más IMEIs";
    
    enviarMensaje($chatId, $respuesta);
}

function comandoAyuda($chatId) {
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║      ❓ AYUDA ❓          ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    
    $respuesta .= "🎯 *¿CÓMO USAR EL BOT?*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $respuesta .= "1️⃣ *GENERAR IMEI*\n";
    $respuesta .= "   • Presiona *📱 Generar IMEI*\n";
    $respuesta .= "   • Envía TAC de 8 dígitos\n";
    $respuesta .= "   • Ejemplo: `35203310`\n\n";
    
    $respuesta .= "2️⃣ *CON IMEI COMPLETO*\n";
    $respuesta .= "   • Envía IMEI de 15 dígitos\n";
    $respuesta .= "   • Se extrae el TAC\n";
    $respuesta .= "   • Ejemplo: `352033101234567`\n\n";
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "💰 *CRÉDITOS*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $respuesta .= "💎 Costo: *" . COSTO_GENERACION . " crédito*\n";
    $respuesta .= "🎁 Registro: *" . CREDITOS_REGISTRO . " créditos* gratis\n";
    $respuesta .= "🛒 Recarga en el menú\n\n";
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "📱 *¿QUÉ ES UN TAC?*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $respuesta .= "Los primeros 8 dígitos del IMEI\n";
    $respuesta .= "que identifican el modelo.\n\n";
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "🔧 *COMANDOS*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $respuesta .= "• `/start` - Menú principal\n";
    $respuesta .= "• `/info TAC` - Consultar info\n";
    $respuesta .= "• *💳 Mis Créditos* - Saldo\n";
    $respuesta .= "• *📊 Mi Perfil* - Info\n";
    $respuesta .= "• *📜 Historial* - Actividad\n";
    $respuesta .= "• *💰 Comprar* - Recargar\n\n";
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "💬 *SOPORTE*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $respuesta .= "¿Problemas? Contacta:\n";
    $respuesta .= "📞 @CHAMOGSM\n\n";
    
    $respuesta .= "✨ ¡Estamos para ayudarte!";
    
    enviarMensaje($chatId, $respuesta);
}

// ============================================
// CONSULTA DE INFORMACIÓN (API)
// ============================================

function comandoInfo($chatId, $texto, $db) {
    $partes = explode(' ', trim($texto));
    
    if (count($partes) < 2) {
        enviarMensaje($chatId, "❌ *Uso correcto:*\n`/info [TAC o IMEI]`\n\n*Ejemplo:*\n`/info 35203310`");
        return;
    }
    
    $input = preg_replace('/[^0-9]/', '', $partes[1]);
    
    if (strlen($input) < 8) {
        enviarMensaje($chatId, "❌ Debe tener al menos 8 dígitos");
        return;
    }
    
    $tac = substr($input, 0, 8);
    
    enviarMensaje($chatId, "🔍 Consultando información...\n⏳ Por favor espera...");
    
    $api = new IMEIDbAPI($db, IMEIDB_API_KEY);
    $info = $api->obtenerInformacionFormateada($input);
    
    if ($info === false) {
        $modeloData = $db->buscarModelo($tac);
        
        if ($modeloData) {
            $respuesta = "📱 *INFORMACIÓN DEL DISPOSITIVO*\n\n";
            $respuesta .= "🏷️ *Marca:* " . ($modeloData['marca'] ?: 'No especificada') . "\n";
            $respuesta .= "📱 *Modelo:* " . $modeloData['modelo'] . "\n";
            $respuesta .= "🔢 *TAC:* `{$tac}`\n\n";
            $respuesta .= "_Información de base de datos local_";
            enviarMensaje($chatId, $respuesta);
        } else {
            enviarMensaje($chatId, "❌ No se encontró información para este TAC/IMEI\n\nPuedes intentar generar un IMEI con este TAC para agregarlo a la base de datos.");
        }
    } else {
        enviarMensaje($chatId, $info);
    }
}

// ============================================
// GENERACIÓN DE IMEI CON CRÉDITOS
// ============================================

function procesarTAC($chatId, $texto, $telegramId, $db, $estados) {
    $usuario = $db->getUsuario($telegramId);
    
    if (!$usuario) {
        enviarMensaje($chatId, "❌ No estás registrado. Usa /start");
        return;
    }
    
    if ($usuario['bloqueado']) {
        $respuesta = "╔═══════════════════════════╗\n";
        $respuesta .= "║      🚫 BLOQUEADO         ║\n";
        $respuesta .= "╚═══════════════════════════╝\n\n";
        $respuesta .= "⚠️ Tu cuenta está suspendida\n\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "📞 Para más información\n";
        $respuesta .= "contacta al administrador";
        enviarMensaje($chatId, $respuesta);
        return;
    }
    
    $tac = extraerTAC($texto);
    if (!$tac) {
        $tac = preg_replace('/[^0-9]/', '', $texto);
    }
    
    if (!validarTAC($tac)) {
        $respuesta = "╔═══════════════════════════╗\n";
        $respuesta .= "║     ❌ TAC INVÁLIDO       ║\n";
        $respuesta .= "╚═══════════════════════════╝\n\n";
        $respuesta .= "⚠️ El TAC debe tener 8 dígitos\n\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $respuesta .= "💡 *EJEMPLOS CORRECTOS*\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "✅ `35203310` (iPhone 13 Pro)\n";
        $respuesta .= "✅ `35840809` (iPhone 14)\n";
        $respuesta .= "✅ `86885904` (Redmi Note 12)";
        enviarMensaje($chatId, $respuesta);
        return;
    }
    
    if ($usuario['creditos'] < COSTO_GENERACION && !$usuario['es_premium']) {
        $respuesta = "╔═══════════════════════════╗\n";
        $respuesta .= "║   ⚠️ SIN CRÉDITOS ⚠️      ║\n";
        $respuesta .= "╚═══════════════════════════╝\n\n";
        $respuesta .= "💰 *Saldo insuficiente*\n\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "📊 Tu saldo: *{$usuario['creditos']}* crédito" . ($usuario['creditos'] != 1 ? 's' : '') . "\n";
        $respuesta .= "💎 Necesitas: *" . COSTO_GENERACION . "* crédito\n\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "🛒 → *💰 Comprar Créditos*\n";
        $respuesta .= "✨ ¡Recarga y continúa!";
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
    
    $respuesta .= "[CHAMOGSM] → BOT IMEI\n\n";
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "📱 *DISPOSITIVO*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $modeloTexto = $modeloData ? $modeloData['modelo'] : "Desconocido";
    $respuesta .= "📱 Modelo: *{$modeloTexto}*\n";
    
    if (esAdmin($telegramId)) {
        $respuesta .= "📡 TAC: `{$tac}`\n";
    }
    
    $respuesta .= "\n━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "📋 *2 IMEIS GENERADOS*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    foreach ($imeis as $index => $imei) {
        $numero = $index + 1;
        $respuesta .= "🔹 IMEI {$numero}:\n";
        $respuesta .= "`{$imei['imei_completo']}`\n\n";
    }
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    $usuario = $db->getUsuario($telegramId);
    if (!$usuario['es_premium']) {
        $respuesta .= "💰 *CRÉDITOS*\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "💎 Usados: " . COSTO_GENERACION . " crédito\n";
        $respuesta .= "💳 Restantes: *{$usuario['creditos']}*\n";
        
        if ($usuario['creditos'] < 5) {
            $respuesta .= "\n⚠️ *¡Saldo bajo!*\n";
            $respuesta .= "🛒 Considera recargar";
        }
    } else {
        $respuesta .= "⭐ *Usuario Premium*\n";
        $respuesta .= "✨ Sin límite de generaciones";
    }
    
    enviarMensaje($chatId, $respuesta);
    
    if (!$modeloData && esAdmin($telegramId)) {
        $estados->setEstado($chatId, 'puede_agregar_modelo', ['tac' => $tac]);
        enviarMensaje($chatId, "\n👑 *¿Conoces el modelo?*\nComo administrador, puedes agregarlo enviando el modelo.\nEjemplo: _iPhone 13 Pro_");
    }
}

function procesarModelo($chatId, $modelo, $estados, $db, $telegramId) {
    if (!esAdmin($telegramId)) {
        return false;
    }
    
    $estado = $estados->getEstado($chatId);
    
    if (!$estado || $estado['estado'] != 'puede_agregar_modelo') {
        return false;
    }
    
    $tac = $estado['datos']['tac'];
    $modeloLimpio = trim($modelo);
    
    $marca = '';
    $marcasConocidas = ['Apple', 'Samsung', 'Xiaomi', 'Huawei', 'Oppo', 'Vivo', 
                        'OnePlus', 'Motorola', 'Nokia', 'Sony', 'LG', 'Realme', 
                        'Poco', 'Google', 'Asus', 'ZTE', 'Honor', 'Lenovo'];
    
    foreach ($marcasConocidas as $marcaConocida) {
        if (stripos($modeloLimpio, $marcaConocida) !== false) {
            $marca = $marcaConocida;
            break;
        }
    }
    
    if ($db->guardarModelo($tac, $modeloLimpio, $marca, 'admin')) {
        $estados->limpiarEstado($chatId);
        enviarMensaje($chatId, "💾 *¡Modelo guardado!*\n\n📡 TAC: `{$tac}`\n📱 Modelo: {$modeloLimpio}\n" . ($marca ? "🏷️ Marca: {$marca}\n" : "") . "\n✅ Ahora todos los usuarios verán este modelo.");
        return true;
    }
    
    return true;
}

// ============================================
// COMANDOS DE ADMINISTRACIÓN
// ============================================

function comandoEstadisticasAdmin($chatId, $db) {
    $stats = $db->getEstadisticasGenerales();
    
    $respuesta = "📊 *ESTADÍSTICAS GENERALES*\n\n";
    $respuesta .= "👥 *Total usuarios:* {$stats['total_usuarios']}\n";
    $respuesta .= "💰 *Créditos en circulación:* {$stats['total_creditos']}\n";
    $respuesta .= "📱 *Total generaciones:* {$stats['total_generaciones']}\n";
    $respuesta .= "👤 *Usuarios activos hoy:* {$stats['usuarios_hoy']}\n";
    $respuesta .= "⭐ *Usuarios premium:* {$stats['usuarios_premium']}\n";
    $respuesta .= "💸 *Pagos pendientes:* {$stats['pagos_pendientes']}\n\n";
    
    if ($stats['total_usuarios'] > 0) {
        $promedio = round($stats['total_generaciones'] / $stats['total_usuarios'], 2);
        $respuesta .= "📊 *Promedio generaciones/usuario:* {$promedio}";
    }
    
    enviarMensaje($chatId, $respuesta);
}

function comandoTopUsuarios($chatId, $db) {
    $top = $db->getTopUsuarios(10);
    
    if (empty($top)) {
        enviarMensaje($chatId, "No hay usuarios registrados.");
        return;
    }
    
    $respuesta = "👥 *TOP 10 USUARIOS MÁS ACTIVOS*\n\n";
    
    foreach ($top as $i => $usuario) {
        $pos = $i + 1;
        $emoji = $pos == 1 ? "🥇" : ($pos == 2 ? "🥈" : ($pos == 3 ? "🥉" : "{$pos}."));
        $username = $usuario['username'] ? "@{$usuario['username']}" : $usuario['first_name'];
        
        $respuesta .= "{$emoji} *{$username}*\n";
        $respuesta .= "   📊 {$usuario['total_generaciones']} generaciones\n";
        $respuesta .= "   💰 {$usuario['creditos']} créditos\n\n";
    }
    
    enviarMensaje($chatId, $respuesta);
}

function comandoPagosPendientes($chatId, $db) {
    $pagos = $db->getPagosPendientes(10);
    
    if (empty($pagos)) {
        enviarMensaje($chatId, "✅ No hay pagos pendientes.");
        return;
    }
    
    $respuesta = "💸 *PAGOS PENDIENTES*\n\n";
    
    foreach ($pagos as $pago) {
        $username = $pago['username'] ? "@{$pago['username']}" : $pago['first_name'];
        $fecha = date('d/m/Y H:i', strtotime($pago['fecha_solicitud']));
        
        $respuesta .= "ID: #{$pago['id']}\n";
        $respuesta .= "👤 {$username} (`{$pago['telegram_id']}`)\n";
        $respuesta .= "📦 {$pago['paquete']}\n";
        $respuesta .= "💰 {$pago['creditos']} créditos\n";
        $respuesta .= "💵 \$" . $pago['monto'] . " {$pago['moneda']}\n";
        $respuesta .= "📅 {$fecha}\n\n";
    }
    
    $respuesta .= "Para ver detalles: `/detalle [ID]`\n";
    $respuesta .= "Para aprobar: `/aprobar [ID]`\n";
    $respuesta .= "Para rechazar: `/rechazar [ID] [motivo]`";
    
    enviarMensaje($chatId, $respuesta);
}

function comandoAgregarCreditos($chatId, $texto, $adminId, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) != 3) {
        enviarMensaje($chatId, "❌ Formato: `/addcredits [USER_ID] [CANTIDAD]`\n\nEjemplo: `/addcredits 123456789 50`");
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
        $db->registrarTransaccion($targetUserId, 'admin_add', $cantidad, "Créditos agregados por administrador", $adminId);
        
        $nuevoSaldo = $usuario['creditos'] + $cantidad;
        enviarMensaje($chatId, "✅ *Créditos agregados*\n\n👤 Usuario: {$usuario['first_name']}\n💰 Cantidad: +{$cantidad}\n💳 Nuevo saldo: {$nuevoSaldo}");
        
        enviarMensaje($targetUserId, "🎉 *¡Has recibido créditos!*\n\n💰 Se han agregado *{$cantidad} créditos* a tu cuenta\n💳 Nuevo saldo: {$nuevoSaldo} créditos\n\n¡Gracias por usar F4 Mobile IMEI Bot!");
    } else {
        enviarMensaje($chatId, "❌ Error al agregar créditos");
    }
}

function comandoBloquearUsuario($chatId, $texto, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) != 2) {
        enviarMensaje($chatId, "❌ Formato: `/block [USER_ID]`\n\nEjemplo: `/block 123456789`");
        return;
    }
    
    $targetUserId = intval($partes[1]);
    
    if ($db->bloquearUsuario($targetUserId, true)) {
        enviarMensaje($chatId, "✅ Usuario bloqueado exitosamente");
        enviarMensaje($targetUserId, "🚫 Tu cuenta ha sido bloqueada. Contacta al administrador si crees que es un error.");
    } else {
        enviarMensaje($chatId, "❌ Error al bloquear usuario");
    }
}

function comandoDesbloquearUsuario($chatId, $texto, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) != 2) {
        enviarMensaje($chatId, "❌ Formato: `/unblock [USER_ID]`\n\nEjemplo: `/unblock 123456789`");
        return;
    }
    
    $targetUserId = intval($partes[1]);
    
    if ($db->bloquearUsuario($targetUserId, false)) {
        enviarMensaje($chatId, "✅ Usuario desbloqueado exitosamente");
        enviarMensaje($targetUserId, "✅ Tu cuenta ha sido desbloqueada. ¡Bienvenido de nuevo!");
    } else {
        enviarMensaje($chatId, "❌ Error al desbloquear usuario");
    }
}

function comandoHacerPremium($chatId, $texto, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) != 2) {
        enviarMensaje($chatId, "❌ Formato: `/premium [USER_ID]`\n\nEjemplo: `/premium 123456789`");
        return;
    }
    
    $targetUserId = intval($partes[1]);
    
    if ($db->setPremium($targetUserId, true)) {
        enviarMensaje($chatId, "✅ Usuario ahora es PREMIUM");
        enviarMensaje($targetUserId, "⭐ *¡Felicidades!*\n\nAhora eres usuario PREMIUM\n\n✨ Beneficios:\n• Generaciones ilimitadas\n• Sin consumo de créditos\n• Acceso prioritario\n\n¡Disfruta tu membresía!");
    } else {
        enviarMensaje($chatId, "❌ Error al activar premium");
    }
}

function comandoQuitarPremium($chatId, $texto, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) != 2) {
        enviarMensaje($chatId, "❌ Formato: `/unpremium [USER_ID]`\n\nEjemplo: `/unpremium 123456789`");
        return;
    }
    
    $targetUserId = intval($partes[1]);
    
    if ($db->setPremium($targetUserId, false)) {
        enviarMensaje($chatId, "✅ Premium removido");
        enviarMensaje($targetUserId, "Tu membresía premium ha expirado. Puedes comprar créditos en '💰 Comprar Créditos'");
    } else {
        enviarMensaje($chatId, "❌ Error al remover premium");
    }
}

function comandoAgregarModelo($chatId, $texto, $db) {
    $partes = explode(' ', $texto, 3);
    
    if (count($partes) < 3) {
        enviarMensaje($chatId, "❌ Uso: `/agregar_modelo TAC Modelo`\n\nEjemplo: `/agregar_modelo 35203310 iPhone 13 Pro`");
        return;
    }
    
    $tac = preg_replace('/[^0-9]/', '', $partes[1]);
    $modeloLimpio = trim($partes[2]);
    
    if (!validarTAC($tac)) {
        enviarMensaje($chatId, "❌ TAC inválido. Debe tener 8 dígitos.");
        return;
    }
    
    $marca = '';
    $marcasConocidas = ['Apple', 'Samsung', 'Xiaomi', 'Huawei', 'Oppo', 'Vivo', 
                        'OnePlus', 'Motorola', 'Nokia', 'Sony', 'LG', 'Realme', 
                        'Poco', 'Google', 'Asus', 'ZTE', 'Honor', 'Lenovo'];
    
    foreach ($marcasConocidas as $marcaConocida) {
        if (stripos($modeloLimpio, $marcaConocida) !== false) {
            $marca = $marcaConocida;
            break;
        }
    }
    
    if ($db->guardarModelo($tac, $modeloLimpio, $marca, 'admin')) {
        $mensaje = "✅ *Modelo agregado exitosamente*\n\n";
        $mensaje .= "📡 TAC: `{$tac}`\n";
        $mensaje .= "📱 Modelo: {$modeloLimpio}\n";
        $mensaje .= "🏷️ Marca: " . ($marca ?: 'Sin marca') . "\n\n";
        $mensaje .= "Ahora todos los usuarios verán este modelo.";
        
        enviarMensaje($chatId, $mensaje);
    } else {
        enviarMensaje($chatId, "❌ Error al guardar el modelo.");
    }
}

function comandoEditarModelo($chatId, $texto, $db) {
    $partes = explode(' ', $texto, 3);
    
    if (count($partes) < 3) {
        enviarMensaje($chatId, "❌ Uso: `/editar_modelo TAC Nuevo Modelo`\n\nEjemplo: `/editar_modelo 35203310 iPhone 14 Pro Max`");
        return;
    }
    
    $tac = preg_replace('/[^0-9]/', '', $partes[1]);
    $nuevoModelo = trim($partes[2]);
    
    if (!validarTAC($tac)) {
        enviarMensaje($chatId, "❌ TAC inválido. Debe tener 8 dígitos.");
        return;
    }
    
    $marca = '';
    $marcasConocidas = ['Apple', 'Samsung', 'Xiaomi', 'Huawei', 'Oppo', 'Vivo', 
                        'OnePlus', 'Motorola', 'Nokia', 'Sony', 'LG', 'Realme', 
                        'Poco', 'Google', 'Asus', 'ZTE', 'Honor', 'Lenovo'];
    
    foreach ($marcasConocidas as $marcaConocida) {
        if (stripos($nuevoModelo, $marcaConocida) !== false) {
            $marca = $marcaConocida;
            break;
        }
    }
    
    if ($db->guardarModelo($tac, $nuevoModelo, $marca, 'admin')) {
        $mensaje = "✅ *Modelo actualizado exitosamente*\n\n";
        $mensaje .= "📡 TAC: `{$tac}`\n";
        $mensaje .= "📱 Nuevo modelo: {$nuevoModelo}\n";
        $mensaje .= "🏷️ Marca: " . ($marca ?: 'Sin marca');
        
        enviarMensaje($chatId, $mensaje);
    } else {
        enviarMensaje($chatId, "❌ Error al actualizar el modelo.");
    }
}

function comandoEliminarModelo($chatId, $texto, $db) {
    $partes = explode(' ', $texto);
    
    if (count($partes) < 2) {
        enviarMensaje($chatId, "❌ Uso: `/eliminar_modelo TAC`\n\nEjemplo: `/eliminar_modelo 35203310`");
        return;
    }
    
    $tac = preg_replace('/[^0-9]/', '', $partes[1]);
    
    if (!validarTAC($tac)) {
        enviarMensaje($chatId, "❌ TAC inválido. Debe tener 8 dígitos.");
        return;
    }
    
    if ($db->eliminarModelo($tac)) {
        enviarMensaje($chatId, "✅ Modelo con TAC `{$tac}` eliminado exitosamente.");
    } else {
        enviarMensaje($chatId, "❌ No se encontró un modelo con ese TAC.");
    }
}

function comandoEstadisticasAPI($chatId, $db) {
    $api = new IMEIDbAPI($db, IMEIDB_API_KEY);
    $stats = $api->obtenerEstadisticas();
    
    $mensaje = "📊 *ESTADÍSTICAS API IMEIDB*\n\n";
    $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= "📡 Total consultas: *{$stats['total_consultas']}*\n";
    $mensaje .= "🔢 IMEIs únicos: *{$stats['imeis_unicos']}*\n";
    
    if ($stats['ultima_consulta']) {
        $fecha = date('d/m/Y H:i', strtotime($stats['ultima_consulta']));
        $mensaje .= "⏰ Última consulta: {$fecha}\n";
    }
    
    $mensaje .= "\n━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $mensaje .= "💡 *Comandos de limpieza:*\n";
    $mensaje .= "`/limpiar_cache` - Limpia caché antigua";
    
    enviarMensaje($chatId, $mensaje);
}

function comandoLimpiarCache($chatId, $db) {
    $api = new IMEIDbAPI($db, IMEIDB_API_KEY);
    $eliminados = $api->limpiarCacheAntiguo(60);
    
    $mensaje = "🧹 *LIMPIEZA DE CACHÉ*\n\n";
    $mensaje .= "✅ Registros eliminados: *{$eliminados}*\n\n";
    $mensaje .= "_Se eliminaron consultas con más de 60 días de antigüedad_";
    
    enviarMensaje($chatId, $mensaje);
}

// ============================================
// PROCESAMIENTO DE CALLBACKS
// ============================================

function procesarCallback($update, $db, $sistemaPagos, $estados) {
    if (!isset($update['callback_query'])) return;
    
    $callbackQuery = $update['callback_query'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $telegramId = $callbackQuery['from']['id'];
    $data = $callbackQuery['data'];
    
    // Confirmar callback
    $url = API_URL . 'answerCallbackQuery';
    $postData = ['callback_query_id' => $callbackQuery['id']];
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($postData)
        ]
    ]);
    @file_get_contents($url, false, $context);
    
    // Procesar según el tipo de callback
    if (strpos($data, 'paquete_') === 0) {
        $paqueteId = str_replace('paquete_', '', $data);
        procesarSeleccionPaquete($chatId, $telegramId, $paqueteId, $db, $sistemaPagos, $estados);
    }
    elseif (strpos($data, 'metodo_') === 0) {
        $parts = explode('_', $data);
        $metodo = $parts[1];
        $moneda = $parts[2];
        procesarSeleccionMetodoPago($chatId, $telegramId, $metodo, $moneda, $db, $sistemaPagos, $estados);
    }
    elseif ($data === 'comprar_creditos') {
        comandoComprarCreditosMejorado($chatId, $telegramId, $db, $sistemaPagos, $estados);
    }
    elseif ($data === 'ingresar_cupon') {
        $estados->setEstado($chatId, 'ingresando_cupon', []);
        enviarMensaje($chatId, "🎟️ *CUPÓN DE DESCUENTO*\n\nEnvía el código de tu cupón:");
    }
}

// ============================================
// PROCESAMIENTO DE ACTUALIZACIONES
// ============================================

function procesarActualizacion($update, $db, $estados, $sistemaPagos) {
    if (!isset($update['message'])) {
        return;
    }
    
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $telegramId = $message['from']['id'];
    $texto = isset($message['text']) ? trim($message['text']) : '';
    
    $usuario = $db->getUsuario($telegramId);
    $esAdminUser = esAdmin($telegramId);
    
    // Procesar capturas de pago
    if (isset($message['photo'])) {
        if (procesarCapturaPago($chatId, $telegramId, $message, $db, $sistemaPagos, $estados)) {
            return; // Ya se procesó la captura
        }
    }
    
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
        enviarMensaje($chatId, "Envía un TAC de 8 dígitos o IMEI de 15 dígitos.\n\nEjemplo: `35203310`\n\n💳 Costo: " . COSTO_GENERACION . " crédito");
    }
    // Panel de administración
    elseif ($texto == '👑 Panel Admin' && $esAdminUser) {
        enviarMensaje($chatId, "👑 *PANEL DE ADMINISTRACIÓN*\n\nSelecciona una opción:", 'Markdown', getTecladoAdmin());
    }
    elseif ($texto == '🔙 Volver al Menú' && $esAdminUser) {
        enviarMensaje($chatId, "Volviendo al menú principal...", 'Markdown', getTecladoPrincipal($esAdminUser));
    }
    elseif ($texto == '📊 Estadísticas' && $esAdminUser) {
        comandoEstadisticasAdmin($chatId, $db);
    }
    elseif ($texto == '👥 Top Usuarios' && $esAdminUser) {
        comandoTopUsuarios($chatId, $db);
    }
    elseif ($texto == '💸 Panel de Pagos' && $esAdminUser) {
        comandoPanelPagosAdmin($chatId, $db, $sistemaPagos);
    }
    elseif ($texto == '➕ Agregar Créditos' && $esAdminUser) {
        enviarMensaje($chatId, "Para agregar créditos usa:\n`/addcredits [USER_ID] [CANTIDAD]`\n\nEjemplo:\n`/addcredits 123456789 50`");
    }
    elseif ($texto == '🚫 Bloquear Usuario' && $esAdminUser) {
        enviarMensaje($chatId, "Para bloquear un usuario usa:\n`/block [USER_ID]`\n\nPara desbloquear:\n`/unblock [USER_ID]`");
    }
    elseif ($texto == '⭐ Hacer Premium' && $esAdminUser) {
        enviarMensaje($chatId, "Para hacer premium usa:\n`/premium [USER_ID]`\n\nPara quitar premium:\n`/unpremium [USER_ID]`");
    }
    elseif ($texto == '📱 Gestionar Modelos' && $esAdminUser) {
        $mensaje = "📱 *GESTIÓN DE MODELOS*\n\n";
        $mensaje .= "*Comandos disponibles:*\n\n";
        $mensaje .= "➕ *Agregar modelo:*\n";
        $mensaje .= "`/agregar_modelo [TAC] [Modelo]`\n";
        $mensaje .= "Ejemplo: `/agregar_modelo 35203310 iPhone 13 Pro`\n\n";
        $mensaje .= "✏️ *Editar modelo:*\n";
        $mensaje .= "`/editar_modelo [TAC] [Nuevo Modelo]`\n";
        $mensaje .= "Ejemplo: `/editar_modelo 35203310 iPhone 14 Pro`\n\n";
        $mensaje .= "🗑️ *Eliminar modelo:*\n";
        $mensaje .= "`/eliminar_modelo [TAC]`\n";
        $mensaje .= "Ejemplo: `/eliminar_modelo 35203310`\n\n";
        $mensaje .= "💡 También puedes agregar modelos generando un IMEI con TAC desconocido.";
        enviarMensaje($chatId, $mensaje);
    }
    elseif ($texto == '📡 Stats API' && $esAdminUser) {
        comandoEstadisticasAPI($chatId, $db);
    }
    // Comandos de pagos admin
    elseif (strpos($texto, '/pagos_pendientes') === 0 && $esAdminUser) {
        comandoPagosPendientes($chatId, $db);
    }
    elseif (strpos($texto, '/detalle') === 0 && $esAdminUser) {
        $partes = explode(' ', $texto);
        if (isset($partes[1])) {
            $pagoId = intval($partes[1]);
            comandoDetallePago($chatId, $pagoId, $db, $sistemaPagos);
        }
    }
    elseif (strpos($texto, '/aprobar') === 0 && $esAdminUser) {
        comandoAprobarPagoMejorado($chatId, $texto, $telegramId, $db, $sistemaPagos);
    }
    elseif (strpos($texto, '/rechazar') === 0 && $esAdminUser) {
        comandoRechazarPagoMejorado($chatId, $texto, $telegramId, $db, $sistemaPagos);
    }
    elseif (strpos($texto, '/crear_cupon') === 0 && $esAdminUser) {
        comandoCrearCupon($chatId, $texto, $telegramId, $db, $sistemaPagos);
    }
    elseif (strpos($texto, '/reporte_mes') === 0 && $esAdminUser) {
        comandoReporteMensual($chatId, $db, $sistemaPagos);
    }
    // Comandos admin directos
    elseif (strpos($texto, '/addcredits') === 0 && $esAdminUser) {
        comandoAgregarCreditos($chatId, $texto, $telegramId, $db);
    }
    elseif (strpos($texto, '/block') === 0 && $esAdminUser) {
        comandoBloquearUsuario($chatId, $texto, $db);
    }
    elseif (strpos($texto, '/unblock') === 0 && $esAdminUser) {
        comandoDesbloquearUsuario($chatId, $texto, $db);
    }
    elseif (strpos($texto, '/premium') === 0 && $esAdminUser) {
        comandoHacerPremium($chatId, $texto, $db);
    }
    elseif (strpos($texto, '/unpremium') === 0 && $esAdminUser) {
        comandoQuitarPremium($chatId, $texto, $db);
    }
    // Comandos de gestión de modelos (solo admins)
    elseif (strpos($texto, '/agregar_modelo') === 0 && $esAdminUser) {
        comandoAgregarModelo($chatId, $texto, $db);
    }
    elseif (strpos($texto, '/editar_modelo') === 0 && $esAdminUser) {
        comandoEditarModelo($chatId, $texto, $db);
    }
    elseif (strpos($texto, '/eliminar_modelo') === 0 && $esAdminUser) {
        comandoEliminarModelo($chatId, $texto, $db);
    }
    elseif (strpos($texto, '/stats_api') === 0 && $esAdminUser) {
        comandoEstadisticasAPI($chatId, $db);
    }
    elseif (strpos($texto, '/limpiar_cache') === 0 && $esAdminUser) {
        comandoLimpiarCache($chatId, $db);
    }
    // Procesamiento de texto libre (TAC o modelo)
    elseif (!empty($texto) && $texto[0] != '/') {
        // Verificar si está en estado de ingresar cupón
        $estado = $estados->getEstado($chatId);
        if ($estado && $estado['estado'] === 'ingresando_cupon') {
            comandoValidarCupon($chatId, $telegramId, $texto, $db, $sistemaPagos);
            $estados->limpiarEstado($chatId);
            return;
        }
        
        // Intentar como modelo primero
        $procesadoComoModelo = procesarModelo($chatId, $texto, $estados, $db, $telegramId);
        
        // Si no se procesó como modelo, procesar como TAC
        if (!$procesadoComoModelo) {
            procesarTAC($chatId, $texto, $telegramId, $db, $estados);
        }
    }
}

// ============================================
// MODOS DE EJECUCIÓN
// ============================================

function modoWebhook($db, $estados, $sistemaPagos) {
    $content = file_get_contents("php://input");
    $update = json_decode($content, true);
    
    if ($update) {
        procesarCallback($update, $db, $sistemaPagos, $estados);
        procesarActualizacion($update, $db, $estados, $sistemaPagos);
    }
}

function modoPolling($db, $estados, $sistemaPagos) {
    $offset = 0;
    
    echo "🤖 Bot con créditos y pagos iniciado\n";
    echo "Presiona Ctrl+C para detener\n\n";
    
    while (true) {
        $url = API_URL . "getUpdates?offset=$offset&timeout=30";
        $response = @file_get_contents($url);
        $updates = json_decode($response, true);
        
        if (isset($updates['result'])) {
            foreach ($updates['result'] as $update) {
                procesarCallback($update, $db, $sistemaPagos, $estados);
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
    // Modo webhook
    $db = new Database();
    $estados = new EstadosUsuario();
    $sistemaPagos = new SistemaPagos($db, BOT_TOKEN, ADMIN_IDS);
    modoWebhook($db, $estados, $sistemaPagos);
}
function comandoPanelPagosAdmin($chatId, $db, $sistemaPagos) {
    $stats = $sistemaPagos->obtenerEstadisticasPagos();
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║  👑 PANEL DE PAGOS 👑     ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    $respuesta .= "📊 *ESTADÍSTICAS*\n\n";
    $respuesta .= "💳 Total: {$stats['total']}\n";
    $respuesta .= "✅ Aprobados: {$stats['aprobados']}\n";
    $respuesta .= "⏳ Pendientes: {$stats['pendientes']}\n\n";
    $respuesta .= "Usa `/pagos_pendientes`";
    
    enviarMensaje($chatId, $respuesta);
}
?>
