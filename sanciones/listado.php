<?php

session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../permisos.php";

verificarPermiso(['admin', 'supervisor']);

$error = "";
$sanciones = [];

/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$filtro_buscar = trim($_GET["buscar"] ?? "");
$filtro_tipo = trim($_GET["tipo_sancion"] ?? "");
$filtro_estado = trim($_GET["estado"] ?? "");
$filtro_accesorias = trim($_GET["accesorias"] ?? "");
$filtro_fecha_desde = trim($_GET["fecha_desde"] ?? "");
$filtro_fecha_hasta = trim($_GET["fecha_hasta"] ?? "");

/*
|--------------------------------------------------------------------------
| CONSULTA PRINCIPAL
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        s.id,
        s.dni,
        s.apellido_nombre,
        s.cedula_id,
        s.tipo_sancion,
        s.dias_suspension,
        s.fecha_inicio,
        s.fecha_fin,
        s.observaciones,
        s.estado_cumplimiento,
        s.fecha_creacion,

        (
            SELECT COUNT(*)
            FROM sanciones_accesorias sa
            WHERE sa.sancion_id = s.id
        ) AS cantidad_accesorias,

        c.id AS cedula_id_original,
        ca.numero_caso

    FROM sanciones s

    LEFT JOIN cedulas c ON c.id = s.cedula_id
    LEFT JOIN casos ca ON ca.id = c.caso_id

    WHERE 1=1
";

$parametros = [];
$tipos = "";

if ($filtro_buscar !== "") {
    $sql .= " AND (
        s.dni LIKE ?
        OR s.apellido_nombre LIKE ?
        OR ca.numero_caso LIKE ?
    )";
    $busqueda_sql = "%" . $filtro_buscar . "%";
    $parametros[] = $busqueda_sql;
    $parametros[] = $busqueda_sql;
    $parametros[] = $busqueda_sql;
    $tipos .= "sss";
}

if ($filtro_tipo !== "") {
    $sql .= " AND s.tipo_sancion = ?";
    $parametros[] = $filtro_tipo;
    $tipos .= "s";
}

if ($filtro_estado !== "") {
    $sql .= " AND s.estado_cumplimiento = ?";
    $parametros[] = $filtro_estado;
    $tipos .= "s";
}

if ($filtro_accesorias === "si") {
    $sql .= " AND (SELECT COUNT(*) FROM sanciones_accesorias sa WHERE sa.sancion_id = s.id) > 0";
} elseif ($filtro_accesorias === "no") {
    $sql .= " AND (SELECT COUNT(*) FROM sanciones_accesorias sa WHERE sa.sancion_id = s.id) = 0";
}

if ($filtro_fecha_desde !== "") {
    $sql .= " AND DATE(s.fecha_creacion) >= ?";
    $parametros[] = $filtro_fecha_desde;
    $tipos .= "s";
}

if ($filtro_fecha_hasta !== "") {
    $sql .= " AND DATE(s.fecha_creacion) <= ?";
    $parametros[] = $filtro_fecha_hasta;
    $tipos .= "s";
}

$sql .= " ORDER BY s.fecha_creacion DESC, s.id DESC LIMIT 500";

$stmt = mysqli_prepare($conexion, $sql);

if ($stmt === false) {

    $error = "Error SQL al preparar la consulta: " . mysqli_error($conexion);

} else {

    if (count($parametros) > 0) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$parametros);
    }

    if (!mysqli_stmt_execute($stmt)) {

        $error = "Error al consultar sanciones: " . mysqli_stmt_error($stmt);

    } else {

        $resultado = mysqli_stmt_get_result($stmt);

        while ($fila = mysqli_fetch_assoc($resultado)) {
            $sanciones[] = $fila;
        }
    }

    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| ESTADÍSTICAS
|--------------------------------------------------------------------------
*/

$stats = [
    'total' => 0,
    'apercibimiento' => 0,
    'suspension' => 0,
    'cesantia' => 0,
    'destitucion' => 0,
    'pendientes' => 0,
    'cumplidas' => 0,
];

$sql_stats = "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN tipo_sancion = 'APERCIBIMIENTO' THEN 1 ELSE 0 END) AS apercibimiento,
        SUM(CASE WHEN tipo_sancion = 'SUSPENSION' THEN 1 ELSE 0 END) AS suspension,
        SUM(CASE WHEN tipo_sancion = 'CESANTIA' THEN 1 ELSE 0 END) AS cesantia,
        SUM(CASE WHEN tipo_sancion = 'DESTITUCION' THEN 1 ELSE 0 END) AS destitucion,
        SUM(CASE WHEN estado_cumplimiento = 'PENDIENTE' THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN estado_cumplimiento = 'CUMPLIDA' THEN 1 ELSE 0 END) AS cumplidas
    FROM sanciones
";

$res_stats = mysqli_query($conexion, $sql_stats);

if ($res_stats) {
    $stats = mysqli_fetch_assoc($res_stats);
    foreach ($stats as $k => $v) {
        $stats[$k] = (int)($v ?? 0);
    }
}

/*
|--------------------------------------------------------------------------
| FUNCIONES AUXILIARES
|--------------------------------------------------------------------------
*/

function h($valor) {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function fecha_argentina($fecha) {
    if (empty($fecha)) return '-';
    $ts = strtotime($fecha);
    if (!$ts) return h($fecha);
    return date('d/m/Y', $ts);
}

function clase_tipo_sancion($tipo) {
    switch ($tipo) {
        case 'APERCIBIMIENTO': return 'tipo-apercibimiento';
        case 'SUSPENSION': return 'tipo-suspension';
        case 'CESANTIA': return 'tipo-cesantia';
        case 'DESTITUCION': return 'tipo-destitucion';
        default: return 'tipo-otro';
    }
}

function clase_estado_cumplimiento($estado) {
    switch ($estado) {
        case 'PENDIENTE': return 'estado-pendiente';
        case 'EN_CURSO': return 'estado-en-curso';
        case 'CUMPLIDA': return 'estado-cumplida';
        case 'INCUMPLIDA': return 'estado-incumplida';
        default: return 'estado-otro';
    }
}

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Sanciones - Sistema Medidas</title>

    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; background: #f3f4f6; color: #1f2937; }
        .layout { display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background: #111827; color: white; min-height: 100vh; position: fixed; left: 0; top: 0; bottom: 0; }
        .sidebar-header { padding: 25px 20px; border-bottom: 1px solid #374151; }
        .sidebar-header h2 { margin: 0; font-size: 20px; }
        .sidebar-header p { margin: 6px 0 0; color: #9ca3af; font-size: 12px; }
        .badge-rol { display: inline-block; margin-top: 8px; padding: 4px 10px; background: #1d4ed8; color: white; border-radius: 20px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-rol.admin { background: #dc2626; }
        .badge-rol.supervisor { background: #0891b2; }
        .badge-rol.operador { background: #16a34a; }
        .badge-rol.consulta { background: #6b7280; }
        .menu { padding: 15px 10px; }
        .menu-title { color: #6b7280; font-size: 11px; font-weight: bold; margin: 15px 10px 8px; text-transform: uppercase; }
        .menu a { display: block; color: #d1d5db; text-decoration: none; padding: 11px 12px; border-radius: 6px; margin-bottom: 3px; font-size: 14px; }
        .menu a:hover { background: #1f2937; color: white; }
        .menu a.active { background: #1d4ed8; color: white; }
        .logout { position: absolute; bottom: 20px; left: 10px; right: 10px; }
        .logout a { display: block; text-align: center; padding: 11px; background: #991b1b; color: white; text-decoration: none; border-radius: 6px; }
        .main { margin-left: 250px; width: calc(100% - 250px); }
        .topbar { background: white; border-bottom: 1px solid #e5e7eb; padding: 18px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { margin: 0; font-size: 22px; }
        .user-info { text-align: right; font-size: 13px; }
        .user-info strong { display: block; }
        .user-info span { color: #6b7280; }
        .content { padding: 30px; max-width: 1600px; }
        .stats { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 20px; }
        .stat { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 15px; border-left: 5px solid #1d4ed8; }
        .stat.total { border-left-color: #374151; }
        .stat.apercibimiento { border-left-color: #f59e0b; }
        .stat.suspension { border-left-color: #3b82f6; }
        .stat.cesantia { border-left-color: #a855f7; }
        .stat.destitucion { border-left-color: #dc2626; }
        .stat.cumplidas { border-left-color: #10b981; }
        .stat-label { font-size: 11px; color: #6b7280; margin-bottom: 6px; text-transform: uppercase; font-weight: bold; }
        .stat-number { font-size: 24px; font-weight: bold; color: #1f2937; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 15px; }
        .card-title { margin: 0; font-size: 18px; }
        .filtros { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr auto; gap: 12px; align-items: end; margin-bottom: 20px; }
        .form-group { margin-bottom: 0; }
        label { display: block; font-size: 12px; font-weight: bold; margin-bottom: 6px; color: #374151; }
        input, select { width: 100%; padding: 10px 11px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-family: inherit; }
        input:focus, select:focus { outline: none; border-color: #2563eb; }
        .btn { border: none; border-radius: 6px; padding: 10px 15px; cursor: pointer; font-size: 13px; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1d4ed8; color: white; }
        .btn-primary:hover { background: #1e40af; }
        .btn-secondary { background: #374151; color: white; }
        .btn-secondary:hover { background: #1f2937; }
        .btn-info { background: #0891b2; color: white; }
        .btn-info:hover { background: #0e7490; }
        .btn-sm { padding: 6px 10px; font-size: 12px; border-radius: 5px; }
        .tabla-contenedor { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1200px; }
        th { background: #f9fafb; color: #374151; font-size: 11px; text-align: left; padding: 12px 10px; border-bottom: 2px solid #e5e7eb; text-transform: uppercase; white-space: nowrap; }
        td { padding: 12px 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px; vertical-align: middle; }
        tr:hover { background: #f9fafb; }
        .tipo-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: bold; text-transform: uppercase; white-space: nowrap; }
        .tipo-apercibimiento { background: #fef3c7; color: #92400e; }
        .tipo-suspension { background: #dbeafe; color: #1e40af; }
        .tipo-cesantia { background: #e9d5ff; color: #6b21a8; }
        .tipo-destitucion { background: #fee2e2; color: #991b1b; }
        .tipo-otro { background: #e5e7eb; color: #374151; }
        .estado-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: bold; text-transform: uppercase; white-space: nowrap; }
        .estado-pendiente { background: #fef3c7; color: #92400e; }
        .estado-en-curso { background: #dbeafe; color: #1e40af; }
        .estado-cumplida { background: #dcfce7; color: #166534; }
        .estado-incumplida { background: #fee2e2; color: #991b1b; }
        .estado-otro { background: #e5e7eb; color: #374151; }
        .info-persona { line-height: 1.4; }
        .info-persona .nombre { font-weight: bold; }
        .info-persona .dni { color: #6b7280; font-size: 11px; font-family: monospace; }
        .sin-datos { text-align: center; padding: 50px 20px; color: #6b7280; }
        .sin-datos strong { display: block; color: #374151; font-size: 16px; margin-bottom: 8px; }
        .accesorias-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; background: #e0e7ff; color: #4338ca; }
        @media (max-width: 1300px) {
            .stats { grid-template-columns: repeat(3, 1fr); }
            .filtros { grid-template-columns: 1fr 1fr 1fr; }
        }
        @media (max-width: 900px) {
            .sidebar { width: 210px; }
            .main { margin-left: 210px; width: calc(100% - 210px); }
            .filtros { grid-template-columns: 1fr 1fr; }
            .stats { grid-template-columns: 1fr 1fr; }
        }
    </style>

</head>

<body>

<div class="layout">

    <aside class="sidebar">
        <div class="sidebar-header">
            <h2>SISTEMA MEDIDAS</h2>
            <p>Gestión de cédulas</p>
            <div class="badge-rol <?= htmlspecialchars(rolActual()) ?>">
                <?= htmlspecialchars(strtoupper(rolActual())) ?>
            </div>
        </div>
        <nav class="menu">
            <div class="menu-title">Principal</div>
            <a href="../index.php">Inicio</a>

            <?php if (tienePermiso(['admin', 'supervisor', 'operador'])): ?>
                <a href="../cedulas/nueva.php">Nueva cédula</a>
            <?php endif; ?>

            <a href="../cedulas/listado.php">Cédulas</a>

            <div class="menu-title">Seguimiento</div>
            <a href="../cedulas/pendientes.php">Pendientes de diligenciar</a>
            <a href="../cedulas/diligenciamiento.php">En diligenciamiento</a>
            <a href="../cedulas/cumplimentadas.php">Cumplimentadas</a>

            <?php if (tienePermiso(['admin', 'supervisor'])): ?>
                <div class="menu-title">Administración</div>
                <a href="../encargados/listado.php">Encargados</a>
                <a href="../medidas/listado.php">Medidas</a>
                <a href="../medidas/tipos.php">Tipos de medida</a>
                <a href="../personas/listado.php">👥 Personas con medidas</a>
                <a href="../sanciones/listado.php">⚖️ Sanciones</a>
                <a href="listado.php" class="active">⚖️ Sanciones</a>
            <?php endif; ?>

            <?php if (esAdmin()): ?>
                <a href="../usuarios/listado.php">Usuarios</a>
                <a href="../auditoria/listado.php">🔍 Auditoría</a>
            <?php endif; ?>
        </nav>
        <div class="logout">
            <a href="../logout.php">Cerrar sesión</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <h1>⚖️ Sanciones</h1>
            <div class="user-info">
                <strong>
                    <?= h(trim(($_SESSION['jerarquia'] ?? '') . ' ' . ($_SESSION['apellido'] ?? '') . ', ' . ($_SESSION['nombre'] ?? ''))) ?>
                </strong>
                <span>Usuario: <?= h($_SESSION['usuario'] ?? '') ?></span>
            </div>
        </header>

        <section class="content">

            <?php if ($error !== ""): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <!-- ESTADÍSTICAS -->
            <div class="stats">
                <div class="stat total">
                    <div class="stat-label">Total</div>
                    <div class="stat-number"><?= $stats['total'] ?></div>
                </div>
                <div class="stat apercibimiento">
                    <div class="stat-label">Apercibimientos</div>
                    <div class="stat-number"><?= $stats['apercibimiento'] ?></div>
                </div>
                <div class="stat suspension">
                    <div class="stat-label">Suspensiones</div>
                    <div class="stat-number"><?= $stats['suspension'] ?></div>
                </div>
                <div class="stat cesantia">
                    <div class="stat-label">Cesantías</div>
                    <div class="stat-number"><?= $stats['cesantia'] ?></div>
                </div>
                <div class="stat destitucion">
                    <div class="stat-label">Destituciones</div>
                    <div class="stat-number"><?= $stats['destitucion'] ?></div>
                </div>
                <div class="stat cumplidas">
                    <div class="stat-label">Cumplidas</div>
                    <div class="stat-number"><?= $stats['cumplidas'] ?></div>
                </div>
            </div>

            <!-- FILTROS -->
            <div class="card">
                <h2 class="card-title">Filtros de búsqueda</h2>

                <form method="GET">
                    <div class="filtros">
                        <div class="form-group">
                            <label for="buscar">Buscar (DNI, nombre, caso)</label>
                            <input type="text" id="buscar" name="buscar" value="<?= h($filtro_buscar) ?>" placeholder="Buscar...">
                        </div>

                        <div class="form-group">
                            <label for="tipo_sancion">Tipo de sanción</label>
                            <select id="tipo_sancion" name="tipo_sancion">
                                <option value="">Todas</option>
                                <option value="APERCIBIMIENTO" <?= ($filtro_tipo === "APERCIBIMIENTO") ? "selected" : "" ?>>Apercibimiento</option>
                                <option value="SUSPENSION" <?= ($filtro_tipo === "SUSPENSION") ? "selected" : "" ?>>Suspensión</option>
                                <option value="CESANTIA" <?= ($filtro_tipo === "CESANTIA") ? "selected" : "" ?>>Cesantía</option>
                                <option value="DESTITUCION" <?= ($filtro_tipo === "DESTITUCION") ? "selected" : "" ?>>Destitución</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="estado">Estado</label>
                            <select id="estado" name="estado">
                                <option value="">Todos</option>
                                <option value="PENDIENTE" <?= ($filtro_estado === "PENDIENTE") ? "selected" : "" ?>>Pendiente</option>
                                <option value="EN_CURSO" <?= ($filtro_estado === "EN_CURSO") ? "selected" : "" ?>>En curso</option>
                                <option value="CUMPLIDA" <?= ($filtro_estado === "CUMPLIDA") ? "selected" : "" ?>>Cumplida</option>
                                <option value="INCUMPLIDA" <?= ($filtro_estado === "INCUMPLIDA") ? "selected" : "" ?>>Incumplida</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="accesorias">Accesorias</label>
                            <select id="accesorias" name="accesorias">
                                <option value="">Todas</option>
                                <option value="si" <?= ($filtro_accesorias === "si") ? "selected" : "" ?>>Con accesorias</option>
                                <option value="no" <?= ($filtro_accesorias === "no") ? "selected" : "" ?>>Sin accesorias</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="fecha_desde">Desde</label>
                            <input type="date" id="fecha_desde" name="fecha_desde" value="<?= h($filtro_fecha_desde) ?>">
                        </div>

                        <div class="form-group">
                            <label for="fecha_hasta">Hasta</label>
                            <input type="date" id="fecha_hasta" name="fecha_hasta" value="<?= h($filtro_fecha_hasta) ?>">
                        </div>

                        <div style="display: flex; gap: 8px;">
                            <button type="submit" class="btn btn-primary">Filtrar</button>
                            <?php if ($filtro_buscar || $filtro_tipo || $filtro_estado || $filtro_accesorias || $filtro_fecha_desde || $filtro_fecha_hasta): ?>
                                <a href="listado.php" class="btn btn-secondary">Limpiar</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- LISTADO -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Listado de sanciones (<?= count($sanciones) ?>)</h2>

                    <?php if (tienePermiso(['admin', 'supervisor'])): ?>
                        <a href="nueva.php" class="btn btn-primary">+ Nueva sanción</a>
                    <?php endif; ?>
                </div>

                <?php if (count($sanciones) === 0): ?>
                    <div class="sin-datos">
                        <strong>No hay sanciones registradas</strong>
                        <span>No se encontraron sanciones con los filtros aplicados.</span>
                    </div>
                <?php else: ?>
                    <div class="tabla-contenedor">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Fecha</th>
                                    <th>Persona</th>
                                    <th>Tipo</th>
                                    <th>Días</th>
                                    <th>Accesorias</th>
                                    <th>Inicio</th>
                                    <th>Fin</th>
                                    <th>Estado</th>
                                    <th>Caso</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sanciones as $sancion): ?>
                                    <tr>
                                        <td><strong>#<?= (int)$sancion['id'] ?></strong></td>

                                        <td><?= fecha_argentina($sancion['fecha_creacion']) ?></td>

                                        <td>
                                            <div class="info-persona">
                                                <div class="nombre"><?= h($sancion['apellido_nombre']) ?></div>
                                                <div class="dni"><?= h($sancion['dni']) ?></div>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="tipo-badge <?= clase_tipo_sancion($sancion['tipo_sancion']) ?>">
                                                <?= h($sancion['tipo_sancion']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php if ($sancion['tipo_sancion'] === 'SUSPENSION' && $sancion['dias_suspension']): ?>
                                                <strong><?= (int)$sancion['dias_suspension'] ?></strong> días
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if ((int)$sancion['cantidad_accesorias'] > 0): ?>
                                                <span class="accesorias-badge">
                                                    <?= (int)$sancion['cantidad_accesorias'] ?>
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>

                                        <td><?= fecha_argentina($sancion['fecha_inicio']) ?></td>

                                        <td><?= fecha_argentina($sancion['fecha_fin']) ?></td>

                                        <td>
                                            <span class="estado-badge <?= clase_estado_cumplimiento($sancion['estado_cumplimiento']) ?>">
                                                <?= h($sancion['estado_cumplimiento']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php if (!empty($sancion['numero_caso'])): ?>
                                                <strong><?= h($sancion['numero_caso']) ?></strong>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <a href="ver.php?id=<?= (int)$sancion['id'] ?>" class="btn btn-info btn-sm">Ver</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </section>
    </main>
</div>

</body>

</html>