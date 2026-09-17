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

$error = "";
$exito = false;

$id = (int)($_GET["id"] ?? $_POST["id"] ?? 0);

if ($id <= 0) {
    header("Location: listado.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| BUSCAR ENCARGADO
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT id, jerarquia, apellido_nombre, telefono, activo, fecha_creacion
    FROM encargados
    WHERE id = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conexion, $sql);

if ($stmt === false) {
    die("Error SQL al preparar la consulta: " . mysqli_error($conexion));
}

mysqli_stmt_bind_param($stmt, "i", $id);

if (!mysqli_stmt_execute($stmt)) {
    die("Error al consultar el encargado: " . mysqli_stmt_error($stmt));
}

$resultado = mysqli_stmt_get_result($stmt);
$encargado = mysqli_fetch_assoc($resultado);
mysqli_stmt_close($stmt);

if (!$encargado) {
    die("El encargado solicitado no existe.");
}

// Guardar datos originales para auditorÃ­a
$datos_anteriores = [
    'id' => (int)$encargado['id'],
    'jerarquia' => $encargado['jerarquia'],
    'apellido_nombre' => $encargado['apellido_nombre'],
    'telefono' => $encargado['telefono'],
    'activo' => (int)$encargado['activo']
];

/*
|--------------------------------------------------------------------------
| PROCESAR FORMULARIO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $jerarquia = trim($_POST["jerarquia"] ?? "");
    $apellido_nombre = trim($_POST["apellido_nombre"] ?? "");
    $telefono = trim($_POST["telefono"] ?? "");
    $activo = isset($_POST["activo"]) ? (int)$_POST["activo"] : 0;

    if ($jerarquia === "") {
        $error = "Debe ingresar la jerarquÃ­a.";
    } elseif ($apellido_nombre === "") {
        $error = "Debe ingresar apellido y nombre.";
    } elseif ($telefono === "") {
        $error = "Debe ingresar el nÃºmero de telÃ©fono.";
    } elseif ($activo !== 0 && $activo !== 1) {
        $error = "El estado seleccionado no es vÃ¡lido.";
    }

    if ($error === "") {

        $sql_update = "
            UPDATE encargados
            SET jerarquia = ?, apellido_nombre = ?, telefono = ?, activo = ?
            WHERE id = ?
        ";

        $stmt_update = mysqli_prepare($conexion, $sql_update);

        if ($stmt_update === false) {

            $error = "Error SQL al preparar la actualizaciÃ³n: " . mysqli_error($conexion);

        } else {

            mysqli_stmt_bind_param($stmt_update, "sssii", $jerarquia, $apellido_nombre, $telefono, $activo, $id);

            if (mysqli_stmt_execute($stmt_update)) {

                $exito = true;

                // AUDITORÃA: EDITAR ENCARGADO
                auditar(
                    $conexion,
                    'EDITAR',
                    'encargados',
                    'encargados',
                    $id,
                    'Se editÃ³ el encargado: ' . $jerarquia . ' ' . $apellido_nombre,
                    $datos_anteriores,
                    [
                        'id' => $id,
                        'jerarquia' => $jerarquia,
                        'apellido_nombre' => $apellido_nombre,
                        'telefono' => $telefono,
                        'activo' => $activo
                    ]
                );

                // Actualizar datos mostrados
                $encargado["jerarquia"] = $jerarquia;
                $encargado["apellido_nombre"] = $apellido_nombre;
                $encargado["telefono"] = $telefono;
                $encargado["activo"] = $activo;

            } else {

                $error = "Error al actualizar el encargado: " . mysqli_stmt_error($stmt_update);
            }

            mysqli_stmt_close($stmt_update);
        }
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Editar encargado - Sistema Medidas</title>

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
.content { padding: 30px; max-width: 1000px; }
.alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
.alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.success-box { background: white; border: 1px solid #bbf7d0; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
.success-box h2 { color: #166534; margin-top: 0; }
.card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
.card-title { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
.grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
.form-group { margin-bottom: 5px; }
.full { grid-column: 1 / -1; }
label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 7px; color: #374151; }
input, select { width: 100%; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; }
input:focus, select:focus { outline: none; border-color: #2563eb; }
.info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a; padding: 14px; border-radius: 7px; margin-bottom: 20px; font-size: 14px; }
.btn { border: none; border-radius: 6px; padding: 11px 17px; cursor: pointer; font-size: 14px; font-weight: bold; text-decoration: none; display: inline-block; }
.btn-primary { background: #1d4ed8; color: white; }
.btn-primary:hover { background: #1e40af; }
.btn-secondary { background: #374151; color: white; }
.btn-secondary:hover { background: #1f2937; }
.btn-success { background: #15803d; color: white; }
.btn-success:hover { background: #166534; }
.acciones { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.acciones-derecha { display: flex; gap: 10px; }
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
            <p>GestiÃ³n de cÃ©dulas</p>
            <div class="badge-rol <?= htmlspecialchars(rolActual()) ?>">
                <?= htmlspecialchars(strtoupper(rolActual())) ?>
            </div>
        </div>
        <nav class="menu">
            <div class="menu-title">Principal</div>
            <a href="../index.php">Inicio</a>

            <?php if (tienePermiso(['admin', 'supervisor', 'operador'])): ?>
                <a href="../cedulas/nueva.php">Nueva cÃ©dula</a>
            <?php endif; ?>

            <a href="../cedulas/listado.php">CÃ©dulas</a>

            <div class="menu-title">Seguimiento</div>
            <a href="../cedulas/pendientes.php">Pendientes de diligenciar</a>
            <a href="../cedulas/diligenciamiento.php">En diligenciamiento</a>
            <a href="../cedulas/cumplimentadas.php">Cumplimentadas</a>

            <?php if (tienePermiso(['admin', 'supervisor'])): ?>
                <div class="menu-title">AdministraciÃ³n</div>
                <a href="listado.php" class="active">Encargados</a>
                <a href="../medidas/listado.php">Medidas</a>
                <a href="../medidas/tipos.php">Tipos de medida</a>
                <a href="../personas/listado.php">👥 Personas con medidas</a>
                <a href="../sanciones/listado.php">⚖️ Sanciones</a>
            <?php endif; ?>

            <?php if (esAdmin()): ?>
                <a href="../usuarios/listado.php">Usuarios</a>
                <a href="../auditoria/listado.php">ðŸ” AuditorÃ­a</a>
            <?php endif; ?>
        </nav>
        <div class="logout">
            <a href="../logout.php">Cerrar sesiÃ³n</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <h1>Editar encargado</h1>
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
                    <h2>âœ“ Encargado actualizado correctamente</h2>
                    <p>Los datos fueron modificados correctamente.</p>
                </div>
            <?php endif; ?>

            <div class="info">
                <strong>ID del encargado:</strong> <?= (int)$encargado["id"] ?>
                <br>
                Este registro podrÃ¡ ser utilizado posteriormente para asignar el diligenciamiento de una cÃ©dula.
            </div>

            <form method="POST">
                <input type="hidden" name="id" value="<?= (int)$id ?>">

                <div class="card">
                    <h2 class="card-title">Datos del encargado</h2>
                    <div class="grid">
                        <div class="form-group">
                            <label for="jerarquia">JerarquÃ­a *</label>
                            <input type="text" id="jerarquia" name="jerarquia" required value="<?= htmlspecialchars($encargado["jerarquia"]) ?>">
                        </div>
                        <div class="form-group">
                            <label for="apellido_nombre">Apellido y nombre *</label>
                            <input type="text" id="apellido_nombre" name="apellido_nombre" required value="<?= htmlspecialchars($encargado["apellido_nombre"]) ?>">
                        </div>
                        <div class="form-group">
                            <label for="telefono">NÃºmero de telÃ©fono *</label>
                            <input type="text" id="telefono" name="telefono" required value="<?= htmlspecialchars($encargado["telefono"]) ?>">
                        </div>
                        <div class="form-group">
                            <label for="activo">Estado *</label>
                            <select id="activo" name="activo" required>
                                <option value="1" <?= ((int)$encargado["activo"] === 1 ? "selected" : "") ?>>ACTIVO</option>
                                <option value="0" <?= ((int)$encargado["activo"] === 0 ? "selected" : "") ?>>INACTIVO</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="acciones">
                        <a href="listado.php" class="btn btn-secondary">Volver al listado</a>
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