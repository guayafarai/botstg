<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * VALIDACIÓN DE CAPTURAS DUPLICADAS - IMPLEMENTACIÓN COMPLETA
 * ═══════════════════════════════════════════════════════════════
 * 
 * INSTALACIÓN:
 * 1. Ejecutar el SQL incluido al final
 * 2. Reemplazar la función procesarCapturaPago en comandos_pagos.php
 * 3. Probar enviando la misma captura dos veces
 * 
 * TIEMPO: 30 minutos
 * DIFICULTAD: ⭐ Muy fácil
 * ROI: 🛡️🛡️🛡️🛡️ Alta seguridad
 */

/**
 * Procesar captura de pago - CON VALIDACIÓN ANTI-DUPLICADOS
 * 
 * CAMBIOS:
 * - Verifica si el file_id ya fue usado antes
 * - Registra intentos de captura duplicada
 * - Notifica al admin sobre intentos sospechosos
 * - Previene fraude por reutilización de capturas
 */
function procesarCapturaPago($chatId, $telegramId, $message, $db, $sistemaPagos, $estados) {
    $estado = $estados->getEstado($chatId);
    
    logSecure("Procesando captura - Usuario: {$telegramId}, Estado: " . ($estado ? $estado['estado'] : 'NULL'), 'INFO');
    
    // Verificar estado del usuario
    if (!$estado || $estado['estado'] != 'esperando_pago') {
        logSecure("Usuario {$telegramId} NO está esperando pago", 'DEBUG');
        return false;
    }
    
    // Verificar que sea una foto
    if (!isset($message['photo']) || empty($message['photo'])) {
        enviarMensaje($chatId, "❌ Por favor envía una *imagen* (captura de pantalla)");
        return true;
    }
    
    $pagoId = $estado['datos']['pago_id'] ?? null;
    
    if (!$pagoId) {
        enviarMensaje($chatId, "❌ Error: No se encontró el ID de pago");
        $estados->limpiarEstado($chatId);
        return true;
    }
    
    logSecure("Procesando pago #{$pagoId}", 'INFO');
    
    // Validar que el pago existe y pertenece al usuario
    try {
        $conn = $db->getConnection();
        $sql = "SELECT * FROM pagos_pendientes 
                WHERE id = :pago_id 
                AND telegram_id = :telegram_id 
                LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':pago_id' => (int)$pagoId,
            ':telegram_id' => (int)$telegramId
        ]);
        
        $pago = $stmt->fetch();
        
        if (!$pago) {
            logSecure("ERROR: Pago #{$pagoId} no encontrado o no pertenece al usuario {$telegramId}", 'ERROR');
            enviarMensaje($chatId, "❌ Error: Pago no encontrado\n\n*Solución:*\nInicia nuevamente:\n💰 *Comprar Créditos*");
            $estados->limpiarEstado($chatId);
            return true;
        }
        
        logSecure("Pago encontrado - Estado actual: '{$pago['estado']}'", 'INFO');
        
        // Verificar si ya fue procesado
        $estadosFinales = ['aprobado', 'rechazado', 'captura_enviada'];
        
        if (in_array($pago['estado'], $estadosFinales)) {
            logSecure("Pago #{$pagoId} ya procesado con estado: {$pago['estado']}", 'WARN');
            
            $mensajes = [
                'aprobado' => "✅ Tu pago ya fue aprobado y los créditos acreditados",
                'rechazado' => "❌ Tu pago fue rechazado. Puedes intentar nuevamente",
                'captura_enviada' => "📸 Tu captura ya fue enviada y está siendo validada"
            ];
            
            $mensaje = $mensajes[$pago['estado']] ?? "Estado: {$pago['estado']}";
            
            enviarMensaje($chatId, "⚠️ *PAGO YA PROCESADO*\n\n{$mensaje}");
            $estados->limpiarEstado($chatId);
            return true;
        }
        
        // Verificar que el estado permita recibir captura
        if (!in_array($pago['estado'], ['pendiente', 'esperando_captura'])) {
            logSecure("Estado '{$pago['estado']}' no permite captura", 'ERROR');
            enviarMensaje($chatId, "❌ Estado de pago inválido\n\nContacta soporte: @CHAMOGSM");
            $estados->limpiarEstado($chatId);
            return true;
        }
        
    } catch(PDOException $e) {
        logSecure("Error BD al buscar pago: " . $e->getMessage(), 'ERROR');
        enviarMensaje($chatId, "❌ Error de base de datos");
        return true;
    }
    
    // Obtener file_id de la foto (mejor resolución)
    $photos = $message['photo'];
    $photo = end($photos);
    $fileId = $photo['file_id'] ?? null;
    
    if (!$fileId) {
        enviarMensaje($chatId, "❌ Error: No se pudo obtener la imagen");
        return true;
    }
    
    // Validar file_id (básico)
    if (strlen($fileId) < 10 || strlen($fileId) > 200) {
        logSecure("File ID inválido: longitud " . strlen($fileId), 'ERROR');
        enviarMensaje($chatId, "❌ Error: Imagen inválida. Intenta de nuevo");
        return true;
    }
    
    // ═══════════════════════════════════════════════════════════════
    // ✅ NUEVA VALIDACIÓN: VERIFICAR CAPTURAS DUPLICADAS
    // ═══════════════════════════════════════════════════════════════
    
    try {
        // Verificar si este file_id ya fue usado en otro pago
        $sql = "SELECT p.*, u.username, u.first_name 
                FROM pagos_pendientes p
                LEFT JOIN usuarios u ON p.telegram_id = u.telegram_id
                WHERE p.captura_file_id = :file_id 
                AND p.id != :current_pago_id
                LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':file_id' => $fileId,
            ':current_pago_id' => (int)$pagoId
        ]);
        
        $capturaDuplicada = $stmt->fetch();
        
        if ($capturaDuplicada) {
            // ¡CAPTURA DUPLICADA DETECTADA!
            
            $otroUsuario = $capturaDuplicada['username'] ? 
                          "@{$capturaDuplicada['username']}" : 
                          $capturaDuplicada['first_name'];
            
            // Registrar intento sospechoso
            registrarIntentoDuplicado($db, $telegramId, $pagoId, $fileId, $capturaDuplicada['id']);
            
            logSecure("⚠️ CAPTURA DUPLICADA DETECTADA - Usuario {$telegramId} intentó usar captura del pago #{$capturaDuplicada['id']}", 'WARN');
            
            // Notificar a los administradores
            notificarCapturasDuplicadas($telegramId, $pagoId, $capturaDuplicada, BOT_TOKEN, ADMIN_IDS);
            
            // Mensaje al usuario
            $respuesta = "🚫 *CAPTURA DUPLICADA DETECTADA*\n\n";
            $respuesta .= "Esta imagen ya fue utilizada en otro pago.\n\n";
            $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $respuesta .= "⚠️ *IMPORTANTE:*\n";
            $respuesta .= "• Cada pago debe tener su propia captura única\n";
            $respuesta .= "• No se pueden reutilizar capturas anteriores\n";
            $respuesta .= "• La captura debe mostrar tu transacción actual\n\n";
            $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $respuesta .= "📸 *¿Qué hacer?*\n";
            $respuesta .= "1. Realiza el pago AHORA\n";
            $respuesta .= "2. Toma una captura NUEVA\n";
            $respuesta .= "3. Envíala aquí\n\n";
            $respuesta .= "⚠️ Intentos repetidos de fraude resultarán en suspensión de cuenta.";
            
            enviarMensaje($chatId, $respuesta);
            
            return true; // No procesar la captura
        }
        
        // Verificar si el usuario tiene múltiples intentos recientes de capturas duplicadas
        $sql = "SELECT COUNT(*) as intentos 
                FROM capturas_duplicadas 
                WHERE telegram_id = :telegram_id 
                AND fecha > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([':telegram_id' => (int)$telegramId]);
        $intentosRecientes = $stmt->fetch();
        
        if ($intentosRecientes['intentos'] >= 3) {
            // Usuario sospechoso - múltiples intentos de fraude
            
            logSecure("🚨 USUARIO SOSPECHOSO - {$telegramId} tiene {$intentosRecientes['intentos']} intentos de capturas duplicadas", 'ERROR');
            
            // Bloquear automáticamente
            $db->bloquearUsuario($telegramId, true);
            
            // Notificar admins urgente
            notificarUsuarioSospechoso($telegramId, $intentosRecientes['intentos'], BOT_TOKEN, ADMIN_IDS);
            
            $respuesta = "🚫 *CUENTA SUSPENDIDA*\n\n";
            $respuesta .= "Tu cuenta ha sido suspendida por intentos repetidos de enviar capturas duplicadas.\n\n";
            $respuesta .= "Para más información, contacta a @CHAMOGSM";
            
            enviarMensaje($chatId, $respuesta);
            $estados->limpiarEstado($chatId);
            
            return true;
        }
        
    } catch(PDOException $e) {
        logSecure("Error al verificar capturas duplicadas: " . $e->getMessage(), 'ERROR');
        // Continuar con el proceso si falla la verificación
    }
    
    // ═══════════════════════════════════════════════════════════════
    // FIN DE VALIDACIÓN DE DUPLICADOS
    // ═══════════════════════════════════════════════════════════════
    
    $caption = isset($message['caption']) ? htmlspecialchars($message['caption'], ENT_QUOTES, 'UTF-8') : null;
    
    logSecure("File ID obtenido: {$fileId}", 'INFO');
    
    // GUARDAR CAPTURA EN BD (una sola vez, con transacción)
    try {
        $db->beginTransaction();
        
        $sql = "UPDATE pagos_pendientes 
                SET captura_file_id = :file_id, 
                    captura_caption = :caption,
                    fecha_captura = NOW(),
                    estado = 'captura_enviada'
                WHERE id = :pago_id
                AND estado IN ('pendiente', 'esperando_captura')";
        
        $stmt = $conn->prepare($sql);
        $resultado = $stmt->execute([
            ':file_id' => $fileId,
            ':caption' => $caption,
            ':pago_id' => (int)$pagoId
        ]);
        
        $filasAfectadas = $stmt->rowCount();
        
        logSecure("UPDATE ejecutado - Filas afectadas: {$filasAfectadas}", 'INFO');
        
        if ($resultado && $filasAfectadas > 0) {
            $db->commit();
            
            // Limpiar estado del usuario
            $estados->limpiarEstado($chatId);
            
            // Notificar a administradores
            notificarCapturaRecibida($pagoId, $db, $fileId, BOT_TOKEN, ADMIN_IDS);
            
            // Mensaje de confirmación al usuario
            $respuesta = "✅ *¡CAPTURA RECIBIDA!*\n\n";
            $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $respuesta .= "🆔 Orden: #{$pagoId}\n";
            $respuesta .= "📸 Captura guardada correctamente\n\n";
            $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $respuesta .= "⏳ *PRÓXIMOS PASOS*\n\n";
            $respuesta .= "1️⃣ Verificación en proceso\n";
            $respuesta .= "2️⃣ Tiempo estimado: 1-24 horas\n";
            $respuesta .= "3️⃣ Te notificaremos el resultado\n\n";
            $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $respuesta .= "💡 Recibirás notificación cuando:\n";
            $respuesta .= "✅ Tu pago sea aprobado\n";
            $respuesta .= "❌ Si hay algún problema\n\n";
            $respuesta .= "📞 Dudas: @CHAMOGSM";
            
            enviarMensaje($chatId, $respuesta);
            
            logSecure("Captura guardada exitosamente para pago #{$pagoId}", 'INFO');
            return true;
            
        } else {
            $db->rollBack();
            logSecure("No se actualizó ninguna fila (posible race condition)", 'ERROR');
            enviarMensaje($chatId, "❌ Error: El pago ya fue procesado\n\nContacta: @CHAMOGSM");
            $estados->limpiarEstado($chatId);
            return true;
        }
        
    } catch(PDOException $e) {
        $db->rollBack();
        logSecure("Error SQL al guardar captura: " . $e->getMessage(), 'ERROR');
        enviarMensaje($chatId, "❌ Error de base de datos\n\nContacta: @CHAMOGSM");
        return true;
    }
}

/**
 * Registrar intento de captura duplicada
 */
function registrarIntentoDuplicado($db, $telegramId, $pagoId, $fileId, $pagoOriginalId) {
    try {
        $conn = $db->getConnection();
        
        $sql = "INSERT INTO capturas_duplicadas 
                (telegram_id, pago_id, file_id, pago_original_id, fecha)
                VALUES (:telegram_id, :pago_id, :file_id, :pago_original_id, NOW())";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':telegram_id' => (int)$telegramId,
            ':pago_id' => (int)$pagoId,
            ':file_id' => $fileId,
            ':pago_original_id' => (int)$pagoOriginalId
        ]);
        
        logSecure("Intento de captura duplicada registrado en BD", 'INFO');
        
    } catch(PDOException $e) {
        logSecure("Error al registrar intento duplicado: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * Notificar admins sobre capturas duplicadas
 */
function notificarCapturasDuplicadas($telegramId, $pagoId, $capturaDuplicada, $botToken, $adminIds) {
    try {
        $otroUsuario = $capturaDuplicada['username'] ? 
                      "@{$capturaDuplicada['username']}" : 
                      $capturaDuplicada['first_name'];
        
        $mensaje = "🚨 *ALERTA: CAPTURA DUPLICADA*\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "⚠️ Usuario intentó usar una captura ya utilizada\n\n";
        $mensaje .= "👤 *Usuario sospechoso:*\n";
        $mensaje .= "• ID: `{$telegramId}`\n";
        $mensaje .= "• Pago actual: #{$pagoId}\n\n";
        $mensaje .= "📸 *Captura original pertenece a:*\n";
        $mensaje .= "• Usuario: {$otroUsuario}\n";
        $mensaje .= "• Pago: #{$capturaDuplicada['id']}\n";
        $mensaje .= "• Estado: {$capturaDuplicada['estado']}\n";
        $mensaje .= "• Fecha: " . date('d/m/Y H:i', strtotime($capturaDuplicada['fecha_solicitud'])) . "\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "⚡ *ACCIONES SUGERIDAS:*\n";
        $mensaje .= "• Verificar ambos usuarios\n";
        $mensaje .= "• Considerar bloqueo si es reincidente\n\n";
        $mensaje .= "`/bloquear {$telegramId}` - Bloquear usuario";
        
        $apiUrl = "https://api.telegram.org/bot{$botToken}/";
        
        foreach ($adminIds as $adminId) {
            $url = $apiUrl . 'sendMessage';
            $data = [
                'chat_id' => $adminId,
                'text' => $mensaje,
                'parse_mode' => 'Markdown'
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10
            ]);
            
            curl_exec($ch);
            curl_close($ch);
        }
        
    } catch(Exception $e) {
        logSecure("Error al notificar capturas duplicadas: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * Notificar sobre usuario sospechoso con múltiples intentos
 */
function notificarUsuarioSospechoso($telegramId, $intentos, $botToken, $adminIds) {
    try {
        $mensaje = "🚨🚨 *ALERTA URGENTE* 🚨🚨\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "⚠️ *USUARIO ALTAMENTE SOSPECHOSO*\n\n";
        $mensaje .= "👤 ID: `{$telegramId}`\n";
        $mensaje .= "🔴 Intentos de fraude: *{$intentos}*\n";
        $mensaje .= "⏰ Última hora\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "✅ *ACCIÓN AUTOMÁTICA:*\n";
        $mensaje .= "Usuario bloqueado automáticamente\n\n";
        $mensaje .= "📋 *REVISAR:*\n";
        $mensaje .= "• Historial de pagos\n";
        $mensaje .= "• Otros intentos sospechosos\n";
        $mensaje .= "• Considerar reporte a autoridades si persiste";
        
        $apiUrl = "https://api.telegram.org/bot{$botToken}/";
        
        foreach ($adminIds as $adminId) {
            $url = $apiUrl . 'sendMessage';
            $data = [
                'chat_id' => $adminId,
                'text' => $mensaje,
                'parse_mode' => 'Markdown'
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10
            ]);
            
            curl_exec($ch);
            curl_close($ch);
        }
        
    } catch(Exception $e) {
        logSecure("Error al notificar usuario sospechoso: " . $e->getMessage(), 'ERROR');
    }
}

?>

<!-- 
═══════════════════════════════════════════════════════════════
SQL PARA CREAR LA TABLA DE CAPTURAS DUPLICADAS
═══════════════════════════════════════════════════════════════

Ejecutar en tu base de datos:
-->

-- Tabla para registrar intentos de capturas duplicadas
CREATE TABLE IF NOT EXISTS capturas_duplicadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT NOT NULL,
    pago_id INT NOT NULL,
    file_id VARCHAR(255) NOT NULL,
    pago_original_id INT NOT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_telegram_fecha (telegram_id, fecha),
    INDEX idx_file_id (file_id),
    INDEX idx_pago (pago_id),
    
    FOREIGN KEY (telegram_id) REFERENCES usuarios(telegram_id) ON DELETE CASCADE,
    FOREIGN KEY (pago_id) REFERENCES pagos_pendientes(id) ON DELETE CASCADE,
    FOREIGN KEY (pago_original_id) REFERENCES pagos_pendientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índice adicional en pagos_pendientes para búsquedas rápidas
CREATE INDEX idx_captura_file_id ON pagos_pendientes(captura_file_id);

-- Vista para análisis de intentos de fraude
CREATE OR REPLACE VIEW vista_intentos_fraude AS
SELECT 
    cd.telegram_id,
    u.username,
    u.first_name,
    COUNT(*) as total_intentos,
    MAX(cd.fecha) as ultimo_intento,
    u.bloqueado
FROM capturas_duplicadas cd
LEFT JOIN usuarios u ON cd.telegram_id = u.telegram_id
GROUP BY cd.telegram_id, u.username, u.first_name, u.bloqueado
ORDER BY total_intentos DESC;

-- Comentarios para documentación
ALTER TABLE capturas_duplicadas 
    COMMENT = 'Registra intentos de usar capturas de pago duplicadas para detectar fraude';

<!--
═══════════════════════════════════════════════════════════════
COMANDOS ADMIN ADICIONALES (Opcional)
═══════════════════════════════════════════════════════════════
-->

-- Agregar a bot_imei_corregido.php:

function comandoVerIntentosFraude($chatId, $db) {
    try {
        $conn = $db->getConnection();
        
        $sql = "SELECT * FROM vista_intentos_fraude LIMIT 20";
        $stmt = $conn->query($sql);
        $intentos = $stmt->fetchAll();
        
        if (empty($intentos)) {
            enviarMensaje($chatId, "✅ No hay intentos de fraude registrados");
            return;
        }
        
        $respuesta = "╔═══════════════════════════╗\n";
        $respuesta .= "║  🚨 INTENTOS DE FRAUDE    ║\n";
        $respuesta .= "╚═══════════════════════════╝\n\n";
        
        foreach ($intentos as $intento) {
            $username = $intento['username'] ? "@{$intento['username']}" : $intento['first_name'];
            $bloqueado = $intento['bloqueado'] ? '🔴 BLOQUEADO' : '⚪ Activo';
            $fecha = date('d/m/Y H:i', strtotime($intento['ultimo_intento']));
            
            $respuesta .= "👤 {$username}\n";
            $respuesta .= "├ ID: `{$intento['telegram_id']}`\n";
            $respuesta .= "├ Intentos: *{$intento['total_intentos']}*\n";
            $respuesta .= "├ Último: {$fecha}\n";
            $respuesta .= "└ Estado: {$bloqueado}\n\n";
        }
        
        enviarMensaje($chatId, $respuesta);
        
    } catch(Exception $e) {
        enviarMensaje($chatId, "❌ Error al consultar intentos de fraude");
        logSecure("Error en comandoVerIntentosFraude: " . $e->getMessage(), 'ERROR');
    }
}

// Agregar al menú admin:
elseif ($texto == '🚨 Ver Intentos Fraude' && $esAdminUser) {
    comandoVerIntentosFraude($chatId, $db);
}
