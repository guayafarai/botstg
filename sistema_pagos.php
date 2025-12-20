<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * SISTEMA COMPLETO DE PAGOS - VERSIÓN CORREGIDA
 * ═══════════════════════════════════════════════════════════════
 */

class SistemaPagos {
    private $db;
    private $botToken;
    private $adminIds;
    private $rateLimiter = [];
    
    // Paquetes de créditos
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
    
    // Métodos de pago
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
            'instrucciones' => 'Envía a través de PayPal y comparte el ID'
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
            'instrucciones' => 'Envía USDT a la dirección'
        ]
    ];
    
    public function __construct($database, $botToken, $adminIds) {
        $this->db = $database;
        $this->botToken = $botToken;
        $this->adminIds = is_array($adminIds) ? $adminIds : [$adminIds];
    }
    
    /**
     * Validar rate limit
     */
    private function checkRateLimit($userId, $action, $limit = 5, $window = 60) {
        $key = "{$userId}_{$action}";
        $now = time();
        
        if (!isset($this->rateLimiter[$key])) {
            $this->rateLimiter[$key] = [];
        }
        
        // Limpiar requests antiguos
        $this->rateLimiter[$key] = array_filter(
            $this->rateLimiter[$key],
            function($timestamp) use ($now, $window) {
                return ($now - $timestamp) < $window;
            }
        );
        
        if (count($this->rateLimiter[$key]) >= $limit) {
            return false;
        }
        
        $this->rateLimiter[$key][] = $now;
        return true;
    }
    
    /**
     * Obtener paquete por ID
     */
    public function obtenerPaquete($id) {
        return isset($this->paquetes[$id]) ? $this->paquetes[$id] : null;
    }
    
    /**
     * Obtener todos los paquetes
     */
    public function obtenerPaquetes() {
        return $this->paquetes;
    }
    
    /**
     * Obtener métodos de pago
     */
    public function obtenerMetodosPago($moneda = null) {
        if ($moneda) {
            return array_filter($this->metodosPago, function($metodo) use ($moneda) {
                return in_array($moneda, $metodo['monedas']);
            });
        }
        return $this->metodosPago;
    }
    
    /**
     * Mostrar paquetes formateados
     */
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
    
    /**
     * Crear solicitud de pago con validaciones
     */
    public function crearSolicitudPago($telegramId, $paqueteId, $metodoPago, $moneda) {
        // Validar rate limit
        if (!$this->checkRateLimit($telegramId, 'crear_pago', 3, 300)) {
            return [
                'exito' => false, 
                'mensaje' => 'Demasiadas solicitudes. Espera un momento.'
            ];
        }
        
        // Validar que el usuario existe
        $usuario = $this->db->getUsuario($telegramId);
        if (!$usuario) {
            return [
                'exito' => false, 
                'mensaje' => 'Usuario no encontrado. Usa /start primero.'
            ];
        }
        
        // Validar paquete
        $paquete = $this->obtenerPaquete($paqueteId);
        if (!$paquete) {
            return ['exito' => false, 'mensaje' => 'Paquete no válido'];
        }
        
        // Validar método de pago
        if (!isset($this->metodosPago[$metodoPago])) {
            return ['exito' => false, 'mensaje' => 'Método de pago no válido'];
        }
        
        // Validar moneda
        if (!in_array($moneda, ['PEN', 'USD', 'USDT'])) {
            return ['exito' => false, 'mensaje' => 'Moneda no válida'];
        }
        
        $precio = $moneda === 'PEN' ? $paquete['precio_pen'] : $paquete['precio_usd'];
        
        // Crear registro con transacción
        try {
            $this->db->beginTransaction();
            
            $sql = "INSERT INTO pagos_pendientes 
                    (telegram_id, paquete, creditos, monto, moneda, metodo_pago, estado, fecha_solicitud)
                    VALUES 
                    (:telegram_id, :paquete, :creditos, :monto, :moneda, :metodo_pago, 'pendiente', NOW())";
            
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':telegram_id' => (int)$telegramId,
                ':paquete' => $paqueteId,
                ':creditos' => (int)$paquete['creditos'],
                ':monto' => (float)$precio,
                ':moneda' => $moneda,
                ':metodo_pago' => $metodoPago
            ]);
            
            $pagoId = $conn->lastInsertId();
            
            $this->db->commit();
            
            // Notificar a administradores
            $this->notificarNuevaSolicitud($pagoId, $telegramId, $paquete, $precio, $moneda, $metodoPago);
            
            return [
                'exito' => true,
                'pago_id' => $pagoId,
                'mensaje' => 'Solicitud creada exitosamente'
            ];
            
        } catch(PDOException $e) {
            $this->db->rollBack();
            logSecure("Error al crear solicitud de pago: " . $e->getMessage(), 'ERROR');
            return ['exito' => false, 'mensaje' => 'Error al crear solicitud'];
        }
    }
    
    /**
     * Aprobar pago con validaciones y transacción
     */
    public function aprobarPago($pagoId, $adminId, $notasAdmin = null) {
        logSecure("Iniciando aprobación de pago #{$pagoId} por admin {$adminId}", 'INFO');
        
        try {
            $this->db->beginTransaction();
            
            // Obtener y bloquear el pago
            $conn = $this->db->getConnection();
            $sql = "SELECT * FROM pagos_pendientes WHERE id = :id FOR UPDATE";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => (int)$pagoId]);
            $pago = $stmt->fetch();
            
            if (!$pago) {
                $this->db->rollBack();
                return ['exito' => false, 'mensaje' => 'Pago no encontrado'];
            }
            
            if ($pago['estado'] === 'aprobado') {
                $this->db->rollBack();
                return ['exito' => false, 'mensaje' => 'Este pago ya fue aprobado'];
            }
            
            // Actualizar estado del pago
            $sql = "UPDATE pagos_pendientes 
                    SET estado = 'aprobado', 
                        fecha_aprobacion = NOW(),
                        admin_id = :admin_id,
                        notas_admin = :notas
                    WHERE id = :id";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':id' => (int)$pagoId,
                ':admin_id' => (int)$adminId,
                ':notas' => $notasAdmin
            ]);
            
            // Agregar créditos
            $creditosAgregados = $this->db->actualizarCreditos(
                $pago['telegram_id'], 
                $pago['creditos'], 
                'add'
            );
            
            if (!$creditosAgregados) {
                throw new Exception("Error al agregar créditos");
            }
            
            // Registrar transacción
            $this->db->registrarTransaccion(
                $pago['telegram_id'],
                'compra',
                $pago['creditos'],
                "Compra de {$pago['paquete']} - {$pago['monto']} {$pago['moneda']} - Pago #{$pagoId}",
                $adminId
            );
            
            $this->db->commit();
            
            // Notificar al usuario
            $this->notificarPagoAprobado($pago);
            
            logSecure("Pago #{$pagoId} aprobado exitosamente", 'INFO');
            
            return [
                'exito' => true,
                'mensaje' => 'Pago aprobado exitosamente',
                'creditos_agregados' => $pago['creditos']
            ];
            
        } catch(Exception $e) {
            $this->db->rollBack();
            logSecure("Error al aprobar pago #{$pagoId}: " . $e->getMessage(), 'ERROR');
            return ['exito' => false, 'mensaje' => 'Error al aprobar pago'];
        }
    }
    
    /**
     * Rechazar pago
     */
    public function rechazarPago($pagoId, $adminId, $motivo) {
        logSecure("Iniciando rechazo de pago #{$pagoId} por admin {$adminId}", 'INFO');
        
        if (empty($motivo)) {
            return ['exito' => false, 'mensaje' => 'Debes especificar un motivo'];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Obtener y bloquear el pago
            $conn = $this->db->getConnection();
            $sql = "SELECT * FROM pagos_pendientes WHERE id = :id FOR UPDATE";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => (int)$pagoId]);
            $pago = $stmt->fetch();
            
            if (!$pago) {
                $this->db->rollBack();
                return ['exito' => false, 'mensaje' => 'Pago no encontrado'];
            }
            
            if ($pago['estado'] === 'rechazado') {
                $this->db->rollBack();
                return ['exito' => false, 'mensaje' => 'Este pago ya fue rechazado'];
            }
            
            // Actualizar estado
            $sql = "UPDATE pagos_pendientes 
                    SET estado = 'rechazado',
                        fecha_rechazo = NOW(),
                        admin_id = :admin_id,
                        motivo_rechazo = :motivo
                    WHERE id = :id";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':id' => (int)$pagoId,
                ':admin_id' => (int)$adminId,
                ':motivo' => htmlspecialchars($motivo, ENT_QUOTES, 'UTF-8')
            ]);
            
            $this->db->commit();
            
            // Notificar al usuario
            $this->notificarPagoRechazado($pago, $motivo);
            
            logSecure("Pago #{$pagoId} rechazado exitosamente", 'INFO');
            
            return ['exito' => true, 'mensaje' => 'Pago rechazado exitosamente'];
            
        } catch(Exception $e) {
            $this->db->rollBack();
            logSecure("Error al rechazar pago #{$pagoId}: " . $e->getMessage(), 'ERROR');
            return ['exito' => false, 'mensaje' => 'Error al rechazar pago'];
        }
    }
    
    /**
     * Obtener detalle de pago
     */
    public function obtenerDetallePago($pagoId) {
        $sql = "SELECT p.*, u.username, u.first_name, u.creditos as creditos_actuales
                FROM pagos_pendientes p
                LEFT JOIN usuarios u ON p.telegram_id = u.telegram_id
                WHERE p.id = :id";
        
        try {
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => (int)$pagoId]);
            return $stmt->fetch();
        } catch(PDOException $e) {
            logSecure("Error al obtener detalle de pago: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Enviar mensaje de Telegram con manejo de errores
     */
    private function enviarMensaje($chatId, $texto, $parseMode = 'Markdown') {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        
        $data = [
            'chat_id' => $chatId,
            'text' => $texto,
            'parse_mode' => $parseMode
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
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false || $httpCode !== 200) {
            logSecure("Error al enviar mensaje a {$chatId}: " . $error, 'ERROR');
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
     * Notificar nueva solicitud
     */
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
    
    /**
     * Notificar pago aprobado
     */
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
    
    /**
     * Notificar pago rechazado
     */
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
}

?>
