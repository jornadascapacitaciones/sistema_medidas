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
$encargado_id = 0;

/*
|--------------------------------------------------------------------------
| PROCESAR FORMULARIO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $jerarquia = trim($_POST["jerarquia"] ?? "");
    $apellido_nombre = trim($_POST["apellido_nombre"] ?? "");
    $telefono = trim($_POST["telefono"] ?? "");
    $activo = isset($_POST["activo"]) ? 1 : 0;

    if ($jerarquia === "") {
        $error = "Debe ingresar la jerarquÃ­a.";
    } elseif ($apellido_nombre === "") {
        $error = "Debe ingresar apellido y nombre.";
    } elseif ($telefono === "") {
        $error = "Debe ingresar un telÃ©fono.";
    } else {

        $sql = "
            INSERT INTO encargados (jerarquia, apellido_nombre, telefono, activo)
            VALUES (?, ?, ?, ?)
        ";

        $stmt = mysqli_prepare($conexion, $sql);

        if ($stmt === false) {

            $error = "Error SQL al preparar el registro: " . mysqli_error($conexion);

        } else {

            mysqli_stmt_bind_param($stmt, "sssi", $jerarquia, $apellido_nombre, $telefono, $activo);

            if (mysqli_stmt_execute($stmt)) {

                $encargado_id = mysqli_insert_id($conexion);

                $exito = true;

                auditar(
                    $conexion,
                    'CREAR',
                    'encargados',
                    'encargados',
                    $encargado_id,
                    'Se creÃ³ un nuevo encargado: ' . $jerarquia . ' ' . $apellido_nombre,
                    null,
                    [
                        'id' => $encargado_id,
                        'jerarquia' => $jerarquia,
                        'apellido_nombre' => $apellido_nombre,
                        'telefono' => $telefono,
                        'activo' => $activo
                    ]
                );

            } else {

                $error = "Error al registrar el encargado: " . mysqli_stmt_error($stmt);
            }

            mysqli_stmt_close($stmt);
        }
    }
}

?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Nuevo encargado - Sistema Medidas</title>

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
        .content { padding: 30px; max-width: 1100px; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-title { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
        .form-group { margin-bottom: 5px; }
        .full { grid-column: 1 / -1; }
        label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 7px; color: #374151; }
        input { width: 100%; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; }
        input:focus { outline: none; border-color: #2563eb; }
        .checkbox-group { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .checkbox-group input { width: auto; }
        .checkbox-group label { margin: 0; cursor: pointer; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .success-box { background: white; border: 1px solid #bbf7d0; border-radius: 10px; padding: 30px; }
        .success-box h2 { color: #166534; margin-top: 0; }
        .success-box p { line-height: 1.6; }
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
        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; }
            .sidebar { width: 210px; }
            .main { margin-left: 210px; width: calc(100% - 210px); }
            .topbar { padding: 15px 20px; }
            .content { padding: 20px; }
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
            <h1>Nuevo encargado</h1>
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
                    <h2>âœ“ Encargado registrado correctamente</h2>
                    <p>El encargado fue incorporado correctamente al sistema.</p>
                    <p><strong>ID interno:</strong> <?= $encargado_id ?></p>
                    <p><strong>JerarquÃ­a:</strong> <?= htmlspecialchars($jerarquia) ?></p>
                    <p><strong>Apellido y nombre:</strong> <?= htmlspecialchars($apellido_nombre) ?></p>
                    <p><strong>TelÃ©fono:</strong> <?= htmlspecialchars($telefono) ?></p>
                    <br>
                    <a href="nuevo.php" class="btn btn-primary">Registrar otro encargado</a>
                    <a href="listado.php" class="btn btn-secondary">Ver encargados</a>
                </div>
            <?php else: ?>

                <div class="info">
                    <strong>InformaciÃ³n:</strong>
                    Este registro corresponde al personal que podrÃ¡ ser asignado posteriormente como encargado de diligenciar una cÃ©dula.
                </div>

                <form method="POST" autocomplete="off">
                    <div class="card">
                        <h2 class="card-title">Datos del encargado</h2>
                        <div class="grid">
                            <div class="form-group">
                                <label for="jerarquia">JerarquÃ­a *</label>
                                <input type="text" id="jerarquia" name="jerarquia" required maxlength="100" value="<?= htmlspecialchars($_POST["jerarquia"] ?? "") ?>" placeholder="Ej.: SARGENTO">
                            </div>
                            <div class="form-group">
                                <label for="apellido_nombre">Apellido y nombre *</label>
                                <input type="text" id="apellido_nombre" name="apellido_nombre" required maxlength="200" value="<?= htmlspecialchars($_POST["apellido_nombre"] ?? "") ?>" placeholder="Ej.: PÃ‰REZ JUAN CARLOS">
                            </div>
                            <div class="form-group">
                                <label for="telefono">TelÃ©fono *</label>
                                <input type="text" id="telefono" name="telefono" required maxlength="50" value="<?= htmlspecialchars($_POST["telefono"] ?? "") ?>" placeholder="Ej.: 351-1234567">
                            </div>
                            <div class="form-group">
                                <label>Estado</label>
                                <div class="checkbox-group">
                                    <input type="checkbox" id="activo" name="activo" value="1" <?= (!isset($_POST["activo"]) || isset($_POST["activo"])) ? "checked" : "" ?>>
                                    <label for="activo">Encargado activo</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="acciones">
                            <a href="listado.php" class="btn btn-secondary">Cancelar</a>
                            <div class="acciones-derecha">
                                <button type="submit" class="btn btn-success">GUARDAR ENCARGADO</button>
                            </div>
                        </div>
                    </div>
                </form>

            <?php endif; ?>
        </section>
    </main>
</div>

</body>

</html>