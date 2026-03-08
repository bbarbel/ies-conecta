<?php
/**
 * Bloques del plugin relacionados con el control de acceso y las redirecciones.
 */

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

add_filter('login_redirect', 'iesc_login_redirect_reservas', 10, 3);
function iesc_login_redirect_reservas($redirect_to, $request, $user) {

    if (!($user instanceof WP_User)) {
        return $redirect_to;
    }

    if (user_can($user, 'manage_options')) {
        return $redirect_to;
    }

    $reservas_page = get_page_by_path('ies-conecta');
    if ($reservas_page) {
        return get_permalink($reservas_page->ID);
    }

    return home_url('/');
}

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

add_filter('show_admin_bar', function($show) {
    return current_user_can('manage_options');
});

add_action('template_redirect', 'iesc_proteger_pagina_reservas');
function iesc_proteger_pagina_reservas() {

    if (is_page('ies-conecta') && !is_user_logged_in()) {
        $url_reservas = get_permalink();
        $login_url    = wp_login_url($url_reservas);

        wp_redirect($login_url);
        exit;
    }
}
