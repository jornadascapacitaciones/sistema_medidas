<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../permisos.php';

verificarPermiso(['admin', 'supervisor', 'operador', 'consulta']);

$cedula_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($cedula_id <= 0) {
    die('ID de cédula no válido.');
}

$error = '';
$cedula = null;
$caso = null;
$involucrados = [];
$medidas = [];
$historial = [];
$cadena_encargados = [];

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
| DATOS DE LA CÉDULA
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        c.id,
        c.caso_id,
        c.fecha_ingreso,
        c.asunto,
        c.archivo_nombre,
        c.estado,
        c.usuario_recepcion_id,
        c.fecha_asignacion,
        c.fecha_cumplimentacion,
        c.observaciones,
        c.fecha_creacion,
        c.numero_resolucion,
        c.numero_hecho,
        c.fecha_hecho,
        c.dni_involucrado,
        c.caracterizacion,
        c.titulo_medida,
        c.color_semaforo,
        c.descripcion_hecho
    FROM cedulas c
    WHERE c.id = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conexion, $sql);

if ($stmt === false) {
    $error = 'Error al preparar la consulta de la cédula: ' . mysqli_error($conexion);
} else {
    mysqli_stmt_bind_param($stmt, 'i', $cedula_id);

    if (!mysqli_stmt_execute($stmt)) {
        $error = 'Error al consultar la cédula: ' . mysqli_stmt_error($stmt);
    } else {
        $resultado = mysqli_stmt_get_result($stmt);
        $cedula = mysqli_fetch_assoc($resultado);

        if (!$cedula) {
            $error = 'La cédula no existe.';
        }
    }

    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| DATOS DEL CASO
|--------------------------------------------------------------------------
*/

if ($error === '' && $cedula) {
    $sql = "SELECT * FROM casos WHERE id = ? LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $cedula['caso_id']);

        if (mysqli_stmt_execute($stmt)) {
            $resultado = mysqli_stmt_get_result($stmt);
            $caso = mysqli_fetch_assoc($resultado);
        }

        mysqli_stmt_close($stmt);
    }
}

/*
|--------------------------------------------------------------------------
| INVOLUCRADOS
|--------------------------------------------------------------------------
*/

if ($error === '' && $cedula) {
    $sql = "
        SELECT i.*
        FROM involucrados i
        WHERE i.cedula_id = ?
        ORDER BY i.id ASC
    ";

    $stmt = mysqli_prepare($conexion, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $cedula_id);

        if (mysqli_stmt_execute($stmt)) {
            $resultado = mysqli_stmt_get_result($stmt);

            while ($fila = mysqli_fetch_assoc($resultado)) {
                $involucrados[] = $fila;
            }
        }

        mysqli_stmt_close($stmt);
    }
}

/*
|--------------------------------------------------------------------------
| MEDIDAS
|--------------------------------------------------------------------------
*/

if ($error === '' && $cedula) {
    $sql = "
        SELECT
            m.*,
            tm.nombre AS tipo_medida,
            i.apellido_nombre AS involucrado_nombre
        FROM medidas m

        LEFT JOIN tipos_medida tm
            ON tm.id = m.tipo_medida_id

        LEFT JOIN involucrados i
            ON i.id = m.involucrado_id

        WHERE m.cedula_id = ?

        ORDER BY m.id ASC
    ";

    $stmt = mysqli_prepare($conexion, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $cedula_id);

        if (mysqli_stmt_execute($stmt)) {
            $resultado = mysqli_stmt_get_result($stmt);

            while ($fila = mysqli_fetch_assoc($resultado)) {
                $medidas[] = $fila;
            }
        }

        mysqli_stmt_close($stmt);
    }
}

/*
|--------------------------------------------------------------------------
| HISTORIAL COMPLETO
|--------------------------------------------------------------------------
*/

if ($error === '' && $cedula) {
    $sql = "
        SELECT
            h.id,
            h.cedula_id,
            h.usuario_id,
            h.accion,
            h.descripcion,
            h.fecha
        FROM historial_cedula h
        WHERE h.cedula_id = ?
        ORDER BY h.fecha ASC, h.id ASC
    ";

    $stmt = mysqli_prepare($conexion, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $cedula_id);

        if (mysqli_stmt_execute($stmt)) {
            $resultado = mysqli_stmt_get_result($stmt);

            while ($fila = mysqli_fetch_assoc($resultado)) {
                $historial[] = $fila;
            }
        }

        mysqli_stmt_close($stmt);
    }
}

/*
|--------------------------------------------------------------------------
| CADENA DE ENCARGADOS
|--------------------------------------------------------------------------
*/

foreach ($historial as $movimiento) {
    $accion = strtoupper($movimiento['accion'] ?? '');
    $descripcion = $movimiento['descripcion'] ?? '';

    if (
        strpos($accion, 'ENCARGADO') !== false ||
        strpos($accion, 'ASIGN') !== false ||
        strpos($accion, 'TRANSFER') !== false ||
        strpos($accion, 'ENTREG') !== false ||
        strpos(strtoupper($descripcion), 'ENCARGADO') !== false
    ) {
        $cadena_encargados[] = $movimiento;
    }
}

/*
|--------------------------------------------------------------------------
| USUARIOS DEL HISTORIAL
|--------------------------------------------------------------------------
*/

$usuarios_historial = [];

foreach ($historial as $movimiento) {
    $usuario_id = (int)($movimiento['usuario_id'] ?? 0);

    if ($usuario_id > 0) {
        $usuarios_historial[$usuario_id] = $usuario_id;
    }
}

$usuarios_datos = [];

if (count($usuarios_historial) > 0) {
    $ids = array_values($usuarios_historial);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tipos = str_repeat('i', count($ids));

    $sql = "SELECT id, usuario FROM usuarios WHERE id IN ($placeholders)";
    $stmt = mysqli_prepare($conexion, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $tipos, ...$ids);

        if (mysqli_stmt_execute($stmt)) {
            $resultado = mysqli_stmt_get_result($stmt);

            while ($fila = mysqli_fetch_assoc($resultado)) {
                $usuarios_datos[(int)$fila['id']] = $fila['usuario'];
            }
        }

        mysqli_stmt_close($stmt);
    }
}

?>
<!DOCTYPE html>

<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ver cédula</title>

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
        .main { margin-left: 250px; width: calc(100% - 250px); }
        .topbar { background: white; border-bottom: 1px solid #e5e7eb; padding: 18px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { margin: 0; font-size: 22px; }
        .user-info { text-align: right; font-size: 13px; }
        .user-info strong { display: block; }
        .user-info span { color: #6b7280; }
        .content { padding: 30px; max-width: 1250px; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-title { margin: 0 0 20px; font-size: 19px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
        .dato strong { display: block; font-size: 12px; color: #6b7280; margin-bottom: 5px; }
        .dato span { font-size: 15px; }
        .full { grid-column: 1 / -1; }
        .estado { display: inline-block; padding: 6px 10px; border-radius: 20px; background: #e5e7eb; font-size: 12px; font-weight: bold; }
        .estado-cumplimentada { background: #dcfce7; color: #166534; }
        .estado-pendiente { background: #fef3c7; color: #92400e; }
        .estado-diligenciamiento { background: #dbeafe; color: #1e40af; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .btn { display: inline-block; padding: 10px 15px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: bold; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #374151; color: white; }
        .btn-secondary:hover { background: #1f2937; }
        .btn-success { background: #15803d; color: white; }
        .btn-success:hover { background: #166534; }
        .btn-info { background: #0891b2; color: white; }
        .btn-info:hover { background: #0e7490; }
        .acciones { display: flex; gap: 10px; flex-wrap: wrap; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px; }
        td { padding: 12px; border-bottom: 1px solid #e5e7eb; font-size: 14px; vertical-align: top; }
        .empty { color: #6b7280; font-style: italic; }
        .cadena { margin-top: 10px; }
        .cadena-item { position: relative; padding-left: 45px; padding-bottom: 28px; }
        .cadena-item:not(:last-child)::before { content: ''; position: absolute; left: 12px; top: 25px; bottom: 0; width: 2px; background: #d1d5db; }
        .cadena-punto { position: absolute; left: 5px; top: 4px; width: 17px; height: 17px; border-radius: 50%; background: #2563eb; border: 3px solid #dbeafe; }
        .cadena-numero { font-size: 11px; color: #6b7280; margin-bottom: 3px; }
        .cadena-titulo { font-weight: bold; font-size: 15px; margin-bottom: 5px; }
        .cadena-descripcion { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 7px; padding: 12px; font-size: 14px; line-height: 1.5; }
        .cadena-fecha { font-size: 12px; color: #6b7280; margin-top: 7px; }
        .timeline { margin-top: 10px; }
        .timeline-item { position: relative; padding-left: 35px; padding-bottom: 25px; border-left: 2px solid #d1d5db; margin-left: 8px; }
        .timeline-item:last-child { border-left-color: transparent; padding-bottom: 0; }
        .timeline-dot { position: absolute; left: -7px; top: 0; width: 12px; height: 12px; border-radius: 50%; background: #2563eb; }
        .timeline-date { font-size: 12px; color: #6b7280; margin-bottom: 5px; }
        .timeline-action { font-weight: bold; font-size: 14px; margin-bottom: 5px; }
        .timeline-description { font-size: 14px; line-height: 1.5; }
        .timeline-user { font-size: 12px; color: #6b7280; margin-top: 5px; }
        .logout { position: absolute; bottom: 20px; left: 10px; right: 10px; }
        .logout a { display: block; text-align: center; padding: 11px; background: #991b1b; color: white; text-decoration: none; border-radius: 6px; }
        @media (max-width: 850px) {
            .sidebar { width: 210px; }
            .main { margin-left: 210px; width: calc(100% - 210px); }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>

<div class="layout">

    <!-- =========================================================
         MENU LATERAL
         ========================================================= -->

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
                <a href="nueva.php">Nueva cédula</a>
            <?php endif; ?>

            <a href="listado.php" class="active">Cédulas</a>

            <div class="menu-title">Seguimiento</div>
            <a href="pendientes.php">Pendientes de diligenciar</a>
            <a href="diligenciamiento.php">En diligenciamiento</a>
            <a href="cumplimentadas.php">Cumplimentadas</a>

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

    <!-- =========================================================
         CONTENIDO
         ========================================================= -->

    <main class="main">
        <header class="topbar">
            <h1>Ver cédula</h1>
            <div class="user-info">
                <strong>
                    <?= h(trim(($_SESSION['jerarquia'] ?? '') . ' ' . ($_SESSION['apellido'] ?? '') . ', ' . ($_SESSION['nombre'] ?? ''))) ?>
                </strong>
                <span>Usuario: <?= h($_SESSION['usuario'] ?? '') ?></span>
            </div>
        </header>

        <section class="content">
            <?php if (isset($_GET['asignado']) && $_GET['asignado'] == '1'): ?>
                <div class="alert alert-success">
                    El movimiento de encargado fue registrado correctamente en el historial.
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($cedula): ?>

                <?php
                $clase_estado = 'estado-pendiente';
                if ($cedula['estado'] === 'CUMPLIMENTADA') {
                    $clase_estado = 'estado-cumplimentada';
                } elseif ($cedula['estado'] === 'EN_DILIGENCIAMIENTO') {
                    $clase_estado = 'estado-diligenciamiento';
                }
                ?>

                <!-- DATOS PRINCIPALES -->
                <div class="card">
                    <h2 class="card-title">Información de la cédula</h2>
                    <div class="grid">
                        <div class="dato">
                            <strong>ID Cédula</strong>
                            <span><?= (int)$cedula['id'] ?></span>
                        </div>

                        <div class="dato">
                            <strong>Número de caso</strong>
                            <span>
                                <?php if ($caso): ?>
                                    <?= h($caso['numero_caso'] ?? '') ?>
                                <?php else: ?>
                                    <?= h($cedula['caso_id']) ?>
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="dato">
                            <strong>Estado</strong>
                            <span class="estado <?= $clase_estado ?>"><?= h($cedula['estado']) ?></span>
                        </div>

                        <div class="dato">
                            <strong>Fecha de ingreso</strong>
                            <span><?= fecha_argentina($cedula['fecha_ingreso']) ?></span>
                        </div>

                        <div class="dato">
                            <strong>Fecha de asignación</strong>
                            <span><?= fecha_argentina($cedula['fecha_asignacion']) ?></span>
                        </div>

                        <div class="dato">
                            <strong>Fecha de cumplimentación</strong>
                            <span><?= fecha_argentina($cedula['fecha_cumplimentacion']) ?></span>
                        </div>

                        <div class="dato full">
                            <strong>Asunto</strong>
                            <span><?= nl2br(h($cedula['asunto'] ?: 'Sin asunto')) ?></span>
                        </div>

                        <?php if (!empty($cedula['observaciones'])): ?>
                            <div class="dato full">
                                <strong>Observaciones</strong>
                                <span><?= nl2br(h($cedula['observaciones'])) ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($cedula['numero_resolucion'])): ?>
                            <div class="dato">
                                <strong>Número de resolución</strong>
                                <span><?= h($cedula['numero_resolucion']) ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($cedula['numero_hecho'])): ?>
                            <div class="dato">
                                <strong>Número de hecho</strong>
                                <span><?= h($cedula['numero_hecho']) ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($cedula['fecha_hecho'])): ?>
                            <div class="dato">
                                <strong>Fecha del hecho</strong>
                                <span><?= fecha_argentina($cedula['fecha_hecho']) ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($cedula['caracterizacion'])): ?>
                            <div class="dato full">
                                <strong>Caracterización</strong>
                                <span><?= h($cedula['caracterizacion']) ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($cedula['titulo_medida'])): ?>
                            <div class="dato full">
                                <strong>Título de la medida</strong>
                                <span><?= h($cedula['titulo_medida']) ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($cedula['descripcion_hecho'])): ?>
                            <div class="dato full">
                                <strong>Descripción del hecho</strong>
                                <span><?= nl2br(h($cedula['descripcion_hecho'])) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ENCARGADOS -->
                <div class="card">
                    <h2 class="card-title">Cadena de encargados</h2>

                    <?php if (count($cadena_encargados) === 0): ?>
                        <p class="empty">Todavía no hay encargados registrados para esta cédula.</p>
                    <?php else: ?>
                        <div class="cadena">
                            <?php $numero_encargado = 0; foreach ($cadena_encargados as $movimiento): $numero_encargado++; ?>
                                <div class="cadena-item">
                                    <div class="cadena-punto"></div>
                                    <div class="cadena-numero">ENCARGADO <?= $numero_encargado ?></div>
                                    <div class="cadena-titulo"><?= h($movimiento['accion']) ?></div>
                                    <div class="cadena-descripcion"><?= nl2br(h($movimiento['descripcion'])) ?></div>
                                    <div class="cadena-fecha"><?= fecha_argentina($movimiento['fecha']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (tienePermiso(['admin', 'supervisor', 'operador'])): ?>
                        <div class="acciones" style="margin-top:20px;">
                            <a href="asignar_encargado.php?id=<?= $cedula_id ?>" class="btn btn-primary">Asignar / transferir encargado</a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- INVOLUCRADOS -->
                <div class="card">
                    <h2 class="card-title">Involucrados</h2>

                    <?php if (count($involucrados) === 0): ?>
                        <p class="empty">No hay involucrados registrados.</p>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Apellido y nombre</th>
                                        <th>DNI</th>
                                        <th>Jerarquía</th>
                                        <th>Dependencia</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($involucrados as $involucrado): ?>
                                        <tr>
                                            <td><?= h($involucrado['id'] ?? '') ?></td>
                                            <td><?= h($involucrado['apellido_nombre'] ?? '') ?></td>
                                            <td><?= h($involucrado['dni'] ?? '') ?></td>
                                            <td><?= h($involucrado['jerarquia'] ?? '') ?></td>
                                            <td><?= h($involucrado['dependencia'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- MEDIDAS -->
                <div class="card">
                    <h2 class="card-title">Medidas registradas</h2>

                    <?php if (count($medidas) === 0): ?>
                        <p class="empty">No hay medidas registradas.</p>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Involucrado</th>
                                        <th>Datos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($medidas as $medida): ?>
                                        <tr>
                                            <td><?= h($medida['tipo_medida'] ?? '') ?></td>
                                            <td><?= h($medida['involucrado_nombre'] ?? '') ?></td>
                                            <td>
                                                <?php
                                                $omitidos = ['id', 'cedula_id', 'tipo_medida_id', 'involucrado_id', 'tipo_medida', 'involucrado_nombre'];
                                                $datos_mostrados = false;

                                                foreach ($medida as $campo => $valor) {
                                                    if (in_array($campo, $omitidos, true)) continue;
                                                    if ($valor === null || $valor === '') continue;

                                                    $datos_mostrados = true;
                                                    echo '<strong>' . h($campo) . ':</strong> ' . h($valor) . '<br>';
                                                }

                                                if (!$datos_mostrados) {
                                                    echo '-';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- HISTORIAL COMPLETO -->
                <div class="card">
                    <h2 class="card-title">Historial de la cédula</h2>

                    <?php if (count($historial) === 0): ?>
                        <p class="empty">Todavía no hay movimientos registrados.</p>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($historial as $movimiento): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot"></div>
                                    <div class="timeline-date"><?= fecha_argentina($movimiento['fecha']) ?></div>
                                    <div class="timeline-action"><?= h($movimiento['accion']) ?></div>

                                    <?php if (!empty($movimiento['descripcion'])): ?>
                                        <div class="timeline-description"><?= nl2br(h($movimiento['descripcion'])) ?></div>
                                    <?php endif; ?>

                                    <?php
                                    $uid = (int)($movimiento['usuario_id'] ?? 0);
                                    ?>

                                    <?php if ($uid > 0): ?>
                                        <div class="timeline-user">
                                            Usuario: <?= h($usuarios_datos[$uid] ?? ('ID ' . $uid)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- BOTONES -->
                <div class="card">
                    <div class="acciones">
                        <a href="listado.php" class="btn btn-secondary">Volver al listado</a>

                        <a href="../historial/ver.php?id=<?= $cedula_id ?>" class="btn btn-info">📜 Ver historial completo</a>

                        <?php if ($cedula['estado'] === 'EN_DILIGENCIAMIENTO' && tienePermiso(['admin', 'supervisor', 'operador'])): ?>
                            <a href="cumplimentar.php?id=<?= $cedula_id ?>" class="btn btn-success">✓ Cumplimentar cédula</a>
                        <?php endif; ?>

                        <?php if (tienePermiso(['admin', 'supervisor'])): ?>
                            <a href="editar.php?id=<?= $cedula_id ?>" class="btn btn-primary">✏️ Editar cédula</a>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endif; ?>
        </section>
    </main>
</div>

</body>

</html>