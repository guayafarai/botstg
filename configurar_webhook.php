<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * CONFIGURADOR AUTOMÁTICO DE WEBHOOK
 * ═══════════════════════════════════════════════════════════════
 * 
 * Este script configura automáticamente el webhook del bot
 * 
 * INSTRUCCIONES:
 * 1. Sube este archivo a tu servidor
 * 2. Abre en el navegador: https://tu-dominio.com/configurar_webhook.php
 * 3. El webhook se configurará automáticamente
 * 4. ¡Listo para usar!
 * 
 * ═══════════════════════════════════════════════════════════════
 */

require_once(__DIR__ . '/config_bot.php');

// Detectar URL actual del servidor
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$domain = $_SERVER['HTTP_HOST'];
$path = dirname($_SERVER['REQUEST_URI']);
$bot_file = 'bot_imei_corregido.php';

$webhook_url = $protocol . "://" . $domain . $path . "/" . $bot_file;
$api_url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurador de Webhook</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 700px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #667eea;
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .status-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .status-box.success {
            background: #d4edda;
            border-left: 5px solid #28a745;
        }
        
        .status-box.error {
            background: #f8d7da;
            border-left: 5px solid #dc3545;
        }
        
        .status-box.info {
            background: #d1ecf1;
            border-left: 5px solid #0c5460;
        }
        
        .status-icon {
            font-size: 48px;
            text-align: center;
            margin-bottom: 15px;
        }
        
        .status-title {
            font-size: 24px;
            font-weight: bold;
            text-align: center;
            margin-bottom: 10px;
        }
        
        .status-message {
            text-align: center;
            line-height: 1.6;
        }
        
        .details {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin-top: 20px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
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
            margin: 10px 5px;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .actions {
            text-align: center;
            margin-top: 30px;
        }
        
        code {
            background: rgba(0,0,0,0.1);
            padding: 2px 6px;
            border-radius: 3px;
            word-break: break-all;
        }

        .warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
        }

        .warning-title {
            color: #856404;
            font-weight: bold;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚙️ Configurador de Webhook</h1>
            <p>Configuración automática para tu Bot de Telegram</p>
        </div>

        <?php
        // Verificar SSL
        if ($protocol !== 'https') {
            ?>
            <div class="status-box error">
                <div class="status-icon">❌</div>
                <div class="status-title">SSL/HTTPS No Detectado</div>
                <div class="status-message">
                    <p>Tu sitio NO está usando HTTPS.</p>
                    <p>Telegram <strong>requiere HTTPS</strong> para webhooks.</p>
                    <br>
                    <p><strong>Solución:</strong></p>
                    <p>Contacta a tu proveedor de hosting para activar el certificado SSL.</p>
                </div>
            </div>
            <?php
            exit;
        }

        // Verificar que el token esté configurado
        if (!defined('BOT_TOKEN') || BOT_TOKEN === 'TU_TOKEN_AQUI') {
            ?>
            <div class="status-box error">
                <div class="status-icon">❌</div>
                <div class="status-title">Token No Configurado</div>
                <div class="status-message">
                    <p>No se ha configurado el token del bot.</p>
                    <p>Edita el archivo <code>config_bot.php</code> con tu token de @BotFather.</p>
                </div>
            </div>
            <?php
            exit;
        }

        // Verificar que el archivo del bot exista
        $bot_file_path = __DIR__ . '/' . $bot_file;
        if (!file_exists($bot_file_path)) {
            ?>
            <div class="status-box error">
                <div class="status-icon">❌</div>
                <div class="status-title">Archivo del Bot No Encontrado</div>
                <div class="status-message">
                    <p>No se encuentra el archivo: <code><?php echo $bot_file; ?></code></p>
                    <p>Asegúrate de que el archivo esté en el mismo directorio que este configurador.</p>
                </div>
            </div>
            <?php
            exit;
        }

        // Intentar configurar el webhook
        $set_webhook_url = $api_url . 'setWebhook?' . http_build_query([
            'url' => $webhook_url,
            'drop_pending_updates' => true
        ]);

        $response = @file_get_contents($set_webhook_url);
        
        if ($response === false) {
            ?>
            <div class="status-box error">
                <div class="status-icon">❌</div>
                <div class="status-title">Error de Conexión</div>
                <div class="status-message">
                    <p>No se pudo conectar a la API de Telegram.</p>
                    <p>Verifica tu conexión a internet.</p>
                </div>
            </div>
            <?php
            exit;
        }

        $result = json_decode($response, true);

        if (isset($result['ok']) && $result['ok']) {
            // Webhook configurado exitosamente
            ?>
            <div class="status-box success">
                <div class="status-icon">✅</div>
                <div class="status-title">¡Webhook Configurado!</div>
                <div class="status-message">
                    <p>El webhook se ha configurado correctamente.</p>
                    <p><strong>Tu bot está listo para recibir mensajes.</strong></p>
                </div>
            </div>

            <div class="details">
                <strong>📍 URL del Webhook:</strong><br>
                <?php echo $webhook_url; ?><br><br>
                
                <strong>🔑 Token del Bot:</strong><br>
                <?php echo substr(BOT_TOKEN, 0, 15); ?>...<?php echo substr(BOT_TOKEN, -5); ?><br><br>
                
                <strong>📊 Estado:</strong><br>
                Activo ✓
            </div>

            <?php
            // Obtener información del webhook
            $webhook_info_url = $api_url . 'getWebhookInfo';
            $info_response = @file_get_contents($webhook_info_url);
            
            if ($info_response) {
                $webhook_info = json_decode($info_response, true);
                if (isset($webhook_info['result'])) {
                    $info = $webhook_info['result'];
                    ?>
                    <div class="status-box info">
                        <div class="status-title">📊 Información del Webhook</div>
                        <div class="status-message">
                            <p><strong>URL:</strong> <?php echo $info['url'] ?? 'N/A'; ?></p>
                            <p><strong>Mensajes pendientes:</strong> <?php echo $info['pending_update_count'] ?? 0; ?></p>
                            <?php if (isset($info['last_error_message']) && !empty($info['last_error_message'])): ?>
                                <p><strong>⚠️ Último error:</strong> <?php echo $info['last_error_message']; ?></p>
                                <p><strong>Fecha del error:</strong> <?php echo date('Y-m-d H:i:s', $info['last_error_date']); ?></p>
                            <?php else: ?>
                                <p>✓ Sin errores</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                }
            }
            ?>

            <div class="warning">
                <div class="warning-title">💡 Próximos Pasos:</div>
                <ol style="margin-left: 20px; line-height: 1.8;">
                    <li>Abre Telegram y busca tu bot</li>
                    <li>Envía el comando: <code>/start</code></li>
                    <li>Prueba enviando un TAC de 8 dígitos, por ejemplo: <code>35203310</code></li>
                    <li>El bot debería responder con información del dispositivo</li>
                </ol>
            </div>

            <?php
        } else {
            // Error al configurar webhook
            ?>
            <div class="status-box error">
                <div class="status-icon">❌</div>
                <div class="status-title">Error al Configurar Webhook</div>
                <div class="status-message">
                    <p><strong>Error:</strong> <?php echo $result['description'] ?? 'Error desconocido'; ?></p>
                </div>
            </div>

            <div class="details">
                <strong>Respuesta completa de Telegram:</strong><br>
                <?php echo htmlspecialchars($response); ?>
            </div>

            <div class="warning">
                <div class="warning-title">🔧 Posibles Soluciones:</div>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li>Verifica que el token sea correcto</li>
                    <li>Asegúrate de que tu sitio use HTTPS (SSL activo)</li>
                    <li>Verifica que el archivo del bot sea accesible públicamente</li>
                    <li>Contacta a tu proveedor de hosting si el problema persiste</li>
                </ul>
            </div>
            <?php
        }
        ?>

        <div class="actions">
            <a href="?" class="btn">🔄 Reconfigurar</a>
            <?php if (file_exists('verificar_actualizado.php')): ?>
                <a href="verificar_actualizado.php" class="btn">🔍 Ver Diagnóstico</a>
            <?php endif; ?>
            <?php if (file_exists('test_imeidb.php')): ?>
                <a href="test_imeidb.php" class="btn">🧪 Probar API</a>
            <?php endif; ?>
        </div>

        <div class="details" style="margin-top: 30px;">
            <strong>🛠️ Información Técnica:</strong><br>
            Servidor: <?php echo $domain; ?><br>
            Protocolo: <?php echo strtoupper($protocol); ?><br>
            Archivo del bot: <?php echo $bot_file; ?><br>
            PHP Version: <?php echo PHP_VERSION; ?>
        </div>
    </div>
</body>
</html>
