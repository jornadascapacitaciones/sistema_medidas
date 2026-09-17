<?php

session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../auditoria.php";
require_once __DIR__ . "/../permisos.php";

verificarPermiso(['admin', 'supervisor']);

$sancion_id = (int)($_GET["id"] ?? 0);

if ($sancion_id <= 0) {
    die("ID de sanción no válido.");
}

$error = "";
$sancion = null;
$accesorias = [];
$historial = [];

/*
|--------------------------------------------------------------------------
| CARGAR SANCIÓN
|--------------------------------------------------------------------------
*/

$sql_sancion = "
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

        c.id AS cedula_original_id,
        c.estado AS cedula_estado,
        c.fecha_ingreso AS cedula_fecha_ingreso,
        c.titulo_medida AS cedula_titulo_medida,
        c.fecha_cumplimentacion AS cedula_fecha_cumplimentacion,

        ca.numero_caso,

        p.jerarquia AS persona_jerarquia,
        p.dependencia AS persona_dependencia,
        p.sub_dependencia AS persona_sub_dependencia,
        p.estado AS persona_estado,
        p.antiguedad AS persona_antiguedad

    FROM sanciones s

    LEFT JOIN cedulas c ON c.id = s.cedula_id
    LEFT JOIN casos ca ON ca.id = c.caso_id
    LEFT JOIN personal_policial p ON p.dni = s.dni

    WHERE s.id = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conexion, $sql_sancion);

if ($stmt === false) {
    die("Error SQL al cargar la sanción: " . mysqli_error($conexion));
}

mysqli_stmt_bind_param($stmt, "i", $sancion_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$sancion = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$sancion) {
    die("La sanción no existe.");
}

/*
|--------------------------------------------------------------------------
| CARGAR ACCESORIAS
|--------------------------------------------------------------------------
*/

$sql_acc = "
    SELECT
        id,
        tipo,
        detalle,
        fecha_inicio,
        fecha_fin,
        estado_cumplimiento,
        fecha_creacion
    FROM sanciones_accesorias
    WHERE sancion_id = ?
    ORDER BY id ASC
";

$stmt_acc = mysqli_prepare($conexion, $sql_acc);
mysqli_stmt_bind_param($stmt_acc, "i", $sancion_id);
mysqli_stmt_execute($stmt_acc);
$res_acc = mysqli_stmt_get_result($stmt_acc);

while ($fila = mysqli_fetch_assoc($res_acc)) {
    $accesorias[] = $fila;
}

mysqli_stmt_close($stmt_acc);

/*
|--------------------------------------------------------------------------
| CARGAR HISTORIAL (desde auditoría)
|--------------------------------------------------------------------------
*/

$sql_hist = "
    SELECT
        id,
        usuario_nombre,
        accion,
        descripcion,
        fecha,
        ip
    FROM auditoria
    WHERE tabla_afectada = 'sanciones'
    AND registro_id = ?
    ORDER BY fecha ASC, id ASC
    LIMIT 50
";

$stmt_hist = mysqli_prepare($conexion, $sql_hist);
mysqli_stmt_bind_param($stmt_hist, "i", $sancion_id);
mysqli_stmt_execute($stmt_hist);
$res_hist = mysqli_stmt_get_result($stmt_hist);

while ($fila = mysqli_fetch_assoc($res_hist)) {
    $historial[] = $fila;
}

mysqli_stmt_close($stmt_hist);

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

function fecha_hora_argentina($fecha) {
    if (empty($fecha)) return '-';
    $ts = strtotime($fecha);
    if (!$ts) return h($fecha);
    return date('d/m/Y H:i:s', $ts);
}

function nombre_tipo_accesoria($tipo) {
    $nombres = [
        'TRATAMIENTO_TERAPEUTICO' => 'Tratamiento terapéutico',
        'DEBERES_ESPECIALES' => 'Deberes especiales de conducta',
        'CURSOS_EDUCATIVOS' => 'Cursos educativos',
        'REPARACION_DANO' => 'Reparación del daño',
        'TAREAS_COMUNITARIAS' => 'Tareas comunitarias',
    ];
    return $nombres[$tipo] ?? $tipo;
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

function clase_estado($estado) {
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

    <title>Detalle de sanción - Sistema Medidas</title>

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
        .content { padding: 30px; max-width: 1300px; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-title { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
        .dato { padding: 12px; background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; }
        .dato-label { display: block; font-size: 11px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin-bottom: 5px; }
        .dato-valor { font-size: 14px; color: #111827; font-weight: 500; }
        .dato-valor.dni { font-family: monospace; font-weight: bold; }
        .full { grid-column: 1 / -1; }
        .tipo-badge { display: inline-block; padding: 8px 15px; border-radius: 20px; font-size: 13px; font-weight: bold; text-transform: uppercase; }
        .tipo-apercibimiento { background: #fef3c7; color: #92400e; }
        .tipo-suspension { background: #dbeafe; color: #1e40af; }
        .tipo-cesantia { background: #e9d5ff; color: #6b21a8; }
        .tipo-destitucion { background: #fee2e2; color: #991b1b; }
        .tipo-otro { background: #e5e7eb; color: #374151; }
        .estado-badge { display: inline-block; padding: 8px 15px; border-radius: 20px; font-size: 13px; font-weight: bold; text-transform: uppercase; }
        .estado-pendiente { background: #fef3c7; color: #92400e; }
        .estado-en-curso { background: #dbeafe; color: #1e40af; }
        .estado-cumplida { background: #dcfce7; color: #166534; }
        .estado-incumplida { background: #fee2e2; color: #991b1b; }
        .estado-otro { background: #e5e7eb; color: #374151; }
        .accion-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .accion-crear { background: #dcfce7; color: #166534; }
        .accion-editar { background: #fef3c7; color: #92400e; }
        .accion-otro { background: #e5e7eb; color: #374151; }
        .accesoria-item { border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; background: #fafafa; margin-bottom: 10px; }
        .accesoria-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; gap: 10px; }
        .accesoria-titulo { font-weight: bold; font-size: 15px; }
        .accesoria-detalle { font-size: 13px; color: #4b5563; line-height: 1.6; }
        .accesoria-detalle strong { color: #374151; }
        .timeline { position: relative; padding-left: 30px; margin-top: 10px; }
        .timeline::before { content: ''; position: absolute; left: 8px; top: 0; bottom: 0; width: 2px; background: #d1d5db; }
        .timeline-item { position: relative; margin-bottom: 20px; }
        .timeline-item:last-child { margin-bottom: 0; }
        .timeline-dot { position: absolute; left: -28px; top: 4px; width: 14px; height: 14px; border-radius: 50%; background: #2563eb; border: 3px solid #dbeafe; }
        .timeline-fecha { font-size: 12px; color: #6b7280; margin-bottom: 4px; }
        .timeline-accion { font-weight: bold; font-size: 14px; margin-bottom: 4px; color: #1e40af; }
        .timeline-descripcion { font-size: 13px; color: #4b5563; line-height: 1.5; background: #f9fafb; border-radius: 6px; padding: 10px; border: 1px solid #e5e7eb; }
        .timeline-meta { font-size: 11px; color: #9ca3af; margin-top: 5px; }
        .btn { border: none; border-radius: 6px; padding: 10px 15px; cursor: pointer; font-size: 13px; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1d4ed8; color: white; }
        .btn-primary:hover { background: #1e40af; }
        .btn-secondary { background: #374151; color: white; }
        .btn-secondary:hover { background: #1f2937; }
        .btn-success { background: #15803d; color: white; }
        .btn-success:hover { background: #166534; }
        .btn-info { background: #0891b2; color: white; }
        .btn-info:hover { background: #0e7490; }
        .acciones { display: flex; gap: 10px; flex-wrap: wrap; }
        .sin-datos { color: #6b7280; font-style: italic; text-align: center; padding: 20px; }
        @media (max-width: 900px) {
            .sidebar { width: 210px; }
            .main { margin-left: 210px; width: calc(100% - 210px); }
            .grid, .grid-3 { grid-template-columns: 1fr; }
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
            <h1>⚖️ Detalle de sanción #<?= (int)$sancion['id'] ?></h1>
            <div class="user-info">
                <strong>
                    <?= h(trim(($_SESSION['jerarquia'] ?? '') . ' ' . ($_SESSION['apellido'] ?? '') . ', ' . ($_SESSION['nombre'] ?? ''))) ?>
                </strong>
                <span>Usuario: <?= h($_SESSION['usuario'] ?? '') ?></span>
            </div>
        </header>

        <section class="content">

            <!-- DATOS DE LA PERSONA -->
            <div class="card">
                <h2 class="card-title">👤 Datos de la persona sancionada</h2>

                <div class="grid">
                    <div class="dato">
                        <span class="dato-label">Apellido y nombre</span>
                        <span class="dato-valor"><?= h($sancion['apellido_nombre']) ?></span>
                    </div>

                    <div class="dato">
                        <span class="dato-label">DNI</span>
                        <span class="dato-valor dni"><?= h($sancion['dni']) ?></span>
                    </div>

                    <?php if (!empty($sancion['persona_jerarquia'])): ?>
                        <div class="dato">
                            <span class="dato-label">Jerarquía</span>
                            <span class="dato-valor"><?= h($sancion['persona_jerarquia']) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($sancion['persona_estado'])): ?>
                        <div class="dato">
                            <span class="dato-label">Estado</span>
                            <span class="dato-valor"><?= h($sancion['persona_estado']) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($sancion['persona_antiguedad'])): ?>
                        <div class="dato">
                            <span class="dato-label">Antigüedad</span>
                            <span class="dato-valor"><?= h($sancion['persona_antiguedad']) ?> años</span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($sancion['persona_dependencia'])): ?>
                        <div class="dato">
                            <span class="dato-label">Dependencia</span>
                            <span class="dato-valor"><?= h($sancion['persona_dependencia']) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($sancion['persona_sub_dependencia'])): ?>
                        <div class="dato full">
                            <span class="dato-label">Sub-dependencia</span>
                            <span class="dato-valor"><?= h($sancion['persona_sub_dependencia']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- DATOS DE LA SANCIÓN -->
            <div class="card">
                <h2 class="card-title">⚖️ Datos de la sanción</h2>

                <div class="grid">
                    <div class="dato">
                        <span class="dato-label">Tipo de sanción</span>
                        <span class="dato-valor">
                            <span class="tipo-badge <?= clase_tipo_sancion($sancion['tipo_sancion']) ?>">
                                <?= h($sancion['tipo_sancion']) ?>
                            </span>
                        </span>
                    </div>

                    <div class="dato">
                        <span class="dato-label">Estado de cumplimiento</span>
                        <span class="dato-valor">
                            <span class="estado-badge <?= clase_estado($sancion['estado_cumplimiento']) ?>">
                                <?= h($sancion['estado_cumplimiento']) ?>
                            </span>
                        </span>
                    </div>

                    <?php if ($sancion['tipo_sancion'] === 'SUSPENSION' && $sancion['dias_suspension']): ?>
                        <div class="dato">
                            <span class="dato-label">Días de suspensión</span>
                            <span class="dato-valor"><?= (int)$sancion['dias_suspension'] ?> días</span>
                        </div>
                    <?php endif; ?>

                    <div class="dato">
                        <span class="dato-label">Fecha de inicio</span>
                        <span class="dato-valor"><?= fecha_argentina($sancion['fecha_inicio']) ?></span>
                    </div>

                    <div class="dato">
                        <span class="dato-label">Fecha de fin</span>
                        <span class="dato-valor"><?= fecha_argentina($sancion['fecha_fin']) ?></span>
                    </div>

                    <div class="dato">
                        <span class="dato-label">Fecha de registro</span>
                        <span class="dato-valor"><?= fecha_hora_argentina($sancion['fecha_creacion']) ?></span>
                    </div>

                    <?php if (!empty($sancion['observaciones'])): ?>
                        <div class="dato full">
                            <span class="dato-label">Observaciones</span>
                            <span class="dato-valor"><?= nl2br(h($sancion['observaciones'])) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CÉDULA ASOCIADA -->
            <?php if (!empty($sancion['cedula_original_id'])): ?>
                <div class="card">
                    <h2 class="card-title">📄 Cédula asociada</h2>

                    <div class="grid">
                        <div class="dato">
                            <span class="dato-label">ID Cédula</span>
                            <span class="dato-valor">#<?= (int)$sancion['cedula_original_id'] ?></span>
                        </div>

                        <div class="dato">
                            <span class="dato-label">Número de caso</span>
                            <span class="dato-valor"><?= h($sancion['numero_caso'] ?? '-') ?></span>
                        </div>

                        <div class="dato">
                            <span class="dato-label">Estado de la cédula</span>
                            <span class="dato-valor"><?= h($sancion['cedula_estado'] ?? '-') ?></span>
                        </div>

                        <div class="dato">
                            <span class="dato-label">Fecha de ingreso</span>
                            <span class="dato-valor"><?= fecha_hora_argentina($sancion['cedula_fecha_ingreso']) ?></span>
                        </div>

                        <?php if (!empty($sancion['cedula_titulo_medida'])): ?>
                            <div class="dato full">
                                <span class="dato-label">Título de la medida</span>
                                <span class="dato-valor"><?= h($sancion['cedula_titulo_medida']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="acciones" style="margin-top: 15px;">
                        <a href="../cedulas/ver.php?id=<?= (int)$sancion['cedula_original_id'] ?>" class="btn btn-info">
                            Ver cédula completa
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- SANCIONES ACCESORIAS -->
            <div class="card">
                <h2 class="card-title">📋 Sanciones accesorias</h2>

                <?php if (count($accesorias) === 0): ?>
                    <div class="sin-datos">
                        Esta sanción no tiene accesorias asociadas.
                    </div>
                <?php else: ?>
                    <?php foreach ($accesorias as $acc): ?>
                        <div class="accesoria-item">
                            <div class="accesoria-header">
                                <div class="accesoria-titulo">
                                    <?= h(nombre_tipo_accesoria($acc['tipo'])) ?>
                                </div>
                                <span class="estado-badge <?= clase_estado($acc['estado_cumplimiento']) ?>">
                                    <?= h($acc['estado_cumplimiento']) ?>
                                </span>
                            </div>

                            <div class="accesoria-detalle">
                                <?php if (!empty($acc['detalle'])): ?>
                                    <strong>Detalle:</strong> <?= nl2br(h($acc['detalle'])) ?>
                                    <br>
                                <?php endif; ?>

                                <strong>Inicio:</strong> <?= fecha_argentina($acc['fecha_inicio']) ?>
                                &nbsp;·&nbsp;
                                <strong>Fin:</strong> <?= fecha_argentina($acc['fecha_fin']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- HISTORIAL DE MOVIMIENTOS -->
            <div class="card">
                <h2 class="card-title">📅 Historial de movimientos</h2>

                <?php if (count($historial) === 0): ?>
                    <div class="sin-datos">
                        No hay movimientos registrados para esta sanción.
                    </div>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($historial as $mov): ?>
                            <?php
                            $clase = 'accion-otro';
                            if ($mov['accion'] === 'CREAR') $clase = 'accion-crear';
                            elseif ($mov['accion'] === 'EDITAR') $clase = 'accion-editar';
                            ?>
                            <div class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div class="timeline-fecha"><?= fecha_hora_argentina($mov['fecha']) ?></div>
                                <div class="timeline-accion">
                                    <span class="accion-badge <?= $clase ?>">
                                        <?= h($mov['accion']) ?>
                                    </span>
                                </div>
                                <div class="timeline-descripcion">
                                    <?= nl2br(h($mov['descripcion'])) ?>
                                </div>
                                <div class="timeline-meta">
                                    Usuario: <?= h($mov['usuario_nombre'] ?: 'Sistema') ?>
                                    · IP: <?= h($mov['ip'] ?? '-') ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- BOTONES -->
            <div class="card">
                <div class="acciones">
                    <a href="listado.php" class="btn btn-secondary">← Volver al listado</a>

                    <?php if (tienePermiso(['admin', 'supervisor'])): ?>
                        <a href="editar.php?id=<?= (int)$sancion['id'] ?>" class="btn btn-primary">✏️ Editar sanción</a>
                    <?php endif; ?>

                    <?php if (!empty($sancion['dni'])): ?>
                        <a href="../personas/ver.php?dni=<?= urlencode($sancion['dni']) ?>" class="btn btn-info">
                            👤 Ver persona
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        </section>
    </main>
</div>

</body>

</html>