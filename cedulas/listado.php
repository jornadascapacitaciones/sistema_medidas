<?php

session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../permisos.php";

verificarPermiso(['admin', 'supervisor', 'operador', 'consulta']);

/*
|--------------------------------------------------------------------------
| VARIABLES
|--------------------------------------------------------------------------
*/

$error = "";
$cedulas = [];

/*
|--------------------------------------------------------------------------
| CONSULTAR CÉDULAS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        c.id,
        c.fecha_ingreso,
        c.asunto,
        c.estado,
        c.titulo_medida,
        ca.numero_caso,
        COUNT(i.id) AS cantidad_involucrados
    FROM cedulas c

    INNER JOIN casos ca
        ON ca.id = c.caso_id

    LEFT JOIN involucrados i
        ON i.cedula_id = c.id

    GROUP BY
        c.id,
        c.fecha_ingreso,
        c.asunto,
        c.estado,
        c.titulo_medida,
        ca.numero_caso

    ORDER BY
        c.fecha_ingreso DESC,
        c.id DESC
";

$resultado = mysqli_query($conexion, $sql);

if (!$resultado) {

    $error =
        "Error al consultar las cédulas: "
        . mysqli_error($conexion);

} else {

    while ($fila = mysqli_fetch_assoc($resultado)) {

        $cedulas[] = $fila;

    }

}

/*
|--------------------------------------------------------------------------
| DATOS DEL USUARIO
|--------------------------------------------------------------------------
*/

$jerarquia = $_SESSION["jerarquia"] ?? "";
$apellido = $_SESSION["apellido"] ?? "";
$nombre = $_SESSION["nombre"] ?? "";
$usuario = $_SESSION["usuario"] ?? "";

?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Cédulas - Sistema Medidas</title>

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
        .logout a:hover { background: #7f1d1d; }
        .main { margin-left: 250px; width: calc(100% - 250px); }
        .topbar { background: white; border-bottom: 1px solid #e5e7eb; padding: 18px 30px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { margin: 0; font-size: 22px; }
        .user-info { text-align: right; font-size: 13px; }
        .user-info strong { display: block; }
        .user-info span { color: #6b7280; }
        .content { padding: 30px; max-width: 1500px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .page-header h2 { margin: 0; font-size: 21px; }
        .page-header p { margin: 6px 0 0; color: #6b7280; font-size: 13px; }
        .btn { display: inline-block; padding: 10px 15px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: bold; border: none; cursor: pointer; }
        .btn-primary { background: #1d4ed8; color: white; }
        .btn-primary:hover { background: #1e40af; }
        .btn-secondary { background: #374151; color: white; }
        .btn-secondary:hover { background: #1f2937; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th { text-align: left; background: #f9fafb; color: #374151; font-size: 12px; text-transform: uppercase; padding: 13px 12px; border-bottom: 1px solid #e5e7eb; }
        td { padding: 14px 12px; border-bottom: 1px solid #e5e7eb; font-size: 14px; vertical-align: middle; }
        tbody tr:hover { background: #f9fafb; }
        .estado { display: inline-block; padding: 6px 9px; border-radius: 20px; font-size: 11px; font-weight: bold; white-space: nowrap; }
        .estado-pendiente { background: #fef3c7; color: #92400e; }
        .estado-diligenciamiento { background: #dbeafe; color: #1e40af; }
        .estado-cumplimentada { background: #dcfce7; color: #166534; }
        .estado-otro { background: #e5e7eb; color: #374151; }
        .acciones { display: flex; gap: 7px; white-space: nowrap; }
        .btn-ver { background: #374151; color: white; padding: 8px 12px; border-radius: 5px; text-decoration: none; font-size: 12px; font-weight: bold; }
        .btn-ver:hover { background: #1f2937; }
        .btn-editar { background: #1d4ed8; color: white; padding: 8px 12px; border-radius: 5px; text-decoration: none; font-size: 12px; font-weight: bold; }
        .btn-editar:hover { background: #1e40af; }
        .sin-registros { text-align: center; padding: 50px 20px; color: #6b7280; }
        .sin-registros strong { display: block; color: #374151; font-size: 16px; margin-bottom: 8px; }
        @media (max-width: 900px) {
            .sidebar { width: 210px; }
            .main { margin-left: 210px; width: calc(100% - 210px); }
            .page-header { align-items: flex-start; flex-direction: column; gap: 15px; }
        }
    </style>

</head>

<body>

<div class="layout">

    <!-- SIDEBAR -->
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

    <!-- MAIN -->
    <main class="main">

        <header class="topbar">
            <h1>Cédulas</h1>
            <div class="user-info">
                <strong>
                    <?= htmlspecialchars(
                        trim(
                            $jerarquia
                            . " "
                            . $apellido
                            . ", "
                            . $nombre
                        )
                    ) ?>
                </strong>
                <span>Usuario: <?= htmlspecialchars($usuario) ?></span>
            </div>
        </header>

        <section class="content">

            <!-- ENCABEZADO -->
            <div class="page-header">
                <div>
                    <h2>Cédulas registradas</h2>
                    <p>Consulta general de las cédulas ingresadas al sistema.</p>
                </div>

                <?php if (tienePermiso(['admin', 'supervisor', 'operador'])): ?>
                    <a href="nueva.php" class="btn btn-primary">+ Nueva cédula</a>
                <?php endif; ?>
            </div>

            <!-- ERROR -->
            <?php if ($error !== ""): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- TABLA -->
            <div class="card">

                <?php if (count($cedulas) === 0): ?>

                    <div class="sin-registros">
                        <strong>No hay cédulas registradas</strong>
                        <span>Todavía no se ha registrado ninguna cédula en el sistema.</span>
                    </div>

                <?php else: ?>

                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Número de caso</th>
                                <th>Fecha de ingreso</th>
                                <th>Asunto</th>
                                <th>Medida</th>
                                <th>Involucrados</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($cedulas as $cedula): ?>

                            <?php
                            $estado = $cedula["estado"] ?? "";
                            $clase_estado = "estado-otro";
                            $texto_estado = $estado;

                            if ($estado === "PENDIENTE_DILIGENCIAMIENTO" || $estado === "RECIBIDA") {
                                $clase_estado = "estado-pendiente";
                                $texto_estado = "PENDIENTE";
                            } elseif ($estado === "EN_DILIGENCIAMIENTO") {
                                $clase_estado = "estado-diligenciamiento";
                                $texto_estado = "EN DILIGENCIAMIENTO";
                            } elseif ($estado === "CUMPLIMENTADA") {
                                $clase_estado = "estado-cumplimentada";
                                $texto_estado = "CUMPLIMENTADA";
                            }
                            ?>

                            <tr>
                                <td><?= (int)$cedula["id"] ?></td>

                                <td>
                                    <strong><?= htmlspecialchars($cedula["numero_caso"]) ?></strong>
                                </td>

                                <td>
                                    <?php
                                    if (!empty($cedula["fecha_ingreso"])) {
                                        echo htmlspecialchars(
                                            date(
                                                "d/m/Y H:i",
                                                strtotime($cedula["fecha_ingreso"])
                                            )
                                        );
                                    } else {
                                        echo "-";
                                    }
                                    ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($cedula["asunto"] ?? "") ?: "-" ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($cedula["titulo_medida"] ?? "") ?: "-" ?>
                                </td>

                                <td>
                                    <?= (int)$cedula["cantidad_involucrados"] ?>
                                </td>

                                <td>
                                    <span class="estado <?= htmlspecialchars($clase_estado) ?>">
                                        <?= htmlspecialchars($texto_estado) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="acciones">
                                        <a
                                            href="ver.php?id=<?= (int)$cedula["id"] ?>"
                                            class="btn-ver"
                                        >
                                            Ver
                                        </a>

                                        <?php if (tienePermiso(['admin', 'supervisor'])): ?>
                                            <a
                                                href="editar.php?id=<?= (int)$cedula["id"] ?>"
                                                class="btn-editar"
                                            >
                                                Editar
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                        </tbody>
                    </table>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

</body>

</html>