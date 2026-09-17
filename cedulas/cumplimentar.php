<?php

session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../auditoria.php";
require_once __DIR__ . "/../permisos.php";
require_once __DIR__ . "/guardar_pdf.php";

verificarPermiso(['admin', 'supervisor', 'operador']);

$usuario_id = (int)($_SESSION["usuario_id"] ?? 0);

$cedula_id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

$error = "";
$exito = false;
$pdfs_guardados = [];

$cedula = null;
$involucrados = [];
$diligenciamiento = null;
$mensajes_whatsapp = [];

if ($cedula_id <= 0) {
    die("ID de cédula no válido.");
}

/*
|--------------------------------------------------------------------------
| CARGAR DATOS DE LA CÉDULA
|--------------------------------------------------------------------------
*/

$sql_cedula = "
    SELECT
        c.id, c.caso_id, c.fecha_ingreso, c.asunto, c.estado, c.observaciones,
        c.fecha_asignacion, c.fecha_cumplimentacion, c.numero_resolucion,
        c.numero_hecho, c.fecha_hecho, c.dni_involucrado, c.caracterizacion,
        c.titulo_medida, c.color_semaforo, c.descripcion_hecho,
        ca.numero_caso, ca.descripcion
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

/*
|--------------------------------------------------------------------------
| CARGAR INVOLUCRADOS
|--------------------------------------------------------------------------
*/

$sql_involucrados = "
    SELECT i.id, i.jerarquia, i.apellido_nombre, i.dni, i.dependencia,
           i.observaciones, m.id AS medida_id, m.tipo_medida_id,
           m.numero_resolucion, m.anio_resolucion,
           m.observaciones AS observaciones_medida,
           tm.nombre AS medida_nombre, tm.categoria AS medida_categoria
    FROM involucrados i
    LEFT JOIN medidas m ON m.involucrado_id = i.id
    LEFT JOIN tipos_medida tm ON tm.id = m.tipo_medida_id
    WHERE i.cedula_id = ?
    ORDER BY i.id ASC
";

$stmt_involucrados = mysqli_prepare($conexion, $sql_involucrados);

if ($stmt_involucrados === false) {
    die("Error SQL al cargar los involucrados: " . mysqli_error($conexion));
}

mysqli_stmt_bind_param($stmt_involucrados, "i", $cedula_id);
mysqli_stmt_execute($stmt_involucrados);
$resultado_involucrados = mysqli_stmt_get_result($stmt_involucrados);

while ($fila = mysqli_fetch_assoc($resultado_involucrados)) {
    $involucrados[] = $fila;
}

mysqli_stmt_close($stmt_involucrados);

/*
|--------------------------------------------------------------------------
| BUSCAR ENCARGADO
|--------------------------------------------------------------------------
*/

$sql_diligenciamiento = "
    SELECT d.id, d.fecha_asignacion, d.fecha_recepcion_firmada, d.estado, d.observaciones,
           e.jerarquia AS encargado_jerarquia, e.apellido_nombre AS encargado_nombre,
           e.telefono AS encargado_telefono,
           u.usuario AS usuario_asignador, u.nombre AS usuario_nombre, u.apellido AS usuario_apellido
    FROM diligenciamientos d
    INNER JOIN encargados e ON e.id = d.encargado_id
    INNER JOIN usuarios u ON u.id = d.usuario_asignador_id
    WHERE d.cedula_id = ?
    ORDER BY d.id DESC
    LIMIT 1
";

$stmt_diligenciamiento = mysqli_prepare($conexion, $sql_diligenciamiento);

if ($stmt_diligenciamiento === false) {
    die("Error SQL al cargar el diligenciamiento: " . mysqli_error($conexion));
}

mysqli_stmt_bind_param($stmt_diligenciamiento, "i", $cedula_id);
mysqli_stmt_execute($stmt_diligenciamiento);
$resultado_diligenciamiento = mysqli_stmt_get_result($stmt_diligenciamiento);
$diligenciamiento = mysqli_fetch_assoc($resultado_diligenciamiento);
mysqli_stmt_close($stmt_diligenciamiento);

if (!$diligenciamiento) {
    $sql_historial_encargado = "
        SELECT h.id, h.accion, h.descripcion, h.fecha,
               e.id AS encargado_id, e.jerarquia, e.apellido_nombre, e.telefono
        FROM historial_cedula h
        INNER JOIN encargados e ON e.id = h.encargado_nuevo_id
        WHERE h.cedula_id = ?
        AND h.encargado_nuevo_id IS NOT NULL
        ORDER BY h.fecha DESC
        LIMIT 1
    ";

    $stmt_historial_encargado = mysqli_prepare($conexion, $sql_historial_encargado);

    if ($stmt_historial_encargado) {
        mysqli_stmt_bind_param($stmt_historial_encargado, "i", $cedula_id);
        mysqli_stmt_execute($stmt_historial_encargado);
        $resultado_historial_encargado = mysqli_stmt_get_result($stmt_historial_encargado);
        $encargado_historial = mysqli_fetch_assoc($resultado_historial_encargado);
        mysqli_stmt_close($stmt_historial_encargado);

        if ($encargado_historial) {
            $diligenciamiento = [
                'id' => 0,
                'fecha_asignacion' => $encargado_historial['fecha'],
                'fecha_recepcion_firmada' => null,
                'estado' => 'EN_DILIGENCIAMIENTO',
                'observaciones' => null,
                'encargado_jerarquia' => $encargado_historial['jerarquia'],
                'encargado_nombre' => $encargado_historial['apellido_nombre'],
                'encargado_telefono' => $encargado_historial['telefono'],
                'usuario_asignador' => '',
                'usuario_nombre' => '',
                'usuario_apellido' => ''
            ];
        }
    }
}

/*
|--------------------------------------------------------------------------
| PROCESAR CUMPLIMENTACIÓN
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fecha_recepcion = trim($_POST["fecha_recepcion"] ?? "");
    $observaciones = trim($_POST["observaciones"] ?? "");

    if (!$diligenciamiento) {
        $error = "La cédula no tiene un encargado asignado.";
    } elseif ($cedula["estado"] === "CUMPLIMENTADA") {
        $error = "La cédula ya se encuentra cumplimentada.";
    } elseif ($fecha_recepcion === "") {
        $error = "Debe indicar la fecha de recepción firmada.";
    } else {

        mysqli_begin_transaction($conexion);

        try {

            $fecha_mysql = str_replace("T", " ", $fecha_recepcion);

            $sql_update_cedula = "
                UPDATE cedulas
                SET estado = 'CUMPLIMENTADA', fecha_cumplimentacion = ?
                WHERE id = ?
            ";

            $stmt_update_cedula = mysqli_prepare($conexion, $sql_update_cedula);

            if ($stmt_update_cedula === false) {
                throw new Exception("Error SQL al preparar la actualización: " . mysqli_error($conexion));
            }

            mysqli_stmt_bind_param($stmt_update_cedula, "si", $fecha_mysql, $cedula_id);

            if (!mysqli_stmt_execute($stmt_update_cedula)) {
                throw new Exception("Error al actualizar la cédula: " . mysqli_stmt_error($stmt_update_cedula));
            }

            mysqli_stmt_close($stmt_update_cedula);

            if ($diligenciamiento && $diligenciamiento['id'] > 0) {
                $sql_update_diligenciamiento = "
                    UPDATE diligenciamientos
                    SET fecha_recepcion_firmada = ?, estado = 'CUMPLIMENTADO', observaciones = ?
                    WHERE id = ?
                ";

                $stmt_update_diligenciamiento = mysqli_prepare($conexion, $sql_update_diligenciamiento);

                if ($stmt_update_diligenciamiento === false) {
                    throw new Exception("Error SQL al preparar actualización de diligenciamiento: " . mysqli_error($conexion));
                }

                mysqli_stmt_bind_param($stmt_update_diligenciamiento, "ssi", $fecha_mysql, $observaciones, $diligenciamiento['id']);

                if (!mysqli_stmt_execute($stmt_update_diligenciamiento)) {
                    throw new Exception("Error al actualizar diligenciamiento: " . mysqli_stmt_error($stmt_update_diligenciamiento));
                }

                mysqli_stmt_close($stmt_update_diligenciamiento);
            }

            $accion = "CUMPLIMENTACION DE CEDULA";
            $descripcion_historial = "Se registró la recepción firmada de la cédula correspondiente al caso Nº " . $cedula["numero_caso"] . ".";

            if ($observaciones !== "") {
                $descripcion_historial .= " Observaciones: " . $observaciones . ".";
            }

            $sql_historial = "
                INSERT INTO historial_cedula (cedula_id, usuario_id, accion, descripcion, fecha)
                VALUES (?, ?, ?, ?, NOW())
            ";

            $stmt_historial = mysqli_prepare($conexion, $sql_historial);
            mysqli_stmt_bind_param($stmt_historial, "iiss", $cedula_id, $usuario_id, $accion, $descripcion_historial);
            mysqli_stmt_execute($stmt_historial);
            mysqli_stmt_close($stmt_historial);

            $mensaje = "Se informa que la cédula correspondiente al caso Nº " . $cedula["numero_caso"] . " fue cumplimentada correctamente.";

            $sql_mensaje = "
                INSERT INTO mensajes (cedula_id, tipo, mensaje, usuario_id)
                VALUES (?, 'CUMPLIMENTACION', ?, ?)
            ";

            $stmt_mensaje = mysqli_prepare($conexion, $sql_mensaje);
            mysqli_stmt_bind_param($stmt_mensaje, "isi", $cedula_id, $mensaje, $usuario_id);
            mysqli_stmt_execute($stmt_mensaje);
            mysqli_stmt_close($stmt_mensaje);

            // AUDITORÍA: CUMPLIMENTAR CÉDULA
            auditar(
                $conexion,
                'CUMPLIMENTAR',
                'cedulas',
                'cedulas',
                $cedula_id,
                'Se cumplimentó la cédula del caso Nº ' . $cedula["numero_caso"],
                [
                    'estado' => $cedula["estado"],
                    'fecha_cumplimentacion' => null
                ],
                [
                    'estado' => 'CUMPLIMENTADA',
                    'fecha_cumplimentacion' => $fecha_mysql,
                    'observaciones' => $observaciones,
                    'encargado' => $diligenciamiento["encargado_nombre"] ?? '-'
                ]
            );

            mysqli_commit($conexion);
            $exito = true;

            $semaforo = "";
            switch ($cedula["color_semaforo"]) {
                case 'ROJO': $semaforo = "🔴"; break;
                case 'AMARILLO': $semaforo = "🟡"; break;
                case 'VERDE': $semaforo = "🟢"; break;
            }

            $fecha_hecho = date("d/m/Y", strtotime($cedula["fecha_hecho"]));
            $fecha_cumpl = date("d/m/Y", strtotime($fecha_mysql));
            $titulo_completo = $cedula["titulo_medida"];

            foreach ($involucrados as $involucrado) {
                $sql_personal = "SELECT * FROM personal_policial WHERE dni = ? LIMIT 1";
                $stmt_personal = mysqli_prepare($conexion, $sql_personal);
                mysqli_stmt_bind_param($stmt_personal, "s", $involucrado["dni"]);
                mysqli_stmt_execute($stmt_personal);
                $resultado_personal = mysqli_stmt_get_result($stmt_personal);
                $personal = mysqli_fetch_assoc($resultado_personal);
                mysqli_stmt_close($stmt_personal);

                $jerarquia = $involucrado["jerarquia"] ?? ($personal['jerarquia'] ?? '');
                $apellido_nombre = $involucrado["apellido_nombre"] ?? ($personal['apellido_nombre'] ?? '');
                $dependencia = $personal['dependencia'] ?? '';
                $sub_dependencia = $personal['sub_dependencia'] ?? '';
                $dni = $involucrado["dni"] ?? $cedula["dni_involucrado"];

                $direccion = '';
                if (!empty($sub_dependencia)) {
                    $direccion = $sub_dependencia;
                } elseif (!empty($dependencia)) {
                    $direccion = $dependencia;
                } else {
                    $direccion = '-';
                }

                $mensaje_ws = $semaforo . " *" . $titulo_completo . "*\n\n";
                $mensaje_ws .= "*1*. HECHO Nº " . $cedula["numero_hecho"] . "\n";
                $mensaje_ws .= "*2*. CASO N° " . $cedula["numero_caso"] . "\n";
                $mensaje_ws .= "*3*. RS Nº " . $cedula["numero_resolucion"] . "\n";
                $mensaje_ws .= "*4*. FECHA " . $fecha_hecho . "\n";
                $mensaje_ws .= "*5*. " . $jerarquia . " " . $apellido_nombre . "\n";
                $mensaje_ws .= "*6*. DNI Nº " . $dni . "\n";
                $mensaje_ws .= "*7*. " . $direccion . "\n";
                $mensaje_ws .= "*8*. " . $cedula["caracterizacion"] . "\n";
                $mensaje_ws .= "*9*. *CUMPLIMENTADA*\n";
                $mensaje_ws .= "*10*. FECHA CUMPLIMENTACIÓN: " . $fecha_cumpl . "\n\n";
                $mensaje_ws .= $cedula["descripcion_hecho"];

                $mensajes_whatsapp[] = [
                    'involucrado' => $apellido_nombre,
                    'mensaje' => $mensaje_ws
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | GUARDAR PDF
            |--------------------------------------------------------------------------
            */

            if (isset($_FILES['pdf_cumplimentada']) && $_FILES['pdf_cumplimentada']['error'] === UPLOAD_ERR_OK) {
                $archivo_temporal = $_FILES['pdf_cumplimentada']['tmp_name'];
                $fecha_hoy = date('d-m-y');
                $medida_abreviada = abreviar_medida($cedula["titulo_medida"]);
                $nombre_archivo_original = $_FILES['pdf_cumplimentada']['name'] ?? '';
                $tamanio_archivo = $_FILES['pdf_cumplimentada']['size'] ?? 0;
                
                $primer_pdf = null;

                foreach ($involucrados as $indice => $involucrado) {
                    $nombre_efectivo = $involucrado["apellido_nombre"] ?? '';
                    
                    if ($nombre_efectivo === '') {
                        $sql_p = "SELECT apellido_nombre FROM personal_policial WHERE dni = ? LIMIT 1";
                        $stmt_p = mysqli_prepare($conexion, $sql_p);
                        mysqli_stmt_bind_param($stmt_p, "s", $involucrado["dni"]);
                        mysqli_stmt_execute($stmt_p);
                        $res_p = mysqli_stmt_get_result($stmt_p);
                        $row_p = mysqli_fetch_assoc($res_p);
                        mysqli_stmt_close($stmt_p);
                        $nombre_efectivo = $row_p['apellido_nombre'] ?? '';
                    }
                    
                    if ($nombre_efectivo === '') continue;

                    if ($indice === 0) {
                        $resultado = guardar_pdf_involucrado(
                            $archivo_temporal, $nombre_efectivo, $medida_abreviada,
                            'CUMPLIMENTADA', $fecha_hoy, false
                        );
                        
                        if ($resultado['exito']) {
                            $primer_pdf = $resultado['ruta'];
                            $pdfs_guardados[] = [
                                'involucrado' => $nombre_efectivo,
                                'nombre_sugerido' => $resultado['nombre_sugerido']
                            ];
                            
                            auditar(
                                $conexion,
                                'SUBIR_PDF',
                                'archivos',
                                'archivos',
                                $cedula_id,
                                'Se subió el PDF de la cédula CUMPLIMENTADA para ' . $nombre_efectivo,
                                null,
                                [
                                    'cedula_id' => $cedula_id,
                                    'involucrado' => $nombre_efectivo,
                                    'nombre_original' => $nombre_archivo_original,
                                    'nombre_guardado' => $resultado['nombre_sugerido'],
                                    'ruta' => $resultado['ruta_relativa'],
                                    'tamanio_bytes' => $tamanio_archivo,
                                    'estado' => 'CUMPLIMENTADA'
                                ]
                            );
                        }
                    } else {
                        if ($primer_pdf) {
                            $resultado = guardar_pdf_involucrado(
                                $primer_pdf, $nombre_efectivo, $medida_abreviada,
                                'CUMPLIMENTADA', $fecha_hoy, true
                            );
                            
                            if ($resultado['exito']) {
                                $pdfs_guardados[] = [
                                    'involucrado' => $nombre_efectivo,
                                    'nombre_sugerido' => $resultado['nombre_sugerido']
                                ];
                                
                                auditar(
                                    $conexion,
                                    'SUBIR_PDF',
                                    'archivos',
                                    'archivos',
                                    $cedula_id,
                                    'Se copió el PDF de la cédula CUMPLIMENTADA para ' . $nombre_efectivo,
                                    null,
                                    [
                                        'cedula_id' => $cedula_id,
                                        'involucrado' => $nombre_efectivo,
                                        'nombre_guardado' => $resultado['nombre_sugerido'],
                                        'ruta' => $resultado['ruta_relativa'],
                                        'estado' => 'CUMPLIMENTADA'
                                    ]
                                );
                            }
                        }
                    }
                }
            }

        } catch (Exception $e) {
            mysqli_rollback($conexion);
            $error = $e->getMessage();
            
            auditar(
                $conexion,
                'ERROR',
                'cedulas',
                'cedulas',
                $cedula_id,
                'Error al cumplimentar cédula: ' . $e->getMessage(),
                null,
                [
                    'cedula_id' => $cedula_id,
                    'numero_caso' => $cedula["numero_caso"] ?? '',
                    'error' => $e->getMessage()
                ]
            );
        }
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cumplimentar Cédula - Sistema Medidas</title>

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
        .card-title { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
        .dato { padding: 12px; background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; }
        .dato-label { display: block; font-size: 11px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin-bottom: 5px; }
        .dato-valor { font-size: 14px; color: #111827; }
        .involucrado { border: 1px solid #d1d5db; border-radius: 8px; padding: 20px; margin-bottom: 18px; background: #fafafa; }
        .involucrado-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .involucrado-header h3 { margin: 0; font-size: 16px; }
        .medida { margin-top: 18px; padding-top: 18px; border-top: 1px solid #e5e7eb; }
        .medida h4 { margin: 0 0 12px; color: #1d4ed8; }
        .encargado-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 20px; }
        .encargado-box h3 { margin-top: 0; color: #1e40af; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .success-box { background: white; border: 1px solid #bbf7d0; border-radius: 10px; padding: 30px; }
        .success-box h2 { color: #166534; margin-top: 0; }
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 7px; color: #374151; }
        input, textarea { width: 100%; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; }
        textarea { min-height: 100px; resize: vertical; }
        input:focus, textarea:focus { outline: none; border-color: #2563eb; }
        .btn { border: none; border-radius: 6px; padding: 11px 17px; cursor: pointer; font-size: 14px; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1d4ed8; color: white; }
        .btn-secondary { background: #374151; color: white; }
        .btn-success { background: #15803d; color: white; }
        .btn-whatsapp { background: #25D366; color: white; }
        .btn-whatsapp:hover { background: #1da851; }
        .acciones { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .estado { display: inline-block; padding: 6px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .estado-pendiente { background: #fef3c7; color: #92400e; }
        .estado-cumplimentada { background: #dcfce7; color: #166534; }
        .mensaje-whatsapp { background: #f0fdf4; border: 2px solid #bbf7d0; border-radius: 10px; padding: 20px; margin-top: 20px; }
        .mensaje-whatsapp h3 { color: #166534; margin-top: 0; }
        .mensaje-item { border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 10px; background: #fafafa; }
        .mensaje-item h4 { margin: 0 0 10px; color: #1e40af; }
        .aviso-flotante { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: #15803d; color: white; padding: 15px 25px; border-radius: 8px; font-weight: bold; font-size: 14px; z-index: 9999; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
        .pdf-info { background: #fef3c7; border: 2px solid #fcd34d; border-radius: 8px; padding: 15px; margin-top: 15px; }
        .pdf-info strong { color: #92400e; }
        .pdf-info code { background: #fef9c3; padding: 3px 8px; border-radius: 4px; font-size: 14px; font-weight: bold; color: #92400e; }
        @media (max-width: 900px) {
            .grid, .grid-3 { grid-template-columns: 1fr; }
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

            <a href="listado.php">Cédulas</a>

            <div class="menu-title">Seguimiento</div>
            <a href="pendientes.php">Pendientes de diligenciar</a>
            <a href="diligenciamiento.php">En diligenciamiento</a>
            <a href="cumplimentadas.php" class="active">Cumplimentadas</a>

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
            <h1>Cumplimentar cédula</h1>
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
                    <h2>✓ Cédula cumplimentada correctamente</h2>
                    <p>La recepción firmada fue registrada correctamente.</p>
                    <p><strong>Número de caso:</strong> <?= htmlspecialchars($cedula["numero_caso"]) ?></p>
                    <p><strong>Fecha de recepción:</strong> <?= date("d/m/Y H:i", strtotime($fecha_mysql)) ?></p>
                    <p><strong>Estado:</strong> <span class="estado estado-cumplimentada">CUMPLIMENTADA</span></p>

                    <?php if (count($pdfs_guardados) > 0): ?>
                        <div class="pdf-info">
                            <strong>📄 Nombres de archivo sugeridos:</strong>
                            <p>Renombre el PDF con el siguiente nombre:</p>
                            <?php foreach ($pdfs_guardados as $pdf): ?>
                                <div style="margin-top: 10px;">
                                    <strong><?= htmlspecialchars($pdf['involucrado']) ?>:</strong><br>
                                    <code><?= htmlspecialchars($pdf['nombre_sugerido']) ?></code>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mensaje-whatsapp">
                        <h3>📱 Mensajes para WhatsApp</h3>
                        <?php foreach ($mensajes_whatsapp as $item): ?>
                            <div class="mensaje-item">
                                <h4>Involucrado: <?= htmlspecialchars($item['involucrado']) ?></h4>
                                <p style="white-space: pre-wrap;"><?= htmlspecialchars($item['mensaje']) ?></p>
                                <div style="display: flex; gap: 10px; margin-top: 10px;">
                                    <button type="button" class="btn btn-whatsapp" onclick="copiarMensaje(this)">📋 Copiar mensaje</button>
                                    <a href="https://wa.me/?text=<?= urlencode($item['mensaje']) ?>" target="_blank" class="btn btn-whatsapp">📱 Enviar por WhatsApp</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <br>
                    <a href="ver.php?id=<?= $cedula_id ?>" class="btn btn-primary">Ver cédula</a>
                    <a href="cumplimentadas.php" class="btn btn-secondary">Ver cumplimentadas</a>
                </div>
            <?php else: ?>

                <div class="card">
                    <h2 class="card-title">Datos del caso</h2>
                    <div class="grid">
                        <div class="dato">
                            <span class="dato-label">Número de caso</span>
                            <span class="dato-valor"><?= htmlspecialchars($cedula["numero_caso"]) ?></span>
                        </div>
                        <div class="dato">
                            <span class="dato-label">Fecha de ingreso</span>
                            <span class="dato-valor"><?= date("d/m/Y H:i", strtotime($cedula["fecha_ingreso"])) ?></span>
                        </div>
                        <div class="dato">
                            <span class="dato-label">Asunto</span>
                            <span class="dato-valor"><?= htmlspecialchars($cedula["asunto"] ?: "-") ?></span>
                        </div>
                        <div class="dato">
                            <span class="dato-label">Estado actual</span>
                            <span class="dato-valor"><span class="estado estado-pendiente"><?= htmlspecialchars($cedula["estado"]) ?></span></span>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2 class="card-title">Encargado del diligenciamiento</h2>
                    <?php if ($diligenciamiento): ?>
                        <div class="encargado-box">
                            <h3><?= htmlspecialchars($diligenciamiento["encargado_jerarquia"] . " " . $diligenciamiento["encargado_nombre"]) ?></h3>
                            <p><strong>Teléfono:</strong> <?= htmlspecialchars($diligenciamiento["encargado_telefono"]) ?></p>
                            <p><strong>Fecha de asignación:</strong> <?= date("d/m/Y H:i", strtotime($diligenciamiento["fecha_asignacion"])) ?></p>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-error">Esta cédula no tiene un encargado registrado en el sistema.</div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h2 class="card-title">Involucrados y medidas</h2>
                    <?php foreach ($involucrados as $indice => $involucrado): ?>
                        <div class="involucrado">
                            <div class="involucrado-header">
                                <h3>Involucrado Nº <?= $indice + 1 ?></h3>
                            </div>
                            <div class="grid-3">
                                <div class="dato">
                                    <span class="dato-label">Jerarquía</span>
                                    <span class="dato-valor"><?= htmlspecialchars($involucrado["jerarquia"] ?: "-") ?></span>
                                </div>
                                <div class="dato">
                                    <span class="dato-label">Apellido y nombre</span>
                                    <span class="dato-valor"><?= htmlspecialchars($involucrado["apellido_nombre"]) ?></span>
                                </div>
                                <div class="dato">
                                    <span class="dato-label">DNI</span>
                                    <span class="dato-valor"><?= htmlspecialchars($involucrado["dni"] ?: "-") ?></span>
                                </div>
                                <div class="dato">
                                    <span class="dato-label">Dependencia</span>
                                    <span class="dato-valor"><?= htmlspecialchars($involucrado["dependencia"] ?: "-") ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($diligenciamiento): ?>
                    <form method="POST" class="card" enctype="multipart/form-data">
                        <h2 class="card-title">Registrar recepción firmada</h2>
                        
                        <div class="form-group">
                            <label for="fecha_recepcion">Fecha y hora de recepción firmada *</label>
                            <input type="datetime-local" id="fecha_recepcion" name="fecha_recepcion" value="<?= htmlspecialchars($_POST["fecha_recepcion"] ?? date("Y-m-d\TH:i")) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="observaciones">Observaciones de la cumplimentación</label>
                            <textarea id="observaciones" name="observaciones" placeholder="Ingrese observaciones si corresponde..."><?= htmlspecialchars($_POST["observaciones"] ?? "") ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="pdf_cumplimentada">📄 Subir PDF de la cédula cumplimentada (solo PDF)</label>
                            <input type="file" id="pdf_cumplimentada" name="pdf_cumplimentada" accept=".pdf,application/pdf">
                            <small style="display: block; margin-top: 8px; color: #6b7280;">
                                El archivo se guardará en las carpetas de cada involucrado como "CUMPLIMENTADA".
                            </small>
                        </div>

                        <div class="acciones">
                            <a href="ver.php?id=<?= $cedula_id ?>" class="btn btn-secondary">Cancelar</a>
                            <button type="submit" class="btn btn-success" onclick="return confirm('¿Confirma que la cédula fue recibida y cumplimentada correctamente?')">REGISTRAR CUMPLIMENTACIÓN</button>
                        </div>
                    </form>
                <?php endif; ?>

            <?php endif; ?>

        </section>
    </main>
</div>

<script>
function copiarMensaje(boton) {
    const mensajeElement = boton.closest('.mensaje-item').querySelector('p');
    const mensaje = mensajeElement.textContent;
    
    const textarea = document.createElement("textarea");
    textarea.value = mensaje;
    textarea.style.position = "fixed";
    textarea.style.opacity = "0";
    document.body.appendChild(textarea);
    textarea.select();
    textarea.setSelectionRange(0, 99999);
    
    try {
        const exitoso = document.execCommand("copy");
        mostrarAviso(exitoso ? "¡Mensaje copiado al portapapeles!" : "No se pudo copiar el mensaje.");
    } catch (err) {
        console.error("Error al copiar:", err);
        alert("Error al copiar el mensaje");
    }
    document.body.removeChild(textarea);
}

function mostrarAviso(mensaje) {
    const aviso = document.createElement("div");
    aviso.textContent = mensaje;
    aviso.className = "aviso-flotante";
    document.body.appendChild(aviso);
    setTimeout(function() {
        aviso.style.opacity = "0";
        setTimeout(function() { document.body.removeChild(aviso); }, 300);
    }, 3000);
}
</script>

</body>
</html>