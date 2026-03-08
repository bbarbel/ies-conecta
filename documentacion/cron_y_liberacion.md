# Cron y liberación automática

El sistema incluye una tarea automática para evitar que las reservas bloqueen aulas si finalmente no se validan.

## Qué hace

Cuando una reserva se crea, queda en estado pendiente y con un tiempo límite de validación.

Si ese tiempo se supera y la reserva sigue sin validarse, el sistema la marca como liberada.

## Cómo se ejecuta

El plugin registra un evento programado de WordPress que se lanza cada cinco minutos.

Ese proceso revisa las reservas pendientes que:

- no tienen validación
- ya han superado la hora límite

y actualiza su estado a liberada.

## Para qué sirve

Esto evita que el cuadrante quede bloqueado por reservas que realmente no se han usado.
