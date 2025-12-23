<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * AUDITOR COMPLETO DE BASE DE DATOS
 * Verifica estructura, columnas, índices, datos y compatibilidad
 * ═══════════════════════════════════════════════════════════════
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cargar configuración
if (!file_exists(__DIR__ . '/config_bot.php')) {
    die("❌ Error: No se encuentra config_bot.php\n");
}

require_once(__DIR__ . '/config_bot.php');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditor de Base de Datos SQL</title>
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
            max-width: 1200px;
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
        
        .section {
            margin: 30px 0;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .section-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
        }
        
        .section-header:hover {
            background: #e9ecef;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-content {
            padding: 20px;
        }
        
        .status-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-ok {
            background: #d4edda;
            color: #155724;
        }
        
        .status-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-error {
            background: #f8d7da;
            color: #721c24;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 14px;
        }
        
        table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
        }
        
        table td {
            padding: 10px 12px;
            border-bottom: 1px solid #dee2e6;
        }
        
        table tr:hover {
            background: #f8f9fa;
        }
        
        .code-block {
            background: #1e1e1e;
            color: #00ff00;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin: 10px 0;
        }
        
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #0066cc;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
        }
        
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
        }
        
        .error-box {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
        }
        
        .success-box {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
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
            border: 1px solid #e0e0e0;
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
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .icon {
            font-size: 24px;
        }
        
        .collapsible-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .collapsible-content.active {
            max-height: 5000px;
        }
        
        .toggle-icon {
            transition: transform 0.3s;
        }
        
        .toggle-icon.rotated {
            transform: rotate(180deg);
        }

        code {
            background: rgba(0,0,0,0.1);
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }

        .index-type {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .index-primary {
            background: #d4edda;
            color: #155724;
        }

        .index-unique {
            background: #d1ecf1;
            color: #0c5460;
        }

        .index-normal {
            background: #f8f9fa;
            color: #495057;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Auditor de Base de Datos SQL</h1>
            <p>Análisis Completo de Estructura y Datos</p>
        </div>
        
        <div class="content">
            <?php
            try {
                // Conectar a la base de datos
                $pdo = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                    DB_USER,
                    DB_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ]
                );
                
                echo '<div class="success-box">';
                echo '<strong>✅ Conexión Exitosa</strong><br>';
                echo 'Base de datos: <strong>' . DB_NAME . '</strong><br>';
                echo 'Host: <strong>' . DB_HOST . '</strong>';
                echo '</div>';
                
                // ================================================================
                // INFORMACIÓN GENERAL DEL SERVIDOR
                // ================================================================
                echo '<div class="section">';
                echo '<div class="section-header" onclick="toggleSection(this)">';
                echo '<span class="section-title"><span class="icon">🖥️</span> Información del Servidor MySQL</span>';
                echo '<span class="toggle-icon">▼</span>';
                echo '</div>';
                echo '<div class="section-content collapsible-content active">';
                
                $serverInfo = $pdo->query("SELECT VERSION() as version")->fetch();
                $charset = $pdo->query("SELECT @@character_set_database as charset")->fetch();
                $collation = $pdo->query("SELECT @@collation_database as collation")->fetch();
                $timezone = $pdo->query("SELECT @@time_zone as timezone")->fetch();
                
                echo '<div class="stats-grid">';
                echo '<div class="stat-card">';
                echo '<div class="stat-number" style="font-size: 20px;">' . htmlspecialchars($serverInfo['version']) . '</div>';
                echo '<div class="stat-label">Versión MySQL</div>';
                echo '</div>';
                
                echo '<div class="stat-card">';
                echo '<div class="stat-number" style="font-size: 20px;">' . htmlspecialchars($charset['charset']) . '</div>';
                echo '<div class="stat-label">Charset</div>';
                echo '</div>';
                
                echo '<div class="stat-card">';
                echo '<div class="stat-number" style="font-size: 20px;">' . htmlspecialchars($collation['collation']) . '</div>';
                echo '<div class="stat-label">Collation</div>';
                echo '</div>';
                
                echo '<div class="stat-card">';
                echo '<div class="stat-number" style="font-size: 20px;">' . htmlspecialchars($timezone['timezone']) . '</div>';
                echo '<div class="stat-label">Timezone</div>';
                echo '</div>';
                echo '</div>';
                
                echo '</div>';
                echo '</div>';
                
                // ================================================================
                // LISTADO DE TABLAS
                // ================================================================
                $tablas = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                
                echo '<div class="summary-box">';
                echo '<h2>' . count($tablas) . '</h2>';
                echo '<p>Tablas encontradas en la base de datos</p>';
                echo '</div>';
                
                echo '<div class="section">';
                echo '<div class="section-header" onclick="toggleSection(this)">';
                echo '<span class="section-title"><span class="icon">📊</span> Resumen de Tablas</span>';
                echo '<span class="toggle-icon">▼</span>';
                echo '</div>';
                echo '<div class="section-content collapsible-content active">';
                
                echo '<table>';
                echo '<thead>';
                echo '<tr>';
                echo '<th>Tabla</th>';
                echo '<th>Registros</th>';
                echo '<th>Tamaño</th>';
                echo '<th>Motor</th>';
                echo '<th>Collation</th>';
                echo '<th>Estado</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';
                
                $totalRegistros = 0;
                $totalSize = 0;
                
                foreach ($tablas as $tabla) {
                    $statusQuery = $pdo->query("SHOW TABLE STATUS LIKE '$tabla'")->fetch();
                    $count = $pdo->query("SELECT COUNT(*) as total FROM `$tabla`")->fetch();
                    
                    $registros = $count['total'];
                    $size = $statusQuery['Data_length'] + $statusQuery['Index_length'];
                    $sizeFormatted = formatBytes($size);
                    $engine = $statusQuery['Engine'];
                    $collation = $statusQuery['Collation'];
                    
                    $totalRegistros += $registros;
                    $totalSize += $size;
                    
                    $statusClass = $registros > 0 ? 'status-ok' : 'status-warning';
                    $statusText = $registros > 0 ? 'Con datos' : 'Vacía';
                    
                    echo '<tr>';
                    echo '<td><strong>' . htmlspecialchars($tabla) . '</strong></td>';
                    echo '<td>' . number_format($registros) . '</td>';
                    echo '<td>' . $sizeFormatted . '</td>';
                    echo '<td>' . htmlspecialchars($engine) . '</td>';
                    echo '<td><small>' . htmlspecialchars($collation) . '</small></td>';
                    echo '<td><span class="status-badge ' . $statusClass . '">' . $statusText . '</span></td>';
                    echo '</tr>';
                }
                
                echo '</tbody>';
                echo '<tfoot>';
                echo '<tr style="background: #f8f9fa; font-weight: bold;">';
                echo '<td>TOTAL</td>';
                echo '<td>' . number_format($totalRegistros) . '</td>';
                echo '<td>' . formatBytes($totalSize) . '</td>';
                echo '<td colspan="3"></td>';
                echo '</tr>';
                echo '</tfoot>';
                echo '</table>';
                
                echo '</div>';
                echo '</div>';
                
                // ================================================================
                // ANÁLISIS DETALLADO DE CADA TABLA
                // ================================================================
                
                $tablasEsenciales = [
                    'usuarios' => [
                        'descripcion' => 'Tabla principal de usuarios del bot',
                        'columnas_requeridas' => ['id', 'telegram_id', 'username', 'first_name', 'creditos', 'total_generaciones', 'es_premium', 'bloqueado']
                    ],
                    'transacciones' => [
                        'descripcion' => 'Registro de todas las transacciones de créditos',
                        'columnas_requeridas' => ['id', 'telegram_id', 'tipo', 'cantidad', 'descripcion', 'fecha']
                    ],
                    'historial_uso' => [
                        'descripcion' => 'Historial de generaciones de IMEI',
                        'columnas_requeridas' => ['id', 'telegram_id', 'tac', 'modelo', 'creditos_usados', 'fecha']
                    ],
                    'tac_modelos' => [
                        'descripcion' => 'Base de datos de modelos de dispositivos',
                        'columnas_requeridas' => ['id', 'tac', 'modelo', 'marca', 'fuente', 'veces_usado']
                    ],
                    'pagos_pendientes' => [
                        'descripcion' => 'Sistema de pagos y compras de créditos',
                        'columnas_requeridas' => ['id', 'telegram_id', 'paquete', 'creditos', 'monto', 'moneda', 'metodo_pago', 'estado', 'captura_file_id']
                    ],
                    'api_cache' => [
                        'descripcion' => 'Cache de consultas a la API de IMEI',
                        'columnas_requeridas' => ['id', 'imei', 'datos', 'fecha_consulta']
                    ]
                ];
                
                foreach ($tablasEsenciales as $nombreTabla => $info) {
                    if (!in_array($nombreTabla, $tablas)) {
                        echo '<div class="section">';
                        echo '<div class="section-header">';
                        echo '<span class="section-title"><span class="icon">⚠️</span> ' . ucfirst($nombreTabla) . '</span>';
                        echo '<span class="status-badge status-error">NO EXISTE</span>';
                        echo '</div>';
                        echo '<div class="section-content">';
                        echo '<div class="error-box">';
                        echo '<strong>⚠️ Tabla Faltante</strong><br>';
                        echo $info['descripcion'] . '<br><br>';
                        echo '<strong>Acción requerida:</strong> Ejecutar script SQL de instalación';
                        echo '</div>';
                        echo '</div>';
                        echo '</div>';
                        continue;
                    }
                    
                    echo '<div class="section">';
                    echo '<div class="section-header" onclick="toggleSection(this)">';
                    echo '<span class="section-title"><span class="icon">📋</span> ' . ucfirst($nombreTabla) . '</span>';
                    echo '<span class="toggle-icon">▼</span>';
                    echo '</div>';
                    echo '<div class="section-content collapsible-content">';
                    
                    // Descripción
                    echo '<div class="info-box">';
                    echo '<strong>Descripción:</strong> ' . $info['descripcion'];
                    echo '</div>';
                    
                    // Obtener columnas
                    $columnas = $pdo->query("SHOW FULL COLUMNS FROM `$nombreTabla`")->fetchAll();
                    
                    echo '<h3>Columnas (' . count($columnas) . ')</h3>';
                    echo '<table>';
                    echo '<thead>';
                    echo '<tr>';
                    echo '<th>Campo</th>';
                    echo '<th>Tipo</th>';
                    echo '<th>Nulo</th>';
                    echo '<th>Key</th>';
                    echo '<th>Default</th>';
                    echo '<th>Extra</th>';
                    echo '<th>Comentario</th>';
                    echo '</tr>';
                    echo '</thead>';
                    echo '<tbody>';
                    
                    $columnasPresentes = [];
                    foreach ($columnas as $col) {
                        $columnasPresentes[] = $col['Field'];
                        
                        $nullClass = $col['Null'] === 'YES' ? 'status-warning' : 'status-ok';
                        $keyIcon = '';
                        if ($col['Key'] === 'PRI') $keyIcon = '🔑';
                        elseif ($col['Key'] === 'UNI') $keyIcon = '⭐';
                        elseif ($col['Key'] === 'MUL') $keyIcon = '🔗';
                        
                        echo '<tr>';
                        echo '<td><strong>' . htmlspecialchars($col['Field']) . '</strong></td>';
                        echo '<td><code>' . htmlspecialchars($col['Type']) . '</code></td>';
                        echo '<td><span class="status-badge ' . $nullClass . '">' . $col['Null'] . '</span></td>';
                        echo '<td>' . $keyIcon . ' ' . htmlspecialchars($col['Key']) . '</td>';
                        echo '<td>' . htmlspecialchars($col['Default'] ?? 'NULL') . '</td>';
                        echo '<td><small>' . htmlspecialchars($col['Extra']) . '</small></td>';
                        echo '<td><small>' . htmlspecialchars($col['Comment']) . '</small></td>';
                        echo '</tr>';
                    }
                    
                    echo '</tbody>';
                    echo '</table>';
                    
                    // Verificar columnas faltantes
                    $columnasFaltantes = array_diff($info['columnas_requeridas'], $columnasPresentes);
                    if (!empty($columnasFaltantes)) {
                        echo '<div class="warning-box">';
                        echo '<strong>⚠️ Columnas Faltantes:</strong><br>';
                        echo implode(', ', $columnasFaltantes);
                        echo '</div>';
                    }
                    
                    // Obtener índices
                    $indices = $pdo->query("SHOW INDEX FROM `$nombreTabla`")->fetchAll();
                    
                    if (!empty($indices)) {
                        echo '<h3>Índices (' . count($indices) . ')</h3>';
                        echo '<table>';
                        echo '<thead>';
                        echo '<tr>';
                        echo '<th>Nombre</th>';
                        echo '<th>Tipo</th>';
                        echo '<th>Columna</th>';
                        echo '<th>Único</th>';
                        echo '<th>Cardinalidad</th>';
                        echo '</tr>';
                        echo '</thead>';
                        echo '<tbody>';
                        
                        foreach ($indices as $idx) {
                            $tipo = $idx['Key_name'] === 'PRIMARY' ? 'PRIMARY' : 
                                   ($idx['Non_unique'] == 0 ? 'UNIQUE' : 'INDEX');
                            
                            $tipoClass = $tipo === 'PRIMARY' ? 'index-primary' : 
                                        ($tipo === 'UNIQUE' ? 'index-unique' : 'index-normal');
                            
                            echo '<tr>';
                            echo '<td><strong>' . htmlspecialchars($idx['Key_name']) . '</strong></td>';
                            echo '<td><span class="index-type ' . $tipoClass . '">' . $tipo . '</span></td>';
                            echo '<td>' . htmlspecialchars($idx['Column_name']) . '</td>';
                            echo '<td>' . ($idx['Non_unique'] == 0 ? '✓' : '✗') . '</td>';
                            echo '<td>' . number_format($idx['Cardinality'] ?? 0) . '</td>';
                            echo '</tr>';
                        }
                        
                        echo '</tbody>';
                        echo '</table>';
                    }
                    
                    // Muestra de datos
                    $count = $pdo->query("SELECT COUNT(*) as total FROM `$nombreTabla`")->fetch()['total'];
                    
                    if ($count > 0) {
                        echo '<h3>Muestra de Datos (primeros 5 registros)</h3>';
                        
                        $muestra = $pdo->query("SELECT * FROM `$nombreTabla` LIMIT 5")->fetchAll();
                        
                        echo '<table>';
                        echo '<thead><tr>';
                        foreach (array_keys($muestra[0]) as $header) {
                            echo '<th>' . htmlspecialchars($header) . '</th>';
                        }
                        echo '</tr></thead>';
                        echo '<tbody>';
                        
                        foreach ($muestra as $row) {
                            echo '<tr>';
                            foreach ($row as $value) {
                                $displayValue = $value;
                                if (strlen($displayValue) > 50) {
                                    $displayValue = substr($displayValue, 0, 50) . '...';
                                }
                                echo '<td>' . htmlspecialchars($displayValue ?? 'NULL') . '</td>';
                            }
                            echo '</tr>';
                        }
                        
                        echo '</tbody>';
                        echo '</table>';
                        
                        echo '<div class="info-box">';
                        echo 'Total de registros: <strong>' . number_format($count) . '</strong>';
                        echo '</div>';
                    } else {
                        echo '<div class="warning-box">';
                        echo '⚠️ Esta tabla está vacía (0 registros)';
                        echo '</div>';
                    }
                    
                    echo '</div>';
                    echo '</div>';
                }
                
                // ================================================================
                // OTRAS TABLAS (no esenciales)
                // ================================================================
                $otrasTablas = array_diff($tablas, array_keys($tablasEsenciales));
                
                if (!empty($otrasTablas)) {
                    echo '<div class="section">';
                    echo '<div class="section-header" onclick="toggleSection(this)">';
                    echo '<span class="section-title"><span class="icon">📦</span> Otras Tablas (' . count($otrasTablas) . ')</span>';
                    echo '<span class="toggle-icon">▼</span>';
                    echo '</div>';
                    echo '<div class="section-content collapsible-content">';
                    
                    echo '<table>';
                    echo '<thead>';
                    echo '<tr>';
                    echo '<th>Tabla</th>';
                    echo '<th>Columnas</th>';
                    echo '<th>Registros</th>';
                    echo '<th>Descripción</th>';
                    echo '</tr>';
                    echo '</thead>';
                    echo '<tbody>';
                    
                    foreach ($otrasTablas as $tabla) {
                        $columnas = $pdo->query("SHOW COLUMNS FROM `$tabla`")->fetchAll();
                        $count = $pdo->query("SELECT COUNT(*) as total FROM `$tabla`")->fetch()['total'];
                        
                        echo '<tr>';
                        echo '<td><strong>' . htmlspecialchars($tabla) . '</strong></td>';
                        echo '<td>' . count($columnas) . '</td>';
                        echo '<td>' . number_format($count) . '</td>';
                        echo '<td><small>Tabla adicional del sistema</small></td>';
                        echo '</tr>';
                    }
                    
                    echo '</tbody>';
                    echo '</table>';
                    
                    echo '</div>';
                    echo '</div>';
                }
                
                // ================================================================
                // VERIFICACIÓN DE INTEGRIDAD
                // ================================================================
                echo '<div class="section">';
                echo '<div class="section-header" onclick="toggleSection(this)">';
                echo '<span class="section-title"><span class="icon">✅</span> Verificación de Integridad</span>';
                echo '<span class="toggle-icon">▼</span>';
                echo '</div>';
                echo '<div class="section-content collapsible-content">';
                
                $checks = [];
                
                // Check 1: Foreign Keys
                if (in_array('usuarios', $tablas) && in_array('transacciones', $tablas)) {
                    $fkCheck = $pdo->query("
                        SELECT COUNT(*) as huerfanos 
                        FROM transacciones t 
                        LEFT JOIN usuarios u ON t.telegram_id = u.telegram_id 
                        WHERE u.telegram_id IS NULL
                    ")->fetch();
                    
                    if ($fkCheck['huerfanos'] == 0) {
                        $checks[] = ['status' => 'ok', 'mensaje' => 'Integridad transacciones ↔ usuarios: OK'];
                    } else {
                        $checks[] = ['status' => 'warning', 'mensaje' => 'Hay ' . $fkCheck['huerfanos'] . ' transacciones huérfanas (sin usuario asociado)'];
                    }
                }
                
                // Check 2: Datos duplicados en usuarios
                if (in_array('usuarios', $tablas)) {
                    $dupCheck = $pdo->query("
                        SELECT COUNT(*) - COUNT(DISTINCT telegram_id) as duplicados 
                        FROM usuarios
                    ")->fetch();
                    
                    if ($dupCheck['duplicados'] == 0) {
                        $checks[] = ['status' => 'ok', 'mensaje' => 'No hay telegram_id duplicados en usuarios'];
                    } else {
                        $checks[] = ['status' => 'error', 'mensaje' => 'HAY ' . $dupCheck['duplicados'] . ' telegram_id duplicados!'];
                    }
                }
                
                // Check 3: Créditos negativos
                if (in_array('usuarios', $tablas)) {
                    $negCheck = $pdo->query("
                        SELECT COUNT(*) as negativos 
                        FROM usuarios 
                        WHERE creditos < 0
                    ")->fetch();
                    
                    if ($negCheck['negativos'] == 0) {
                        $checks[] = ['status' => 'ok', 'mensaje' => 'No hay usuarios con créditos negativos'];
                    } else {
                        $checks[] = ['status' => 'error', 'mensaje' => 'HAY ' . $negCheck['negativos'] . ' usuarios con créditos negativos!'];
                    }
                }
                
                // Check 4: Cache antiguo
                if (in_array('api_cache', $tablas)) {
                    $oldCache = $pdo->query("
                        SELECT COUNT(*) as antiguos 
                        FROM api_cache 
                        WHERE TIMESTAMPDIFF(DAY, fecha_consulta, NOW()) > 60
                    ")->fetch();
                    
                    if ($oldCache['antiguos'] == 0) {
                        $checks[] = ['status' => 'ok', 'mensaje' => 'Cache de API está limpio'];
                    } else {
                        $checks[] = ['status' => 'warning', 'mensaje' => 'Hay ' . $oldCache['antiguos'] . ' registros de cache antiguos (>60 días) - considerar limpiar'];
                    }
                }
                
                // Mostrar checks
                foreach ($checks as $check) {
                    $class = $check['status'] === 'ok' ? 'success-box' : 
                            ($check['status'] === 'warning' ? 'warning-box' : 'error-box');
                    
                    echo '<div class="' . $class . '">';
                    echo $check['mensaje'];
                    echo '</div>';
                }
                
                echo '</div>';
                echo '</div>';
                
                // ================================================================
                // RECOMENDACIONES
                // ================================================================
                echo '<div class="section">';
                echo '<div class="section-header" onclick="toggleSection(this)">';
                echo '<span class="section-title"><span class="icon">💡</span> Recomendaciones</span>';
                echo '<span class="toggle-icon">▼</span>';
                echo '</div>';
                echo '<div class="section-content collapsible-content">';
                
                $recomendaciones = [];
                
                // Verificar índices importantes
                if (in_array('usuarios', $tablas)) {
                    $indices = $pdo->query("SHOW INDEX FROM usuarios WHERE Column_name = 'telegram_id'")->fetchAll();
                    if (empty($indices)) {
                        $recomendaciones[] = 'Crear índice en usuarios.telegram_id para mejorar rendimiento';
                    }
                }
                
                if (in_array('tac_modelos', $tablas)) {
                    $indices = $pdo->query("SHOW INDEX FROM tac_modelos WHERE Column_name = 'tac'")->fetchAll();
                    if (empty($indices)) {
                        $recomendaciones[] = 'Crear índice en tac_modelos.tac para búsquedas más rápidas';
                    }
                }
                
                // Verificar charset
                $tables_charset = $pdo->query("
                    SELECT TABLE_NAME, TABLE_COLLATION 
                    FROM information_schema.TABLES 
                    WHERE TABLE_SCHEMA = '" . DB_NAME . "' 
                    AND TABLE_COLLATION NOT LIKE 'utf8%'
                ")->fetchAll();
                
                if (!empty($tables_charset)) {
                    $recomendaciones[] = 'Algunas tablas no usan UTF-8: ' . implode(', ', array_column($tables_charset, 'TABLE_NAME'));
                }
                
                // Verificar tamaño
                if ($totalSize > 100 * 1024 * 1024) { // > 100 MB
                    $recomendaciones[] = 'La base de datos es grande (' . formatBytes($totalSize) . '). Considerar optimización.';
                }
                
                if (empty($recomendaciones)) {
                    echo '<div class="success-box">';
                    echo '✅ No hay recomendaciones críticas. La base de datos está bien configurada.';
                    echo '</div>';
                } else {
                    foreach ($recomendaciones as $rec) {
                        echo '<div class="warning-box">';
                        echo '💡 ' . $rec;
                        echo '</div>';
                    }
                }
                
                echo '</div>';
                echo '</div>';
                
                // ================================================================
                // EXPORT SQL
                // ================================================================
                echo '<div class="section">';
                echo '<div class="section-header" onclick="toggleSection(this)">';
                echo '<span class="section-title"><span class="icon">📄</span> Script SQL de Estructura</span>';
                echo '<span class="toggle-icon">▼</span>';
                echo '</div>';
                echo '<div class="section-content collapsible-content">';
                
                echo '<div class="info-box">';
                echo 'Este es el script SQL que recrea la estructura completa de tu base de datos:';
                echo '</div>';
                
                echo '<div class="code-block">';
                echo '-- Script generado automáticamente: ' . date('Y-m-d H:i:s') . "\n";
                echo '-- Base de datos: ' . DB_NAME . "\n\n";
                
                foreach ($tablas as $tabla) {
                    $createTable = $pdo->query("SHOW CREATE TABLE `$tabla`")->fetch();
                    echo $createTable['Create Table'] . ";\n\n";
                }
                
                echo '</div>';
                
                echo '</div>';
                echo '</div>';
                
            } catch (PDOException $e) {
                echo '<div class="error-box">';
                echo '<strong>❌ Error de Conexión</strong><br>';
                echo 'No se pudo conectar a la base de datos.<br><br>';
                echo '<strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '<br><br>';
                echo '<strong>Verifica:</strong><br>';
                echo '• Host: ' . DB_HOST . '<br>';
                echo '• Base de datos: ' . DB_NAME . '<br>';
                echo '• Usuario: ' . DB_USER . '<br>';
                echo '• Que la base de datos exista<br>';
                echo '• Que el usuario tenga permisos';
                echo '</div>';
            }
            
            function formatBytes($bytes, $precision = 2) {
                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                
                for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
                    $bytes /= 1024;
                }
                
                return round($bytes, $precision) . ' ' . $units[$i];
            }
            ?>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="?" class="btn">🔄 Actualizar Análisis</a>
                <a href="verificar.php" class="btn">🔍 Verificar Bot</a>
                <a href="verificar_pagos.php" class="btn">💳 Verificar Pagos</a>
            </div>
        </div>
    </div>
    
    <script>
        function toggleSection(header) {
            const content = header.nextElementSibling;
            const icon = header.querySelector('.toggle-icon');
            
            content.classList.toggle('active');
            icon.classList.toggle('rotated');
        }
        
        // Expandir primera sección por defecto
        document.addEventListener('DOMContentLoaded', function() {
            const firstSection = document.querySelector('.section-content');
            if (firstSection) {
                firstSection.classList.add('active');
            }
        });
    </script>
</body>
</html>
