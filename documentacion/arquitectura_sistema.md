# Arquitectura del sistema

IES-Conecta se apoya en varios componentes que trabajan juntos dentro de la red del centro.

## Portal web

La parte visible para el usuario está desarrollada sobre WordPress. Desde ahí el profesorado puede iniciar sesión, consultar el cuadrante, crear reservas, validarlas y revisar sus reservas activas.

## Directorio Activo

La autenticación se realiza con cuentas del dominio. Además, los grupos del Directorio Activo se utilizan para decidir qué puede hacer cada usuario dentro del sistema.

## Base de datos MariaDB

La información del sistema se guarda en una base de datos externa. Ahí se registran:

- usuarios y grupos
- permisos por edificio y recurso
- reservas
- franjas horarias
- auditoría

## Validación mediante QR

Cuando el profesor llega al aula puede validar su reserva mediante QR. El sistema comprueba que exista una reserva pendiente, que sea del día actual y que todavía esté dentro del tiempo permitido.

## Liberación automática

Si una reserva no se valida a tiempo, un proceso automático la marca como liberada para que el recurso vuelva a aparecer disponible.

## Organización del repositorio

En este repositorio se incluye el plugin principal y, además, varios documentos y fragmentos de código separados por bloques funcionales para que se entienda mejor la lógica del sistema.
