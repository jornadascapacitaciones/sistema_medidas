```php
<?php

session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config.php";

$usuario_id = (int)$_SESSION["usuario_id"];

$cedula_id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

$error = "";
$exito = false;

$cedula = null;
$involucrados = [];
$encargados = [];

/*
|--------------------------------------------------------------------------
| VALIDAR ID
|--------------------------------------------------------------------------
*/

if ($cedula_id <= 0) {
    die("Cédula no válida.");
}

/*
|--------------------------------------------------------------------------
| CARGAR CÉDULA
|--------------------------------------------------------------------------
*/

$sql_cedula = "
    SELECT
        c.id,
        c.fecha_ingreso,
        c.asunto,
        c.estado,
        c.observaciones,
        ca.id AS caso_id,
        ca.numero_caso,
        ca.descripcion AS descripcion_caso
    FROM cedulas c
    INNER JOIN casos ca
        ON ca.id = c.caso_id
    WHERE c.id = ?
    LIMIT 1
";

$stmt_cedula = mysqli_prepare(
    $conexion,
    $sql_cedula
);

if ($stmt_cedula === false) {
    die(
        "Error SQL al cargar la cédula: "
        . mysqli_error($conexion)
    );
}

mysqli_stmt_bind_param(
    $stmt_cedula,
    "i",
    $cedula_id
);

mysqli_stmt_execute($stmt_cedula);

$resultado_cedula = mysqli_stmt_get_result(
    $stmt_cedula
);

$cedula = mysqli_fetch_assoc(
    $resultado_cedula
);

mysqli_stmt_close($stmt_cedula);

if (!$cedula) {
    die("La cédula indicada no existe.");
}

/*
|--------------------------------------------------------------------------
| VERIFICAR ESTADO
|--------------------------------------------------------------------------
*/

if (
    $cedula["estado"] !==
    "PENDIENTE_DILIGENCIAMIENTO"
) {
    $error =
        "Esta cédula no se encuentra pendiente de diligenciamiento. "
        . "Estado actual: "
        . $cedula["estado"]
        . ".";
}

/*
|--------------------------------------------------------------------------
| CARGAR INVOLUCRADOS Y MEDIDAS
|--------------------------------------------------------------------------
*/

$sql_involucrados = "
    SELECT
        i.id,
        i.jerarquia,
        i.apellido_nombre,
        i.dni,
        i.dependencia,

        tm.nombre AS medida,
        tm.categoria AS categoria_medida,

        m.numero_resolucion,
        m.anio_resolucion,
        m.observaciones AS observaciones_medida

    FROM involucrados i

    LEFT JOIN medidas m
        ON m.involucrado_id = i.id
        AND m.cedula_id = i.cedula_id

    LEFT JOIN tipos_medida tm
        ON tm.id = m.tipo_medida_id

    WHERE i.cedula_id = ?

    ORDER BY i.id ASC
";

$stmt_involucrados = mysqli_prepare(
    $conexion,
    $sql_involucrados
);

if ($stmt_involucrados === false) {
    die(
        "Error SQL al cargar los involucrados: "
        . mysqli_error($conexion)
    );
}

mysqli_stmt_bind_param(
    $stmt_involucrados,
    "i",
    $cedula_id
);

mysqli_stmt_execute($stmt_involucrados);

$resultado_involucrados =
    mysqli_stmt_get_result(
        $stmt_involucrados
    );

while (
    $fila = mysqli_fetch_assoc(
        $resultado_involucrados
    )
) {
    $involucrados[] = $fila;
}

mysqli_stmt_close(
    $stmt_involucrados
);

/*
|--------------------------------------------------------------------------
| CARGAR ENCARGADOS ACTIVOS
|--------------------------------------------------------------------------
*/

$sql_encargados = "
    SELECT
        id,
        jerarquia,
        apellido_nombre,
        telefono
    FROM encargados
    WHERE activo = 1
    ORDER BY apellido_nombre ASC
";

$resultado_encargados = mysqli_query(
    $conexion,
    $sql_encargados
);

if ($resultado_encargados === false) {
    die(
        "Error al cargar los encargados: "
        . mysqli_error($conexion)
    );
}

while (
    $fila = mysqli_fetch_assoc(
        $resultado_encargados
    )
) {
    $encargados[] = $fila;
}

/*
|--------------------------------------------------------------------------
| PROCESAR ASIGNACIÓN
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && $error === ""
) {

    $encargado_id = (int)(
        $_POST["encargado_id"] ?? 0
    );

    $observaciones = trim(
        $_POST["observaciones"] ?? ""
    );

    /*
    |--------------------------------------------------------------------------
    | VALIDACIONES
    |--------------------------------------------------------------------------
    */

    if ($encargado_id <= 0) {

        $error =
            "Debe seleccionar un encargado.";
    } else {

        /*
        |--------------------------------------------------------------------------
        | VERIFICAR ENCARGADO
        |--------------------------------------------------------------------------
        */

        $sql_validar_encargado = "
            SELECT
                id,
                jerarquia,
                apellido_nombre,
                telefono
            FROM encargados
            WHERE id = ?
            AND activo = 1
            LIMIT 1
        ";

        $stmt_validar_encargado =
            mysqli_prepare(
                $conexion,
                $sql_validar_encargado
            );

        if ($stmt_validar_encargado === false) {

            $error =
                "Error SQL al validar el encargado: "
                . mysqli_error($conexion);

        } else {

            mysqli_stmt_bind_param(
                $stmt_validar_encargado,
                "i",
                $encargado_id
            );

            mysqli_stmt_execute(
                $stmt_validar_encargado
            );

            $resultado_encargado =
                mysqli_stmt_get_result(
                    $stmt_validar_encargado
                );

            $encargado =
                mysqli_fetch_assoc(
                    $resultado_encargado
                );

            mysqli_stmt_close(
                $stmt_validar_encargado
            );

            if (!$encargado) {

                $error =
                    "El encargado seleccionado no existe "
                    . "o se encuentra inactivo.";
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GUARDAR ASIGNACIÓN
    |--------------------------------------------------------------------------
    */

    if ($error === "") {

        mysqli_begin_transaction(
            $conexion
        );

        try {

            /*
            |--------------------------------------------------------------------------
            | VOLVER A COMPROBAR EL ESTADO
            |--------------------------------------------------------------------------
            */

            $sql_estado = "
                SELECT estado
                FROM cedulas
                WHERE id = ?
                LIMIT 1
            ";

            $stmt_estado = mysqli_prepare(
                $conexion,
                $sql_estado
            );

            if ($stmt_estado === false) {
                throw new Exception(
                    "Error al comprobar el estado de la cédula: "
                    . mysqli_error($conexion)
                );
            }

            mysqli_stmt_bind_param(
                $stmt_estado,
                "i",
                $cedula_id
            );

            if (
                !mysqli_stmt_execute(
                    $stmt_estado
                )
            ) {
                throw new Exception(
                    "Error al comprobar el estado: "
                    . mysqli_stmt_error(
                        $stmt_estado
                    )
                );
            }

            $resultado_estado =
                mysqli_stmt_get_result(
                    $stmt_estado
                );

            $fila_estado =
                mysqli_fetch_assoc(
                    $resultado_estado
                );

            mysqli_stmt_close(
                $stmt_estado
            );

            if (
                !$fila_estado
                || $fila_estado["estado"]
                    !== "PENDIENTE_DILIGENCIAMIENTO"
            ) {
                throw new Exception(
                    "La cédula ya no se encuentra pendiente "
                    . "de diligenciamiento."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | VERIFICAR QUE NO TENGA DILIGENCIAMIENTO ACTIVO
            |--------------------------------------------------------------------------
            */

            $sql_existente = "
                SELECT id
                FROM diligenciamientos
                WHERE cedula_id = ?
                AND estado IN (
                    'PENDIENTE',
                    'EN_DILIGENCIAMIENTO'
                )
                LIMIT 1
            ";

            $stmt_existente = mysqli_prepare(
                $conexion,
                $sql_existente
            );

            if ($stmt_existente === false) {
                throw new Exception(
                    "Error SQL al verificar asignaciones existentes: "
                    . mysqli_error($conexion)
                );
            }

            mysqli_stmt_bind_param(
                $stmt_existente,
                "i",
                $cedula_id
            );

            if (
                !mysqli_stmt_execute(
                    $stmt_existente
                )
            ) {
                throw new Exception(
                    "Error al verificar asignaciones existentes: "
                    . mysqli_stmt_error(
                        $stmt_existente
                    )
                );
            }

            $resultado_existente =
                mysqli_stmt_get_result(
                    $stmt_existente
                );

            $ya_asignada =
                mysqli_fetch_assoc(
                    $resultado_existente
                );

            mysqli_stmt_close(
                $stmt_existente
            );

            if ($ya_asignada) {
                throw new Exception(
                    "Esta cédula ya posee un diligenciamiento pendiente."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | INSERTAR DILIGENCIAMIENTO
            |--------------------------------------------------------------------------
            */

            $sql_diligenciamiento = "
                INSERT INTO diligenciamientos
                (
                    cedula_id,
                    encargado_id,
                    usuario_asignador_id,
                    fecha_asignacion,
                    estado,
                    observaciones
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    NOW(),
                    'PENDIENTE',
                    ?
                )
            ";

            $stmt_diligenciamiento =
                mysqli_prepare(
                    $conexion,
                    $sql_diligenciamiento
                );

            if ($stmt_diligenciamiento === false) {
                throw new Exception(
                    "Error SQL al preparar el diligenciamiento: "
                    . mysqli_error($conexion)
                );
            }

            mysqli_stmt_bind_param(
                $stmt_diligenciamiento,
                "iiis",
                $cedula_id,
                $encargado_id,
                $usuario_id,
                $observaciones
            );

            if (
                !mysqli_stmt_execute(
                    $stmt_diligenciamiento
                )
            ) {
                throw new Exception(
                    "Error al registrar el diligenciamiento: "
                    . mysqli_stmt_error(
                        $stmt_diligenciamiento
                    )
                );
            }

            mysqli_stmt_close(
                $stmt_diligenciamiento
            );

            /*
            |--------------------------------------------------------------------------
            | ACTUALIZAR ESTADO DE LA CÉDULA
            |--------------------------------------------------------------------------
            */

            $sql_actualizar_cedula = "
                UPDATE cedulas
                SET
                    estado = 'EN_DILIGENCIAMIENTO',
                    fecha_asignacion = NOW()
                WHERE id = ?
            ";

            $stmt_actualizar_cedula =
                mysqli_prepare(
                    $conexion,
                    $sql_actualizar_cedula
                );

            if ($stmt_actualizar_cedula === false) {
                throw new Exception(
                    "Error SQL al preparar actualización de cédula: "
                    . mysqli_error($conexion)
                );
            }

            mysqli_stmt_bind_param(
                $stmt_actualizar_cedula,
                "i",
                $cedula_id
            );

            if (
                !mysqli_stmt_execute(
                    $stmt_actualizar_cedula
                )
            ) {
                throw new Exception(
                    "Error al actualizar el estado de la cédula: "
                    . mysqli_stmt_error(
                        $stmt_actualizar_cedula
                    )
                );
            }

            mysqli_stmt_close(
                $stmt_actualizar_cedula
            );

            /*
            |--------------------------------------------------------------------------
            | HISTORIAL
            |--------------------------------------------------------------------------
            */

            $accion =
                "ASIGNACION DE DILIGENCIAMIENTO";

            $descripcion_historial =
                "Se asignó el diligenciamiento del caso Nº "
                . $cedula["numero_caso"]
                . " al encargado "
                . $encargado["jerarquia"]
                . " "
                . $encargado["apellido_nombre"]
                . ", teléfono "
                . $encargado["telefono"]
                . ".";

            if ($observaciones !== "") {

                $descripcion_historial .=
                    " Observaciones: "
                    . $observaciones
                    . ".";
            }

            $sql_historial = "
                INSERT INTO historial_cedula
                (
                    cedula_id,
                    usuario_id,
                    accion,
                    descripcion
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?
                )
            ";

            $stmt_historial =
                mysqli_prepare(
                    $conexion,
                    $sql_historial
                );

            if ($stmt_historial === false) {
                throw new Exception(
                    "Error SQL al preparar historial: "
                    . mysqli_error($conexion)
                );
            }

            mysqli_stmt_bind_param(
                $stmt_historial,
                "iiss",
                $cedula_id,
                $usuario_id,
                $accion,
                $descripcion_historial
            );

            if (
                !mysqli_stmt_execute(
                    $stmt_historial
                )
            ) {
                throw new Exception(
                    "Error al registrar el historial: "
                    . mysqli_stmt_error(
                        $stmt_historial
                    )
                );
            }

            mysqli_stmt_close(
                $stmt_historial
            );

            /*
            |--------------------------------------------------------------------------
            | CONFIRMAR
            |--------------------------------------------------------------------------
            */

            mysqli_commit(
                $conexion
            );

            $exito = true;

        } catch (Exception $e) {

            mysqli_rollback(
                $conexion
            );

            $error =
                $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Asignar diligenciamiento - Sistema Medidas
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
            background: #f3f4f6;
            color: #1f2937;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: #111827;
            color: white;
            min-height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
        }

        .sidebar-header {
            padding: 25px 20px;
            border-bottom:
                1px solid #374151;
        }

        .sidebar-header h2 {
            margin: 0;
            font-size: 20px;
        }

        .sidebar-header p {
            margin: 6px 0 0;
            color: #9ca3af;
            font-size: 12px;
        }

        .menu {
            padding: 15px 10px;
        }

        .menu-title {
            color: #6b7280;
            font-size: 11px;
            font-weight: bold;
            margin:
                15px 10px 8px;
            text-transform: uppercase;
        }

        .menu a {
            display: block;
            color: #d1d5db;
            text-decoration: none;
            padding: 11px 12px;
            border-radius: 6px;
            margin-bottom: 3px;
            font-size: 14px;
        }

        .menu a:hover {
            background: #1f2937;
            color: white;
        }

        .menu a.active {
            background: #1d4ed8;
            color: white;
        }

        .logout {
            position: absolute;
            bottom: 20px;
            left: 10px;
            right: 10px;
        }

        .logout a {
            display: block;
            text-align: center;
            padding: 11px;
            background: #991b1b;
            color: white;
            text-decoration: none;
            border-radius: 6px;
        }

        .main {
            margin-left: 250px;
            width: calc(100% - 250px);
        }

        .topbar {
            background: white;
            border-bottom:
                1px solid #e5e7eb;
            padding: 18px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .topbar h1 {
            margin: 0;
            font-size: 22px;
        }

        .user-info {
            text-align: right;
            font-size: 13px;
        }

        .user-info strong {
            display: block;
        }

        .user-info span {
            color: #6b7280;
        }

        .content {
            padding: 30px;
            max-width: 1400px;
        }

        .card {
            background: white;
            border:
                1px solid #e5e7eb;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
        }

        .card-title {
            margin:
                0 0 20px;
            font-size: 18px;
            border-bottom:
                1px solid #e5e7eb;
            padding-bottom: 12px;
        }

        .grid {
            display: grid;
            grid-template-columns:
                repeat(2, 1fr);
            gap: 18px;
        }

        .dato {
            margin-bottom: 5px;
        }

        .dato label {
            display: block;
            font-size: 12px;
            font-weight: bold;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .dato span {
            display: block;
            font-size: 15px;
        }

        .involucrado {
            border:
                1px solid #d1d5db;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            background: #fafafa;
        }

        .involucrado h3 {
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 16px;
        }

        .medida {
            margin-top: 15px;
            padding-top: 15px;
            border-top:
                1px solid #e5e7eb;
        }

        .medida strong {
            color: #1d4ed8;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border:
                1px solid #fecaca;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border:
                1px solid #bbf7d0;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 7px;
            color: #374151;
        }

        select,
        textarea {
            width: 100%;
            padding: 11px 12px;
            border:
                1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }

        select:focus,
        textarea:focus {
            outline: none;
            border-color: #2563eb;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .info-encargado {
            background: #eff6ff;
            border:
                1px solid #bfdbfe;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            display: none;
        }

        .info-encargado strong {
            color: #1d4ed8;
        }

        .acciones {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .acciones-derecha {
            display: flex;
            gap: 10px;
        }

        .btn {
            border: none;
            border-radius: 6px;
            padding: 11px 17px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #1d4ed8;
            color: white;
        }

        .btn-primary:hover {
            background: #1e40af;
        }

        .btn-secondary {
            background: #374151;
            color: white;
        }

        .btn-success {
            background: #15803d;
            color: white;
        }

        .btn-success:hover {
            background: #166534;
        }

        .success-box {
            background: white;
            border:
                1px solid #bbf7d0;
            border-radius: 10px;
            padding: 30px;
        }

        .success-box h2 {
            color: #166534;
            margin-top: 0;
        }

        @media (max-width: 900px) {

            .grid {
                grid-template-columns: 1fr;
            }

            .sidebar {
                width: 210px;
            }

            .main {
                margin-left: 210px;
                width:
                    calc(100% - 210px);
            }

        }

    </style>

</head>

<body>

<div class="layout">

    <aside class="sidebar">

        <div class="sidebar-header">

            <h2>
                SISTEMA MEDIDAS
            </h2>

            <p>
                Gestión de cédulas
            </p>

        </div>

        <nav class="menu">

            <div class="menu-title">
                Principal
            </div>

            <a href="../index.php">
                Inicio
            </a>

            <a href="nueva.php">
                Nueva cédula
            </a>

            <a href="listado.php">
                Cédulas
            </a>

            <div class="menu-title">
                Seguimiento
            </div>

            <a
                href="pendientes.php"
                class="active"
            >
                Pendientes
            </a>

            <a href="diligenciamiento.php">
                En diligenciamiento
            </a>

            <a href="cumplimentadas.php">
                Cumplimentadas
            </a>

            <div class="menu-title">
                Administración
            </div>

            <a href="../encargados/listado.php">
                Encargados
            </a>

            <a href="../usuarios/listado.php">
                Usuarios
            </a>

        </nav>

        <div class="logout">

            <a href="../logout.php">
                Cerrar sesión
            </a>

        </div>

    </aside>

    <main class="main">

        <header class="topbar">

            <h1>
                Asignar diligenciamiento
            </h1>

            <div class="user-info">

                <strong>
                    <?= htmlspecialchars(
                        ($_SESSION["jerarquia"] ?? "")
                        . " "
                        . ($_SESSION["apellido"] ?? "")
                        . ", "
                        . ($_SESSION["nombre"] ?? "")
                    ) ?>
                </strong>

                <span>
                    Usuario:
                    <?= htmlspecialchars(
                        $_SESSION["usuario"] ?? ""
                    ) ?>
                </span>

            </div>

        </header>

        <section class="content">

            <?php if ($error !== ""): ?>

                <div class="alert alert-error">

                    <?= htmlspecialchars($error) ?>

                </div>

            <?php endif; ?>


            <?php if ($exito): ?>

                <div class="success-box">

                    <h2>
                        ✓ Diligenciamiento asignado correctamente
                    </h2>

                    <p>
                        La cédula fue asignada al encargado seleccionado.
                    </p>

                    <p>
                        <strong>
                            Número de caso:
                        </strong>

                        <?= htmlspecialchars(
                            $cedula["numero_caso"]
                        ) ?>
                    </p>

                    <p>
                        <strong>
                            Encargado:
                        </strong>

                        <?= htmlspecialchars(
                            $encargado["jerarquia"]
                            . " "
                            . $encargado["apellido_nombre"]
                        ) ?>
                    </p>

                    <p>
                        <strong>
                            Teléfono:
                        </strong>

                        <?= htmlspecialchars(
                            $encargado["telefono"]
                        ) ?>
                    </p>

                    <p>
                        <strong>
                            Estado:
                        </strong>

                        EN DILIGENCIAMIENTO
                    </p>

                    <br>

                    <div class="acciones-derecha">

                        <a
                            href="pendientes.php"
                            class="btn btn-secondary"
                        >
                            Volver a pendientes
                        </a>

                        <a
                            href="ver.php?id=<?= $cedula_id ?>"
                            class="btn btn-primary"
                        >
                            Ver cédula
                        </a>

                    </div>

                </div>

            <?php else: ?>


                <div class="card">

                    <h2 class="card-title">
                        Datos del caso
                    </h2>

                    <div class="grid">

                        <div class="dato">

                            <label>
                                Número de caso
                            </label>

                            <span>
                                <?= htmlspecialchars(
                                    $cedula["numero_caso"]
                                ) ?>
                            </span>

                        </div>

                        <div class="dato">

                            <label>
                                Fecha de ingreso
                            </label>

                            <span>
                                <?= date(
                                    "d/m/Y H:i:s",
                                    strtotime(
                                        $cedula["fecha_ingreso"]
                                    )
                                ) ?>
                            </span>

                        </div>

                        <div class="dato">

                            <label>
                                Estado actual
                            </label>

                            <span>
                                PENDIENTE DE DILIGENCIAMIENTO
                            </span>

                        </div>

                        <div class="dato">

                            <label>
                                Asunto
                            </label>

                            <span>
                                <?= htmlspecialchars(
                                    $cedula["asunto"]
                                    ?: "Sin asunto"
                                ) ?>
                            </span>

                        </div>

                    </div>

                </div>


                <div class="card">

                    <h2 class="card-title">
                        Involucrados
                    </h2>

                    <?php if (
                        count($involucrados) === 0
                    ): ?>

                        <p>
                            No se encontraron involucrados.
                        </p>

                    <?php else: ?>

                        <?php foreach (
                            $involucrados
                            as $indice => $involucrado
                        ): ?>

                            <div class="involucrado">

                                <h3>
                                    Involucrado Nº
                                    <?= $indice + 1 ?>
                                </h3>

                                <div class="grid">

                                    <div class="dato">

                                        <label>
                                            Jerarquía
                                        </label>

                                        <span>
                                            <?= htmlspecialchars(
                                                $involucrado["jerarquia"]
                                                ?: "-"
                                            ) ?>
                                        </span>

                                    </div>

                                    <div class="dato">

                                        <label>
                                            Apellido y nombre
                                        </label>

                                        <span>
                                            <?= htmlspecialchars(
                                                $involucrado["apellido_nombre"]
                                            ) ?>
                                        </span>

                                    </div>

                                    <div class="dato">

                                        <label>
                                            DNI
                                        </label>

                                        <span>
                                            <?= htmlspecialchars(
                                                $involucrado["dni"]
                                                ?: "-"
                                            ) ?>
                                        </span>

                                    </div>

                                    <div class="dato">

                                        <label>
                                            Dependencia
                                        </label>

                                        <span>
                                            <?= htmlspecialchars(
                                                $involucrado["dependencia"]
                                                ?: "-"
                                            ) ?>
                                        </span>

                                    </div>

                                </div>

                                <div class="medida">

                                    <strong>
                                        Medida:
                                    </strong>

                                    <?= htmlspecialchars(
                                        $involucrado["medida"]
                                        ?: "-"
                                    ) ?>

                                    <br><br>

                                    <strong>
                                        Resolución:
                                    </strong>

                                    <?= htmlspecialchars(
                                        $involucrado["numero_resolucion"]
                                        ?: "-"
                                    ) ?>

                                    /

                                    <?= htmlspecialchars(
                                        $involucrado["anio_resolucion"]
                                        ?: "-"
                                    ) ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>


                <form
                    method="POST"
                    onsubmit="
                        return confirm(
                            '¿Está seguro de asignar esta cédula al encargado seleccionado?'
                        );
                    "
                >

                    <div class="card">

                        <h2 class="card-title">
                            Asignar encargado
                        </h2>

                        <div class="form-group">

                            <label for="encargado_id">

                                Encargado del diligenciamiento *

                            </label>

                            <select
                                name="encargado_id"
                                id="encargado_id"
                                required
                                onchange="mostrarEncargado()"
                            >

                                <option value="">
                                    Seleccione un encargado
                                </option>

                                <?php foreach (
                                    $encargados
                                    as $encargado_item
                                ): ?>

                                    <option
                                        value="<?= (int)$encargado_item["id"] ?>"
                                        data-jerarquia="<?= htmlspecialchars(
                                            $encargado_item["jerarquia"],
                                            ENT_QUOTES
                                        ) ?>"
                                        data-nombre="<?= htmlspecialchars(
                                            $encargado_item["apellido_nombre"],
                                            ENT_QUOTES
                                        ) ?>"
                                        data-telefono="<?= htmlspecialchars(
                                            $encargado_item["telefono"],
                                            ENT_QUOTES
                                        ) ?>"
                                        <?= (
                                            isset(
                                                $_POST["encargado_id"]
                                            )
                                            && (int)$_POST["encargado_id"]
                                                === (int)$encargado_item["id"]
                                        )
                                            ? "selected"
                                            : ""
                                        ?>
                                    >

                                        <?= htmlspecialchars(
                                            $encargado_item["jerarquia"]
                                            . " "
                                            . $encargado_item["apellido_nombre"]
                                        ) ?>

                                        -
                                        <?= htmlspecialchars(
                                            $encargado_item["telefono"]
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <div
                                id="infoEncargado"
                                class="info-encargado"
                            >

                                <strong>
                                    Encargado seleccionado
                                </strong>

                                <br><br>

                                <span id="datosEncargado">
                                </span>

                            </div>

                        </div>


                        <div class="form-group">

                            <label for="observaciones">

                                Observaciones de la asignación

                            </label>

                            <textarea
                                id="observaciones"
                                name="observaciones"
                                placeholder="Observaciones relacionadas con el diligenciamiento..."
                            ><?= htmlspecialchars(
                                $_POST["observaciones"] ?? ""
                            ) ?></textarea>

                        </div>

                    </div>


                    <div class="card">

                        <div class="acciones">

                            <a
                                href="pendientes.php"
                                class="btn btn-secondary"
                            >
                                Cancelar
                            </a>

                            <button
                                type="submit"
                                class="btn btn-success"
                            >
                                ASIGNAR DILIGENCIAMIENTO
                            </button>

                        </div>

                    </div>

                </form>

            <?php endif; ?>

        </section>

    </main>

</div>


<script>

function mostrarEncargado() {

    const select =
        document.getElementById(
            "encargado_id"
        );

    const info =
        document.getElementById(
            "infoEncargado"
        );

    const datos =
        document.getElementById(
            "datosEncargado"
        );

    const opcion =
        select.options[
            select.selectedIndex
        ];

    if (
        !opcion
        || !opcion.value
    ) {

        info.style.display =
            "none";

        datos.innerHTML =
            "";

        return;
    }

    const jerarquia =
        opcion.dataset.jerarquia
        || "";

    const nombre =
        opcion.dataset.nombre
        || "";

    const telefono =
        opcion.dataset.telefono
        || "";

    datos.innerHTML =
        "<strong>Jerarquía:</strong> "
        + escapeHtml(jerarquia)
        + "<br>"
        + "<strong>Nombre:</strong> "
        + escapeHtml(nombre)
        + "<br>"
        + "<strong>Teléfono:</strong> "
        + escapeHtml(telefono);

    info.style.display =
        "block";
}


function escapeHtml(texto) {

    const div =
        document.createElement(
            "div"
        );

    div.textContent =
        texto;

    return div.innerHTML;
}


document.addEventListener(
    "DOMContentLoaded",
    function() {
        mostrarEncargado();
    }
);

</script>

</body>

</html>
```
