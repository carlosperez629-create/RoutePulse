# Registro de migraciones de esquema

Cada cambio de la base de datos después de la Fase 0 se hace con un archivo numerado en esta carpeta (`001_descripcion.sql`, `002_...`) y se anota aquí. El mismo pull request actualiza `database/schema.sql` para que una instalación nueva quede igual que una actualizada.

El esquema inicial (`schema.sql` v1.1, 23 tablas) es la migración `000`.

| Número | Fecha | Quién | Módulo | Qué cambia |
|---|---|---|---|---|
| 000 | | | todos | Esquema inicial de 23 tablas (`schema.sql` + `seed.sql`) |
