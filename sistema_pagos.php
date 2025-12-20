<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * SISTEMA COMPLETO DE PAGOS - BOT TELEGRAM IMEI
 * VERSIÓN CON FIXES CRÍTICOS APLICADOS
 * ═══════════════════════════════════════════════════════════════
 */

class SistemaPagos {
    private $db;
    private $botToken;
    private $adminIds;
    
    // Paquetes de créditos disponibles
    private $paquetes = [
        'basico' => [
            'nombre' => '🥉 BÁSICO',
            'creditos' => 50,
            'precio_usd' => 5.00,
            'precio_pen' => 20.00,
            'descripcion' => '50 generaciones de IMEI',
            'ahorro' => 0
        ],
        'estandar' => [
            'nombre' => '🥈 ESTÁNDAR',
            'creditos' => 100,
            'precio_usd' => 9.00,
            'precio_pen' => 35.00,
            'descripcion' => '100 generaciones de IMEI',
            'ahorro' => 10
        ],
        'premium' => [
            'nombre' => '🥇 PREMIUM',
            'creditos' => 200,
            'precio_usd' => 16.00,
            'precio_pen' => 60.00,
            'descripcion' => '200 generaciones de IMEI',
            'ahorro' => 20
        ],
        'mega' => [
            'nombre' => '💎 MEGA',
            'creditos' => 500,
            'precio_usd' => 35.00,
            'precio_pen' => 130.00,
            'descripcion' => '500 generaciones de IMEI',
            'ahorro' => 30
        ],
        'ultra' => [
            'nombre' => '👑 ULTRA',
            'creditos' => 1000,
            'precio_usd' => 60.00,
            'precio_pen' => 220.00,
            'descripcion' => '1000 generaciones de IMEI',
            'ahorro' => 40
        ]
    ];
    
    // Métodos de pago disponibles
    private $metodosPago = [
        'yape' => [
            'nombre' => 'Yape (Perú)',
            'emoji' => '💳',
            'numero' => '924780239',
            'titular' => 'VICTOR AGUILAR',
            'monedas' => ['PEN'],
            'instrucciones' => 'Envía el pago al número y sube tu captura'
        ],
        'plin' => [
            'nombre' => 'Plin (Perú)',
            'emoji' => '💰',
            'numero' => '924780239',
            'titular' => 'VICTOR AGUILAR',
            'monedas' => ['PEN'],
            'instrucciones' => 'Envía el pago al número y sube tu captura'
        ],
        'paypal' => [
            'nombre' => 'PayPal',
            'emoji' => '🌐',
            'email' => 'pagos@chamogsm.com',
            'monedas' => ['USD'],
            'instrucciones' => 'Envía a través de PayPal y comparte el ID de transacción'
        ],
        'binance' => [
            'nombre' => 'Binance Pay',
            'emoji' => '₿',
            'id' => '123456789',
            'monedas' => ['USDT'],
            'instrucciones' => 'Paga con Binance Pay y envía captura'
        ],
        'usdt' => [
            'nombre' => 'USDT (TRC20)',
            'emoji' => '💎',
            'address' => 'TXx...xyz',
            'monedas' => ['USDT'],
            'instrucciones' => 'Envía USDT a la dirección y comparte el hash'
        ]
    ];
    
    public function __construct($database, $botToken, $adminIds) {
        $this->db = $database;
        $this->botToken = $botToken;
        $this->adminIds = $adminIds;
    }
    
    // ═══════════════════════════════════════
    // GESTIÓN DE PAQUETES
    // ═══════════════════════════════════════
    
    public function obtenerPaquetes($moneda = 'USD') {
        return $this->paquetes;
    }
    
    public function obtenerPaquete($id) {
        return isset($this->paquetes[$id]) ? $this->paquetes[$id] : null;
    }
    
    public function mostrarPaquetes($moneda = 'PEN') {
        $mensaje = "╔═══════════════════════════╗\n";
        $mensaje .= "║  💰 PAQUETES DE CRÉDITOS  ║\n";
        $mensaje .= "╚═══════════════════════════╝\n\n";
        
        foreach ($this->paquetes as $id => $paquete) {
            $precio = $moneda === 'PEN' ? $paquete['precio_pen'] : $paquete['precio_usd'];
            $simbolo = $moneda === 'PEN' ? 'S/.' : '$';
            
            $mensaje .= "{$paquete['nombre']}\n";
            $mensaje .= "├ 💎 {$paquete['creditos']} créditos\n";
            $mensaje .= "├ 💵 {$simbolo}{$precio} {$moneda}\n";
            
            if ($paquete['ahorro'] > 0) {
                $mensaje .= "├ 🎁 Ahorra {$paquete['ahorro']}%\n";
            }
            
            $mensaje .= "└ 📱 {$paquete['descripcion']}\n\n";
        }
        
        return $mensaje;
    }
    
    // ═══════════════════════════════════════
    // MÉTODOS DE PAGO
    // ═══════════════════════════════════════
    
    public function obtenerMetodosPago($moneda = null) {
        if ($moneda) {
            return array_filter($this->metodosPago, function($metodo) use ($moneda) {
                return in_array($moneda, $metodo['monedas']);
            });
        }
        return $this->metodosPago;
    }
    
    public function mostrarMetodosPago($moneda = 'PEN') {
        $metodos = $this->obtenerMetodosPago($moneda);
        
        $mensaje = "╔═══════════════════════════╗\n";
        $mensaje .= "║   💳 MÉTODOS DE PAGO      ║\n";
        $mensaje .= "╚═══════════════════════════╝\n\n";
        
        foreach ($metodos as $id => $metodo) {
            $mensaje .= "{$metodo['emoji']} *{$metodo['nombre']}*\n";
            
            if (isset($metodo['numero'])) {
                $mensaje .= "📱 Número: `{$metodo['numero']}`\n";
                $mensaje .= "👤 Titular: {$metodo['titular']}\n";
            }
            
            if (isset($metodo['email'])) {
                $mensaje .= "📧 Email: `{$metodo['email']}`\n";
            }
            
            if (isset($metodo['address'])) {
                $mensaje .= "🔗 Dirección: `{$metodo['address']}`\n";
            }
            
            if (isset($metodo['id'])) {
                $mensaje .= "🆔 ID: `{$metodo['id']}`\n";
            }
            
            $mensaje .= "\n";
        }
        
        return $mensaje;
    }
    
    // ═══════════════════════════════════════
    // CREACIÓN DE SOLICITUD DE PAGO
    // FIX CRÍTICO #4: Validar que el usuario existe
    // ═══════════════════════════════════════
    
    public function crearSolicitudPago($telegramId, $paqueteId, $metodoPago, $moneda) {
        // FIX CRÍTICO #4: Verificar que el usuario existe antes de crear el pago
        $usuario = $this->db->getUsuario($telegramId);
        if (!$usuario) {
            error_log("ERROR: Intento de crear pago para usuario inexistente: {$telegramId}");
            return ['exito' => false, 'mensaje' => 'Usuario no encontrado. Usa /start primero.'];
        }
        
        $paquete = $this->obtenerPaquete($paqueteId);
        
        if (!$paquete) {
            return ['exito' => false, 'mensaje' => 'Paquete no válido'];
        }
        
        $precio = $moneda === 'PEN' ? $paquete['precio_pen'] : $paquete['precio_usd'];
        
        // Crear registro en base de datos
        $sql = "INSERT INTO pagos_pendientes 
                (telegram_id, paquete, creditos, monto, moneda, metodo_pago, estado, fecha_solicitud)
                VALUES 
                (:telegram_id, :paquete, :creditos, :monto, :moneda, :metodo_pago, 'pendiente', NOW())";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([
                ':telegram_id' => $telegramId,
                ':paquete' => $paqueteId,
                ':creditos' => $paquete['creditos'],
                ':monto' => $precio,
                ':moneda' => $moneda,
                ':metodo_pago' => $metodoPago
            ]);
            
            $pagoId = $this->db->conn->lastInsertId();
            
            // Notificar a administradores
            $this->notificarNuevaSolicitud($pagoId, $telegramId, $paquete, $precio, $moneda, $metodoPago);
            
            return [
                'exito' => true,
                'pago_id' => $pagoId,
                'mensaje' => 'Solicitud creada exitosamente'
            ];
            
        } catch(PDOException $e) {
            error_log("Error al crear solicitud de pago: " . $e->getMessage());
            return ['exito' => false, 'mensaje' => 'Error al crear solicitud: ' . $e->getMessage()];
        }
    }
    
    // ═══════════════════════════════════════
    // GESTIÓN DE CAPTURAS
    // ═══════════════════════════════════════
    
    public function guardarCaptura($pagoId, $fileId, $caption = null) {
        $sql = "UPDATE pagos_pendientes 
                SET captura_file_id = :file_id, 
                    captura_caption = :caption,
                    fecha_captura = NOW(),
                    estado = 'captura_enviada'
                WHERE id = :pago_id";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([
                ':file_id' => $fileId,
                ':caption' => $caption,
                ':pago_id' => $pagoId
            ]);
            
            // Notificar a administradores
            $this->notificarCapturaRecibida($pagoId);
            
            return true;
        } catch(PDOException $e) {
            error_log("Error al guardar captura: " . $e->getMessage());
            return false;
        }
    }
    
    public function obtenerPagoPendiente($telegramId) {
        $sql = "SELECT * FROM pagos_pendientes 
                WHERE telegram_id = :telegram_id 
                AND estado IN ('pendiente', 'esperando_captura')
                ORDER BY fecha_solicitud DESC 
                LIMIT 1";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([':telegram_id' => $telegramId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            error_log("Error al obtener pago pendiente: " . $e->getMessage());
            return false;
        }
    }
    
    // ═══════════════════════════════════════
    // VALIDACIÓN Y APROBACIÓN
    // ═══════════════════════════════════════
    
    public function aprobarPago($pagoId, $adminId, $notasAdmin = null) {
        error_log("=== INICIANDO APROBACIÓN DE PAGO ===");
        error_log("Pago ID: {$pagoId}");
        error_log("Admin ID: {$adminId}");
        
        // Obtener información del pago
        $sql = "SELECT * FROM pagos_pendientes WHERE id = :id";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([':id' => $pagoId]);
            $pago = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$pago) {
                error_log("ERROR: Pago no encontrado");
                return ['exito' => false, 'mensaje' => 'Pago no encontrado'];
            }
            
            error_log("Pago encontrado - Estado actual: {$pago['estado']}");
            
            if ($pago['estado'] === 'aprobado') {
                error_log("ADVERTENCIA: Pago ya aprobado");
                return ['exito' => false, 'mensaje' => 'Este pago ya fue aprobado'];
            }
            
            // Iniciar transacción
            $this->db->conn->beginTransaction();
            
            // Actualizar estado del pago
            $sql = "UPDATE pagos_pendientes 
                    SET estado = 'aprobado', 
                        fecha_aprobacion = NOW(),
                        admin_id = :admin_id,
                        notas_admin = :notas
                    WHERE id = :id";
            
            $stmt = $this->db->conn->prepare($sql);
            $result = $stmt->execute([
                ':id' => $pagoId,
                ':admin_id' => $adminId,
                ':notas' => $notasAdmin
            ]);
            
            if (!$result) {
                throw new Exception("Error al actualizar estado del pago");
            }
            
            error_log("Estado del pago actualizado a 'aprobado'");
            
            // Agregar créditos al usuario
            $creditosAgregados = $this->db->actualizarCreditos($pago['telegram_id'], $pago['creditos'], 'add');
            
            if (!$creditosAgregados) {
                throw new Exception("Error al agregar créditos al usuario");
            }
            
            error_log("Créditos agregados: {$pago['creditos']}");
            
            // Registrar transacción
            $this->db->registrarTransaccion(
                $pago['telegram_id'],
                'compra',
                $pago['creditos'],
                "Compra de {$pago['paquete']} - {$pago['monto']} {$pago['moneda']} - Pago #{$pagoId}",
                $adminId
            );
            
            error_log("Transacción registrada");
            
            $this->db->conn->commit();
            
            error_log("Transacción completada exitosamente");
            
            // Notificar al usuario
            $this->notificarPagoAprobado($pago);
            
            return [
                'exito' => true,
                'mensaje' => 'Pago aprobado exitosamente',
                'creditos_agregados' => $pago['creditos']
            ];
            
        } catch(Exception $e) {
            $this->db->conn->rollBack();
            error_log("ERROR en aprobarPago: " . $e->getMessage());
            return ['exito' => false, 'mensaje' => 'Error al aprobar pago: ' . $e->getMessage()];
        }
    }
    
    public function rechazarPago($pagoId, $adminId, $motivo) {
        error_log("=== INICIANDO RECHAZO DE PAGO ===");
        error_log("Pago ID: {$pagoId}");
        error_log("Admin ID: {$adminId}");
        error_log("Motivo: {$motivo}");
        
        // Obtener información del pago
        $sql = "SELECT * FROM pagos_pendientes WHERE id = :id";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([':id' => $pagoId]);
            $pago = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$pago) {
                error_log("ERROR: Pago no encontrado");
                return ['exito' => false, 'mensaje' => 'Pago no encontrado'];
            }
            
            error_log("Pago encontrado - Estado actual: '{$pago['estado']}'");
            error_log("Telegram ID: {$pago['telegram_id']}");
            error_log("Paquete: {$pago['paquete']}");
            
            // Verificar si ya fue rechazado
            if ($pago['estado'] === 'rechazado') {
                error_log("ADVERTENCIA: Pago ya rechazado");
                return ['exito' => false, 'mensaje' => 'Este pago ya fue rechazado'];
            }
            
            // Actualizar estado del pago
            $sql = "UPDATE pagos_pendientes 
                    SET estado = 'rechazado',
                        fecha_rechazo = NOW(),
                        admin_id = :admin_id,
                        motivo_rechazo = :motivo
                    WHERE id = :id";
            
            $stmt = $this->db->conn->prepare($sql);
            $result = $stmt->execute([
                ':id' => $pagoId,
                ':admin_id' => $adminId,
                ':motivo' => $motivo
            ]);
            
            if (!$result) {
                error_log("ERROR: No se pudo ejecutar UPDATE");
                return ['exito' => false, 'mensaje' => 'Error al ejecutar actualización'];
            }
            
            $filasAfectadas = $stmt->rowCount();
            error_log("Filas afectadas por UPDATE: {$filasAfectadas}");
            
            if ($filasAfectadas === 0) {
                error_log("ADVERTENCIA: No se actualizó ninguna fila");
                return ['exito' => false, 'mensaje' => 'No se pudo actualizar el pago'];
            }
            
            error_log("Estado actualizado a 'rechazado'");
            
            // Notificar al usuario
            $this->notificarPagoRechazado($pago, $motivo);
            
            error_log("Usuario notificado");
            error_log("=== RECHAZO COMPLETADO EXITOSAMENTE ===");
            
            return [
                'exito' => true, 
                'mensaje' => 'Pago rechazado exitosamente'
            ];
            
        } catch(PDOException $e) {
            error_log("ERROR SQL en rechazarPago: " . $e->getMessage());
            error_log("SQL State: " . $e->getCode());
            return ['exito' => false, 'mensaje' => 'Error de base de datos: ' . $e->getMessage()];
        } catch(Exception $e) {
            error_log("ERROR GENERAL en rechazarPago: " . $e->getMessage());
            return ['exito' => false, 'mensaje' => 'Error: ' . $e->getMessage()];
        }
    }
    
    // ═══════════════════════════════════════
    // SISTEMA DE CUPONES/DESCUENTOS
    // ═══════════════════════════════════════
    
    public function crearCupon($codigo, $descuentoPorcentaje, $usoMaximo = 1, $fechaExpiracion = null) {
        $sql = "INSERT INTO cupones 
                (codigo, descuento_porcentaje, uso_maximo, fecha_expiracion, activo)
                VALUES 
                (:codigo, :descuento, :uso_maximo, :expiracion, 1)";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([
                ':codigo' => strtoupper($codigo),
                ':descuento' => $descuentoPorcentaje,
                ':uso_maximo' => $usoMaximo,
                ':expiracion' => $fechaExpiracion
            ]);
            
            return true;
        } catch(PDOException $e) {
            error_log("Error al crear cupón: " . $e->getMessage());
            return false;
        }
    }
    
    public function validarCupon($codigo, $telegramId) {
        $sql = "SELECT * FROM cupones 
                WHERE codigo = :codigo 
                AND activo = 1
                AND (fecha_expiracion IS NULL OR fecha_expiracion > NOW())
                AND uso_actual < uso_maximo";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([':codigo' => strtoupper($codigo)]);
            $cupon = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$cupon) {
                return ['valido' => false, 'mensaje' => 'Cupón no válido o expirado'];
            }
            
            // Verificar si el usuario ya usó este cupón
            $sql = "SELECT COUNT(*) as usos FROM pagos_pendientes 
                    WHERE telegram_id = :telegram_id 
                    AND cupon_codigo = :codigo";
            
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([
                ':telegram_id' => $telegramId,
                ':codigo' => $cupon['codigo']
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['usos'] > 0) {
                return ['valido' => false, 'mensaje' => 'Ya has usado este cupón'];
            }
            
            return [
                'valido' => true,
                'descuento' => $cupon['descuento_porcentaje'],
                'codigo' => $cupon['codigo']
            ];
            
        } catch(PDOException $e) {
            error_log("Error al validar cupón: " . $e->getMessage());
            return ['valido' => false, 'mensaje' => 'Error al validar cupón'];
        }
    }
    
    public function aplicarCupon($pagoId, $cuponCodigo) {
        $sql = "UPDATE pagos_pendientes 
                SET cupon_codigo = :codigo 
                WHERE id = :pago_id";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([
                ':codigo' => $cuponCodigo,
                ':pago_id' => $pagoId
            ]);
            
            // Incrementar uso del cupón
            $sql = "UPDATE cupones SET uso_actual = uso_actual + 1 WHERE codigo = :codigo";
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([':codigo' => $cuponCodigo]);
            
            return true;
        } catch(PDOException $e) {
            error_log("Error al aplicar cupón: " . $e->getMessage());
            return false;
        }
    }
    
    // ═══════════════════════════════════════
    // NOTIFICACIONES (CON FIX CRÍTICO #7)
    // ═══════════════════════════════════════
    
    private function notificarNuevaSolicitud($pagoId, $telegramId, $paquete, $precio, $moneda, $metodoPago) {
        $usuario = $this->db->getUsuario($telegramId);
        $username = $usuario['username'] ? "@{$usuario['username']}" : $usuario['first_name'];
        
        $mensaje = "🔔 *NUEVA SOLICITUD DE PAGO*\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "🆔 Pago ID: #{$pagoId}\n";
        $mensaje .= "👤 Usuario: {$username}\n";
        $mensaje .= "📱 Telegram ID: `{$telegramId}`\n";
        $mensaje .= "📦 Paquete: {$paquete['nombre']}\n";
        $mensaje .= "💎 Créditos: {$paquete['creditos']}\n";
        $mensaje .= "💰 Monto: {$precio} {$moneda}\n";
        $mensaje .= "💳 Método: {$metodoPago}\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "⏳ Esperando captura de pago...";
        
        foreach ($this->adminIds as $adminId) {
            $this->enviarMensaje($adminId, $mensaje);
        }
    }
    
    private function notificarCapturaRecibida($pagoId) {
        $sql = "SELECT p.*, u.username, u.first_name 
                FROM pagos_pendientes p
                LEFT JOIN usuarios u ON p.telegram_id = u.telegram_id
                WHERE p.id = :id";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([':id' => $pagoId]);
            $pago = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$pago) return;
            
            $username = $pago['username'] ? "@{$pago['username']}" : $pago['first_name'];
            
            $mensaje = "📸 *CAPTURA DE PAGO RECIBIDA*\n\n";
            $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $mensaje .= "🆔 Pago ID: #{$pagoId}\n";
            $mensaje .= "👤 Usuario: {$username}\n";
            $mensaje .= "📦 Paquete: {$pago['paquete']}\n";
            $mensaje .= "💰 Monto: {$pago['monto']} {$pago['moneda']}\n";
            $mensaje .= "💳 Método: {$pago['metodo_pago']}\n\n";
            
            if ($pago['captura_caption']) {
                $mensaje .= "📝 Nota: {$pago['captura_caption']}\n\n";
            }
            
            $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $mensaje .= "⚡ Comandos:\n";
            $mensaje .= "`/aprobar {$pagoId}` - Aprobar pago\n";
            $mensaje .= "`/rechazar {$pagoId} [motivo]` - Rechazar pago\n";
            $mensaje .= "`/detalle {$pagoId}` - Ver detalles";
            
            foreach ($this->adminIds as $adminId) {
                // Enviar mensaje
                $this->enviarMensaje($adminId, $mensaje);
                
                // Reenviar la captura
                if ($pago['captura_file_id']) {
                    $this->enviarFoto($adminId, $pago['captura_file_id']);
                }
            }
        } catch(PDOException $e) {
            error_log("Error al notificar captura recibida: " . $e->getMessage());
        }
    }
    
    private function notificarPagoAprobado($pago) {
        $mensaje = "✅ *¡PAGO APROBADO!*\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "🎉 Tu pago ha sido aprobado\n\n";
        $mensaje .= "📦 Paquete: {$pago['paquete']}\n";
        $mensaje .= "💎 Créditos agregados: *{$pago['creditos']}*\n";
        $mensaje .= "💰 Monto: {$pago['monto']} {$pago['moneda']}\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "✨ Ya puedes usar tus créditos\n";
        $mensaje .= "🚀 → *📱 Generar IMEI*\n\n";
        $mensaje .= "¡Gracias por tu compra! 🙏";
        
        $this->enviarMensaje($pago['telegram_id'], $mensaje);
    }
    
    private function notificarPagoRechazado($pago, $motivo) {
        $mensaje = "❌ *PAGO RECHAZADO*\n\n";
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "Tu pago ha sido rechazado\n\n";
        $mensaje .= "📦 Paquete: {$pago['paquete']}\n";
        $mensaje .= "💰 Monto: {$pago['monto']} {$pago['moneda']}\n\n";
        
        if ($motivo) {
            $mensaje .= "📝 *Motivo:*\n{$motivo}\n\n";
        }
        
        $mensaje .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $mensaje .= "💬 Si tienes dudas, contacta:\n";
        $mensaje .= "📞 @CHAMOGSM\n\n";
        $mensaje .= "Puedes intentar realizar el pago nuevamente";
        
        $this->enviarMensaje($pago['telegram_id'], $mensaje);
    }
    
    // ═══════════════════════════════════════
    // ESTADÍSTICAS Y REPORTES
    // ═══════════════════════════════════════
    
    public function obtenerEstadisticasPagos() {
        $stats = [];
        
        try {
            // Total de pagos
            $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN estado = 'aprobado' THEN 1 ELSE 0 END) as aprobados,
                    SUM(CASE WHEN estado = 'rechazado' THEN 1 ELSE 0 END) as rechazados,
                    SUM(CASE WHEN estado IN ('pendiente', 'captura_enviada') THEN 1 ELSE 0 END) as pendientes,
                    SUM(CASE WHEN estado = 'aprobado' THEN monto ELSE 0 END) as ingresos_usd,
                    SUM(CASE WHEN estado = 'aprobado' THEN creditos ELSE 0 END) as creditos_vendidos
                    FROM pagos_pendientes";
            
            $stmt = $this->db->conn->query($sql);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Pagos por método
            $sql = "SELECT metodo_pago, COUNT(*) as total 
                    FROM pagos_pendientes 
                    WHERE estado = 'aprobado'
                    GROUP BY metodo_pago";
            
            $stmt = $this->db->conn->query($sql);
            $stats['por_metodo'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Pagos recientes
            $sql = "SELECT DATE(fecha_solicitud) as fecha, COUNT(*) as total, SUM(monto) as ingresos
                    FROM pagos_pendientes 
                    WHERE estado = 'aprobado'
                    AND fecha_solicitud >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    GROUP BY DATE(fecha_solicitud)
                    ORDER BY fecha DESC";
            
            $stmt = $this->db->conn->query($sql);
            $stats['ultimos_30_dias'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $stats;
            
        } catch(PDOException $e) {
            error_log("Error al obtener estadísticas: " . $e->getMessage());
            return [];
        }
    }
    
    public function generarReporteMensual($mes = null, $anio = null) {
        if (!$mes) $mes = date('m');
        if (!$anio) $anio = date('Y');
        
        $sql = "SELECT 
                p.*,
                u.username,
                u.first_name
                FROM pagos_pendientes p
                LEFT JOIN usuarios u ON p.telegram_id = u.telegram_id
                WHERE MONTH(p.fecha_solicitud) = :mes
                AND YEAR(p.fecha_solicitud) = :anio
                AND p.estado = 'aprobado'
                ORDER BY p.fecha_aprobacion DESC";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([':mes' => $mes, ':anio' => $anio]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            error_log("Error al generar reporte mensual: " . $e->getMessage());
            return [];
        }
    }
    
    // ═══════════════════════════════════════
    // FUNCIONES AUXILIARES (CON FIX CRÍTICO #7)
    // ═══════════════════════════════════════
    
    private function enviarMensaje($chatId, $texto, $parseMode = 'Markdown') {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        
        $data = [
            'chat_id' => $chatId,
            'text' => $texto,
            'parse_mode' => $parseMode
        ];
        
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($data)
            ]
        ];
        
        $context = stream_context_create($options);
        
        // FIX CRÍTICO #7: No silenciar errores y validar respuesta
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            error_log("Error al enviar mensaje a chat {$chatId}: " . ($error['message'] ?? 'Unknown error'));
            return false;
        }
        
        $result = json_decode($response, true);
        if (!isset($result['ok']) || !$result['ok']) {
            error_log("Telegram API error para chat {$chatId}: " . ($result['description'] ?? 'Unknown error'));
            return false;
        }
        
        return $response;
    }
    
    private function enviarFoto($chatId, $fileId) {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendPhoto";
        
        $data = [
            'chat_id' => $chatId,
            'photo' => $fileId
        ];
        
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($data)
            ]
        ];
        
        $context = stream_context_create($options);
        
        // FIX CRÍTICO #7: No silenciar errores y validar respuesta
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            error_log("Error al enviar foto a chat {$chatId}: " . ($error['message'] ?? 'Unknown error'));
            return false;
        }
        
        $result = json_decode($response, true);
        if (!isset($result['ok']) || !$result['ok']) {
            error_log("Telegram API error (foto) para chat {$chatId}: " . ($result['description'] ?? 'Unknown error'));
            return false;
        }
        
        return $response;
    }
    
    public function obtenerDetallePago($pagoId) {
        $sql = "SELECT p.*, u.username, u.first_name, u.creditos as creditos_actuales
                FROM pagos_pendientes p
                LEFT JOIN usuarios u ON p.telegram_id = u.telegram_id
                WHERE p.id = :id";
        
        try {
            $stmt = $this->db->conn->prepare($sql);
            $stmt->execute([':id' => $pagoId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            error_log("Error al obtener detalle de pago: " . $e->getMessage());
            return false;
        }
    }
}
?>
