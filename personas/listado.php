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
$personas = [];

/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$filtro_buscar = trim($_GET["buscar"] ?? "");
$filtro_activa = trim($_GET["activa"] ?? "");
$filtro_tipo_medida = (int)($_GET["tipo_medida"] ?? 0);
$filtro_dependencia = trim($_GET["dependencia"] ?? "");
$filtro_fecha_desde = trim($_GET["fecha_desde"] ?? "");
$filtro_fecha_hasta = trim($_GET["fecha_hasta"] ?? "");

/*
|--------------------------------------------------------------------------
| CONSULTA PRINCIPAL
|--------------------------------------------------------------------------
|
| Agrupamos por DNI para obtener una fila por persona.
|
| Para cada persona:
|   - Última medida (la más reciente por fecha de creación)
|   - Total de medidas
|   - Cantidad de medidas activas
|
*/

$sql = "
    SELECT
        i.dni,
        i.jerarquia,
        i.apellido_nombre,
        i.dependencia,

        COUNT(DISTINCT m.id) AS total_medidas,

        (
            SELECT COUNT(DISTINCT m2.id)
            FROM involucrados i2
            INNER JOIN medidas m2 ON m2.involucrado_id = i2.id
            WHERE i2.dni = i.dni
              AND m2.tipo_medida_id NOT IN (15, 20, 21, 22)
        ) AS medidas_activas,

        (
            SELECT tm3.nombre
            FROM involucrados i3
            INNER JOIN medidas m3 ON m3.involucrado_id = i3.id
            LEFT JOIN tipos_medida tm3 ON tm3.id = m3.tipo_medida_id
            WHERE i3.dni = i.dni
            ORDER BY m3.fecha_creacion DESC, m3.id DESC
            LIMIT 1
        ) AS ultima_medida_nombre,

        (
            SELECT m4.tipo_medida_id
            FROM involucrados i4
            INNER JOIN medidas m4 ON m4.involucrado_id = i4.id
            WHERE i4.dni = i.dni
            ORDER BY m4.fecha_creacion DESC, m4.id DESC
            LIMIT 1
        ) AS ultima_medida_tipo_id,

        (
            SELECT c5.fecha_ingreso
            FROM involucrados i5
            INNER JOIN medidas m5 ON m5.involucrado_id = i5.id
            INNER JOIN cedulas c5 ON c5.id = m5.cedula_id
            WHERE i5.dni = i.dni
            ORDER BY m5.fecha_creacion DESC, m5.id DESC
            LIMIT 1
        ) AS ultima_fecha

    FROM involucrados i
    INNER JOIN medidas m ON m.involucrado_id = i.id
    WHERE 1=1
";

$parametros = [];
$tipos = "";

if ($filtro_buscar !== "") {
    $sql .= " AND (i.dni LIKE ? OR i.apellido_nombre LIKE ? OR i.jerarquia LIKE ?)";
    $busqueda_sql = "%" . $filtro_buscar . "%";
    $parametros[] = $busqueda_sql;
    $parametros[] = $busqueda_sql;
    $parametros[] = $busqueda_sql;
    $tipos .= "sss";
}

if ($filtro_tipo_medida > 0) {
    $sql .= " AND EXISTS (
        SELECT 1 FROM involucrados ix
        INNER JOIN medidas mx ON mx.involucrado_id = ix.id
        WHERE ix.dni = i.dni
          AND mx.tipo_medida_id = ?
    )";
    $parametros[] = $filtro_tipo_medida;
    $tipos .= "i";
}

if ($filtro_dependencia !== "") {
    $sql .= " AND i.dependencia LIKE ?";
    $parametros[] = "%" . $filtro_dependencia . "%";
    $tipos .= "s";
}

if ($filtro_fecha_desde !== "") {
    $sql .= " AND DATE(m.fecha_creacion) >= ?";
    $parametros[] = $filtro_fecha_desde;
    $tipos .= "s";
}

if ($filtro_fecha_hasta !== "") {
    $sql .= " AND DATE(m.fecha_creacion) <= ?";
    $parametros[] = $filtro_fecha_hasta;
    $tipos .= "s";
}

$sql .= " GROUP BY i.dni, i.jerarquia, i.apellido_nombre, i.dependencia";

// Filtro de activa (se aplica después del GROUP BY con HAVING)
if ($filtro_activa === "si") {
    $sql .= " HAVING medidas_activas > 0";
} elseif ($filtro_activa === "no") {
    $sql .= " HAVING medidas_activas = 0";
}

$sql .= " ORDER BY i.apellido_nombre ASC, i.dni ASC LIMIT 1000";

$stmt = mysqli_prepare($conexion, $sql);

if ($stmt === false) {

    $error = "Error SQL: " . mysqli_error($conexion);

} else {

    if (count($parametros) > 0) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$parametros);
    }

    if (!mysqli_stmt_execute($stmt)) {

        $error = "Error al consultar: " . mysqli_stmt_error($stmt);

    } else {

        $resultado = mysqli_stmt_get_result($stmt);

        while ($fila = mysqli_fetch_assoc($resultado)) {
            $personas[] = $fila;
        }
    }

    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| CARGAR TIPOS DE MEDIDA PARA FILTRO
|--------------------------------------------------------------------------
*/

$tipos_disponibles = [];
$sql_tipos = "SELECT id, nombre FROM tipos_medida WHERE activo = 1 ORDER BY nombre ASC";
$res_tipos = mysqli_query($conexion, $sql_tipos);
if ($res_tipos) {
    while ($fila = mysqli_fetch_assoc($res_tipos)) {
        $tipos_disponibles[] = $fila;
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

function calcular_estado($ultima_medida_tipo_id) {
    // Tipos que finalizan la medida
    $tipos_cierre = [15, 20, 21, 22];

    if ($ultima_medida_tipo_id === null) {
        return ['clase' => 'estado-sin-datos', 'texto' => 'SIN DATOS'];
    }

    $tipo = (int)$ultima_medida_tipo_id;

    if (!in_array($tipo, $tipos_cierre)) {
        return ['clase' => 'estado-activa', 'texto' => 'ACTIVA'];
    }

    switch ($tipo) {
        case 15:
            return ['clase' => 'estado-servicio', 'texto' => 'FINALIZADA - Servicio Efectivo'];
        case 20:
            return ['clase' => 'estado-cesantia', 'texto' => 'FINALIZADA - Cesantía'];
        case 21:
            return ['clase' => 'estado-destitucion', 'texto' => 'FINALIZADA - Destitución'];
        case 22:
            return ['clase' => 'estado-sobreseimiento', 'texto' => 'FINALIZADA - Sobreseimiento'];
        default:
            return ['clase' => 'estado-finalizada', 'texto' => 'FINALIZADA'];
    }
}

/*
|--------------------------------------------------------------------------
| ESTADÍSTICAS GENERALES
|--------------------------------------------------------------------------
*/

$total_personas = count($personas);
$total_activas = 0;
$total_finalizadas = 0;

foreach ($personas as $p) {
    if ((int)$p['medidas_activas'] > 0) {
        $total_activas++;
    } else {
        $total_finalizadas++;
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Personas con medidas - Sistema Medidas</title>

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
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; border-left: 5px solid #1d4ed8; }
        .stat.activa { border-left-color: #10b981; }
        .stat.finalizada { border-left-color: #6b7280; }
        .stat-label { font-size: 12px; color: #6b7280; margin-bottom: 8px; text-transform: uppercase; }
        .stat-number { font-size: 28px; font-weight: bold; color: #1d4ed8; }
        .stat.activa .stat-number { color: #10b981; }
        .stat.finalizada .stat-number { color: #6b7280; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-title { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
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
        th { background: #f9fafb; color: #374151; font-size: 12px; text-align: left; padding: 12px 10px; border-bottom: 2px solid #e5e7eb; text-transform: uppercase; white-space: nowrap; }
        td { padding: 12px 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px; vertical-align: middle; }
        tr:hover { background: #f9fafb; }
        .estado-badge { display: inline-block; padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; white-space: nowrap; }
        .estado-activa { background: #dcfce7; color: #166534; }
        .estado-servicio { background: #dbeafe; color: #1e40af; }
        .estado-cesantia { background: #fed7aa; color: #9a3412; }
        .estado-destitucion { background: #fee2e2; color: #991b1b; }
        .estado-sobreseimiento { background: #e5e7eb; color: #374151; }
        .estado-finalizada { background: #f3f4f6; color: #374151; }
        .estado-sin-datos { background: #f3f4f6; color: #9ca3af; }
        .contador-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; background: #e0e7ff; color: #4338ca; }
        .contador-badge.activa { background: #d1fae5; color: #065f46; }
        .contador-badge.cero { background: #f3f4f6; color: #9ca3af; }
        .dni-texto { font-family: monospace; font-weight: bold; }
        .sin-datos { text-align: center; padding: 50px 20px; color: #6b7280; }
        .sin-datos strong { display: block; color: #374151; font-size: 16px; margin-bottom: 8px; }
        .info-persona { line-height: 1.5; }
        .info-persona .nombre { font-weight: bold; }
        .info-persona .detalle { color: #6b7280; font-size: 12px; }
        @media (max-width: 1200px) {
            .filtros { grid-template-columns: 1fr 1fr 1fr; }
        }
        @media (max-width: 900px) {
            .sidebar { width: 210px; }
            .main { margin-left: 210px; width: calc(100% - 210px); }
            .filtros { grid-template-columns: 1fr 1fr; }
            .stats { grid-template-columns: 1fr; }
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
                <a href="../sanciones/listado.php">⚖️ Sanciones</a>
                <a href="listado.php" class="active">👥 Personas con medidas</a>
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
            <h1>👥 Personas con medidas</h1>
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

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">Total de personas</div>
                    <div class="stat-number"><?= number_format($total_personas) ?></div>
                </div>
                <div class="stat activa">
                    <div class="stat-label">Con medida activa</div>
                    <div class="stat-number"><?= number_format($total_activas) ?></div>
                </div>
                <div class="stat finalizada">
                    <div class="stat-label">Con medida finalizada</div>
                    <div class="stat-number"><?= number_format($total_finalizadas) ?></div>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title">Filtros de búsqueda</h2>

                <form method="GET">
                    <div class="filtros">
                        <div class="form-group">
                            <label for="buscar">Buscar (DNI, nombre, jerarquía)</label>
                            <input type="text" id="buscar" name="buscar" value="<?= h($filtro_buscar) ?>" placeholder="Buscar...">
                        </div>

                        <div class="form-group">
                            <label for="activa">Medida activa</label>
                            <select id="activa" name="activa">
                                <option value="">Todas</option>
                                <option value="si" <?= ($filtro_activa === "si") ? "selected" : "" ?>>Solo activas</option>
                                <option value="no" <?= ($filtro_activa === "no") ? "selected" : "" ?>>Solo finalizadas</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="tipo_medida">Tipo de medida</label>
                            <select id="tipo_medida" name="tipo_medida">
                                <option value="">Todos</option>
                                <?php foreach ($tipos_disponibles as $tipo): ?>
                                    <option value="<?= (int)$tipo['id'] ?>" <?= ($filtro_tipo_medida === (int)$tipo['id']) ? "selected" : "" ?>>
                                        <?= h($tipo['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="dependencia">Dependencia</label>
                            <input type="text" id="dependencia" name="dependencia" value="<?= h($filtro_dependencia) ?>" placeholder="Ej: DGSC">
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
                            <?php if ($filtro_buscar || $filtro_activa || $filtro_tipo_medida || $filtro_dependencia || $filtro_fecha_desde || $filtro_fecha_hasta): ?>
                                <a href="listado.php" class="btn btn-secondary">Limpiar</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <div class="card">
                <h2 class="card-title">Listado de personas (<?= count($personas) ?>)</h2>

                <?php if (count($personas) === 0): ?>
                    <div class="sin-datos">
                        <strong>No se encontraron personas</strong>
                        <span>No hay resultados con los filtros aplicados.</span>
                    </div>
                <?php else: ?>
                    <div class="tabla-contenedor">
                        <table>
                            <thead>
                                <tr>
                                    <th>Jerarquía</th>
                                    <th>Apellido y nombre</th>
                                    <th>DNI</th>
                                    <th>Total medidas</th>
                                    <th>Activas</th>
                                    <th>Última medida</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($personas as $persona): ?>
                                    <?php
                                    $estado_info = calcular_estado($persona['ultima_medida_tipo_id']);
                                    $total_med = (int)$persona['total_medidas'];
                                    $activas = (int)$persona['medidas_activas'];
                                    ?>
                                    <tr>
                                        <td><?= h($persona['jerarquia'] ?? '-') ?></td>
                                        <td>
                                            <div class="info-persona">
                                                <div class="nombre"><?= h($persona['apellido_nombre']) ?></div>
                                                <?php if (!empty($persona['dependencia'])): ?>
                                                    <div class="detalle"><?= h($persona['dependencia']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="dni-texto"><?= h($persona['dni']) ?></td>
                                        <td>
                                            <span class="contador-badge"><?= $total_med ?></span>
                                        </td>
                                        <td>
                                            <?php if ($activas > 0): ?>
                                                <span class="contador-badge activa"><?= $activas ?></span>
                                            <?php else: ?>
                                                <span class="contador-badge cero">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($persona['ultima_medida_nombre'] ?? '-') ?></td>
                                        <td>
                                            <span class="estado-badge <?= $estado_info['clase'] ?>">
                                                <?= h($estado_info['texto']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="ver.php?dni=<?= urlencode($persona['dni']) ?>" class="btn btn-info btn-sm">Ver detalle</a>
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