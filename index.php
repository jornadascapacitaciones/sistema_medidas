<?php

session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/permisos.php";

verificarPermiso(['admin', 'supervisor', 'operador', 'consulta']);

$usuario_id = (int)$_SESSION["usuario_id"];
$rol_actual = rolActual();

/*
|--------------------------------------------------------------------------
| DATOS DEL USUARIO
|--------------------------------------------------------------------------
*/

$jerarquia = $_SESSION["jerarquia"] ?? "";
$apellido = $_SESSION["apellido"] ?? "";
$nombre = $_SESSION["nombre"] ?? "";
$usuario = $_SESSION["usuario"] ?? "";

/*
|--------------------------------------------------------------------------
| CONTADORES
|--------------------------------------------------------------------------
*/

$total_cedulas = 0;
$pendientes = 0;
$en_diligenciamiento = 0;
$cumplimentadas = 0;
$total_encargados = 0;
$total_usuarios = 0;
$creadas_hoy = 0;
$cumplimentadas_semana = 0;

$sql_total = "SELECT COUNT(*) AS total FROM cedulas";
$resultado = mysqli_query($conexion, $sql_total);
if ($resultado) {
    $fila = mysqli_fetch_assoc($resultado);
    $total_cedulas = (int)$fila["total"];
}

$sql_pendientes = "SELECT COUNT(*) AS total FROM cedulas WHERE estado IN ('RECIBIDA', 'PENDIENTE_DILIGENCIAMIENTO')";
$resultado = mysqli_query($conexion, $sql_pendientes);
if ($resultado) {
    $fila = mysqli_fetch_assoc($resultado);
    $pendientes = (int)$fila["total"];
}

$sql_diligenciamiento = "SELECT COUNT(*) AS total FROM cedulas WHERE estado = 'EN_DILIGENCIAMIENTO'";
$resultado = mysqli_query($conexion, $sql_diligenciamiento);
if ($resultado) {
    $fila = mysqli_fetch_assoc($resultado);
    $en_diligenciamiento = (int)$fila["total"];
}

$sql_cumplimentadas = "SELECT COUNT(*) AS total FROM cedulas WHERE estado = 'CUMPLIMENTADA'";
$resultado = mysqli_query($conexion, $sql_cumplimentadas);
if ($resultado) {
    $fila = mysqli_fetch_assoc($resultado);
    $cumplimentadas = (int)$fila["total"];
}

$sql_hoy = "SELECT COUNT(*) AS total FROM cedulas WHERE DATE(fecha_ingreso) = CURDATE()";
$resultado = mysqli_query($conexion, $sql_hoy);
if ($resultado) {
    $fila = mysqli_fetch_assoc($resultado);
    $creadas_hoy = (int)$fila["total"];
}

$sql_semana = "SELECT COUNT(*) AS total FROM cedulas WHERE estado = 'CUMPLIMENTADA' AND YEARWEEK(fecha_cumplimentacion, 1) = YEARWEEK(CURDATE(), 1)";
$resultado = mysqli_query($conexion, $sql_semana);
if ($resultado) {
    $fila = mysqli_fetch_assoc($resultado);
    $cumplimentadas_semana = (int)$fila["total"];
}

$sql_encargados = "SELECT COUNT(*) AS total FROM encargados WHERE activo = 1";
$resultado = mysqli_query($conexion, $sql_encargados);
if ($resultado) {
    $fila = mysqli_fetch_assoc($resultado);
    $total_encargados = (int)$fila["total"];
}

$sql_usuarios = "SELECT COUNT(*) AS total FROM usuarios";
$resultado = mysqli_query($conexion, $sql_usuarios);
if ($resultado) {
    $fila = mysqli_fetch_assoc($resultado);
    $total_usuarios = (int)$fila["total"];
}

/*
|--------------------------------------------------------------------------
| ÚLTIMAS CÉDULAS
|--------------------------------------------------------------------------
*/

$ultimas_cedulas = [];

$sql_ultimas = "
    SELECT c.id, c.fecha_ingreso, c.estado, c.titulo_medida, c.fecha_cumplimentacion, ca.numero_caso
    FROM cedulas c
    INNER JOIN casos ca ON ca.id = c.caso_id
    ORDER BY c.fecha_ingreso DESC
    LIMIT 5
";

$resultado = mysqli_query($conexion, $sql_ultimas);
if ($resultado) {
    while ($fila = mysqli_fetch_assoc($resultado)) {
        $ultimas_cedulas[] = $fila;
    }
}

/*
|--------------------------------------------------------------------------
| PORCENTAJES
|--------------------------------------------------------------------------
*/

$porcentaje_cumplimentadas = 0;
$porcentaje_diligenciamiento = 0;
$porcentaje_pendientes = 0;

if ($total_cedulas > 0) {
    $porcentaje_cumplimentadas = round(($cumplimentadas / $total_cedulas) * 100, 1);
    $porcentaje_diligenciamiento = round(($en_diligenciamiento / $total_cedulas) * 100, 1);
    $porcentaje_pendientes = round(($pendientes / $total_cedulas) * 100, 1);
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Inicio - Sistema Medidas</title>

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
        .content { padding: 30px; max-width: 1400px; }
        .welcome { background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%); color: white; border-radius: 10px; padding: 30px; margin-bottom: 25px; }
        .welcome h2 { margin: 0 0 8px; font-size: 24px; }
        .welcome p { margin: 0; opacity: 0.95; font-size: 14px; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 25px; }
        .stat-card { background: white; border-radius: 10px; padding: 22px; border-left: 5px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: transform 0.15s, box-shadow 0.15s; text-decoration: none; color: inherit; display: block; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .stat-card.pendientes { border-left-color: #f59e0b; background: linear-gradient(to right, #fffbeb 0%, white 30%); }
        .stat-card.diligenciamiento { border-left-color: #3b82f6; background: linear-gradient(to right, #eff6ff 0%, white 30%); }
        .stat-card.cumplimentadas { border-left-color: #10b981; background: linear-gradient(to right, #ecfdf5 0%, white 30%); }
        .stat-card.total { border-left-color: #374151; background: linear-gradient(to right, #f3f4f6 0%, white 30%); }
        .stat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .stat-icon { font-size: 28px; }
        .stat-badge { font-size: 11px; padding: 3px 8px; border-radius: 20px; font-weight: bold; }
        .stat-card.pendientes .stat-badge { background: #fef3c7; color: #92400e; }
        .stat-card.diligenciamiento .stat-badge { background: #dbeafe; color: #1e40af; }
        .stat-card.cumplimentadas .stat-badge { background: #d1fae5; color: #065f46; }
        .stat-card.total .stat-badge { background: #e5e7eb; color: #374151; }
        .stat-title { color: #6b7280; font-size: 13px; margin-bottom: 8px; font-weight: 600; }
        .stat-number { font-size: 36px; font-weight: bold; color: #111827; line-height: 1; margin-bottom: 5px; }
        .stat-subtitle { color: #9ca3af; font-size: 12px; }
        .progress-card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 25px; }
        .progress-card h3 { margin: 0 0 20px; font-size: 16px; color: #374151; display: flex; align-items: center; gap: 10px; }
        .progress-bar-container { width: 100%; height: 30px; background: #f3f4f6; border-radius: 15px; overflow: hidden; display: flex; margin-bottom: 15px; }
        .progress-segment { height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: bold; transition: width 0.3s; min-width: 0; }
        .progress-segment.cumplimentadas { background: #10b981; }
        .progress-segment.diligenciamiento { background: #3b82f6; }
        .progress-segment.pendientes { background: #f59e0b; }
        .progress-legend { display: flex; gap: 25px; flex-wrap: wrap; font-size: 13px; }
        .legend-item { display: flex; align-items: center; gap: 8px; }
        .legend-color { width: 14px; height: 14px; border-radius: 3px; }
        .legend-color.verde { background: #10b981; }
        .legend-color.azul { background: #3b82f6; }
        .legend-color.amarillo { background: #f59e0b; }
        .section { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 25px; }
        .section h2 { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; display: flex; align-items: center; gap: 10px; }
        .ultimas-lista { display: flex; flex-direction: column; gap: 10px; }
        .ultima-item { display: flex; align-items: center; gap: 15px; padding: 15px; background: #fafafa; border-radius: 8px; border-left: 4px solid #e5e7eb; text-decoration: none; color: inherit; transition: background 0.15s; }
        .ultima-item:hover { background: #f3f4f6; }
        .ultima-item.pendiente { border-left-color: #f59e0b; }
        .ultima-item.diligenciamiento { border-left-color: #3b82f6; }
        .ultima-item.cumplimentada { border-left-color: #10b981; }
        .ultima-icono { font-size: 24px; }
        .ultima-info { flex: 1; }
        .ultima-titulo { font-weight: bold; font-size: 14px; margin-bottom: 4px; }
        .ultima-detalle { font-size: 12px; color: #6b7280; }
        .ultima-estado { padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: bold; white-space: nowrap; }
        .ultima-estado.pendiente { background: #fef3c7; color: #92400e; }
        .ultima-estado.diligenciamiento { background: #dbeafe; color: #1e40af; }
        .ultima-estado.cumplimentada { background: #d1fae5; color: #065f46; }
        .quick-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
        .action { border: 1px solid #d1d5db; border-radius: 8px; padding: 20px; text-decoration: none; color: #1f2937; background: #fafafa; transition: 0.15s; }
        .action:hover { background: #f3f4f6; border-color: #2563eb; transform: translateY(-2px); }
        .action-title { font-weight: bold; margin-bottom: 7px; font-size: 15px; display: flex; align-items: center; gap: 8px; }
        .action-description { color: #6b7280; font-size: 13px; line-height: 1.4; }
        .info-hoy { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .info-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; text-align: center; }
        .info-card .numero { font-size: 32px; font-weight: bold; color: #1d4ed8; margin-bottom: 5px; }
        .info-card .texto { color: #6b7280; font-size: 13px; }
        @media (max-width: 1100px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            .quick-actions { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 900px) {
            .sidebar { width: 210px; }
            .main { margin-left: 210px; width: calc(100% - 210px); }
        }
        @media (max-width: 700px) {
            .stats, .quick-actions, .info-hoy { grid-template-columns: 1fr; }
            .topbar { padding: 15px 20px; }
            .content { padding: 20px; }
            .progress-legend { flex-direction: column; gap: 10px; }
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
            <div class="badge-rol <?= htmlspecialchars($rol_actual) ?>">
                <?= htmlspecialchars(strtoupper($rol_actual)) ?>
            </div>
        </div>

        <nav class="menu">
            <div class="menu-title">Principal</div>
            <a href="index.php" class="active">Inicio</a>

            <?php if (tienePermiso(['admin', 'supervisor', 'operador'])): ?>
                <a href="cedulas/nueva.php">Nueva cédula</a>
            <?php endif; ?>

            <a href="cedulas/listado.php">Cédulas</a>

            <div class="menu-title">Seguimiento</div>
            <a href="cedulas/pendientes.php">Pendientes de diligenciar</a>
            <a href="cedulas/diligenciamiento.php">En diligenciamiento</a>
            <a href="cedulas/cumplimentadas.php">Cumplimentadas</a>

            <?php if (tienePermiso(['admin', 'supervisor'])): ?>
                <div class="menu-title">Administración</div>
                <a href="encargados/listado.php">Encargados</a>
                <a href="medidas/listado.php">Medidas</a>
                <a href="medidas/tipos.php">Tipos de medida</a>
                <a href="personas/listado.php">👥 Personas con medidas</a>
                <a href="sanciones/listado.php">⚖️ Sanciones</a>
            <?php endif; ?>

            <?php if (esAdmin()): ?>
                <a href="usuarios/listado.php">Usuarios</a>
                <a href="auditoria/listado.php">🔍 Auditoría</a>
            <?php endif; ?>
        </nav>

        <div class="logout">
            <a href="logout.php">Cerrar sesión</a>
        </div>
    </aside>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main">

        <header class="topbar">
            <h1>Inicio</h1>
            <div class="user-info">
                <strong>
                    <?= htmlspecialchars(trim($jerarquia . " " . $apellido . ", " . $nombre)) ?>
                </strong>
                <span>Usuario: <?= htmlspecialchars($usuario) ?> · Rol: <strong><?= htmlspecialchars(strtoupper($rol_actual)) ?></strong></span>
            </div>
        </header>

        <section class="content">

            <div class="welcome">
                <h2>¡Bienvenido al Sistema de Medidas!</h2>
                <p>
                    Desde este panel puede gestionar las cédulas, involucrados,
                    medidas, encargados y seguimiento del diligenciamiento.
                </p>
            </div>

            <div class="stats">

                <a href="cedulas/pendientes.php" class="stat-card pendientes">
                    <div class="stat-header">
                        <div class="stat-icon">🟡</div>
                        <span class="stat-badge"><?= $porcentaje_pendientes ?>%</span>
                    </div>
                    <div class="stat-title">Pendientes de diligenciar</div>
                    <div class="stat-number"><?= $pendientes ?></div>
                    <div class="stat-subtitle">Requieren asignación</div>
                </a>

                <a href="cedulas/diligenciamiento.php" class="stat-card diligenciamiento">
                    <div class="stat-header">
                        <div class="stat-icon">🔵</div>
                        <span class="stat-badge"><?= $porcentaje_diligenciamiento ?>%</span>
                    </div>
                    <div class="stat-title">En diligenciamiento</div>
                    <div class="stat-number"><?= $en_diligenciamiento ?></div>
                    <div class="stat-subtitle">En proceso actualmente</div>
                </a>

                <a href="cedulas/cumplimentadas.php" class="stat-card cumplimentadas">
                    <div class="stat-header">
                        <div class="stat-icon">🟢</div>
                        <span class="stat-badge"><?= $porcentaje_cumplimentadas ?>%</span>
                    </div>
                    <div class="stat-title">Cumplimentadas</div>
                    <div class="stat-number"><?= $cumplimentadas ?></div>
                    <div class="stat-subtitle">Finalizadas correctamente</div>
                </a>

                <div class="stat-card total">
                    <div class="stat-header">
                        <div class="stat-icon">📊</div>
                        <span class="stat-badge">TOTAL</span>
                    </div>
                    <div class="stat-title">Total de cédulas</div>
                    <div class="stat-number"><?= $total_cedulas ?></div>
                    <div class="stat-subtitle">Registradas en el sistema</div>
                </div>

            </div>

            <?php if ($total_cedulas > 0): ?>
                <div class="progress-card">
                    <h3>📈 Progreso general del sistema</h3>
                    <div class="progress-bar-container">
                        <?php if ($cumplimentadas > 0): ?>
                            <div class="progress-segment cumplimentadas" style="width: <?= $porcentaje_cumplimentadas ?>%;">
                                <?php if ($porcentaje_cumplimentadas > 5): ?><?= $porcentaje_cumplimentadas ?>%<?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($en_diligenciamiento > 0): ?>
                            <div class="progress-segment diligenciamiento" style="width: <?= $porcentaje_diligenciamiento ?>%;">
                                <?php if ($porcentaje_diligenciamiento > 5): ?><?= $porcentaje_diligenciamiento ?>%<?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($pendientes > 0): ?>
                            <div class="progress-segment pendientes" style="width: <?= $porcentaje_pendientes ?>%;">
                                <?php if ($porcentaje_pendientes > 5): ?><?= $porcentaje_pendientes ?>%<?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="progress-legend">
                        <div class="legend-item">
                            <div class="legend-color verde"></div>
                            <span><strong><?= $cumplimentadas ?></strong> Cumplimentadas (<?= $porcentaje_cumplimentadas ?>%)</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color azul"></div>
                            <span><strong><?= $en_diligenciamiento ?></strong> En diligenciamiento (<?= $porcentaje_diligenciamiento ?>%)</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color amarillo"></div>
                            <span><strong><?= $pendientes ?></strong> Pendientes (<?= $porcentaje_pendientes ?>%)</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (count($ultimas_cedulas) > 0): ?>
                <div class="section">
                    <h2>🕐 Últimas cédulas registradas</h2>
                    <div class="ultimas-lista">
                        <?php foreach ($ultimas_cedulas as $ultima): ?>
                            <?php
                            $estado = $ultima['estado'];
                            $clase_estado = 'pendiente';
                            $icono = '🟡';
                            $texto_estado = 'PENDIENTE';

                            if ($estado === 'EN_DILIGENCIAMIENTO') {
                                $clase_estado = 'diligenciamiento';
                                $icono = '🔵';
                                $texto_estado = 'EN DILIGENCIAMIENTO';
                            } elseif ($estado === 'CUMPLIMENTADA') {
                                $clase_estado = 'cumplimentada';
                                $icono = '🟢';
                                $texto_estado = 'CUMPLIMENTADA';
                            }
                            ?>
                            <a href="cedulas/ver.php?id=<?= (int)$ultima['id'] ?>" class="ultima-item <?= $clase_estado ?>">
                                <div class="ultima-icono"><?= $icono ?></div>
                                <div class="ultima-info">
                                    <div class="ultima-titulo">
                                        Caso Nº <?= htmlspecialchars($ultima['numero_caso']) ?>
                                        - <?= htmlspecialchars($ultima['titulo_medida'] ?: 'Sin medida') ?>
                                    </div>
                                    <div class="ultima-detalle">
                                        Ingreso: <?= date("d/m/Y H:i", strtotime($ultima['fecha_ingreso'])) ?>
                                        <?php if ($ultima['fecha_cumplimentacion']): ?>
                                            · Cumplimentada: <?= date("d/m/Y", strtotime($ultima['fecha_cumplimentacion'])) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="ultima-estado <?= $clase_estado ?>">
                                    <?= $texto_estado ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="section">
                <h2>📅 Actividad reciente</h2>
                <div class="info-hoy">
                    <div class="info-card">
                        <div class="numero"><?= $creadas_hoy ?></div>
                        <div class="texto">Cédulas creadas hoy</div>
                    </div>
                    <div class="info-card">
                        <div class="numero"><?= $cumplimentadas_semana ?></div>
                        <div class="texto">Cumplimentadas esta semana</div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h2>⚡ Accesos rápidos</h2>
                <div class="quick-actions">

                    <?php if (tienePermiso(['admin', 'supervisor', 'operador'])): ?>
                        <a href="cedulas/nueva.php" class="action">
                            <div class="action-title">➕ Nueva cédula</div>
                            <div class="action-description">
                                Registrar una nueva cédula, su caso, involucrados y medidas.
                            </div>
                        </a>
                    <?php endif; ?>

                    <a href="cedulas/pendientes.php" class="action">
                        <div class="action-title">🟡 Pendientes de diligenciar</div>
                        <div class="action-description">
                            Consultar las cédulas pendientes de asignar encargado.
                        </div>
                    </a>

                    <a href="cedulas/listado.php" class="action">
                        <div class="action-title">📋 Ver todas las cédulas</div>
                        <div class="action-description">
                            Consultar todas las cédulas registradas en el sistema.
                        </div>
                    </a>

                    <a href="cedulas/diligenciamiento.php" class="action">
                        <div class="action-title">🔵 En diligenciamiento</div>
                        <div class="action-description">
                            Consultar las cédulas que actualmente se encuentran en proceso.
                        </div>
                    </a>

                    <a href="cedulas/cumplimentadas.php" class="action">
                        <div class="action-title">🟢 Cumplimentadas</div>
                        <div class="action-description">
                            Consultar las cédulas que ya finalizaron su diligenciamiento.
                        </div>
                    </a>

                    <?php if (tienePermiso(['admin', 'supervisor'])): ?>
                        <a href="encargados/listado.php" class="action">
                            <div class="action-title">👮 Encargados</div>
                            <div class="action-description">
                                Administrar los encargados responsables del diligenciamiento.
                            </div>
                        </a>
                    <?php endif; ?>

                </div>
            </div>

            <div class="section">
                <h2>ℹ️ Información del sistema</h2>
                <div class="info-hoy">
                    <div class="info-card">
                        <div class="numero"><?= $total_encargados ?></div>
                        <div class="texto">Encargados activos</div>
                    </div>
                    <div class="info-card">
                        <div class="numero"><?= $total_usuarios ?></div>
                        <div class="texto">Usuarios registrados</div>
                    </div>
                </div>
            </div>

        </section>

    </main>

</div>

</body>

</html>