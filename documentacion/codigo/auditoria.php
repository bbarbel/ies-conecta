<?php
/**
 * Bloques del plugin relacionados con la auditoría.
 */

function iesc_log_reserva_event($mysqli, $accion, $reserva_id, $recurso_id = null, $fecha = null) {
    if (!$mysqli || $mysqli->connect_errno) {
        return;
    }

    $reserva_id = (int)$reserva_id;

    $current_user   = wp_get_current_user();
    $login_usuario  = $current_user ? $current_user->user_login   : 'sistema';
    $nombre_usuario = $current_user ? $current_user->display_name : 'Sistema / Cron';

    $grupos_usuario = '';

    $id_recurso     = null;
    $nombre_recurso = null;
    $edificio       = null;

    if ($reserva_id > 0) {
        if ($stmt = $mysqli->prepare("
            SELECT 
                r.recurso_id,
                rec.nombre       AS nombre_recurso,
                e.nombre         AS nombre_edificio
            FROM reservas r
            LEFT JOIN recursos  rec ON rec.id       = r.recurso_id
            LEFT JOIN edificios e   ON e.id         = rec.edificio_id
            WHERE r.id = ?
            LIMIT 1
        ")) {
            $stmt->bind_param('i', $reserva_id);
            $stmt->execute();
            $stmt->bind_result($rid, $rnombre, $enombre);
            if ($stmt->fetch()) {
                $id_recurso     = $rid;
                $nombre_recurso = $rnombre;
                $edificio       = $enombre;
            }
            $stmt->close();
        }
    }

    if ($id_recurso === null && $recurso_id !== null) {
        $id_recurso = (int)$recurso_id;
    }

    if ($fecha === null) {
        $fecha = iesc_now_mysql();
    }
    $info_adicional = 'Fecha ' . $fecha . ' - recurso ' . (int)$id_recurso;

    if ($stmt = $mysqli->prepare("
        INSERT INTO auditoria_reservas
        (accion, id_reserva, id_recurso, nombre_recurso, edificio,
         login_usuario, nombre_usuario, grupos_usuario, info_adicional)
        VALUES (?,?,?,?,?,?,?,?,?)
    ")) {
        $stmt->bind_param(
            'siissssss',
            $accion,
            $reserva_id,
            $id_recurso,
            $nombre_recurso,
            $edificio,
            $login_usuario,
            $nombre_usuario,
            $grupos_usuario,
            $info_adicional
        );
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Ejemplo de uso de la función de auditoría al crear una reserva:
 *
 * iesc_log_reserva_event(
 *     $mysqli,
 *     'crear_reserva',
 *     $reserva_id,
 *     $recurso_id,
 *     $fecha_hora_inicio_mysql
 * );
 */

function iesc_auditoria_reservas_shortcode($atts) {

    if (!is_user_logged_in()) {
        return '<h3>Debes iniciar sesión con tu cuenta corporativa.</h3>';
    }

    $upn = iesc_get_current_upn();
    if ($upn === '') {
        return '<h3>No se ha podido identificar tu usuario.</h3>';
    }

    if (!iesc_usuario_tiene_grupo($upn, 'GRP_GESTION_RESERVAS')) {
        return '<h3>Esta página de auditoría solo está disponible para Jefatura.</h3>';
    }

    $hoy = iesc_today_ymd();
    $fecha_desde = isset($_GET['aud_desde']) ? sanitize_text_field($_GET['aud_desde']) : $hoy;
    $fecha_hasta = isset($_GET['aud_hasta']) ? sanitize_text_field($_GET['aud_hasta']) : $hoy;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) {
        $fecha_desde = $hoy;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) {
        $fecha_hasta = $hoy;
    }

    $desde_sql = $fecha_desde . ' 00:00:00';
    $hasta_sql = $fecha_hasta . ' 23:59:59';

    $mysqli = iesc_get_mysqli();
    if (!$mysqli) {
        return 'Error de conexión con la base de datos de reservas.';
    }

    $sql = "
        SELECT
            ar.fecha_evento,
            ar.accion,
            ar.login_usuario,
            ar.nombre_usuario,
            ar.id_reserva,
            ar.id_recurso,
            ar.nombre_recurso,
            ar.edificio,
            ar.info_adicional
        FROM auditoria_reservas ar
        WHERE ar.fecha_evento BETWEEN ? AND ?
        ORDER BY ar.fecha_evento DESC
    ";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        $mysqli->close();
        return 'Error al preparar la consulta de auditoría.';
    }

    $stmt->bind_param('ss', $desde_sql, $hasta_sql);
    $stmt->execute();
    $res = $stmt->get_result();

    $logs = array();
    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();
    $mysqli->close();

    ob_start();
    ?>
    <div style="max-width:1100px;">

        <h3>Auditoría de reservas (Jefatura)</h3>

        <form method="get" style="margin-bottom:15px;">
            <label>Desde:
                <input type="date" name="aud_desde" value="<?php echo esc_attr($fecha_desde); ?>">
            </label>
            &nbsp;
            <label>Hasta:
                <input type="date" name="aud_hasta" value="<?php echo esc_attr($fecha_hasta); ?>">
            </label>
            &nbsp;
            <button type="submit">Filtrar</button>
        </form>

        <?php if (empty($logs)): ?>
            <p>No hay registros de auditoría en el rango seleccionado.</p>
        <?php else: ?>
            <table border="1" cellpadding="5" style="border-collapse:collapse;width:100%;font-size:0.9em;">
                <tr style="background:#f0f0f0;">
                    <th>Fecha evento</th>
                    <th>Acción</th>
                    <th>Usuario</th>
                    <th>Recurso</th>
                    <th>Edificio</th>
                    <th>ID reserva</th>
                    <th>Detalle</th>
                </tr>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html($log['fecha_evento']); ?></td>
                        <td><?php echo esc_html($log['accion']); ?></td>
                        <td><?php echo esc_html($log['nombre_usuario'] . ' (' . $log['login_usuario'] . ')'); ?></td>
                        <td>
                            <?php
                            if (!empty($log['nombre_recurso'])) {
                                echo esc_html($log['nombre_recurso']) . ' [ID ' . (int)$log['id_recurso'] . ']';
                            } else {
                                echo 'ID ' . (int)$log['id_recurso'];
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html($log['edificio']); ?></td>
                        <td><?php echo (int)$log['id_reserva']; ?></td>
                        <td><?php echo esc_html($log['info_adicional']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('auditoria_reservas', 'iesc_auditoria_reservas_shortcode');
