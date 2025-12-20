<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * CLASE DATABASE MEJORADA - VERSIÓN SEGURA
 * ═══════════════════════════════════════════════════════════════
 */

class Database {
    private $conn;
    private $inTransaction = false;
    
    public function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_PERSISTENT => false
                ]
            );
        } catch(PDOException $e) {
            logSecure("Error de conexión DB: " . $e->getMessage(), 'ERROR');
            throw new Exception("Error de conexión a base de datos");
        }
    }
    
    /**
     * Iniciar transacción
     */
    public function beginTransaction() {
        if (!$this->inTransaction) {
            $this->conn->beginTransaction();
            $this->inTransaction = true;
        }
    }
    
    /**
     * Confirmar transacción
     */
    public function commit() {
        if ($this->inTransaction) {
            $this->conn->commit();
            $this->inTransaction = false;
        }
    }
    
    /**
     * Revertir transacción
     */
    public function rollBack() {
        if ($this->inTransaction) {
            $this->conn->rollBack();
            $this->inTransaction = false;
        }
    }
    
    /**
     * Registrar o actualizar usuario
     */
    public function registrarUsuario($telegramId, $username, $firstName, $lastName) {
        $sql = "INSERT INTO usuarios (telegram_id, username, first_name, last_name, creditos)
                VALUES (:telegram_id, :username, :first_name, :last_name, :creditos)
                ON DUPLICATE KEY UPDATE 
                    username = VALUES(username),
                    first_name = VALUES(first_name),
                    last_name = VALUES(last_name),
                    ultima_actividad = CURRENT_TIMESTAMP";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $creditos = CREDITOS_REGISTRO;
            
            $result = $stmt->execute([
                ':telegram_id' => (int)$telegramId,
                ':username' => $this->sanitize($username),
                ':first_name' => $this->sanitize($firstName),
                ':last_name' => $this->sanitize($lastName),
                ':creditos' => (int)$creditos
            ]);
            
            if ($stmt->rowCount() > 0) {
                $this->registrarTransaccion($telegramId, 'registro', $creditos, 'Créditos de bienvenida');
                return true;
            }
            return false;
        } catch(PDOException $e) {
            logSecure("Error al registrar usuario {$telegramId}: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Obtener información de usuario
     */
    public function getUsuario($telegramId) {
        $sql = "SELECT * FROM usuarios WHERE telegram_id = :telegram_id LIMIT 1";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':telegram_id' => (int)$telegramId]);
            return $stmt->fetch();
        } catch(PDOException $e) {
            logSecure("Error al obtener usuario {$telegramId}: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Actualizar créditos de forma segura
     */
    public function actualizarCreditos($telegramId, $cantidad, $operacion = 'add') {
        $telegramId = (int)$telegramId;
        $cantidad = abs((int)$cantidad);
        
        if ($cantidad <= 0) {
            return false;
        }
        
        try {
            $this->beginTransaction();
            
            // Bloquear fila para evitar race conditions
            $sql = "SELECT creditos FROM usuarios WHERE telegram_id = :telegram_id FOR UPDATE";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':telegram_id' => $telegramId]);
            $usuario = $stmt->fetch();
            
            if (!$usuario) {
                $this->rollBack();
                return false;
            }
            
            $creditosActuales = (int)$usuario['creditos'];
            
            if ($operacion === 'add') {
                $nuevoSaldo = $creditosActuales + $cantidad;
            } else {
                if ($creditosActuales < $cantidad) {
                    $this->rollBack();
                    return false;
                }
                $nuevoSaldo = $creditosActuales - $cantidad;
            }
            
            $sql = "UPDATE usuarios SET creditos = :creditos WHERE telegram_id = :telegram_id";
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                ':creditos' => $nuevoSaldo,
                ':telegram_id' => $telegramId
            ]);
            
            $this->commit();
            return $result;
            
        } catch(PDOException $e) {
            $this->rollBack();
            logSecure("Error al actualizar créditos usuario {$telegramId}: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Incrementar contador de generaciones
     */
    public function incrementarGeneraciones($telegramId) {
        $sql = "UPDATE usuarios 
                SET total_generaciones = total_generaciones + 1 
                WHERE telegram_id = :telegram_id";
        
        try {
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute([':telegram_id' => (int)$telegramId]);
        } catch(PDOException $e) {
            logSecure("Error al incrementar generaciones: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Bloquear/desbloquear usuario
     */
    public function bloquearUsuario($telegramId, $bloquear = true) {
        $sql = "UPDATE usuarios SET bloqueado = :bloqueado WHERE telegram_id = :telegram_id";
        
        try {
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute([
                ':bloqueado' => $bloquear ? 1 : 0,
                ':telegram_id' => (int)$telegramId
            ]);
        } catch(PDOException $e) {
            logSecure("Error al bloquear usuario: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Establecer premium
     */
    public function setPremium($telegramId, $premium = true) {
        $sql = "UPDATE usuarios SET es_premium = :premium WHERE telegram_id = :telegram_id";
        
        try {
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute([
                ':premium' => $premium ? 1 : 0,
                ':telegram_id' => (int)$telegramId
            ]);
        } catch(PDOException $e) {
            logSecure("Error al establecer premium: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Registrar transacción
     */
    public function registrarTransaccion($telegramId, $tipo, $cantidad, $descripcion, $adminId = null) {
        $sql = "INSERT INTO transacciones (telegram_id, tipo, cantidad, descripcion, admin_id)
                VALUES (:telegram_id, :tipo, :cantidad, :descripcion, :admin_id)";
        
        try {
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute([
                ':telegram_id' => (int)$telegramId,
                ':tipo' => $this->sanitize($tipo),
                ':cantidad' => (int)$cantidad,
                ':descripcion' => $this->sanitize($descripcion),
                ':admin_id' => $adminId ? (int)$adminId : null
            ]);
        } catch(PDOException $e) {
            logSecure("Error al registrar transacción: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Registrar uso de IMEI
     */
    public function registrarUso($telegramId, $tac, $modelo) {
        $sql = "INSERT INTO historial_uso (telegram_id, tac, modelo, creditos_usados)
                VALUES (:telegram_id, :tac, :modelo, :creditos_usados)";
        
        try {
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute([
                ':telegram_id' => (int)$telegramId,
                ':tac' => $this->sanitize($tac),
                ':modelo' => $this->sanitize($modelo),
                ':creditos_usados' => COSTO_GENERACION
            ]);
        } catch(PDOException $e) {
            logSecure("Error al registrar uso: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Obtener historial de usuario
     */
    public function getHistorialUsuario($telegramId, $limite = 10) {
        $sql = "SELECT * FROM historial_uso 
                WHERE telegram_id = :telegram_id 
                ORDER BY fecha DESC 
                LIMIT :limite";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':telegram_id', (int)$telegramId, PDO::PARAM_INT);
            $stmt->bindValue(':limite', (int)$limite, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            logSecure("Error al obtener historial: " . $e->getMessage(), 'ERROR');
            return [];
        }
    }
    
    /**
     * Guardar modelo TAC
     */
    public function guardarModelo($tac, $modelo, $marca = '', $fuente = 'usuario') {
        $sql = "INSERT INTO tac_modelos (tac, modelo, marca, fuente, veces_usado) 
                VALUES (:tac, :modelo, :marca, :fuente, 1)
                ON DUPLICATE KEY UPDATE 
                    modelo = VALUES(modelo),
                    marca = VALUES(marca),
                    veces_usado = veces_usado + 1,
                    ultima_consulta = CURRENT_TIMESTAMP";
        
        try {
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute([
                ':tac' => $this->sanitize($tac),
                ':modelo' => $this->sanitize($modelo),
                ':marca' => $this->sanitize($marca),
                ':fuente' => $this->sanitize($fuente)
            ]);
        } catch(PDOException $e) {
            logSecure("Error al guardar modelo: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Buscar modelo por TAC
     */
    public function buscarModelo($tac) {
        $sql = "SELECT * FROM tac_modelos WHERE tac = :tac LIMIT 1";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':tac' => $this->sanitize($tac)]);
            return $stmt->fetch();
        } catch(PDOException $e) {
            logSecure("Error al buscar modelo: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Eliminar modelo
     */
    public function eliminarModelo($tac) {
        $sql = "DELETE FROM tac_modelos WHERE tac = :tac";
        
        try {
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute([':tac' => $this->sanitize($tac)]) && $stmt->rowCount() > 0;
        } catch(PDOException $e) {
            logSecure("Error al eliminar modelo: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Obtener estadísticas generales
     */
    public function getEstadisticasGenerales() {
        try {
            $stats = [];
            
            $queries = [
                'total_usuarios' => "SELECT COUNT(*) as total FROM usuarios",
                'total_creditos' => "SELECT COALESCE(SUM(creditos), 0) as total FROM usuarios",
                'total_generaciones' => "SELECT COALESCE(SUM(total_generaciones), 0) as total FROM usuarios",
                'usuarios_hoy' => "SELECT COUNT(*) as total FROM usuarios WHERE DATE(ultima_actividad) = CURDATE()",
                'pagos_pendientes' => "SELECT COUNT(*) as total FROM pagos_pendientes WHERE estado = 'pendiente'",
                'usuarios_premium' => "SELECT COUNT(*) as total FROM usuarios WHERE es_premium = 1"
            ];
            
            foreach ($queries as $key => $query) {
                $stmt = $this->conn->query($query);
                $result = $stmt->fetch();
                $stats[$key] = $result['total'] ?? 0;
            }
            
            return $stats;
        } catch(PDOException $e) {
            logSecure("Error al obtener estadísticas: " . $e->getMessage(), 'ERROR');
            return [];
        }
    }
    
    /**
     * Obtener top usuarios
     */
    public function getTopUsuarios($limite = 10) {
        $sql = "SELECT telegram_id, username, first_name, creditos, total_generaciones 
                FROM usuarios 
                ORDER BY total_generaciones DESC 
                LIMIT :limite";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':limite', (int)$limite, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            logSecure("Error al obtener top usuarios: " . $e->getMessage(), 'ERROR');
            return [];
        }
    }
    
    /**
     * Obtener pagos pendientes
     */
    public function getPagosPendientes($limite = 20) {
        $sql = "SELECT p.*, u.username, u.first_name 
                FROM pagos_pendientes p
                LEFT JOIN usuarios u ON p.telegram_id = u.telegram_id
                WHERE p.estado IN ('pendiente', 'captura_enviada', 'esperando_captura')
                ORDER BY p.fecha_solicitud DESC
                LIMIT :limite";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':limite', (int)$limite, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            logSecure("Error al obtener pagos pendientes: " . $e->getMessage(), 'ERROR');
            return [];
        }
    }
    
    /**
     * Sanitizar entrada
     */
    private function sanitize($input) {
        if ($input === null) {
            return null;
        }
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Obtener conexión (solo para casos especiales)
     */
    public function getConnection() {
        return $this->conn;
    }
    
    /**
     * Cerrar conexión
     */
    public function __destruct() {
        if ($this->inTransaction) {
            $this->rollBack();
        }
        $this->conn = null;
    }
}

?>
