<?php

session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../permisos.php";

verificarPermiso(['admin', 'supervisor', 'operador', 'consulta']);

$error = "";
$medidas = [];

/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$filtro_buscar = trim($_GET["buscar"] ?? "");
$filtro_categoria = trim($_GET["categoria"] ?? "");
$filtro_tipo = (int)($_GET["tipo_medida"] ?? 0);
$filtro_fecha_desde = trim($_GET["fecha_desde"] ?? "");
$filtro_fecha_hasta = trim($_GET["fecha_hasta"] ?? "");

/*
|--------------------------------------------------------------------------
| CONSULTA PRINCIPAL
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        m.id,
        m.numero_resolucion,
        m.anio_resolucion,
        m.observaciones AS observaciones_medida,
        m.fecha_creacion,

        i.id AS involucrado_id,
        i.jerarquia,
        i.apellido_nombre,
        i.dni,
        i.dependencia,

        tm.id AS tipo_medida_id,
        tm.nombre AS tipo_medida_nombre,
        tm.categoria AS tipo_medida_categoria,

        c.id AS cedula_id,
        c.fecha_ingreso,
        c.estado AS cedula_estado,
        c.titulo_medida AS cedula_titulo_medida,

        ca.numero_caso

    FROM medidas m

    INNER JOIN involucrados i
        ON i.id = m.involucrado_id

    INNER JOIN cedulas c
        ON c.id = m.cedula_id

    INNER JOIN casos ca
        ON ca.id = c.caso_id

    LEFT JOIN tipos_medida tm
        ON tm.id = m.tipo_medida_id

    WHERE 1=1
";

$parametros = [];
$tipos = "";

if ($filtro_buscar !== "") {
    $sql .= " AND (
        ca.numero_caso LIKE ?
        OR i.apellido_nombre LIKE ?
        OR i.dni LIKE ?
        OR m.numero_resolucion LIKE ?
    )";
    $busqueda_sql = "%" . $filtro_buscar . "%";
    $parametros[] = $busqueda_sql;
    $parametros[] = $busqueda_sql;
    $parametros[] = $busqueda_sql;
    $parametros[] = $busqueda_sql;
    $tipos .= "ssss";
}

if ($filtro_categoria !== "") {
    $sql .= " AND tm.categoria = ?";
    $parametros[] = $filtro_categoria;
    $tipos .= "s";
}

if ($filtro_tipo > 0) {
    $sql .= " AND m.tipo_medida_id = ?";
    $parametros[] = $filtro_tipo;
    $tipos .= "i";
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

$sql .= " ORDER BY m.fecha_creacion DESC, m.id DESC LIMIT 500";

$stmt = mysqli_prepare($conexion, $sql);

if ($stmt === false) {

    $error = "Error SQL al preparar la consulta: " . mysqli_error($conexion);

} else {

    if (count($parametros) > 0) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$parametros);
    }

    if (!mysqli_stmt_execute($stmt)) {

        $error = "Error al consultar medidas: " . mysqli_stmt_error($stmt);

    } else {

        $resultado = mysqli_stmt_get_result($stmt);

        while ($fila = mysqli_fetch_assoc($resultado)) {
            $medidas[] = $fila;
        }
    }

    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| CARGAR CATEGORÍAS Y TIPOS PARA FILTROS
|--------------------------------------------------------------------------
*/

$categorias_disponibles = ['INICIAL', 'PRORROGA', 'CAMBIO', 'LEVANTAMIENTO', 'EXCEPCIONAL'];

$tipos_disponibles = [];
$sql_tipos = "SELECT id, nombre, categoria FROM tipos_medida WHERE activo = 1 ORDER BY categoria, nombre";
$res_tipos = mysqli_query($conexion, $sql_tipos);
if ($res_tipos) {
    while ($fila = mysqli_fetch_assoc($res_tipos)) {
        $tipos_disponibles[] = $fila;
    }
}

/*
|--------------------------------------------------------------------------
| TOTAL GENERAL
|--------------------------------------------------------------------------
*/

$total_medidas = 0;
$res_total = mysqli_query($conexion, "SELECT COUNT(*) AS total FROM medidas");
if ($res_total) {
    $fila_total = mysqli_fetch_assoc($res_total);
    $total_medidas = (int)$fila_total['total'];
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Medidas - Sistema Medidas</title>

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
        .content { padding: 30px; max-width: 1500px; }
        .stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; }
        .stat-label { font-size: 12px; color: #6b7280; margin-bottom: 8px; text-transform: uppercase; }
        .stat-number { font-size: 28px; font-weight: bold; color: #1d4ed8; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-title { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .filtros { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto; gap: 12px; align-items: end; margin-bottom: 20px; }
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
        td { padding: 12px 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px; vertical-align: top; }
        tr:hover { background: #f9fafb; }
        .categoria-badge { display: inline-block; padding: 4px 8px; border-radius: 20px; font-size: 10px; font-weight: bold; text-transform: uppercase; white-space: nowrap; }
        .cat-inicial { background: #dbeafe; color: #1e40af; }
        .cat-prorroga { background: #fef3c7; color: #92400e; }
        .cat-cambio { background: #e0e7ff; color: #4338ca; }
        .cat-levantamiento { background: #d1fae5; color: #065f46; }
        .cat-excepcional { background: #fce7f3; color: #9d174d; }
        .cat-sin { background: #e5e7eb; color: #374151; }
        .involucrado-info { line-height: 1.5; }
        .involucrado-nombre { font-weight: bold; }
        .involucrado-dni { color: #6b7280; font-size: 12px; }
        .caso-info { line-height: 1.5; }
        .caso-numero { font-weight: bold; color: #1d4ed8; }
        .sin-datos { text-align: center; padding: 50px 20px; color: #6b7280; }
        .sin-datos strong { display: block; color: #374151; font-size: 16px; margin-bottom: 8px; }
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
                <a href="../personas/listado.php">👥 Personas con medidas</a>
                <a href="../sanciones/listado.php">⚖️ Sanciones</a>
                <a href="listado.php" class="active">Medidas</a>
                <a href="tipos.php">Tipos de medida</a>
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
            <h1>Medidas</h1>
            <div class="user-info">
                <strong>
                    <?= htmlspecialchars(
                        ($_SESSION["jerarquia"] ?? "") . " " . ($_SESSION["apellido"] ?? "") . ", " . ($_SESSION["nombre"] ?? "")
                    ) ?>
                </strong>
                <span>Usuario: <?= htmlspecialchars($_SESSION["usuario"] ?? "") ?></span>
            </div>
        </header>

        <section class="content">

            <?php if ($error !== ""): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">Total de medidas en el sistema</div>
                    <div class="stat-number"><?= number_format($total_medidas) ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Medidas mostradas (límite 500)</div>
                    <div class="stat-number"><?= count($medidas) ?></div>
                </div>
            </div>

            <!-- FILTROS -->
            <div class="card">
                <h2 class="card-title">Filtros de búsqueda</h2>

                <form method="GET">
                    <div class="filtros">
                        <div class="form-group">
                            <label for="buscar">Buscar (caso, nombre, DNI, resolución)</label>
                            <input type="text" id="buscar" name="buscar" value="<?= htmlspecialchars($filtro_buscar) ?>" placeholder="Buscar...">
                        </div>

                        <div class="form-group">
                            <label for="categoria">Categoría</label>
                            <select id="categoria" name="categoria">
                                <option value="">Todas</option>
                                <?php foreach ($categorias_disponibles as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>" <?= ($filtro_categoria === $cat) ? "selected" : "" ?>>
                                        <?= htmlspecialchars($cat) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="tipo_medida">Tipo específico</label>
                            <select id="tipo_medida" name="tipo_medida">
                                <option value="">Todos</option>
                                <?php foreach ($tipos_disponibles as $tipo): ?>
                                    <option value="<?= (int)$tipo['id'] ?>" <?= ($filtro_tipo === (int)$tipo['id']) ? "selected" : "" ?>>
                                        <?= htmlspecialchars($tipo['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="fecha_desde">Desde</label>
                            <input type="date" id="fecha_desde" name="fecha_desde" value="<?= htmlspecialchars($filtro_fecha_desde) ?>">
                        </div>

                        <div class="form-group">
                            <label for="fecha_hasta">Hasta</label>
                            <input type="date" id="fecha_hasta" name="fecha_hasta" value="<?= htmlspecialchars($filtro_fecha_hasta) ?>">
                        </div>

                        <div style="display: flex; gap: 8px;">
                            <button type="submit" class="btn btn-primary">Filtrar</button>
                            <?php if ($filtro_buscar || $filtro_categoria || $filtro_tipo || $filtro_fecha_desde || $filtro_fecha_hasta): ?>
                                <a href="listado.php" class="btn btn-secondary">Limpiar</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- LISTADO -->
            <div class="card">
                <h2 class="card-title">Listado de medidas registradas</h2>

                <?php if (count($medidas) === 0): ?>
                    <div class="sin-datos">
                        <strong>No hay medidas registradas</strong>
                        <span>No se encontraron medidas con los filtros aplicados.</span>
                    </div>
                <?php else: ?>
                    <div class="tabla-contenedor">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tipo de medida</th>
                                    <th>Categoría</th>
                                    <th>Involucrado</th>
                                    <th>Caso / Cédula</th>
                                    <th>Resolución</th>
                                    <th>Fecha</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($medidas as $medida): ?>
                                    <?php
                                    $cat = $medida['tipo_medida_categoria'] ?? '';
                                    $clase_cat = 'cat-sin';
                                    if ($cat === 'INICIAL') $clase_cat = 'cat-inicial';
                                    elseif ($cat === 'PRORROGA') $clase_cat = 'cat-prorroga';
                                    elseif ($cat === 'CAMBIO') $clase_cat = 'cat-cambio';
                                    elseif ($cat === 'LEVANTAMIENTO') $clase_cat = 'cat-levantamiento';
                                    elseif ($cat === 'EXCEPCIONAL') $clase_cat = 'cat-excepcional';
                                    ?>
                                    <tr>
                                        <td><?= (int)$medida['id'] ?></td>

                                        <td>
                                            <strong><?= htmlspecialchars($medida['tipo_medida_nombre'] ?? 'Sin tipo') ?></strong>
                                        </td>

                                        <td>
                                            <span class="categoria-badge <?= $clase_cat ?>">
                                                <?= htmlspecialchars($cat ?: 'SIN CAT.') ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="involucrado-info">
                                                <div class="involucrado-nombre">
                                                    <?= htmlspecialchars(($medida['jerarquia'] ?? '') . ' ' . $medida['apellido_nombre']) ?>
                                                </div>
                                                <div class="involucrado-dni">
                                                    DNI: <?= htmlspecialchars($medida['dni'] ?? '-') ?>
                                                </div>
                                                <div class="involucrado-dni">
                                                    <?= htmlspecialchars($medida['dependencia'] ?? '-') ?>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <div class="caso-info">
                                                <div class="caso-numero">
                                                    Caso Nº <?= htmlspecialchars($medida['numero_caso']) ?>
                                                </div>
                                                <div class="involucrado-dni">
                                                    Cédula #<?= (int)$medida['cedula_id'] ?>
                                                </div>
                                                <div class="involucrado-dni">
                                                    <?= htmlspecialchars($medida['cedula_estado']) ?>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($medida['numero_resolucion'] ?: '-') ?>
                                            <?php if ($medida['anio_resolucion']): ?>
                                                / <?= htmlspecialchars($medida['anio_resolucion']) ?>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= $medida['fecha_creacion'] ? date("d/m/Y", strtotime($medida['fecha_creacion'])) : '-' ?>
                                        </td>

                                        <td>
                                            <div class="acciones">
                                                <a href="../cedulas/ver.php?id=<?= (int)$medida['cedula_id'] ?>" class="btn btn-info btn-sm">Ver cédula</a>
                                            </div>
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