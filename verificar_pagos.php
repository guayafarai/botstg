<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * VERIFICADOR DEL SISTEMA DE PAGOS
 * ═══════════════════════════════════════════════════════════════
 */

require_once(__DIR__ . '/config_bot.php');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificador Sistema de Pagos</title>
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }

        .stat-label {
            color: #666;
            margin-top: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        table th, table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        code {
            background: rgba(0,0,0,0.1);
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💳 Verificador de Sistema de Pagos</h1>
            <p>Diagnóstico Completo del Sistema</p>
        </div>
        
        <div class="content">
            <?php
            $checks = [];
            $passed_checks = 0;
            $total_checks = 0;
            
            // Conexión a BD
            try {
                $pdo = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                    DB_USER,
                    DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                
                // ========================================
                // CHECK 1: Tabla pagos_pendientes
                // ========================================
                $total_checks++;
                $stmt = $pdo->query("SHOW TABLES LIKE 'pagos_pendientes'");
                
                if ($stmt->rowCount() > 0) {
                    // Verificar columnas
                    $stmt = $pdo->query("SHOW COLUMNS FROM pagos_pendientes");
                    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $required_columns = [
                        'id', 'telegram_id', 'paquete', 'creditos', 'monto', 
                        'moneda', 'metodo_pago', 'captura_file_id', 'estado',
                        'fecha_solicitud', 'fecha_aprobacion', 'admin_id'
                    ];
                    
                    $missing_columns = array_diff($required_columns, $columns);
                    
                    if (empty($missing_columns)) {
                        $passed_checks++;
                        $checks[] = [
                            'status' => 'success',
                            'icon' => '✅',
                            'title' => 'Tabla pagos_pendientes',
                            'detail' => "Tabla correctamente configurada con " . count($columns) . " columnas"
                        ];
                    } else {
                        $checks[] = [
                            'status' => 'error',
                            'icon' => '❌',
                            'title' => 'Columnas Faltantes en pagos_pendientes',
                            'detail' => "Faltan: " . implode(', ', $missing_columns) . "<br>Ejecuta el script SQL de actualización"
                        ];
                    }
                } else {
                    $checks[] = [
                        'status' => 'error',
                        'icon' => '❌',
                        'title' => 'Tabla pagos_pendientes No Existe',
                        'detail' => "Ejecuta el script sql_sistema_pagos.sql"
                    ];
                }
                
                // ========================================
                // CHECK 2: Tabla cupones
                // ========================================
                $total_checks++;
                $stmt = $pdo->query("SHOW TABLES LIKE 'cupones'");
                
                if ($stmt->rowCount() > 0) {
                    $passed_checks++;
                    
                    // Contar cupones
                    $stmt = $pdo->query("SELECT COUNT(*) as total, 
                                               SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activos
                                        FROM cupones");
                    $cuponesStats = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $checks[] = [
                        'status' => 'success',
                        'icon' => '✅',
                        'title' => 'Sistema de Cupones',
                        'detail' => "Tabla configurada. Total: {$cuponesStats['total']}, Activos: {$cuponesStats['activos']}"
                    ];
                } else {
                    $checks[] = [
                        'status' => 'warning',
                        'icon' => '⚠️',
                        'title' => 'Tabla cupones No Existe',
                        'detail' => "Sistema de cupones no disponible. Ejecuta sql_sistema_pagos.sql"
                    ];
                }
                
                // ========================================
                // CHECK 3: Tabla paquetes_creditos
                // ========================================
                $total_checks++;
                $stmt = $pdo->query("SHOW TABLES LIKE 'paquetes_creditos'");
                
                if ($stmt->rowCount() > 0) {
                    $passed_checks++;
                    
                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM paquetes_creditos WHERE activo = 1");
                    $paquetesCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                    
                    $checks[] = [
                        'status' => 'success',
                        'icon' => '✅',
                        'title' => 'Paquetes de Créditos',
                        'detail' => "Configurado. {$paquetesCount} paquetes activos"
                    ];
                } else {
                    $checks[] = [
                        'status' => 'warning',
                        'icon' => '⚠️',
                        'title' => 'Tabla paquetes_creditos No Existe',
                        'detail' => "Paquetes se definirán en código. Recomendado: ejecutar sql_sistema_pagos.sql"
                    ];
                }
                
                // ========================================
                // CHECK 4: Tabla metodos_pago_config
                // ========================================
                $total_checks++;
                $stmt = $pdo->query("SHOW TABLES LIKE 'metodos_pago_config'");
                
                if ($stmt->rowCount() > 0) {
                    $passed_checks++;
                    
                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM metodos_pago_config WHERE activo = 1");
                    $metodosCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                    
                    $checks[] = [
                        'status' => 'success',
                        'icon' => '✅',
                        'title' => 'Métodos de Pago',
                        'detail' => "Configurado. {$metodosCount} métodos activos"
                    ];
                } else {
                    $checks[] = [
                        'status' => 'warning',
                        'icon' => '⚠️',
                        'title' => 'Tabla metodos_pago_config No Existe',
                        'detail' => "Se usarán métodos por defecto. Ejecuta sql_sistema_pagos.sql para configuración dinámica"
                    ];
                }
                
                // ========================================
                // CHECK 5: Archivos del sistema
                // ========================================
                $total_checks++;
                $required_files = [
                    'sistema_pagos.php',
                    'comandos_pagos.php',
                    'sql_sistema_pagos.sql'
                ];
                
                $existing_files = [];
                $missing_files = [];
                
                foreach ($required_files as $file) {
                    if (file_exists(__DIR__ . '/' . $file)) {
                        $existing_files[] = $file;
                    } else {
                        $missing_files[] = $file;
                    }
                }
                
                if (empty($missing_files)) {
                    $passed_checks++;
                    $checks[] = [
                        'status' => 'success',
                        'icon' => '✅',
                        'title' => 'Archivos del Sistema de Pagos',
                        'detail' => "Todos los archivos presentes: " . implode(', ', $existing_files)
                    ];
                } else {
                    $checks[] = [
                        'status' => 'error',
                        'icon' => '❌',
                        'title' => 'Archivos Faltantes',
                        'detail' => "Faltan: " . implode(', ', $missing_files)
                    ];
                }
                
                // ========================================
                // CHECK 6: Estadísticas de Pagos
                // ========================================
                $total_checks++;
                
                try {
                    $stmt = $pdo->query("SELECT 
                                            COUNT(*) as total_pagos,
                                            SUM(CASE WHEN estado = 'aprobado' THEN 1 ELSE 0 END) as aprobados,
                                            SUM(CASE WHEN estado = 'rechazado' THEN 1 ELSE 0 END) as rechazados,
                                            SUM(CASE WHEN estado IN ('pendiente', 'captura_enviada') THEN 1 ELSE 0 END) as pendientes,
                                            SUM(CASE WHEN estado = 'aprobado' AND moneda = 'USD' THEN monto ELSE 0 END) as ingresos_usd,
                                            SUM(CASE WHEN estado = 'aprobado' AND moneda = 'PEN' THEN monto ELSE 0 END) as ingresos_pen,
                                            SUM(CASE WHEN estado = 'aprobado' THEN creditos ELSE 0 END) as creditos_vendidos
                                         FROM pagos_pendientes");
                    
                    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $passed_checks++;
                    $checks[] = [
                        'status' => 'success',
                        'icon' => '✅',
                        'title' => 'Sistema de Pagos Activo',
                        'detail' => "Total pagos: {$stats['total_pagos']}<br>" .
                                   "Aprobados: {$stats['aprobados']}<br>" .
                                   "Pendientes: {$stats['pendientes']}<br>" .
                                   "Ingresos USD: \${$stats['ingresos_usd']}<br>" .
                                   "Ingresos PEN: S/.{$stats['ingresos_pen']}<br>" .
                                   "Créditos vendidos: {$stats['creditos_vendidos']}"
                    ];
                    
                    // Guardar para mostrar después
                    $payment_stats = $stats;
                    
                } catch(PDOException $e) {
                    $checks[] = [
                        'status' => 'warning',
                        'icon' => '⚠️',
                        'title' => 'No Hay Datos de Pagos',
                        'detail' => "Sistema listo pero sin pagos registrados"
                    ];
                }
                
                // ========================================
                // CHECK 7: Vistas y Procedimientos
                // ========================================
                $total_checks++;
                
                try {
                    $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
                    $views = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $expected_views = ['vista_pagos_pendientes', 'vista_estadisticas_diarias', 'vista_rendimiento_metodos'];
                    $existing_views = array_intersect($expected_views, $views);
                    
                    if (count($existing_views) === count($expected_views)) {
                        $passed_checks++;
                        $checks[] = [
                            'status' => 'success',
                            'icon' => '✅',
                            'title' => 'Vistas de Base de Datos',
                            'detail' => "Todas las vistas creadas: " . implode(', ', $existing_views)
                        ];
                    } else {
                        $missing_views = array_diff($expected_views, $existing_views);
                        $checks[] = [
                            'status' => 'warning',
                            'icon' => '⚠️',
                            'title' => 'Vistas Faltantes',
                            'detail' => "Faltan: " . implode(', ', $missing_views) . "<br>Ejecuta sql_sistema_pagos.sql"
                        ];
                    }
                } catch(PDOException $e) {
                    $checks[] = [
                        'status' => 'warning',
                        'icon' => '⚠️',
                        'title' => 'Vistas No Configuradas',
                        'detail' => "Sistema funcionará pero sin reportes avanzados"
                    ];
                }
                
                // ========================================
                // CHECK 8: Triggers
                // ========================================
                $total_checks++;
                
                try {
                    $stmt = $pdo->query("SHOW TRIGGERS FROM " . DB_NAME);
                    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $trigger_names = array_column($triggers, 'Trigger');
                    $expected_triggers = ['after_pago_insert', 'after_pago_update'];
                    $existing_triggers = array_intersect($expected_triggers, $trigger_names);
                    
                    if (count($existing_triggers) === count($expected_triggers)) {
                        $passed_checks++;
                        $checks[] = [
                            'status' => 'success',
                            'icon' => '✅',
                            'title' => 'Triggers Automáticos',
                            'detail' => "Triggers activos para actualización automática de estadísticas"
                        ];
                    } else {
                        $checks[] = [
                            'status' => 'warning',
                            'icon' => '⚠️',
                            'title' => 'Triggers No Configurados',
                            'detail' => "Estadísticas se actualizarán manualmente. Ejecuta sql_sistema_pagos.sql"
                        ];
                    }
                } catch(PDOException $e) {
                    $checks[] = [
                        'status' => 'warning',
                        'icon' => '⚠️',
                        'title' => 'Triggers No Disponibles',
                        'detail' => "Sistema funcionará sin actualización automática de estadísticas"
                    ];
                }
                
            } catch(PDOException $e) {
                $checks[] = [
                    'status' => 'error',
                    'icon' => '❌',
                    'title' => 'Error de Conexión a Base de Datos',
                    'detail' => $e->getMessage()
                ];
            }
            
            // ========================================
            // MOSTRAR RESUMEN
            // ========================================
            $percentage = $total_checks > 0 ? round(($passed_checks / $total_checks) * 100) : 0;
            ?>
            
            <div class="summary-box">
                <h2><?php echo $percentage; ?>%</h2>
                <p><?php echo $passed_checks; ?> de <?php echo $total_checks; ?> verificaciones pasadas</p>
            </div>
            
            <?php if (isset($payment_stats)): ?>
            <div class="info-box">
                <h3>💰 Estadísticas del Sistema de Pagos</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $payment_stats['total_pagos']; ?></div>
                        <div class="stat-label">Total Pagos</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #28a745;"><?php echo $payment_stats['aprobados']; ?></div>
                        <div class="stat-label">Aprobados</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #ffc107;"><?php echo $payment_stats['pendientes']; ?></div>
                        <div class="stat-label">Pendientes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #667eea;"><?php echo $payment_stats['creditos_vendidos']; ?></div>
                        <div class="stat-label">Créditos Vendidos</div>
                    </div>
                </div>
                
                <table>
                    <tr>
                        <th>Ingresos USD</th>
                        <td>$<?php echo number_format($payment_stats['ingresos_usd'], 2); ?></td>
                    </tr>
                    <tr>
                        <th>Ingresos PEN</th>
                        <td>S/. <?php echo number_format($payment_stats['ingresos_pen'], 2); ?></td>
                    </tr>
                    <tr>
                        <th>Tasa de Aprobación</th>
                        <td>
                            <?php 
                            if ($payment_stats['total_pagos'] > 0) {
                                echo round(($payment_stats['aprobados'] / $payment_stats['total_pagos']) * 100, 2) . '%';
                            } else {
                                echo '0%';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
            <?php endif; ?>
            
            <h2 style="margin: 30px 0 20px 0;">Resultados Detallados</h2>
            
            <?php foreach ($checks as $check): ?>
            <div class="check-item <?php echo $check['status']; ?>">
                <div class="check-header">
                    <span class="check-icon"><?php echo $check['icon']; ?></span>
                    <span class="check-title"><?php echo $check['title']; ?></span>
                </div>
                <div class="check-detail"><?php echo $check['detail']; ?></div>
            </div>
            <?php endforeach; ?>
            
            <?php if ($percentage == 100): ?>
            <div class="info-box" style="background: #d4edda; border-color: #28a745;">
                <h3 style="color: #28a745;">🎉 ¡Sistema de Pagos Completamente Configurado!</h3>
                <p><strong>Funcionalidades disponibles:</strong></p>
                <ul style="margin: 15px 0 15px 20px; line-height: 1.8;">
                    <li>✅ Gestión completa de pagos</li>
                    <li>✅ Subida y validación de capturas</li>
                    <li>✅ Sistema de cupones de descuento</li>
                    <li>✅ Múltiples métodos de pago</li>
                    <li>✅ Notificaciones automáticas</li>
                    <li>✅ Reportes y estadísticas</li>
                    <li>✅ Panel de administración</li>
                </ul>
            </div>
            <?php elseif ($percentage >= 70): ?>
            <div class="info-box" style="background: #fff3cd; border-color: #ffc107;">
                <h3 style="color: #856404;">⚠️ Sistema Casi Listo</h3>
                <p>El sistema de pagos está casi completo. Revisa los puntos marcados arriba y:</p>
                <ul style="margin: 15px 0 15px 20px; line-height: 1.8;">
                    <li>Ejecuta <code>sql_sistema_pagos.sql</code> en tu base de datos</li>
                    <li>Verifica que todos los archivos estén presentes</li>
                    <li>Configura los métodos de pago en el código</li>
                </ul>
            </div>
            <?php else: ?>
            <div class="info-box" style="background: #f8d7da; border-color: #dc3545;">
                <h3 style="color: #721c24;">❌ Configuración Incompleta</h3>
                <p><strong>Pasos para completar la instalación:</strong></p>
                <ol style="margin: 15px 0 15px 20px; line-height: 1.8;">
                    <li>Sube todos los archivos del sistema de pagos al servidor</li>
                    <li>Ejecuta <code>sql_sistema_pagos.sql</code> en phpMyAdmin</li>
                    <li>Configura los datos de pago en <code>sistema_pagos.php</code></li>
                    <li>Integra los comandos en <code>bot_imei_corregido.php</code></li>
                    <li>Recarga esta página para verificar</li>
                </ol>
            </div>
            <?php endif; ?>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="?" class="btn">🔄 Verificar Nuevamente</a>
                <a href="verificar.php" class="btn" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                    🔍 Verificar Bot General
                </a>
            </div>
            
            <div class="info-box" style="margin-top: 30px;">
                <h3>📚 Documentación del Sistema de Pagos</h3>
                <p><strong>Comandos de Usuario:</strong></p>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><code>💰 Comprar Créditos</code> - Ver paquetes disponibles</li>
                    <li><code>🎟️ Validar Cupón</code> - Aplicar cupón de descuento</li>
                    <li>Enviar captura de pago como imagen</li>
                </ul>
                
                <p style="margin-top: 15px;"><strong>Comandos de Administrador:</strong></p>
                <ul style="margin: 10px 0 10px 20px;">
                    <li><code>/panel_pagos</code> - Ver estadísticas</li>
                    <li><code>/pagos_pendientes</code> - Ver pagos pendientes</li>
                    <li><code>/detalle [ID]</code> - Ver detalles de pago</li>
                    <li><code>/aprobar [ID]</code> - Aprobar pago</li>
                    <li><code>/rechazar [ID] [motivo]</code> - Rechazar pago</li>
                    <li><code>/crear_cupon [CODIGO] [DESCUENTO]</code> - Crear cupón</li>
                    <li><code>/reporte_mes</code> - Reporte mensual</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
