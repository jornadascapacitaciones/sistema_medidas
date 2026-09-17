<?php

session_start();

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../auditoria.php";
require_once __DIR__ . "/../permisos.php";

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

verificarPermiso(['admin', 'supervisor', 'operador', 'consulta']);

$dni = trim($_GET['dni'] ?? '');

if ($dni === '') {
    echo json_encode(['error' => 'DNI vacío']);
    exit;
}

/*
|--------------------------------------------------------------------------
| CONECTAR A SISTEMA_SAD
|--------------------------------------------------------------------------
*/

$conexion_sad = mysqli_connect("localhost", "root", "nueva_clave_segura*", "sistema_sad");

if (!$conexion_sad) {
    echo json_encode(['error' => 'Error de conexión a sistema_sad: ' . mysqli_connect_error()]);
    exit;
}

mysqli_set_charset($conexion_sad, "utf8mb4");

$hechos = [];

/*
|--------------------------------------------------------------------------
| BUSCAR EN datos_2026
|--------------------------------------------------------------------------
*/

$sql_2026 = "
    SELECT 
        id,
        fecha,
        sub_categoria,
        parte_wsp
    FROM datos_2026
    WHERE dni_1 = ? OR dni_2 = ? OR dni_3 = ? OR dni_4 = ? OR dni_5 = ?
       OR dni_6 = ? OR dni_7 = ? OR dni_8 = ? OR dni_9 = ?
    ORDER BY fecha ASC, hora ASC
    LIMIT 50
";

$stmt_2026 = mysqli_prepare($conexion_sad, $sql_2026);

if ($stmt_2026) {
    mysqli_stmt_bind_param($stmt_2026, "sssssssss", $dni, $dni, $dni, $dni, $dni, $dni, $dni, $dni, $dni);
    mysqli_stmt_execute($stmt_2026);
    $resultado_2026 = mysqli_stmt_get_result($stmt_2026);

    while ($fila = mysqli_fetch_assoc($resultado_2026)) {
        $fila['tabla'] = 'datos_2026';
        $fila['fecha_orden'] = $fila['fecha'];
        $hechos[] = $fila;
    }

    mysqli_stmt_close($stmt_2026);
}

/*
|--------------------------------------------------------------------------
| BUSCAR EN datos_2025
|--------------------------------------------------------------------------
*/

$sql_2025 = "
    SELECT 
        id,
        fecha,
        sub_categoria,
        parte_wsp
    FROM datos_2025
    WHERE dni_1 = ? OR dni_2 = ? OR dni_3 = ? OR dni_4 = ? OR dni_5 = ?
       OR dni_6 = ? OR dni_7 = ? OR dni_8 = ? OR dni_9 = ?
    ORDER BY fecha ASC, hora ASC
    LIMIT 50
";

$stmt_2025 = mysqli_prepare($conexion_sad, $sql_2025);

if ($stmt_2025) {
    mysqli_stmt_bind_param($stmt_2025, "sssssssss", $dni, $dni, $dni, $dni, $dni, $dni, $dni, $dni, $dni);
    mysqli_stmt_execute($stmt_2025);
    $resultado_2025 = mysqli_stmt_get_result($stmt_2025);

    while ($fila = mysqli_fetch_assoc($resultado_2025)) {
        $fila['tabla'] = 'datos_2025';
        $fila['fecha_orden'] = $fila['fecha'];
        $hechos[] = $fila;
    }

    mysqli_stmt_close($stmt_2025);
}

/*
|--------------------------------------------------------------------------
| BUSCAR EN datos_historicos
|--------------------------------------------------------------------------
*/

$sql_historicos = "
    SELECT 
        rinov,
        fecha,
        sub_categoria,
        parte_wsp
    FROM datos_historicos
    WHERE dni_1 = ? OR dni_2 = ? OR dni_3 = ? OR dni_4 = ? OR dni_5 = ?
       OR dni_6 = ? OR dni_7 = ? OR dni_8 = ? OR dni_9 = ?
    ORDER BY fecha ASC, hora ASC
    LIMIT 50
";

$stmt_historicos = mysqli_prepare($conexion_sad, $sql_historicos);

if ($stmt_historicos) {
    mysqli_stmt_bind_param($stmt_historicos, "sssssssss", $dni, $dni, $dni, $dni, $dni, $dni, $dni, $dni, $dni);
    mysqli_stmt_execute($stmt_historicos);
    $resultado_historicos = mysqli_stmt_get_result($stmt_historicos);

    while ($fila = mysqli_fetch_assoc($resultado_historicos)) {
        $fila['tabla'] = 'datos_historicos';
        // Convertir fecha DD/MM/YYYY a YYYY-MM-DD
        $fecha_original = $fila['fecha'] ?? '';
        if ($fecha_original) {
            $partes = explode('/', $fecha_original);
            if (count($partes) === 3) {
                $fila['fecha_orden'] = $partes[2] . '-' . $partes[1] . '-' . $partes[0];
            } else {
                $fila['fecha_orden'] = $fecha_original;
            }
        } else {
            $fila['fecha_orden'] = '';
        }
        $hechos[] = $fila;
    }

    mysqli_stmt_close($stmt_historicos);
}

mysqli_close($conexion_sad);

/*
|--------------------------------------------------------------------------
| ORDENAR POR FECHA ASCENDENTE
|--------------------------------------------------------------------------
*/

usort($hechos, function($a, $b) {
    $fechaA = $a['fecha_orden'] ?? '';
    $fechaB = $b['fecha_orden'] ?? '';
    return strcmp($fechaA, $fechaB);
});

/*
|--------------------------------------------------------------------------
| AUDITORÍA: BUSQUEDA DE HECHOS EN SAD
|--------------------------------------------------------------------------
*/

auditar(
    $conexion,
    'BUSCAR',
    'sad',
    'datos_2026',
    0,
    'Búsqueda de hechos en SAD para DNI: ' . $dni . ' — ' . count($hechos) . ' hecho(s) encontrado(s)',
    null,
    [
        'dni_buscado' => $dni,
        'cantidad_hechos' => count($hechos),
        'tablas_consultadas' => ['datos_2026', 'datos_2025', 'datos_historicos']
    ]
);

/*
|--------------------------------------------------------------------------
| RESPUESTA
|--------------------------------------------------------------------------
*/

if (count($hechos) === 0) {
    echo json_encode(['error' => 'No se encontraron hechos para el DNI ingresado']);
} else {
    echo json_encode($hechos);
}