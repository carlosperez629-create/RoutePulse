-- =====================================================================
-- RoutePulse - Esquema de base de datos v1.1 (FUENTE DE VERDAD) - 23 tablas
-- Motor: MySQL 8 / MariaDB 10.5+  |  InnoDB  |  utf8mb4
-- Reglas:
--   * Todas las fechas se guardan en UTC (la conexion hace SET time_zone='+00:00').
--   * Cada tabla tiene UN modulo dueño (ver comentario "DUEÑO"). Solo el dueño
--     cambia su estructura; el resto la usa a traves de los servicios internos.
--   * Cambios posteriores: archivo nuevo en database/migraciones/NNN_descripcion.sql
--     (nunca editar este archivo despues de la Fase 0 sin acuerdo de los 4).
-- Uso: crear la base de datos vacia, seleccionarla e importar este archivo.
-- =====================================================================
-- CREATE DATABASE IF NOT EXISTS routepulse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE routepulse;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- M8 SEGURIDAD Y USUARIOS (los 4 roles con login son un ENUM en usuarios)
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rol ENUM('admin','despachador','gerente','conductor') NOT NULL,   -- el destinatario no tiene login (entra por token)
  nombre VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  telefono VARCHAR(30) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME NULL,
  ultimo_login DATETIME NULL,
  debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email),
  KEY idx_usuarios_rol (rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE log_auditoria (            -- DUEÑO: M8
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NULL,
  accion VARCHAR(60) NOT NULL,          -- ej: login_ok, login_fallido, paquete_cambiar_estado
  entidad VARCHAR(40) NOT NULL,         -- ej: usuario, paquete, ruta
  entidad_id VARCHAR(40) NULL,
  detalle LONGTEXT NULL,                -- JSON con antes/despues u otros datos
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aud_usuario (usuario_id, created_at),
  KEY idx_aud_entidad (entidad, entidad_id),
  KEY idx_aud_fecha (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- COMPARTIDAS (dueño: M8, las lee todo el mundo)
-- ---------------------------------------------------------------------
CREATE TABLE configuracion (
  clave VARCHAR(80) NOT NULL,
  valor VARCHAR(500) NOT NULL,
  tipo ENUM('int','float','bool','string','json') NOT NULL DEFAULT 'string',
  descripcion VARCHAR(255) NULL,
  modulo_duenio VARCHAR(30) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE archivos (                 -- DUEÑO: M4 (firmas y fotos)
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo ENUM('firma','foto_entrega','foto_incidencia','otro') NOT NULL,
  ruta_relativa VARCHAR(255) NOT NULL,  -- relativa a storage/uploads, NUNCA dentro de public/
  mime VARCHAR(60) NOT NULL,
  tamano_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  subido_por INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_archivos_sha (sha256),
  CONSTRAINT fk_archivos_usuario FOREIGN KEY (subido_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- M7 INTEGRACIONES
-- ---------------------------------------------------------------------
CREATE TABLE integraciones (            -- DUEÑO: M7
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo VARCHAR(40) NOT NULL,          -- shopify, woocommerce, magento, mercadolibre, erp_generico, twilio, meta_whatsapp, smtp, google_maps, osrm
  nombre VARCHAR(100) NOT NULL,
  tipo ENUM('ecommerce','erp_wms','mensajeria','mapas') NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 0,
  modo_simulado TINYINT(1) NOT NULL DEFAULT 1,   -- 1 = no llama al proveedor real
  config_json LONGTEXT NULL,            -- ajustes NO secretos. Los secretos viven en config/config.php
  api_key_hash CHAR(64) NULL,           -- sha256 de la API key entrante (clientes ERP/WMS)
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_integraciones_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- CATALOGOS: HUBS (M3) Y FLOTA / ZONAS (M1)
-- ---------------------------------------------------------------------
CREATE TABLE hubs (                     -- DUEÑO: M3
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(100) NOT NULL,
  direccion VARCHAR(255) NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE zonas (                    -- DUEÑO: M1
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(80) NOT NULL,
  descripcion VARCHAR(255) NULL,
  color CHAR(7) NOT NULL DEFAULT '#0d6efd',
  poligono_geojson LONGTEXT NULL,       -- GeoJSON Polygon: {"type":"Polygon","coordinates":[[[lng,lat],...]]}
  activa TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_zonas_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE zona_codigos_postales (    -- DUEÑO: M1
  zona_id INT UNSIGNED NOT NULL,
  codigo_postal VARCHAR(12) NOT NULL,
  PRIMARY KEY (zona_id, codigo_postal),
  KEY idx_zcp_cp (codigo_postal),
  CONSTRAINT fk_zcp_zona FOREIGN KEY (zona_id) REFERENCES zonas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehiculos (                -- DUEÑO: M1
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  placa VARCHAR(20) NOT NULL,
  tipo ENUM('furgon','motocicleta','vehiculo_electrico','bicicleta') NOT NULL,
  marca_modelo VARCHAR(80) NULL,
  capacidad_kg DECIMAL(8,2) NOT NULL,
  capacidad_m3 DECIMAL(6,2) NOT NULL,
  costo_por_km DECIMAL(8,4) NOT NULL DEFAULT 0,   -- combustible + mantenimiento, usado por M6
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vehiculos_placa (placa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conductores (              -- DUEÑO: M1 (el login vive en usuarios, rol 'conductor')
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NOT NULL,
  vehiculo_id INT UNSIGNED NULL,        -- vehiculo habitual
  licencia VARCHAR(40) NULL,
  estado ENUM('disponible','en_ruta','inactivo') NOT NULL DEFAULT 'disponible',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conductores_usuario (usuario_id),
  KEY idx_conductores_vehiculo (vehiculo_id),
  CONSTRAINT fk_conductores_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
  CONSTRAINT fk_conductores_vehiculo FOREIGN KEY (vehiculo_id) REFERENCES vehiculos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE zona_conductor (           -- DUEÑO: M1
  zona_id INT UNSIGNED NOT NULL,
  conductor_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (zona_id, conductor_id),
  CONSTRAINT fk_zc_zona FOREIGN KEY (zona_id) REFERENCES zonas (id) ON DELETE CASCADE,
  CONSTRAINT fk_zc_conductor FOREIGN KEY (conductor_id) REFERENCES conductores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- M3 TRAZABILIDAD DE ENVIOS
-- ---------------------------------------------------------------------
CREATE TABLE paquetes (                 -- DUEÑO: M3
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo_guia VARCHAR(20) NOT NULL,     -- formato RP-AAMMDD-XXXXXX (ver 00-base/04)
  integracion_id INT UNSIGNED NULL,     -- de donde vino (NULL = creado a mano)
  origen_externo_id VARCHAR(80) NULL,   -- id del pedido en la tienda/ERP (idempotencia)
  remitente_nombre VARCHAR(100) NOT NULL,
  remitente_telefono VARCHAR(30) NULL,
  destinatario_nombre VARCHAR(100) NOT NULL,
  destinatario_telefono VARCHAR(30) NOT NULL,
  destinatario_email VARCHAR(150) NULL,
  direccion_entrega VARCHAR(255) NOT NULL,
  referencia VARCHAR(255) NULL,
  ciudad VARCHAR(80) NULL,
  codigo_postal VARCHAR(12) NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  geocodificado TINYINT(1) NOT NULL DEFAULT 0,
  zona_id INT UNSIGNED NULL,
  hub_id INT UNSIGNED NULL,
  peso_kg DECIMAL(8,2) NOT NULL DEFAULT 0.50,
  volumen_m3 DECIMAL(8,4) NOT NULL DEFAULT 0.0100,
  cobro_contra_entrega DECIMAL(12,2) NOT NULL DEFAULT 0,
  moneda CHAR(3) NOT NULL DEFAULT 'USD',
  ventana_inicio DATETIME NULL,         -- horario solicitado por el cliente (UTC)
  ventana_fin DATETIME NULL,
  prioridad ENUM('normal','urgente') NOT NULL DEFAULT 'normal',
  estado ENUM('creado','ingresado_hub','en_clasificacion','asignado_ruta','en_transito','entregado','incidencia','cancelado','devuelto') NOT NULL DEFAULT 'creado',
  intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,        -- intentos de entrega ya realizados
  max_intentos TINYINT UNSIGNED NOT NULL DEFAULT 3,
  fecha_programada DATE NULL,           -- fecha elegida al reprogramar
  notas VARCHAR(500) NULL,
  -- Enlace de seguimiento (lo escribe SOLO M3 al crear y al cerrar el paquete; lo lee M5)
  token_seguimiento CHAR(32) NOT NULL,   -- bin2hex(random_bytes(16)), unico por paquete
  token_expira_at DATETIME NULL,         -- NULL mientras el paquete esta activo; M3 la fija al llegar a un estado terminal
  -- OTP de entrega (lo escribe SOLO M4, via otp_service.php). Sin OTP vigente = otp_hash NULL
  otp_hash CHAR(64) NULL,                -- hash_hmac('sha256', codigo, clave_app). El codigo en claro NUNCA se guarda
  otp_expira_at DATETIME NULL,
  otp_intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,       -- intentos fallidos del OTP vigente
  otp_solicitudes TINYINT UNSIGNED NOT NULL DEFAULT 0,    -- solicitudes dentro de la ventana de 1 hora
  otp_ventana_at DATETIME NULL,                           -- inicio de esa ventana
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_paquetes_guia (codigo_guia),
  UNIQUE KEY uq_paquetes_token (token_seguimiento),
  UNIQUE KEY uq_paquetes_externo (integracion_id, origen_externo_id),
  KEY idx_paquetes_estado (estado),
  KEY idx_paquetes_hub_estado (hub_id, estado),
  KEY idx_paquetes_zona (zona_id),
  KEY idx_paquetes_cp (codigo_postal),
  KEY idx_paquetes_programada (fecha_programada),
  KEY idx_paquetes_creado (created_at),
  CONSTRAINT fk_paquetes_integracion FOREIGN KEY (integracion_id) REFERENCES integraciones (id),
  CONSTRAINT fk_paquetes_zona FOREIGN KEY (zona_id) REFERENCES zonas (id),
  CONSTRAINT fk_paquetes_hub FOREIGN KEY (hub_id) REFERENCES hubs (id),
  CONSTRAINT fk_paquetes_creador FOREIGN KEY (created_by) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE historial_estados (        -- DUEÑO: M3 (solo INSERT, nunca UPDATE/DELETE)
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  paquete_id INT UNSIGNED NOT NULL,
  estado_anterior VARCHAR(20) NULL,
  estado_nuevo VARCHAR(20) NOT NULL,
  usuario_id INT UNSIGNED NULL,         -- NULL = accion automatica del sistema
  motivo VARCHAR(255) NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hist_paquete (paquete_id, created_at),
  CONSTRAINT fk_hist_paquete FOREIGN KEY (paquete_id) REFERENCES paquetes (id),
  CONSTRAINT fk_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webhook_eventos (          -- DUEÑO: M7
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  integracion_id INT UNSIGNED NOT NULL,
  evento_externo_id VARCHAR(120) NOT NULL,   -- id unico del evento/pedido en el origen
  tipo VARCHAR(60) NOT NULL,
  payload LONGTEXT NOT NULL,
  estado ENUM('recibido','procesado','error','duplicado') NOT NULL DEFAULT 'recibido',
  error VARCHAR(500) NULL,
  paquete_id INT UNSIGNED NULL,
  intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  recibido_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  procesado_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webhook_evento (integracion_id, evento_externo_id),
  KEY idx_webhook_estado (estado),
  CONSTRAINT fk_webhook_integracion FOREIGN KEY (integracion_id) REFERENCES integraciones (id),
  CONSTRAINT fk_webhook_paquete FOREIGN KEY (paquete_id) REFERENCES paquetes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- M1 RUTAS Y PARADAS
-- ---------------------------------------------------------------------
CREATE TABLE rutas (                    -- DUEÑO: M1
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo VARCHAR(30) NOT NULL,          -- R-AAMMDD-NN
  fecha DATE NOT NULL,
  hub_id INT UNSIGNED NOT NULL,
  conductor_id INT UNSIGNED NULL,
  vehiculo_id INT UNSIGNED NULL,
  zona_id INT UNSIGNED NULL,
  estado ENUM('borrador','planificada','en_curso','completada','cancelada') NOT NULL DEFAULT 'borrador',
  total_paquetes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  carga_kg DECIMAL(8,2) NOT NULL DEFAULT 0,
  carga_m3 DECIMAL(8,3) NOT NULL DEFAULT 0,
  distancia_km_estimada DECIMAL(8,2) NULL,
  duracion_min_estimada INT NULL,
  distancia_km_real DECIMAL(8,2) NULL,  -- la calcula M2 al completar la ruta
  polyline_sugerida LONGTEXT NULL,      -- JSON [[lat,lng],[lat,lng],...] de la ruta sugerida
  inicio_real DATETIME NULL,
  fin_real DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rutas_codigo (codigo),
  KEY idx_rutas_fecha_estado (fecha, estado),
  KEY idx_rutas_conductor (conductor_id, fecha),
  CONSTRAINT fk_rutas_hub FOREIGN KEY (hub_id) REFERENCES hubs (id),
  CONSTRAINT fk_rutas_conductor FOREIGN KEY (conductor_id) REFERENCES conductores (id),
  CONSTRAINT fk_rutas_vehiculo FOREIGN KEY (vehiculo_id) REFERENCES vehiculos (id),
  CONSTRAINT fk_rutas_zona FOREIGN KEY (zona_id) REFERENCES zonas (id),
  CONSTRAINT fk_rutas_creador FOREIGN KEY (created_by) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE paradas (                  -- DUEÑO: M1 (M2 escribe eta; M4 escribe llegada/salida/estado via servicios)
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ruta_id INT UNSIGNED NOT NULL,
  paquete_id INT UNSIGNED NOT NULL,
  orden SMALLINT UNSIGNED NOT NULL,     -- 1..N dentro de la ruta
  estado ENUM('pendiente','en_camino','llegado','entregada','fallida','omitida') NOT NULL DEFAULT 'pendiente',
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  eta DATETIME NULL,
  eta_actualizado_at DATETIME NULL,
  llegada_real DATETIME NULL,
  salida_real DATETIME NULL,
  intento_num TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_paradas_ruta_paquete (ruta_id, paquete_id),
  KEY idx_paradas_ruta_orden (ruta_id, orden),
  KEY idx_paradas_paquete (paquete_id),
  CONSTRAINT fk_paradas_ruta FOREIGN KEY (ruta_id) REFERENCES rutas (id) ON DELETE CASCADE,
  CONSTRAINT fk_paradas_paquete FOREIGN KEY (paquete_id) REFERENCES paquetes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE incidencias (              -- DUEÑO: M3
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  paquete_id INT UNSIGNED NOT NULL,
  parada_id INT UNSIGNED NULL,
  tipo ENUM('direccion_no_encontrada','cliente_ausente','cobro_no_realizado','rechazado_por_cliente','paquete_danado','zona_insegura','otro') NOT NULL,
  descripcion VARCHAR(500) NULL,
  foto_archivo_id INT UNSIGNED NULL,
  estado ENUM('abierta','reprogramada','devuelta','cancelada') NOT NULL DEFAULT 'abierta',
  reprogramada_para DATE NULL,
  creada_por INT UNSIGNED NULL,
  resuelta_por INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resuelta_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_inc_paquete (paquete_id),
  KEY idx_inc_estado (estado),
  KEY idx_inc_fecha (created_at),
  CONSTRAINT fk_inc_paquete FOREIGN KEY (paquete_id) REFERENCES paquetes (id),
  CONSTRAINT fk_inc_parada FOREIGN KEY (parada_id) REFERENCES paradas (id) ON DELETE SET NULL,
  CONSTRAINT fk_inc_foto FOREIGN KEY (foto_archivo_id) REFERENCES archivos (id),
  CONSTRAINT fk_inc_creada FOREIGN KEY (creada_por) REFERENCES usuarios (id),
  CONSTRAINT fk_inc_resuelta FOREIGN KEY (resuelta_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- M2 RASTREO GPS
-- ---------------------------------------------------------------------
CREATE TABLE puntos_gps (               -- DUEÑO: M2 (solo INSERT; en produccion el usuario de BD no debe tener UPDATE/DELETE aqui)
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ruta_id INT UNSIGNED NOT NULL,
  conductor_id INT UNSIGNED NOT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  velocidad_kmh DECIMAL(5,1) NULL,
  precision_m DECIMAL(6,1) NULL,
  rumbo SMALLINT NULL,
  bateria_pct TINYINT UNSIGNED NULL,
  capturado_at DATETIME(3) NOT NULL,    -- hora del telefono (UTC)
  recibido_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  hash_registro CHAR(64) NULL,          -- sha256(hash_anterior + datos); cadena de integridad por ruta
  PRIMARY KEY (id),
  KEY idx_gps_ruta_tiempo (ruta_id, capturado_at),
  KEY idx_gps_conductor_tiempo (conductor_id, capturado_at),
  CONSTRAINT fk_gps_ruta FOREIGN KEY (ruta_id) REFERENCES rutas (id),
  CONSTRAINT fk_gps_conductor FOREIGN KEY (conductor_id) REFERENCES conductores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ultima_posicion (          -- DUEÑO: M2 (cache para el mapa en vivo; se reescribe en cada envio)
  conductor_id INT UNSIGNED NOT NULL,
  ruta_id INT UNSIGNED NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  velocidad_kmh DECIMAL(5,1) NULL,
  rumbo SMALLINT NULL,
  bateria_pct TINYINT UNSIGNED NULL,
  capturado_at DATETIME(3) NOT NULL,
  actualizado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (conductor_id),
  CONSTRAINT fk_up_conductor FOREIGN KEY (conductor_id) REFERENCES conductores (id),
  CONSTRAINT fk_up_ruta FOREIGN KEY (ruta_id) REFERENCES rutas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE eventos_geocerca (         -- DUEÑO: M2
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ruta_id INT UNSIGNED NOT NULL,
  parada_id INT UNSIGNED NOT NULL,
  paquete_id INT UNSIGNED NOT NULL,
  tipo ENUM('entrada','salida') NOT NULL,
  radio_m INT UNSIGNED NOT NULL,
  distancia_m DECIMAL(8,1) NOT NULL,
  notificado TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_geo_parada_tipo (parada_id, tipo),
  KEY idx_geo_ruta (ruta_id),
  CONSTRAINT fk_geo_ruta FOREIGN KEY (ruta_id) REFERENCES rutas (id),
  CONSTRAINT fk_geo_parada FOREIGN KEY (parada_id) REFERENCES paradas (id) ON DELETE CASCADE,
  CONSTRAINT fk_geo_paquete FOREIGN KEY (paquete_id) REFERENCES paquetes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- M4 APP DEL CONDUCTOR (pruebas de entrega)
-- ---------------------------------------------------------------------
CREATE TABLE entregas (                 -- DUEÑO: M4 (un registro por intento de entrega)
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid_cliente CHAR(36) NOT NULL,       -- UUID generado en el telefono; hace la sincronizacion idempotente
  parada_id INT UNSIGNED NOT NULL,
  paquete_id INT UNSIGNED NOT NULL,
  conductor_id INT UNSIGNED NOT NULL,
  intento_num TINYINT UNSIGNED NOT NULL DEFAULT 1,
  resultado ENUM('entregado','fallida') NOT NULL,
  motivo_fallo ENUM('direccion_no_encontrada','cliente_ausente','cobro_no_realizado','rechazado_por_cliente','paquete_danado','zona_insegura','otro') NULL,
  receptor_nombre VARCHAR(100) NULL,
  receptor_documento VARCHAR(30) NULL,
  completa TINYINT(1) NOT NULL DEFAULT 1,       -- 1 = entregado completo (insumo de OTIF)
  otp_validado TINYINT(1) NOT NULL DEFAULT 0,
  firma_archivo_id INT UNSIGNED NULL,
  foto_archivo_id INT UNSIGNED NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  precision_m DECIMAL(6,1) NULL,
  nota VARCHAR(500) NULL,
  fuera_de_linea TINYINT(1) NOT NULL DEFAULT 0,
  ocurrido_at DATETIME(3) NOT NULL,     -- hora real del evento en el telefono (UTC)
  sincronizado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_entregas_uuid (uuid_cliente),
  KEY idx_entregas_paquete (paquete_id),
  KEY idx_entregas_conductor_fecha (conductor_id, ocurrido_at),
  KEY idx_entregas_parada (parada_id),
  CONSTRAINT fk_entregas_parada FOREIGN KEY (parada_id) REFERENCES paradas (id),
  CONSTRAINT fk_entregas_paquete FOREIGN KEY (paquete_id) REFERENCES paquetes (id),
  CONSTRAINT fk_entregas_conductor FOREIGN KEY (conductor_id) REFERENCES conductores (id),
  CONSTRAINT fk_entregas_firma FOREIGN KEY (firma_archivo_id) REFERENCES archivos (id),
  CONSTRAINT fk_entregas_foto FOREIGN KEY (foto_archivo_id) REFERENCES archivos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- M7 NOTIFICACIONES (cola)
-- ---------------------------------------------------------------------
CREATE TABLE plantillas_notificacion (  -- DUEÑO: M7 (textos definidos en modulos/05 y 07)
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo ENUM('en_camino','proximidad','entregado','fallida','otp') NOT NULL,
  canal ENUM('whatsapp','sms','email') NOT NULL,
  asunto VARCHAR(150) NULL,
  cuerpo TEXT NOT NULL,                 -- variables: {nombre} {guia} {eta_min} {link} {otp} {motivo} {fecha}
  activa TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plantilla_tipo_canal (tipo, canal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notificaciones (           -- DUEÑO: M7
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  paquete_id INT UNSIGNED NOT NULL,
  tipo ENUM('en_camino','proximidad','entregado','fallida','otp') NOT NULL,
  canal ENUM('whatsapp','sms','email') NOT NULL,
  destino VARCHAR(150) NOT NULL,
  asunto VARCHAR(150) NULL,
  mensaje TEXT NOT NULL,
  clave_dedup VARCHAR(100) NULL,        -- evita duplicados: ej "52:en_camino" | "52:proximidad:parada17"
  estado ENUM('pendiente','enviado','fallido','cancelado') NOT NULL DEFAULT 'pendiente',
  intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  proximo_intento_at DATETIME NULL,
  proveedor_mensaje_id VARCHAR(100) NULL,
  error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviado_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notif_dedup (clave_dedup),
  KEY idx_notif_cola (estado, proximo_intento_at),
  KEY idx_notif_paquete (paquete_id),
  CONSTRAINT fk_notif_paquete FOREIGN KEY (paquete_id) REFERENCES paquetes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
