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
require_once __DIR__ . '/../auditoria.php';
require_once __DIR__ . '/../permisos.php';

verificarPermiso(['admin', 'supervisor', 'operador']);

$cedula_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($cedula_id <= 0) {
    die('ID de cédula no válido.');
}

$error = '';
$mensaje = '';

$cedula = null;
$encargados = [];
$historial_encargados = [];

/*
|--------------------------------------------------------------------------
| FUNCIONES
|--------------------------------------------------------------------------
*/

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
| PROCESAR CREACIÓN DE NUEVO ENCARGADO (AJAX)
|--------------------------------------------------------------------------
*/

if (isset($_POST['accion']) && $_POST['accion'] === 'crear_encargado') {
    header('Content-Type: application/json');
    
    $nueva_jerarquia = trim($_POST['nueva_jerarquia'] ?? '');
    $nuevo_apellido_nombre = trim($_POST['nuevo_apellido_nombre'] ?? '');
    $nuevo_telefono = trim($_POST['nuevo_telefono'] ?? '');
    
    if ($nueva_jerarquia === '' || $nuevo_apellido_nombre === '' || $nuevo_telefono === '') {
        echo json_encode(['error' => 'Todos los campos son obligatorios']);
        exit;
    }
    
    $sql = "INSERT INTO encargados (jerarquia, apellido_nombre, telefono, activo) VALUES (?, ?, ?, 1)";
    $stmt = mysqli_prepare($conexion, $sql);
    
    if (!$stmt) {
        echo json_encode(['error' => 'Error SQL: ' . mysqli_error($conexion)]);
        exit;
    }
    
    mysqli_stmt_bind_param($stmt, 'sss', $nueva_jerarquia, $nuevo_apellido_nombre, $nuevo_telefono);
    
    if (!mysqli_stmt_execute($stmt)) {
        echo json_encode(['error' => 'Error al guardar: ' . mysqli_stmt_error($stmt)]);
        mysqli_stmt_close($stmt);
        exit;
    }
    
    $nuevo_id = mysqli_insert_id($conexion);
    mysqli_stmt_close($stmt);
    
    // AUDITORÍA: CREAR ENCARGADO
    auditar(
        $conexion,
        'CREAR',
        'encargados',
        'encargados',
        $nuevo_id,
        'Se creó un nuevo encargado desde asignación: ' . $nueva_jerarquia . ' ' . $nuevo_apellido_nombre,
        null,
        [
            'id' => $nuevo_id,
            'jerarquia' => $nueva_jerarquia,
            'apellido_nombre' => $nuevo_apellido_nombre,
            'telefono' => $nuevo_telefono,
            'activo' => 1,
            'cedula_id' => $cedula_id
        ]
    );
    
    echo json_encode([
        'success' => true,
        'id' => $nuevo_id,
        'jerarquia' => $nueva_jerarquia,
        'apellido_nombre' => $nuevo_apellido_nombre,
        'telefono' => $nuevo_telefono
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| OBTENER CÉDULA
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
        c.fecha_creacion
    FROM cedulas c
    WHERE c.id = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conexion, $sql);

if ($stmt === false) {
    $error = 'Error al preparar la consulta de la cédula: ' . mysqli_error($conexion);
} else {
    mysqli_stmt_bind_param($stmt, 'i', $cedula_id);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    $cedula = mysqli_fetch_assoc($resultado);
    mysqli_stmt_close($stmt);

    if (!$cedula) {
        $error = 'La cédula no existe.';
    }
}

/*
|--------------------------------------------------------------------------
| CARGAR ENCARGADOS ACTIVOS
|--------------------------------------------------------------------------
*/

if ($error === '') {
    $sql = "
        SELECT id, jerarquia, apellido_nombre, telefono, activo, fecha_creacion
        FROM encargados
        WHERE activo = 1
        ORDER BY apellido_nombre ASC
    ";

    $resultado = mysqli_query($conexion, $sql);

    if (!$resultado) {
        $error = 'Error al consultar encargados: ' . mysqli_error($conexion);
    } else {
        while ($fila = mysqli_fetch_assoc($resultado)) {
            $encargados[] = $fila;
        }
    }
}

/*
|--------------------------------------------------------------------------
| CARGAR HISTORIAL DE ENCARGADOS
|--------------------------------------------------------------------------
*/

if ($error === '') {
    $sql = "
        SELECT h.id, h.usuario_id, h.accion, h.descripcion, h.fecha
        FROM historial_cedula h
        WHERE h.cedula_id = ?
        AND (
            UPPER(h.accion) LIKE '%ENCARGADO%'
            OR UPPER(h.accion) LIKE '%ASIGN%'
            OR UPPER(h.accion) LIKE '%TRANSFER%'
            OR UPPER(h.accion) LIKE '%ENTREG%'
        )
        ORDER BY h.fecha ASC, h.id ASC
    ";

    $stmt = mysqli_prepare($conexion, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $cedula_id);
        mysqli_stmt_execute($stmt);
        $resultado = mysqli_stmt_get_result($stmt);

        while ($fila = mysqli_fetch_assoc($resultado)) {
            $historial_encargados[] = $fila;
        }
        mysqli_stmt_close($stmt);
    }
}

/*
|--------------------------------------------------------------------------
| PROCESAR ASIGNACIÓN
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && !isset($_POST['accion'])) {
    $encargado_id = isset($_POST['encargado_id']) ? (int) $_POST['encargado_id'] : 0;
    $tipo_movimiento = $_POST['tipo_movimiento'] ?? 'ASIGNACION';
    $observacion = trim($_POST['observacion'] ?? '');

    if ($encargado_id <= 0) {
        $error = 'Debe seleccionar un encargado.';
    } else {
        $sql = "
            SELECT id, jerarquia, apellido_nombre, telefono
            FROM encargados
            WHERE id = ?
            AND activo = 1
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conexion, $sql);

        if (!$stmt) {
            $error = 'Error al preparar la consulta del encargado: ' . mysqli_error($conexion);
        } else {
            mysqli_stmt_bind_param($stmt, 'i', $encargado_id);
            mysqli_stmt_execute($stmt);
            $resultado = mysqli_stmt_get_result($stmt);
            $encargado = mysqli_fetch_assoc($resultado);
            mysqli_stmt_close($stmt);

            if (!$encargado) {
                $error = 'El encargado seleccionado no existe o se encuentra inactivo.';
            }
        }
    }

    if ($error === '') {
        $nombre_encargado = trim(($encargado['jerarquia'] ?? '') . ' ' . ($encargado['apellido_nombre'] ?? ''));

        if ($tipo_movimiento === 'TRANSFERENCIA') {
            $accion = 'TRANSFERENCIA DE ENCARGADO';
            $descripcion = 'La cédula fue transferida al encargado: ' . $nombre_encargado . '.';
        } else {
            $accion = 'ASIGNACION DE ENCARGADO';
            $descripcion = 'Se asignó como encargado de la cédula a: ' . $nombre_encargado . '.';
        }

        if ($observacion !== '') {
            $descripcion .= ' Observación: ' . $observacion;
        }

        $usuario_id = (int) $_SESSION['usuario_id'];

        mysqli_begin_transaction($conexion);

        try {
            $sql_diligenciamiento = "
                INSERT INTO diligenciamientos (
                    cedula_id, encargado_id, usuario_asignador_id,
                    fecha_asignacion, estado, observaciones
                )
                VALUES (?, ?, ?, NOW(), 'PENDIENTE', ?)
            ";

            $stmt_diligenciamiento = mysqli_prepare($conexion, $sql_diligenciamiento);

            if (!$stmt_diligenciamiento) {
                throw new Exception('No se pudo preparar el diligenciamiento: ' . mysqli_error($conexion));
            }

            mysqli_stmt_bind_param($stmt_diligenciamiento, 'iiis', $cedula_id, $encargado_id, $usuario_id, $observacion);

            if (!mysqli_stmt_execute($stmt_diligenciamiento)) {
                throw new Exception('No se pudo registrar el diligenciamiento: ' . mysqli_stmt_error($stmt_diligenciamiento));
            }

            $diligenciamiento_id = mysqli_insert_id($conexion);
            mysqli_stmt_close($stmt_diligenciamiento);

            $sql_historial = "
                INSERT INTO historial_cedula (
                    cedula_id, usuario_id, encargado_anterior_id,
                    encargado_nuevo_id, accion, descripcion, fecha
                )
                VALUES (?, ?, NULL, ?, ?, ?, NOW())
            ";

            $stmt_historial = mysqli_prepare($conexion, $sql_historial);

            if (!$stmt_historial) {
                throw new Exception('No se pudo preparar el historial: ' . mysqli_error($conexion));
            }

            mysqli_stmt_bind_param($stmt_historial, 'iiiss', $cedula_id, $usuario_id, $encargado_id, $accion, $descripcion);

            if (!mysqli_stmt_execute($stmt_historial)) {
                throw new Exception('No se pudo registrar el historial: ' . mysqli_stmt_error($stmt_historial));
            }

            mysqli_stmt_close($stmt_historial);

            $sql_cedula = "
                UPDATE cedulas
                SET estado = 'EN_DILIGENCIAMIENTO',
                    fecha_asignacion = NOW()
                WHERE id = ?
            ";

            $stmt_cedula = mysqli_prepare($conexion, $sql_cedula);

            if (!$stmt_cedula) {
                throw new Exception('No se pudo preparar la actualización de la cédula: ' . mysqli_error($conexion));
            }

            mysqli_stmt_bind_param($stmt_cedula, 'i', $cedula_id);

            if (!mysqli_stmt_execute($stmt_cedula)) {
                throw new Exception('No se pudo actualizar la cédula: ' . mysqli_stmt_error($stmt_cedula));
            }

            mysqli_stmt_close($stmt_cedula);

            // AUDITORÍA: ASIGNAR/TRANSFERIR
            auditar(
                $conexion,
                ($tipo_movimiento === 'TRANSFERENCIA' ? 'TRANSFERIR' : 'ASIGNAR'),
                'cedulas',
                'diligenciamientos',
                $diligenciamiento_id,
                ($tipo_movimiento === 'TRANSFERENCIA' 
                    ? 'Se transfirió la cédula al encargado: ' 
                    : 'Se asignó encargado a la cédula: ') . $nombre_encargado,
                [
                    'estado' => $cedula['estado'],
                    'fecha_asignacion' => $cedula['fecha_asignacion']
                ],
                [
                    'cedula_id' => $cedula_id,
                    'diligenciamiento_id' => $diligenciamiento_id,
                    'encargado_id' => $encargado_id,
                    'encargado_nombre' => $nombre_encargado,
                    'encargado_telefono' => $encargado['telefono'],
                    'tipo_movimiento' => $tipo_movimiento,
                    'observacion' => $observacion,
                    'estado_nuevo' => 'EN_DILIGENCIAMIENTO'
                ]
            );

            mysqli_commit($conexion);

            header('Location: ver.php?id=' . $cedula_id . '&asignado=1');
            exit;

        } catch (Throwable $e) {
            mysqli_rollback($conexion);
            $error = $e->getMessage();
            
            auditar(
                $conexion,
                'ERROR',
                'cedulas',
                'diligenciamientos',
                $cedula_id,
                'Error al asignar encargado: ' . $e->getMessage(),
                null,
                [
                    'cedula_id' => $cedula_id,
                    'encargado_id' => $encargado_id,
                    'error' => $e->getMessage()
                ]
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| NÚMERO DE CASO
|--------------------------------------------------------------------------
*/

$numero_caso = '';

if ($error === '' && $cedula) {
    $sql = "SELECT numero_caso FROM casos WHERE id = ? LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $cedula['caso_id']);
        mysqli_stmt_execute($stmt);
        $resultado = mysqli_stmt_get_result($stmt);
        $caso = mysqli_fetch_assoc($resultado);

        if ($caso) {
            $numero_caso = $caso['numero_caso'] ?? '';
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
    <title>Asignar encargado</title>
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
        .menu a.active { background: #2563eb; color: white; }
        .main { margin-left: 250px; width: calc(100% - 250px); }
        .topbar { background: white; border-bottom: 1px solid #e5e7eb; padding: 18px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { margin: 0; font-size: 22px; }
        .user-info { text-align: right; font-size: 13px; }
        .user-info strong { display: block; }
        .user-info span { color: #6b7280; }
        .content { padding: 30px; max-width: 1200px; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-title { margin: 0 0 20px; font-size: 19px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
        .full { grid-column: 1 / -1; }
        .dato strong { display: block; font-size: 12px; color: #6b7280; margin-bottom: 5px; }
        .dato span { font-size: 15px; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        label { display: block; font-weight: bold; font-size: 13px; margin-bottom: 8px; }
        select, textarea, input[type="text"] { width: 100%; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-family: inherit; font-size: 14px; background: white; }
        textarea { min-height: 110px; resize: vertical; }
        .form-group { margin-bottom: 20px; }
        .radio-group { display: flex; gap: 25px; margin-top: 10px; }
        .radio-option { display: flex; align-items: center; gap: 7px; font-weight: normal; }
        .radio-option input { width: auto; }
        .btn { display: inline-block; text-decoration: none; padding: 11px 18px; border-radius: 6px; font-size: 14px; font-weight: bold; border: none; cursor: pointer; }
        .btn-primary { background: #1d4ed8; color: white; }
        .btn-secondary { background: #374151; color: white; }
        .btn-success { background: #15803d; color: white; }
        .btn-info { background: #0891b2; color: white; }
        .btn-info:hover { background: #0e7490; }
        .btn:hover { opacity: .9; }
        .acciones { display: flex; gap: 10px; flex-wrap: wrap; }
        .historial { margin-top: 10px; }
        .historial-item { position: relative; padding: 0 0 20px 28px; border-left: 2px solid #d1d5db; margin-left: 8px; }
        .historial-item:last-child { border-left-color: transparent; padding-bottom: 0; }
        .punto { position: absolute; left: -7px; top: 0; width: 12px; height: 12px; background: #2563eb; border-radius: 50%; }
        .historial-fecha { color: #6b7280; font-size: 12px; margin-bottom: 5px; }
        .historial-accion { font-weight: bold; font-size: 14px; margin-bottom: 5px; }
        .historial-descripcion { font-size: 14px; line-height: 1.5; }
        .empty { color: #6b7280; font-style: italic; }
        .select-encargado-wrapper { display: flex; gap: 10px; align-items: flex-end; }
        .select-encargado-wrapper select { flex: 1; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-overlay.active { display: flex; }
        .modal { background: white; border-radius: 10px; padding: 30px; max-width: 500px; width: 90%; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .modal h3 { margin-top: 0; color: #1e40af; }
        .modal-buttons { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .modal .form-group { margin-bottom: 15px; }
        .modal label { font-size: 13px; }
        .modal input[type="text"] { font-size: 14px; }
        @media (max-width: 850px) {
            .sidebar { width: 210px; }
            .main { margin-left: 210px; width: calc(100% - 210px); }
            .grid { grid-template-columns: 1fr; }
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
                <a href="nueva.php">Nueva cédula</a>
            <?php endif; ?>

            <a href="listado.php">Cédulas</a>

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

    <main class="main">
        <header class="topbar">
            <h1>Asignar encargado</h1>
            <div class="user-info">
                <strong>
                    <?= h(trim(($_SESSION['jerarquia'] ?? '') . ' ' . ($_SESSION['apellido'] ?? '') . ', ' . ($_SESSION['nombre'] ?? ''))) ?>
                </strong>
                <span>Usuario: <?= h($_SESSION['usuario'] ?? '') ?></span>
            </div>
        </header>

        <section class="content">
            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($cedula): ?>
                <div class="card">
                    <h2 class="card-title">Cédula</h2>
                    <div class="grid">
                        <div class="dato">
                            <strong>Número de caso</strong>
                            <span><?= h($numero_caso !== '' ? $numero_caso : $cedula['caso_id']) ?></span>
                        </div>
                        <div class="dato">
                            <strong>Estado</strong>
                            <span><?= h($cedula['estado']) ?></span>
                        </div>
                        <div class="dato full">
                            <strong>Asunto</strong>
                            <span><?= nl2br(h($cedula['asunto'] ?: 'Sin asunto')) ?></span>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2 class="card-title">Asignar o transferir encargado</h2>
                    <form method="POST" action="">
                        <div class="form-group">
                            <label>Tipo de movimiento</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="tipo_movimiento" value="ASIGNACION" checked>
                                    Primera asignación
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="tipo_movimiento" value="TRANSFERENCIA">
                                    Transferir a otro encargado
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="encargado_id">Encargado</label>
                            <div class="select-encargado-wrapper">
                                <select name="encargado_id" id="encargado_id" required>
                                    <option value="">-- Seleccione un encargado --</option>
                                    <?php foreach ($encargados as $encargado): ?>
                                        <option value="<?= (int)$encargado['id'] ?>">
                                            <?= h(trim($encargado['jerarquia'] . ' ' . $encargado['apellido_nombre'])) ?>
                                            <?php if (!empty($encargado['telefono'])): ?> - Tel: <?= h($encargado['telefono']) ?><?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-info" onclick="abrirModalNuevoEncargado()">+ Nuevo encargado</button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="observacion">Observación</label>
                            <textarea name="observacion" id="observacion" placeholder="Ingrese una observación sobre la asignación o transferencia..."></textarea>
                        </div>

                        <div class="acciones">
                            <button type="submit" class="btn btn-primary">Guardar movimiento</button>
                            <a href="ver.php?id=<?= $cedula_id ?>" class="btn btn-secondary">Cancelar</a>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <h2 class="card-title">Cadena de encargados</h2>
                    <?php if (count($historial_encargados) === 0): ?>
                        <p class="empty">Esta cédula todavía no tiene movimientos de encargados registrados.</p>
                    <?php else: ?>
                        <div class="historial">
                            <?php $numero = 0; foreach ($historial_encargados as $movimiento): $numero++; ?>
                                <div class="historial-item">
                                    <div class="punto"></div>
                                    <div class="historial-fecha"><?= fecha_argentina($movimiento['fecha']) ?></div>
                                    <div class="historial-accion"><?= h($movimiento['accion']) ?></div>
                                    <div class="historial-descripcion"><?= nl2br(h($movimiento['descripcion'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="acciones">
                        <a href="ver.php?id=<?= $cedula_id ?>" class="btn btn-secondary">Volver a la cédula</a>
                        <a href="pendientes.php" class="btn btn-secondary">Ver pendientes</a>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<!-- MODAL PARA NUEVO ENCARGADO -->
<div id="modalNuevoEncargado" class="modal-overlay">
    <div class="modal">
        <h3>➕ Nuevo encargado</h3>
        
        <div class="form-group">
            <label for="nueva_jerarquia">Jerarquía *</label>
            <input type="text" id="nueva_jerarquia" placeholder="Ej.: SARGENTO" required>
        </div>
        
        <div class="form-group">
            <label for="nuevo_apellido_nombre">Apellido y nombre *</label>
            <input type="text" id="nuevo_apellido_nombre" placeholder="Ej.: PÉREZ JUAN CARLOS" required>
        </div>
        
        <div class="form-group">
            <label for="nuevo_telefono">Teléfono *</label>
            <input type="text" id="nuevo_telefono" placeholder="Ej.: 351-1234567" required>
        </div>
        
        <div id="modalError" class="alert alert-error" style="display: none;"></div>
        
        <div class="modal-buttons">
            <button type="button" class="btn btn-secondary" onclick="cerrarModalNuevoEncargado()">Cancelar</button>
            <button type="button" class="btn btn-success" onclick="guardarNuevoEncargado()">Guardar</button>
        </div>
    </div>
</div>

<script>
function abrirModalNuevoEncargado() {
    document.getElementById('modalNuevoEncargado').classList.add('active');
    document.getElementById('nueva_jerarquia').value = '';
    document.getElementById('nuevo_apellido_nombre').value = '';
    document.getElementById('nuevo_telefono').value = '';
    document.getElementById('modalError').style.display = 'none';
    document.getElementById('nueva_jerarquia').focus();
}

function cerrarModalNuevoEncargado() {
    document.getElementById('modalNuevoEncargado').classList.remove('active');
}

function guardarNuevoEncargado() {
    const jerarquia = document.getElementById('nueva_jerarquia').value.trim();
    const apellido_nombre = document.getElementById('nuevo_apellido_nombre').value.trim();
    const telefono = document.getElementById('nuevo_telefono').value.trim();
    const modalError = document.getElementById('modalError');
    
    if (jerarquia === '' || apellido_nombre === '' || telefono === '') {
        modalError.textContent = 'Todos los campos son obligatorios.';
        modalError.style.display = 'block';
        return;
    }
    
    const formData = new FormData();
    formData.append('accion', 'crear_encargado');
    formData.append('nueva_jerarquia', jerarquia);
    formData.append('nuevo_apellido_nombre', apellido_nombre);
    formData.append('nuevo_telefono', telefono);
    
    fetch('asignar_encargado.php?id=<?= $cedula_id ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            modalError.textContent = data.error;
            modalError.style.display = 'block';
            return;
        }
        
        const select = document.getElementById('encargado_id');
        const option = document.createElement('option');
        option.value = data.id;
        option.textContent = data.jerarquia + ' ' + data.apellido_nombre + ' - Tel: ' + data.telefono;
        option.selected = true;
        select.appendChild(option);
        
        cerrarModalNuevoEncargado();
        mostrarAviso('✓ Encargado "' + data.apellido_nombre + '" creado y seleccionado');
    })
    .catch(error => {
        console.error('Error:', error);
        modalError.textContent = 'Error al guardar el encargado.';
        modalError.style.display = 'block';
    });
}

function mostrarAviso(mensaje) {
    const aviso = document.createElement('div');
    aviso.textContent = mensaje;
    aviso.style.cssText = `
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: #15803d;
        color: white;
        padding: 15px 25px;
        border-radius: 8px;
        font-weight: bold;
        font-size: 14px;
        z-index: 9999;
        box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        transition: opacity 0.3s;
    `;
    document.body.appendChild(aviso);
    
    setTimeout(function() {
        aviso.style.opacity = '0';
        setTimeout(function() {
            document.body.removeChild(aviso);
        }, 300);
    }, 3000);
}

document.getElementById('modalNuevoEncargado').addEventListener('click', function(e) {
    if (e.target === this) {
        cerrarModalNuevoEncargado();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModalNuevoEncargado();
    }
});
</script>

</body>
</html>