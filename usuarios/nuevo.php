<?php

session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../auditoria.php";
require_once __DIR__ . "/../permisos.php";

verificarPermiso(['admin']);

$error = "";
$exito = false;
$usuario_id_creado = 0;

/*
|--------------------------------------------------------------------------
| PROCESAR FORMULARIO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $usuario = trim($_POST["usuario"] ?? "");
    $password = $_POST["password"] ?? "";
    $password_confirm = $_POST["password_confirm"] ?? "";
    $nombre = trim($_POST["nombre"] ?? "");
    $apellido = trim($_POST["apellido"] ?? "");
    $jerarquia = trim($_POST["jerarquia"] ?? "");
    $rol = trim($_POST["rol"] ?? "operador");
    $activo = isset($_POST["activo"]) ? 1 : 0;

    $roles_validos = ['admin', 'supervisor', 'operador', 'consulta'];

    if ($usuario === "") {
        $error = "Debe ingresar un nombre de usuario.";
    } elseif ($password === "") {
        $error = "Debe ingresar una contraseña.";
    } elseif (strlen($password) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres.";
    } elseif ($password !== $password_confirm) {
        $error = "Las contraseñas no coinciden.";
    } elseif ($nombre === "") {
        $error = "Debe ingresar el nombre.";
    } elseif ($apellido === "") {
        $error = "Debe ingresar el apellido.";
    } elseif (!in_array($rol, $roles_validos)) {
        $error = "El rol seleccionado no es válido.";
    } else {

        $sql_verificar = "SELECT id FROM usuarios WHERE usuario = ? LIMIT 1";
        $stmt_verificar = mysqli_prepare($conexion, $sql_verificar);

        if ($stmt_verificar === false) {
            $error = "Error SQL al verificar usuario: " . mysqli_error($conexion);
        } else {
            mysqli_stmt_bind_param($stmt_verificar, "s", $usuario);
            mysqli_stmt_execute($stmt_verificar);
            $resultado_verificar = mysqli_stmt_get_result($stmt_verificar);
            $usuario_existente = mysqli_fetch_assoc($resultado_verificar);
            mysqli_stmt_close($stmt_verificar);

            if ($usuario_existente) {
                $error = "El nombre de usuario '" . $usuario . "' ya se encuentra registrado.";
            } else {

                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $sql = "
                    INSERT INTO usuarios (usuario, password, nombre, apellido, jerarquia, rol, activo)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ";

                $stmt = mysqli_prepare($conexion, $sql);

                if ($stmt === false) {
                    $error = "Error SQL al preparar el registro: " . mysqli_error($conexion);
                } else {
                    mysqli_stmt_bind_param($stmt, "ssssssi", $usuario, $password_hash, $nombre, $apellido, $jerarquia, $rol, $activo);

                    if (mysqli_stmt_execute($stmt)) {

                        $usuario_id_creado = mysqli_insert_id($conexion);
                        $exito = true;

                        // AUDITORÍA: CREAR USUARIO
                        auditar(
                            $conexion,
                            'CREAR',
                            'usuarios',
                            'usuarios',
                            $usuario_id_creado,
                            'Se creó un nuevo usuario: ' . $usuario . ' (' . $apellido . ', ' . $nombre . ') - Rol: ' . $rol,
                            null,
                            [
                                'id' => $usuario_id_creado,
                                'usuario' => $usuario,
                                'nombre' => $nombre,
                                'apellido' => $apellido,
                                'jerarquia' => $jerarquia,
                                'rol' => $rol,
                                'activo' => $activo
                            ]
                        );

                    } else {
                        $error = "Error al registrar el usuario: " . mysqli_stmt_error($stmt);
                    }

                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Nuevo usuario - Sistema Medidas</title>

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
        input, select { width: 100%; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; }
        input:focus, select:focus { outline: none; border-color: #2563eb; }
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
        .info-rol { background: #fef3c7; border: 1px solid #fcd34d; color: #92400e; padding: 12px; border-radius: 7px; margin-top: 8px; font-size: 12px; line-height: 1.5; }
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
                <a href="listado.php" class="active">Usuarios</a>
                <a href="../auditoria/listado.php">🔍 Auditoría</a>
            <?php endif; ?>
        </nav>
        <div class="logout">
            <a href="../logout.php">Cerrar sesión</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <h1>Nuevo usuario</h1>
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
                    <h2>✓ Usuario registrado correctamente</h2>
                    <p>El usuario fue creado correctamente.</p>
                    <p><strong>ID interno:</strong> <?= $usuario_id_creado ?></p>
                    <p><strong>Usuario:</strong> <?= htmlspecialchars($usuario) ?></p>
                    <p><strong>Nombre completo:</strong> <?= htmlspecialchars($apellido . ", " . $nombre) ?></p>
                    <p><strong>Jerarquía:</strong> <?= htmlspecialchars($jerarquia ?: "-") ?></p>
                    <p><strong>Rol:</strong> <?= htmlspecialchars(strtoupper($rol)) ?></p>
                    <br>
                    <a href="nuevo.php" class="btn btn-primary">Registrar otro usuario</a>
                    <a href="listado.php" class="btn btn-secondary">Ver usuarios</a>
                </div>
            <?php else: ?>

                <div class="info">
                    <strong>Información:</strong>
                    Este registro corresponde al personal que podrá acceder al sistema para gestionar cédulas, involucrados, medidas y diligenciamientos.
                </div>

                <form method="POST" autocomplete="off">
                    <div class="card">
                        <h2 class="card-title">Datos del usuario</h2>
                        <div class="grid">
                            <div class="form-group">
                                <label for="usuario">Nombre de usuario *</label>
                                <input type="text" id="usuario" name="usuario" required maxlength="50" value="<?= htmlspecialchars($_POST["usuario"] ?? "") ?>" placeholder="Ej.: jperez">
                            </div>
                            <div class="form-group">
                                <label for="jerarquia">Jerarquía</label>
                                <input type="text" id="jerarquia" name="jerarquia" maxlength="100" value="<?= htmlspecialchars($_POST["jerarquia"] ?? "") ?>" placeholder="Ej.: SARGENTO">
                            </div>
                            <div class="form-group">
                                <label for="password">Contraseña *</label>
                                <input type="password" id="password" name="password" required minlength="6" placeholder="Mínimo 6 caracteres">
                            </div>
                            <div class="form-group">
                                <label for="password_confirm">Confirmar contraseña *</label>
                                <input type="password" id="password_confirm" name="password_confirm" required minlength="6" placeholder="Repita la contraseña">
                            </div>
                            <div class="form-group">
                                <label for="nombre">Nombre *</label>
                                <input type="text" id="nombre" name="nombre" required maxlength="100" value="<?= htmlspecialchars($_POST["nombre"] ?? "") ?>" placeholder="Ej.: JUAN">
                            </div>
                            <div class="form-group">
                                <label for="apellido">Apellido *</label>
                                <input type="text" id="apellido" name="apellido" required maxlength="100" value="<?= htmlspecialchars($_POST["apellido"] ?? "") ?>" placeholder="Ej.: PÉREZ">
                            </div>
                            <div class="form-group full">
                                <label for="rol">Rol del usuario *</label>
                                <select id="rol" name="rol" required>
                                    <option value="consulta" <?= (($_POST["rol"] ?? "") === "consulta") ? "selected" : "" ?>>CONSULTA - Solo puede ver</option>
                                    <option value="operador" <?= (($_POST["rol"] ?? "") === "operador") ? "selected" : "" ?>>OPERADOR - Puede crear cédulas</option>
                                    <option value="supervisor" <?= (($_POST["rol"] ?? "") === "supervisor") ? "selected" : "" ?>>SUPERVISOR - Puede editar cédulas y encargados</option>
                                    <option value="admin" <?= (($_POST["rol"] ?? "") === "admin") ? "selected" : "" ?>>ADMIN - Control total</option>
                                </select>
                                <div class="info-rol">
                                    <strong>Roles disponibles:</strong><br>
                                    <strong>CONSULTA:</strong> Solo lectura del sistema<br>
                                    <strong>OPERADOR:</strong> Crear cédulas, asignar encargados, cumplimentar<br>
                                    <strong>SUPERVISOR:</strong> Todo lo del operador + editar cédulas y encargados<br>
                                    <strong>ADMIN:</strong> Control total incluyendo usuarios y auditoría
                                </div>
                            </div>
                            <div class="form-group full">
                                <label>Estado</label>
                                <div class="checkbox-group">
                                    <input type="checkbox" id="activo" name="activo" value="1" <?= (!isset($_POST["activo"]) || isset($_POST["activo"])) ? "checked" : "" ?>>
                                    <label for="activo">Usuario activo</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="acciones">
                            <a href="listado.php" class="btn btn-secondary">Cancelar</a>
                            <div class="acciones-derecha">
                                <button type="submit" class="btn btn-success">GUARDAR USUARIO</button>
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