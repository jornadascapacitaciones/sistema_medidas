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

    return date('d/m/Y H:i', $timestamp);
}

$error = '';
$cedulas = [];

/*
|--------------------------------------------------------------------------
| CONSULTAR CÉDULAS PENDIENTES
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        c.id,
        c.caso_id,
        c.fecha_ingreso,
        c.asunto,
        c.estado,
        c.fecha_asignacion,
        c.fecha_cumplimentacion,
        c.observaciones,

        (
            SELECT COUNT(*)
            FROM historial_cedula h
            WHERE h.cedula_id = c.id
              AND (
                    UPPER(h.accion) LIKE '%ENCARGADO%'
                    OR UPPER(h.accion) LIKE '%ASIGN%'
                    OR UPPER(h.accion) LIKE '%TRANSFER%'
                    OR UPPER(h.accion) LIKE '%ENTREG%'
                  )
        ) AS cantidad_encargados

    FROM cedulas c

    WHERE c.estado IN (
        'RECIBIDA',
        'PENDIENTE_DILIGENCIAMIENTO',
        'EN_DILIGENCIAMIENTO'
    )

    ORDER BY
        c.fecha_ingreso ASC,
        c.id ASC
";

$resultado = mysqli_query($conexion, $sql);

if (!$resultado) {

    $error =
        'Error al consultar las cédulas pendientes: '
        . mysqli_error($conexion);

} else {

    while (
        $fila = mysqli_fetch_assoc($resultado)
    ) {

        $cedulas[] = $fila;
    }
}

/*
|--------------------------------------------------------------------------
| ESTADÍSTICAS
|--------------------------------------------------------------------------
*/

$total_pendientes = count($cedulas);

$sin_encargado = 0;
$con_encargado = 0;

foreach ($cedulas as $cedula) {

    if (
        (int)$cedula['cantidad_encargados'] > 0
    ) {

        $con_encargado++;

    } else {

        $sin_encargado++;
    }
}

?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Cédulas pendientes</title>

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
        .content { padding: 30px; max-width: 1400px; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-title { margin: 0 0 20px; font-size: 19px; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; }
        .stat-label { font-size: 12px; color: #6b7280; margin-bottom: 8px; }
        .stat-number { font-size: 28px; font-weight: bold; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 13px 12px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px; white-space: nowrap; }
        td { padding: 14px 12px; border-bottom: 1px solid #e5e7eb; font-size: 13px; vertical-align: middle; }
        tr:hover { background: #fafafa; }
        .badge { display: inline-block; padding: 6px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; white-space: nowrap; }
        .badge-pendiente { background: #fef3c7; color: #92400e; }
        .badge-encargado { background: #dcfce7; color: #166534; }
        .badge-sin-encargado { background: #fee2e2; color: #991b1b; }
        .acciones { display: flex; gap: 7px; flex-wrap: wrap; }
        .btn { display: inline-block; padding: 8px 11px; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: bold; white-space: nowrap; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-success { background: #15803d; color: white; }
        .btn-success:hover { background: #166534; }
        .btn-secondary { background: #374151; color: white; }
        .btn-secondary:hover { background: #1f2937; }
        .empty { text-align: center; padding: 50px 20px; color: #6b7280; }
        .empty-icon { font-size: 45px; margin-bottom: 15px; }
        .empty-title { font-size: 18px; font-weight: bold; color: #374151; margin-bottom: 8px; }
        .logout { position: absolute; bottom: 20px; left: 10px; right: 10px; }
        .logout a { display: block; text-align: center; padding: 11px; background: #991b1b; color: white; text-decoration: none; border-radius: 6px; }
        @media (max-width: 900px) {
            .sidebar { width: 210px; }
            .main { margin-left: 210px; width: calc(100% - 210px); }
            .stats { grid-template-columns: 1fr; }
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
            <a href="pendientes.php" class="active">Pendientes de diligenciar</a>
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
            <h1>Cédulas pendientes</h1>
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

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">TOTAL PENDIENTES</div>
                    <div class="stat-number"><?= $total_pendientes ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">SIN ENCARGADO</div>
                    <div class="stat-number"><?= $sin_encargado ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">CON ENCARGADO</div>
                    <div class="stat-number"><?= $con_encargado ?></div>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title">Cédulas que requieren seguimiento</h2>

                <?php if (count($cedulas) === 0): ?>
                    <div class="empty">
                        <div class="empty-icon">✓</div>
                        <div class="empty-title">No hay cédulas pendientes</div>
                        <div>Actualmente no existen cédulas pendientes de diligenciamiento.</div>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>CASO</th>
                                    <th>INGRESO</th>
                                    <th>ASUNTO</th>
                                    <th>ESTADO</th>
                                    <th>ENCARGADOS</th>
                                    <th>ACCIONES</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cedulas as $cedula): ?>
                                    <tr>
                                        <td><strong><?= (int)$cedula['id'] ?></strong></td>
                                        <td><?= h($cedula['caso_id']) ?></td>
                                        <td><?= fecha_argentina($cedula['fecha_ingreso']) ?></td>
                                        <td>
                                            <?php
                                            $asunto = trim($cedula['asunto'] ?? '');
                                            if (strlen($asunto) > 80) {
                                                echo h(substr($asunto, 0, 80) . '...');
                                            } else {
                                                echo nl2br(h($asunto ?: 'Sin asunto'));
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-pendiente">
                                                <?= h($cedula['estado']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ((int)$cedula['cantidad_encargados'] > 0): ?>
                                                <span class="badge badge-encargado">
                                                    <?= (int)$cedula['cantidad_encargados'] ?> encargado(s)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-sin-encargado">
                                                    Sin encargado
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="acciones">
                                                <a href="ver.php?id=<?= (int)$cedula['id'] ?>" class="btn btn-primary">Ver</a>

                                                <?php if (tienePermiso(['admin', 'supervisor', 'operador'])): ?>
                                                    <a href="asignar_encargado.php?id=<?= (int)$cedula['id'] ?>" class="btn btn-success">Asignar encargado</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2 class="card-title">Funcionamiento</h2>
                <p style="line-height:1.6; color:#4b5563;">
                    Desde esta pantalla se pueden controlar las cédulas que todavía requieren seguimiento.
                    Una cédula puede tener uno o varios encargados.
                    Si el primer encargado entrega la cédula a otra persona, se registra el nuevo encargado sin eliminar el anterior.
                </p>
                <p style="line-height:1.6; color:#4b5563;">
                    La cadena completa queda registrada en el historial y puede consultarse desde
                    <strong>Ver cédula</strong>.
                </p>
            </div>

        </section>
    </main>
</div>

</body>

</html>