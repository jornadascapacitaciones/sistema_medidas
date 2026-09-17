<?php

session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../permisos.php";

verificarPermiso(['admin', 'supervisor', 'operador', 'consulta']);

$cedula_id = (int)($_GET["id"] ?? 0);

if ($cedula_id <= 0) {
    die("ID de cédula no válido. Debe especificar ?id=XXX en la URL.");
}

function h($valor)
{
    return htmlspecialchars(
        (string)($valor ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function fecha_argentina($fecha)
{
    if (empty($fecha)) {
        return '-';
    }

    $timestamp = strtotime($fecha);

    if (!$timestamp) {
        return h($fecha);
    }

    return date('d/m/Y H:i:s', $timestamp);
}

/*
|--------------------------------------------------------------------------
| CARGAR CÉDULA
|--------------------------------------------------------------------------
*/

$sql_cedula = "
    SELECT
        c.id,
        c.estado,
        c.fecha_ingreso,
        c.fecha_asignacion,
        c.fecha_cumplimentacion,
        c.asunto,
        c.titulo_medida,
        ca.numero_caso
    FROM cedulas c
    INNER JOIN casos ca ON ca.id = c.caso_id
    WHERE c.id = ?
    LIMIT 1
";

$stmt_cedula = mysqli_prepare($conexion, $sql_cedula);

if ($stmt_cedula === false) {
    die("Error SQL al cargar la cédula: " . mysqli_error($conexion));
}

mysqli_stmt_bind_param($stmt_cedula, "i", $cedula_id);
mysqli_stmt_execute($stmt_cedula);
$res_cedula = mysqli_stmt_get_result($stmt_cedula);
$cedula = mysqli_fetch_assoc($res_cedula);
mysqli_stmt_close($stmt_cedula);

if (!$cedula) {
    die("La cédula indicada no existe.");
}

/*
|--------------------------------------------------------------------------
| CARGAR HISTORIAL
|--------------------------------------------------------------------------
*/

$historial = [];

$sql_historial = "
    SELECT
        h.id,
        h.usuario_id,
        h.accion,
        h.descripcion,
        h.fecha,
        u.usuario AS usuario_username,
        u.nombre AS usuario_nombre,
        u.apellido AS usuario_apellido,
        u.jerarquia AS usuario_jerarquia
    FROM historial_cedula h
    LEFT JOIN usuarios u ON u.id = h.usuario_id
    WHERE h.cedula_id = ?
    ORDER BY h.fecha ASC, h.id ASC
";

$stmt_historial = mysqli_prepare($conexion, $sql_historial);
mysqli_stmt_bind_param($stmt_historial, "i", $cedula_id);
mysqli_stmt_execute($stmt_historial);
$res_historial = mysqli_stmt_get_result($stmt_historial);

while ($fila = mysqli_fetch_assoc($res_historial)) {
    $historial[] = $fila;
}

mysqli_stmt_close($stmt_historial);

/*
|--------------------------------------------------------------------------
| CARGAR AUDITORÍA RELACIONADA (opcional - solo para admin)
|--------------------------------------------------------------------------
*/

$auditoria_relacionada = [];

if (esAdmin()) {
    $sql_aud = "
        SELECT id, usuario_nombre, accion, modulo, descripcion, fecha, ip
        FROM auditoria
        WHERE (tabla_afectada = 'cedulas' AND registro_id = ?)
           OR (tabla_afectada = 'diligenciamientos' AND descripcion LIKE ?)
           OR (modulo = 'archivos' AND registro_id = ?)
        ORDER BY fecha ASC
        LIMIT 100
    ";

    $busqueda_desc = '%caso Nº ' . $cedula['numero_caso'] . '%';

    $stmt_aud = mysqli_prepare($conexion, $sql_aud);

    if ($stmt_aud) {
        mysqli_stmt_bind_param($stmt_aud, "isi", $cedula_id, $busqueda_desc, $cedula_id);
        mysqli_stmt_execute($stmt_aud);
        $res_aud = mysqli_stmt_get_result($stmt_aud);

        while ($fila = mysqli_fetch_assoc($res_aud)) {
            $auditoria_relacionada[] = $fila;
        }

        mysqli_stmt_close($stmt_aud);
    }
}

/*
|--------------------------------------------------------------------------
| ESTADÍSTICAS DEL HISTORIAL
|--------------------------------------------------------------------------
*/

$total_movimientos = count($historial);
$primer_movimiento = $historial[0] ?? null;
$ultimo_movimiento = end($historial) ?: null;

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Historial - Sistema Medidas</title>

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
        .content { padding: 30px; max-width: 1200px; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-title { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .info-item { padding: 12px; background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; }
        .info-label { display: block; font-size: 11px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin-bottom: 5px; }
        .info-value { font-size: 14px; color: #111827; }
        .full { grid-column: 1 / -1; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; }
        .stat-label { font-size: 12px; color: #6b7280; margin-bottom: 8px; text-transform: uppercase; }
        .stat-number { font-size: 26px; font-weight: bold; color: #1d4ed8; }
        .timeline { position: relative; margin-top: 10px; }
        .timeline-item { position: relative; padding: 0 0 25px 40px; border-left: 2px solid #d1d5db; margin-left: 12px; }
        .timeline-item:last-child { border-left-color: transparent; padding-bottom: 0; }
        .timeline-dot { position: absolute; left: -9px; top: 0; width: 16px; height: 16px; border-radius: 50%; background: #2563eb; border: 3px solid #dbeafe; }
        .timeline-date { font-size: 12px; color: #6b7280; margin-bottom: 6px; }
        .timeline-action { font-weight: bold; font-size: 14px; margin-bottom: 6px; color: #1e40af; }
        .timeline-description { font-size: 13px; line-height: 1.6; color: #374151; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; }
        .timeline-user { font-size: 12px; color: #6b7280; margin-top: 6px; }
        .empty { color: #6b7280; font-style: italic; padding: 30px 0; text-align: center; }
        .btn { border: none; border-radius: 6px; padding: 10px 15px; cursor: pointer; font-size: 13px; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1d4ed8; color: white; }
        .btn-primary:hover { background: #1e40af; }
        .btn-secondary { background: #374151; color: white; }
        .btn-secondary:hover { background: #1f2937; }
        .btn-info { background: #0891b2; color: white; }
        .btn-info:hover { background: #0e7490; }
        .acciones { display: flex; gap: 10px; flex-wrap: wrap; }
        .estado-badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .estado-pendiente { background: #fef3c7; color: #92400e; }
        .estado-diligenciamiento { background: #dbeafe; color: #1e40af; }
        .estado-cumplimentada { background: #dcfce7; color: #166534; }
        .auditoria-tabla { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 12px; }
        .auditoria-tabla th { background: #f9fafb; padding: 8px 10px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 11px; text-transform: uppercase; }
        .auditoria-tabla td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        @media (max-width: 900px) {
            .sidebar { width: 210px; }
            .main { margin-left: 210px; width: calc(100% - 210px); }
            .info-grid { grid-template-columns: 1fr; }
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
                <a href="../auditoria/listado.php">🔍 Auditoría</a>
            <?php endif; ?>
        </nav>
        <div class="logout">
            <a href="../logout.php">Cerrar sesión</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <h1>Historial de cédula</h1>
            <div class="user-info">
                <strong>
                    <?= h(trim(($_SESSION['jerarquia'] ?? '') . ' ' . ($_SESSION['apellido'] ?? '') . ', ' . ($_SESSION['nombre'] ?? ''))) ?>
                </strong>
                <span>Usuario: <?= h($_SESSION['usuario'] ?? '') ?></span>
            </div>
        </header>

        <section class="content">

            <!-- RESUMEN DE LA CÉDULA -->
            <div class="card">
                <h2 class="card-title">Resumen de la cédula #<?= (int)$cedula['id'] ?></h2>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Número de caso</span>
                        <span class="info-value"><?= h($cedula['numero_caso']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Estado actual</span>
                        <span class="info-value">
                            <?php
                            $clase_estado = 'estado-pendiente';
                            if ($cedula['estado'] === 'CUMPLIMENTADA') $clase_estado = 'estado-cumplimentada';
                            elseif ($cedula['estado'] === 'EN_DILIGENCIAMIENTO') $clase_estado = 'estado-diligenciamiento';
                            ?>
                            <span class="estado-badge <?= $clase_estado ?>"><?= h($cedula['estado']) ?></span>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Fecha de ingreso</span>
                        <span class="info-value"><?= fecha_argentina($cedula['fecha_ingreso']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Fecha de asignación</span>
                        <span class="info-value"><?= fecha_argentina($cedula['fecha_asignacion']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Fecha de cumplimentación</span>
                        <span class="info-value"><?= fecha_argentina($cedula['fecha_cumplimentacion']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Medida</span>
                        <span class="info-value"><?= h($cedula['titulo_medida'] ?: '-') ?></span>
                    </div>
                    <div class="info-item full">
                        <span class="info-label">Asunto</span>
                        <span class="info-value"><?= nl2br(h($cedula['asunto'] ?: '-')) ?></span>
                    </div>
                </div>
            </div>

            <!-- ESTADÍSTICAS -->
            <div class="stats">
                <div class="stat">
                    <div class="stat-label">Total de movimientos</div>
                    <div class="stat-number"><?= $total_movimientos ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Primer movimiento</div>
                    <div class="stat-number" style="font-size: 14px;">
                        <?= $primer_movimiento ? fecha_argentina($primer_movimiento['fecha']) : '-' ?>
                    </div>
                </div>
                <div class="stat">
                    <div class="stat-label">Último movimiento</div>
                    <div class="stat-number" style="font-size: 14px;">
                        <?= $ultimo_movimiento ? fecha_argentina($ultimo_movimiento['fecha']) : '-' ?>
                    </div>
                </div>
            </div>

            <!-- TIMELINE DEL HISTORIAL -->
            <div class="card">
                <h2 class="card-title">📅 Historial completo de la cédula</h2>

                <?php if (count($historial) === 0): ?>
                    <div class="empty">
                        Esta cédula todavía no tiene movimientos registrados.
                    </div>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($historial as $mov): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div class="timeline-date"><?= fecha_argentina($mov['fecha']) ?></div>
                                <div class="timeline-action"><?= h($mov['accion']) ?></div>
                                <?php if (!empty($mov['descripcion'])): ?>
                                    <div class="timeline-description"><?= nl2br(h($mov['descripcion'])) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($mov['usuario_username'])): ?>
                                    <div class="timeline-user">
                                        Usuario: <?= h(trim(($mov['usuario_jerarquia'] ?? '') . ' ' . ($mov['usuario_apellido'] ?? '') . ', ' . ($mov['usuario_nombre'] ?? ''))) ?>
                                        (<?= h($mov['usuario_username']) ?>)
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- AUDITORÍA RELACIONADA (solo admin) -->
            <?php if (esAdmin() && count($auditoria_relacionada) > 0): ?>
                <div class="card">
                    <h2 class="card-title">🔍 Auditoría relacionada (solo admin)</h2>
                    <p style="font-size: 12px; color: #6b7280; margin-bottom: 15px;">
                        Movimientos técnicos registrados automáticamente por el sistema.
                    </p>

                    <div style="overflow-x: auto;">
                        <table class="auditoria-tabla">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Usuario</th>
                                    <th>Acción</th>
                                    <th>Descripción</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditoria_relacionada as $aud): ?>
                                    <tr>
                                        <td><?= fecha_argentina($aud['fecha']) ?></td>
                                        <td><?= h($aud['usuario_nombre'] ?: 'Sistema') ?></td>
                                        <td><strong><?= h($aud['accion']) ?></strong></td>
                                        <td><?= h($aud['descripcion']) ?></td>
                                        <td><?= h($aud['ip']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- BOTONES -->
            <div class="card">
                <div class="acciones">
                    <a href="../cedulas/ver.php?id=<?= (int)$cedula_id ?>" class="btn btn-primary">Ver cédula completa</a>
                    <a href="../cedulas/listado.php" class="btn btn-secondary">Volver al listado</a>

                    <?php if (esAdmin()): ?>
                        <a href="../auditoria/listado.php" class="btn btn-info">Ir a auditoría general</a>
                    <?php endif; ?>
                </div>
            </div>

        </section>
    </main>
</div>

</body>

</html>