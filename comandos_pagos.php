<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * COMANDOS DE PAGOS PARA BOT TELEGRAM - VERSIÓN CORREGIDA
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
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "💰 *DETALLES DE COMPRA*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $respuesta .= "• Paquete: {$pago['paquete']}\n";
    $respuesta .= "• Créditos: {$pago['creditos']}\n";
    $respuesta .= "• Monto: {$pago['monto']} {$pago['moneda']}\n";
    $respuesta .= "• Método: {$pago['metodo_pago']}\n";
    
    // FIX: Verificar si la clave existe antes de acceder
    if (!empty($pago['cupon_codigo'])) {
        $respuesta .= "• Cupón: {$pago['cupon_codigo']}\n";
    }
    
    $respuesta .= "\n━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "📊 *ESTADO*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $estadoEmoji = [
        'pendiente' => '⏳',
        'esperando_captura' => '📸',
        'captura_enviada' => '📸',
        'aprobado' => '✅',
        'rechazado' => '❌'
    ];
    
    $emoji = $estadoEmoji[$pago['estado']] ?? '📋';
    $respuesta .= "• Estado: {$emoji} " . strtoupper($pago['estado']) . "\n";
    
    // FIX: Verificar existencia de fechas opcionales
    if (!empty($pago['fecha_captura'])) {
        $respuesta .= "• Captura: " . date('d/m/Y H:i', strtotime($pago['fecha_captura'])) . "\n";
    }
    
    if (!empty($pago['fecha_aprobacion'])) {
        $respuesta .= "• Aprobado: " . date('d/m/Y H:i', strtotime($pago['fecha_aprobacion'])) . "\n";
    }
    
    if (!empty($pago['fecha_rechazo'])) {
        $respuesta .= "• Rechazado: " . date('d/m/Y H:i', strtotime($pago['fecha_rechazo'])) . "\n";
    }
    
    // FIX: Verificar campos de texto opcionales
    if (!empty($pago['motivo_rechazo'])) {
        $respuesta .= "\n📝 Motivo rechazo:\n{$pago['motivo_rechazo']}\n";
    }
    
    if (!empty($pago['notas_admin'])) {
        $respuesta .= "\n💬 Notas admin:\n{$pago['notas_admin']}\n";
    }
    
    $respuesta .= "\n━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "⚡ *ACCIONES*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    if (in_array($pago['estado'], ['captura_enviada', 'esperando_captura'])) {
        $respuesta .= "`/aprobar {$pago['id']}` - Aprobar\n";
        $respuesta .= "`/rechazar {$pago['id']} motivo` - Rechazar";
    } elseif ($pago['estado'] === 'pendiente') {
        $respuesta .= "⏳ Esperando captura del usuario";
    } elseif ($pago['estado'] === 'aprobado') {
        $respuesta .= "✅ Pago ya procesado";
    } else {
        $respuesta .= "❌ Pago rechazado";
    }
    
    enviarMensaje($chatId, $respuesta);
    
    // Si hay captura, enviarla
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
        enviarMensaje($chatId, "❌ Formato: `/aprobar [ID] [notas opcionales]`\n\nEjemplo: `/aprobar 5 Todo correcto`");
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
        enviarMensaje($chatId, "❌ Formato: `/rechazar [ID] [motivo]`\n\nEjemplo: `/rechazar 5 Monto incorrecto`");
        return;
    }
    
    $pagoId = intval($partes[1]);
    $motivo = $partes[2];
    
    $resultado = $sistemaPagos->rechazarPago($pagoId, $adminId, $motivo);
    
    if ($resultado['exito']) {
        $respuesta = "❌ *PAGO RECHAZADO*\n\n";
        $respuesta .= "🆔 Pago ID: #{$pagoId}\n";
        $respuesta .= "📝 Motivo: {$motivo}\n\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "✅ Usuario notificado\n";
        $respuesta .= "✅ Estado actualizado";
        
        enviarMensaje($chatId, $respuesta);
    } else {
        enviarMensaje($chatId, "❌ Error: " . $resultado['mensaje']);
    }
}

function comandoCrearCupon($chatId, $texto, $adminId, $db, $sistemaPagos) {
    $partes = explode(' ', $texto);
    
    if (count($partes) < 3) {
        enviarMensaje($chatId, "❌ Formato: `/crear_cupon CODIGO DESCUENTO [USO_MAXIMO] [FECHA_EXP]`\n\nEjemplo: `/crear_cupon NAVIDAD25 25 100 2025-12-31`");
        return;
    }
    
    $codigo = strtoupper($partes[1]);
    $descuento = intval($partes[2]);
    $usoMaximo = isset($partes[3]) ? intval($partes[3]) : 1;
    $fechaExp = isset($partes[4]) ? $partes[4] : null;
    
    if ($sistemaPagos->crearCupon($codigo, $descuento, $usoMaximo, $fechaExp)) {
        $respuesta = "✅ *CUPÓN CREADO*\n\n";
        $respuesta .= "🎟️ Código: `{$codigo}`\n";
        $respuesta .= "💰 Descuento: {$descuento}%\n";
        $respuesta .= "🔢 Uso máximo: {$usoMaximo}\n";
        
        if ($fechaExp) {
            $respuesta .= "📅 Expira: {$fechaExp}\n";
        }
        
        $respuesta .= "\n━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "✅ Cupón listo para usar\n";
        $respuesta .= "📢 Compártelo con tus usuarios";
        
        enviarMensaje($chatId, $respuesta);
    } else {
        enviarMensaje($chatId, "❌ Error al crear cupón. Posiblemente ya existe.");
    }
}

function comandoReporteMensual($chatId, $db, $sistemaPagos) {
    $reporte = $sistemaPagos->generarReporteMensual();
    
    if (empty($reporte)) {
        enviarMensaje($chatId, "📊 No hay datos para este mes");
        return;
    }
    
    $totalCreditos = 0;
    $totalUSD = 0;
    $totalPEN = 0;
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║  📊 REPORTE MENSUAL       ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    
    $respuesta .= "📅 " . date('F Y') . "\n\n";
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    foreach ($reporte as $pago) {
        $fecha = date('d/m', strtotime($pago['fecha_aprobacion']));
        $username = $pago['username'] ? "@{$pago['username']}" : $pago['first_name'];
        
        $respuesta .= "🗓️ {$fecha} - {$username}\n";
        $respuesta .= "   💎 {$pago['creditos']} créditos\n";
        $respuesta .= "   💰 {$pago['monto']} {$pago['moneda']}\n\n";
        
        $totalCreditos += $pago['creditos'];
        
        if ($pago['moneda'] === 'USD') {
            $totalUSD += $pago['monto'];
        } else {
            $totalPEN += $pago['monto'];
        }
    }
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "📈 *TOTALES*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $respuesta .= "• Pagos: " . count($reporte) . "\n";
    $respuesta .= "• Créditos: {$totalCreditos}\n";
    $respuesta .= "• USD: \${$totalUSD}\n";
    $respuesta .= "• PEN: S/.{$totalPEN}";
    
    enviarMensaje($chatId, $respuesta);
}

?>━━\n\n";
    $respuesta .= "💡 *¿CÓMO COMPRAR?*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $respuesta .= "1️⃣ Selecciona tu paquete\n";
    $respuesta .= "2️⃣ Elige método de pago\n";
    $respuesta .= "3️⃣ Realiza la transferencia\n";
    $respuesta .= "4️⃣ Envía tu captura\n";
    $respuesta .= "5️⃣ ¡Listo! Créditos acreditados\n\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
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
            ],
            [
                ['text' => '🎟️ Tengo un cupón', 'callback_data' => 'ingresar_cupon']
            ],
            [
                ['text' => '🔙 Volver', 'callback_data' => 'menu_principal']
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
        enviarMensaje($chatId, "❌ Error al crear solicitud: " . $resultado['mensaje']);
        return;
    }
    
    $pagoId = $resultado['pago_id'];
    
    // IMPORTANTE: Actualizar el estado del pago a 'esperando_captura'
    try {
        $sql = "UPDATE pagos_pendientes SET estado = 'esperando_captura' WHERE id = :pago_id";
        $stmt = $db->conn->prepare($sql);
        $stmt->execute([':pago_id' => $pagoId]);
    } catch(PDOException $e) {
        error_log("Error al actualizar estado: " . $e->getMessage());
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
    
    $respuesta .= "⏰ Tienes 72 horas para completar";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '❌ Cancelar pago', 'callback_data' => 'cancelar_pago_' . $pagoId]
            ],
            [
                ['text' => '❓ Ayuda', 'callback_data' => 'ayuda_pago']
            ]
        ]
    ];
    
    enviarMensaje($chatId, $respuesta, 'Markdown', json_encode($keyboard));
}

/**
 * VERSIÓN CORREGIDA - Procesar captura de pago recibida
 */
function procesarCapturaPago($chatId, $telegramId, $message, $db, $sistemaPagos, $estados) {
    // Obtener estado actual
    $estado = $estados->getEstado($chatId);
    
    // Log para debug
    error_log("=== PROCESANDO CAPTURA ===");
    error_log("Usuario: {$telegramId}");
    error_log("Estado: " . json_encode($estado));
    
    // Verificar que el usuario esté esperando captura
    if (!$estado || $estado['estado'] != 'esperando_pago') {
        error_log("Usuario NO está esperando pago. Estado actual: " . ($estado ? $estado['estado'] : 'NULL'));
        return false; // No está esperando captura
    }
    
    // Verificar que sea una foto
    if (!isset($message['photo'])) {
        enviarMensaje($chatId, "❌ Por favor envía una *imagen* (captura de pantalla)");
        return true;
    }
    
    $pagoId = $estado['datos']['pago_id'];
    
    error_log("Pago ID: {$pagoId}");
    
    // Verificar que el pago exista
    $sql = "SELECT * FROM pagos_pendientes WHERE id = :pago_id AND telegram_id = :telegram_id";
    try {
        $stmt = $db->conn->prepare($sql);
        $stmt->execute([
            ':pago_id' => $pagoId,
            ':telegram_id' => $telegramId
        ]);
        $pago = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pago) {
            error_log("ERROR: Pago #{$pagoId} no encontrado para usuario {$telegramId}");
            enviarMensaje($chatId, "❌ Error: No se encontró el pago.\n\n*Solución:*\nInicia el proceso nuevamente con:\n💰 *Comprar Créditos*");
            $estados->limpiarEstado($chatId);
            return true;
        }
        
        error_log("Pago encontrado. Estado actual: {$pago['estado']}");
        
        // Verificar estado del pago
        if (!in_array($pago['estado'], ['pendiente', 'esperando_captura'])) {
            enviarMensaje($chatId, "❌ Este pago ya fue procesado.\n\nEstado: *{$pago['estado']}*");
            $estados->limpiarEstado($chatId);
            return true;
        }
        
    } catch(PDOException $e) {
        error_log("ERROR BD al buscar pago: " . $e->getMessage());
        enviarMensaje($chatId, "❌ Error de base de datos.\n\nContacta soporte: @CHAMOGSM");
        return true;
    }
    
    // Obtener el file_id de la foto de mayor resolución
    $photos = $message['photo'];
    $photo = end($photos);
    $fileId = $photo['file_id'];
    
    // Caption opcional
    $caption = isset($message['caption']) ? $message['caption'] : null;
    
    error_log("File ID: {$fileId}");
    error_log("Caption: " . ($caption ?: 'NULL'));
    
    // GUARDAR CAPTURA DIRECTAMENTE EN LA BD
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
        
        error_log("Resultado UPDATE: " . ($resultado ? 'TRUE' : 'FALSE'));
        error_log("Filas afectadas: {$filasAfectadas}");
        
        if ($resultado && $filasAfectadas > 0) {
            // Limpiar estado
            $estados->limpiarEstado($chatId);
            
            // Notificar a administradores
            notificarCapturaRecibidaDirecta($pagoId, $db, $fileId, BOT_TOKEN, ADMIN_IDS);
            
            // Mensaje de éxito
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
            error_log("ERROR: No se actualizó ninguna fila");
            enviarMensaje($chatId, "❌ Error al guardar captura.\n\n*Debug:*\n- Pago ID: {$pagoId}\n- File ID recibido: ✓\n- BD conectada: ✓\n- Filas afectadas: {$filasAfectadas}\n\nContacta: @CHAMOGSM");
            return true;
        }
        
    } catch(PDOException $e) {
        error_log("ERROR SQL al guardar captura: " . $e->getMessage());
        enviarMensaje($chatId, "❌ Error de base de datos:\n\n`{$e->getMessage()}`\n\nContacta: @CHAMOGSM");
        return true;
    }
}

/**
 * Función auxiliar para notificar directamente
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
        
        if (!$pago) return;
        
        $username = $pago['username'] ? "@{$pago['username']}" : $pago['first_name'];
        
        $mensaje = "📸 *CAPTURA DE PAGO RECIBIDA*\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "🆔 Pago ID: #{$pagoId}\n";
        $mensaje .= "👤 Usuario: {$username}\n";
        $mensaje .= "📱 ID: `{$pago['telegram_id']}`\n";
        $mensaje .= "📦 Paquete: {$pago['paquete']}\n";
        $mensaje .= "💰 Monto: {$pago['monto']} {$pago['moneda']}\n";
        $mensaje .= "💳 Método: {$pago['metodo_pago']}\n\n";
        
        if ($pago['captura_caption']) {
            $mensaje .= "📝 Nota: {$pago['captura_caption']}\n\n";
        }
        
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "⚡ *COMANDOS:*\n";
        $mensaje .= "`/detalle {$pagoId}` - Ver detalles\n";
        $mensaje .= "`/aprobar {$pagoId}` - Aprobar pago\n";
        $mensaje .= "`/rechazar {$pagoId}` - Rechazar pago";
        
        $apiUrl = "https://api.telegram.org/bot{$botToken}/";
        
        foreach ($adminIds as $adminId) {
            // Enviar mensaje
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
            @file_get_contents($url, false, $context);
            
            // Enviar foto
            $url = $apiUrl . 'sendPhoto';
            $data = [
                'chat_id' => $adminId,
                'photo' => $fileId,
                'caption' => "📸 Captura del pago #{$pagoId}"
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
        
    } catch(PDOException $e) {
        error_log("Error al notificar admins: " . $e->getMessage());
    }
}

function comandoValidarCupon($chatId, $telegramId, $codigo, $db, $sistemaPagos) {
    $resultado = $sistemaPagos->validarCupon($codigo, $telegramId);
    
    if ($resultado['valido']) {
        $respuesta = "✅ *¡CUPÓN VÁLIDO!*\n\n";
        $respuesta .= "🎟️ Código: `{$resultado['codigo']}`\n";
        $respuesta .= "💰 Descuento: {$resultado['descuento']}%\n\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "El descuento se aplicará en tu próxima compra\n\n";
        $respuesta .= "💡 Selecciona un paquete para continuar";
        
        enviarMensaje($chatId, $respuesta);
    } else {
        $respuesta = "❌ *CUPÓN NO VÁLIDO*\n\n";
        $respuesta .= "📝 Motivo: {$resultado['mensaje']}\n\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $respuesta .= "💡 Verifica:\n";
        $respuesta .= "• Código escrito correctamente\n";
        $respuesta .= "• Cupón no expirado\n";
        $respuesta .= "• No lo hayas usado antes\n\n";
        $respuesta .= "📞 Dudas: @CHAMOGSM";
        
        enviarMensaje($chatId, $respuesta);
    }
}

// ═══════════════════════════════════════════════════════════════
// COMANDOS DE ADMINISTRACIÓN DE PAGOS
// ═══════════════════════════════════════════════════════════════

function comandoPanelPagosAdmin($chatId, $db, $sistemaPagos) {
    $stats = $sistemaPagos->obtenerEstadisticasPagos();
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║  👑 PANEL DE PAGOS 👑     ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    
    $respuesta .= "📊 *ESTADÍSTICAS GENERALES*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $respuesta .= "💳 Total pagos: *{$stats['total']}*\n";
    $respuesta .= "✅ Aprobados: *{$stats['aprobados']}*\n";
    $respuesta .= "❌ Rechazados: *{$stats['rechazados']}*\n";
    $respuesta .= "⏳ Pendientes: *{$stats['pendientes']}*\n\n";
    
    $respuesta .= "💰 Ingresos: *\${$stats['ingresos_usd']}*\n";
    $respuesta .= "💎 Créditos vendidos: *{$stats['creditos_vendidos']}*\n\n";
    
    if (!empty($stats['por_metodo'])) {
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $respuesta .= "💳 *POR MÉTODO DE PAGO*\n";
        $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        foreach ($stats['por_metodo'] as $metodo) {
            $respuesta .= "• {$metodo['metodo_pago']}: {$metodo['total']}\n";
        }
        $respuesta .= "\n";
    }
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $respuesta .= "🔧 *COMANDOS DISPONIBLES*\n\n";
    $respuesta .= "`/pagos_pendientes` - Ver pagos\n";
    $respuesta .= "`/detalle [ID]` - Ver detalles\n";
    $respuesta .= "`/aprobar [ID]` - Aprobar\n";
    $respuesta .= "`/rechazar [ID]` - Rechazar\n";
    $respuesta .= "`/crear_cupon` - Crear cupón\n";
    $respuesta .= "`/reporte_mes` - Reporte mensual";
    
    enviarMensaje($chatId, $respuesta);
}

function comandoDetallePago($chatId, $pagoId, $db, $sistemaPagos) {
    $pago = $sistemaPagos->obtenerDetallePago($pagoId);
    
    if (!$pago) {
        enviarMensaje($chatId, "❌ Pago no encontrado");
        return;
    }
    
    $username = $pago['username'] ? "@{$pago['username']}" : $pago['first_name'];
    
    $respuesta = "╔═══════════════════════════╗\n";
    $respuesta .= "║   📋 DETALLE DE PAGO      ║\n";
    $respuesta .= "╚═══════════════════════════╝\n\n";
    
    $respuesta .= "🆔 *ID:* #{$pago['id']}\n";
    $respuesta .= "📅 *Fecha:* " . date('d/m/Y H:i', strtotime($pago['fecha_solicitud'])) . "\n\n";
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $respuesta .= "👤 *USUARIO*\n";
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $respuesta .= "• Nombre: {$pago['first_name']}\n";
    $respuesta .= "• Usuario: {$username}\n";
    $respuesta .= "• ID: `{$pago['telegram_id']}`\n";
    $respuesta .= "• Créditos actuales: {$pago['creditos_actuales']}\n\n";
    
    $respuesta .= "━━━━━━━━━━━━━━━━━━━━━━