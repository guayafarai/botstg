<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * PANEL WEB - GESTIÓN DE MODELOS TAC
 * VERSIÓN 4.0 - LOGIN CON BASE DE DATOS SQL
 * ═══════════════════════════════════════════════════════════════
 */

ob_start();
session_start();

require_once(__DIR__ . '/config_bot.php');
require_once(__DIR__ . '/Database.php');

// ========================================
// CLASE DE AUTENTICACIÓN
// ========================================

class PanelAuth {
    private $db;
    private $conn;
    private $maxAttempts = 5;
    private $lockoutTime = 900; // 15 minutos
    
    public function __construct($database) {
        $this->db = $database;
        $this->conn = $database->getConnection();
    }
    
    /**
     * Autenticar usuario
     */
    public function login($username, $password, $ip, $userAgent) {
        try {
            // Verificar si está bloqueado
            if ($this->isBlocked($username, $ip)) {
                $this->logAttempt($username, $ip, $userAgent, false, 'Usuario bloqueado');
                return [
                    'success' => false,
                    'message' => 'Cuenta bloqueada temporalmente. Intenta en 15 minutos.'
                ];
            }
            
            // Buscar usuario
            $sql = "SELECT * FROM panel_admins WHERE username = ? AND activo = 1 LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $this->logAttempt($username, $ip, $userAgent, false, 'Usuario no existe o inactivo');
                $this->incrementFailedAttempts($username, $ip);
                return [
                    'success' => false,
                    'message' => 'Usuario o contraseña incorrectos'
                ];
            }
            
            // Verificar contraseña
            if (!password_verify($password, $user['password_hash'])) {
                $this->logAttempt($username, $ip, $userAgent, false, 'Contraseña incorrecta');
                $this->incrementFailedAttempts($username, $ip);
                return [
                    'success' => false,
                    'message' => 'Usuario o contraseña incorrectos'
                ];
            }
            
            // Login exitoso
            $this->resetFailedAttempts($username, $ip);
            $this->updateLastLogin($user['id']);
            $this->logAttempt($username, $ip, $userAgent, true, null);
            
            return [
                'success' => true,
                'user' => $user
            ];
            
        } catch (PDOException $e) {
            logSecure("Error en login: " . $e->getMessage(), 'ERROR');
            return [
                'success' => false,
                'message' => 'Error del sistema'
            ];
        }
    }
    
    /**
     * Verificar si usuario/IP está bloqueado
     */
    private function isBlocked($username, $ip) {
        $sql = "SELECT bloqueado_hasta FROM panel_admins 
                WHERE username = ? 
                AND bloqueado_hasta > NOW()";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$username]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Incrementar intentos fallidos
     */
    private function incrementFailedAttempts($username, $ip) {
        $sql = "UPDATE panel_admins 
                SET intentos_fallidos = intentos_fallidos + 1 
                WHERE username = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$username]);
        
        $sql = "SELECT intentos_fallidos FROM panel_admins WHERE username = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && $user['intentos_fallidos'] >= $this->maxAttempts) {
            $bloquear_hasta = date('Y-m-d H:i:s', time() + $this->lockoutTime);
            
            $sql = "UPDATE panel_admins 
                    SET bloqueado_hasta = ? 
                    WHERE username = ?";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$bloquear_hasta, $username]);
            
            logSecure("Usuario {$username} bloqueado hasta {$bloquear_hasta}", 'WARN');
        }
    }
    
    /**
     * Resetear intentos fallidos
     */
    private function resetFailedAttempts($username, $ip) {
        $sql = "UPDATE panel_admins 
                SET intentos_fallidos = 0, 
                    bloqueado_hasta = NULL 
                WHERE username = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$username]);
    }
    
    /**
     * Actualizar último login
     */
    private function updateLastLogin($userId) {
        $sql = "UPDATE panel_admins 
                SET ultimo_login = NOW() 
                WHERE id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$userId]);
    }
    
    /**
     * Registrar intento de login
     */
    private function logAttempt($username, $ip, $userAgent, $exitoso, $motivo) {
        $sql = "INSERT INTO panel_login_logs 
                (username, ip_address, user_agent, exitoso, motivo_fallo) 
                VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            $username,
            $ip,
            $userAgent,
            $exitoso ? 1 : 0,
            $motivo
        ]);
    }
}

// ========================================
// INICIALIZAR
// ========================================

try {
    $db = new Database();
    $auth = new PanelAuth($db);
} catch (Exception $e) {
    die("Error de conexión: " . $e->getMessage());
}

$userIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

// ========================================
// VERIFICAR AUTENTICACIÓN
// ========================================

if (!isset($_SESSION['panel_authenticated']) || $_SESSION['panel_authenticated'] !== true) {
    
    $login_error = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $login_error = 'Completa todos los campos';
        } else {
            $result = $auth->login($username, $password, $userIp, $userAgent);
            
            if ($result['success']) {
                $_SESSION['panel_authenticated'] = true;
                $_SESSION['panel_user_id'] = $result['user']['id'];
                $_SESSION['panel_username'] = $result['user']['username'];
                $_SESSION['panel_nombre'] = $result['user']['nombre_completo'];
                $_SESSION['panel_login_time'] = time();
                $_SESSION['panel_ip'] = $userIp;
                
                session_regenerate_id(true);
                
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $login_error = $result['message'];
                sleep(2);
            }
        }
    }
    
    ob_end_clean();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - Panel de Modelos</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .login-box {
                background: white;
                padding: 40px;
                border-radius: 20px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                max-width: 400px;
                width: 100%;
            }
            .login-box h1 {
                color: #667eea;
                margin-bottom: 30px;
                font-size: 28px;
                text-align: center;
            }
            .form-group {
                margin-bottom: 20px;
            }
            .form-group label {
                display: block;
                margin-bottom: 8px;
                color: #495057;
                font-weight: 500;
            }
            .form-group input {
                width: 100%;
                padding: 12px 15px;
                border: 2px solid #e0e0e0;
                border-radius: 10px;
                font-size: 16px;
                transition: border 0.3s;
            }
            .form-group input:focus {
                outline: none;
                border-color: #667eea;
            }
            .btn-login {
                width: 100%;
                padding: 15px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 10px;
                font-size: 16px;
                cursor: pointer;
                transition: transform 0.2s;
            }
            .btn-login:hover {
                transform: translateY(-2px);
            }
            .error {
                background: #f8d7da;
                color: #721c24;
                padding: 12px;
                border-radius: 8px;
                margin-bottom: 20px;
                font-size: 14px;
                border: 1px solid #f5c6cb;
            }
            .info {
                background: #d1ecf1;
                color: #0c5460;
                padding: 12px;
                border-radius: 8px;
                margin-top: 20px;
                font-size: 12px;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>🔐 Panel de Modelos</h1>
            
            <?php if (!empty($login_error)): ?>
                <div class="error">
                    ❌ <?php echo htmlspecialchars($login_error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label for="username">Usuario</label>
                    <input 
                        type="text" 
                        id="username"
                        name="username" 
                        placeholder="Ingresa tu usuario" 
                        required 
                        autofocus
                        autocomplete="username"
                    >
                </div>
                
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input 
                        type="password" 
                        id="password"
                        name="password" 
                        placeholder="Ingresa tu contraseña" 
                        required
                        autocomplete="current-password"
                    >
                </div>
                
                <button type="submit" class="btn-login">Iniciar Sesión</button>
            </form>
            
            <div class="info">
                🔒 Autenticación segura con base de datos<br>
                Usuario por defecto: <strong>admin</strong> / <strong>Admin123!</strong>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ========================================
// VALIDACIÓN DE SESIÓN ACTIVA
// ========================================

if (isset($_SESSION['panel_login_time'])) {
    if (time() - $_SESSION['panel_login_time'] > 1800) {
        session_destroy();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$_SESSION['panel_login_time'] = time();

if (isset($_SESSION['panel_ip']) && $_SESSION['panel_ip'] !== $userIp) {
    logSecure("Cambio de IP detectado", 'WARN');
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ========================================
// LOGOUT
// ========================================

if (isset($_GET['logout'])) {
    $username = $_SESSION['panel_username'] ?? 'unknown';
    logSecure("Logout del usuario {$username}", 'INFO');
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ========================================
// INICIALIZAR PANEL
// ========================================

$nombre_usuario = $_SESSION['panel_nombre'] ?? $_SESSION['panel_username'];

try {
    $conn = $db->getConnection();
    
    $check_table = $conn->query("SHOW TABLES LIKE 'tac_modelos'");
    if ($check_table->rowCount() === 0) {
        throw new Exception("La tabla 'tac_modelos' no existe.");
    }
    
} catch (Exception $e) {
    ob_end_clean();
    die("Error: " . $e->getMessage());
}

// ========================================
// CSRF TOKEN
// ========================================

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function verifyCsrfToken() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            die("Error de seguridad: Token CSRF inválido");
        }
    }
}

// ========================================
// PROCESAR ACCIONES
// ========================================

$message = '';
$message_type = '';

verifyCsrfToken();

// CREAR NUEVO MODELO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $tac = preg_replace('/[^0-9]/', '', $_POST['tac'] ?? '');
    $modelo = trim($_POST['modelo'] ?? '');
    $marca = trim($_POST['marca'] ?? '');
    $fuente = trim($_POST['fuente'] ?? 'manual');
    
    if (strlen($tac) === 8 && !empty($modelo)) {
        try {
            $resultado = $db->guardarModelo($tac, $modelo, $marca, $fuente);
            
            if ($resultado) {
                $message = "Modelo agregado/actualizado exitosamente";
                $message_type = 'success';
                logSecure("Modelo TAC {$tac} agregado por {$_SESSION['panel_username']}", 'INFO');
            } else {
                $message = "Error al guardar el modelo";
                $message_type = 'error';
            }
        } catch (PDOException $e) {
            $message = "Error al agregar modelo: " . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = "TAC inválido (debe tener 8 dígitos) o modelo vacío";
        $message_type = 'error';
    }
}

// ACTUALIZAR MODELO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $modelo = trim($_POST['modelo'] ?? '');
    $marca = trim($_POST['marca'] ?? '');
    $fuente = trim($_POST['fuente'] ?? 'manual');
    
    if ($id > 0 && !empty($modelo)) {
        try {
            $sql = "UPDATE tac_modelos 
                    SET modelo = ?, marca = ?, fuente = ?, ultima_consulta = NOW()
                    WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $result = $stmt->execute([$modelo, $marca, $fuente, $id]);
            
            if ($stmt->rowCount() > 0) {
                $message = "Modelo actualizado exitosamente";
                $message_type = 'success';
                logSecure("Modelo ID {$id} actualizado por {$_SESSION['panel_username']}", 'INFO');
            } else {
                $message = "No se realizaron cambios";
                $message_type = 'warning';
            }
        } catch (PDOException $e) {
            $message = "Error al actualizar: " . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = "ID inválido o modelo vacío";
        $message_type = 'error';
    }
}

// ELIMINAR MODELO
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    if ($id > 0) {
        try {
            $info = $conn->prepare("SELECT tac, modelo FROM tac_modelos WHERE id = ?");
            $info->execute([$id]);
            $modelo_info = $info->fetch(PDO::FETCH_ASSOC);
            
            if ($modelo_info) {
                $sql = "DELETE FROM tac_modelos WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$id]);
                
                if ($stmt->rowCount() > 0) {
                    $message = "Modelo eliminado: {$modelo_info['modelo']}";
                    $message_type = 'success';
                    logSecure("Modelo TAC {$modelo_info['tac']} eliminado por {$_SESSION['panel_username']}", 'INFO');
                } else {
                    $message = "No se pudo eliminar";
                    $message_type = 'error';
                }
            }
        } catch (PDOException $e) {
            $message = "Error al eliminar: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// ========================================
// BÚSQUEDA Y PAGINACIÓN
// ========================================

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$fuente_filter = isset($_GET['fuente']) ? trim($_GET['fuente']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

try {
    $where = [];
    $params = [];
    
    if (!empty($search)) {
        $where[] = "(tac LIKE ? OR modelo LIKE ? OR marca LIKE ?)";
        $search_param = "%{$search}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if (!empty($fuente_filter)) {
        $where[] = "fuente = ?";
        $params[] = $fuente_filter;
    }
    
    $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Contar total
    $count_sql = "SELECT COUNT(*) as total FROM tac_modelos {$where_clause}";
    $stmt = $conn->prepare($count_sql);
    $stmt->execute($params);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_records = $result ? (int)$result['total'] : 0;
    $total_pages = $total_records > 0 ? (int)ceil($total_records / $per_page) : 0;
    
    // Obtener registros
    $sql = "SELECT * FROM tac_modelos {$where_clause} 
            ORDER BY veces_usado DESC, ultima_consulta DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $params[] = $per_page;
    $params[] = $offset;
    $stmt->execute($params);
    
    $modelos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas
    $stats_sql = "SELECT 
                    COUNT(*) as total_modelos,
                    COUNT(DISTINCT marca) as total_marcas,
                    COALESCE(SUM(veces_usado), 0) as total_usos,
                    COUNT(CASE WHEN fuente = 'imeidb_api' THEN 1 END) as de_api,
                    COUNT(CASE WHEN fuente = 'usuario' THEN 1 END) as de_usuarios
                  FROM tac_modelos";
    $stats_stmt = $conn->query($stats_sql);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats['total_modelos'] = (int)$stats['total_modelos'];
    $stats['total_marcas'] = (int)$stats['total_marcas'];
    $stats['total_usos'] = (int)$stats['total_usos'];
    $stats['de_api'] = (int)$stats['de_api'];
    $stats['de_usuarios'] = (int)$stats['de_usuarios'];
    
    // Fuentes disponibles
    $fuentes_sql = "SELECT DISTINCT fuente FROM tac_modelos WHERE fuente IS NOT NULL ORDER BY fuente";
    $fuentes_stmt = $conn->query($fuentes_sql);
    $fuentes = $fuentes_stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (PDOException $e) {
    logSecure("Error en consulta: " . $e->getMessage(), 'ERROR');
    $modelos = [];
    $total_records = 0;
    $total_pages = 0;
    $stats = [
        'total_modelos' => 0,
        'total_marcas' => 0,
        'total_usos' => 0,
        'de_api' => 0,
        'de_usuarios' => 0
    ];
    $fuentes = [];
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Gestión - Modelos TAC</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 28px;
        }
        
        .header .user-info {
            text-align: right;
        }
        
        .header .user-info strong {
            display: block;
            margin-bottom: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            transition: transform 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .btn-primary {
            background: white;
            color: #667eea;
            font-weight: 600;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .card-header {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #333;
        }
        
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .message.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .search-bar input,
        .search-bar select {
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border 0.3s;
        }
        
        .search-bar input {
            flex: 1;
            min-width: 250px;
        }
        
        .search-bar select {
            min-width: 150px;
        }
        
        .search-bar input:focus,
        .search-bar select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
            color: #495057;
        }
        
        table td {
            padding: 12px 15px;
            border-bottom: 1px solid #dee2e6;
        }
        
        table tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-api {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .badge-user {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-local {
            background: #f8f9fa;
            color: #495057;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 30px;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border-radius: 5px;
            background: white;
            color: #667eea;
            text-decoration: none;
            border: 1px solid #e0e0e0;
        }
        
        .pagination a:hover {
            background: #667eea;
            color: white;
        }
        
        .pagination .current {
            background: #667eea;
            color: white;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #333;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #495057;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        code {
            background: rgba(0,0,0,0.1);
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>📱 Panel de Modelos TAC</h1>
                <p style="opacity: 0.9; margin-top: 5px;">Gestión de base de datos de dispositivos</p>
            </div>
            <div class="user-info">
                <strong>👤 <?php echo htmlspecialchars($nombre_usuario); ?></strong>
                <a href="?logout=1" class="btn btn-danger">🚪 Salir</a>
            </div>
        </div>
        
        <?php if ($message): ?>
        <div class="message <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <!-- ESTADÍSTICAS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_modelos']); ?></div>
                <div class="stat-label">Total Modelos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_marcas']); ?></div>
                <div class="stat-label">Marcas Distintas</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_usos']); ?></div>
                <div class="stat-label">Total Consultas</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['de_api']); ?></div>
                <div class="stat-label">De API</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['de_usuarios']); ?></div>
                <div class="stat-label">De Usuarios</div>
            </div>
        </div>
        
        <!-- BÚSQUEDA Y FILTROS -->
        <div class="card">
            <form method="GET" class="search-bar">
                <input 
                    type="text" 
                    name="search" 
                    placeholder="🔍 Buscar por TAC, modelo o marca..." 
                    value="<?php echo htmlspecialchars($search); ?>"
                >
                <select name="fuente">
                    <option value="">Todas las fuentes</option>
                    <?php foreach ($fuentes as $f): ?>
                        <option value="<?php echo htmlspecialchars($f); ?>" <?php echo $fuente_filter === $f ? 'selected' : ''; ?>>
                            <?php echo ucfirst(htmlspecialchars($f)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Buscar</button>
                <?php if ($search || $fuente_filter): ?>
                    <a href="?" class="btn btn-secondary">Limpiar</a>
                <?php endif; ?>
                <button type="button" class="btn btn-success" onclick="openModal('create')">➕ Agregar Modelo</button>
            </form>
        </div>
        
        <!-- TABLA DE MODELOS -->
        <div class="card">
            <div class="card-header">
                📋 Modelos Registrados (<?php echo number_format($total_records); ?>)
            </div>
            
            <?php if (empty($modelos)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📱</div>
                    <h3>No se encontraron modelos</h3>
                    <p>Intenta con otros criterios de búsqueda o agrega un nuevo modelo.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>TAC</th>
                                <th>Modelo</th>
                                <th>Marca</th>
                                <th>Fuente</th>
                                <th>Usos</th>
                                <th>Última Consulta</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($modelos as $modelo): ?>
                            <tr>
                                <td><?php echo $modelo['id']; ?></td>
                                <td><code><?php echo htmlspecialchars($modelo['tac']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($modelo['modelo']); ?></strong></td>
                                <td><?php echo htmlspecialchars($modelo['marca'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php
                                    $fuente = $modelo['fuente'];
                                    $badge_class = 'badge-local';
                                    if (strpos($fuente, 'api') !== false) $badge_class = 'badge-api';
                                    elseif ($fuente === 'usuario') $badge_class = 'badge-user';
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo htmlspecialchars($fuente); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($modelo['veces_usado']); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($modelo['ultima_consulta'])); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick='openModal("edit", <?php echo json_encode($modelo); ?>)'>
                                        ✏️ Editar
                                    </button>
                                    <a href="?delete=<?php echo $modelo['id']; ?>" 
                                       class="btn btn-sm btn-danger" 
                                       onclick="return confirm('¿Eliminar este modelo?\n\nTAC: <?php echo $modelo['tac']; ?>\nModelo: <?php echo htmlspecialchars($modelo['modelo']); ?>')">
                                        🗑️
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- PAGINACIÓN -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&fuente=<?php echo urlencode($fuente_filter); ?>">
                            ← Anterior
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <?php if ($i === $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&fuente=<?php echo urlencode($fuente_filter); ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&fuente=<?php echo urlencode($fuente_filter); ?>">
                            Siguiente →
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- MODAL CREAR/EDITAR -->
    <div id="modalForm" class="modal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle">Agregar Modelo</div>
            
            <form method="POST" id="modelForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="modeloId">
                
                <div class="form-group">
                    <label for="tac">TAC (8 dígitos) *</label>
                    <input 
                        type="text" 
                        name="tac" 
                        id="tac" 
                        placeholder="35203310" 
                        maxlength="8" 
                        pattern="[0-9]{8}"
                        required
                    >
                    <small style="color: #666;">Solo números, exactamente 8 dígitos</small>
                </div>
                
                <div class="form-group">
                    <label for="modelo">Modelo *</label>
                    <input 
                        type="text" 
                        name="modelo" 
                        id="modelo" 
                        placeholder="iPhone 13 Pro" 
                        required
                    >
                </div>
                
                <div class="form-group">
                    <label for="marca">Marca</label>
                    <input 
                        type="text" 
                        name="marca" 
                        id="marca" 
                        placeholder="Apple"
                    >
                </div>
                
                <div class="form-group">
                    <label for="fuente">Fuente</label>
                    <select name="fuente" id="fuente">
                        <option value="manual">Manual</option>
                        <option value="usuario">Usuario</option>
                        <option value="imeidb_api">IMEI DB API</option>
                        <option value="local">Local</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancelar</button>
                    <button type="submit" class="btn btn-success">Guardar</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openModal(action, data = null) {
            const modal = document.getElementById('modalForm');
            const form = document.getElementById('modelForm');
            const title = document.getElementById('modalTitle');
            const formAction = document.getElementById('formAction');
            const tacInput = document.getElementById('tac');
            
            if (action === 'create') {
                title.textContent = 'Agregar Nuevo Modelo';
                formAction.value = 'create';
                form.reset();
                form.querySelector('input[name="csrf_token"]').value = '<?php echo $_SESSION['csrf_token']; ?>';
                formAction.value = 'create';
                tacInput.readOnly = false;
            } else if (action === 'edit' && data) {
                title.textContent = 'Editar Modelo';
                formAction.value = 'update';
                
                document.getElementById('modeloId').value = data.id;
                document.getElementById('tac').value = data.tac;
                document.getElementById('modelo').value = data.modelo;
                document.getElementById('marca').value = data.marca || '';
                document.getElementById('fuente').value = data.fuente;
                
                tacInput.readOnly = true;
            }
            
            modal.classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('modalForm').classList.remove('active');
        }
        
        document.getElementById('modalForm').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        document.getElementById('tac').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '').substring(0, 8);
        });
    </script>
</body>
</html>