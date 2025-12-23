<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * COMANDOS DE PAGOS - VERSIÓN TOTALMENTE CORREGIDA v2.0
 * ═══════════════════════════════════════════════════════════════
 * CAMBIOS:
 * - Funciones anti-fraude implementadas
 * - Validación robusta de capturas duplicadas
 * - Trigger de BD para prevenir race conditions
 * - Notificaciones mejoradas
 */

require_once(__DIR__ . '/sistema_pagos.php');

/**
 * Comando para mostrar paquetes y comprar créditos
 */
function comandoComprarCreditosMejorado($chatId, $telegramId, $db, $sistemaPagos, $estados) {
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║  💰 COMPRAR CRÉDITOS 💰   ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    
    $respuesta .= $sistemaPagos->mostrarPaquetes('PEN');
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $respuesta .= "💡 *¿CÓMO COMPRAR?*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $respuesta .= "1️⃣ Selecciona tu paquete\n";
    $respuesta .= "2️⃣ Elige método de pago\n";
    $respuesta .= "3️⃣ Realiza la transferencia\n";
    $respuesta .= "4️⃣ Envía tu captura\n";
    $respuesta .= "5️⃣ ¡Listo! Créditos acreditados\n\n";
    $respuesta .= "🎯 Selecciona un paquete:";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🥉 BÁSICO - 50 créditos', 'callback_data' => 'paquete_basico'],
                ['text' => '🥈 ESTÁNDAR - 100 créditos', 'callback_data' => 'paquete_estandar']
            ],
            [
                ['text' => '🥇 PREMIUM - 200 créditos', 'callback_data' => 'paquete_premium'],
                ['text' => '💎 MEGA - 500 créditos', 'callback_data' => 'paquete_mega']
            ],
            [
                ['text' => '👑 ULTRA - 1000 créditos', 'callback_data' => 'paquete_ultra']
            ]
        ]
    ];
    
    enviarMensaje($chatId, $respuesta, 'Markdown', json_encode($keyboard));
}

/**
 * Procesar selección de paquete
 */
function procesarSeleccionPaquete($chatId, $telegramId, $paqueteId, $db, $sistemaPagos, $estados) {
    $paquete = $sistemaPagos->obtenerPaquete($paqueteId);
    
    if (!$paquete) {
        enviarMensaje($chatId, "❌ Paquete no válido");
        return;
    }
    
    $estados->setEstado($chatId, 'seleccionando_metodo_pago', [
        'paquete_id' => $paqueteId,
        'paso' => 'metodo_pago'
    ]);
    
    $respuesta = "✅ *Has seleccionado:*\n\n";
    $respuesta .= "{$paquete['nombre']}\n";
    $respuesta .= "💎 {$paquete['creditos']} créditos\n";
    $respuesta .= "💵 S/.{$paquete['precio_pen']} PEN / \${$paquete['precio_usd']} USD\n\n";
    
    if ($paquete['ahorro'] > 0) {
        $respuesta .= "🎁 ¡Ahorras {$paquete['ahorro']}%!\n\n";
    }
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $respuesta .= "💳 *Selecciona tu método de pago:*";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '💳 Yape (S/. ' . $paquete['precio_pen'] . ')', 'callback_data' => 'metodo_yape_PEN']
            ],
            [
                ['text' => '💰 Plin (S/. ' . $paquete['precio_pen'] . ')', 'callback_data' => 'metodo_plin_PEN']
            ],
            [
                ['text' => '🌐 PayPal ($' . $paquete['precio_usd'] . ')', 'callback_data' => 'metodo_paypal_USD']
            ],
            [
                ['text' => '₿ Binance Pay (USDT)', 'callback_data' => 'metodo_binance_USDT']
            ],
            [
                ['text' => '💎 USDT TRC20', 'callback_data' => 'metodo_usdt_USDT']
            ],
            [
                ['text' => '🔙 Cambiar paquete', 'callback_data' => 'comprar_creditos']
            ]
        ]
    ];
    
    enviarMensaje($chatId, $respuesta, 'Markdown', json_encode($keyboard));
}

/**
 * Procesar selección de método de pago
 */
function procesarSeleccionMetodoPago($chatId, $telegramId, $metodo, $moneda, $db, $sistemaPagos, $estados) {
    $estado = $estados->getEstado($chatId);
    
    if (!$estado || $estado['estado'] != 'seleccionando_metodo_pago') {
        enviarMensaje($chatId, "❌ Error: Selecciona primero un paquete");
        return;
    }
    
    $paqueteId = $estado['datos']['paquete_id'];
    $paquete = $sistemaPagos->obtenerPaquete($paqueteId);
    
    if (!$paquete) {
        enviarMensaje($chatId, "❌ Error: Paquete no encontrado");
        $estados->limpiarEstado($chatId);
        return;
    }
    
    // Crear solicitud de pago
    $resultado = $sistemaPagos->crearSolicitudPago($telegramId, $paqueteId, $metodo, $moneda);
    
    if (!$resultado['exito']) {
        enviarMensaje($chatId, "❌ Error: " . $resultado['mensaje']);
        return;
    }
    
    $pagoId = $resultado['pago_id'];
    
    logSecure("Pago #{$pagoId} creado para usuario {$telegramId}", 'INFO');
    
    // Actualizar estado a esperando_captura en BD
    try {
        $conn = $db->getConnection();
        $sql = "UPDATE pagos_pendientes 
                SET estado = :estado 
                WHERE id = :pago_id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':estado' => 'esperando_captura',
            ':pago_id' => (int)$pagoId
        ]);
        
        logSecure("Estado del pago #{$pagoId} actualizado a 'esperando_captura'", 'INFO');
        
    } catch(PDOException $e) {
        logSecure("Error al actualizar estado del pago: " . $e->getMessage(), 'ERROR');
    }
    
    // Actualizar estado del usuario
    $estados->setEstado($chatId, 'esperando_pago', [
        'pago_id' => $pagoId,
        'paquete_id' => $paqueteId,
        'metodo' => $metodo,
        'moneda' => $moneda
    ]);
    
    // Obtener detalles del método de pago
    $metodosPago = $sistemaPagos->obtenerMetodosPago();
    $detallesMetodo = $metodosPago[$metodo] ?? null;
    
    $precio = $moneda === 'PEN' ? $paquete['precio_pen'] : $paquete['precio_usd'];
    
    // Mensaje con instrucciones
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║   📋 INSTRUCCIONES        ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    
    $respuesta .= "🆔 *Orden de Pago:* #{$pagoId}\n\n";
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "📦 *RESUMEN DE COMPRA*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $respuesta .= "• Paquete: {$paquete['nombre']}\n";
    $respuesta .= "• Créditos: {$paquete['creditos']}\n";
    $respuesta .= "• Monto: ";
    
    if ($moneda === 'PEN') {
        $respuesta .= "S/. {$precio}\n";
    } elseif ($moneda === 'USD') {
        $respuesta .= "\${$precio}\n";
    } else {
        $respuesta .= "{$precio} {$moneda}\n";
    }
    
    if ($detallesMetodo) {
        $respuesta .= "• Método: {$detallesMetodo['nombre']}\n\n";
        
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $respuesta .= "💳 *DATOS DE PAGO*\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        if (isset($detallesMetodo['numero'])) {
            $respuesta .= "📱 Número: `{$detallesMetodo['numero']}`\n";
            $respuesta .= "👤 Titular: {$detallesMetodo['titular']}\n";
        }
        
        if (isset($detallesMetodo['email'])) {
            $respuesta .= "📧 Email: `{$detallesMetodo['email']}`\n";
        }
        
        if (isset($detallesMetodo['address'])) {
            $respuesta .= "🔗 Dirección: `{$detallesMetodo['address']}`\n";
        }
        
        if (isset($detallesMetodo['id'])) {
            $respuesta .= "🆔 ID: `{$detallesMetodo['id']}`\n";
        }
    }
    
    $respuesta .= "\n━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "📸 *IMPORTANTE*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $respuesta .= "• Envía el monto exacto\n";
    $respuesta .= "• Incluye tu ID: `{$telegramId}`\n";
    $respuesta .= "• Captura debe ser legible\n";
    $respuesta .= "• ⚠️ No reutilices capturas antiguas\n\n";
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "📸 *SIGUIENTE PASO*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $respuesta .= "📸 *Envía tu captura como imagen*\n\n";
    
    $respuesta .= "⏰ Tienes 72 horas";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '❌ Cancelar pago', 'callback_data' => 'cancelar_pago_' . $pagoId]
            ]
        ]
    ];
    
    enviarMensaje($chatId, $respuesta, 'Markdown', json_encode($keyboard));
}

/**
 * Procesar captura de pago - VERSIÓN CORREGIDA CON ANTI-FRAUDE
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
    // ✅ VALIDACIÓN ANTI-FRAUDE: VERIFICAR CAPTURAS DUPLICADAS
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
        
        if ($intentosRecientes && $intentosRecientes['intentos'] >= 3) {
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
        
        // Verificar si es error de constraint UNIQUE (captura duplicada)
        if ($e->getCode() == 23000 && strpos($e->getMessage(), 'unique_captura_file_id') !== false) {
            logSecure("⚠️ CAPTURA DUPLICADA (constraint violation) - Usuario {$telegramId}", 'WARN');
            
            $respuesta = "🚫 *CAPTURA DUPLICADA*\n\n";
            $respuesta .= "Esta imagen ya fue utilizada.\n";
            $respuesta .= "Envía una captura NUEVA de tu pago actual.";
            
            enviarMensaje($chatId, $respuesta);
        } else {
            logSecure("Error SQL al guardar captura: " . $e->getMessage(), 'ERROR');
            enviarMensaje($chatId, "❌ Error de base de datos\n\nContacta: @CHAMOGSM");
        }
        
        return true;
    }
}

/**
 * Notificar a administradores - VERSIÓN CORREGIDA
 */
function notificarCapturaRecibida($pagoId, $db, $fileId, $botToken, $adminIds) {
    try {
        $conn = $db->getConnection();
        $sql = "SELECT p.*, u.username, u.first_name 
                FROM pagos_pendientes p
                LEFT JOIN usuarios u ON p.telegram_id = u.telegram_id
                WHERE p.id = :id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => (int)$pagoId]);
        $pago = $stmt->fetch();
        
        if (!$pago) {
            logSecure("No se pudo obtener info del pago #{$pagoId}", 'ERROR');
            return;
        }
        
        $username = !empty($pago['username']) ? "@{$pago['username']}" : $pago['first_name'];
        
        $mensaje = "📸 *NUEVA CAPTURA DE PAGO*\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "🆔 Pago ID: *#{$pagoId}*\n";
        $mensaje .= "👤 Usuario: {$username}\n";
        $mensaje .= "📱 Telegram ID: `{$pago['telegram_id']}`\n";
        $mensaje .= "📦 Paquete: {$pago['paquete']}\n";
        $mensaje .= "💎 Créditos: {$pago['creditos']}\n";
        $mensaje .= "💰 Monto: {$pago['monto']} {$pago['moneda']}\n";
        $mensaje .= "💳 Método: {$pago['metodo_pago']}\n\n";
        
        if (!empty($pago['captura_caption'])) {
            $mensaje .= "📝 Nota: {$pago['captura_caption']}\n\n";
        }
        
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $mensaje .= "⚡ *COMANDOS*\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "`/detalle {$pagoId}`\n";
        $mensaje .= "`/aprobar {$pagoId}`\n";
        $mensaje .= "`/rechazar {$pagoId} [motivo]`";
        
        $apiUrl = "https://api.telegram.org/bot{$botToken}/";
        
        foreach ($adminIds as $adminId) {
            // Enviar mensaje
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
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($response === false || $httpCode !== 200) {
                logSecure("Error al notificar admin {$adminId}", 'ERROR');
                continue;
            }
            
            // Enviar foto
            $url = $apiUrl . 'sendPhoto';
            $data = [
                'chat_id' => $adminId,
                'photo' => $fileId,
                'caption' => "📸 Captura pago #{$pagoId}\n\n`/aprobar {$pagoId}`",
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
            
            logSecure("Admin {$adminId} notificado correctamente", 'INFO');
        }
        
    } catch(Exception $e) {
        logSecure("Error al notificar admins: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * Comando para ver detalle de pago
 */
function comandoDetallePago($chatId, $pagoId, $db, $sistemaPagos) {
    $pago = $sistemaPagos->obtenerDetallePago($pagoId);
    
    if (!$pago) {
        enviarMensaje($chatId, "❌ Pago no encontrado");
        return;
    }
    
    $username = !empty($pago['username']) ? "@{$pago['username']}" : $pago['first_name'];
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║   📋 DETALLE PAGO #{$pago['id']}   ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    
    $respuesta .= "📅 " . date('d/m/Y H:i', strtotime($pago['fecha_solicitud'])) . "\n\n";
    
    $respuesta .= "👤 *USUARIO*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "• Nombre: {$pago['first_name']}\n";
    $respuesta .= "• Usuario: {$username}\n";
    $respuesta .= "• ID: `{$pago['telegram_id']}`\n";
    $respuesta .= "• Créditos actuales: {$pago['creditos_actuales']}\n\n";
    
    $respuesta .= "💰 *DETALLES*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "• Paquete: {$pago['paquete']}\n";
    $respuesta .= "• Créditos: {$pago['creditos']}\n";
    $respuesta .= "• Monto: {$pago['monto']} {$pago['moneda']}\n";
    $respuesta .= "• Método: {$pago['metodo_pago']}\n\n";
    
    $respuesta .= "📊 *ESTADO*: {$pago['estado']}\n";
    
    if (!empty($pago['motivo_rechazo'])) {
        $respuesta .= "\n📝 Motivo rechazo:\n{$pago['motivo_rechazo']}";
    }
    
    enviarMensaje($chatId, $respuesta);
    
    // Enviar captura si existe
    if (!empty($pago['captura_file_id'])) {
        $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendPhoto";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'chat_id' => $chatId,
                'photo' => $pago['captura_file_id'],
                'caption' => "📸 Captura del pago #{$pago['id']}"
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10
        ]);
        
        curl_exec($ch);
        curl_close($ch);
    }
}

/**
 * Comando para aprobar pago
 */
function comandoAprobarPagoMejorado($chatId, $texto, $adminId, $db, $sistemaPagos) {
    $partes = explode(' ', $texto, 3);
    
    if (count($partes) < 2) {
        enviarMensaje($chatId, "❌ Formato: `/aprobar [ID] [notas]`\n\nEjemplo: `/aprobar 5`");
        return;
    }
    
    $pagoId = intval($partes[1]);
    $notas = isset($partes[2]) ? $partes[2] : null;
    
    if ($pagoId <= 0) {
        enviarMensaje($chatId, "❌ ID inválido");
        return;
    }
    
    $resultado = $sistemaPagos->aprobarPago($pagoId, $adminId, $notas);
    
    if ($resultado['exito']) {
        $respuesta = "✅ *PAGO APROBADO*\n\n";
        $respuesta .= "🆔 Pago: #{$pagoId}\n";
        $respuesta .= "💎 Créditos: {$resultado['creditos_agregados']}\n\n";
        $respuesta .= "✅ Usuario notificado\n";
        $respuesta .= "✅ Créditos acreditados";
        
        enviarMensaje($chatId, $respuesta);
    } else {
        enviarMensaje($chatId, "❌ Error: " . $resultado['mensaje']);
    }
}

/**
 * Comando para rechazar pago
 */
function comandoRechazarPagoMejorado($chatId, $texto, $adminId, $db, $sistemaPagos) {
    $partes = explode(' ', $texto, 3);
    
    if (count($partes) < 3) {
        enviarMensaje($chatId, "❌ Formato: `/rechazar [ID] [motivo]`\n\nEjemplo:\n`/rechazar 5 Monto incorrecto`");
        return;
    }
    
    $pagoId = intval($partes[1]);
    $motivo = trim($partes[2]);
    
    if ($pagoId <= 0) {
        enviarMensaje($chatId, "❌ ID inválido");
        return;
    }
    
    if (empty($motivo)) {
        enviarMensaje($chatId, "❌ Debes especificar un motivo");
        return;
    }
    
    $resultado = $sistemaPagos->rechazarPago($pagoId, $adminId, $motivo);
    
    if ($resultado['exito']) {
        $respuesta = "❌ *PAGO RECHAZADO*\n\n";
        $respuesta .= "🆔 Pago: #{$pagoId}\n";
        $respuesta .= "📝 Motivo: {$motivo}\n\n";
        $respuesta .= "✅ Usuario notificado";
        
        enviarMensaje($chatId, $respuesta);
    } else {
        enviarMensaje($chatId, "❌ Error: " . $resultado['mensaje']);
    }
}

// ═══════════════════════════════════════════════════════════════
// FUNCIONES ANTI-FRAUDE - AGREGADAS
// ═══════════════════════════════════════════════════════════════

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
        
        logSecure("Intento de captura duplicada registrado - Usuario: {$telegramId}, Pago: {$pagoId}", 'WARN');
        
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
