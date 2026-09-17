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

$error = "";
$exito = false;
$sancion_id_creada = 0;

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
| PROCESAR FORMULARIO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $dni = trim($_POST["dni"] ?? "");
    $cedula_id = (int)($_POST["cedula_id"] ?? 0);
    $tipo_sancion = trim($_POST["tipo_sancion"] ?? "");
    $dias_suspension = trim($_POST["dias_suspension"] ?? "");
    $fecha_inicio = trim($_POST["fecha_inicio"] ?? "");
    $fecha_fin = trim($_POST["fecha_fin"] ?? "");
    $observaciones = trim($_POST["observaciones"] ?? "");
    $estado_cumplimiento = trim($_POST["estado_cumplimiento"] ?? "PENDIENTE");

    $accesorias_seleccionadas = $_POST["accesorias"] ?? [];

    /*
    |--------------------------------------------------------------------------
    | VALIDACIONES
    |--------------------------------------------------------------------------
    */

    $tipos_validos = ['APERCIBIMIENTO', 'SUSPENSION', 'CESANTIA', 'DESTITUCION'];
    $estados_validos = ['PENDIENTE', 'EN_CURSO', 'CUMPLIDA', 'INCUMPLIDA'];

    if ($dni === "") {
        $error = "Debe buscar una persona por DNI.";
    } elseif ($cedula_id <= 0) {
        $error = "Debe seleccionar una cédula asociada.";
    } elseif (!in_array($tipo_sancion, $tipos_validos)) {
        $error = "El tipo de sanción seleccionado no es válido.";
    } elseif (!in_array($estado_cumplimiento, $estados_validos)) {
        $error = "El estado de cumplimiento no es válido.";
    } elseif ($tipo_sancion === 'SUSPENSION' && $dias_suspension === "") {
        $error = "Debe indicar los días de suspensión.";
    } else {

        // Verificar que la persona existe
        $sql_persona = "SELECT apellido_nombre FROM personal_policial WHERE dni = ? LIMIT 1";
        $stmt_persona = mysqli_prepare($conexion, $sql_persona);
        mysqli_stmt_bind_param($stmt_persona, "s", $dni);
        mysqli_stmt_execute($stmt_persona);
        $res_persona = mysqli_stmt_get_result($stmt_persona);
        $persona = mysqli_fetch_assoc($res_persona);
        mysqli_stmt_close($stmt_persona);

        if (!$persona) {
            $error = "No se encontró personal con el DNI ingresado.";
        } else {

            // Verificar que la cédula existe
            $sql_cedula = "SELECT id FROM cedulas WHERE id = ? LIMIT 1";
            $stmt_cedula = mysqli_prepare($conexion, $sql_cedula);
            mysqli_stmt_bind_param($stmt_cedula, "i", $cedula_id);
            mysqli_stmt_execute($stmt_cedula);
            $res_cedula = mysqli_stmt_get_result($stmt_cedula);
            $cedula_existe = mysqli_fetch_assoc($res_cedula);
            mysqli_stmt_close($stmt_cedula);

            if (!$cedula_existe) {
                $error = "La cédula seleccionada no existe.";
            } else {

                $apellido_nombre = $persona['apellido_nombre'];
                $dias_valor = ($tipo_sancion === 'SUSPENSION' && $dias_suspension !== "") ? (int)$dias_suspension : null;
                $fecha_inicio_valor = ($fecha_inicio !== "") ? $fecha_inicio : null;
                $fecha_fin_valor = ($fecha_fin !== "") ? $fecha_fin : null;

                mysqli_begin_transaction($conexion);

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | INSERTAR SANCIÓN
                    |--------------------------------------------------------------------------
                    */

                    $sql_insert = "
                        INSERT INTO sanciones (
                            dni, apellido_nombre, cedula_id, tipo_sancion,
                            dias_suspension, fecha_inicio, fecha_fin,
                            observaciones, estado_cumplimiento
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ";

                    $stmt_insert = mysqli_prepare($conexion, $sql_insert);

                    if ($stmt_insert === false) {
                        throw new Exception("Error SQL al preparar la inserción: " . mysqli_error($conexion));
                    }

                    mysqli_stmt_bind_param(
                        $stmt_insert,
                        "ssisissis",
                        $dni,
                        $apellido_nombre,
                        $cedula_id,
                        $tipo_sancion,
                        $dias_valor,
                        $fecha_inicio_valor,
                        $fecha_fin_valor,
                        $observaciones,
                        $estado_cumplimiento
                    );

                    if (!mysqli_stmt_execute($stmt_insert)) {
                        throw new Exception("Error al crear la sanción: " . mysqli_stmt_error($stmt_insert));
                    }

                    $sancion_id_creada = mysqli_insert_id($conexion);
                    mysqli_stmt_close($stmt_insert);

                    /*
                    |--------------------------------------------------------------------------
                    | INSERTAR ACCESORIAS
                    |--------------------------------------------------------------------------
                    */

                    if (is_array($accesorias_seleccionadas) && count($accesorias_seleccionadas) > 0) {

                        foreach ($accesorias_seleccionadas as $tipo_accesoria) {

                            if (!isset($tipos_accesorias[$tipo_accesoria])) continue;

                            $detalle_accesoria = trim($_POST["detalle_" . $tipo_accesoria] ?? "");
                            $fecha_ini_acc = trim($_POST["fecha_inicio_" . $tipo_accesoria] ?? "");
                            $fecha_fin_acc = trim($_POST["fecha_fin_" . $tipo_accesoria] ?? "");

                            $sql_acc = "
                                INSERT INTO sanciones_accesorias (
                                    sancion_id, tipo, detalle,
                                    fecha_inicio, fecha_fin, estado_cumplimiento
                                )
                                VALUES (?, ?, ?, ?, ?, 'PENDIENTE')
                            ";

                            $stmt_acc = mysqli_prepare($conexion, $sql_acc);

                            if ($stmt_acc === false) {
                                throw new Exception("Error SQL al preparar accesoria: " . mysqli_error($conexion));
                            }

                            $fecha_ini_val = ($fecha_ini_acc !== "") ? $fecha_ini_acc : null;
                            $fecha_fin_val = ($fecha_fin_acc !== "") ? $fecha_fin_acc : null;

                            mysqli_stmt_bind_param(
                                $stmt_acc,
                                "issss",
                                $sancion_id_creada,
                                $tipo_accesoria,
                                $detalle_accesoria,
                                $fecha_ini_val,
                                $fecha_fin_val
                            );

                            if (!mysqli_stmt_execute($stmt_acc)) {
                                throw new Exception("Error al crear accesoria: " . mysqli_stmt_error($stmt_acc));
                            }

                            mysqli_stmt_close($stmt_acc);
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | AUDITORÍA
                    |--------------------------------------------------------------------------
                    */

                    auditar(
                        $conexion,
                        'CREAR',
                        'sanciones',
                        'sanciones',
                        $sancion_id_creada,
                        'Se creó una sanción para ' . $apellido_nombre . ' (DNI ' . $dni . ') - Tipo: ' . $tipo_sancion,
                        null,
                        [
                            'id' => $sancion_id_creada,
                            'dni' => $dni,
                            'apellido_nombre' => $apellido_nombre,
                            'cedula_id' => $cedula_id,
                            'tipo_sancion' => $tipo_sancion,
                            'dias_suspension' => $dias_valor,
                            'estado_cumplimiento' => $estado_cumplimiento,
                            'cantidad_accesorias' => count($accesorias_seleccionadas)
                        ]
                    );

                    mysqli_commit($conexion);
                    $exito = true;

                } catch (Exception $e) {
                    mysqli_rollback($conexion);
                    $error = $e->getMessage();
                }
            }
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

    <title>Nueva sanción - Sistema Medidas</title>

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
        .btn { border: none; border-radius: 6px; padding: 11px 17px; cursor: pointer; font-size: 14px; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1d4ed8; color: white; }
        .btn-primary:hover { background: #1e40af; }
        .btn-secondary { background: #374151; color: white; }
        .btn-secondary:hover { background: #1f2937; }
        .btn-success { background: #15803d; color: white; }
        .btn-success:hover { background: #166534; }
        .btn-info { background: #0891b2; color: white; }
        .btn-info:hover { background: #0e7490; }
        .acciones { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .acciones-derecha { display: flex; gap: 10px; }
        .info-persona { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 15px; margin-top: 10px; display: none; }
        .info-persona strong { color: #1e40af; }
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
        .aviso-flotante { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: #15803d; color: white; padding: 15px 25px; border-radius: 8px; font-weight: bold; font-size: 14px; z-index: 9999; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
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
            <h1>⚖️ Nueva sanción</h1>
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
                    <h2>✓ Sanción registrada correctamente</h2>
                    <p>La sanción fue creada correctamente.</p>
                    <p><strong>ID de la sanción:</strong> #<?= $sancion_id_creada ?></p>
                    <br>
                    <a href="ver.php?id=<?= $sancion_id_creada ?>" class="btn btn-primary">Ver sanción</a>
                    <a href="listado.php" class="btn btn-secondary">Volver al listado</a>
                    <a href="nueva.php" class="btn btn-success">Registrar otra</a>
                </div>
            <?php else: ?>

                <div class="info">
                    <strong>Importante:</strong>
                    La sanción se debe asociar a una cédula ya existente. Si la cédula no existe, primero debe crearla desde el módulo de cédulas.
                </div>

                <form method="POST" id="formSancion">

                    <!-- DATOS DE LA PERSONA -->
                    <div class="card">
                        <h2 class="card-title">👤 Datos de la persona sancionada</h2>

                        <div class="grid">
                            <div class="form-group">
                                <label for="dni">DNI *</label>
                                <input
                                    type="text"
                                    id="dni"
                                    name="dni"
                                    required
                                    value="<?= h($_POST["dni"] ?? "") ?>"
                                    onblur="buscarPersona()"
                                    placeholder="Ingrese el DNI del efectivo"
                                >
                            </div>

                            <div class="form-group">
                                <label for="cedula_id">Cédula asociada *</label>
                                <input
                                    type="number"
                                    id="cedula_id"
                                    name="cedula_id"
                                    required
                                    value="<?= h($_POST["cedula_id"] ?? "") ?>"
                                    placeholder="Ej: 10"
                                >
                                <small style="display: block; margin-top: 5px; color: #6b7280; font-size: 12px;">
                                    Ingrese el ID de la cédula a la que se asocia esta sanción.
                                </small>
                            </div>
                        </div>

                        <div id="infoPersona" class="info-persona"></div>
                    </div>

                    <!-- DATOS DE LA SANCIÓN -->
                    <div class="card">
                        <h2 class="card-title">⚖️ Datos de la sanción</h2>

                        <div class="grid">
                            <div class="form-group">
                                <label for="tipo_sancion">Tipo de sanción *</label>
                                <select id="tipo_sancion" name="tipo_sancion" required onchange="actualizarDias()">
                                    <option value="">Seleccione tipo</option>
                                    <option value="APERCIBIMIENTO" <?= (($_POST["tipo_sancion"] ?? "") === "APERCIBIMIENTO") ? "selected" : "" ?>>Apercibimiento</option>
                                    <option value="SUSPENSION" <?= (($_POST["tipo_sancion"] ?? "") === "SUSPENSION") ? "selected" : "" ?>>Suspensión</option>
                                    <option value="CESANTIA" <?= (($_POST["tipo_sancion"] ?? "") === "CESANTIA") ? "selected" : "" ?>>Cesantía</option>
                                    <option value="DESTITUCION" <?= (($_POST["tipo_sancion"] ?? "") === "DESTITUCION") ? "selected" : "" ?>>Destitución</option>
                                </select>
                            </div>

                            <div class="form-group" id="grupoDias" style="display: none;">
                                <label for="dias_suspension">Días de suspensión *</label>
                                <input
                                    type="number"
                                    id="dias_suspension"
                                    name="dias_suspension"
                                    min="1"
                                    max="120"
                                    value="<?= h($_POST["dias_suspension"] ?? "") ?>"
                                    placeholder="Entre 1 y 120 días"
                                >
                            </div>

                            <div class="form-group">
                                <label for="fecha_inicio">Fecha de inicio</label>
                                <input
                                    type="date"
                                    id="fecha_inicio"
                                    name="fecha_inicio"
                                    value="<?= h($_POST["fecha_inicio"] ?? "") ?>"
                                >
                            </div>

                            <div class="form-group">
                                <label for="fecha_fin">Fecha de fin</label>
                                <input
                                    type="date"
                                    id="fecha_fin"
                                    name="fecha_fin"
                                    value="<?= h($_POST["fecha_fin"] ?? "") ?>"
                                >
                            </div>

                            <div class="form-group">
                                <label for="estado_cumplimiento">Estado de cumplimiento *</label>
                                <select id="estado_cumplimiento" name="estado_cumplimiento" required>
                                    <option value="PENDIENTE" <?= (($_POST["estado_cumplimiento"] ?? "PENDIENTE") === "PENDIENTE") ? "selected" : "" ?>>Pendiente</option>
                                    <option value="EN_CURSO" <?= (($_POST["estado_cumplimiento"] ?? "") === "EN_CURSO") ? "selected" : "" ?>>En curso</option>
                                    <option value="CUMPLIDA" <?= (($_POST["estado_cumplimiento"] ?? "") === "CUMPLIDA") ? "selected" : "" ?>>Cumplida</option>
                                    <option value="INCUMPLIDA" <?= (($_POST["estado_cumplimiento"] ?? "") === "INCUMPLIDA") ? "selected" : "" ?>>Incumplida</option>
                                </select>
                            </div>

                            <div class="form-group full">
                                <label for="observaciones">Observaciones</label>
                                <textarea
                                    id="observaciones"
                                    name="observaciones"
                                    placeholder="Observaciones relacionadas con la sanción..."
                                ><?= h($_POST["observaciones"] ?? "") ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- SANCIONES ACCESORIAS -->
                    <div class="card">
                        <h2 class="card-title">📋 Sanciones accesorias (opcional)</h2>

                        <p style="color: #6b7280; font-size: 13px; margin-bottom: 15px;">
                            Marque las sanciones accesorias que correspondan. Puede seleccionar varias.
                        </p>

                        <div class="accesorias-grid">
                            <?php foreach ($tipos_accesorias as $clave => $etiqueta): ?>
                                <?php
                                $marcada = (
                                    isset($_POST["accesorias"]) &&
                                    is_array($_POST["accesorias"]) &&
                                    in_array($clave, $_POST["accesorias"])
                                );
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
                                            <label for="detalle_<?= $clave ?>">Detalle</label>
                                            <input
                                                type="text"
                                                id="detalle_<?= $clave ?>"
                                                name="detalle_<?= $clave ?>"
                                                value="<?= h($_POST["detalle_" . $clave] ?? "") ?>"
                                                placeholder="Detalle de la accesoria"
                                            >
                                        </div>

                                        <div class="grid">
                                            <div class="form-group">
                                                <label for="fecha_inicio_<?= $clave ?>">Inicio</label>
                                                <input
                                                    type="date"
                                                    id="fecha_inicio_<?= $clave ?>"
                                                    name="fecha_inicio_<?= $clave ?>"
                                                    value="<?= h($_POST["fecha_inicio_" . $clave] ?? "") ?>"
                                                >
                                            </div>
                                            <div class="form-group">
                                                <label for="fecha_fin_<?= $clave ?>">Fin</label>
                                                <input
                                                    type="date"
                                                    id="fecha_fin_<?= $clave ?>"
                                                    name="fecha_fin_<?= $clave ?>"
                                                    value="<?= h($_POST["fecha_fin_" . $clave] ?? "") ?>"
                                                >
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- BOTONES -->
                    <div class="card">
                        <div class="acciones">
                            <a href="listado.php" class="btn btn-secondary">Cancelar</a>
                            <div class="acciones-derecha">
                                <button type="submit" class="btn btn-success">GUARDAR SANCIÓN</button>
                            </div>
                        </div>
                    </div>

                </form>

            <?php endif; ?>

        </section>
    </main>
</div>

<script>
function buscarPersona() {
    const dni = document.getElementById("dni").value.trim();
    if (dni === "") return;

    fetch("../cedulas/buscar_personal.php?dni=" + encodeURIComponent(dni))
        .then(response => response.json())
        .then(data => {
            const div = document.getElementById("infoPersona");

            if (data.error) {
                div.style.display = "none";
                div.innerHTML = "";
            } else {
                div.style.display = "block";
                div.innerHTML =
                    "<strong>Persona encontrada:</strong><br>" +
                    "Jerarquía: " + data.jerarquia + "<br>" +
                    "Apellido y nombre: " + data.apellido_nombre + "<br>" +
                    "DNI: " + data.dni + "<br>" +
                    "Dependencia: " + data.dependencia;
            }
        })
        .catch(error => {
            console.error("Error:", error);
        });
}

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
        inputDias.value = "";
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

    // Detectar si hay una cédula en la URL
    const urlParams = new URLSearchParams(window.location.search);
    const cedulaId = urlParams.get("cedula_id");
    const dni = urlParams.get("dni");

    if (cedulaId) {
        document.getElementById("cedula_id").value = cedulaId;
    }

    if (dni) {
        document.getElementById("dni").value = dni;
        buscarPersona();
    }
});
</script>

</body>

</html>