# Simular GPS sin teléfono

Sirve para probar el monitor (M2), las geocercas, el ETA y el portal del cliente (M5) sin salir a la calle.

## Pasos

1. Iniciar sesión como `conductor1@routepulse.test` y obtener el token CSRF (`GET /api/auth/csrf.php`). Conservar la cookie `RPSESSID`.
2. La ruta 1 del seed está `en_curso`. Enviar puntos con `POST /api/gps/enviar.php` (JSON), uno cada 2 segundos, avanzando de a poco desde el HUB (10.5571993,-71.6490662) hacia la parada `en_camino` (paquete 7: 10.5824993,-71.6453662):

```
{ "ruta_id": 1,
  "puntos": [ { "lat": 4.6200, "lng": -74.0800, "velocidad_kmh": 30, "precision_m": 8, "rumbo": 10, "bateria_pct": 80, "capturado_at": "<ISO UTC ahora>" } ] }
```

3. Cuando la distancia a la parada baje de 500 m (por ejemplo, 10.5794993,-71.6458662) debe crearse **un solo** evento de geocerca y **una sola** notificación `proximidad`.
4. Abrir `/t/00000000000000000000000000000007` (portal) y `/monitor/flota.php` (despachador) y ver cómo se mueve el vehículo.
5. Para probar "sin señal": dejar de enviar puntos más de 60 s (demorado) y más de 300 s (sin señal).

Se puede automatizar con un script propio (curl, Postman o un pequeño PHP/JS) que recorra una lista de coordenadas; cada integrante puede guardar el suyo en `tests/`.
