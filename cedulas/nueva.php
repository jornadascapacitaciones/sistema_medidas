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

$error = "";
$exito = false;
$cedula_id_creada = 0;
$pdfs_guardados = [];

$tipos_medida = [];
$caracterizaciones = [];
$tipos_medida_tiempos = [];

/*
|--------------------------------------------------------------------------
| CARGAR TIPOS DE MEDIDA (para involucrados adicionales)
|--------------------------------------------------------------------------
*/

$sql_tipos = "SELECT id, nombre, categoria FROM tipos_medida WHERE activo = 1 ORDER BY categoria, nombre";
$resultado_tipos = mysqli_query($conexion, $sql_tipos);
if ($resultado_tipos) {
    while ($fila = mysqli_fetch_assoc($resultado_tipos)) {
        $tipos_medida[] = $fila;
    }
}

/*
|--------------------------------------------------------------------------
| CARGAR CARACTERIZACIONES
|--------------------------------------------------------------------------
*/

$sql_caracterizaciones = "SELECT categoria, subcategoria FROM caracterizaciones ORDER BY categoria, subcategoria";
$resultado_caracterizaciones = mysqli_query($conexion, $sql_caracterizaciones);
if ($resultado_caracterizaciones) {
    while ($fila = mysqli_fetch_assoc($resultado_caracterizaciones)) {
        $caracterizaciones[] = $fila;
    }
}

$categorias = [];
foreach ($caracterizaciones as $carac) {
    $categorias[$carac['categoria']][] = $carac['subcategoria'];
}

/*
|--------------------------------------------------------------------------
| CARGAR TIPOS DE MEDIDA Y TIEMPOS
|--------------------------------------------------------------------------
*/

$sql_tiempos = "SELECT DISTINCT tipo_medida, tiempo FROM tipos_medida_tiempos WHERE activo = 1 ORDER BY tipo_medida, tiempo";
$resultado_tiempos = mysqli_query($conexion, $sql_tiempos);
if ($resultado_tiempos) {
    while ($fila = mysqli_fetch_assoc($resultado_tiempos)) {
        $tipos_medida_tiempos[] = $fila;
    }
}

$tipos_medida_list = [];
$tiempos_por_tipo = [];
foreach ($tipos_medida_tiempos as $item) {
    $tipo = $item['tipo_medida'];
    $tiempo = $item['tiempo'];
    if (!in_array($tipo, $tipos_medida_list)) {
        $tipos_medida_list[] = $tipo;
    }
    if (!isset($tiempos_por_tipo[$tipo])) {
        $tiempos_por_tipo[$tipo] = [];
    }
    if (!in_array($tiempo, $tiempos_por_tipo[$tipo])) {
        $tiempos_por_tipo[$tipo][] = $tiempo;
    }
}

/*
|--------------------------------------------------------------------------
| PROCESAR FORMULARIO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $numero_caso = trim($_POST["numero_caso"] ?? "");
    $numero_hecho = trim($_POST["numero_hecho"] ?? "");
    $numero_resolucion = trim($_POST["numero_resolucion"] ?? "");
    $tipo_medida = trim($_POST["tipo_medida"] ?? "");
    $tiempo = trim($_POST["tiempo"] ?? "");
    $fecha_hecho = trim($_POST["fecha_hecho"] ?? "");
    $dni_involucrado = trim($_POST["dni_involucrado"] ?? "");
    $caracterizacion = trim($_POST["caracterizacion"] ?? "");
    $titulo_medida = trim($_POST["titulo_medida"] ?? "");
    $color_semaforo = trim($_POST["color_semaforo"] ?? "VERDE");
    $descripcion_hecho = trim($_POST["descripcion_hecho"] ?? "");

    $involucrados = $_POST["involucrados"] ?? [];

    if ($numero_caso === "") {
        $error = "Debe ingresar el número de caso.";
    } elseif ($dni_involucrado === "") {
        $error = "Debe ingresar el DNI del involucrado.";
    } elseif ($numero_hecho === "") {
        $error = "Debe ingresar el número de hecho.";
    } elseif ($numero_resolucion === "") {
        $error = "Debe ingresar el número de resolución.";
    } elseif ($titulo_medida === "") {
        $error = "Debe ingresar el título de la medida.";
    } elseif ($descripcion_hecho === "") {
        $error = "Debe ingresar la descripción del hecho.";
    } else {

        $sql_personal = "SELECT * FROM personal_policial WHERE dni = ? LIMIT 1";
        $stmt_personal = mysqli_prepare($conexion, $sql_personal);
        mysqli_stmt_bind_param($stmt_personal, "s", $dni_involucrado);
        mysqli_stmt_execute($stmt_personal);
        $resultado_personal = mysqli_stmt_get_result($stmt_personal);
        $personal = mysqli_fetch_assoc($resultado_personal);
        mysqli_stmt_close($stmt_personal);

        if (!$personal) {
            $error = "No se encontró personal con el DNI ingresado.";
        } else {

            $datos_involucrados = [];

            if (is_array($involucrados)) {
                foreach ($involucrados as $indice => $involucrado) {
                    $jerarquia = trim($involucrado["jerarquia"] ?? "");
                    $apellido_nombre = trim($involucrado["apellido_nombre"] ?? "");
                    $dni = trim($involucrado["dni"] ?? "");
                    $dependencia = trim($involucrado["dependencia"] ?? "");
                    $tipo_medida_id = (int)($involucrado["tipo_medida_id"] ?? 0);
                    $numero_resolucion_involucrado = trim($involucrado["numero_resolucion"] ?? "");
                    $anio_resolucion = trim($involucrado["anio_resolucion"] ?? "");
                    $observaciones_medida = trim($involucrado["observaciones_medida"] ?? "");

                    if ($apellido_nombre === "" && $dni === "" && $tipo_medida_id === 0) {
                        continue;
                    }
                    if ($apellido_nombre === "") {
                        $error = "Debe ingresar apellido y nombre del involucrado adicional.";
                        break;
                    }
                    if ($dni === "") {
                        $error = "Debe ingresar el DNI del involucrado adicional.";
                        break;
                    }
                    if ($tipo_medida_id <= 0) {
                        $error = "Debe seleccionar una medida para el involucrado adicional.";
                        break;
                    }

                    $datos_involucrados[] = [
                        "jerarquia" => $jerarquia,
                        "apellido_nombre" => $apellido_nombre,
                        "dni" => $dni,
                        "dependencia" => $dependencia,
                        "tipo_medida_id" => $tipo_medida_id,
                        "numero_resolucion" => $numero_resolucion_involucrado,
                        "anio_resolucion" => $anio_resolucion !== "" ? (int)$anio_resolucion : null,
                        "observaciones_medida" => $observaciones_medida
                    ];
                }
            }

            if ($error === "") {

                mysqli_begin_transaction($conexion);

                try {
                    $sql_caso = "SELECT id FROM casos WHERE numero_caso = ? LIMIT 1";
                    $stmt_caso = mysqli_prepare($conexion, $sql_caso);
                    mysqli_stmt_bind_param($stmt_caso, "s", $numero_caso);
                    mysqli_stmt_execute($stmt_caso);
                    $resultado_caso = mysqli_stmt_get_result($stmt_caso);
                    $caso_existente = mysqli_fetch_assoc($resultado_caso);
                    mysqli_stmt_close($stmt_caso);

                    if ($caso_existente) {
                        $caso_id = (int)$caso_existente["id"];
                    } else {
                        $sql_insert_caso = "INSERT INTO casos (numero_caso, descripcion) VALUES (?, ?)";
                        $stmt_insert_caso = mysqli_prepare($conexion, $sql_insert_caso);
                        mysqli_stmt_bind_param($stmt_insert_caso, "ss", $numero_caso, $descripcion);
                        mysqli_stmt_execute($stmt_insert_caso);
                        $caso_id = mysqli_insert_id($conexion);
                        mysqli_stmt_close($stmt_insert_caso);
                    }

                    $sql_cedula = "
                        INSERT INTO cedulas (
                            caso_id, fecha_ingreso, asunto, 
                            numero_resolucion, numero_hecho, fecha_hecho,
                            dni_involucrado, caracterizacion, titulo_medida,
                            color_semaforo, descripcion_hecho,
                            estado, usuario_recepcion_id, observaciones
                        )
                        VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE_DILIGENCIAMIENTO', ?, ?)
                    ";

                    $stmt_cedula = mysqli_prepare($conexion, $sql_cedula);
                    if ($stmt_cedula === false) {
                        throw new Exception("Error SQL al preparar la creación de la cédula: " . mysqli_error($conexion));
                    }

                    mysqli_stmt_bind_param(
                        $stmt_cedula,
                        "isssssssssis",
                        $caso_id, $titulo_medida, $numero_resolucion, $numero_hecho,
                        $fecha_hecho, $dni_involucrado, $caracterizacion, $titulo_medida,
                        $color_semaforo, $descripcion_hecho, $usuario_id, $observaciones
                    );

                    if (!mysqli_stmt_execute($stmt_cedula)) {
                        throw new Exception("Error al registrar la cédula: " . mysqli_stmt_error($stmt_cedula));
                    }

                    $cedula_id = mysqli_insert_id($conexion);
                    mysqli_stmt_close($stmt_cedula);

                    $sql_involucrado_principal = "
                        INSERT INTO involucrados (cedula_id, jerarquia, apellido_nombre, dni, dependencia, observaciones)
                        VALUES (?, ?, ?, ?, ?, NULL)
                    ";
                    $stmt_involucrado_principal = mysqli_prepare($conexion, $sql_involucrado_principal);
                    $jerarquia_principal = $personal['jerarquia'] ?? '';
                    $apellido_nombre_principal = $personal['apellido_nombre'] ?? '';
                    $dependencia_principal = $personal['dependencia'] ?? '';

                    mysqli_stmt_bind_param($stmt_involucrado_principal, "issss",
                        $cedula_id, $jerarquia_principal, $apellido_nombre_principal,
                        $dni_involucrado, $dependencia_principal
                    );
                    mysqli_stmt_execute($stmt_involucrado_principal);
                    $involucrado_principal_id = mysqli_insert_id($conexion);
                    mysqli_stmt_close($stmt_involucrado_principal);

                    $tipo_medida_id_principal = 0;
                    $sql_tipo_medida = "SELECT id FROM tipos_medida WHERE nombre = ? LIMIT 1";
                    $stmt_tipo_medida = mysqli_prepare($conexion, $sql_tipo_medida);
                    mysqli_stmt_bind_param($stmt_tipo_medida, "s", $tipo_medida);
                    mysqli_stmt_execute($stmt_tipo_medida);
                    $resultado_tipo_medida = mysqli_stmt_get_result($stmt_tipo_medida);
                    $tipo_medida_row = mysqli_fetch_assoc($resultado_tipo_medida);
                    if ($tipo_medida_row) {
                        $tipo_medida_id_principal = (int)$tipo_medida_row['id'];
                    }
                    mysqli_stmt_close($stmt_tipo_medida);

                    if ($tipo_medida_id_principal > 0) {
                        $sql_medida_principal = "
                            INSERT INTO medidas (cedula_id, involucrado_id, tipo_medida_id, numero_resolucion, anio_resolucion, observaciones)
                            VALUES (?, ?, ?, ?, NULL, NULL)
                        ";
                        $stmt_medida_principal = mysqli_prepare($conexion, $sql_medida_principal);
                        mysqli_stmt_bind_param($stmt_medida_principal, "iiis",
                            $cedula_id, $involucrado_principal_id, $tipo_medida_id_principal, $numero_resolucion
                        );
                        mysqli_stmt_execute($stmt_medida_principal);
                        mysqli_stmt_close($stmt_medida_principal);
                    }

                    foreach ($datos_involucrados as $datos) {
                        $sql_involucrado = "
                            INSERT INTO involucrados (cedula_id, jerarquia, apellido_nombre, dni, dependencia, observaciones)
                            VALUES (?, ?, ?, ?, ?, NULL)
                        ";
                        $stmt_involucrado = mysqli_prepare($conexion, $sql_involucrado);
                        mysqli_stmt_bind_param($stmt_involucrado, "issss",
                            $cedula_id, $datos["jerarquia"], $datos["apellido_nombre"],
                            $datos["dni"], $datos["dependencia"]
                        );
                        mysqli_stmt_execute($stmt_involucrado);
                        $involucrado_id = mysqli_insert_id($conexion);
                        mysqli_stmt_close($stmt_involucrado);

                        $sql_medida = "
                            INSERT INTO medidas (cedula_id, involucrado_id, tipo_medida_id, numero_resolucion, anio_resolucion, observaciones)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ";
                        $stmt_medida = mysqli_prepare($conexion, $sql_medida);
                        $anio = $datos["anio_resolucion"];
                        mysqli_stmt_bind_param($stmt_medida, "iiisis",
                            $cedula_id, $involucrado_id, $datos["tipo_medida_id"],
                            $datos["numero_resolucion"], $anio, $datos["observaciones_medida"]
                        );
                        mysqli_stmt_execute($stmt_medida);
                        mysqli_stmt_close($stmt_medida);
                    }

                    $accion = "RECEPCION DE CEDULA";
                    $descripcion_historial = "Se registró la recepción del documento correspondiente al caso Nº " . $numero_caso . ".";
                    $sql_historial = "INSERT INTO historial_cedula (cedula_id, usuario_id, accion, descripcion) VALUES (?, ?, ?, ?)";
                    $stmt_historial = mysqli_prepare($conexion, $sql_historial);
                    mysqli_stmt_bind_param($stmt_historial, "iiss", $cedula_id, $usuario_id, $accion, $descripcion_historial);
                    mysqli_stmt_execute($stmt_historial);
                    mysqli_stmt_close($stmt_historial);

                    // AUDITORÍA: CREAR CÉDULA
                    auditar(
                        $conexion,
                        'CREAR',
                        'cedulas',
                        'cedulas',
                        $cedula_id,
                        'Se creó una nueva cédula para el caso Nº ' . $numero_caso,
                        null,
                        [
                            'cedula_id' => $cedula_id,
                            'caso_id' => $caso_id,
                            'numero_caso' => $numero_caso,
                            'numero_hecho' => $numero_hecho,
                            'numero_resolucion' => $numero_resolucion,
                            'titulo_medida' => $titulo_medida,
                            'tipo_medida' => $tipo_medida,
                            'tiempo' => $tiempo,
                            'dni_involucrado' => $dni_involucrado,
                            'involucrado_principal' => $apellido_nombre_principal,
                            'caracterizacion' => $caracterizacion,
                            'color_semaforo' => $color_semaforo,
                            'cantidad_involucrados' => count($datos_involucrados) + 1,
                            'involucrados_adicionales' => array_map(function($d) {
                                return $d['apellido_nombre'] . ' (DNI: ' . $d['dni'] . ')';
                            }, $datos_involucrados)
                        ]
                    );

                    mysqli_commit($conexion);
                    $exito = true;
                    $cedula_id_creada = $cedula_id;

                    /*
                    |--------------------------------------------------------------------------
                    | GUARDAR PDF EN CARPETAS DE INVOLUCRADOS
                    |--------------------------------------------------------------------------
                    */

                    if (isset($_FILES['pdf_cedula']) && $_FILES['pdf_cedula']['error'] === UPLOAD_ERR_OK) {
                        $archivo_temporal = $_FILES['pdf_cedula']['tmp_name'];
                        $fecha_hoy = date('d-m-y');
                        $medida_abreviada = abreviar_medida($titulo_medida);
                        $nombre_archivo_original = $_FILES['pdf_cedula']['name'] ?? '';
                        $tamanio_archivo = $_FILES['pdf_cedula']['size'] ?? 0;
                        
                        $resultado = guardar_pdf_involucrado(
                            $archivo_temporal,
                            $personal['apellido_nombre'],
                            $medida_abreviada,
                            'A CUMPLIMENTAR',
                            $fecha_hoy,
                            false
                        );
                        
                        if ($resultado['exito']) {
                            $pdfs_guardados[] = [
                                'involucrado' => $personal['apellido_nombre'],
                                'nombre_sugerido' => $resultado['nombre_sugerido']
                            ];
                            
                            auditar(
                                $conexion,
                                'SUBIR_PDF',
                                'archivos',
                                'archivos',
                                $cedula_id,
                                'Se subió el PDF de la cédula (A CUMPLIMENTAR) para ' . $personal['apellido_nombre'],
                                null,
                                [
                                    'cedula_id' => $cedula_id,
                                    'involucrado' => $personal['apellido_nombre'],
                                    'nombre_original' => $nombre_archivo_original,
                                    'nombre_guardado' => $resultado['nombre_sugerido'],
                                    'ruta' => $resultado['ruta_relativa'],
                                    'tamanio_bytes' => $tamanio_archivo,
                                    'estado' => 'A CUMPLIMENTAR'
                                ]
                            );
                            
                            foreach ($datos_involucrados as $datos) {
                                $resultado_copia = guardar_pdf_involucrado(
                                    $resultado['ruta'],
                                    $datos['apellido_nombre'],
                                    $medida_abreviada,
                                    'A CUMPLIMENTAR',
                                    $fecha_hoy,
                                    true
                                );
                                
                                if ($resultado_copia['exito']) {
                                    $pdfs_guardados[] = [
                                        'involucrado' => $datos['apellido_nombre'],
                                        'nombre_sugerido' => $resultado_copia['nombre_sugerido']
                                    ];
                                    
                                    auditar(
                                        $conexion,
                                        'SUBIR_PDF',
                                        'archivos',
                                        'archivos',
                                        $cedula_id,
                                        'Se copió el PDF de la cédula (A CUMPLIMENTAR) para ' . $datos['apellido_nombre'],
                                        null,
                                        [
                                            'cedula_id' => $cedula_id,
                                            'involucrado' => $datos['apellido_nombre'],
                                            'nombre_guardado' => $resultado_copia['nombre_sugerido'],
                                            'ruta' => $resultado_copia['ruta_relativa'],
                                            'estado' => 'A CUMPLIMENTAR'
                                        ]
                                    );
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
                        0,
                        'Error al crear cédula: ' . $e->getMessage(),
                        null,
                        [
                            'numero_caso' => $numero_caso,
                            'error' => $e->getMessage()
                        ]
                    );
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| GENERAR MENSAJES WHATSAPP POR CADA INVOLUCRADO
|--------------------------------------------------------------------------
*/

$mensajes_whatsapp = [];

if ($exito) {
    $sql_involucrados_consulta = "
        SELECT i.*, m.tipo_medida_id, tm.nombre AS medida_nombre
        FROM involucrados i
        LEFT JOIN medidas m ON m.involucrado_id = i.id
        LEFT JOIN tipos_medida tm ON tm.id = m.tipo_medida_id
        WHERE i.cedula_id = ?
        ORDER BY i.id ASC
    ";

    $stmt_involucrados_consulta = mysqli_prepare($conexion, $sql_involucrados_consulta);
    mysqli_stmt_bind_param($stmt_involucrados_consulta, "i", $cedula_id);
    mysqli_stmt_execute($stmt_involucrados_consulta);
    $resultado_involucrados = mysqli_stmt_get_result($stmt_involucrados_consulta);
    $involucrados_cedula = [];
    while ($fila = mysqli_fetch_assoc($resultado_involucrados)) {
        $involucrados_cedula[] = $fila;
    }
    mysqli_stmt_close($stmt_involucrados_consulta);

    $semaforo = "";
    switch ($color_semaforo) {
        case 'ROJO': $semaforo = "🔴"; break;
        case 'AMARILLO': $semaforo = "🟡"; break;
        case 'VERDE': $semaforo = "🟢"; break;
    }

    $fecha_formateada = date("d/m/Y", strtotime($fecha_hecho));
    $titulo_completo = $titulo_medida;

    foreach ($involucrados_cedula as $involucrado) {
        $jerarquia = $involucrado['jerarquia'] ?? '';
        $apellido_nombre = $involucrado['apellido_nombre'] ?? '';
        $dni = $involucrado['dni'] ?? '';
        
        $sql_personal_msg = "SELECT dependencia, sub_dependencia FROM personal_policial WHERE dni = ? LIMIT 1";
        $stmt_personal_msg = mysqli_prepare($conexion, $sql_personal_msg);
        mysqli_stmt_bind_param($stmt_personal_msg, "s", $dni);
        mysqli_stmt_execute($stmt_personal_msg);
        $resultado_personal_msg = mysqli_stmt_get_result($stmt_personal_msg);
        $personal_msg = mysqli_fetch_assoc($resultado_personal_msg);
        mysqli_stmt_close($stmt_personal_msg);
        
        $dependencia = $personal_msg['dependencia'] ?? '';
        $sub_dependencia = $personal_msg['sub_dependencia'] ?? '';
        
        $direccion = '';
        if (!empty($sub_dependencia)) {
            $direccion = $sub_dependencia;
        } elseif (!empty($dependencia)) {
            $direccion = $dependencia;
        } else {
            $direccion = '-';
        }

        $mensaje = $semaforo . " *" . $titulo_completo . "*\n\n";
        $mensaje .= "*1*. HECHO Nº " . $numero_hecho . "\n";
        $mensaje .= "*2*. CASO N° " . $numero_caso . "\n";
        $mensaje .= "*3*. RS Nº " . $numero_resolucion . "\n";
        $mensaje .= "*4*. FECHA " . $fecha_formateada . "\n";
        $mensaje .= "*5*. " . $jerarquia . " " . $apellido_nombre . "\n";
        $mensaje .= "*6*. DNI Nº " . $dni . "\n";
        $mensaje .= "*7*. " . $direccion . "\n";
        $mensaje .= "*8*. " . $caracterizacion . "\n";
        $mensaje .= "*9*. *A CUMPLIMENTAR*\n\n";
        $mensaje .= $descripcion_hecho;

        $mensajes_whatsapp[] = [
            'involucrado' => $apellido_nombre,
            'mensaje' => $mensaje
        ];
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Cédula - Sistema Medidas</title>

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
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .success-box { background: white; border: 1px solid #bbf7d0; border-radius: 10px; padding: 30px; }
        .success-box h2 { color: #166534; margin-top: 0; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 25px; margin-bottom: 20px; }
        .card-title { margin: 0 0 20px; font-size: 18px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
        .form-group { margin-bottom: 5px; }
        .full { grid-column: 1 / -1; }
        label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 7px; color: #374151; }
        input, select, textarea { width: 100%; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #2563eb; }
        textarea { min-height: 90px; resize: vertical; }
        .involucrado { border: 1px solid #d1d5db; border-radius: 8px; padding: 20px; margin-bottom: 18px; background: #fafafa; }
        .involucrado-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .involucrado-header h3 { margin: 0; font-size: 16px; }
        .medida-title { margin: 22px 0 15px; padding-top: 15px; border-top: 1px solid #e5e7eb; font-size: 15px; }
        .btn { border: none; border-radius: 6px; padding: 11px 17px; cursor: pointer; font-size: 14px; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1d4ed8; color: white; }
        .btn-primary:hover { background: #1e40af; }
        .btn-secondary { background: #374151; color: white; }
        .btn-secondary:hover { background: #1f2937; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-success { background: #15803d; color: white; }
        .btn-success:hover { background: #166534; }
        .btn-whatsapp { background: #25D366; color: white; }
        .btn-whatsapp:hover { background: #1da851; }
        .acciones { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .acciones-derecha { display: flex; gap: 10px; }
        .mensaje-whatsapp { background: #f0fdf4; border: 2px solid #bbf7d0; border-radius: 10px; padding: 20px; margin-top: 20px; }
        .mensaje-whatsapp h3 { color: #166534; margin-top: 0; }
        .mensaje-item { border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 10px; background: #fafafa; }
        .mensaje-item h4 { margin: 0 0 10px; color: #1e40af; }
        .aviso-flotante { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: #15803d; color: white; padding: 15px 25px; border-radius: 8px; font-weight: bold; font-size: 14px; z-index: 9999; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
        .tabla-hechos { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .tabla-hechos th { background: #f9fafb; color: #374151; font-size: 12px; text-align: left; padding: 10px; border-bottom: 2px solid #e5e7eb; }
        .tabla-hechos td { padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px; }
        .tabla-hechos tr:hover { background: #f9fafb; }
        .hechos-container { display: none; margin-top: 15px; }
        .hechos-container h4 { margin: 0 0 10px; color: #1e40af; }
        .dato-personal { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 15px; margin-top: 10px; }
        .dato-personal strong { color: #1e40af; }
        .semaforo-options { display: flex; gap: 20px; margin-bottom: 15px; }
        .semaforo-options label { display: flex; align-items: center; gap: 5px; cursor: pointer; }
        .semaforo-options input[type="radio"] { width: auto; }
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
                <a href="nueva.php" class="active">Nueva cédula</a>
            <?php endif; ?>

            <a href="listado.php">Cédulas</a>

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

    <main class="main">
        <header class="topbar">
            <h1>Nueva cédula</h1>
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
                    <h2>✓ Cédula registrada correctamente</h2>
                    <p>El documento fue registrado correctamente.</p>
                    <p><strong>Número de caso:</strong> <?= htmlspecialchars($numero_caso) ?></p>
                    <p><strong>ID interno de cédula:</strong> <?= $cedula_id_creada ?></p>
                    <p><strong>Estado:</strong> PENDIENTE DE DILIGENCIAMIENTO</p>

                    <?php if (count($pdfs_guardados) > 0): ?>
                        <div class="pdf-info">
                            <strong>📄 Nombres de archivo sugeridos:</strong>
                            <p>Renombre el PDF subido con el siguiente nombre:</p>
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
                    <a href="nueva.php" class="btn btn-primary">Registrar otra cédula</a>
                    <a href="listado.php" class="btn btn-secondary">Ver cédulas</a>
                </div>
            <?php else: ?>

                <form method="POST" id="formCedula" onsubmit="return confirmarCarga()" enctype="multipart/form-data">

                    <input type="hidden" id="titulo_medida" name="titulo_medida" value="<?= htmlspecialchars($titulo_medida ?? "") ?>">

                    <div class="card">
                        <h2 class="card-title">Datos del caso</h2>
                        <div class="grid">
                            <div class="form-group">
                                <label for="numero_caso">Número de caso *</label>
                                <input type="text" id="numero_caso" name="numero_caso" required value="<?= htmlspecialchars($_POST["numero_caso"] ?? "") ?>">
                            </div>
                            <div class="form-group">
                                <label for="numero_hecho">Número de hecho *</label>
                                <input type="text" id="numero_hecho" name="numero_hecho" required value="<?= htmlspecialchars($_POST["numero_hecho"] ?? "") ?>">
                            </div>
                            <div class="form-group">
                                <label for="numero_resolucion">Número de resolución *</label>
                                <input type="text" id="numero_resolucion" name="numero_resolucion" required value="<?= htmlspecialchars($_POST["numero_resolucion"] ?? "") ?>">
                            </div>
                            <div class="form-group">
                                <label for="fecha_hecho">Fecha del hecho</label>
                                <input type="date" id="fecha_hecho" name="fecha_hecho" value="<?= htmlspecialchars($_POST["fecha_hecho"] ?? date("Y-m-d")) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h2 class="card-title">Involucrado principal</h2>
                        <div class="grid">
                            <div class="form-group">
                                <label for="dni_involucrado">DNI *</label>
                                <input type="text" id="dni_involucrado" name="dni_involucrado" required value="<?= htmlspecialchars($_POST["dni_involucrado"] ?? "") ?>" onblur="buscarPersonal()">
                            </div>
                            <div class="form-group">
                                <label for="caracterizacion">Caracterización del hecho *</label>
                                <select id="caracterizacion" name="caracterizacion" required>
                                    <option value="">Seleccione caracterización</option>
                                    <?php foreach ($categorias as $categoria => $subcategorias): ?>
                                        <optgroup label="<?= htmlspecialchars($categoria) ?>">
                                            <?php foreach ($subcategorias as $subcategoria): ?>
                                                <option value="<?= htmlspecialchars($subcategoria) ?>" <?= (($_POST["caracterizacion"] ?? "") === $subcategoria) ? "selected" : "" ?>>
                                                    <?= htmlspecialchars($subcategoria) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div id="datosPersonal" class="dato-personal" style="display: none;">
                            <strong>Datos del personal:</strong>
                            <div id="personalInfo"></div>
                        </div>
                        <div id="hechosContainer" class="hechos-container">
                            <h4>📋 Hechos registrados en SAD:</h4>
                            <div id="hechosTable"></div>
                        </div>
                    </div>

                    <div class="card">
                        <h2 class="card-title">Título del mensaje</h2>
                        <div class="grid">
                            <div class="form-group">
                                <label for="tipo_medida">Tipo de medida *</label>
                                <select id="tipo_medida" name="tipo_medida" required onchange="actualizarTiempos()">
                                    <option value="">Seleccione tipo de medida</option>
                                    <?php foreach ($tipos_medida_list as $tipo): ?>
                                        <option value="<?= htmlspecialchars($tipo) ?>" <?= (($_POST["tipo_medida"] ?? "") === $tipo) ? "selected" : "" ?>>
                                            <?= htmlspecialchars($tipo) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="tiempo">Tiempo *</label>
                                <select id="tiempo" name="tiempo" required onchange="actualizarTitulo()">
                                    <option value="">Seleccione tiempo</option>
                                    <?php foreach ($tiempos_por_tipo as $tipo => $tiempos): ?>
                                        <?php foreach ($tiempos as $tiempo_item): ?>
                                            <option value="<?= htmlspecialchars($tiempo_item) ?>" data-tipo="<?= htmlspecialchars($tipo) ?>" <?= (($_POST["tiempo"] ?? "") === $tiempo_item) ? "selected" : "" ?>>
                                                <?= htmlspecialchars($tiempo_item) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Medida</label>
                            <div class="semaforo-options">
                                <label>
                                    <input type="radio" name="color_semaforo" value="VERDE" <?= (($_POST["color_semaforo"] ?? "VERDE") === "VERDE") ? "checked" : "" ?>>
                                    🟢 POSITIVA
                                </label>
                                <label>
                                    <input type="radio" name="color_semaforo" value="AMARILLO" <?= (($_POST["color_semaforo"] ?? "") === "AMARILLO") ? "checked" : "" ?>>
                                    🟡 INTERMEDIA
                                </label>
                                <label>
                                    <input type="radio" name="color_semaforo" value="ROJO" <?= (($_POST["color_semaforo"] ?? "") === "ROJO") ? "checked" : "" ?>>
                                    🔴 NEGATIVA
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h2 class="card-title">Descripción del hecho</h2>
                        <div class="form-group">
                            <label for="descripcion_hecho">Descripción breve del hecho *</label>
                            <textarea id="descripcion_hecho" name="descripcion_hecho" required><?= htmlspecialchars($_POST["descripcion_hecho"] ?? "") ?></textarea>
                        </div>
                    </div>

                    <div class="card">
                        <h2 class="card-title">📄 Archivo PDF de la cédula</h2>
                        <div class="form-group">
                            <label for="pdf_cedula">Subir PDF de la cédula (solo PDF)</label>
                            <input type="file" id="pdf_cedula" name="pdf_cedula" accept=".pdf,application/pdf">
                            <small style="display: block; margin-top: 8px; color: #6b7280;">
                                El archivo se guardará automáticamente en las carpetas de cada involucrado.
                            </small>
                        </div>
                    </div>

                    <div class="card">
                        <h2 class="card-title">Involucrados adicionales (opcional)</h2>
                        <div id="contenedorInvolucrados"></div>
                        <button type="button" class="btn btn-secondary" onclick="agregarInvolucrado()">+ Agregar involucrado</button>
                    </div>

                    <div class="card">
                        <div class="acciones">
                            <a href="../index.php" class="btn btn-secondary">Cancelar</a>
                            <button type="submit" class="btn btn-success">GUARDAR CÉDULA</button>
                        </div>
                    </div>

                </form>

            <?php endif; ?>

        </section>
    </main>
</div>

<script>
const tiposMedida = <?= json_encode($tipos_medida, JSON_UNESCAPED_UNICODE) ?>;
let contadorInvolucrados = 0;

function buscarPersonal() {
    const dni = document.getElementById("dni_involucrado").value.trim();
    if (dni === "") return;
    
    fetch("buscar_personal.php?dni=" + encodeURIComponent(dni))
        .then(response => response.json())
        .then(data => {
            const divPersonal = document.getElementById("datosPersonal");
            const divInfo = document.getElementById("personalInfo");
            
            if (data.error) {
                divPersonal.style.display = "none";
                divInfo.innerHTML = "";
            } else {
                divPersonal.style.display = "block";
                divInfo.innerHTML = 
                    "<strong>Jerarquía:</strong> " + data.jerarquia + "<br>" +
                    "<strong>Apellido y nombre:</strong> " + data.apellido_nombre + "<br>" +
                    "<strong>DNI:</strong> " + data.dni + "<br>" +
                    "<strong>Dependencia:</strong> " + data.dependencia + "<br>" +
                    "<strong>Sub-dependencia:</strong> " + (data.sub_dependencia || "-") + "<br>" +
                    "<strong>Antigüedad:</strong> " + data.antiguedad + " años";
            }
        })
        .catch(error => { console.error("Error:", error); });
    
    buscarHechosSAD(dni);
}

function buscarHechosSAD(dni) {
    const hechosContainer = document.getElementById("hechosContainer");
    const hechosTable = document.getElementById("hechosTable");
    
    fetch("buscar_hechos_sad.php?dni=" + encodeURIComponent(dni))
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                hechosContainer.style.display = "none";
                hechosTable.innerHTML = "";
                return;
            }
            
            hechosContainer.style.display = "block";
            let html = '<table class="tabla-hechos">';
            html += '<thead><tr>';
            html += '<th>Fecha</th><th>Número de hecho</th><th>Subcategoría</th><th>Detalle</th>';
            html += '</tr></thead><tbody>';
            
            data.forEach(function(hecho) {
                let fechaFormateada = hecho.fecha || '';
                if (fechaFormateada) {
                    const partes = fechaFormateada.split('-');
                    if (partes.length === 3) {
                        fechaFormateada = partes[2] + '/' + partes[1] + '/' + partes[0];
                    }
                }
                const numeroHecho = hecho.tabla === 'datos_historicos' ? (hecho.rinov || '') : (hecho.id || '');
                html += '<tr>';
                html += '<td>' + fechaFormateada + '</td>';
                html += '<td><strong>' + numeroHecho + '</strong></td>';
                html += '<td>' + (hecho.sub_categoria || '') + '</td>';
                html += '<td style="max-width: 400px; white-space: pre-wrap; font-size: 12px;">' + (hecho.parte_wsp || '') + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            hechosTable.innerHTML = html;
        })
        .catch(error => {
            console.error("Error:", error);
            hechosContainer.style.display = "none";
        });
}

function actualizarTiempos() {
    const tipoSeleccionado = document.getElementById("tipo_medida").value;
    const selectTiempo = document.getElementById("tiempo");
    const tituloMedida = document.getElementById("titulo_medida");
    
    if (!selectTiempo.dataset.original) {
        selectTiempo.dataset.original = selectTiempo.innerHTML;
    }
    selectTiempo.innerHTML = selectTiempo.dataset.original;
    
    const opciones = selectTiempo.querySelectorAll('option[data-tipo]');
    opciones.forEach(function(opcion) {
        opcion.style.display = (opcion.dataset.tipo === tipoSeleccionado) ? "" : "none";
    });
    
    selectTiempo.value = "";
    tituloMedida.value = tipoSeleccionado;
}

function actualizarTitulo() {
    const tipoSeleccionado = document.getElementById("tipo_medida").value;
    const tiempoSeleccionado = document.getElementById("tiempo").value;
    const tituloMedida = document.getElementById("titulo_medida");
    
    if (tipoSeleccionado !== "" && tiempoSeleccionado !== "") {
        tituloMedida.value = tipoSeleccionado + " " + tiempoSeleccionado;
    } else if (tipoSeleccionado !== "") {
        tituloMedida.value = tipoSeleccionado;
    } else {
        tituloMedida.value = "";
    }
}

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

function confirmarCarga() {
    return confirm("¿Está seguro de guardar esta cédula?");
}

function agregarInvolucrado() {
    contadorInvolucrados++;
    const numero = contadorInvolucrados;
    const contenedor = document.getElementById("contenedorInvolucrados");
    const bloque = document.createElement("div");
    bloque.className = "involucrado";
    bloque.dataset.numero = numero;

    let opcionesMedida = '<option value="">Seleccione una medida</option>';
    tiposMedida.forEach(function(tipo) {
        opcionesMedida += '<option value="' + tipo.id + '">' + escapeHtml(tipo.nombre) + '</option>';
    });

    bloque.innerHTML = `
        <div class="involucrado-header">
            <h3>Involucrado adicional Nº ${numero}</h3>
            <button type="button" class="btn btn-danger" onclick="eliminarInvolucrado(this)">Eliminar</button>
        </div>
        <div class="grid-3">
            <div class="form-group">
                <label>DNI *</label>
                <input type="text" name="involucrados[${numero}][dni]" onblur="buscarInvolucradoAdicional(this)">
            </div>
            <div class="form-group">
                <label>Jerarquía</label>
                <input type="text" name="involucrados[${numero}][jerarquia]" readonly>
            </div>
            <div class="form-group">
                <label>Apellido y nombre *</label>
                <input type="text" name="involucrados[${numero}][apellido_nombre]" readonly>
            </div>
            <div class="form-group">
                <label>Dependencia</label>
                <input type="text" name="involucrados[${numero}][dependencia]" readonly>
            </div>
        </div>
        <h4 class="medida-title">Medida correspondiente</h4>
        <div class="grid-3">
            <div class="form-group">
                <label>Medida *</label>
                <select name="involucrados[${numero}][tipo_medida_id]">
                    ${opcionesMedida}
                </select>
            </div>
            <div class="form-group">
                <label>Nº de resolución</label>
                <input type="text" name="involucrados[${numero}][numero_resolucion]">
            </div>
            <div class="form-group">
                <label>Año de resolución</label>
                <input type="number" name="involucrados[${numero}][anio_resolucion]" min="1900" max="2155">
            </div>
            <div class="form-group full">
                <label>Observaciones de la medida</label>
                <textarea name="involucrados[${numero}][observaciones_medida]"></textarea>
            </div>
        </div>
    `;

    contenedor.appendChild(bloque);
}

function buscarInvolucradoAdicional(input) {
    const dni = input.value.trim();
    if (dni === "") return;
    
    const bloque = input.closest('.involucrado');
    
    fetch("buscar_personal.php?dni=" + encodeURIComponent(dni))
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert(data.error);
                return;
            }
            bloque.querySelector('[name$="[jerarquia]"]').value = data.jerarquia || '';
            bloque.querySelector('[name$="[apellido_nombre]"]').value = data.apellido_nombre || '';
            bloque.querySelector('[name$="[dependencia]"]').value = data.dependencia || '';
            mostrarAviso("✓ Datos cargados para " + data.apellido_nombre);
        })
        .catch(error => {
            console.error("Error:", error);
            alert("Error al buscar personal");
        });
}

function eliminarInvolucrado(boton) {
    const bloque = boton.closest(".involucrado");
    bloque.remove();
    renumerarInvolucrados();
}

function renumerarInvolucrados() {
    const bloques = document.querySelectorAll(".involucrado");
    bloques.forEach(function(bloque, index) {
        const numero = index + 1;
        bloque.dataset.numero = numero;
        const titulo = bloque.querySelector(".involucrado-header h3");
        if (titulo) titulo.textContent = "Involucrado adicional Nº " + numero;
        const campos = bloque.querySelectorAll("input, select, textarea");
        campos.forEach(function(campo) {
            campo.name = campo.name.replace(/involucrados\[\d+\]/, "involucrados[" + numero + "]");
        });
    });
}

function escapeHtml(texto) {
    const div = document.createElement("div");
    div.textContent = texto;
    return div.innerHTML;
}

document.addEventListener("DOMContentLoaded", function() {
    const tipoSeleccionado = document.getElementById("tipo_medida").value;
    if (tipoSeleccionado !== "") {
        actualizarTiempos();
    }
});
</script>

</body>
</html>