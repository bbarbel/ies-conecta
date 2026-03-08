# Auditoría del sistema

El sistema registra acciones importantes relacionadas con las reservas.

La información se guarda en la tabla `auditoria_reservas`.

## Qué se registra

Entre otras acciones, el sistema puede registrar:

- creación de reservas
- validación de reservas
- liberación automática
- cancelaciones
- otras acciones de control sobre las reservas

## Qué datos guarda

Cada registro puede incluir:

- fecha y hora del evento
- usuario que realizó la acción
- reserva afectada
- recurso
- edificio
- información adicional

## Visualización

La auditoría se muestra mediante el shortcode `[auditoria_reservas]`.

Solo pueden acceder a esta pantalla los usuarios con permisos de gestión.
