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

$usuario_id = (int)($_SESSION["usuario_id"] ?? 0);

$sancion_id = (int)($_GET["id"] ?? $_POST["id"] ?? 0);

if ($sancion_id <= 0) {
    header("Location: listado.php");
    exit;
}

$error = "";
$exito = false;

/*
|--------------------------------------------------------------------------
| TIPOS DE SANCIONES ACCESORIAS
|--------------------------------------------------------------------------
*/

$tipos_accesorias = [
    'TRATAMIENTO_TERAPEUTICO' => 'Tratamiento terapéutico',
    'DEBERES_ESPECIALES' => 'Deberes especiales de conducta',
    'CURSOS_EDUCATIVOS' => 'Cursos educativos',
    'REPARACION_DANO' => 'Reparación del daño',
    'TAREAS_COMUNITARIAS' => 'Tareas comunitarias',
];

/*
|--------------------------------------------------------------------------
| CARGAR SANCIÓN
|--------------------------------------------------------------------------
*/

$sql_sancion = "
    SELECT
        s.id, s.dni, s.apellido_nombre, s.cedula_id, s.tipo_sancion,
        s.dias_suspension, s.fecha_inicio, s.fecha_fin,
        s.observaciones, s.estado_cumplimiento, s.fecha_creacion,
        ca.numero_caso
    FROM sanciones s
    LEFT JOIN cedulas c ON c.id = s.cedula_id
    LEFT JOIN casos ca ON ca.id = c.caso_id
    WHERE s.id = ?
    LIMIT 1
";

$stmt_sancion = mysqli_prepare($conexion, $sql_sancion);

if ($stmt_sancion === false) {
    die("Error SQL al cargar la sanción: " . mysqli_error($conexion));
}

mysqli_stmt_bind_param($stmt_sancion, "i", $sancion_id);
mysqli_stmt_execute($stmt_sancion);
$res_sancion = mysqli_stmt_get_result($stmt_sancion);
$sancion = mysqli_fetch_assoc($res_sancion);
mysqli_stmt_close($stmt_sancion);

if (!$sancion) {
    die("La sanción no existe.");
}

// Guardar datos originales para auditoría
$datos_anteriores = [
    'dni' => $sancion['dni'],
    'apellido_nombre' => $sancion['apellido_nombre'],
    'cedula_id' => (int)$sancion['cedula_id'],
    'tipo_sancion' => $sancion['tipo_sancion'],
    'dias_suspension' => $sancion['dias_suspension'],
    'fecha_inicio' => $sancion['fecha_inicio'],
    'fecha_fin' => $sancion['fecha_fin'],
    'observaciones' => $sancion['observaciones'],
    'estado_cumplimiento' => $sancion['estado_cumplimiento']
];

/*
|--------------------------------------------------------------------------
| CARGAR ACCESORIAS ACTUALES
|--------------------------------------------------------------------------
*/

$accesorias_actuales = [];
$sql_acc_actuales = "SELECT id, tipo, detalle, fecha_inicio, fecha_fin, estado_cumplimiento FROM sanciones_accesorias WHERE sancion_id = ?";
$stmt_acc_actuales = mysqli_prepare($conexion, $sql_acc_actuales);
mysqli_stmt_bind_param($stmt_acc_actuales, "i", $sancion_id);
mysqli_stmt_execute($stmt_acc_actuales);
$res_acc_actuales = mysqli_stmt_get_result($stmt_acc_actuales);

while ($fila = mysqli_fetch_assoc($res_acc_actuales)) {
    $accesorias_actuales[$fila['tipo']] = $fila;
}

mysqli_stmt_close($stmt_acc_actuales);

/*
|--------------------------------------------------------------------------
| PROCESAR FORMULARIO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $tipo_sancion = trim($_POST["tipo_sancion"] ?? "");
    $dias_suspension = trim($_POST["dias_suspension"] ?? "");
    $fecha_inicio = trim($_POST["fecha_inicio"] ?? "");
    $fecha_fin = trim($_POST["fecha_fin"] ?? "");
    $observaciones = trim($_POST["observaciones"] ?? "");
    $estado_cumplimiento = trim($_POST["estado_cumplimiento"] ?? "PENDIENTE");

    $accesorias_seleccionadas = $_POST["accesorias"] ?? [];

    $tipos_validos = ['APERCIBIMIENTO', 'SUSPENSION', 'CESANTIA', 'DESTITUCION'];
    $estados_validos = ['PENDIENTE', 'EN_CURSO', 'CUMPLIDA', 'INCUMPLIDA'];

    if (!in_array($tipo_sancion, $tipos_validos)) {
        $error = "El tipo de sanción seleccionado no es válido.";
    } elseif (!in_array($estado_cumplimiento, $estados_validos)) {
        $error = "El estado de cumplimiento no es válido.";
    } elseif ($tipo_sancion === 'SUSPENSION' && $dias_suspension === "") {
        $error = "Debe indicar los días de suspensión.";
    } else {

        $dias_valor = ($tipo_sancion === 'SUSPENSION' && $dias_suspension !== "") ? (int)$dias_suspension : null;
        $fecha_inicio_valor = ($fecha_inicio !== "") ? $fecha_inicio : null;
        $fecha_fin_valor = ($fecha_fin !== "") ? $fecha_fin : null;

        mysqli_begin_transaction($conexion);

        try {

            /*
            |--------------------------------------------------------------------------
            | ACTUALIZAR SANCIÓN
            |--------------------------------------------------------------------------
            */

            $sql_update = "
                UPDATE sanciones
                SET tipo_sancion = ?,
                    dias_suspension = ?,
                    fecha_inicio = ?,
                    fecha_fin = ?,
                    observaciones = ?,
                    estado_cumplimiento = ?
                WHERE id = ?
            ";

            $stmt_update = mysqli_prepare($conexion, $sql_update);

            if ($stmt_update === false) {
                throw new Exception("Error SQL al preparar la actualización: " . mysqli_error($conexion));
            }

            mysqli_stmt_bind_param(
                $stmt_update,
                "sissisi",
                $tipo_sancion,
                $dias_valor,
                $fecha_inicio_valor,
                $fecha_fin_valor,
                $observaciones,
                $estado_cumplimiento,
                $sancion_id
            );

            if (!mysqli_stmt_execute($stmt_update)) {
                throw new Exception("Error al actualizar la sanción: " . mysqli_stmt_error($stmt_update));
            }

            mysqli_stmt_close($stmt_update);

            /*
            |--------------------------------------------------------------------------
            | PROCESAR ACCESORIAS
            |--------------------------------------------------------------------------
            */

            if (is_array($accesorias_seleccionadas)) {

                // Primero: eliminar accesorias que ya no están marcadas
                $tipos_actuales = array_keys($accesorias_actuales);
                $tipos_seleccionados = $accesorias_seleccionadas;

                foreach ($tipos_actuales as $tipo_actual) {
                    if (!in_array($tipo_actual, $tipos_seleccionados)) {
                        // Eliminar
                        $sql_del = "DELETE FROM sanciones_accesorias WHERE sancion_id = ? AND tipo = ?";
                        $stmt_del = mysqli_prepare($conexion, $sql_del);
                        mysqli_stmt_bind_param($stmt_del, "is", $sancion_id, $tipo_actual);
                        mysqli_stmt_execute($stmt_del);
                        mysqli_stmt_close($stmt_del);
                    }
                }

                // Segundo: insertar nuevas o actualizar existentes
                foreach ($tipos_seleccionados as $tipo_accesoria) {

                    if (!isset($tipos_accesorias[$tipo_accesoria])) continue;

                    $detalle_accesoria = trim($_POST["detalle_" . $tipo_accesoria] ?? "");
                    $fecha_ini_acc = trim($_POST["fecha_inicio_" . $tipo_accesoria] ?? "");
                    $fecha_fin_acc = trim($_POST["fecha_fin_" . $tipo_accesoria] ?? "");
                    $estado_acc = trim($_POST["estado_" . $tipo_accesoria] ?? "PENDIENTE");

                    $fecha_ini_val = ($fecha_ini_acc !== "") ? $fecha_ini_acc : null;
                    $fecha_fin_val = ($fecha_fin_acc !== "") ? $fecha_fin_acc : null;

                    if (isset($accesorias_actuales[$tipo_accesoria])) {

                        // Actualizar
                        $acc_id = (int)$accesorias_actuales[$tipo_accesoria]['id'];

                        $sql_upd_acc = "
                            UPDATE sanciones_accesorias
                            SET detalle = ?, fecha_inicio = ?, fecha_fin = ?, estado_cumplimiento = ?
                            WHERE id = ?
                        ";

                        $stmt_upd_acc = mysqli_prepare($conexion, $sql_upd_acc);
                        mysqli_stmt_bind_param($stmt_upd_acc, "ssssi", $detalle_accesoria, $fecha_ini_val, $fecha_fin_val, $estado_acc, $acc_id);
                        mysqli_stmt_execute($stmt_upd_acc);
                        mysqli_stmt_close($stmt_upd_acc);

                    } else {

                        // Insertar
                        $sql_ins_acc = "
                            INSERT INTO sanciones_accesorias
                            (sancion_id, tipo, detalle, fecha_inicio, fecha_fin, estado_cumplimiento)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ";

                        $stmt_ins_acc = mysqli_prepare($conexion, $sql_ins_acc);
                        mysqli_stmt_bind_param($stmt_ins_acc, "isssss", $sancion_id, $tipo_accesoria, $detalle_accesoria, $fecha_ini_val, $fecha_fin_val, $estado_acc);
                        mysqli_stmt_execute($stmt_ins_acc);
                        mysqli_stmt_close($stmt_ins_acc);
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | AUDITORÍA
            |--------------------------------------------------------------------------
            */

            $datos_nuevos = [
                'tipo_sancion' => $tipo_sancion,
                'dias_suspension' => $dias_valor,
                'fecha_inicio' => $fecha_inicio_valor,
                'fecha_fin' => $fecha_fin_valor,
                'observaciones' => $observaciones,
                'estado_cumplimiento' => $estado_cumplimiento,
                'accesorias' => $accesorias_seleccionadas
            ];

            auditar(
                $conexion,
                'EDITAR',
                'sanciones',
                'sanciones',
                $sancion_id,
                'Se editó la sanción de ' . $sancion['apellido_nombre'] . ' (DNI ' . $sancion['dni'] . ')',
                $datos_anteriores,
                $datos_nuevos
            );

            mysqli_commit($conexion);
            $exito = true;

            // Recargar datos
            $sancion['tipo_sancion'] = $tipo_sancion;
            $sancion['dias_suspension'] = $dias_valor;
            $sancion['fecha_inicio'] = $fecha_inicio_valor;
            $sancion['fecha_fin'] = $fecha_fin_valor;
            $sancion['observaciones'] = $observaciones;
            $sancion['estado_cumplimiento'] = $estado_cumplimiento;

            // Recargar accesorias
            $accesorias_actuales = [];
            $stmt_reload = mysqli_prepare($conexion, "SELECT id, tipo, detalle, fecha_inicio, fecha_fin, estado_cumplimiento FROM sanciones_accesorias WHERE sancion_id = ?");
            mysqli_stmt_bind_param($stmt_reload, "i", $sancion_id);
            mysqli_stmt_execute($stmt_reload);
            $res_reload = mysqli_stmt_get_result($stmt_reload);
            while ($fila = mysqli_fetch_assoc($res_reload)) {
                $accesorias_actuales[$fila['tipo']] = $fila;
            }
            mysqli_stmt_close($stmt_reload);

        } catch (Exception $e) {
            mysqli_rollback($conexion);
            $error = $e->getMessage();
        }
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

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Editar sanción - Sistema Medidas</title>

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
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-title { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .success-box { background: white; border: 1px solid #bbf7d0; border-radius: 10px; padding: 30px; }
        .success-box h2 { color: #166534; margin-top: 0; }
        .success-box p { line-height: 1.6; }
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
        .form-group { margin-bottom: 15px; }
        .full { grid-column: 1 / -1; }
        label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 7px; color: #374151; }
        input, select, textarea { width: 100%; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #2563eb; }
        textarea { min-height: 90px; resize: vertical; }
        input[readonly] { background: #f3f4f6; cursor: not-allowed; }
        .btn { border: none; border-radius: 6px; padding: 11px 17px; cursor: pointer; font-size: 14px; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1d4ed8; color: white; }
        .btn-primary:hover { background: #1e40af; }
        .btn-secondary { background: #374151; color: white; }
        .btn-secondary:hover { background: #1f2937; }
        .btn-success { background: #15803d; color: white; }
        .btn-success:hover { background: #166534; }
        .acciones { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .acciones-derecha { display: flex; gap: 10px; }
        .info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 14px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; line-height: 1.5; }
        .accesorias-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 15px; }
        .accesoria-item { border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; background: #fafafa; transition: 0.15s; }
        .accesoria-item.activa { border-color: #2563eb; background: #eff6ff; }
        .accesoria-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .accesoria-header input[type="checkbox"] { width: auto; }
        .accesoria-header label { margin: 0; cursor: pointer; font-size: 14px; }
        .accesoria-detalles { display: none; }
        .accesoria-detalles.activa { display: block; }
        .accesoria-detalles .form-group { margin-bottom: 10px; }
        @media (max-width: 900px) {
            .grid, .accesorias-grid { grid-template-columns: 1fr; }
            .sidebar { width: 210px; }
            .main { margin-left: 210px; width: calc(100% - 210px); }
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
            <h1>✏️ Editar sanción #<?= (int)$sancion['id'] ?></h1>
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

            <?php if ($exito): ?>
                <div class="success-box">
                    <h2>✓ Sanción actualizada correctamente</h2>
                    <p>Los cambios fueron guardados.</p>
                    <br>
                    <a href="ver.php?id=<?= $sancion_id ?>" class="btn btn-primary">Ver sanción</a>
                    <a href="listado.php" class="btn btn-secondary">Volver al listado</a>
                </div>
            <?php endif; ?>

            <div class="info">
                <strong>San­ción #<?= (int)$sancion['id'] ?>:</strong>
                <?= h($sancion['apellido_nombre']) ?> (DNI <?= h($sancion['dni']) ?>)
                <?php if (!empty($sancion['numero_caso'])): ?>
                    · Caso Nº <?= h($sancion['numero_caso']) ?>
                <?php endif; ?>
            </div>

            <form method="POST">

                <input type="hidden" name="id" value="<?= (int)$sancion_id ?>">

                <!-- DATOS NO EDITABLES -->
                <div class="card">
                    <h2 class="card-title">👤 Datos de la persona (no editables)</h2>

                    <div class="grid">
                        <div class="form-group">
                            <label>Apellido y nombre</label>
                            <input type="text" value="<?= h($sancion['apellido_nombre']) ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label>DNI</label>
                            <input type="text" value="<?= h($sancion['dni']) ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label>Cédula asociada</label>
                            <input type="text" value="#<?= (int)$sancion['cedula_id'] ?>" readonly>
                        </div>

                        <?php if (!empty($sancion['numero_caso'])): ?>
                            <div class="form-group">
                                <label>Número de caso</label>
                                <input type="text" value="<?= h($sancion['numero_caso']) ?>" readonly>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- DATOS EDITABLES -->
                <div class="card">
                    <h2 class="card-title">⚖️ Datos de la sanción</h2>

                    <div class="grid">
                        <div class="form-group">
                            <label for="tipo_sancion">Tipo de sanción *</label>
                            <select id="tipo_sancion" name="tipo_sancion" required onchange="actualizarDias()">
                                <option value="APERCIBIMIENTO" <?= ($sancion['tipo_sancion'] === 'APERCIBIMIENTO') ? "selected" : "" ?>>Apercibimiento</option>
                                <option value="SUSPENSION" <?= ($sancion['tipo_sancion'] === 'SUSPENSION') ? "selected" : "" ?>>Suspensión</option>
                                <option value="CESANTIA" <?= ($sancion['tipo_sancion'] === 'CESANTIA') ? "selected" : "" ?>>Cesantía</option>
                                <option value="DESTITUCION" <?= ($sancion['tipo_sancion'] === 'DESTITUCION') ? "selected" : "" ?>>Destitución</option>
                            </select>
                        </div>

                        <div class="form-group" id="grupoDias" style="<?= ($sancion['tipo_sancion'] === 'SUSPENSION') ? '' : 'display:none;' ?>">
                            <label for="dias_suspension">Días de suspensión *</label>
                            <input
                                type="number"
                                id="dias_suspension"
                                name="dias_suspension"
                                min="1"
                                max="120"
                                value="<?= h($sancion['dias_suspension']) ?>"
                                placeholder="Entre 1 y 120 días"
                            >
                        </div>

                        <div class="form-group">
                            <label for="fecha_inicio">Fecha de inicio</label>
                            <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= h($sancion['fecha_inicio']) ?>">
                        </div>

                        <div class="form-group">
                            <label for="fecha_fin">Fecha de fin</label>
                            <input type="date" id="fecha_fin" name="fecha_fin" value="<?= h($sancion['fecha_fin']) ?>">
                        </div>

                        <div class="form-group">
                            <label for="estado_cumplimiento">Estado de cumplimiento *</label>
                            <select id="estado_cumplimiento" name="estado_cumplimiento" required>
                                <option value="PENDIENTE" <?= ($sancion['estado_cumplimiento'] === 'PENDIENTE') ? "selected" : "" ?>>Pendiente</option>
                                <option value="EN_CURSO" <?= ($sancion['estado_cumplimiento'] === 'EN_CURSO') ? "selected" : "" ?>>En curso</option>
                                <option value="CUMPLIDA" <?= ($sancion['estado_cumplimiento'] === 'CUMPLIDA') ? "selected" : "" ?>>Cumplida</option>
                                <option value="INCUMPLIDA" <?= ($sancion['estado_cumplimiento'] === 'INCUMPLIDA') ? "selected" : "" ?>>Incumplida</option>
                            </select>
                        </div>

                        <div class="form-group full">
                            <label for="observaciones">Observaciones</label>
                            <textarea id="observaciones" name="observaciones"><?= h($sancion['observaciones']) ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- ACCESORIAS -->
                <div class="card">
                    <h2 class="card-title">📋 Sanciones accesorias</h2>

                    <p style="color: #6b7280; font-size: 13px; margin-bottom: 15px;">
                        Marque las sanciones accesorias que correspondan. Puede seleccionar varias.
                    </p>

                    <div class="accesorias-grid">
                        <?php foreach ($tipos_accesorias as $clave => $etiqueta): ?>
                            <?php
                            $acc_actual = $accesorias_actuales[$clave] ?? null;
                            $marcada = ($acc_actual !== null);
                            ?>
                            <div class="accesoria-item <?= $marcada ? 'activa' : '' ?>" id="item_<?= $clave ?>">
                                <div class="accesoria-header">
                                    <input
                                        type="checkbox"
                                        id="acc_<?= $clave ?>"
                                        name="accesorias[]"
                                        value="<?= $clave ?>"
                                        <?= $marcada ? "checked" : "" ?>
                                        onchange="toggleAccesoria('<?= $clave ?>')"
                                    >
                                    <label for="acc_<?= $clave ?>">
                                        <strong><?= h($etiqueta) ?></strong>
                                    </label>
                                </div>

                                <div class="accesoria-detalles <?= $marcada ? 'activa' : '' ?>" id="detalles_<?= $clave ?>">
                                    <div class="form-group">
                                        <label>Detalle</label>
                                        <input
                                            type="text"
                                            name="detalle_<?= $clave ?>"
                                            value="<?= h($acc_actual['detalle'] ?? '') ?>"
                                            placeholder="Detalle"
                                        >
                                    </div>

                                    <div class="grid">
                                        <div class="form-group">
                                            <label>Inicio</label>
                                            <input
                                                type="date"
                                                name="fecha_inicio_<?= $clave ?>"
                                                value="<?= h($acc_actual['fecha_inicio'] ?? '') ?>"
                                            >
                                        </div>
                                        <div class="form-group">
                                            <label>Fin</label>
                                            <input
                                                type="date"
                                                name="fecha_fin_<?= $clave ?>"
                                                value="<?= h($acc_actual['fecha_fin'] ?? '') ?>"
                                            >
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>Estado</label>
                                        <select name="estado_<?= $clave ?>">
                                            <option value="PENDIENTE" <?= (($acc_actual['estado_cumplimiento'] ?? '') === 'PENDIENTE') ? "selected" : "" ?>>Pendiente</option>
                                            <option value="EN_CURSO" <?= (($acc_actual['estado_cumplimiento'] ?? '') === 'EN_CURSO') ? "selected" : "" ?>>En curso</option>
                                            <option value="CUMPLIDA" <?= (($acc_actual['estado_cumplimiento'] ?? '') === 'CUMPLIDA') ? "selected" : "" ?>>Cumplida</option>
                                            <option value="INCUMPLIDA" <?= (($acc_actual['estado_cumplimiento'] ?? '') === 'INCUMPLIDA') ? "selected" : "" ?>>Incumplida</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- BOTONES -->
                <div class="card">
                    <div class="acciones">
                        <a href="ver.php?id=<?= $sancion_id ?>" class="btn btn-secondary">Cancelar</a>
                        <div class="acciones-derecha">
                            <button type="submit" class="btn btn-success">GUARDAR CAMBIOS</button>
                        </div>
                    </div>
                </div>

            </form>

        </section>
    </main>
</div>

<script>
function actualizarDias() {
    const tipo = document.getElementById("tipo_sancion").value;
    const grupoDias = document.getElementById("grupoDias");
    const inputDias = document.getElementById("dias_suspension");

    if (tipo === "SUSPENSION") {
        grupoDias.style.display = "block";
        inputDias.required = true;
    } else {
        grupoDias.style.display = "none";
        inputDias.required = false;
    }
}

function toggleAccesoria(clave) {
    const checkbox = document.getElementById("acc_" + clave);
    const item = document.getElementById("item_" + clave);
    const detalles = document.getElementById("detalles_" + clave);

    if (checkbox.checked) {
        item.classList.add("activa");
        detalles.classList.add("activa");
    } else {
        item.classList.remove("activa");
        detalles.classList.remove("activa");
    }
}

document.addEventListener("DOMContentLoaded", function() {
    actualizarDias();
});
</script>

</body>

</html>