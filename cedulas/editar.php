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

$cedula_id = isset($_GET["id"]) ? (int)$_GET["id"] : (int)($_POST["id"] ?? 0);

if ($cedula_id <= 0) {
    die("ID de cédula no válido.");
}

$error = "";
$exito = false;

/*
|--------------------------------------------------------------------------
| CARGAR TIPOS DE MEDIDA
|--------------------------------------------------------------------------
*/

$tipos_medida = [];
$sql_tipos = "SELECT id, nombre, categoria FROM tipos_medida WHERE activo = 1 ORDER BY categoria, nombre";
$resultado_tipos = mysqli_query($conexion, $sql_tipos);
if ($resultado_tipos) {
    while ($fila = mysqli_fetch_assoc($resultado_tipos)) {
        $tipos_medida[] = $fila;
    }
}

/*
|--------------------------------------------------------------------------
| CARGAR CARACTERIZACIONES
|--------------------------------------------------------------------------
*/

$caracterizaciones = [];
$sql_carac = "SELECT categoria, subcategoria FROM caracterizaciones ORDER BY categoria, subcategoria";
$resultado_carac = mysqli_query($conexion, $sql_carac);
if ($resultado_carac) {
    while ($fila = mysqli_fetch_assoc($resultado_carac)) {
        $caracterizaciones[] = $fila;
    }
}

$categorias = [];
foreach ($caracterizaciones as $carac) {
    $categorias[$carac['categoria']][] = $carac['subcategoria'];
}

/*
|--------------------------------------------------------------------------
| CARGAR TIPOS DE MEDIDA Y TIEMPOS
|--------------------------------------------------------------------------
*/

$tipos_medida_list = [];
$tiempos_por_tipo = [];

$sql_tiempos = "SELECT DISTINCT tipo_medida, tiempo FROM tipos_medida_tiempos WHERE activo = 1 ORDER BY tipo_medida, tiempo";
$resultado_tiempos = mysqli_query($conexion, $sql_tiempos);
if ($resultado_tiempos) {
    while ($fila = mysqli_fetch_assoc($resultado_tiempos)) {
        $tipo = $fila['tipo_medida'];
        $tiempo = $fila['tiempo'];
        if (!in_array($tipo, $tipos_medida_list)) {
            $tipos_medida_list[] = $tipo;
        }
        if (!isset($tiempos_por_tipo[$tipo])) {
            $tiempos_por_tipo[$tipo] = [];
        }
        if (!in_array($tiempo, $tiempos_por_tipo[$tipo])) {
            $tiempos_por_tipo[$tipo][] = $tiempo;
        }
    }
}

/*
|--------------------------------------------------------------------------
| CARGAR CÉDULA
|--------------------------------------------------------------------------
*/

$sql_cedula = "
    SELECT
        c.id, c.caso_id, c.fecha_ingreso, c.asunto, c.estado, c.observaciones,
        c.numero_resolucion, c.numero_hecho, c.fecha_hecho, c.dni_involucrado,
        c.caracterizacion, c.titulo_medida, c.color_semaforo, c.descripcion_hecho,
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
$resultado_cedula = mysqli_stmt_get_result($stmt_cedula);
$cedula = mysqli_fetch_assoc($resultado_cedula);
mysqli_stmt_close($stmt_cedula);

if (!$cedula) {
    die("La cédula indicada no existe.");
}

// Guardar datos originales para auditoría
$datos_anteriores = [
    'numero_caso' => $cedula['numero_caso'],
    'numero_hecho' => $cedula['numero_hecho'],
    'numero_resolucion' => $cedula['numero_resolucion'],
    'fecha_hecho' => $cedula['fecha_hecho'],
    'dni_involucrado' => $cedula['dni_involucrado'],
    'caracterizacion' => $cedula['caracterizacion'],
    'titulo_medida' => $cedula['titulo_medida'],
    'color_semaforo' => $cedula['color_semaforo'],
    'descripcion_hecho' => $cedula['descripcion_hecho']
];

/*
|--------------------------------------------------------------------------
| CARGAR INVOLUCRADOS
|--------------------------------------------------------------------------
*/

$involucrados = [];
$sql_involucrados = "
    SELECT i.id, i.jerarquia, i.apellido_nombre, i.dni, i.dependencia,
           m.tipo_medida_id, m.numero_resolucion, m.anio_resolucion
    FROM involucrados i
    LEFT JOIN medidas m ON m.involucrado_id = i.id
    WHERE i.cedula_id = ?
    ORDER BY i.id ASC
";

$stmt_inv = mysqli_prepare($conexion, $sql_involucrados);
if ($stmt_inv) {
    mysqli_stmt_bind_param($stmt_inv, "i", $cedula_id);
    mysqli_stmt_execute($stmt_inv);
    $res_inv = mysqli_stmt_get_result($stmt_inv);
    while ($fila = mysqli_fetch_assoc($res_inv)) {
        $involucrados[] = $fila;
    }
    mysqli_stmt_close($stmt_inv);
}

/*
|--------------------------------------------------------------------------
| PROCESAR FORMULARIO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $numero_hecho = trim($_POST["numero_hecho"] ?? "");
    $numero_resolucion = trim($_POST["numero_resolucion"] ?? "");
    $fecha_hecho = trim($_POST["fecha_hecho"] ?? "");
    $caracterizacion = trim($_POST["caracterizacion"] ?? "");
    $color_semaforo = trim($_POST["color_semaforo"] ?? "VERDE");
    $descripcion_hecho = trim($_POST["descripcion_hecho"] ?? "");

    if ($numero_hecho === "") {
        $error = "Debe ingresar el número de hecho.";
    } elseif ($numero_resolucion === "") {
        $error = "Debe ingresar el número de resolución.";
    } elseif ($descripcion_hecho === "") {
        $error = "Debe ingresar la descripción del hecho.";
    } else {

        mysqli_begin_transaction($conexion);

        try {

            $sql_update = "
                UPDATE cedulas
                SET numero_hecho = ?,
                    numero_resolucion = ?,
                    fecha_hecho = ?,
                    caracterizacion = ?,
                    color_semaforo = ?,
                    descripcion_hecho = ?
                WHERE id = ?
            ";

            $stmt_update = mysqli_prepare($conexion, $sql_update);

            if ($stmt_update === false) {
                throw new Exception("Error SQL al preparar la actualización: " . mysqli_error($conexion));
            }

            mysqli_stmt_bind_param(
                $stmt_update,
                "ssssssi",
                $numero_hecho,
                $numero_resolucion,
                $fecha_hecho,
                $caracterizacion,
                $color_semaforo,
                $descripcion_hecho,
                $cedula_id
            );

            if (!mysqli_stmt_execute($stmt_update)) {
                throw new Exception("Error al actualizar la cédula: " . mysqli_stmt_error($stmt_update));
            }

            mysqli_stmt_close($stmt_update);

            // Actualizar caracterización de los involucrados también
            $sql_update_inv = "
                UPDATE medidas
                SET numero_resolucion = ?
                WHERE cedula_id = ?
            ";

            $stmt_update_inv = mysqli_prepare($conexion, $sql_update_inv);
            if ($stmt_update_inv) {
                mysqli_stmt_bind_param($stmt_update_inv, "si", $numero_resolucion, $cedula_id);
                mysqli_stmt_execute($stmt_update_inv);
                mysqli_stmt_close($stmt_update_inv);
            }

            // HISTORIAL
            $accion = "EDICION DE CEDULA";
            $descripcion_historial = "Se editó la cédula del caso Nº " . $cedula["numero_caso"] . ".";

            $sql_historial = "
                INSERT INTO historial_cedula (cedula_id, usuario_id, accion, descripcion, fecha)
                VALUES (?, ?, ?, ?, NOW())
            ";

            $stmt_historial = mysqli_prepare($conexion, $sql_historial);
            mysqli_stmt_bind_param($stmt_historial, "iiss", $cedula_id, $usuario_id, $accion, $descripcion_historial);
            mysqli_stmt_execute($stmt_historial);
            mysqli_stmt_close($stmt_historial);

            // AUDITORÍA: EDITAR CÉDULA
            auditar(
                $conexion,
                'EDITAR',
                'cedulas',
                'cedulas',
                $cedula_id,
                'Se editó la cédula del caso Nº ' . $cedula["numero_caso"],
                $datos_anteriores,
                [
                    'numero_hecho' => $numero_hecho,
                    'numero_resolucion' => $numero_resolucion,
                    'fecha_hecho' => $fecha_hecho,
                    'caracterizacion' => $caracterizacion,
                    'color_semaforo' => $color_semaforo,
                    'descripcion_hecho' => $descripcion_hecho
                ]
            );

            mysqli_commit($conexion);
            $exito = true;

            // Recargar datos actualizados
            $cedula["numero_hecho"] = $numero_hecho;
            $cedula["numero_resolucion"] = $numero_resolucion;
            $cedula["fecha_hecho"] = $fecha_hecho;
            $cedula["caracterizacion"] = $caracterizacion;
            $cedula["color_semaforo"] = $color_semaforo;
            $cedula["descripcion_hecho"] = $descripcion_hecho;

        } catch (Exception $e) {
            mysqli_rollback($conexion);
            $error = $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Cédula - Sistema Medidas</title>

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
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .success-box { background: white; border: 1px solid #bbf7d0; border-radius: 10px; padding: 30px; margin-bottom: 20px; }
        .success-box h2 { color: #166534; margin-top: 0; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-title { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
        .form-group { margin-bottom: 5px; }
        .full { grid-column: 1 / -1; }
        label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 7px; color: #374151; }
        input, select, textarea { width: 100%; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #2563eb; }
        textarea { min-height: 100px; resize: vertical; }
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
        .semaforo-options { display: flex; gap: 20px; margin-bottom: 15px; }
        .semaforo-options label { display: flex; align-items: center; gap: 5px; cursor: pointer; }
        .semaforo-options input[type="radio"] { width: auto; }
        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; }
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

    <main class="main">
        <header class="topbar">
            <h1>Editar cédula</h1>
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

            <?php if ($exito): ?>
                <div class="success-box">
                    <h2>✓ Cédula editada correctamente</h2>
                    <p>Los datos de la cédula fueron actualizados.</p>
                    <br>
                    <a href="ver.php?id=<?= $cedula_id ?>" class="btn btn-primary">Ver cédula</a>
                    <a href="listado.php" class="btn btn-secondary">Volver al listado</a>
                </div>
            <?php endif; ?>

            <div class="info">
                <strong>ID de la cédula:</strong> <?= (int)$cedula["id"] ?>
                <br>
                <strong>Número de caso:</strong> <?= htmlspecialchars($cedula["numero_caso"]) ?>
                <br>
                <strong>Estado actual:</strong> <?= htmlspecialchars($cedula["estado"]) ?>
                <br>
                <br>
                ⚠️ <strong>Importante:</strong> Solo se pueden editar los campos del caso y la descripción del hecho.
                Para modificar los involucrados, medidas o asignar otro encargado, use las opciones correspondientes.
            </div>

            <form method="POST">

                <input type="hidden" name="id" value="<?= (int)$cedula_id ?>">

                <!-- DATOS DEL CASO -->
                <div class="card">
                    <h2 class="card-title">Datos del caso</h2>
                    <div class="grid">
                        <div class="form-group">
                            <label>Número de caso (no editable)</label>
                            <input type="text" value="<?= htmlspecialchars($cedula["numero_caso"]) ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>Fecha de ingreso (no editable)</label>
                            <input type="text" value="<?= date("d/m/Y H:i", strtotime($cedula["fecha_ingreso"])) ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label for="numero_hecho">Número de hecho *</label>
                            <input type="text" id="numero_hecho" name="numero_hecho" required value="<?= htmlspecialchars($cedula["numero_hecho"] ?? "") ?>">
                        </div>
                        <div class="form-group">
                            <label for="numero_resolucion">Número de resolución *</label>
                            <input type="text" id="numero_resolucion" name="numero_resolucion" required value="<?= htmlspecialchars($cedula["numero_resolucion"] ?? "") ?>">
                        </div>
                        <div class="form-group">
                            <label for="fecha_hecho">Fecha del hecho</label>
                            <input type="date" id="fecha_hecho" name="fecha_hecho" value="<?= htmlspecialchars($cedula["fecha_hecho"] ?? date("Y-m-d")) ?>">
                        </div>
                        <div class="form-group">
                            <label>DNI involucrado principal (no editable)</label>
                            <input type="text" value="<?= htmlspecialchars($cedula["dni_involucrado"] ?? "") ?>" readonly>
                        </div>
                    </div>
                </div>

                <!-- CARACTERIZACIÓN -->
                <div class="card">
                    <h2 class="card-title">Caracterización del hecho</h2>
                    <div class="form-group">
                        <label for="caracterizacion">Caracterización *</label>
                        <select id="caracterizacion" name="caracterizacion" required>
                            <option value="">Seleccione caracterización</option>
                            <?php foreach ($categorias as $categoria => $subcategorias): ?>
                                <optgroup label="<?= htmlspecialchars($categoria) ?>">
                                    <?php foreach ($subcategorias as $subcategoria): ?>
                                        <option value="<?= htmlspecialchars($subcategoria) ?>" <?= ($cedula["caracterizacion"] === $subcategoria) ? "selected" : "" ?>>
                                            <?= htmlspecialchars($subcategoria) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- MEDIDA -->
                <div class="card">
                    <h2 class="card-title">Título y medida</h2>
                    <div class="grid">
                        <div class="form-group full">
                            <label>Título de la medida (no editable)</label>
                            <input type="text" value="<?= htmlspecialchars($cedula["titulo_medida"] ?? "") ?>" readonly>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Medida</label>
                        <div class="semaforo-options">
                            <label>
                                <input type="radio" name="color_semaforo" value="VERDE" <?= ($cedula["color_semaforo"] === "VERDE") ? "checked" : "" ?>>
                                🟢 POSITIVA
                            </label>
                            <label>
                                <input type="radio" name="color_semaforo" value="AMARILLO" <?= ($cedula["color_semaforo"] === "AMARILLO") ? "checked" : "" ?>>
                                🟡 INTERMEDIA
                            </label>
                            <label>
                                <input type="radio" name="color_semaforo" value="ROJO" <?= ($cedula["color_semaforo"] === "ROJO") ? "checked" : "" ?>>
                                🔴 NEGATIVA
                            </label>
                        </div>
                    </div>
                </div>

                <!-- DESCRIPCIÓN DEL HECHO -->
                <div class="card">
                    <h2 class="card-title">Descripción del hecho</h2>
                    <div class="form-group">
                        <label for="descripcion_hecho">Descripción *</label>
                        <textarea id="descripcion_hecho" name="descripcion_hecho" required><?= htmlspecialchars($cedula["descripcion_hecho"] ?? "") ?></textarea>
                    </div>
                </div>

                <!-- INVOLUCRADOS (solo lectura) -->
                <div class="card">
                    <h2 class="card-title">Involucrados (no editables desde aquí)</h2>
                    <?php if (count($involucrados) === 0): ?>
                        <p style="color: #6b7280; font-style: italic;">No hay involucrados registrados.</p>
                    <?php else: ?>
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px;">#</th>
                                    <th style="text-align: left; padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px;">Apellido y nombre</th>
                                    <th style="text-align: left; padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px;">DNI</th>
                                    <th style="text-align: left; padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px;">Jerarquía</th>
                                    <th style="text-align: left; padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px;">Dependencia</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($involucrados as $indice => $inv): ?>
                                    <tr>
                                        <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px;"><?= $indice + 1 ?></td>
                                        <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px;"><strong><?= htmlspecialchars($inv["apellido_nombre"]) ?></strong></td>
                                        <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px;"><?= htmlspecialchars($inv["dni"] ?? "-") ?></td>
                                        <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px;"><?= htmlspecialchars($inv["jerarquia"] ?? "-") ?></td>
                                        <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px;"><?= htmlspecialchars($inv["dependencia"] ?? "-") ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- BOTONES -->
                <div class="card">
                    <div class="acciones">
                        <a href="ver.php?id=<?= $cedula_id ?>" class="btn btn-secondary">Cancelar</a>
                        <div class="acciones-derecha">
                            <button type="submit" class="btn btn-success">GUARDAR CAMBIOS</button>
                        </div>
                    </div>
                </div>

            </form>

        </section>
    </main>

</div>

</body>

</html>