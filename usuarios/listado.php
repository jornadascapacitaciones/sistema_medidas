<?php

session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../permisos.php";

verificarPermiso(['admin']);

$error = "";
$usuarios = [];

/*
|--------------------------------------------------------------------------
| CONSULTAR USUARIOS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id,
        usuario,
        nombre,
        apellido,
        jerarquia,
        rol,
        activo,
        fecha_creacion
    FROM usuarios
    ORDER BY
        activo DESC,
        apellido ASC,
        nombre ASC
";

$resultado = mysqli_query($conexion, $sql);

if (!$resultado) {

    $error =
        "Error al consultar usuarios: "
        . mysqli_error($conexion);

} else {

    while ($fila = mysqli_fetch_assoc($resultado)) {

        $usuarios[] = $fila;

    }

    mysqli_free_result($resultado);
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Usuarios - Sistema Medidas</title>

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
        .card-header { display: flex; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 20px; }
        .card-header h2 { margin: 0; font-size: 18px; }
        .btn { border: none; border-radius: 6px; padding: 10px 15px; cursor: pointer; font-size: 14px; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1d4ed8; color: white; }
        .btn-primary:hover { background: #1e40af; }
        .btn-secondary { background: #374151; color: white; }
        .btn-secondary:hover { background: #1f2937; }
        .btn-warning { background: #d97706; color: white; }
        .btn-warning:hover { background: #b45309; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .tabla-contenedor { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f9fafb; color: #374151; font-size: 13px; text-align: left; padding: 13px 12px; border-bottom: 2px solid #e5e7eb; white-space: nowrap; }
        td { padding: 13px 12px; border-bottom: 1px solid #e5e7eb; font-size: 14px; vertical-align: middle; }
        tr:hover { background: #f9fafb; }
        .estado { display: inline-block; padding: 5px 9px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .estado-activo { background: #dcfce7; color: #166534; }
        .estado-inactivo { background: #fee2e2; color: #991b1b; }
        .rol-badge { display: inline-block; padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .rol-admin { background: #fee2e2; color: #991b1b; }
        .rol-supervisor { background: #cffafe; color: #0e7490; }
        .rol-operador { background: #dcfce7; color: #166534; }
        .rol-consulta { background: #e5e7eb; color: #374151; }
        .sin-registros { text-align: center; padding: 40px 20px; color: #6b7280; }
        .acciones { display: flex; gap: 7px; flex-wrap: wrap; }
        .numero { color: #6b7280; font-weight: bold; }
        @media (max-width: 900px) {
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
            <h1>Usuarios</h1>
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

            <div class="card">
                <div class="card-header">
                    <h2>Usuarios registrados</h2>
                    <a href="nuevo.php" class="btn btn-primary">+ Nuevo usuario</a>
                </div>

                <?php if (count($usuarios) === 0 && $error === ""): ?>
                    <div class="sin-registros">
                        <p>No hay usuarios registrados.</p>
                        <a href="nuevo.php" class="btn btn-primary">Registrar primer usuario</a>
                    </div>
                <?php elseif (count($usuarios) > 0): ?>

                    <div class="tabla-contenedor">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Usuario</th>
                                    <th>Apellido y nombre</th>
                                    <th>Jerarquía</th>
                                    <th>Rol</th>
                                    <th>Estado</th>
                                    <th>Fecha de alta</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usuarios as $indice => $usuario_item): ?>
                                    <?php
                                    $rol = $usuario_item['rol'] ?? 'operador';
                                    $clase_rol = 'rol-consulta';

                                    if ($rol === 'admin') $clase_rol = 'rol-admin';
                                    elseif ($rol === 'supervisor') $clase_rol = 'rol-supervisor';
                                    elseif ($rol === 'operador') $clase_rol = 'rol-operador';
                                    ?>
                                    <tr>
                                        <td class="numero"><?= $indice + 1 ?></td>
                                        <td><strong><?= htmlspecialchars($usuario_item["usuario"]) ?></strong></td>
                                        <td><?= htmlspecialchars($usuario_item["apellido"] . ", " . $usuario_item["nombre"]) ?></td>
                                        <td><?= htmlspecialchars($usuario_item["jerarquia"] ?: "-") ?></td>
                                        <td>
                                            <span class="rol-badge <?= $clase_rol ?>">
                                                <?= htmlspecialchars(strtoupper($rol)) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ((int)$usuario_item["activo"] === 1): ?>
                                                <span class="estado estado-activo">ACTIVO</span>
                                            <?php else: ?>
                                                <span class="estado estado-inactivo">INACTIVO</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date("d/m/Y H:i", strtotime($usuario_item["fecha_creacion"])) ?></td>
                                        <td>
                                            <div class="acciones">
                                                <a href="editar.php?id=<?= (int)$usuario_item["id"] ?>" class="btn btn-warning">Editar</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>
            </div>

        </section>
    </main>
</div>

</body>

</html>