-- =====================================================================
-- RoutePulse - Datos de prueba (seed) v1.0   *** SOLO DESARROLLO ***
-- Importar DESPUES de schema.sql. Todos los usuarios tienen la clave: Dev12345!
-- Las coordenadas son de EJEMPLO (centro ficticio de pruebas): si el equipo
-- quiere otra ciudad, cambie lat/lng de hubs, zonas, paquetes, paradas y puntos.
-- Todo el equipo debe probar contra ESTOS mismos datos.
-- Los archivos de firma/foto referenciados en 'archivos' NO existen en disco
-- (son marcadores); M4 puede crear imagenes de ejemplo en storage/uploads/seed/.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO usuarios (id, rol, nombre, email, password_hash, telefono, activo) VALUES
 (1,'admin','Admin Prueba','admin@routepulse.test','$2y$12$/iMBQ6SPQYeye.6e3NwpQOo9DOdvA.UajmunPNBNI6K0Ohc5BIqhS','+570000000001',1),
 (2,'despachador','Diana Despacho','despachador@routepulse.test','$2y$12$/iMBQ6SPQYeye.6e3NwpQOo9DOdvA.UajmunPNBNI6K0Ohc5BIqhS','+570000000002',1),
 (3,'gerente','Gabriel Gerente','gerente@routepulse.test','$2y$12$/iMBQ6SPQYeye.6e3NwpQOo9DOdvA.UajmunPNBNI6K0Ohc5BIqhS','+570000000003',1),
 (4,'conductor','Carlos Conductor','conductor1@routepulse.test','$2y$12$/iMBQ6SPQYeye.6e3NwpQOo9DOdvA.UajmunPNBNI6K0Ohc5BIqhS','+570000000004',1),
 (5,'conductor','Camila Conductora','conductor2@routepulse.test','$2y$12$/iMBQ6SPQYeye.6e3NwpQOo9DOdvA.UajmunPNBNI6K0Ohc5BIqhS','+570000000005',1);

INSERT INTO configuracion (clave, valor, tipo, descripcion, modulo_duenio) VALUES
 ('geocerca_radio_m','500','int','Radio de proximidad a la parada (metros)','M2'),
 ('gps_precision_max_m','200','int','Puntos con precision peor que esto se guardan pero no se usan para geocerca/ETA','M2'),
 ('gps_demorado_s','60','int','Segundos sin recibir punto para marcar vehiculo como demorado','M2'),
 ('gps_sin_senal_s','300','int','Segundos sin recibir punto para marcar vehiculo sin senal','M2'),
 ('monitor_refresh_s','5','int','Refresco del monitor de flota (segundos)','M2'),
 ('portal_refresh_s','10','int','Refresco del portal de seguimiento (segundos)','M5'),
 ('reprogramar_dias_max','7','int','Dias hacia adelante que el cliente puede reprogramar','M5'),
 ('otp_obligatorio','1','bool','Exigir OTP al entregar (solo si hay conexion)','M4'),
 ('otp_max_por_hora','3','int','Maximo de OTP que se pueden solicitar por paquete por hora','M4'),
 ('entrega_distancia_max_m','300','int','Distancia maxima entre el punto de entrega y la parada antes de advertir','M4'),
 ('dwell_max_s','7200','int','Paradas con mas segundos que esto se excluyen del promedio de tiempo de parada','M6'),
 ('gps_intervalo_s','10','int','Cada cuantos segundos la app envia ubicacion','M2'),
 ('gps_retencion_dias','90','int','Dias que se conservan los puntos GPS','M2'),
 ('eta_parada_default_min','5','int','Minutos por parada si aun no hay promedio real','M2'),
 ('eta_velocidad_media_kmh','25','float','Velocidad urbana de respaldo para estimar tiempos','M2'),
 ('eta_recalculo_min_s','30','int','Minimo de segundos entre recalculos de ETA de una ruta','M2'),
 ('proximidad_eta_min','10','int','Avisar proximidad si el ETA baja de estos minutos','M2'),
 ('portal_paradas_en_vivo','3','int','Mostrar vehiculo en vivo cuando faltan N paradas o menos','M5'),
 ('token_seguimiento_dias','30','int','Dias de vigencia del enlace de seguimiento tras cerrar el envio','M5'),
 ('max_intentos_entrega','3','int','Intentos maximos de entrega por paquete','M3'),
 ('otp_longitud','6','int','Digitos del codigo OTP','M4'),
 ('otp_expira_min','30','int','Minutos de vigencia del OTP','M4'),
 ('otp_max_intentos','3','int','Intentos fallidos permitidos por OTP','M4'),
 ('ruta_max_paradas','40','int','Maximo de paradas por ruta','M1'),
 ('canales_orden','["whatsapp","sms","email"]','json','Orden de preferencia de canales de notificacion','M7'),
 ('notif_max_reintentos','3','int','Reintentos de envio de una notificacion','M7'),
 ('notif_backoff_min','[1,5,15]','json','Minutos de espera entre reintentos','M7'),
 ('mapas_proveedor','osrm','string','osrm | google (proveedor de rutas y geocodificacion)','M7'),
 ('login_max_intentos','5','int','Intentos fallidos antes de bloquear la cuenta','M8'),
 ('login_bloqueo_min','15','int','Minutos de bloqueo tras exceder intentos','M8'),
 ('sesion_inactividad_min','60','int','Minutos de inactividad para cerrar sesion (no conductor)','M8'),
 ('sesion_conductor_horas','12','int','Horas maximas de sesion del conductor','M8'),
 ('password_min_largo','10','int','Largo minimo de contrasena','M8'),
 ('rate_login_por_min','10','int','Intentos de login por minuto por IP','M8'),
 ('rate_tracking_por_min','60','int','Consultas al portal de seguimiento por minuto por IP','M8'),
 ('zona_horaria_ui','America/Bogota','string','Zona horaria para MOSTRAR fechas (la BD siempre es UTC). Cambiar a la del equipo','M8'),
 ('moneda_defecto','USD','string','Moneda por defecto','M8');

INSERT INTO integraciones (id, codigo, nombre, tipo, activo, modo_simulado) VALUES
 (1,'shopify','Shopify','ecommerce',1,1),
 (2,'woocommerce','WooCommerce','ecommerce',1,1),
 (3,'magento','Magento','ecommerce',0,1),
 (4,'mercadolibre','Mercado Libre','ecommerce',0,1),
 (5,'erp_generico','ERP / WMS generico (API entrante)','erp_wms',1,1),
 (6,'twilio','Twilio (SMS y WhatsApp)','mensajeria',1,1),
 (7,'meta_whatsapp','Meta WhatsApp Business API','mensajeria',0,1),
 (8,'smtp','Correo SMTP','mensajeria',1,1),
 (9,'osrm','OSRM / OpenStreetMap','mapas',1,1),
 (10,'google_maps','Google Maps Platform','mapas',0,1);

INSERT INTO hubs (id, nombre, direccion, lat, lng, activo) VALUES
 (1,'HUB Central','Av. Principal 100 (ejemplo)',4.6097000,-74.0817000,1);

INSERT INTO zonas (id, nombre, descripcion, color, poligono_geojson, activa) VALUES
 (1,'Zona Norte','Sector norte de pruebas','#0d6efd','{"type":"Polygon","coordinates":[[[-74.1000,4.6200],[-74.0500,4.6200],[-74.0500,4.6700],[-74.1000,4.6700],[-74.1000,4.6200]]]}',1),
 (2,'Zona Sur','Sector sur de pruebas','#198754','{"type":"Polygon","coordinates":[[[-74.1000,4.5600],[-74.0500,4.5600],[-74.0500,4.6100],[-74.1000,4.6100],[-74.1000,4.5600]]]}',1);
INSERT INTO zona_codigos_postales (zona_id, codigo_postal) VALUES (1,'110111'),(1,'110121'),(2,'110611'),(2,'110621');

INSERT INTO vehiculos (id, placa, tipo, marca_modelo, capacidad_kg, capacidad_m3, costo_por_km, activo) VALUES
 (1,'FUR-001','furgon','Furgon 1.5t',1500.00,9.00,0.3500,1),
 (2,'MOT-001','motocicleta','Moto 150cc con caja',30.00,0.15,0.0800,1),
 (3,'ELE-001','vehiculo_electrico','Van electrica',800.00,5.00,0.0600,1);

INSERT INTO conductores (id, usuario_id, vehiculo_id, licencia, estado) VALUES
 (1,4,2,'LIC-0001','en_ruta'),
 (2,5,1,'LIC-0002','disponible');
INSERT INTO zona_conductor (zona_id, conductor_id) VALUES (1,1),(2,2);

INSERT INTO paquetes (id, codigo_guia, remitente_nombre, remitente_telefono, destinatario_nombre, destinatario_telefono, destinatario_email, direccion_entrega, ciudad, codigo_postal, lat, lng, geocodificado, zona_id, hub_id, peso_kg, volumen_m3, cobro_contra_entrega, moneda, ventana_inicio, ventana_fin, prioridad, estado, intentos, max_intentos, created_by, token_seguimiento) VALUES
(1,'RP-261113-A1B2C3','Tienda Demo','+570000009999','Ana Torres','+570000001001','cliente1@correo.test','Calle 1 #10-20, Zona Norte','Ciudad Ejemplo','110111',4.6400000,-74.0800000,1,1,NULL,1.20,0.0200,0.00,'USD',UTC_TIMESTAMP() + INTERVAL 1 HOUR,UTC_TIMESTAMP() + INTERVAL 6 HOUR,'normal','creado',0,3,2,'00000000000000000000000000000001'),
(2,'RP-261113-D4E5F6','Tienda Demo','+570000009999','Bruno Diaz','+570000001002','cliente2@correo.test','Calle 2 #11-21, Zona Norte','Ciudad Ejemplo','110111',4.6450000,-74.0750000,1,1,1,2.50,0.0400,25.00,'USD',UTC_TIMESTAMP() + INTERVAL 1 HOUR,UTC_TIMESTAMP() + INTERVAL 6 HOUR,'normal','ingresado_hub',0,3,2,'00000000000000000000000000000002'),
(3,'RP-261113-G7H8J9','Tienda Demo','+570000009999','Clara Ruiz','+570000001003','cliente3@correo.test','Calle 3 #12-22, Zona Norte','Ciudad Ejemplo','110121',4.6500000,-74.0700000,1,1,1,0.80,0.0100,0.00,'USD',UTC_TIMESTAMP() + INTERVAL 1 HOUR,UTC_TIMESTAMP() + INTERVAL 6 HOUR,'normal','en_clasificacion',0,3,2,'00000000000000000000000000000003'),
(4,'RP-261113-K2L3M4','Tienda Demo','+570000009999','Diego Paz','+570000001004','cliente4@correo.test','Calle 4 #13-23, Zona Sur','Ciudad Ejemplo','110611',4.5900000,-74.0900000,1,2,1,3.00,0.0500,0.00,'USD',UTC_TIMESTAMP() + INTERVAL 1 HOUR,UTC_TIMESTAMP() + INTERVAL 6 HOUR,'normal','en_clasificacion',0,3,2,'00000000000000000000000000000004'),
(5,'RP-261112-N5P6Q7','Tienda Demo','+570000009999','Elena Mora','+570000001005','cliente5@correo.test','Calle 5 #14-24, Zona Sur','Ciudad Ejemplo','110611',4.5800000,-74.0850000,1,2,1,1.00,0.0150,0.00,'USD',UTC_TIMESTAMP() + INTERVAL 1 HOUR,UTC_TIMESTAMP() + INTERVAL 6 HOUR,'normal','cancelado',0,3,2,'00000000000000000000000000000005'),
(6,'RP-261113-R8S9T2','Tienda Demo','+570000009999','Felipe Rey','+570000001006','cliente6@correo.test','Calle 6 #15-25, Zona Norte','Ciudad Ejemplo','110111',4.6300000,-74.0900000,1,1,1,1.50,0.0200,0.00,'USD',UTC_TIMESTAMP() + INTERVAL 1 HOUR,UTC_TIMESTAMP() + INTERVAL 6 HOUR,'normal','entregado',1,3,2,'00000000000000000000000000000006'),
(7,'RP-261113-U3V4W5','Tienda Demo','+570000009999','Gloria Sanz','+570000001007','cliente7@correo.test','Calle 7 #16-26, Zona Norte','Ciudad Ejemplo','110111',4.6350000,-74.0780000,1,1,1,2.00,0.0300,40.00,'USD',UTC_TIMESTAMP() + INTERVAL 1 HOUR,UTC_TIMESTAMP() + INTERVAL 6 HOUR,'normal','en_transito',0,3,2,'00000000000000000000000000000007'),
(8,'RP-261113-X6Y7Z2','Tienda Demo','+570000009999','Hugo Leal','+570000001008','cliente8@correo.test','Calle 8 #17-27, Zona Norte','Ciudad Ejemplo','110121',4.6550000,-74.0720000,1,1,1,0.90,0.0100,0.00,'USD',UTC_TIMESTAMP() + INTERVAL 1 HOUR,UTC_TIMESTAMP() + INTERVAL 6 HOUR,'normal','en_transito',0,3,2,'00000000000000000000000000000008'),
(9,'RP-261113-A3B4C5','Tienda Demo','+570000009999','Irene Vega','+570000001009','cliente9@correo.test','Calle 9 #18-28, Zona Norte','Ciudad Ejemplo','110121',4.6600000,-74.0650000,1,1,1,4.00,0.0600,0.00,'USD',UTC_TIMESTAMP() + INTERVAL 1 HOUR,UTC_TIMESTAMP() + INTERVAL 6 HOUR,'urgente','en_transito',0,3,2,'00000000000000000000000000000009'),
(10,'RP-261113-D6E7F8','Tienda Demo','+570000009999','Javier Nieto','+570000001010','cliente10@correo.test','Calle 10 #19-29, Zona Norte','Ciudad Ejemplo','110111',4.6250000,-74.0950000,1,1,1,1.10,0.0120,0.00,'USD',UTC_TIMESTAMP() + INTERVAL 1 HOUR,UTC_TIMESTAMP() + INTERVAL 6 HOUR,'normal','incidencia',1,3,2,'00000000000000000000000000000010'),
(11,'RP-261113-G9H2J3','Tienda Demo','+570000009999','Karla Pino','+570000001011','cliente11@correo.test','Calle 11 #20-30, Zona Sur','Ciudad Ejemplo','110611',4.5950000,-74.0800000,1,2,1,6.00,0.0800,0.00,'USD',UTC_TIMESTAMP() + INTERVAL 1 HOUR,UTC_TIMESTAMP() + INTERVAL 6 HOUR,'normal','asignado_ruta',0,3,2,'00000000000000000000000000000011'),
(12,'RP-261113-K4L5M6','Tienda Demo','+570000009999','Luis Soto','+570000001012','cliente12@correo.test','Calle 12 #21-31, Zona Sur','Ciudad Ejemplo','110621',4.5750000,-74.0700000,1,2,1,3.50,0.0450,15.00,'USD',UTC_TIMESTAMP() + INTERVAL 1 HOUR,UTC_TIMESTAMP() + INTERVAL 6 HOUR,'normal','asignado_ruta',0,3,2,'00000000000000000000000000000012');

INSERT INTO historial_estados (paquete_id, estado_anterior, estado_nuevo, usuario_id, motivo, created_at) VALUES
(1,NULL,'creado',2,NULL,UTC_TIMESTAMP() - INTERVAL 240 MINUTE),
(2,NULL,'creado',2,NULL,UTC_TIMESTAMP() - INTERVAL 240 MINUTE),
(2,'creado','ingresado_hub',2,NULL,UTC_TIMESTAMP() - INTERVAL 215 MINUTE),
(3,NULL,'creado',2,NULL,UTC_TIMESTAMP() - INTERVAL 240 MINUTE),
(3,'creado','ingresado_hub',2,NULL,UTC_TIMESTAMP() - INTERVAL 215 MINUTE),
(3,'ingresado_hub','en_clasificacion',2,NULL,UTC_TIMESTAMP() - INTERVAL 190 MINUTE),
(4,NULL,'creado',2,NULL,UTC_TIMESTAMP() - INTERVAL 240 MINUTE),
(4,'creado','ingresado_hub',2,NULL,UTC_TIMESTAMP() - INTERVAL 215 MINUTE),
(4,'ingresado_hub','en_clasificacion',2,NULL,UTC_TIMESTAMP() - INTERVAL 190 MINUTE),
(5,NULL,'creado',2,NULL,UTC_TIMESTAMP() - INTERVAL 240 MINUTE),
(5,'creado','cancelado',2,NULL,UTC_TIMESTAMP() - INTERVAL 215 MINUTE),
(6,NULL,'creado',2,NULL,UTC_TIMESTAMP() - INTERVAL 240 MINUTE),
(6,'creado','ingresado_hub',2,NULL,UTC_TIMESTAMP() - INTERVAL 215 MINUTE),
(6,'ingresado_hub','en_clasificacion',2,NULL,UTC_TIMESTAMP() - INTERVAL 190 MINUTE),
(6,'en_clasificacion','asignado_ruta',2,NULL,UTC_TIMESTAMP() - INTERVAL 165 MINUTE),
(6,'asignado_ruta','en_transito',2,NULL,UTC_TIMESTAMP() - INTERVAL 88 MINUTE),
(6,'en_transito','entregado',2,NULL,UTC_TIMESTAMP() - INTERVAL 76 MINUTE),
(7,NULL,'creado',2,NULL,UTC_TIMESTAMP() - INTERVAL 240 MINUTE),
(7,'creado','ingresado_hub',2,NULL,UTC_TIMESTAMP() - INTERVAL 215 MINUTE),
(7,'ingresado_hub','en_clasificacion',2,NULL,UTC_TIMESTAMP() - INTERVAL 190 MINUTE),
(7,'en_clasificacion','asignado_ruta',2,NULL,UTC_TIMESTAMP() - INTERVAL 165 MINUTE),
(7,'asignado_ruta','en_transito',2,NULL,UTC_TIMESTAMP() - INTERVAL 88 MINUTE),
(8,NULL,'creado',2,NULL,UTC_TIMESTAMP() - INTERVAL 240 MINUTE),
(8,'creado','ingresado_hub',2,NULL,UTC_TIMESTAMP() - INTERVAL 215 MINUTE),
(8,'ingresado_hub','en_clasificacion',2,NULL,UTC_TIMESTAMP() - INTERVAL 190 MINUTE),
(8,'en_clasificacion','asignado_ruta',2,NULL,UTC_TIMESTAMP() - INTERVAL 165 MINUTE),
(8,'asignado_ruta','en_transito',2,NULL,UTC_TIMESTAMP() - INTERVAL 88 MINUTE),
(9,NULL,'creado',2,NULL,UTC_TIMESTAMP() - INTERVAL 240 MINUTE),
(9,'creado','ingresado_hub',2,NULL,UTC_TIMESTAMP() - INTERVAL 215 MINUTE),
(9,'ingresado_hub','en_clasificacion',2,NULL,UTC_TIMESTAMP() - INTERVAL 190 MINUTE),
(9,'en_clasificacion','asignado_ruta',2,NULL,UTC_TIMESTAMP() - INTERVAL 165 MINUTE),
(9,'asignado_ruta','en_transito',2,NULL,UTC_TIMESTAMP() - INTERVAL 88 MINUTE),
(10,NULL,'creado',2,NULL,UTC_TIMESTAMP() - INTERVAL 240 MINUTE),
(10,'creado','ingresado_hub',2,NULL,UTC_TIMESTAMP() - INTERVAL 215 MINUTE),
(10,'ingresado_hub','en_clasificacion',2,NULL,UTC_TIMESTAMP() - INTERVAL 190 MINUTE),
(10,'en_clasificacion','asignado_ruta',2,NULL,UTC_TIMESTAMP() - INTERVAL 165 MINUTE),
(10,'asignado_ruta','en_transito',2,NULL,UTC_TIMESTAMP() - INTERVAL 88 MINUTE),
(10,'en_transito','incidencia',2,NULL,UTC_TIMESTAMP() - INTERVAL 56 MINUTE),
(11,NULL,'creado',2,NULL,UTC_TIMESTAMP() - INTERVAL 240 MINUTE),
(11,'creado','ingresado_hub',2,NULL,UTC_TIMESTAMP() - INTERVAL 215 MINUTE),
(11,'ingresado_hub','en_clasificacion',2,NULL,UTC_TIMESTAMP() - INTERVAL 190 MINUTE),
(11,'en_clasificacion','asignado_ruta',2,NULL,UTC_TIMESTAMP() - INTERVAL 165 MINUTE),
(12,NULL,'creado',2,NULL,UTC_TIMESTAMP() - INTERVAL 240 MINUTE),
(12,'creado','ingresado_hub',2,NULL,UTC_TIMESTAMP() - INTERVAL 215 MINUTE),
(12,'ingresado_hub','en_clasificacion',2,NULL,UTC_TIMESTAMP() - INTERVAL 190 MINUTE),
(12,'en_clasificacion','asignado_ruta',2,NULL,UTC_TIMESTAMP() - INTERVAL 165 MINUTE);

INSERT INTO archivos (id, tipo, ruta_relativa, mime, tamano_bytes, sha256, lat, lng, subido_por) VALUES
 (1,'firma','seed/firma_demo.png','image/png',2048,'0000000000000000000000000000000000000000000000000000000000000000',4.6300000,-74.0900000,4),
 (2,'foto_entrega','seed/foto_demo.jpg','image/jpeg',51200,'1111111111111111111111111111111111111111111111111111111111111111',4.6300000,-74.0900000,4);

INSERT INTO rutas (id, codigo, fecha, hub_id, conductor_id, vehiculo_id, zona_id, estado, total_paquetes, carga_kg, carga_m3, distancia_km_estimada, duracion_min_estimada, inicio_real, created_by) VALUES
 (1,'R-261113-01',UTC_DATE(),1,1,2,1,'en_curso',5,9.50,0.132,18.40,95,UTC_TIMESTAMP() - INTERVAL 90 MINUTE,2),
 (2,'R-261113-02',UTC_DATE(),1,2,1,2,'planificada',2,9.50,0.125,12.00,70,NULL,2);

INSERT INTO paradas (id, ruta_id, paquete_id, orden, estado, lat, lng, eta, llegada_real, salida_real, intento_num) VALUES
 (1,1,6,1,'entregada',4.6300000,-74.0900000,NULL,UTC_TIMESTAMP() - INTERVAL 80 MINUTE,UTC_TIMESTAMP() - INTERVAL 75 MINUTE,1),
 (2,1,10,2,'fallida',4.6250000,-74.0950000,NULL,UTC_TIMESTAMP() - INTERVAL 60 MINUTE,UTC_TIMESTAMP() - INTERVAL 55 MINUTE,1),
 (3,1,7,3,'en_camino',4.6350000,-74.0780000,UTC_TIMESTAMP() + INTERVAL 10 MINUTE,NULL,NULL,1),
 (4,1,8,4,'pendiente',4.6550000,-74.0720000,UTC_TIMESTAMP() + INTERVAL 25 MINUTE,NULL,NULL,1),
 (5,1,9,5,'pendiente',4.6600000,-74.0650000,UTC_TIMESTAMP() + INTERVAL 45 MINUTE,NULL,NULL,1),
 (6,2,11,1,'pendiente',4.5950000,-74.0800000,NULL,NULL,NULL,1),
 (7,2,12,2,'pendiente',4.5750000,-74.0700000,NULL,NULL,NULL,1);

INSERT INTO incidencias (id, paquete_id, parada_id, tipo, descripcion, estado, creada_por) VALUES
 (1,10,2,'cliente_ausente','Nadie atendio en la direccion','abierta',4);

INSERT INTO entregas (id, uuid_cliente, parada_id, paquete_id, conductor_id, intento_num, resultado, motivo_fallo, receptor_nombre, completa, otp_validado, firma_archivo_id, foto_archivo_id, lat, lng, precision_m, fuera_de_linea, ocurrido_at) VALUES
 (1,'e0000000-0000-4000-8000-000000000001',1,6,1,1,'entregado',NULL,'Felipe Rey',1,1,1,2,4.6300000,-74.0900000,8.0,0,UTC_TIMESTAMP() - INTERVAL 77 MINUTE),
 (2,'e0000000-0000-4000-8000-000000000002',2,10,1,1,'fallida','cliente_ausente',NULL,1,0,NULL,NULL,4.6250000,-74.0950000,10.0,0,UTC_TIMESTAMP() - INTERVAL 57 MINUTE);

INSERT INTO puntos_gps (ruta_id, conductor_id, lat, lng, velocidad_kmh, precision_m, rumbo, bateria_pct, capturado_at, recibido_at) VALUES
(1,1,4.6097000,-74.0817000,28.0,8.0,90,80,UTC_TIMESTAMP(3) - INTERVAL 30 MINUTE,UTC_TIMESTAMP(3) - INTERVAL 30 MINUTE),
(1,1,4.6200000,-74.0850000,28.0,8.0,90,80,UTC_TIMESTAMP(3) - INTERVAL 24 MINUTE,UTC_TIMESTAMP(3) - INTERVAL 24 MINUTE),
(1,1,4.6300000,-74.0900000,28.0,8.0,90,80,UTC_TIMESTAMP(3) - INTERVAL 18 MINUTE,UTC_TIMESTAMP(3) - INTERVAL 18 MINUTE),
(1,1,4.6250000,-74.0950000,28.0,8.0,90,80,UTC_TIMESTAMP(3) - INTERVAL 12 MINUTE,UTC_TIMESTAMP(3) - INTERVAL 12 MINUTE),
(1,1,4.6300000,-74.0850000,28.0,8.0,90,80,UTC_TIMESTAMP(3) - INTERVAL 6 MINUTE,UTC_TIMESTAMP(3) - INTERVAL 6 MINUTE),
(1,1,4.6330000,-74.0800000,28.0,8.0,90,80,UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE,UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE);

INSERT INTO ultima_posicion (conductor_id, ruta_id, lat, lng, velocidad_kmh, rumbo, bateria_pct, capturado_at) VALUES
 (1,1,4.6330000,-74.0800000,28.0,90,80,UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE);

INSERT INTO eventos_geocerca (ruta_id, parada_id, paquete_id, tipo, radio_m, distancia_m, notificado) VALUES
 (1,1,6,'entrada',500,420.0,1),
 (1,2,10,'entrada',500,380.0,1);

INSERT INTO plantillas_notificacion (tipo, canal, asunto, cuerpo, activa) VALUES
('en_camino','whatsapp',NULL,'Hola {nombre}, tu paquete {guia} ya va en camino. Siguelo en vivo aqui: {link}',1),
('en_camino','sms',NULL,'Hola {nombre}, tu paquete {guia} ya va en camino. Siguelo en vivo aqui: {link}',1),
('en_camino','email','Tu paquete {guia} va en camino','Hola {nombre}, tu paquete {guia} ya va en camino. Siguelo en vivo aqui: {link}',1),
('proximidad','whatsapp',NULL,'Hola {nombre}, tu repartidor llegara en aproximadamente {eta_min} minutos con tu paquete {guia}. Seguimiento: {link}',1),
('proximidad','sms',NULL,'Hola {nombre}, tu repartidor llegara en aproximadamente {eta_min} minutos con tu paquete {guia}. Seguimiento: {link}',1),
('proximidad','email','Tu repartidor esta cerca','Hola {nombre}, tu repartidor llegara en aproximadamente {eta_min} minutos con tu paquete {guia}. Seguimiento: {link}',1),
('entregado','whatsapp',NULL,'Hola {nombre}, tu paquete {guia} fue entregado. Gracias por confiar en nosotros. Detalle: {link}',1),
('entregado','sms',NULL,'Hola {nombre}, tu paquete {guia} fue entregado. Gracias por confiar en nosotros. Detalle: {link}',1),
('entregado','email','Paquete {guia} entregado','Hola {nombre}, tu paquete {guia} fue entregado. Gracias por confiar en nosotros. Detalle: {link}',1),
('fallida','whatsapp',NULL,'Hola {nombre}, no pudimos entregar tu paquete {guia} ({motivo}). Puedes reprogramar la entrega aqui: {link}',1),
('fallida','sms',NULL,'Hola {nombre}, no pudimos entregar tu paquete {guia} ({motivo}). Puedes reprogramar la entrega aqui: {link}',1),
('fallida','email','No pudimos entregar tu paquete {guia}','Hola {nombre}, no pudimos entregar tu paquete {guia} ({motivo}). Puedes reprogramar la entrega aqui: {link}',1),
('otp','whatsapp',NULL,'Tu codigo de entrega para el paquete {guia} es {otp}. Dictalo solo al repartidor al recibir y no lo compartas por otro medio.',1),
('otp','sms',NULL,'Tu codigo de entrega para el paquete {guia} es {otp}. Dictalo solo al repartidor al recibir y no lo compartas por otro medio.',1),
('otp','email','Codigo de entrega {guia}','Tu codigo de entrega para el paquete {guia} es {otp}. Dictalo solo al repartidor al recibir y no lo compartas por otro medio.',1);

SET FOREIGN_KEY_CHECKS = 1;
