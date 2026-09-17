<?php

session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../permisos.php";

verificarPermiso(['admin']);

$error = "";
$registros = [];

/*
|--------------------------------------------------------------------------
| FILTROS
|--------------------------------------------------------------------------
*/

$filtro_usuario = trim($_GET["usuario"] ?? "");
$filtro_modulo = trim($_GET["modulo"] ?? "");
$filtro_accion = trim($_GET["accion"] ?? "");
$filtro_fecha_desde = trim($_GET["fecha_desde"] ?? "");
$filtro_fecha_hasta = trim($_GET["fecha_hasta"] ?? "");
$filtro_buscar = trim($_GET["buscar"] ?? "");

/*
|--------------------------------------------------------------------------
| CONSULTA PRINCIPAL
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        a.id,
        a.usuario_id,
        a.usuario_nombre,
        a.accion,
        a.modulo,
        a.tabla_afectada,
        a.registro_id,
        a.descripcion,
        a.ip,
        a.fecha
    FROM auditoria a
    WHERE 1=1
";

$parametros = [];
$tipos = "";

if ($filtro_usuario !== "") {
    $sql .= " AND a.usuario_nombre LIKE ?";
    $parametros[] = "%" . $filtro_usuario . "%";
    $tipos .= "s";
}

if ($filtro_modulo !== "") {
    $sql .= " AND a.modulo = ?";
    $parametros[] = $filtro_modulo;
    $tipos .= "s";
}

if ($filtro_accion !== "") {
    $sql .= " AND a.accion = ?";
    $parametros[] = $filtro_accion;
    $tipos .= "s";
}

if ($filtro_fecha_desde !== "") {
    $sql .= " AND DATE(a.fecha) >= ?";
    $parametros[] = $filtro_fecha_desde;
    $tipos .= "s";
}

if ($filtro_fecha_hasta !== "") {
    $sql .= " AND DATE(a.fecha) <= ?";
    $parametros[] = $filtro_fecha_hasta;
    $tipos .= "s";
}

if ($filtro_buscar !== "") {
    $sql .= " AND (a.descripcion LIKE ? OR a.usuario_nombre LIKE ? OR a.ip LIKE ?)";
    $parametros[] = "%" . $filtro_buscar . "%";
    $parametros[] = "%" . $filtro_buscar . "%";
    $parametros[] = "%" . $filtro_buscar . "%";
    $tipos .= "sss";
}

$sql .= " ORDER BY a.fecha DESC, a.id DESC LIMIT 500";

$stmt = mysqli_prepare($conexion, $sql);

if ($stmt === false) {

    $error = "Error SQL al preparar la consulta: " . mysqli_error($conexion);

} else {

    if (count($parametros) > 0) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$parametros);
    }

    if (!mysqli_stmt_execute($stmt)) {

        $error = "Error al consultar la auditoría: " . mysqli_stmt_error($stmt);

    } else {

        $resultado = mysqli_stmt_get_result($stmt);

        while ($fila = mysqli_fetch_assoc($resultado)) {
            $registros[] = $fila;
        }
    }

    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| LISTA DE MÓDULOS Y ACCIONES (para filtros)
|--------------------------------------------------------------------------
*/

$modulos = [];
$sql_modulos = "SELECT DISTINCT modulo FROM auditoria ORDER BY modulo ASC";
$res_modulos = mysqli_query($conexion, $sql_modulos);
if ($res_modulos) {
    while ($fila = mysqli_fetch_assoc($res_modulos)) {
        $modulos[] = $fila['modulo'];
    }
}

$acciones = [];
$sql_acciones = "SELECT DISTINCT accion FROM auditoria ORDER BY accion ASC";
$res_acciones = mysqli_query($conexion, $sql_acciones);
if ($res_acciones) {
    while ($fila = mysqli_fetch_assoc($res_acciones)) {
        $acciones[] = $fila['accion'];
    }
}

/*
|--------------------------------------------------------------------------
| TOTAL DE REGISTROS EN LA TABLA
|--------------------------------------------------------------------------
*/

$total_auditoria = 0;
$res_total = mysqli_query($conexion, "SELECT COUNT(*) AS total FROM auditoria");
if ($res_total) {
    $fila_total = mysqli_fetch_assoc($res_total);
    $total_auditoria = (int)$fila_total['total'];
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Auditoría - Sistema Medidas</title>

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
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-title { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; }
        .stat-label { font-size: 12px; color: #6b7280; margin-bottom: 8px; text-transform: uppercase; }
        .stat-number { font-size: 26px; font-weight: bold; color: #1d4ed8; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
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
        .tabla-contenedor { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1100px; }
        th { background: #f9fafb; color: #374151; font-size: 12px; text-align: left; padding: 12px 10px; border-bottom: 2px solid #e5e7eb; text-transform: uppercase; white-space: nowrap; }
        td { padding: 12px 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px; vertical-align: top; }
        tr:hover { background: #f9fafb; }
        .accion-badge { display: inline-block; padding: 4px 8px; border-radius: 20px; font-size: 10px; font-weight: bold; text-transform: uppercase; white-space: nowrap; }
        .accion-crear { background: #dcfce7; color: #166534; }
        .accion-editar { background: #fef3c7; color: #92400e; }
        .accion-eliminar { background: #fee2e2; color: #991b1b; }
        .accion-login { background: #dbeafe; color: #1e40af; }
        .accion-logout { background: #e5e7eb; color: #374151; }
        .accion-login_fallido { background: #fecaca; color: #7f1d1d; }
        .accion-asignar { background: #cffafe; color: #0e7490; }
        .accion-transferir { background: #e0e7ff; color: #4338ca; }
        .accion-cumplimentar { background: #d1fae5; color: #065f46; }
        .accion-subir_pdf { background: #fce7f3; color: #9d174d; }
        .accion-error { background: #fee2e2; color: #991b1b; }
        .accion-otro { background: #e5e7eb; color: #374151; }
        .modulo-badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; background: #f3f4f6; color: #374151; }
        .fecha-celda { font-size: 12px; color: #6b7280; white-space: nowrap; }
        .descripcion-celda { max-width: 400px; line-height: 1.5; }
        .ip-celda { font-family: monospace; font-size: 12px; color: #6b7280; }
        .sin-registros { text-align: center; padding: 40px 20px; color: #6b7280; }
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
            <?php endif; ?>

            <?php if (esAdmin()): ?>
                <a href="../usuarios/listado.php">Usuarios</a>
                <a href="listado.php" class="active">🔍 Auditoría</a>
            <?php endif; ?>
        </nav>
        <div class="logout">
            <a href="../logout.php">Cerrar sesión</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <h1>🔍 Auditoría del sistema</h1>
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
                    <div class="stat-label">Total de movimientos</div>
                    <div class="stat-number"><?= number_format($total_auditoria) ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Registros mostrados</div>
                    <div class="stat-number"><?= count($registros) ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Límite de consulta</div>
                    <div class="stat-number" style="color: #6b7280;">500</div>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title">Filtros de búsqueda</h2>

                <form method="GET">
                    <div class="filtros">
                        <div class="form-group">
                            <label for="buscar">Buscar (texto, usuario o IP)</label>
                            <input type="text" id="buscar" name="buscar" value="<?= htmlspecialchars($filtro_buscar) ?>" placeholder="Buscar...">
                        </div>

                        <div class="form-group">
                            <label for="usuario">Usuario</label>
                            <input type="text" id="usuario" name="usuario" value="<?= htmlspecialchars($filtro_usuario) ?>" placeholder="Nombre">
                        </div>

                        <div class="form-group">
                            <label for="modulo">Módulo</label>
                            <select id="modulo" name="modulo">
                                <option value="">Todos</option>
                                <?php foreach ($modulos as $mod): ?>
                                    <option value="<?= htmlspecialchars($mod) ?>" <?= ($filtro_modulo === $mod) ? "selected" : "" ?>>
                                        <?= htmlspecialchars($mod) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="accion">Acción</label>
                            <select id="accion" name="accion">
                                <option value="">Todas</option>
                                <?php foreach ($acciones as $acc): ?>
                                    <option value="<?= htmlspecialchars($acc) ?>" <?= ($filtro_accion === $acc) ? "selected" : "" ?>>
                                        <?= htmlspecialchars($acc) ?>
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

                        <div>
                            <button type="submit" class="btn btn-primary">Filtrar</button>
                        </div>
                    </div>

                    <?php if ($filtro_buscar || $filtro_usuario || $filtro_modulo || $filtro_accion || $filtro_fecha_desde || $filtro_fecha_hasta): ?>
                        <div style="margin-top: 10px;">
                            <a href="listado.php" class="btn btn-secondary">Limpiar filtros</a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <div class="card">
                <h2 class="card-title">Movimientos registrados (últimos 500)</h2>

                <?php if (count($registros) === 0): ?>
                    <div class="sin-registros">
                        <p>No se encontraron movimientos con los filtros aplicados.</p>
                    </div>
                <?php else: ?>
                    <div class="tabla-contenedor">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Fecha</th>
                                    <th>Usuario</th>
                                    <th>Acción</th>
                                    <th>Módulo</th>
                                    <th>Registro</th>
                                    <th>Descripción</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($registros as $reg): ?>
                                    <?php
                                    $accion = strtolower($reg['accion']);
                                    $clase_accion = 'accion-otro';

                                    if ($accion === 'crear') $clase_accion = 'accion-crear';
                                    elseif ($accion === 'editar') $clase_accion = 'accion-editar';
                                    elseif ($accion === 'eliminar') $clase_accion = 'accion-eliminar';
                                    elseif ($accion === 'login') $clase_accion = 'accion-login';
                                    elseif ($accion === 'logout') $clase_accion = 'accion-logout';
                                    elseif ($accion === 'login_fallido') $clase_accion = 'accion-login_fallido';
                                    elseif ($accion === 'asignar') $clase_accion = 'accion-asignar';
                                    elseif ($accion === 'transferir') $clase_accion = 'accion-transferir';
                                    elseif ($accion === 'cumplimentar') $clase_accion = 'accion-cumplimentar';
                                    elseif ($accion === 'subir_pdf') $clase_accion = 'accion-subir_pdf';
                                    elseif ($accion === 'error') $clase_accion = 'accion-error';
                                    ?>
                                    <tr>
                                        <td><?= (int)$reg['id'] ?></td>
                                        <td class="fecha-celda">
                                            <?= date("d/m/Y H:i:s", strtotime($reg['fecha'])) ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($reg['usuario_nombre'] ?: 'Sistema') ?>
                                        </td>
                                        <td>
                                            <span class="accion-badge <?= $clase_accion ?>">
                                                <?= htmlspecialchars($reg['accion']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="modulo-badge">
                                                <?= htmlspecialchars($reg['modulo']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($reg['registro_id']): ?>
                                                #<?= (int)$reg['registro_id'] ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="descripcion-celda">
                                            <?= nl2br(htmlspecialchars($reg['descripcion'] ?? '')) ?>
                                        </td>
                                        <td class="ip-celda">
                                            <?= htmlspecialchars($reg['ip'] ?? '-') ?>
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