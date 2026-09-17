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
$exito = "";
$accion = $_GET["accion"] ?? "";
$id = (int)($_GET["id"] ?? 0);

/*
|--------------------------------------------------------------------------
| PROCESAR ACCIONES (CREAR / EDITAR / ACTIVAR / DESACTIVAR)
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $accion_post = $_POST["accion"] ?? "";
    $id_post = (int)($_POST["id"] ?? 0);
    $nombre = trim($_POST["nombre"] ?? "");
    $categoria = trim($_POST["categoria"] ?? "INICIAL");
    $activo = isset($_POST["activo"]) ? (int)$_POST["activo"] : 1;

    $categorias_validas = ['INICIAL', 'PRORROGA', 'CAMBIO', 'LEVANTAMIENTO', 'EXCEPCIONAL'];

    if ($accion_post === "crear") {

        if ($nombre === "") {
            $error = "Debe ingresar el nombre del tipo de medida.";
        } elseif (!in_array($categoria, $categorias_validas)) {
            $error = "La categoría seleccionada no es válida.";
        } else {

            // Verificar que no exista
            $sql_check = "SELECT id FROM tipos_medida WHERE nombre = ? LIMIT 1";
            $stmt_check = mysqli_prepare($conexion, $sql_check);
            mysqli_stmt_bind_param($stmt_check, "s", $nombre);
            mysqli_stmt_execute($stmt_check);
            $res_check = mysqli_stmt_get_result($stmt_check);
            $existe = mysqli_fetch_assoc($res_check);
            mysqli_stmt_close($stmt_check);

            if ($existe) {
                $error = "Ya existe un tipo de medida con ese nombre.";
            } else {

                $sql_insert = "INSERT INTO tipos_medida (nombre, categoria, activo) VALUES (?, ?, ?)";
                $stmt_insert = mysqli_prepare($conexion, $sql_insert);
                mysqli_stmt_bind_param($stmt_insert, "ssi", $nombre, $categoria, $activo);

                if (mysqli_stmt_execute($stmt_insert)) {

                    $nuevo_id = mysqli_insert_id($conexion);
                    mysqli_stmt_close($stmt_insert);

                    auditar(
                        $conexion,
                        'CREAR',
                        'medidas',
                        'tipos_medida',
                        $nuevo_id,
                        'Se creó el tipo de medida: ' . $nombre . ' (' . $categoria . ')',
                        null,
                        [
                            'id' => $nuevo_id,
                            'nombre' => $nombre,
                            'categoria' => $categoria,
                            'activo' => $activo
                        ]
                    );

                    $exito = "Tipo de medida creado correctamente.";

                } else {
                    $error = "Error al crear: " . mysqli_stmt_error($stmt_insert);
                    mysqli_stmt_close($stmt_insert);
                }
            }
        }

    } elseif ($accion_post === "editar" && $id_post > 0) {

        if ($nombre === "") {
            $error = "Debe ingresar el nombre del tipo de medida.";
        } elseif (!in_array($categoria, $categorias_validas)) {
            $error = "La categoría seleccionada no es válida.";
        } else {

            // Obtener datos anteriores
            $sql_ant = "SELECT nombre, categoria, activo FROM tipos_medida WHERE id = ? LIMIT 1";
            $stmt_ant = mysqli_prepare($conexion, $sql_ant);
            mysqli_stmt_bind_param($stmt_ant, "i", $id_post);
            mysqli_stmt_execute($stmt_ant);
            $res_ant = mysqli_stmt_get_result($stmt_ant);
            $datos_ant = mysqli_fetch_assoc($res_ant);
            mysqli_stmt_close($stmt_ant);

            if (!$datos_ant) {
                $error = "El tipo de medida no existe.";
            } else {

                $sql_upd = "UPDATE tipos_medida SET nombre = ?, categoria = ?, activo = ? WHERE id = ?";
                $stmt_upd = mysqli_prepare($conexion, $sql_upd);
                mysqli_stmt_bind_param($stmt_upd, "ssii", $nombre, $categoria, $activo, $id_post);

                if (mysqli_stmt_execute($stmt_upd)) {

                    mysqli_stmt_close($stmt_upd);

                    auditar(
                        $conexion,
                        'EDITAR',
                        'medidas',
                        'tipos_medida',
                        $id_post,
                        'Se editó el tipo de medida: ' . $nombre,
                        $datos_ant,
                        [
                            'nombre' => $nombre,
                            'categoria' => $categoria,
                            'activo' => $activo
                        ]
                    );

                    $exito = "Tipo de medida actualizado correctamente.";

                } else {
                    $error = "Error al actualizar: " . mysqli_stmt_error($stmt_upd);
                    mysqli_stmt_close($stmt_upd);
                }
            }
        }

    } elseif ($accion_post === "toggle" && $id_post > 0) {

        // Activar/desactivar
        $sql_ant = "SELECT nombre, activo FROM tipos_medida WHERE id = ? LIMIT 1";
        $stmt_ant = mysqli_prepare($conexion, $sql_ant);
        mysqli_stmt_bind_param($stmt_ant, "i", $id_post);
        mysqli_stmt_execute($stmt_ant);
        $res_ant = mysqli_stmt_get_result($stmt_ant);
        $datos_ant = mysqli_fetch_assoc($res_ant);
        mysqli_stmt_close($stmt_ant);

        if ($datos_ant) {
            $nuevo_estado = (int)$datos_ant['activo'] === 1 ? 0 : 1;

            $sql_upd = "UPDATE tipos_medida SET activo = ? WHERE id = ?";
            $stmt_upd = mysqli_prepare($conexion, $sql_upd);
            mysqli_stmt_bind_param($stmt_upd, "ii", $nuevo_estado, $id_post);

            if (mysqli_stmt_execute($stmt_upd)) {
                mysqli_stmt_close($stmt_upd);

                auditar(
                    $conexion,
                    'EDITAR',
                    'medidas',
                    'tipos_medida',
                    $id_post,
                    'Se ' . ($nuevo_estado === 1 ? 'activó' : 'desactivó') . ' el tipo de medida: ' . $datos_ant['nombre'],
                    ['activo' => (int)$datos_ant['activo']],
                    ['activo' => $nuevo_estado]
                );

                $exito = "Estado cambiado correctamente.";
            } else {
                $error = "Error al cambiar estado: " . mysqli_stmt_error($stmt_upd);
                mysqli_stmt_close($stmt_upd);
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| CARGAR TIPO A EDITAR (si aplica)
|--------------------------------------------------------------------------
*/

$tipo_editar = null;

if ($accion === "editar" && $id > 0) {
    $sql = "SELECT * FROM tipos_medida WHERE id = ? LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $tipo_editar = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| CARGAR LISTADO DE TIPOS DE MEDIDA
|--------------------------------------------------------------------------
*/

$tipos_medida = [];

$sql_lista = "
    SELECT id, nombre, categoria, activo, fecha_creacion
    FROM tipos_medida
    ORDER BY
        activo DESC,
        categoria ASC,
        nombre ASC
";

$res_lista = mysqli_query($conexion, $sql_lista);
if ($res_lista) {
    while ($fila = mysqli_fetch_assoc($res_lista)) {
        $tipos_medida[] = $fila;
    }
}

/*
|--------------------------------------------------------------------------
| ESTADÍSTICAS
|--------------------------------------------------------------------------
*/

$total_tipos = count($tipos_medida);
$tipos_activos = 0;
$tipos_inactivos = 0;

foreach ($tipos_medida as $tipo) {
    if ((int)$tipo['activo'] === 1) {
        $tipos_activos++;
    } else {
        $tipos_inactivos++;
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Tipos de medida - Sistema Medidas</title>

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
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; }
        .stat-label { font-size: 12px; color: #6b7280; margin-bottom: 8px; text-transform: uppercase; }
        .stat-number { font-size: 28px; font-weight: bold; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; }
        .card-title { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 7px; color: #374151; }
        input, select { width: 100%; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; }
        input:focus, select:focus { outline: none; border-color: #2563eb; }
        .checkbox-group { display: flex; align-items: center; gap: 10px; }
        .checkbox-group input { width: auto; }
        .checkbox-group label { margin: 0; cursor: pointer; }
        .btn { border: none; border-radius: 6px; padding: 11px 17px; cursor: pointer; font-size: 14px; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1d4ed8; color: white; }
        .btn-primary:hover { background: #1e40af; }
        .btn-secondary { background: #374151; color: white; }
        .btn-secondary:hover { background: #1f2937; }
        .btn-success { background: #15803d; color: white; }
        .btn-success:hover { background: #166534; }
        .btn-warning { background: #d97706; color: white; }
        .btn-warning:hover { background: #b45309; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-full { width: 100%; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f9fafb; color: #374151; font-size: 12px; text-align: left; padding: 12px 10px; border-bottom: 2px solid #e5e7eb; text-transform: uppercase; }
        td { padding: 12px 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px; vertical-align: middle; }
        tr:hover { background: #f9fafb; }
        .categoria-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .cat-inicial { background: #dbeafe; color: #1e40af; }
        .cat-prorroga { background: #fef3c7; color: #92400e; }
        .cat-cambio { background: #e0e7ff; color: #4338ca; }
        .cat-levantamiento { background: #d1fae5; color: #065f46; }
        .cat-excepcional { background: #fce7f3; color: #9d174d; }
        .estado-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; }
        .estado-activo { background: #dcfce7; color: #166534; }
        .estado-inactivo { background: #fee2e2; color: #991b1b; }
        .acciones { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn-sm { padding: 6px 10px; font-size: 12px; border-radius: 5px; }
        .sin-datos { text-align: center; padding: 40px 20px; color: #6b7280; }
        @media (max-width: 1100px) {
            .grid { grid-template-columns: 1fr; }
        }
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
                <a href="listado.php">Medidas</a>
                <a href="tipos.php" class="active">Tipos de medida</a>
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
            <h1>Tipos de medida</h1>
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

            <?php if ($exito !== ""): ?>
                <div class="alert alert-success"><?= htmlspecialchars($exito) ?></div>
            <?php endif; ?>

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">Total de tipos</div>
                    <div class="stat-number"><?= $total_tipos ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Activos</div>
                    <div class="stat-number" style="color: #16a34a;"><?= $tipos_activos ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Inactivos</div>
                    <div class="stat-number" style="color: #dc2626;"><?= $tipos_inactivos ?></div>
                </div>
            </div>

            <div class="grid">

                <!-- FORMULARIO CREAR/EDITAR -->
                <div class="card">
                    <h2 class="card-title">
                        <?= $tipo_editar ? '✏️ Editar tipo de medida' : '➕ Nuevo tipo de medida' ?>
                    </h2>

                    <form method="POST">
                        <input type="hidden" name="accion" value="<?= $tipo_editar ? 'editar' : 'crear' ?>">
                        <?php if ($tipo_editar): ?>
                            <input type="hidden" name="id" value="<?= (int)$tipo_editar['id'] ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="nombre">Nombre *</label>
                            <input type="text" id="nombre" name="nombre" required maxlength="150" value="<?= htmlspecialchars($tipo_editar['nombre'] ?? '') ?>" placeholder="Ej.: SIT. PASIVA">
                        </div>

                        <div class="form-group">
                            <label for="categoria">Categoría *</label>
                            <select id="categoria" name="categoria" required>
                                <option value="INICIAL" <?= (($tipo_editar['categoria'] ?? '') === 'INICIAL') ? 'selected' : '' ?>>INICIAL</option>
                                <option value="PRORROGA" <?= (($tipo_editar['categoria'] ?? '') === 'PRORROGA') ? 'selected' : '' ?>>PRÓRROGA</option>
                                <option value="CAMBIO" <?= (($tipo_editar['categoria'] ?? '') === 'CAMBIO') ? 'selected' : '' ?>>CAMBIO</option>
                                <option value="LEVANTAMIENTO" <?= (($tipo_editar['categoria'] ?? '') === 'LEVANTAMIENTO') ? 'selected' : '' ?>>LEVANTAMIENTO</option>
                                <option value="EXCEPCIONAL" <?= (($tipo_editar['categoria'] ?? '') === 'EXCEPCIONAL') ? 'selected' : '' ?>>EXCEPCIONAL</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Estado</label>
                            <div class="checkbox-group">
                                <input type="checkbox" id="activo" name="activo" value="1" <?= (!isset($tipo_editar['activo']) || (int)$tipo_editar['activo'] === 1) ? 'checked' : '' ?>>
                                <label for="activo">Activo</label>
                            </div>
                        </div>

                        <div style="display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-success btn-full">
                                <?= $tipo_editar ? 'GUARDAR CAMBIOS' : 'CREAR TIPO' ?>
                            </button>
                        </div>

                        <?php if ($tipo_editar): ?>
                            <div style="margin-top: 10px;">
                                <a href="tipos.php" class="btn btn-secondary btn-full" style="text-align: center;">Cancelar edición</a>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- LISTADO -->
                <div class="card">
                    <h2 class="card-title">Listado de tipos de medida</h2>

                    <?php if (count($tipos_medida) === 0): ?>
                        <div class="sin-datos">
                            <p>No hay tipos de medida registrados.</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre</th>
                                        <th>Categoría</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tipos_medida as $tipo): ?>
                                        <tr>
                                            <td><?= (int)$tipo['id'] ?></td>
                                            <td><strong><?= htmlspecialchars($tipo['nombre']) ?></strong></td>
                                            <td>
                                                <?php
                                                $cat = $tipo['categoria'];
                                                $clase_cat = 'cat-inicial';
                                                if ($cat === 'PRORROGA') $clase_cat = 'cat-prorroga';
                                                elseif ($cat === 'CAMBIO') $clase_cat = 'cat-cambio';
                                                elseif ($cat === 'LEVANTAMIENTO') $clase_cat = 'cat-levantamiento';
                                                elseif ($cat === 'EXCEPCIONAL') $clase_cat = 'cat-excepcional';
                                                ?>
                                                <span class="categoria-badge <?= $clase_cat ?>">
                                                    <?= htmlspecialchars($cat) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ((int)$tipo['activo'] === 1): ?>
                                                    <span class="estado-badge estado-activo">ACTIVO</span>
                                                <?php else: ?>
                                                    <span class="estado-badge estado-inactivo">INACTIVO</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="acciones">
                                                    <a href="tipos.php?accion=editar&id=<?= (int)$tipo['id'] ?>" class="btn btn-warning btn-sm">Editar</a>

                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('¿Confirma cambiar el estado de este tipo de medida?');">
                                                        <input type="hidden" name="accion" value="toggle">
                                                        <input type="hidden" name="id" value="<?= (int)$tipo['id'] ?>">
                                                        <button type="submit" class="btn <?= (int)$tipo['activo'] === 1 ? 'btn-danger' : 'btn-success' ?> btn-sm">
                                                            <?= (int)$tipo['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </section>
    </main>
</div>

</body>

</html>