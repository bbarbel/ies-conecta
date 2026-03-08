<?php
/**
 * Bloques del plugin relacionados con los shortcodes principales:
 * - [cuadrante_reservas]
 * - [mis_reservas]
 * - [auditoria_reservas]
 * - [iesc_checkin]
 */

function iesc_cuadrante_shortcode($atts) {

    $atts = shortcode_atts(array(
        'edificio_id' => 1,
    ), $atts, 'cuadrante_reservas');

    $edificio_id = (int)$atts['edificio_id'];

    if (!is_user_logged_in()) {
        return '<h3>Debes iniciar sesión con tu cuenta corporativa.</h3>';
    }

    $upn = iesc_get_current_upn();

    if ($upn === '' || iesc_usuario_tiene_grupo($upn, 'GRP_ALUMNADO')) {
        return '<h3>Esta página de reservas solo está disponible para el profesorado.</h3>';
    }

    if ($edificio_id > 0 && !iesc_usuario_tiene_permiso_edificio($upn, $edificio_id)) {
        return '<h3>No tienes permiso para reservar en este edificio.</h3>';
    }

    $fecha = isset($_GET['fecha']) ? sanitize_text_field($_GET['fecha']) : iesc_today_ymd();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $fecha = iesc_today_ymd();
    }

    $mysqli = iesc_get_mysqli();
    if (!$mysqli) return 'Error conexión base de datos de reservas.';

    $stmt = $mysqli->prepare("
        SELECT id, nombre
        FROM recursos
        WHERE edificio_id = ?
        ORDER BY nombre
    ");
    if (!$stmt) {
        $mysqli->close();
        return 'Error al cargar recursos.';
    }

    $stmt->bind_param("i", $edificio_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $recursos = array();
    while ($row = $res->fetch_assoc()) {
        $recursos[(int)$row['id']] = $row['nombre'];
    }
    $stmt->close();

    if (empty($recursos)) {
        $mysqli->close();
        return 'No hay recursos para este edificio.';
    }

    $recursos_permitidos = iesc_get_recursos_permitidos_usuario($mysqli, $upn);

    $periodos = array();
    $res2 = $mysqli->query("SELECT id, nombre, hora_inicio, hora_fin FROM periodos ORDER BY id");
    if ($res2) {
        while ($row = $res2->fetch_assoc()) {
            $periodos[(int)$row['id']] = $row;
        }
    }

    if (empty($periodos)) {
        $mysqli->close();
        return 'No hay periodos configurados.';
    }

    $stmt2 = $mysqli->prepare("
        SELECT rf.recurso_id, rf.periodo_id, u.display_name, r.estado
        FROM reserva_franjas rf
        JOIN reservas r    ON r.id = rf.reserva_id
        JOIN recursos rec  ON rec.id = rf.recurso_id
        JOIN usuarios_ad u ON u.id = r.usuario_id
        WHERE rf.fecha = ?
          AND rec.edificio_id = ?
          AND r.estado IN ('pendiente','confirmada','validada')
    ");
    if (!$stmt2) {
        $mysqli->close();
        return 'Error al cargar reservas.';
    }

    $stmt2->bind_param("si", $fecha, $edificio_id);
    $stmt2->execute();
    $res3 = $stmt2->get_result();

    $ocupacion = array();
    while ($row = $res3->fetch_assoc()) {
        $p = (int)$row['periodo_id'];
        $r = (int)$row['recurso_id'];
        $ocupacion[$p][$r] = array(
            'profesor' => $row['display_name'],
            'estado'   => $row['estado'],
        );
    }
    $stmt2->close();
    $mysqli->close();

    $mostrar_form_reserva = false;
    $form_fecha      = $fecha;
    $form_periodo_id = 0;
    $form_recurso_id = 0;

    if (isset($_GET['accion']) && $_GET['accion'] === 'reservar') {
        $get_edif = isset($_GET['edificio_id']) ? (int)$_GET['edificio_id'] : 0;
        if ($get_edif === $edificio_id) {
            $mostrar_form_reserva = true;
            $form_fecha      = isset($_GET['fecha']) ? sanitize_text_field($_GET['fecha']) : $fecha;
            $form_periodo_id = isset($_GET['periodo']) ? (int)$_GET['periodo'] : 0;
            $form_recurso_id = isset($_GET['recurso']) ? (int)$_GET['recurso'] : 0;
        }
    }

    $ahora_dt  = iesc_now_dt();
    $hoy_ymd   = $ahora_dt->format('Y-m-d');
    $ahora_str = $ahora_dt->format('H:i:s');

    ob_start();
    ?>
    <div class="iesc-cuadrante-wrapper" style="margin-bottom:30px;">
        <style>
            .iesc-header-sin-permiso { background:#ffcdd2 !important; color:#900 !important; }
            .iesc-celda-sin-permiso  { background:#ffcdd2 !important; color:#900 !important; cursor:not-allowed; opacity:0.95; }
            .iesc-celda-sin-permiso a { pointer-events:none; color:#900 !important; text-decoration:none; }
            .iesc-leyenda-permisos { margin-top:8px; font-size:0.9em; }
            .iesc-debug-hora { font-size:0.8em; color:#666; margin-bottom:5px; }
        </style>

        <div class="iesc-debug-hora">
            Hora actual usada por el sistema (Europe/Madrid): <strong><?php echo esc_html($hoy_ymd . ' ' . $ahora_str); ?></strong>
        </div>

        <form method="get" style="margin-bottom:10px;">
            <input type="hidden" name="edificio_id" value="<?php echo esc_attr($edificio_id); ?>">
            Fecha:
            <input type="date" name="fecha" value="<?php echo esc_attr($fecha); ?>">
            <button type="submit">Ver</button>
        </form>

        <?php if ($mostrar_form_reserva && $form_periodo_id && $form_recurso_id && isset($periodos[$form_periodo_id]) && isset($recursos[$form_recurso_id])): ?>
            <div style="border:1px solid #ccc; padding:10px; margin-bottom:15px;">
                <h4>Confirmar reserva</h4>
                <p>
                    Fecha: <strong><?php echo esc_html($form_fecha); ?></strong><br>
                    Periodo: <strong><?php echo esc_html($periodos[$form_periodo_id]['nombre']); ?></strong><br>
                    Recurso: <strong><?php echo esc_html($recursos[$form_recurso_id]); ?></strong>
                </p>
                <form method="post">
                    <?php wp_nonce_field('iesc_hacer_reserva', 'iesc_reserva_nonce'); ?>
                    <input type="hidden" name="fecha" value="<?php echo esc_attr($form_fecha); ?>">
                    <input type="hidden" name="periodo_id" value="<?php echo esc_attr($form_periodo_id); ?>">
                    <input type="hidden" name="recurso_id" value="<?php echo esc_attr($form_recurso_id); ?>">
                    <input type="hidden" name="edificio_id" value="<?php echo esc_attr($edificio_id); ?>">
                    <button type="submit">Confirmar reserva</button>
                </form>
            </div>
        <?php endif; ?>

        <table border="1" cellpadding="5" style="border-collapse:collapse; width:100%;">
            <tr style="background:#f0f0f0;">
                <th>Periodo</th>
                <?php foreach ($recursos as $rid => $rec): ?>
                    <?php
                    $puede_recurso = !empty($recursos_permitidos[$rid]);
                    $th_class = $puede_recurso ? '' : ' class="iesc-header-sin-permiso"';
                    ?>
                    <th<?php echo $th_class; ?>><?php echo esc_html($rec); ?></th>
                <?php endforeach; ?>
            </tr>

            <?php foreach ($periodos as $pid => $per): ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($per['nombre']); ?></strong><br>
                        <small><?php echo esc_html(substr($per['hora_inicio'], 0, 5) . ' - ' . substr($per['hora_fin'], 0, 5)); ?></small>
                    </td>

                    <?php foreach ($recursos as $rid => $rec): ?>
                        <?php
                        $celda = isset($ocupacion[$pid][$rid]) ? $ocupacion[$pid][$rid] : null;
                        $puede_recurso = !empty($recursos_permitidos[$rid]);
                        $td_class = '';
                        $contenido = '';
                        $color = '#fff';

                        if ($celda) {
                            if ($celda['estado'] === 'confirmada') {
                                $color = '#ffcdd2';
                            } elseif ($celda['estado'] === 'validada') {
                                $color = '#c8e6c9';
                            } else {
                                $color = '#ffe082';
                            }
                            $contenido = esc_html($celda['profesor']);
                        } else {
                            if (!$puede_recurso) {
                                $color = '#ffcdd2';
                                $contenido = 'No permitido';
                                $td_class = ' class="iesc-celda-sin-permiso"';
                            } else {
                                $es_pasado = false;

                                if ($fecha < $hoy_ymd) {
                                    $es_pasado = true;
                                } elseif ($fecha === $hoy_ymd) {
                                    $dt_fin_celda = iesc_build_dt($fecha, $per['hora_fin']);
                                    if ($dt_fin_celda && $dt_fin_celda <= $ahora_dt) {
                                        $es_pasado = true;
                                    }
                                }

                                if ($es_pasado) {
                                    $color = '#eeeeee';
                                    $contenido = 'Pasado';
                                } else {
                                    $color = '#e8f5e9';
                                    $url_reserva = add_query_arg(array(
                                        'accion'      => 'reservar',
                                        'fecha'       => $fecha,
                                        'periodo'     => $pid,
                                        'recurso'     => $rid,
                                        'edificio_id' => $edificio_id,
                                    ));
                                    $contenido = '<a href="' . esc_url($url_reserva) . '">Reservar</a>';
                                }
                            }
                        }
                        ?>
                        <td<?php echo $td_class; ?> style="background:<?php echo esc_attr($color); ?>; text-align:center;">
                            <?php echo $contenido; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="iesc-leyenda-permisos">
            <span style="background:#ffcdd2;border:1px solid #900;padding:2px 6px;margin-right:4px;display:inline-block;"></span>
            Recursos que tu departamento no puede reservar.
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('cuadrante_reservas', 'iesc_cuadrante_shortcode');

function iesc_mis_reservas_shortcode($atts) {
    if (!is_user_logged_in()) return '<h3>Debes iniciar sesión con tu cuenta corporativa.</h3>';

    $upn = iesc_get_current_upn();
    if ($upn === '' || iesc_usuario_tiene_grupo($upn, 'GRP_ALUMNADO')) {
        return '<h3>Esta página de reservas solo está disponible para el profesorado.</h3>';
    }

    $mysqli = iesc_get_mysqli();
    if (!$mysqli) return 'Error de conexión con la base de datos de reservas.';

    $es_jefatura = iesc_usuario_tiene_grupo($upn, 'GRP_GESTION_RESERVAS') ? true : false;

    $sql = "
        SELECT
            r.id AS reserva_id,
            r.usuario_id,
            rf.fecha,
            p.nombre AS periodo,
            p.hora_inicio,
            p.hora_fin,
            rec.nombre AS recurso,
            u.display_name AS profesor
        FROM reservas r
        JOIN reserva_franjas rf ON rf.reserva_id = r.id
        JOIN recursos rec       ON rec.id       = rf.recurso_id
        JOIN periodos p         ON p.id         = rf.periodo_id
        JOIN usuarios_ad u      ON u.id         = r.usuario_id
        WHERE rf.fecha >= CURDATE()
          AND r.estado IN ('pendiente','confirmada','validada')
    ";

    if (!$es_jefatura) {
        $sql .= " AND LOWER(u.upn) = ? ";
    }

    $sql .= " ORDER BY rf.fecha, p.id, rec.nombre";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        $mysqli->close();
        return 'Error al preparar la consulta.';
    }

    if (!$es_jefatura) {
        $stmt->bind_param("s", $upn);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $reservas = array();
    while ($row = $res->fetch_assoc()) $reservas[] = $row;

    $stmt->close();
    $mysqli->close();

    ob_start();

    if (empty($reservas)) {
        echo '<p>No hay reservas activas.</p>';
    } else {
        echo '<h3>Reservas activas</h3>';
        echo '<table border="1" cellpadding="5" style="border-collapse:collapse;width:100%;">';
        echo '<tr style="background:#f0f0f0;">';
        echo '<th>Fecha</th><th>Periodo</th><th>Hora</th><th>Recurso</th>';
        if ($es_jefatura) echo '<th>Profesor/a</th>';
        echo '<th>Acciones</th>';
        echo '</tr>';

        foreach ($reservas as $r) {
            echo '<tr>';
            echo '<td>' . esc_html($r['fecha']) . '</td>';
            echo '<td>' . esc_html($r['periodo']) . '</td>';
            echo '<td>' . esc_html(substr($r['hora_inicio'],0,5) . ' - ' . substr($r['hora_fin'],0,5)) . '</td>';
            echo '<td>' . esc_html($r['recurso']) . '</td>';
            if ($es_jefatura) echo '<td>' . esc_html($r['profesor']) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'¿Seguro que deseas cancelar esta reserva?\');">';
            wp_nonce_field('iesc_borrar_reserva', 'iesc_borrar_reserva_nonce');
            echo '<input type="hidden" name="reserva_id" value="' . (int)$r['reserva_id'] . '">';
            echo '<button type="submit">Cancelar</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }

    return ob_get_clean();
}
add_shortcode('mis_reservas', 'iesc_mis_reservas_shortcode');

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

function iesc_checkin_shortcode($atts) {

    if (!is_user_logged_in()) {
        return '<h3>Debes iniciar sesión para validar tu reserva.</h3>';
    }

    $upn = iesc_get_current_upn();
    if ($upn === '' || iesc_usuario_tiene_grupo($upn, 'GRP_ALUMNADO')) {
        return '<h3>Acceso no permitido.</h3>';
    }

    $recurso_id = isset($_GET['recurso_id']) ? (int)$_GET['recurso_id'] : 0;
    if ($recurso_id <= 0) {
        return '<h3>Falta recurso_id en la URL.</h3><p>Ejemplo: ?recurso_id=13</p>';
    }

    $mensaje = '';

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['iesc_checkin_submit'])) {

        if (empty($_POST['iesc_checkin_nonce']) || !wp_verify_nonce($_POST['iesc_checkin_nonce'], 'iesc_checkin_accion')) {
            $mensaje = '<div style="border:1px solid #f00;padding:10px;">Nonce inválido.</div>';
        } else {

            $mysqli = iesc_get_mysqli();
            if (!$mysqli) {
                $mensaje = '<div style="border:1px solid #f00;padding:10px;">Error de conexión con la base de datos.</div>';
            } else {

                $usuario_ad_id = iesc_get_usuario_ad_id_by_upn($mysqli, $upn);
                if ($usuario_ad_id <= 0) {
                    $mysqli->close();
                    $mensaje = '<div style="border:1px solid #f00;padding:10px;">Tu usuario no está dado de alta en usuarios_ad.</div>';
                } else {

                    $fecha_hoy = iesc_today_ymd();
                    $ahora     = iesc_now_mysql();

                    $sql = "
                        SELECT id
                        FROM reservas
                        WHERE recurso_id            = ?
                          AND usuario_id            = ?
                          AND fecha                 = ?
                          AND estado                = 'pendiente'
                          AND validada_en IS NULL
                          AND expira_validacion_en IS NOT NULL
                          AND expira_validacion_en >= ?
                        ORDER BY expira_validacion_en ASC
                        LIMIT 1
                    ";

                    $stmt = $mysqli->prepare($sql);
                    if (!$stmt) {
                        $mysqli->close();
                        $mensaje = '<div style="border:1px solid #f00;padding:10px;">Error interno al preparar la consulta.</div>';
                    } else {
                        $stmt->bind_param('iiss', $recurso_id, $usuario_ad_id, $fecha_hoy, $ahora);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $reserva = $res ? $res->fetch_assoc() : null;
                        $stmt->close();

                        if (!$reserva) {
                            $mysqli->close();
                            $mensaje = '<div style="border:1px solid #f00;padding:10px;">No se ha encontrado ninguna reserva vigente para este aula, o ha expirado la ventana de validación.</div>';
                        } else {

                            $sql2 = "UPDATE reservas SET validada_en = ?, estado = 'validada' WHERE id = ?";
                            $stmt2 = $mysqli->prepare($sql2);
                            if (!$stmt2) {
                                $mysqli->close();
                                $mensaje = '<div style="border:1px solid #f00;padding:10px;">Error interno al preparar la actualización.</div>';
                            } else {
                                $stmt2->bind_param('si', $ahora, $reserva['id']);
                                $ok = $stmt2->execute();
                                $stmt2->close();
                                $mysqli->close();

                                if ($ok) {
                                    $mensaje = '<div style="border:1px solid #0a0;padding:10px;background:#e8f5e9;">Reserva validada correctamente.</div>';
                                } else {
                                    $mensaje = '<div style="border:1px solid #f00;padding:10px;">Error al validar la reserva.</div>';
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    ob_start();
    ?>
    <div style="max-width:720px;">
        <h3>Validación de reserva</h3>
        <p>Aula/Recurso ID: <strong><?php echo esc_html($recurso_id); ?></strong></p>

        <?php echo $mensaje; ?>

        <form method="post">
            <?php wp_nonce_field('iesc_checkin_accion', 'iesc_checkin_nonce'); ?>
            <button type="submit" name="iesc_checkin_submit">Validar ahora</button>
        </form>

        <p style="margin-top:12px;font-size:0.95em;opacity:0.85;">
            La validación solo funciona si tienes una reserva <strong>pendiente</strong> para hoy y dentro de la ventana permitida.
        </p>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('iesc_checkin', 'iesc_checkin_shortcode');
