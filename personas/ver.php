<?php

session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../permisos.php";

verificarPermiso(['admin', 'supervisor']);

$dni = trim($_GET["dni"] ?? "");

if ($dni === "") {
    header("Location: listado.php");
    exit;
}

$error = "";
$persona = null;
$medidas = [];
$sanciones = [];

function h($valor) {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function fecha_argentina($fecha) {
    if (empty($fecha)) {
        return '-';
    }

    $timestamp = strtotime($fecha);

    if (!$timestamp) {
        return h($fecha);
    }

    return date('d/m/Y', $timestamp);
}

/*
|--------------------------------------------------------------------------
| CARGAR DATOS DE LA PERSONA
|--------------------------------------------------------------------------
*/

$sql_persona = "
    SELECT
        i.dni,
        i.jerarquia,
        i.apellido_nombre,
        i.dependencia,
        p.estado,
        p.antiguedad,
        p.sub_dependencia
    FROM involucrados i
    LEFT JOIN personal_policial p ON p.dni = i.dni
    WHERE i.dni = ?
    LIMIT 1
";

$stmt_persona = mysqli_prepare($conexion, $sql_persona);

if ($stmt_persona === false) {
    die("Error SQL al cargar la persona: " . mysqli_error($conexion));
}

mysqli_stmt_bind_param($stmt_persona, "s", $dni);
mysqli_stmt_execute($stmt_persona);
$res_persona = mysqli_stmt_get_result($stmt_persona);
$persona = mysqli_fetch_assoc($res_persona);
mysqli_stmt_close($stmt_persona);

if (!$persona) {
    // Si no existe en involucrados, buscar en personal_policial
    $sql_pp = "SELECT dni, jerarquia, apellido_nombre, dependencia, sub_dependencia, estado, antiguedad FROM personal_policial WHERE dni = ? LIMIT 1";
    $stmt_pp = mysqli_prepare($conexion, $sql_pp);
    mysqli_stmt_bind_param($stmt_pp, "s", $dni);
    mysqli_stmt_execute($stmt_pp);
    $res_pp = mysqli_stmt_get_result($stmt_pp);
    $persona = mysqli_fetch_assoc($res_pp);
    mysqli_stmt_close($stmt_pp);

    if (!$persona) {
        die("No se encontró una persona con el DNI especificado.");
    }
}

/*
|--------------------------------------------------------------------------
| CARGAR TODAS LAS MEDIDAS DE LA PERSONA
|--------------------------------------------------------------------------
|
| Orden ascendente: de la más antigua a la más reciente
|
*/

$sql_medidas = "
    SELECT
        m.id AS medida_id,
        m.numero_resolucion,
        m.anio_resolucion,
        m.observaciones AS observaciones_medida,
        m.fecha_creacion AS fecha_medida,

        tm.id AS tipo_medida_id,
        tm.nombre AS tipo_medida_nombre,
        tm.categoria AS tipo_medida_categoria,

        c.id AS cedula_id,
        c.fecha_ingreso,
        c.fecha_cumplimentacion,
        c.estado AS cedula_estado,
        c.titulo_medida AS cedula_titulo_medida,

        ca.numero_caso

    FROM involucrados i

    INNER JOIN medidas m ON m.involucrado_id = i.id
    INNER JOIN cedulas c ON c.id = m.cedula_id
    INNER JOIN casos ca ON ca.id = c.caso_id
    LEFT JOIN tipos_medida tm ON tm.id = m.tipo_medida_id

    WHERE i.dni = ?

    ORDER BY m.fecha_creacion ASC, m.id ASC
";

$stmt_medidas = mysqli_prepare($conexion, $sql_medidas);
mysqli_stmt_bind_param($stmt_medidas, "s", $dni);
mysqli_stmt_execute($stmt_medidas);
$res_medidas = mysqli_stmt_get_result($stmt_medidas);

while ($fila = mysqli_fetch_assoc($res_medidas)) {
    $medidas[] = $fila;
}

mysqli_stmt_close($stmt_medidas);

/*
|--------------------------------------------------------------------------
| CARGAR TODAS LAS SANCIONES DE LA PERSONA
|--------------------------------------------------------------------------
*/

$sql_sanciones = "
    SELECT
        s.id,
        s.cedula_id,
        s.tipo_sancion,
        s.dias_suspension,
        s.fecha_inicio,
        s.fecha_fin,
        s.observaciones,
        s.estado_cumplimiento,
        s.fecha_creacion,

        c.id AS cedula_original_id,
        ca.numero_caso,

        (
            SELECT COUNT(*)
            FROM sanciones_accesorias sa
            WHERE sa.sancion_id = s.id
        ) AS cantidad_accesorias

    FROM sanciones s
    LEFT JOIN cedulas c ON c.id = s.cedula_id
    LEFT JOIN casos ca ON ca.id = c.caso_id
    WHERE s.dni = ?
    ORDER BY s.fecha_creacion DESC, s.id DESC
";

$stmt_sanciones = mysqli_prepare($conexion, $sql_sanciones);
mysqli_stmt_bind_param($stmt_sanciones, "s", $dni);
mysqli_stmt_execute($stmt_sanciones);
$res_sanciones = mysqli_stmt_get_result($stmt_sanciones);

while ($fila = mysqli_fetch_assoc($res_sanciones)) {
    $sanciones[] = $fila;
}

mysqli_stmt_close($stmt_sanciones);

/*
|--------------------------------------------------------------------------
| CALCULAR ESTADÍSTICAS
|--------------------------------------------------------------------------
*/

$tipos_cierre = [15, 20, 21, 22];

$total_medidas = count($medidas);
$medidas_activas = 0;
$medidas_finalizadas = 0;
$ultima_medida = null;
$primera_medida = null;

foreach ($medidas as $med) {
    $tipo_id = (int)($med['tipo_medida_id'] ?? 0);

    if (!in_array($tipo_id, $tipos_cierre)) {
        $medidas_activas++;
    } else {
        $medidas_finalizadas++;
    }
}

if ($total_medidas > 0) {
    $primera_medida = $medidas[0];
    $ultima_medida = $medidas[$total_medidas - 1];
}

$total_sanciones = count($sanciones);

/*
|--------------------------------------------------------------------------
| DETERMINAR ESTADO ACTUAL
|--------------------------------------------------------------------------
*/

$estado_actual = ['clase' => 'estado-sin-datos', 'texto' => 'SIN DATOS'];

if ($ultima_medida) {
    $ultimo_tipo = (int)($ultima_medida['tipo_medida_id'] ?? 0);

    if (!in_array($ultimo_tipo, $tipos_cierre)) {
        $estado_actual = ['clase' => 'estado-activa', 'texto' => 'ACTIVA'];
    } else {
        switch ($ultimo_tipo) {
            case 15:
                $estado_actual = ['clase' => 'estado-servicio', 'texto' => 'FINALIZADA - Servicio Efectivo'];
                break;
            case 20:
                $estado_actual = ['clase' => 'estado-cesantia', 'texto' => 'FINALIZADA - Cesantía'];
                break;
            case 21:
                $estado_actual = ['clase' => 'estado-destitucion', 'texto' => 'FINALIZADA - Destitución'];
                break;
            case 22:
                $estado_actual = ['clase' => 'estado-sobreseimiento', 'texto' => 'FINALIZADA - Sobreseimiento'];
                break;
            default:
                $estado_actual = ['clase' => 'estado-finalizada', 'texto' => 'FINALIZADA'];
        }
    }
}

/*
|--------------------------------------------------------------------------
| FUNCIONES AUXILIARES PARA SANCIONES
|--------------------------------------------------------------------------
*/

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

    <title>Detalle de persona - Sistema Medidas</title>

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
        .content { padding: 30px; max-width: 1400px; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-title { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .datos-persona { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
        .dato { padding: 12px; background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; }
        .dato-label { display: block; font-size: 11px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin-bottom: 5px; }
        .dato-valor { font-size: 15px; color: #111827; font-weight: 500; }
        .dato-valor.dni { font-family: monospace; font-weight: bold; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; border-left: 5px solid #1d4ed8; }
        .stat.activas { border-left-color: #10b981; }
        .stat.finalizadas { border-left-color: #6b7280; }
        .stat.sanciones { border-left-color: #dc2626; }
        .stat-label { font-size: 12px; color: #6b7280; margin-bottom: 8px; text-transform: uppercase; }
        .stat-number { font-size: 28px; font-weight: bold; color: #1d4ed8; }
        .stat.activas .stat-number { color: #10b981; }
        .stat.finalizadas .stat-number { color: #6b7280; }
        .stat.sanciones .stat-number { color: #dc2626; }
        .estado-badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .estado-activa { background: #dcfce7; color: #166534; }
        .estado-servicio { background: #dbeafe; color: #1e40af; }
        .estado-cesantia { background: #fed7aa; color: #9a3412; }
        .estado-destitucion { background: #fee2e2; color: #991b1b; }
        .estado-sobreseimiento { background: #e5e7eb; color: #374151; }
        .estado-finalizada { background: #f3f4f6; color: #374151; }
        .estado-sin-datos { background: #f3f4f6; color: #9ca3af; }
        .timeline { position: relative; padding-left: 30px; margin-top: 20px; }
        .timeline::before { content: ''; position: absolute; left: 8px; top: 0; bottom: 0; width: 2px; background: #d1d5db; }
        .timeline-item { position: relative; margin-bottom: 25px; }
        .timeline-item:last-child { margin-bottom: 0; }
        .timeline-dot { position: absolute; left: -28px; top: 8px; width: 18px; height: 18px; border-radius: 50%; background: #2563eb; border: 3px solid #dbeafe; }
        .timeline-item.activa .timeline-dot { background: #10b981; border-color: #d1fae5; }
        .timeline-item.cerrada .timeline-dot { background: #6b7280; border-color: #e5e7eb; }
        .timeline-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; }
        .timeline-item.activa .timeline-card { background: #f0fdf4; border-color: #bbf7d0; }
        .timeline-item.cerrada .timeline-card { background: #f9fafb; border-color: #e5e7eb; }
        .timeline-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; gap: 15px; flex-wrap: wrap; }
        .timeline-fecha { font-size: 12px; color: #6b7280; }
        .timeline-medida { font-size: 16px; font-weight: bold; margin-bottom: 6px; color: #1f2937; }
        .timeline-item.activa .timeline-medida { color: #166534; }
        .timeline-detalle { font-size: 13px; color: #4b5563; line-height: 1.6; }
        .timeline-detalle strong { color: #374151; }
        .timeline-acciones { margin-top: 12px; }
        .badge-cat { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .cat-inicial { background: #dbeafe; color: #1e40af; }
        .cat-prorroga { background: #fef3c7; color: #92400e; }
        .cat-cambio { background: #e0e7ff; color: #4338ca; }
        .cat-levantamiento { background: #d1fae5; color: #065f46; }
        .cat-excepcional { background: #fce7f3; color: #9d174d; }
        .badge-estado-medida { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .badge-medida-activa { background: #dcfce7; color: #166534; }
        .badge-medida-cerrada { background: #e5e7eb; color: #374151; }
        .btn { border: none; border-radius: 6px; padding: 10px 15px; cursor: pointer; font-size: 13px; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1d4ed8; color: white; }
        .btn-primary:hover { background: #1e40af; }
        .btn-secondary { background: #374151; color: white; }
        .btn-secondary:hover { background: #1f2937; }
        .btn-info { background: #0891b2; color: white; }
        .btn-info:hover { background: #0e7490; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 5px; }
        .acciones { display: flex; gap: 10px; flex-wrap: wrap; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .sancion-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; margin-bottom: 15px; background: #fafafa; }
        .sancion-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; gap: 15px; flex-wrap: wrap; }
        .tipo-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .tipo-apercibimiento { background: #fef3c7; color: #92400e; }
        .tipo-suspension { background: #dbeafe; color: #1e40af; }
        .tipo-cesantia { background: #e9d5ff; color: #6b21a8; }
        .tipo-destitucion { background: #fee2e2; color: #991b1b; }
        .tipo-otro { background: #e5e7eb; color: #374151; }
        .estado-pendiente { background: #fef3c7; color: #92400e; }
        .estado-en-curso { background: #dbeafe; color: #1e40af; }
        .estado-cumplida { background: #dcfce7; color: #166534; }
        .estado-incumplida { background: #fee2e2; color: #991b1b; }
        .estado-otro { background: #e5e7eb; color: #374151; }
        .sancion-detalle { font-size: 13px; color: #4b5563; line-height: 1.6; }
        .sancion-detalle strong { color: #374151; }
        .accesorias-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; background: #e0e7ff; color: #4338ca; }
        .sin-datos { text-align: center; padding: 30px 20px; color: #6b7280; font-style: italic; }
        @media (max-width: 900px) {
            .sidebar { width: 210px; }
            .main { margin-left: 210px; width: calc(100% - 210px); }
            .datos-persona, .stats { grid-template-columns: 1fr 1fr; }
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
                <a href="listado.php" class="active">👥 Personas con medidas</a>
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
            <h1>👤 Detalle de persona</h1>
            <div class="user-info">
                <strong>
                    <?= h(trim(($_SESSION['jerarquia'] ?? '') . ' ' . ($_SESSION['apellido'] ?? '') . ', ' . ($_SESSION['nombre'] ?? ''))) ?>
                </strong>
                <span>Usuario: <?= h($_SESSION['usuario'] ?? '') ?></span>
            </div>
        </header>

        <section class="content">

            <!-- DATOS PERSONALES -->
            <div class="card">
                <h2 class="card-title">📋 Datos de la persona</h2>

                <div class="datos-persona">
                    <div class="dato">
                        <span class="dato-label">Jerarquía</span>
                        <span class="dato-valor"><?= h($persona['jerarquia'] ?? '-') ?></span>
                    </div>

                    <div class="dato">
                        <span class="dato-label">Apellido y nombre</span>
                        <span class="dato-valor"><?= h($persona['apellido_nombre'] ?? '-') ?></span>
                    </div>

                    <div class="dato">
                        <span class="dato-label">DNI</span>
                        <span class="dato-valor dni"><?= h($persona['dni']) ?></span>
                    </div>

                    <div class="dato">
                        <span class="dato-label">Estado</span>
                        <span class="dato-valor"><?= h($persona['estado'] ?? '-') ?></span>
                    </div>

                    <div class="dato">
                        <span class="dato-label">Antigüedad</span>
                        <span class="dato-valor">
                            <?= !empty($persona['antiguedad']) ? h($persona['antiguedad']) . ' años' : '-' ?>
                        </span>
                    </div>

                    <div class="dato">
                        <span class="dato-label">Dependencia</span>
                        <span class="dato-valor"><?= h($persona['dependencia'] ?? '-') ?></span>
                    </div>

                    <?php if (!empty($persona['sub_dependencia'])): ?>
                        <div class="dato" style="grid-column: 1 / -1;">
                            <span class="dato-label">Sub-dependencia</span>
                            <span class="dato-valor"><?= h($persona['sub_dependencia']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ESTADO ACTUAL -->
            <div class="card">
                <h2 class="card-title">🎯 Estado actual</h2>

                <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                    <span class="estado-badge <?= $estado_actual['clase'] ?>" style="font-size: 14px; padding: 10px 18px;">
                        <?= h($estado_actual['texto']) ?>
                    </span>

                    <?php if ($ultima_medida): ?>
                        <div style="color: #6b7280; font-size: 13px;">
                            Última medida: <strong><?= h($ultima_medida['tipo_medida_nombre'] ?? '-') ?></strong>
                            · <?= fecha_argentina($ultima_medida['fecha_medida']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ESTADÍSTICAS -->
            <div class="stats">
                <div class="stat">
                    <div class="stat-label">Total de medidas</div>
                    <div class="stat-number"><?= $total_medidas ?></div>
                </div>

                <div class="stat activas">
                    <div class="stat-label">Medidas activas</div>
                    <div class="stat-number"><?= $medidas_activas ?></div>
                </div>

                <div class="stat finalizadas">
                    <div class="stat-label">Medidas finalizadas</div>
                    <div class="stat-number"><?= $medidas_finalizadas ?></div>
                </div>

                <div class="stat sanciones">
                    <div class="stat-label">Sanciones</div>
                    <div class="stat-number"><?= $total_sanciones ?></div>
                </div>
            </div>

            <!-- HISTORIAL DE MEDIDAS -->
            <div class="card">
                <h2 class="card-title">📅 Historial de medidas (orden cronológico)</h2>

                <?php if (count($medidas) === 0): ?>
                    <p style="color: #6b7280; font-style: italic;">
                        Esta persona no tiene medidas registradas.
                    </p>
                <?php else: ?>

                    <div class="timeline">

                        <?php foreach ($medidas as $indice => $med): ?>

                            <?php
                            $tipo_id = (int)($med['tipo_medida_id'] ?? 0);
                            $es_cierre = in_array($tipo_id, $tipos_cierre);

                            $clase_item = 'activa';
                            $badge_estado_medida = 'badge-medida-activa';
                            $texto_estado_medida = 'ACTIVA';

                            if ($es_cierre) {
                                $clase_item = 'cerrada';
                                $badge_estado_medida = 'badge-medida-cerrada';

                                switch ($tipo_id) {
                                    case 15:
                                        $texto_estado_medida = 'CERRADA - Servicio Efectivo';
                                        break;
                                    case 20:
                                        $texto_estado_medida = 'CERRADA - Cesantía';
                                        break;
                                    case 21:
                                        $texto_estado_medida = 'CERRADA - Destitución';
                                        break;
                                    case 22:
                                        $texto_estado_medida = 'CERRADA - Sobreseimiento';
                                        break;
                                    default:
                                        $texto_estado_medida = 'CERRADA';
                                }
                            }

                            $categoria = $med['tipo_medida_categoria'] ?? '';
                            $clase_cat = 'cat-inicial';
                            if ($categoria === 'PRORROGA') $clase_cat = 'cat-prorroga';
                            elseif ($categoria === 'CAMBIO') $clase_cat = 'cat-cambio';
                            elseif ($categoria === 'LEVANTAMIENTO') $clase_cat = 'cat-levantamiento';
                            elseif ($categoria === 'EXCEPCIONAL') $clase_cat = 'cat-excepcional';
                            ?>

                            <div class="timeline-item <?= $clase_item ?>">

                                <div class="timeline-dot"></div>

                                <div class="timeline-card">

                                    <div class="timeline-header">
                                        <div>
                                            <div class="timeline-fecha">
                                                Medida Nº <?= $indice + 1 ?>
                                                · <?= fecha_argentina($med['fecha_medida']) ?>
                                            </div>
                                            <div class="timeline-medida">
                                                <?= h($med['tipo_medida_nombre'] ?? 'Sin tipo') ?>
                                            </div>
                                        </div>

                                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                            <span class="badge-cat <?= $clase_cat ?>">
                                                <?= h($categoria ?: 'SIN CAT.') ?>
                                            </span>
                                            <span class="badge-estado-medida <?= $badge_estado_medida ?>">
                                                <?= h($texto_estado_medida) ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="timeline-detalle">
                                        <strong>Caso:</strong> Nº <?= h($med['numero_caso']) ?>
                                        &nbsp;·&nbsp;
                                        <strong>Cédula:</strong> #<?= (int)$med['cedula_id'] ?>
                                        &nbsp;·&nbsp;
                                        <strong>Estado cédula:</strong> <?= h($med['cedula_estado']) ?>
                                        <br>

                                        <strong>Resolución:</strong> <?= h($med['numero_resolucion'] ?: '-') ?>
                                        <?php if ($med['anio_resolucion']): ?>
                                            / <?= h($med['anio_resolucion']) ?>
                                        <?php endif; ?>

                                        <?php if (!empty($med['observaciones_medida'])): ?>
                                            <br>
                                            <strong>Observaciones:</strong> <?= nl2br(h($med['observaciones_medida'])) ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="timeline-acciones">
                                        <a href="../cedulas/ver.php?id=<?= (int)$med['cedula_id'] ?>" class="btn btn-info btn-sm">
                                            Ver cédula
                                        </a>
                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

            <!-- SANCIONES -->
            <div class="card">
                <h2 class="card-title">⚖️ Sanciones (<?= $total_sanciones ?>)</h2>

                <?php if ($total_sanciones === 0): ?>
                    <div class="sin-datos">
                        Esta persona no tiene sanciones registradas.
                    </div>
                <?php else: ?>

                    <?php foreach ($sanciones as $sancion): ?>
                        <div class="sancion-card">

                            <div class="sancion-header">
                                <div>
                                    <div style="font-size: 12px; color: #6b7280; margin-bottom: 5px;">
                                        Sanción #<?= (int)$sancion['id'] ?>
                                        · Registrada el <?= fecha_argentina($sancion['fecha_creacion']) ?>
                                    </div>

                                    <span class="tipo-badge <?= clase_tipo_sancion($sancion['tipo_sancion']) ?>">
                                        <?= h($sancion['tipo_sancion']) ?>
                                    </span>
                                </div>

                                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                    <?php if ((int)$sancion['cantidad_accesorias'] > 0): ?>
                                        <span class="accesorias-badge">
                                            <?= (int)$sancion['cantidad_accesorias'] ?> accesoria(s)
                                        </span>
                                    <?php endif; ?>

                                    <span class="estado-badge <?= clase_estado_cumplimiento($sancion['estado_cumplimiento']) ?>">
                                        <?= h($sancion['estado_cumplimiento']) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="sancion-detalle">
                                <?php if ($sancion['tipo_sancion'] === 'SUSPENSION' && $sancion['dias_suspension']): ?>
                                    <strong>Días de suspensión:</strong> <?= (int)$sancion['dias_suspension'] ?> días
                                    <br>
                                <?php endif; ?>

                                <strong>Período:</strong>
                                <?= fecha_argentina($sancion['fecha_inicio']) ?>
                                al <?= fecha_argentina($sancion['fecha_fin']) ?>
                                <br>

                                <?php if (!empty($sancion['numero_caso'])): ?>
                                    <strong>Caso:</strong> Nº <?= h($sancion['numero_caso']) ?>
                                    &nbsp;·&nbsp;
                                    <strong>Cédula:</strong> #<?= (int)$sancion['cedula_original_id'] ?>
                                    <br>
                                <?php endif; ?>

                                <?php if (!empty($sancion['observaciones'])): ?>
                                    <strong>Observaciones:</strong> <?= nl2br(h($sancion['observaciones'])) ?>
                                <?php endif; ?>
                            </div>

                            <div style="margin-top: 12px;">
                                <a href="../sanciones/ver.php?id=<?= (int)$sancion['id'] ?>" class="btn btn-primary btn-sm">
                                    Ver sanción completa
                                </a>
                            </div>

                        </div>
                    <?php endforeach; ?>

                    <div style="margin-top: 15px;">
                        <a href="../sanciones/nueva.php?dni=<?= urlencode($dni) ?>" class="btn btn-danger btn-sm">
                            + Nueva sanción para esta persona
                        </a>
                    </div>

                <?php endif; ?>

            </div>

            <!-- BOTONES -->
            <div class="card">
                <div class="acciones">
                    <a href="listado.php" class="btn btn-secondary">← Volver al listado</a>
                </div>
            </div>

        </section>
    </main>
</div>

</body>

</html>