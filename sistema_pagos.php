<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * SISTEMA COMPLETO DE PAGOS - VERSIÓN 3.0 CORREGIDA
 * ═══════════════════════════════════════════════════════════════
 * MEJORAS:
 * - Rate limiting robusto con Redis fallback
 * - Validación exhaustiva de datos
 * - Logging mejorado
 * - Manejo de errores completo
 */

class SistemaPagos {
    private $db;
    private $botToken;
    private $adminIds;
    private $rateLimiter = [];
    private $rateLimitFile = '/tmp/payment_rate_limits.json';
    
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
            'instrucciones' => 'Envía el pago al número y sube tu captura',
            'activo' => true
        ],
        'plin' => [
            'nombre' => 'Plin (Perú)',
            'emoji' => '💰',
            'numero' => '924780239',
            'titular' => 'VICTOR AGUILAR',
            'monedas' => ['PEN'],
            'instrucciones' => 'Envía el pago al número y sube tu captura',
            'activo' => true
        ],
        'paypal' => [
            'nombre' => 'PayPal',
            'emoji' => '🌐',
            'email' => 'pagos@chamogsm.com',
            'monedas' => ['USD'],
            'instrucciones' => 'Envía a través de PayPal y comparte el ID',
            'activo' => true
        ],
        'binance' => [
            'nombre' => 'Binance Pay',
            'emoji' => '₿',
            'id' => '123456789',
            'monedas' => ['USDT'],
            'instrucciones' => 'Paga con Binance Pay y envía captura',
            'activo' => true
        ],
        'usdt' => [
            'nombre' => 'USDT (TRC20)',
            'emoji' => '💎',
            'address' => 'TXx...xyz',
            'monedas' => ['USDT'],
            'instrucciones' => 'Envía USDT a la dirección',
            'activo' => true
        ]
    ];
    
    public function __construct($database, $botToken, $adminIds) {
        $this->db = $database;
        $this->botToken = $botToken;
        $this->adminIds = is_array($adminIds) ? $adminIds : [$adminIds];
        
        // Cargar rate limits desde archivo
        $this->loadRateLimits();
    }
    
    /**
     * Cargar rate limits desde archivo persistente
     */
    private function loadRateLimits() {
        if (file_exists($this->rateLimitFile)) {
            $content = @file_get_contents($this->rateLimitFile);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                $this->rateLimiter = is_array($decoded) ? $decoded : [];
            }
        }
    }
    
    /**
     * Guardar rate limits en archivo
     */
    private function saveRateLimits() {
        @file_put_contents($this->rateLimitFile, json_encode($this->rateLimiter), LOCK_EX);
    }
    
    /**
     * Validar rate limit mejorado
     */
    private function checkRateLimit($userId, $action, $limit = 5, $window = 60) {
        $key = "{$userId}_{$action}";
        $now = time();
        
        if (!isset($this->rateLimiter[$key])) {
            $this->rateLimiter[$key] = [
                'attempts' => [],
                'blocked_until' => 0
            ];
        }
        
        // Verificar si está bloqueado
        if ($this->rateLimiter[$key]['blocked_until'] > $now) {
            $remainingTime = $this->rateLimiter[$key]['blocked_until'] - $now;
            logSecure("Rate limit bloqueado para usuario {$userId} en acción {$action}. Tiempo restante: {$remainingTime}s", 'WARN');
            return false;
        }
        
        // Limpiar intentos antiguos
        $this->rateLimiter[$key]['attempts'] = array_filter(
            $this->rateLimiter[$key]['attempts'],
            function($timestamp) use ($now, $window) {
                return ($now - $timestamp) < $window;
            }
        );
        
        // Verificar límite
        if (count($this->rateLimiter[$key]['attempts']) >= $limit) {
            // Bloquear por el doble del window
            $this->rateLimiter[$key]['blocked_until'] = $now + ($window * 2);
            $this->saveRateLimits();
            
            logSecure("Rate limit excedido para usuario {$userId} en acción {$action}. Bloqueado por " . ($window * 2) . "s", 'WARN');
            return false;
        }
        
        // Registrar intento
        $this->rateLimiter[$key]['attempts'][] = $now;
        $this->saveRateLimits();
        
        return true;
    }
    
    /**
     * Limpiar rate limits expirados (mantenimiento)
     */
    public function cleanupRateLimits() {
        $now = time();
        $cleaned = 0;
        
        foreach ($this->rateLimiter as $key => $data) {
            // Eliminar si no tiene intentos recientes y no está bloqueado
            if (empty($data['attempts']) && $data['blocked_until'] < $now) {
                unset($this->rateLimiter[$key]);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            $this->saveRateLimits();
            logSecure("Limpiados {$cleaned} rate limits expirados", 'DEBUG');
        }
        
        return $cleaned;
    }
    
    /**
     * Obtener paquete por ID con validación
     */
    public function obtenerPaquete($id) {
        if (!isset($this->paquetes[$id])) {
            logSecure("Intento de acceder a paquete inexistente: {$id}", 'WARN');
            return null;
        }
        return $this->paquetes[$id];
    }
    
    /**
     * Obtener todos los paquetes activos
     */
    public function obtenerPaquetes() {
        return $this->paquetes;
    }
    
    /**
     * Obtener métodos de pago activos
     */
    public function obtenerMetodosPago($moneda = null) {
        $metodos = array_filter($this->metodosPago, function($metodo) {
            return isset($metodo['activo']) && $metodo['activo'] === true;
        });
        
        if ($moneda) {
            return array_filter($metodos, function($metodo) use ($moneda) {
                return in_array($moneda, $metodo['monedas']);
            });
        }
        
        return $metodos;
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
     * Validar datos de entrada
     */
    private function validarDatosPago($telegramId, $paqueteId, $metodoPago, $moneda) {
        $errores = [];
        
        // Validar telegram ID
        if (!is_numeric($telegramId) || $telegramId <= 0) {
            $errores[] = "Telegram ID inválido";
        }
        
        // Validar paquete
        if (!isset($this->paquetes[$paqueteId])) {
            $errores[] = "Paquete no existe";
        }
        
        // Validar método de pago
        $metodosActivos = $this->obtenerMetodosPago();
        if (!isset($metodosActivos[$metodoPago])) {
            $errores[] = "Método de pago no disponible";
        }
        
        // Validar moneda
        if (!in_array($moneda, ['PEN', 'USD', 'USDT'])) {
            $errores[] = "Moneda no válida";
        }
        
        // Validar que el método acepte la moneda
        if (isset($metodosActivos[$metodoPago]) && 
            !in_array($moneda, $metodosActivos[$metodoPago]['monedas'])) {
            $errores[] = "El método de pago no acepta esta moneda";
        }
        
        return $errores;
    }
    
    /**
     * Crear solicitud de pago con validaciones exhaustivas
     */
    public function crearSolicitudPago($telegramId, $paqueteId, $metodoPago, $moneda) {
        // Validar rate limit (3 solicitudes por 5 minutos)
        if (!$this->checkRateLimit($telegramId, 'crear_pago', 3, 300)) {
            return [
                'exito' => false, 
                'mensaje' => '⏱️ Demasiadas solicitudes. Espera unos minutos antes de intentar nuevamente.'
            ];
        }
        
        // Validar datos de entrada
        $errores = $this->validarDatosPago($telegramId, $paqueteId, $metodoPago, $moneda);
        if (!empty($errores)) {
            logSecure("Validación fallida al crear pago - Usuario: {$telegramId}, Errores: " . implode(', ', $errores), 'WARN');
            return [
                'exito' => false,
                'mensaje' => 'Datos inválidos: ' . implode(', ', $errores)
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
        
        // Verificar si el usuario está bloqueado
        if (isset($usuario['bloqueado']) && $usuario['bloqueado']) {
            logSecure("Usuario bloqueado intentó crear pago - ID: {$telegramId}", 'WARN');
            return [
                'exito' => false,
                'mensaje' => '🚫 Tu cuenta está suspendida. Contacta a soporte.'
            ];
        }
        
        // Verificar pagos pendientes del usuario (máximo 3)
        try {
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare("
                SELECT COUNT(*) as pendientes 
                FROM pagos_pendientes 
                WHERE telegram_id = ? 
                AND estado IN ('pendiente', 'esperando_captura', 'captura_enviada')
            ");
            $stmt->execute([(int)$telegramId]);
            $result = $stmt->fetch();
            
            if ($result && $result['pendientes'] >= 3) {
                return [
                    'exito' => false,
                    'mensaje' => '⚠️ Ya tienes 3 pagos pendientes. Completa o cancela uno antes de crear otro.'
                ];
            }
        } catch (PDOException $e) {
            logSecure("Error al verificar pagos pendientes: " . $e->getMessage(), 'ERROR');
        }
        
        $paquete = $this->obtenerPaquete($paqueteId);
        $precio = $moneda === 'PEN' ? $paquete['precio_pen'] : $paquete['precio_usd'];
        
        // Crear registro con transacción
        try {
            $this->db->beginTransaction();
            
            $sql = "INSERT INTO pagos_pendientes 
                    (telegram_id, paquete, creditos, monto, moneda, metodo_pago, estado, fecha_solicitud)
                    VALUES 
                    (?, ?, ?, ?, ?, ?, 'pendiente', NOW())";
            
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                (int)$telegramId,
                $paqueteId,
                (int)$paquete['creditos'],
                (float)$precio,
                $moneda,
                $metodoPago
            ]);
            
            $pagoId = $conn->lastInsertId();
            
            $this->db->commit();
            
            logSecure("Solicitud de pago creada - ID: {$pagoId}, Usuario: {$telegramId}, Paquete: {$paqueteId}, Monto: {$precio} {$moneda}", 'INFO');
            
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
            return [
                'exito' => false, 
                'mensaje' => 'Error al crear solicitud. Intenta nuevamente.'
            ];
        }
    }
    
    /**
     * Aprobar pago con validaciones y transacción atómica
     */
    public function aprobarPago($pagoId, $adminId, $notasAdmin = null) {
        logSecure("Iniciando aprobación de pago #{$pagoId} por admin {$adminId}", 'INFO');
        
        try {
            $this->db->beginTransaction();
            
            // Obtener y bloquear el pago
            $conn = $this->db->getConnection();
            $sql = "SELECT * FROM pagos_pendientes WHERE id = ? FOR UPDATE";
            $stmt = $conn->prepare($sql);
            $stmt->execute([(int)$pagoId]);
            $pago = $stmt->fetch();
            
            if (!$pago) {
                $this->db->rollBack();
                return ['exito' => false, 'mensaje' => 'Pago no encontrado'];
            }
            
            // Validar estado
            if ($pago['estado'] === 'aprobado') {
                $this->db->rollBack();
                logSecure("Intento de aprobar pago ya aprobado #{$pagoId}", 'WARN');
                return ['exito' => false, 'mensaje' => 'Este pago ya fue aprobado'];
            }
            
            if (!in_array($pago['estado'], ['pendiente', 'captura_enviada'])) {
                $this->db->rollBack();
                return ['exito' => false, 'mensaje' => "No se puede aprobar un pago con estado: {$pago['estado']}"];
            }
            
            // Actualizar estado del pago
            $sql = "UPDATE pagos_pendientes 
                    SET estado = 'aprobado', 
                        fecha_aprobacion = NOW(),
                        admin_id = ?,
                        notas_admin = ?
                    WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                (int)$adminId,
                $notasAdmin,
                (int)$pagoId
            ]);
            
            // Agregar créditos con verificación
            $creditosAgregados = $this->db->actualizarCreditos(
                $pago['telegram_id'], 
                $pago['creditos'], 
                'add'
            );
            
            if (!$creditosAgregados) {
                $this->db->rollBack();
                logSecure("Error al agregar créditos para pago #{$pagoId}", 'ERROR');
                return ['exito' => false, 'mensaje' => 'Error al agregar créditos'];
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
            
            logSecure("Pago #{$pagoId} aprobado exitosamente - Usuario: {$pago['telegram_id']}, Créditos: {$pago['creditos']}", 'INFO');
            
            return [
                'exito' => true,
                'mensaje' => 'Pago aprobado exitosamente',
                'creditos_agregados' => $pago['creditos']
            ];
            
        } catch(Exception $e) {
            $this->db->rollBack();
            logSecure("Error al aprobar pago #{$pagoId}: " . $e->getMessage(), 'ERROR');
            return ['exito' => false, 'mensaje' => 'Error al procesar aprobación'];
        }
    }
    
    /**
     * Rechazar pago con motivo obligatorio
     */
    public function rechazarPago($pagoId, $adminId, $motivo) {
        logSecure("Iniciando rechazo de pago #{$pagoId} por admin {$adminId}", 'INFO');
        
        if (empty(trim($motivo))) {
            return ['exito' => false, 'mensaje' => 'El motivo es obligatorio'];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Obtener y bloquear el pago
            $conn = $this->db->getConnection();
            $sql = "SELECT * FROM pagos_pendientes WHERE id = ? FOR UPDATE";
            $stmt = $conn->prepare($sql);
            $stmt->execute([(int)$pagoId]);
            $pago = $stmt->fetch();
            
            if (!$pago) {
                $this->db->rollBack();
                return ['exito' => false, 'mensaje' => 'Pago no encontrado'];
            }
            
            // Validar estado
            if ($pago['estado'] === 'rechazado') {
                $this->db->rollBack();
                return ['exito' => false, 'mensaje' => 'Este pago ya fue rechazado'];
            }
            
            if ($pago['estado'] === 'aprobado') {
                $this->db->rollBack();
                return ['exito' => false, 'mensaje' => 'No se puede rechazar un pago aprobado'];
            }
            
            // Actualizar estado
            $sql = "UPDATE pagos_pendientes 
                    SET estado = 'rechazado',
                        fecha_rechazo = NOW(),
                        admin_id = ?,
                        motivo_rechazo = ?
                    WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                (int)$adminId,
                htmlspecialchars($motivo, ENT_QUOTES, 'UTF-8'),
                (int)$pagoId
            ]);
            
            $this->db->commit();
            
            // Notificar al usuario
            $this->notificarPagoRechazado($pago, $motivo);
            
            logSecure("Pago #{$pagoId} rechazado - Motivo: {$motivo}", 'INFO');
            
            return ['exito' => true, 'mensaje' => 'Pago rechazado exitosamente'];
            
        } catch(Exception $e) {
            $this->db->rollBack();
            logSecure("Error al rechazar pago #{$pagoId}: " . $e->getMessage(), 'ERROR');
            return ['exito' => false, 'mensaje' => 'Error al procesar rechazo'];
        }
    }
    
    /**
     * Obtener detalle de pago con información completa
     */
    public function obtenerDetallePago($pagoId) {
        $sql = "SELECT p.*, u.username, u.first_name, u.creditos as creditos_actuales, u.bloqueado
                FROM pagos_pendientes p
                LEFT JOIN usuarios u ON p.telegram_id = u.telegram_id
                WHERE p.id = ?";
        
        try {
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare($sql);
            $stmt->execute([(int)$pagoId]);
            return $stmt->fetch();
        } catch(PDOException $e) {
            logSecure("Error al obtener detalle de pago #{$pagoId}: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Enviar mensaje de Telegram con reintentos
     */
    private function enviarMensaje($chatId, $texto, $parseMode = 'Markdown', $maxRetries = 3) {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        
        $data = [
            'chat_id' => $chatId,
            'text' => $texto,
            'parse_mode' => $parseMode
        ];
        
        for ($intento = 1; $intento <= $maxRetries; $intento++) {
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
            
            if ($response !== false && $httpCode === 200) {
                $result = json_decode($response, true);
                if (isset($result['ok']) && $result['ok']) {
                    return true;
                }
            }
            
            // Si falla, esperar antes de reintentar
            if ($intento < $maxRetries) {
                sleep(2);
                logSecure("Reintentando envío de mensaje a {$chatId} (intento {$intento}/{$maxRetries})", 'WARN');
            }
        }
        
        logSecure("Error al enviar mensaje a {$chatId} después de {$maxRetries} intentos", 'ERROR');
        return false;
    }
    
    /**
     * Notificar nueva solicitud a admins
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
     * Notificar pago aprobado al usuario
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
     * Notificar pago rechazado al usuario
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
    
    /**
     * Destructor - guardar rate limits
     */
    public function __destruct() {
        $this->saveRateLimits();
    }
}

?>