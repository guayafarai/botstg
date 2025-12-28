-- ═══════════════════════════════════════════════════════════════
-- SCRIPT DE INSTALACIÓN - VERSIÓN SIN EVENTOS
-- Bot Generador de IMEI - Versión 3.0
-- Compatible con usuarios sin privilegios SUPER
-- ═══════════════════════════════════════════════════════════════

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ═══════════════════════════════════════════════════════════════
-- TABLA: usuarios
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `telegram_id` BIGINT(20) NOT NULL,
  `username` VARCHAR(255) DEFAULT NULL,
  `first_name` VARCHAR(255) NOT NULL,
  `last_name` VARCHAR(255) DEFAULT NULL,
  `creditos` INT(11) NOT NULL DEFAULT 0,
  `total_generaciones` INT(11) NOT NULL DEFAULT 0,
  `es_premium` TINYINT(1) NOT NULL DEFAULT 0,
  `bloqueado` TINYINT(1) NOT NULL DEFAULT 0,
  `fecha_registro` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultima_actividad` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_telegram_id` (`telegram_id`),
  KEY `idx_username` (`username`),
  KEY `idx_bloqueado` (`bloqueado`),
  KEY `idx_premium` (`es_premium`),
  KEY `idx_ultima_actividad` (`ultima_actividad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- TABLA: transacciones
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `transacciones` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `telegram_id` BIGINT(20) NOT NULL,
  `tipo` ENUM('registro', 'compra', 'uso', 'admin_add', 'admin_remove', 'bonus') NOT NULL,
  `cantidad` INT(11) NOT NULL,
  `descripcion` TEXT DEFAULT NULL,
  `admin_id` BIGINT(20) DEFAULT NULL,
  `fecha` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_telegram_id` (`telegram_id`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- TABLA: historial_uso
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `historial_uso` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `telegram_id` BIGINT(20) NOT NULL,
  `tac` VARCHAR(8) NOT NULL,
  `modelo` VARCHAR(255) NOT NULL DEFAULT 'Desconocido',
  `creditos_usados` INT(11) NOT NULL DEFAULT 1,
  `fecha` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_telegram_id` (`telegram_id`),
  KEY `idx_tac` (`tac`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_telegram_fecha` (`telegram_id`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- TABLA: tac_modelos
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `tac_modelos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tac` VARCHAR(8) NOT NULL,
  `modelo` VARCHAR(255) NOT NULL,
  `marca` VARCHAR(100) DEFAULT NULL,
  `fuente` ENUM('usuario', 'api', 'imeidb_api', 'manual', 'local') NOT NULL DEFAULT 'usuario',
  `veces_usado` INT(11) NOT NULL DEFAULT 0,
  `fecha_agregado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultima_consulta` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tac` (`tac`),
  KEY `idx_marca` (`marca`),
  KEY `idx_fuente` (`fuente`),
  KEY `idx_veces_usado` (`veces_usado`),
  KEY `idx_ultima_consulta` (`ultima_consulta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- TABLA: pagos_pendientes
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `pagos_pendientes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `telegram_id` BIGINT(20) NOT NULL,
  `paquete` VARCHAR(50) NOT NULL,
  `creditos` INT(11) NOT NULL,
  `monto` DECIMAL(10,2) NOT NULL,
  `moneda` ENUM('PEN', 'USD', 'USDT') NOT NULL DEFAULT 'PEN',
  `metodo_pago` VARCHAR(50) NOT NULL,
  `captura_file_id` VARCHAR(255) DEFAULT NULL,
  `captura_caption` TEXT DEFAULT NULL,
  `estado` ENUM('pendiente', 'esperando_captura', 'captura_enviada', 'aprobado', 'rechazado', 'cancelado') NOT NULL DEFAULT 'pendiente',
  `fecha_solicitud` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_captura` TIMESTAMP NULL DEFAULT NULL,
  `fecha_aprobacion` TIMESTAMP NULL DEFAULT NULL,
  `fecha_rechazo` TIMESTAMP NULL DEFAULT NULL,
  `admin_id` BIGINT(20) DEFAULT NULL,
  `motivo_rechazo` TEXT DEFAULT NULL,
  `notas_admin` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_captura_file_id` (`captura_file_id`),
  KEY `idx_telegram_id` (`telegram_id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_fecha_solicitud` (`fecha_solicitud`),
  KEY `idx_metodo_pago` (`metodo_pago`),
  KEY `idx_telegram_estado` (`telegram_id`, `estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- TABLA: capturas_duplicadas
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `capturas_duplicadas` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `telegram_id` BIGINT(20) NOT NULL,
  `pago_id` INT(11) NOT NULL,
  `file_id` VARCHAR(255) NOT NULL,
  `pago_original_id` INT(11) NOT NULL,
  `fecha` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_telegram_id` (`telegram_id`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_file_id` (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- TABLA: api_cache
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `api_cache` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `imei` VARCHAR(15) NOT NULL,
  `datos` TEXT NOT NULL,
  `fecha_consulta` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_imei` (`imei`),
  KEY `idx_fecha_consulta` (`fecha_consulta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- VISTAS
-- ═══════════════════════════════════════════════════════════════

-- Vista: Intentos de fraude por usuario
CREATE OR REPLACE VIEW `vista_intentos_fraude` AS
SELECT 
    cd.telegram_id,
    u.username,
    u.first_name,
    u.bloqueado,
    COUNT(*) AS total_intentos,
    MAX(cd.fecha) AS ultimo_intento,
    GROUP_CONCAT(DISTINCT cd.pago_id ORDER BY cd.fecha DESC SEPARATOR ', ') AS pagos_afectados
FROM capturas_duplicadas cd
LEFT JOIN usuarios u ON cd.telegram_id = u.telegram_id
GROUP BY cd.telegram_id, u.username, u.first_name, u.bloqueado
ORDER BY total_intentos DESC;

-- Vista: Estadísticas de pagos
CREATE OR REPLACE VIEW `vista_estadisticas_pagos` AS
SELECT 
    COUNT(*) AS total_pagos,
    SUM(CASE WHEN estado = 'aprobado' THEN 1 ELSE 0 END) AS pagos_aprobados,
    SUM(CASE WHEN estado = 'rechazado' THEN 1 ELSE 0 END) AS pagos_rechazados,
    SUM(CASE WHEN estado IN ('pendiente', 'esperando_captura', 'captura_enviada') THEN 1 ELSE 0 END) AS pagos_pendientes,
    SUM(CASE WHEN estado = 'aprobado' THEN monto ELSE 0 END) AS ingresos_totales,
    SUM(CASE WHEN estado = 'aprobado' AND moneda = 'PEN' THEN monto ELSE 0 END) AS ingresos_pen,
    SUM(CASE WHEN estado = 'aprobado' AND moneda = 'USD' THEN monto ELSE 0 END) AS ingresos_usd,
    SUM(CASE WHEN estado = 'aprobado' THEN creditos ELSE 0 END) AS creditos_vendidos,
    AVG(CASE WHEN estado = 'aprobado' THEN monto ELSE NULL END) AS ticket_promedio
FROM pagos_pendientes;

-- Vista: Top usuarios más activos
CREATE OR REPLACE VIEW `vista_top_usuarios` AS
SELECT 
    u.telegram_id,
    u.username,
    u.first_name,
    u.creditos,
    u.total_generaciones,
    u.es_premium,
    u.bloqueado,
    u.fecha_registro,
    COUNT(DISTINCT h.id) AS usos_recientes,
    MAX(h.fecha) AS ultima_generacion
FROM usuarios u
LEFT JOIN historial_uso h ON u.telegram_id = h.telegram_id 
    AND h.fecha > DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY u.telegram_id, u.username, u.first_name, u.creditos, 
         u.total_generaciones, u.es_premium, u.bloqueado, u.fecha_registro
ORDER BY u.total_generaciones DESC;

-- Vista: Modelos más populares
CREATE OR REPLACE VIEW `vista_modelos_populares` AS
SELECT 
    t.tac,
    t.modelo,
    t.marca,
    t.fuente,
    t.veces_usado,
    t.ultima_consulta,
    COUNT(DISTINCT h.telegram_id) AS usuarios_distintos,
    COUNT(h.id) AS usos_totales
FROM tac_modelos t
LEFT JOIN historial_uso h ON t.tac = h.tac
GROUP BY t.tac, t.modelo, t.marca, t.fuente, t.veces_usado, t.ultima_consulta
ORDER BY t.veces_usado DESC;

-- ═══════════════════════════════════════════════════════════════
-- PROCEDIMIENTOS ALMACENADOS
-- ═══════════════════════════════════════════════════════════════

DELIMITER //

-- Procedimiento: Limpiar cache antiguo
CREATE PROCEDURE `sp_limpiar_cache_antiguo`(IN dias INT)
BEGIN
    DELETE FROM api_cache 
    WHERE TIMESTAMPDIFF(DAY, fecha_consulta, NOW()) > dias;
    
    SELECT ROW_COUNT() AS registros_eliminados;
END//

-- Procedimiento: Estadísticas diarias
CREATE PROCEDURE `sp_estadisticas_diarias`(IN fecha DATE)
BEGIN
    SELECT 
        COUNT(DISTINCT u.telegram_id) AS usuarios_activos,
        COUNT(h.id) AS generaciones_totales,
        SUM(h.creditos_usados) AS creditos_consumidos,
        COUNT(DISTINCT h.tac) AS modelos_distintos,
        (SELECT COUNT(*) FROM pagos_pendientes 
         WHERE DATE(fecha_solicitud) = fecha) AS pagos_del_dia,
        (SELECT COUNT(*) FROM pagos_pendientes 
         WHERE DATE(fecha_aprobacion) = fecha) AS pagos_aprobados_dia
    FROM usuarios u
    LEFT JOIN historial_uso h ON u.telegram_id = h.telegram_id 
        AND DATE(h.fecha) = fecha;
END//

-- Procedimiento: Reporte de fraudes
CREATE PROCEDURE `sp_reporte_fraudes`()
BEGIN
    SELECT 
        u.telegram_id,
        u.username,
        u.first_name,
        u.bloqueado,
        COUNT(cd.id) AS intentos_fraude,
        MAX(cd.fecha) AS ultimo_intento,
        GROUP_CONCAT(DISTINCT cd.pago_id SEPARATOR ', ') AS pagos_involucrados
    FROM usuarios u
    INNER JOIN capturas_duplicadas cd ON u.telegram_id = cd.telegram_id
    GROUP BY u.telegram_id, u.username, u.first_name, u.bloqueado
    ORDER BY intentos_fraude DESC;
END//

-- Procedimiento: Backup de usuario
CREATE PROCEDURE `sp_backup_usuario`(IN user_telegram_id BIGINT)
BEGIN
    SELECT 'USUARIO' AS tipo, u.* FROM usuarios u WHERE telegram_id = user_telegram_id;
    SELECT 'TRANSACCIONES' AS tipo, t.* FROM transacciones t WHERE telegram_id = user_telegram_id;
    SELECT 'HISTORIAL' AS tipo, h.* FROM historial_uso h WHERE telegram_id = user_telegram_id;
    SELECT 'PAGOS' AS tipo, p.* FROM pagos_pendientes p WHERE telegram_id = user_telegram_id;
    SELECT 'FRAUDES' AS tipo, cd.* FROM capturas_duplicadas cd WHERE telegram_id = user_telegram_id;
END//

DELIMITER ;

-- ═══════════════════════════════════════════════════════════════
-- DATOS INICIALES
-- ═══════════════════════════════════════════════════════════════

INSERT IGNORE INTO `tac_modelos` (`tac`, `modelo`, `marca`, `fuente`) VALUES
('35203310', 'iPhone 13 Pro', 'Apple', 'manual'),
('35289311', 'Galaxy S21', 'Samsung', 'manual'),
('35665810', 'Redmi Note 10', 'Xiaomi', 'manual'),
('35363711', 'P40 Pro', 'Huawei', 'manual'),
('35917810', 'Pixel 6', 'Google', 'manual'),
('35326811', 'OnePlus 9', 'OnePlus', 'manual'),
('35837710', 'Moto G100', 'Motorola', 'manual'),
('35944510', 'Xperia 5 III', 'Sony', 'manual'),
('35685311', 'Find X3', 'Oppo', 'manual'),
('35741210', 'V21', 'Vivo', 'manual');

-- ═══════════════════════════════════════════════════════════════
-- VERIFICACIÓN
-- ═══════════════════════════════════════════════════════════════

SELECT '✅ INSTALACIÓN COMPLETADA' AS estado;
SELECT 'Tablas creadas correctamente' AS mensaje;
