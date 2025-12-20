<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  VERIFICADOR COMPLETO - BOT TELEGRAM IMEI (ACTUALIZADO)
 * ═══════════════════════════════════════════════════════════════
 */

// Cargar configuración desde config_bot.php
require_once(__DIR__ . '/config_bot.php');
require_once(__DIR__ . '/config_imeidb.php');

$config = [
    'bot_token' => BOT_TOKEN,
    'bot_file' => 'bot_imei_corregido.php',
    'db_host' => DB_HOST,
    'db_user' => DB_USER,
    'db_pass' => DB_PASS,
    'db_name' => DB_NAME,
];

// Función para obtener estado
function getStatus($success) {
    return $success ? '✅' : '❌';
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificador - Bot Telegram IMEI</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .content {
            padding: 40px;
        }
        
        .check-item {
            background: #f8f9fa;
            border-left: 5px solid #e0e0e0;
            padding: 20px;
            margin: 15px 0;
            border-radius: 5px;
            transition: all 0.3s;
        }
        
        .check-item.success {
            border-left-color: #28a745;
            background: #d4edda;
        }
        
        .check-item.error {
            border-left-color: #dc3545;
            background: #f8d7da;
        }
        
        .check-item.warning {
            border-left-color: #ffc107;
            background: #fff3cd;
        }
        
        .check-header {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .check-icon {
            font-size: 24px;
            margin-right: 15px;
        }
        
        .check-title {
            font-size: 18px;
            font-weight: 600;
        }
        
        .check-detail {
            margin-left: 39px;
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .check-detail code {
            background: rgba(0,0,0,0.1);
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        
        .summary-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin: 30px 0;
            text-align: center;
        }
        
        .summary-box h2 {
            font-size: 48px;
            margin-bottom: 10px;
        }
        
        .summary-box p {
            font-size: 18px;
            opacity: 0.9;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .info-box {
            background: #e7f3ff;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .info-box h3 {
            color: #0066cc;
            margin-bottom: 10px;
        }
        
        .steps {
            list-style: none;
            padding: 0;
        }
        
        .steps li {
            padding: 10px;
            margin: 5px 0;
            background: white;
            border-radius: 5px;
            border-left: 3px solid #667eea;
        }
        
        .config-display {
            background: #1e1e1e;
            color: #00ff00;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            margin: 10px 0;
            overflow-x: auto;
        }
        
        .progress-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 30px 0;
        }
        
        .progress-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .progress-card .number {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .progress-card .label {
            color: #666;
            font-size: 14px;
        }

        .fix-button {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 10px;
        }

        .fix-button:hover {
            background: #218838;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Verificador del Bot</h1>
            <p>Diagnóstico Completo del Sistema</p>
        </div>
        
        <div class="content">
            <?php
            $checks = [];
            $passed_checks = 0;
            $total_checks = 0;
            
            // ========================================
            // CHECK 1: Archivos de configuración
            // ========================================
            $total_checks++;
            $config_files = [
                'config_bot.php' => file_exists(__DIR__ . '/config_bot.php'),
                'config_imeidb.php' => file_exists(__DIR__ . '/config_imeidb.php'),
                'bot_imei_corregido.php' => file_exists(__DIR__ . '/bot_imei_corregido.php'),
                'imeidb_api.php' => file_exists(__DIR__ . '/imeidb_api.php')
            ];
            
            $all_files_exist = !in_array(false, $config_files);
            
            if ($all_files_exist) {
                $passed_checks++;
                $checks[] = [
                    'status' => 'success',
                    'icon' => '✅',
                    'title' => 'Archivos de Configuración',
                    'detail' => "Todos los archivos necesarios están presentes:<br>" . 
                               implode('<br>', array_map(function($file) { return "• $file"; }, array_keys($config_files)))
                ];
            } else {
                $missing = array_keys(array_filter($config_files, function($v) { return !$v; }));
                $checks[] = [
                    'status' => 'error',
                    'icon' => '❌',
                    'title' => 'Archivos Faltantes',
                    'detail' => "Faltan los siguientes archivos: " . implode(', ', $missing)
                ];
            }
            
            // ========================================
            // CHECK 2: Extensiones PHP
            // ========================================
            $total_checks++;
            $extensions = ['pdo', 'pdo_mysql', 'json', 'curl', 'openssl'];
            $missing_ext = [];
            
            foreach ($extensions as $ext) {
                if (!extension_loaded($ext)) {
                    $missing_ext[] = $ext;
                }
            }
            
            if (empty($missing_ext)) {
                $passed_checks++;
                $checks[] = [
                    'status' => 'success',
                    'icon' => '✅',
                    'title' => 'Extensiones PHP',
                    'detail' => "Todas las extensiones necesarias están instaladas: " . implode(', ', $extensions)
                ];
            } else {
                $checks[] = [
                    'status' => 'error',
                    'icon' => '❌',
                    'title' => 'Extensiones PHP Faltantes',
                    'detail' => "Faltan: " . implode(', ', $missing_ext) . "<br>Contacta a tu proveedor de hosting."
                ];
            }
            
            // ========================================
            // CHECK 3: Conexión a base de datos
            // ========================================
            $total_checks++;
            try {
                $pdo = new PDO(
                    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
                    $config['db_user'],
                    $config['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                
                $passed_checks++;
                $checks[] = [
                    'status' => 'success',
                    'icon' => '✅',
                    'title' => 'Conexión a Base de Datos',
                    'detail' => "Conectado exitosamente a: <strong>{$config['db_name']}</strong> en {$config['db_host']}"
                ];
                
                // ========================================
                // CHECK 4: Tablas de base de datos
                // ========================================
                $total_checks++;
                $required_tables = [
                    'usuarios',
                    'transacciones',
                    'historial_uso',
                    'modelos_moviles',
                    'api_cache'
                ];
                
                $existing_tables = [];
                $result = $pdo->query("SHOW TABLES");
                while ($row = $result->fetch(PDO::FETCH_NUM)) {
                    $existing_tables[] = $row[0];
                }
                
                $missing_tables = array_diff($required_tables, $existing_tables);
                
                if (empty($missing_tables)) {
                    $passed_checks++;
                    $checks[] = [
                        'status' => 'success',
                        'icon' => '✅',
                        'title' => 'Tablas de Base de Datos',
                        'detail' => "Todas las tablas necesarias existen: " . implode(', ', $required_tables)
                    ];
                } else {
                    $checks[] = [
                        'status' => 'error',
                        'icon' => '❌',
                        'title' => 'Tablas Faltantes',
                        'detail' => "Faltan las siguientes tablas: " . implode(', ', $missing_tables) . 
                                   "<br><br><strong>Solución:</strong> Ejecuta el script de instalación SQL"
                    ];
                }
                
            } catch(PDOException $e) {
                $checks[] = [
                    'status' => 'error',
                    'icon' => '❌',
                    'title' => 'Error de Conexión a Base de Datos',
                    'detail' => "No se pudo conectar: " . $e->getMessage() . 
                               "<br><br><strong>Verifica:</strong><br>• Credenciales de base de datos<br>• Que la base de datos exista<br>• Permisos del usuario"
                ];
            }
            
            // ========================================
            // CHECK 5: Token de Telegram
            // ========================================
            $total_checks++;
            $api_url = 'https://api.telegram.org/bot' . $config['bot_token'] . '/';
            $response = @file_get_contents($api_url . 'getMe');
            
            if ($response) {
                $result = json_decode($response, true);
                if (isset($result['ok']) && $result['ok']) {
                    $passed_checks++;
                    $bot_info = $result['result'];
                    $checks[] = [
                        'status' => 'success',
                        'icon' => '✅',
                        'title' => 'Token de Telegram Válido',
                        'detail' => "Bot conectado: <strong>@{$bot_info['username']}</strong><br>Nombre: {$bot_info['first_name']}<br>ID: {$bot_info['id']}"
                    ];
                } else {
                    $checks[] = [
                        'status' => 'error',
                        'icon' => '❌',
                        'title' => 'Token Inválido',
                        'detail' => "El token de Telegram no es válido. Verifica que lo copiaste correctamente de @BotFather."
                    ];
                }
            } else {
                $checks[] = [
                    'status' => 'error',
                    'icon' => '❌',
                    'title' => 'No se Puede Conectar a Telegram',
                    'detail' => "No se pudo conectar a la API de Telegram. Verifica tu conexión a internet."
                ];
            }
            
            // ========================================
            // CHECK 6: API de IMEIDb
            // ========================================
            $total_checks++;
            $imeidb_configured = !empty(IMEIDB_API_KEY) && IMEIDB_API_KEY !== 'TU_API_KEY_AQUI';
            
            if ($imeidb_configured) {
                $passed_checks++;
                $checks[] = [
                    'status' => 'success',
                    'icon' => '✅',
                    'title' => 'API de IMEIDb Configurada',
                    'detail' => "API Key: " . substr(IMEIDB_API_KEY, 0, 15) . "...<br>URL: " . IMEIDB_API_URL
                ];
            } else {
                $checks[] = [
                    'status' => 'warning',
                    'icon' => '⚠️',
                    'title' => 'API de IMEIDb No Configurada',
                    'detail' => "El bot funcionará con la base de datos local únicamente.<br>Para usar la API, configura IMEIDB_API_KEY en config_imeidb.php"
                ];
            }
            
            // ========================================
            // CHECK 7: Webhook configurado
            // ========================================
            $total_checks++;
            $response = @file_get_contents($api_url . 'getWebhookInfo');
            
            if ($response) {
                $result = json_decode($response, true);
                if (isset($result['ok']) && $result['ok']) {
                    $webhook_info = $result['result'];
                    
                    if (!empty($webhook_info['url'])) {
                        $passed_checks++;
                        $pending = $webhook_info['pending_update_count'] ?? 0;
                        $last_error = isset($webhook_info['last_error_message']) ? $webhook_info['last_error_message'] : 'Ninguno';
                        
                        $detail = "URL configurada: <code>{$webhook_info['url']}</code><br>";
                        $detail .= "Mensajes pendientes: <strong>$pending</strong><br>";
                        $detail .= "Último error: $last_error";
                        
                        if ($pending > 0 || !empty($webhook_info['last_error_message'])) {
                            $checks[] = [
                                'status' => 'warning',
                                'icon' => '⚠️',
                                'title' => 'Webhook Configurado con Problemas',
                                'detail' => $detail
                            ];
                        } else {
                            $checks[] = [
                                'status' => 'success',
                                'icon' => '✅',
                                'title' => 'Webhook Configurado Correctamente',
                                'detail' => $detail
                            ];
                        }
                    } else {
                        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
                                      "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['REQUEST_URI']) . '/' . $config['bot_file'];
                        
                        $checks[] = [
                            'status' => 'error',
                            'icon' => '❌',
                            'title' => 'Webhook No Configurado',
                            'detail' => "El webhook no está configurado. El bot no recibirá mensajes.<br><br>" .
                                       "<strong>Para configurarlo manualmente:</strong><br>" .
                                       "Visita esta URL en tu navegador:<br>" .
                                       "<code style='display:block;margin-top:10px;word-break:break-all;'>" .
                                       $api_url . "setWebhook?url=" . urlencode($current_url) .
                                       "</code>"
                        ];
                    }
                }
            }
            
            // ========================================
            // CHECK 8: SSL/HTTPS activo
            // ========================================
            $total_checks++;
            $is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
            
            if ($is_https) {
                $passed_checks++;
                $checks[] = [
                    'status' => 'success',
                    'icon' => '✅',
                    'title' => 'SSL/HTTPS Activo',
                    'detail' => "Tu sitio está usando HTTPS, lo cual es requerido por Telegram para webhooks."
                ];
            } else {
                $checks[] = [
                    'status' => 'error',
                    'icon' => '❌',
                    'title' => 'SSL/HTTPS No Detectado',
                    'detail' => "Telegram requiere HTTPS para webhooks. Activa SSL en tu hosting."
                ];
            }
            
            // ========================================
            // Mostrar resumen
            // ========================================
            $percentage = round(($passed_checks / $total_checks) * 100);
            ?>
            
            <div class="summary-box">
                <h2><?php echo $percentage; ?>%</h2>
                <p><?php echo $passed_checks; ?> de <?php echo $total_checks; ?> verificaciones pasadas</p>
            </div>
            
            <div class="progress-summary">
                <div class="progress-card">
                    <div class="number" style="color: #28a745;"><?php echo $passed_checks; ?></div>
                    <div class="label">Exitosas</div>
                </div>
                <div class="progress-card">
                    <div class="number" style="color: #dc3545;"><?php echo $total_checks - $passed_checks; ?></div>
                    <div class="label">Fallidas</div>
                </div>
                <div class="progress-card">
                    <div class="number" style="color: #667eea;"><?php echo $total_checks; ?></div>
                    <div class="label">Total</div>
                </div>
            </div>
            
            <h2 style="margin: 30px 0 20px 0;">Resultados Detallados</h2>
            
            <?php
            // Mostrar todas las verificaciones
            foreach ($checks as $check) {
                ?>
                <div class="check-item <?php echo $check['status']; ?>">
                    <div class="check-header">
                        <span class="check-icon"><?php echo $check['icon']; ?></span>
                        <span class="check-title"><?php echo $check['title']; ?></span>
                    </div>
                    <div class="check-detail"><?php echo $check['detail']; ?></div>
                </div>
                <?php
            }
            
            // ========================================
            // Recomendaciones finales
            // ========================================
            if ($percentage == 100) {
                ?>
                <div class="info-box" style="background: #d4edda; border-color: #28a745;">
                    <h3 style="color: #28a745;">🎉 ¡Todo Está Perfecto!</h3>
                    <p>Tu bot está completamente configurado y listo para usar.</p>
                    <br>
                    <p><strong>Próximos pasos:</strong></p>
                    <ol class="steps">
                        <li>Abre Telegram y busca tu bot</li>
                        <li>Envía <code>/start</code></li>
                        <li>Prueba enviando un TAC: <code>35203310</code></li>
                        <li>¡Disfruta tu bot! 🚀</li>
                    </ol>
                </div>
                <?php
            } elseif ($percentage >= 70) {
                ?>
                <div class="info-box" style="background: #fff3cd; border-color: #ffc107;">
                    <h3 style="color: #856404;">⚠️ Casi Listo</h3>
                    <p>Tu bot está casi configurado. Revisa los puntos marcados con ❌ arriba y corrígelos.</p>
                </div>
                <?php
            } else {
                ?>
                <div class="info-box" style="background: #f8d7da; border-color: #dc3545;">
                    <h3 style="color: #721c24;">❌ Configuración Incompleta</h3>
                    <p>Hay varios problemas que debes corregir antes de que el bot funcione.</p>
                    <br>
                    <p><strong>Sugerencias:</strong></p>
                    <ol class="steps">
                        <li>Corrige manualmente cada punto marcado con ❌</li>
                        <li>Recarga esta página para verificar nuevamente</li>
                        <li>Si el webhook no está configurado, usa el enlace proporcionado arriba</li>
                    </ol>
                </div>
                <?php
            }
            ?>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="?" class="btn">🔄 Verificar Nuevamente</a>
                <?php if (file_exists('test_imeidb.php')): ?>
                    <a href="test_imeidb.php" class="btn" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        🧪 Probar API IMEIDb
                    </a>
                <?php endif; ?>
            </div>
            
            <div class="info-box" style="margin-top: 30px;">
                <h3>💡 Configuración Actual</h3>
                <div class="config-display">
Token: <?php echo substr($config['bot_token'], 0, 10); ?>...<?php echo substr($config['bot_token'], -5); ?>

Bot File: <?php echo $config['bot_file']; ?>

Database: <?php echo $config['db_name']; ?>

Host: <?php echo $config['db_host']; ?>

User: <?php echo $config['db_user']; ?>

API IMEIDb: <?php echo $imeidb_configured ? 'Configurada ✓' : 'No configurada'; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
