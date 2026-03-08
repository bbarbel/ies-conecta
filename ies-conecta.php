<?php
/*
Plugin Name: IES Conecta Reservas
Description: Cuadrante simple de reservas conectado a la base de datos ies_conecta (permisos por edificio/recurso + check-in QR + cancelaciones + cron liberación + auditoría).
Version: 1.9.8
Author: 
*/

if (!defined('ABSPATH')) exit;

/* =========================================================
 * CONFIGURACIÓN BÁSICA Y FUNCIONES DE APOYO
 * ========================================================= */

/**
 * Conexión con la base de datos externa del proyecto.
  */
function iesc_get_mysqli() {
    $db_host = 'DB_HOST';
    $db_user = 'DB_USER';
    $db_pass = 'DB_PASSWORD';
    $db_name = 'DB_NAME';

    $mysqli = @new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($mysqli->connect_errno) {
        return false;
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

/**
 * Guarda un evento en la tabla de auditoría.
 * La idea es que quede registrado qué pasó, quién lo hizo
 * y sobre qué recurso/reserva fue.
 */
function iesc_log_reserva_event($mysqli, $accion, $reserva_id, $recurso_id = null, $fecha = null) {
    if (!$mysqli || $mysqli->connect_errno) {
        return;
    }

    $reserva_id = (int)$reserva_id;

    // Usuario actual de WordPress. Si no lo hay, lo marco como sistema/cron.
    $current_user   = wp_get_current_user();
    $login_usuario  = $current_user ? $current_user->user_login   : 'sistema';
    $nombre_usuario = $current_user ? $current_user->display_name : 'Sistema / Cron';

    $grupos_usuario = '';

    // Intento sacar el recurso y el edificio a partir de la reserva
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

    // Si no pude sacar el recurso por la reserva, uso el que llegue por parámetro
    if ($id_recurso === null && $recurso_id !== null) {
        $id_recurso = (int)$recurso_id;
    }

    // Texto de apoyo para dejar algo más de contexto en la auditoría
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
 * Devuelve el correo/UPN del usuario logueado en WordPress.
 */
function iesc_get_current_upn() {
    if (!is_user_logged_in()) return '';
    $wp_user = wp_get_current_user();
    return isset($wp_user->user_email) ? strtolower(trim($wp_user->user_email)) : '';
}

/**
 * Uso una zona horaria fija para que todo vaya con la misma hora.
 */
function iesc_get_timezone() {
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone('Europe/Madrid');
    }
    return $tz;
}

function iesc_now_dt() {
    return new DateTime('now', iesc_get_timezone());
}

function iesc_now_mysql() {
    return iesc_now_dt()->format('Y-m-d H:i:s');
}

function iesc_today_ymd() {
    return iesc_now_dt()->format('Y-m-d');
}

function iesc_build_dt($fecha, $hora) {
    $tz = iesc_get_timezone();
    $fecha = trim($fecha);
    $hora  = trim($hora);

    $dt = DateTime::createFromFormat('Y-m-d H:i:s', "$fecha $hora", $tz);
    if (!$dt) {
        $dt = DateTime::createFromFormat('Y-m-d H:i', "$fecha $hora", $tz);
    }
    return $dt;
}

/**
 * Devuelve el id de usuarios_ad para un UPN.
 * Lo guardo en caché durante la request para no repetir consultas.
 */
function iesc_get_usuario_ad_id_by_upn($mysqli, $upn) {
    static $cache = array();

    $upn = strtolower(trim((string)$upn));
    if ($upn === '') return 0;

    if (isset($cache[$upn])) {
        return (int)$cache[$upn];
    }

    if (!$mysqli) return 0;

    $stmt = $mysqli->prepare("SELECT id FROM usuarios_ad WHERE LOWER(upn) = ? LIMIT 1");
    if (!$stmt) {
        $cache[$upn] = 0;
        return 0;
    }

    $stmt->bind_param("s", $upn);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $cache[$upn] = $row ? (int)$row['id'] : 0;
    return (int)$cache[$upn];
}

/**
 * Comprueba si un usuario pertenece a un grupo AD concreto.
 */
function iesc_usuario_tiene_grupo($upn, $nombre_grupo) {
    $upn = strtolower(trim((string)$upn));
    $nombre_grupo = trim((string)$nombre_grupo);

    if ($upn === '' || $nombre_grupo === '') return 0;

    $mysqli = iesc_get_mysqli();
    if (!$mysqli) return 0;

    $sql = "
        SELECT COUNT(*) AS total
        FROM usuarios_ad u
        JOIN usuarios_ad_grupos ug ON ug.usuario_id = u.id
        JOIN grupos_ad g           ON g.id         = ug.grupo_id
        WHERE LOWER(u.upn) = ?
          AND g.nombre = ?
    ";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        $mysqli->close();
        return 0;
    }

    $stmt->bind_param("ss", $upn, $nombre_grupo);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;

    $stmt->close();
    $mysqli->close();

    return $row ? (int)$row['total'] : 0;
}

/**
 * Comprueba si el usuario tiene permiso para reservar en un edificio.
 */
function iesc_usuario_tiene_permiso_edificio($upn, $edificio_id) {
    $upn = strtolower(trim((string)$upn));
    $edificio_id = (int)$edificio_id;

    if ($upn === '' || $edificio_id <= 0) return 0;

    if (iesc_usuario_tiene_grupo($upn, 'GRP_ALUMNADO')) {
        return 0;
    }

    if (
        iesc_usuario_tiene_grupo($upn, 'GRP_PROFESORES_ITINERANTES') ||
        iesc_usuario_tiene_grupo($upn, 'GRP_GESTION_RESERVAS')
    ) {
        return 1;
    }

    $mysqli = iesc_get_mysqli();
    if (!$mysqli) return 0;

    $sql = "
        SELECT COUNT(*) AS total
        FROM usuarios_ad u
        JOIN usuarios_ad_grupos ug ON ug.usuario_id = u.id
        JOIN grupos_ad g           ON g.id         = ug.grupo_id
        JOIN permisos_grupo_edificio pge ON pge.grupo_id = g.id
        WHERE LOWER(u.upn) = ?
          AND pge.edificio_id = ?
          AND g.tipo = 'edificio'
    ";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        $mysqli->close();
        return 0;
    }

    $stmt->bind_param("si", $upn, $edificio_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;

    $stmt->close();
    $mysqli->close();

    return $row ? (int)$row['total'] : 0;
}

/**
 * Comprueba si el usuario puede reservar un recurso concreto.
 */
function iesc_usuario_puede_reservar_recurso($mysqli, $upn, $recurso_id) {
    $upn = strtolower(trim((string)$upn));
    $recurso_id = (int)$recurso_id;

    if (!$mysqli || $upn === '' || $recurso_id <= 0) return false;

    $sql = "
        SELECT 1
        FROM usuarios_ad u
        JOIN usuarios_ad_grupos ug    ON u.id = ug.usuario_id
        JOIN permisos_grupo_recurso p ON p.grupo_id = ug.grupo_id
        WHERE LOWER(u.upn) = ?
          AND p.recurso_id = ?
          AND p.puede_reservar = 1
        LIMIT 1
    ";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param("si", $upn, $recurso_id);
    $stmt->execute();
    $stmt->store_result();
    $ok = ($stmt->num_rows > 0);
    $stmt->close();

    return $ok;
}

/**
 * Devuelve los recursos que sí puede reservar el usuario.
 * Lo uso para pintar en rojo los que no le tocan.
 */
function iesc_get_recursos_permitidos_usuario($mysqli, $upn) {
    $permitidos = array();
    $upn = strtolower(trim((string)$upn));
    if (!$mysqli || $upn === '') return $permitidos;

    $sql = "
        SELECT DISTINCT p.recurso_id
        FROM usuarios_ad u
        JOIN usuarios_ad_grupos ug    ON u.id = ug.usuario_id
        JOIN permisos_grupo_recurso p ON p.grupo_id = ug.grupo_id
        WHERE LOWER(u.upn) = ?
          AND p.puede_reservar = 1
    ";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return $permitidos;

    $stmt->bind_param("s", $upn);
    $stmt->execute();
    $stmt->bind_result($recurso_id);

    while ($stmt->fetch()) {
        $permitidos[(int)$recurso_id] = true;
    }

    $stmt->close();
    return $permitidos;
}

/* =========================================================
 * BARRA FIJA DE INICIAR/CERRAR SESIÓN EN EL FRONTEND
 * ========================================================= */

function iesc_login_logout_bar() {
    // No la enseño en admin ni en wp-login.php
    if (is_admin()) return;
    if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false) {
        return;
    }

    global $wp;
    $current_path = isset($wp->request) ? $wp->request : '';
    $current_url  = home_url(add_query_arg(array(), $current_path));

    echo '<div style="
            position:fixed;
            bottom:10px;
            right:10px;
            z-index:9999;
            font-size:14px;
            font-family:inherit;
        ">';

    if (is_user_logged_in()) {
        $logout_url = wp_logout_url($current_url);
        echo '<a href="' . esc_url($logout_url) . '" 
                 style="background:#333;
                        color:#fff;
                        padding:6px 12px;
                        border-radius:4px;
                        text-decoration:none;">
                 Cerrar sesión
              </a>';
    } else {
        $login_url = wp_login_url($current_url);
        echo '<a href="' . esc_url($login_url) . '" 
                 style="background:#333;
                        color:#fff;
                        padding:6px 12px;
                        border-radius:4px;
                        text-decoration:none;">
                 Iniciar sesión
              </a>';
    }

    echo '</div>';
}
add_action('wp_footer', 'iesc_login_logout_bar');

/* =========================================================
 * BLOQUEO DE RUTAS PARA ALUMNADO Y FILTROS DE MENÚ
 * ========================================================= */

function iesc_bloquear_rutas_reservas_alumnado() {
    if (!is_user_logged_in()) return;

    $upn = iesc_get_current_upn();
    if ($upn === '' || !iesc_usuario_tiene_grupo($upn, 'GRP_ALUMNADO')) return;

    if (isset($_GET['iesc_checkin']) || isset($_GET['iesc_cron_test'])) {
        wp_die('Acceso no permitido.');
    }

    if (is_page()) {
        global $post;
        $slug = isset($post->post_name) ? $post->post_name : '';

        $slugs_bloqueados = array(
            'cuadrante-secundaria',
            'aulas-bachillerato',
            'mis-reservas',
        );

        if ($slug && in_array($slug, $slugs_bloqueados, true)) {
            wp_die('Esta página de reservas solo está disponible para el profesorado.');
        }
    }
}
add_action('template_redirect', 'iesc_bloquear_rutas_reservas_alumnado', 1);

function iesc_filtrar_menu_items_alumnado($items, $args) {
    if (!is_user_logged_in()) return $items;

    $upn = iesc_get_current_upn();
    if ($upn === '' || !iesc_usuario_tiene_grupo($upn, 'GRP_ALUMNADO')) return $items;

    $bloqueos = array(
        '/index.php/aulas-bachillerato/',
        '/index.php/cuadrante-secundaria/',
        '/aulas-bachillerato/',
        '/cuadrante-secundaria/',
        '/mis-reservas/',
    );

    $out = array();
    foreach ((array)$items as $item) {
        $url = isset($item->url) ? (string)$item->url : '';
        $match = false;
        foreach ($bloqueos as $b) {
            if ($b !== '' && stripos($url, $b) !== false) {
                $match = true;
                break;
            }
        }
        if (!$match) $out[] = $item;
    }
    return $out;
}
add_filter('wp_nav_menu_objects', 'iesc_filtrar_menu_items_alumnado', 10, 2);

/**
 * Oculta la auditoría del menú para todo el mundo salvo Jefatura.
 */
function iesc_filtrar_menu_items_auditoria($items, $args) {
    if (!is_user_logged_in()) {
        return $items;
    }

    $upn = iesc_get_current_upn();
    if ($upn === '') {
        return $items;
    }

    if (iesc_usuario_tiene_grupo($upn, 'GRP_GESTION_RESERVAS')) {
        return $items;
    }

    // Ajustar si el slug real de la página cambia
    $slug_auditoria = '/auditoria-reservas/';

    $out = array();
    foreach ((array)$items as $item) {
        $url = isset($item->url) ? (string)$item->url : '';
        if ($slug_auditoria !== '' && stripos($url, $slug_auditoria) !== false) {
            continue;
        }
        $out[] = $item;
    }

    return $out;
}
add_filter('wp_nav_menu_objects', 'iesc_filtrar_menu_items_auditoria', 11, 2);

/* =========================================================
 * CREAR RESERVA (POST)
 * ========================================================= */

function iesc_handle_reserva_post() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

    if (empty($_POST['iesc_reserva_nonce']) ||
        !wp_verify_nonce($_POST['iesc_reserva_nonce'], 'iesc_hacer_reserva')) {
        return;
    }

    if (!is_user_logged_in()) {
        wp_die('Debes iniciar sesión con tu cuenta corporativa para reservar.');
    }

    $upn = iesc_get_current_upn();
    if ($upn === '' || iesc_usuario_tiene_grupo($upn, 'GRP_ALUMNADO')) {
        wp_die('El alumnado no puede realizar reservas.');
    }

    $fecha       = isset($_POST['fecha'])       ? sanitize_text_field($_POST['fecha']) : '';
    $periodo_id  = isset($_POST['periodo_id'])  ? (int)$_POST['periodo_id']            : 0;
    $recurso_id  = isset($_POST['recurso_id'])  ? (int)$_POST['recurso_id']            : 0;
    $edificio_id = isset($_POST['edificio_id']) ? (int)$_POST['edificio_id']           : 0;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $periodo_id <= 0 || $recurso_id <= 0 || $edificio_id <= 0) {
        wp_die('Datos de reserva no válidos.');
    }

    $mysqli = iesc_get_mysqli();
    if (!$mysqli) wp_die('No se pudo conectar con la base de datos de reservas.');

    $usuario_ad_id = iesc_get_usuario_ad_id_by_upn($mysqli, $upn);
    if ($usuario_ad_id <= 0) {
        $mysqli->close();
        wp_die('Tu usuario no está dado de alta en la tabla usuarios_ad.');
    }

    if (!iesc_usuario_tiene_permiso_edificio($upn, $edificio_id)) {
        $mysqli->close();
        wp_die('No tienes permiso para reservar en este edificio.');
    }

    if (!iesc_usuario_puede_reservar_recurso($mysqli, $upn, $recurso_id)) {
        $mysqli->close();
        wp_die('No tienes permiso para reservar este recurso.');
    }

    // Solo necesito la hora de inicio para calcular hasta cuándo se puede validar
    $stmt_p = $mysqli->prepare("SELECT hora_inicio FROM periodos WHERE id = ? LIMIT 1");
    if (!$stmt_p) {
        $mysqli->close();
        wp_die('Error interno al preparar el periodo.');
    }
    $stmt_p->bind_param("i", $periodo_id);
    $stmt_p->execute();
    $res_p = $stmt_p->get_result();
    $row_p = $res_p ? $res_p->fetch_assoc() : null;
    $stmt_p->close();

    if (!$row_p) {
        $mysqli->close();
        wp_die('No se encontró el periodo seleccionado.');
    }

    $hora_inicio = $row_p['hora_inicio'];
    $dt_inicio   = iesc_build_dt($fecha, $hora_inicio);
    if ($dt_inicio) {
        $fecha_hora_inicio_mysql = $dt_inicio->format('Y-m-d H:i:s');
    } else {
        $fecha_hora_inicio_mysql = $fecha . ' ' . trim($hora_inicio);
    }

    // Primero compruebo que ese hueco siga libre
    $stmt_chk = $mysqli->prepare("
        SELECT COUNT(*) AS total
        FROM reserva_franjas rf
        JOIN reservas r ON r.id = rf.reserva_id
        WHERE rf.fecha = ?
          AND rf.periodo_id = ?
          AND rf.recurso_id = ?
          AND r.estado IN ('pendiente','confirmada','validada')
    ");
    if (!$stmt_chk) {
        $mysqli->close();
        wp_die('Error interno al comprobar disponibilidad.');
    }
    $stmt_chk->bind_param("sii", $fecha, $periodo_id, $recurso_id);
    $stmt_chk->execute();
    $res_chk = $stmt_chk->get_result();
    $row_chk = $res_chk ? $res_chk->fetch_assoc() : null;
    $stmt_chk->close();

    if ($row_chk && (int)$row_chk['total'] > 0) {
        $mysqli->close();
        wp_die('Esta franja ya ha sido reservada por otro usuario.');
    }

    // Limpio restos de reservas que ya no deberían bloquear ese hueco
    $stmt_clean = $mysqli->prepare("
        DELETE rf
        FROM reserva_franjas rf
        JOIN reservas r ON r.id = rf.reserva_id
        WHERE rf.fecha = ?
          AND rf.periodo_id = ?
          AND rf.recurso_id = ?
          AND r.estado NOT IN ('pendiente','confirmada','validada')
    ");
    if ($stmt_clean) {
        $stmt_clean->bind_param("sii", $fecha, $periodo_id, $recurso_id);
        $stmt_clean->execute();
        $stmt_clean->close();
    }

    // Creo la reserva en estado pendiente
    $estado = 'pendiente';
    $stmt_r = $mysqli->prepare("
        INSERT INTO reservas (recurso_id, usuario_id, fecha, estado, expira_validacion_en)
        VALUES (?, ?, ?, ?, DATE_ADD(?, INTERVAL 15 MINUTE))
    ");
    if (!$stmt_r) {
        $mysqli->close();
        wp_die('Error interno al crear la reserva.');
    }
    $stmt_r->bind_param("iisss", $recurso_id, $usuario_ad_id, $fecha, $estado, $fecha_hora_inicio_mysql);
    $stmt_r->execute();
    $reserva_id = (int)$stmt_r->insert_id;
    $stmt_r->close();

    if ($reserva_id <= 0) {
        $mysqli->close();
        wp_die('No se pudo crear la reserva.');
    }

    // La apunto en auditoría
    iesc_log_reserva_event(
        $mysqli,
        'crear_reserva',
        $reserva_id,
        $recurso_id,
        $fecha_hora_inicio_mysql
    );

    // Guardo también la franja concreta de esa reserva
    $stmt_rf = $mysqli->prepare("
        INSERT INTO reserva_franjas (reserva_id, recurso_id, periodo_id, fecha)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmt_rf) {
        $mysqli->close();
        wp_die('Error interno al registrar la franja.');
    }
    $stmt_rf->bind_param("iiis", $reserva_id, $recurso_id, $periodo_id, $fecha);
    $ok_rf = $stmt_rf->execute();
    $err_rf = $stmt_rf->error;
    $stmt_rf->close();

    if (!$ok_rf) {
        $mysqli->close();
        wp_die('Error al registrar la franja de reserva: ' . esc_html($err_rf));
    }

    $mysqli->close();

    $redirect = remove_query_arg(array('accion', 'periodo', 'recurso', 'edificio_id', 'fecha'));
    if (!$redirect) $redirect = home_url();

    wp_safe_redirect($redirect);
    exit;
}
add_action('init', 'iesc_handle_reserva_post');

/* =========================================================
 * SHORTCODE DEL CUADRANTE
 * ========================================================= */

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

/* =========================================================
 * CANCELAR RESERVAS (POST) + SHORTCODE [mis_reservas]
 * ========================================================= */

function iesc_usuario_puede_borrar_reserva($mysqli, $upn, $reserva) {
    $upn = strtolower(trim((string)$upn));
    if (!$mysqli || $upn === '' || !$reserva) return false;

    if (iesc_usuario_tiene_grupo($upn, 'GRP_GESTION_RESERVAS')) {
        return true;
    }

    $mi_usuario_ad_id = iesc_get_usuario_ad_id_by_upn($mysqli, $upn);
    if ($mi_usuario_ad_id <= 0) return false;

    if ($mi_usuario_ad_id !== (int)$reserva['usuario_id']) return false;

    $hoy = iesc_today_ymd();
    if (!empty($reserva['fecha']) && $reserva['fecha'] < $hoy) return false;

    return true;
}

function iesc_handle_borrar_reserva_post() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

    if (empty($_POST['iesc_borrar_reserva_nonce']) ||
        !wp_verify_nonce($_POST['iesc_borrar_reserva_nonce'], 'iesc_borrar_reserva')) {
        return;
    }

    if (!is_user_logged_in()) wp_die('Debes iniciar sesión con tu cuenta corporativa.');

    $upn = iesc_get_current_upn();

    $reserva_id = isset($_POST['reserva_id']) ? (int)$_POST['reserva_id'] : 0;
    if ($reserva_id <= 0) wp_die('Reserva no válida.');

    $mysqli = iesc_get_mysqli();
    if (!$mysqli) wp_die('No se pudo conectar con la base de datos de reservas.');

    $stmt = $mysqli->prepare("
        SELECT r.id, r.usuario_id, MIN(rf.fecha) AS fecha
        FROM reservas r
        JOIN reserva_franjas rf ON rf.reserva_id = r.id
        WHERE r.id = ?
        GROUP BY r.id, r.usuario_id
        LIMIT 1
    ");
    if (!$stmt) {
        $mysqli->close();
        wp_die('Error al localizar la reserva.');
    }

    $stmt->bind_param("i", $reserva_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $reserva = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$reserva) {
        $mysqli->close();
        wp_die('La reserva no existe.');
    }

    if (!iesc_usuario_puede_borrar_reserva($mysqli, $upn, $reserva)) {
        $mysqli->close();
        wp_die('No tienes permiso para cancelar esta reserva.');
    }

    $stmt_rf = $mysqli->prepare("DELETE FROM reserva_franjas WHERE reserva_id = ?");
    if ($stmt_rf) {
        $stmt_rf->bind_param("i", $reserva_id);
        $stmt_rf->execute();
        $stmt_rf->close();
    }

    $stmt_r = $mysqli->prepare("DELETE FROM reservas WHERE id = ?");
    if ($stmt_r) {
        $stmt_r->bind_param("i", $reserva_id);
        $stmt_r->execute();
        $stmt_r->close();
    }

    $mysqli->close();

    $redirect = !empty($_POST['_wp_http_referer']) ? esc_url_raw($_POST['_wp_http_referer']) : home_url();
    wp_safe_redirect($redirect);
    exit;
}
add_action('init', 'iesc_handle_borrar_reserva_post');

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

/* =========================================================
 * SHORTCODE: [auditoria_reservas] SOLO PARA JEFATURA
 * ========================================================= */

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

    // Por defecto muestro los registros del día actual
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

/* =========================================================
 * CHECK-IN POR QR (ENDPOINT)
 * ========================================================= */

function iesc_endpoint_checkin() {
    if (!isset($_GET['iesc_checkin'])) return;

    if (!is_user_logged_in()) wp_die('Debes iniciar sesión para validar tu reserva.');

    $upn = iesc_get_current_upn();
    if ($upn === '' || iesc_usuario_tiene_grupo($upn, 'GRP_ALUMNADO')) {
        wp_die('Acceso no permitido.');
    }

    if (!isset($_GET['recurso_id']) || !is_numeric($_GET['recurso_id'])) {
        wp_die('QR inválido: falta el identificador del aula.');
    }

    $recurso_id = (int)$_GET['recurso_id'];

    $mysqli = iesc_get_mysqli();
    if (!$mysqli) wp_die('Error de conexión con la base de datos de reservas.');

    $usuario_ad_id = iesc_get_usuario_ad_id_by_upn($mysqli, $upn);
    if ($usuario_ad_id <= 0) {
        $mysqli->close();
        wp_die('Tu usuario no está dado de alta en la tabla usuarios_ad.');
    }

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
        wp_die('Error interno al preparar la consulta.');
    }

    $stmt->bind_param('iiss', $recurso_id, $usuario_ad_id, $fecha_hoy, $ahora);
    $stmt->execute();
    $res = $stmt->get_result();
    $reserva = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$reserva) {
        $mysqli->close();
        wp_die('No se ha encontrado ninguna reserva vigente para este aula, o ha expirado el tiempo de validación.');
    }

    $sql2 = "
        UPDATE reservas
        SET validada_en = ?, estado = 'validada'
        WHERE id = ?
    ";

    $stmt2 = $mysqli->prepare($sql2);
    if (!$stmt2) {
        $mysqli->close();
        wp_die('Error interno al preparar la actualización.');
    }

    $stmt2->bind_param('si', $ahora, $reserva['id']);
    $ok = $stmt2->execute();
    $stmt2->close();
    $mysqli->close();

    if (!$ok) wp_die('Se ha producido un error al validar la reserva. Inténtalo de nuevo.');

    wp_die('Reserva validada correctamente.');
}
add_action('init', 'iesc_endpoint_checkin');

/* =========================================================
 * CRON PARA LIBERAR RESERVAS NO VALIDADAS
 * ========================================================= */

function iesc_cron_liberar_reservas() {
    $mysqli = iesc_get_mysqli();
    if (!$mysqli) {
        wp_die('Cron: sin conexión a BBDD.');
    }

    $sql = "
        UPDATE reservas
        SET estado = 'liberada'
        WHERE estado = 'pendiente'
          AND validada_en IS NULL
          AND expira_validacion_en IS NOT NULL
          AND expira_validacion_en < NOW()
    ";

    $ok = $mysqli->query($sql);
    $affected = $mysqli->affected_rows;
    $err = $mysqli->error;

    $mysqli->close();

    wp_die("Cron OK=" . ($ok ? '1' : '0') . " | affected_rows=$affected | err=$err");
}

function iesc_cron_schedules($schedules) {
    if (!isset($schedules['five_minutes'])) {
        $schedules['five_minutes'] = array(
            'interval' => 300,
            'display'  => 'Cada 5 minutos (IES Conecta)'
        );
    }
    return $schedules;
}
add_filter('cron_schedules', 'iesc_cron_schedules');

function iesc_reservas_activate() {
    if (!wp_next_scheduled('iesc_evento_liberar_reservas')) {
        wp_schedule_event(time(), 'five_minutes', 'iesc_evento_liberar_reservas');
    }
}
register_activation_hook(__FILE__, 'iesc_reservas_activate');

function iesc_reservas_deactivate() {
    wp_clear_scheduled_hook('iesc_evento_liberar_reservas');
}
register_deactivation_hook(__FILE__, 'iesc_reservas_deactivate');

add_action('iesc_evento_liberar_reservas', 'iesc_cron_liberar_reservas');

function iesc_cron_test_manual() {
    if (isset($_GET['iesc_cron_test'])) {
        iesc_cron_liberar_reservas();
        wp_die('Cron de liberación ejecutado manualmente.');
    }
}
add_action('init', 'iesc_cron_test_manual');

/* =========================================================
 * SHORTCODE: [iesc_checkin]
 * ========================================================= */

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

/* =========================================================
 * REDIRECCIÓN TRAS LOGIN Y BLOQUEO DEL DASHBOARD
 * ========================================================= */

/**
 * Después del login:
 * - Admin sigue como siempre
 * - El resto va a la página principal de reservas
 */
add_filter('login_redirect', 'iesc_login_redirect_reservas', 10, 3);
function iesc_login_redirect_reservas($redirect_to, $request, $user) {

    if (!($user instanceof WP_User)) {
        return $redirect_to;
    }

    if (user_can($user, 'manage_options')) {
        return $redirect_to;
    }

    // Aquí hay que poner el slug real de la página de reservas
    $reservas_page = get_page_by_path('ies-conecta');
    if ($reservas_page) {
        return get_permalink($reservas_page->ID);
    }

    return home_url('/');
}

/**
 * Bloquea el acceso a /wp-admin para quien no sea admin.
 */
add_action('admin_init', 'iesc_block_wp_admin_for_non_admins');
function iesc_block_wp_admin_for_non_admins() {

    if (!is_user_logged_in()) {
        return;
    }

    if (current_user_can('manage_options') || wp_doing_ajax()) {
        return;
    }

    $reservas_page = get_page_by_path('ies-conecta');
    if ($reservas_page) {
        $destino = get_permalink($reservas_page->ID);
    } else {
        $destino = home_url('/');
    }

    wp_redirect($destino);
    exit;
}

/**
 * Oculta la barra de administración para quien no sea admin.
 */
add_filter('show_admin_bar', function($show) {
    return current_user_can('manage_options');
});

/**
 * Si alguien entra directamente en la página de reservas sin login,
 * lo mando a autenticarse primero.
 */
add_action('template_redirect', 'iesc_proteger_pagina_reservas');
function iesc_proteger_pagina_reservas() {

    if (is_page('ies-conecta') && !is_user_logged_in()) {
        $url_reservas = get_permalink();
        $login_url    = wp_login_url($url_reservas);

        wp_redirect($login_url);
        exit;
    }
}
