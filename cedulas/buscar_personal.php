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
| BUSCAR PERSONAL
|--------------------------------------------------------------------------
*/

$sql = "SELECT * FROM personal_policial WHERE dni = ? LIMIT 1";
$stmt = mysqli_prepare($conexion, $sql);

if ($stmt === false) {
    echo json_encode(['error' => 'Error SQL: ' . mysqli_error($conexion)]);
    exit;
}

mysqli_stmt_bind_param($stmt, "s", $dni);
mysqli_stmt_execute($stmt);
$resultado = mysqli_stmt_get_result($stmt);
$personal = mysqli_fetch_assoc($resultado);
mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| AUDITORÍA: BUSQUEDA DE PERSONAL
|--------------------------------------------------------------------------
*/

auditar(
    $conexion,
    'BUSCAR',
    'personal',
    'personal_policial',
    $personal ? (int)$personal['id'] : 0,
    'Búsqueda de personal por DNI: ' . $dni . ' — ' . ($personal ? 'Encontrado: ' . $personal['apellido_nombre'] : 'NO encontrado'),
    null,
    [
        'dni_buscado' => $dni,
        'resultado' => $personal ? 'encontrado' : 'no_encontrado',
        'nombre_encontrado' => $personal['apellido_nombre'] ?? null
    ]
);

/*
|--------------------------------------------------------------------------
| RESPUESTA
|--------------------------------------------------------------------------
*/

if ($personal) {
    echo json_encode($personal);
} else {
    echo json_encode(['error' => 'No se encontró personal con ese DNI']);
}