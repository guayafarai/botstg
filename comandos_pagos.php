<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * COMANDOS DE PAGOS - VERSIÓN CON FIXES CRÍTICOS
 * ═══════════════════════════════════════════════════════════════
 */

require_once(__DIR__ . '/sistema_pagos.php');

// ═══════════════════════════════════════════════════════════════
// FUNCIONES DE COMANDOS DE PAGO
// ═══════════════════════════════════════════════════════════════

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

function procesarSeleccionMetodoPago($chatId, $telegramId, $metodo, $moneda, $db, $sistemaPagos, $estados) {
    $estado = $estados->getEstado($chatId);
    
    if (!$estado || $estado['estado'] != 'seleccionando_metodo_pago') {
        enviarMensaje($chatId, "❌ Error: Selecciona primero un paquete");
        return;
    }
    
    $paqueteId = $estado['datos']['paquete_id'];
    $paquete = $sistemaPagos->obtenerPaquete($paqueteId);
    
    // Crear solicitud de pago
    $resultado = $sistemaPagos->crearSolicitudPago($telegramId, $paqueteId, $metodo, $moneda);
    
    if (!$resultado['exito']) {
        enviarMensaje($chatId, "❌ Error: " . $resultado['mensaje']);
        return;
    }
    
    $pagoId = $resultado['pago_id'];
    
    error_log("=== PAGO CREADO ===");
    error_log("Pago ID: {$pagoId}");
    
    // Actualizar estado a 'esperando_captura' usando prepared statement
    // FIX CRÍTICO #11: Prevenir SQL injection
    $sqlUpdate = "UPDATE pagos_pendientes 
                  SET estado = :estado 
                  WHERE id = :pago_id";
    
    try {
        $stmt = $db->conn->prepare($sqlUpdate);
        $resultado_update = $stmt->execute([
            ':estado' => 'esperando_captura',
            ':pago_id' => $pagoId
        ]);
        
        error_log("UPDATE estado ejecutado - Resultado: " . ($resultado_update ? 'TRUE' : 'FALSE'));
        error_log("Filas afectadas: " . $stmt->rowCount());
        
    } catch(PDOException $e) {
        error_log("ERROR al actualizar estado: " . $e->getMessage());
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
    $respuesta .= "• Captura debe ser legible\n\n";
    
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
 * ═══════════════════════════════════════════════════════════════
 * FUNCIÓN CRÍTICA SIMPLIFICADA - Procesar captura de pago
 * FIX: Eliminada lógica redundante y múltiples actualizaciones
 * ═══════════════════════════════════════════════════════════════
 */
function procesarCapturaPago($chatId, $telegramId, $message, $db, $sistemaPagos, $estados) {
    $estado = $estados->getEstado($chatId);
    
    error_log("=== PROCESANDO CAPTURA ===");
    error_log("Usuario: {$telegramId}");
    error_log("Estado usuario: " . ($estado ? json_encode($estado) : 'NULL'));
    
    // Verificar estado del usuario
    if (!$estado || $estado['estado'] != 'esperando_pago') {
        error_log("Usuario NO está esperando pago");
        return false; // No está esperando captura
    }
    
    // Verificar que sea una foto
    if (!isset($message['photo'])) {
        enviarMensaje($chatId, "❌ Por favor envía una *imagen* (captura de pantalla)");
        return true; // Procesado pero con error
    }
    
    $pagoId = $estado['datos']['pago_id'];
    error_log("Procesando pago ID: {$pagoId}");
    
    // VERIFICACIÓN SIMPLIFICADA: Buscar el pago
    $sql = "SELECT * FROM pagos_pendientes WHERE id = :pago_id AND telegram_id = :telegram_id";
    try {
        $stmt = $db->conn->prepare($sql);
        $stmt->execute([
            ':pago_id' => $pagoId,
            ':telegram_id' => $telegramId
        ]);
        $pago = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pago) {
            error_log("ERROR: Pago #{$pagoId} no encontrado");
            enviarMensaje($chatId, "❌ Error: No se encontró el pago #" . $pagoId . "\n\n*Solución:*\nInicia nuevamente:\n💰 *Comprar Créditos*");
            $estados->limpiarEstado($chatId);
            return true;
        }
        
        error_log("Pago encontrado - Estado actual: '{$pago['estado']}'");
        
        // VERIFICAR ESTADO: ¿Ya fue procesado?
        $estadosFinales = ['aprobado', 'rechazado', 'captura_enviada'];
        
        if (in_array($pago['estado'], $estadosFinales)) {
            error_log("ADVERTENCIA: Pago ya en estado final: {$pago['estado']}");
            
            $estadosMsg = [
                'aprobado' => '✅ APROBADO - Créditos ya acreditados',
                'rechazado' => '❌ RECHAZADO - Pago no válido',
                'captura_enviada' => '📸 CAPTURA ENVIADA - Esperando validación'
            ];
            
            $mensajeEstado = isset($estadosMsg[$pago['estado']]) ? $estadosMsg[$pago['estado']] : $pago['estado'];
            
            $respuesta = "⚠️ *PAGO YA PROCESADO*\n\n";
            $respuesta .= "🆔 Orden: #{$pagoId}\n";
            $respuesta .= "📊 Estado: *{$mensajeEstado}*\n\n";
            
            if ($pago['estado'] === 'captura_enviada') {
                $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                $respuesta .= "📸 Tu captura ya fue enviada\n";
                $respuesta .= "⏳ Estamos validándola\n";
                $respuesta .= "⏱️ Tiempo estimado: 1-24 horas\n\n";
                $respuesta .= "💡 Te notificaremos cuando se apruebe";
            } elseif ($pago['estado'] === 'aprobado') {
                $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                $respuesta .= "✅ Tus créditos ya fueron acreditados\n";
                $respuesta .= "💎 Revisa tu saldo en:\n";
                $respuesta .= "→ *💳 Mis Créditos*";
            } else {
                $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                if (!empty($pago['motivo_rechazo'])) {
                    $respuesta .= "📝 Motivo: {$pago['motivo_rechazo']}\n\n";
                }
                $respuesta .= "💡 Puedes hacer un nuevo intento:\n";
                $respuesta .= "→ *💰 Comprar Créditos*";
            }
            
            enviarMensaje($chatId, $respuesta);
            $estados->limpiarEstado($chatId);
            return true;
        }
        
        // VERIFICAR que el estado permite recibir captura
        $estadosPermitidos = ['pendiente', 'esperando_captura'];
        
        if (!in_array($pago['estado'], $estadosPermitidos)) {
            error_log("ERROR: Estado no permitido para captura: {$pago['estado']}");
            enviarMensaje($chatId, "❌ Error: Estado de pago inválido\n\nContacta soporte: @CHAMOGSM");
            $estados->limpiarEstado($chatId);
            return true;
        }
        
    } catch(PDOException $e) {
        error_log("ERROR BD al buscar pago: " . $e->getMessage());
        enviarMensaje($chatId, "❌ Error de base de datos\n\nContacta: @CHAMOGSM");
        return true;
    }
    
    // Obtener file_id de la foto (la de mayor resolución)
    $photos = $message['photo'];
    $photo = end($photos);
    $fileId = $photo['file_id'];
    
    $caption = isset($message['caption']) ? $message['caption'] : null;
    
    error_log("File ID obtenido: {$fileId}");
    if ($caption) {
        error_log("Caption: {$caption}");
    }
    
    // GUARDAR CAPTURA EN BASE DE DATOS (Una sola vez, sin verificaciones redundantes)
    $sql = "UPDATE pagos_pendientes 
            SET captura_file_id = :file_id, 
                captura_caption = :caption,
                fecha_captura = NOW(),
                estado = 'captura_enviada'
            WHERE id = :pago_id";
    
    try {
        $stmt = $db->conn->prepare($sql);
        $resultado = $stmt->execute([
            ':file_id' => $fileId,
            ':caption' => $caption,
            ':pago_id' => $pagoId
        ]);
        
        $filasAfectadas = $stmt->rowCount();
        
        error_log("UPDATE ejecutado - Resultado: " . ($resultado ? 'TRUE' : 'FALSE'));
        error_log("Filas afectadas: {$filasAfectadas}");
        
        if ($resultado && $filasAfectadas > 0) {
            // Limpiar estado del usuario
            $estados->limpiarEstado($chatId);
            
            // Notificar a administradores
            notificarCapturaRecibidaDirecta($pagoId, $db, $fileId, BOT_TOKEN, ADMIN_IDS);
            
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
            
            error_log("=== CAPTURA GUARDADA EXITOSAMENTE ===");
            return true;
            
        } else {
            error_log("ERROR: No se actualizó ninguna fila en la BD");
            enviarMensaje($chatId, "❌ Error al guardar captura\n\n*Debug Info:*\nPago ID: {$pagoId}\nFilas afectadas: {$filasAfectadas}\n\nContacta: @CHAMOGSM");
            return true;
        }
        
    } catch(PDOException $e) {
        error_log("ERROR SQL al guardar captura: " . $e->getMessage());
        enviarMensaje($chatId, "❌ Error de base de datos:\n\n`{$e->getMessage()}`\n\nContacta: @CHAMOGSM");
        return true;
    }
}

/**
 * Notificar a administradores sobre captura recibida
 * FIX CRÍTICO #10: Continuar notificando a todos los admins aunque falle uno
 */
function notificarCapturaRecibidaDirecta($pagoId, $db, $fileId, $botToken, $adminIds) {
    $sql = "SELECT p.*, u.username, u.first_name 
            FROM pagos_pendientes p
            LEFT JOIN usuarios u ON p.telegram_id = u.telegram_id
            WHERE p.id = :id";
    
    try {
        $stmt = $db->conn->prepare($sql);
        $stmt->execute([':id' => $pagoId]);
        $pago = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pago) {
            error_log("ERROR: No se pudo obtener info del pago #{$pagoId} para notificar");
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
            $mensaje .= "📝 Nota del usuario:\n_{$pago['captura_caption']}_\n\n";
        }
        
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $mensaje .= "⚡ *COMANDOS RÁPIDOS*\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "`/detalle {$pagoId}` - Ver detalles\n";
        $mensaje .= "`/aprobar {$pagoId}` - Aprobar pago\n";
        $mensaje .= "`/rechazar {$pagoId} motivo` - Rechazar";
        
        $apiUrl = "https://api.telegram.org/bot{$botToken}/";
        
        // FIX CRÍTICO #10: Continuar con todos los admins aunque falle uno
        foreach ($adminIds as $adminId) {
            try {
                // Enviar mensaje de texto
                $url = $apiUrl . 'sendMessage';
                $data = [
                    'chat_id' => $adminId,
                    'text' => $mensaje,
                    'parse_mode' => 'Markdown'
                ];
                
                $options = [
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-Type: application/json',
                        'content' => json_encode($data)
                    ]
                ];
                
                $context = stream_context_create($options);
                $result = file_get_contents($url, false, $context);
                
                if ($result === false) {
                    error_log("Error al enviar notificación a admin {$adminId} - " . error_get_last()['message']);
                    continue; // Continuar con el siguiente admin
                }
                
                $response = json_decode($result, true);
                if (!isset($response['ok']) || !$response['ok']) {
                    error_log("Telegram API error para admin {$adminId}: " . ($response['description'] ?? 'Unknown'));
                    continue; // Continuar con el siguiente admin
                }
                
                error_log("Notificación enviada a admin {$adminId}");
                
                // Enviar foto (captura)
                $url = $apiUrl . 'sendPhoto';
                $data = [
                    'chat_id' => $adminId,
                    'photo' => $fileId,
                    'caption' => "📸 Captura de pago #{$pagoId}\n\nPara aprobar: `/aprobar {$pagoId}`",
                    'parse_mode' => 'Markdown'
                ];
                
                $options = [
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-Type: application/json',
                        'content' => json_encode($data)
                    ]
                ];
                
                $context = stream_context_create($options);
                $result = file_get_contents($url, false, $context);
                
                if ($result === false) {
                    error_log("Error al enviar foto a admin {$adminId} - " . error_get_last()['message']);
                    continue; // Continuar con el siguiente admin
                }
                
                $response = json_decode($result, true);
                if (!isset($response['ok']) || !$response['ok']) {
                    error_log("Telegram API error (foto) para admin {$adminId}: " . ($response['description'] ?? 'Unknown'));
                    continue;
                }
                
                error_log("Foto enviada a admin {$adminId}");
                
            } catch (Exception $e) {
                error_log("Excepción al notificar admin {$adminId}: " . $e->getMessage());
                continue; // Continuar con el siguiente admin
            }
        }
        
    } catch(PDOException $e) {
        error_log("ERROR al obtener datos para notificar admins: " . $e->getMessage());
    }
}

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
    
    $respuesta .= "💰 *DETALLES DE COMPRA*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "• Paquete: {$pago['paquete']}\n";
    $respuesta .= "• Créditos: {$pago['creditos']}\n";
    $respuesta .= "• Monto: {$pago['monto']} {$pago['moneda']}\n";
    $respuesta .= "• Método: {$pago['metodo_pago']}\n\n";
    
    $estadoEmoji = [
        'pendiente' => '⏳',
        'esperando_captura' => '📸',
        'captura_enviada' => '📸',
        'aprobado' => '✅',
        'rechazado' => '❌'
    ];
    
    $emoji = isset($estadoEmoji[$pago['estado']]) ? $estadoEmoji[$pago['estado']] : '📋';
    
    $respuesta .= "📊 *ESTADO*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "{$emoji} " . strtoupper($pago['estado']) . "\n";
    
    if (!empty($pago['fecha_captura'])) {
        $respuesta .= "📸 Captura: " . date('d/m H:i', strtotime($pago['fecha_captura'])) . "\n";
    }
    
    if (!empty($pago['fecha_aprobacion'])) {
        $respuesta .= "✅ Aprobado: " . date('d/m H:i', strtotime($pago['fecha_aprobacion'])) . "\n";
    }
    
    if (!empty($pago['motivo_rechazo'])) {
        $respuesta .= "\n📝 Motivo rechazo:\n{$pago['motivo_rechazo']}";
    }
    
    $respuesta .= "\n\n⚡ *ACCIONES*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    if (in_array($pago['estado'], ['captura_enviada', 'esperando_captura'])) {
        $respuesta .= "`/aprobar {$pago['id']}`\n";
        $respuesta .= "`/rechazar {$pago['id']} [motivo]`";
    } else {
        $respuesta .= "Estado final - No hay acciones disponibles";
    }
    
    enviarMensaje($chatId, $respuesta);
    
    // Enviar captura si existe
    if (!empty($pago['captura_file_id'])) {
        $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendPhoto";
        
        $data = [
            'chat_id' => $chatId,
            'photo' => $pago['captura_file_id'],
            'caption' => "📸 Captura del pago #{$pago['id']}"
        ];
        
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($data)
            ]
        ];
        
        $context = stream_context_create($options);
        @file_get_contents($url, false, $context);
    }
}

function comandoAprobarPagoMejorado($chatId, $texto, $adminId, $db, $sistemaPagos) {
    $partes = explode(' ', $texto, 3);
    
    if (count($partes) < 2) {
        enviarMensaje($chatId, "❌ Formato: `/aprobar [ID] [notas opcionales]`\n\nEjemplo: `/aprobar 5`");
        return;
    }
    
    $pagoId = intval($partes[1]);
    $notas = isset($partes[2]) ? $partes[2] : null;
    
    $resultado = $sistemaPagos->aprobarPago($pagoId, $adminId, $notas);
    
    if ($resultado['exito']) {
        $respuesta = "✅ *PAGO APROBADO*\n\n";
        $respuesta .= "🆔 Pago ID: #{$pagoId}\n";
        $respuesta .= "💎 Créditos agregados: {$resultado['creditos_agregados']}\n\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "✅ Usuario notificado\n";
        $respuesta .= "✅ Créditos acreditados\n";
        $respuesta .= "✅ Transacción registrada";
        
        enviarMensaje($chatId, $respuesta);
    } else {
        enviarMensaje($chatId, "❌ Error: " . $resultado['mensaje']);
    }
}

function comandoRechazarPagoMejorado($chatId, $texto, $adminId, $db, $sistemaPagos) {
    $partes = explode(' ', $texto, 3);
    
    if (count($partes) < 3) {
        enviarMensaje($chatId, "❌ *Formato incorrecto*\n\n*Uso:*\n`/rechazar [ID] [motivo]`\n\n*Ejemplos:*\n`/rechazar 5 Monto incorrecto`\n`/rechazar 5 El comprobante no coincide con el monto`");
        return;
    }
    
    $pagoId = intval($partes[1]);
    $motivo = trim($partes[2]);
    
    if (empty($motivo)) {
        enviarMensaje($chatId, "❌ *El motivo no puede estar vacío*\n\n*Ejemplo:*\n`/rechazar {$pagoId} Monto incorrecto`");
        return;
    }
    
    if ($pagoId <= 0) {
        enviarMensaje($chatId, "❌ *ID de pago inválido*\n\n*Ejemplo:*\n`/rechazar 5 Monto incorrecto`");
        return;
    }
    
    error_log("=== RECHAZANDO PAGO ===");
    error_log("Pago ID: {$pagoId}");
    error_log("Admin ID: {$adminId}");
    error_log("Motivo: {$motivo}");
    
    $resultado = $sistemaPagos->rechazarPago($pagoId, $adminId, $motivo);
    
    if ($resultado['exito']) {
        $respuesta = "❌ *PAGO RECHAZADO*\n\n";
        $respuesta .= "🆔 Pago ID: #{$pagoId}\n";
        $respuesta .= "📝 Motivo: {$motivo}\n\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "✅ Usuario notificado\n";
        $respuesta .= "✅ Estado actualizado\n";
        $respuesta .= "✅ Motivo guardado";
        
        enviarMensaje($chatId, $respuesta);
        
        error_log("Pago #{$pagoId} rechazado exitosamente");
    } else {
        enviarMensaje($chatId, "❌ Error: " . $resultado['mensaje']);
        error_log("Error al rechazar pago #{$pagoId}: " . $resultado['mensaje']);
    }
}

?>
