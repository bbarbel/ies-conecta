<?php
/**
 * Bloques del plugin relacionados con permisos por grupos, edificio y recurso.
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
