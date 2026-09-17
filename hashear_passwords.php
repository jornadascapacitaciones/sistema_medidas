<?php

session_start();

require_once __DIR__ . "/config.php";

echo "<h1>🔐 Hasheando contraseñas...</h1>";
echo "<pre>";

$sql = "
    SELECT id, usuario, password 
    FROM usuarios 
    WHERE password NOT LIKE '\$2y\$%'
    ORDER BY id ASC
";

$resultado = mysqli_query($conexion, $sql);

if (!$resultado) {
    die("Error al consultar usuarios: " . mysqli_error($conexion));
}

$total = 0;
$hasheados = 0;
$errores = 0;

while ($fila = mysqli_fetch_assoc($resultado)) {

    $total++;

    $id = (int)$fila['id'];
    $usuario = $fila['usuario'];
    $password_plano = $fila['password'];

    $nuevo_hash = password_hash($password_plano, PASSWORD_DEFAULT);

    $sql_update = "UPDATE usuarios SET password = ? WHERE id = ?";
    $stmt_update = mysqli_prepare($conexion, $sql_update);

    if (!$stmt_update) {
        echo "❌ ERROR al preparar: $usuario - " . mysqli_error($conexion) . "\n";
        $errores++;
        continue;
    }

    mysqli_stmt_bind_param($stmt_update, "si", $nuevo_hash, $id);

    if (mysqli_stmt_execute($stmt_update)) {
        echo "✅ Hasheado: '$usuario' (ID: $id)\n";
        $hasheados++;
    } else {
        echo "❌ ERROR: '$usuario' - " . mysqli_stmt_error($stmt_update) . "\n";
        $errores++;
    }

    mysqli_stmt_close($stmt_update);
}

echo "\n";
echo "===========================================\n";
echo "RESUMEN\n";
echo "===========================================\n";
echo "Total procesados: $total\n";
echo "✅ Hasheados:     $hasheados\n";
echo "❌ Errores:       $errores\n";
echo "===========================================\n";
echo "\n⚠️  IMPORTANTE: Elimine este archivo ahora.\n";

echo "</pre>";
?>