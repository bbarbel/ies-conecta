# Shortcodes del sistema

En el plugin se utilizan varios shortcodes de WordPress para mostrar las distintas partes del sistema dentro del portal.

## [cuadrante_reservas]

Muestra el cuadrante de reservas de un edificio.

Permite:
- ver las aulas o recursos disponibles
- consultar las franjas ya ocupadas
- iniciar una nueva reserva

También aplica los permisos por recurso y marca en rojo los que el usuario no puede reservar.

## [mis_reservas]

Muestra las reservas activas del usuario.

Desde esta pantalla el profesorado puede revisar sus reservas y cancelarlas.  
Si el usuario pertenece al grupo de gestión, también puede ver las reservas del resto.

## [auditoria_reservas]

Muestra la auditoría del sistema.

Solo está disponible para jefatura o usuarios del grupo de gestión.  
Permite filtrar por fechas y revisar qué acciones se han realizado.

## [iesc_checkin]

Se utiliza para validar una reserva mediante QR.

Comprueba que el usuario tenga una reserva pendiente para ese recurso, para el día actual y dentro del tiempo permitido.
