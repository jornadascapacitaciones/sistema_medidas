<?php
// Conectar a la base de datos sistema_sad
$conexion_sad = mysqli_connect("localhost", "root", "nueva_clave_segura*", "sistema_sad");

if (!$conexion_sad) {
    die("Error de conexión a sistema_sad: " . mysqli_connect_error());
}

mysqli_set_charset($conexion_sad, "utf8mb4");

// Obtener todas las tablas
$sql_tablas = "SHOW TABLES";
$resultado_tablas = mysqli_query($conexion_sad, $sql_tablas);

echo "<h1>Base de datos: sistema_sad</h1>";
echo "<h2>Tablas encontradas:</h2>";
echo "<ul>";

while ($tabla = mysqli_fetch_array($resultado_tablas)) {
    $nombre_tabla = $tabla[0];
    echo "<li><strong>$nombre_tabla</strong></li>";
    
    // Obtener estructura de cada tabla
    $sql_estructura = "DESCRIBE $nombre_tabla";
    $resultado_estructura = mysqli_query($conexion_sad, $sql_estructura);
    
    echo "<ul>";
    while ($columna = mysqli_fetch_assoc($resultado_estructura)) {
        echo "<li>";
        echo "<strong>" . $columna['Field'] . "</strong> - ";
        echo $columna['Type'];
        if ($columna['Key'] === 'PRI') echo " (PRIMARY KEY)";
        if ($columna['Null'] === 'NO') echo " (NOT NULL)";
        echo "</li>";
    }
    echo "</ul>";
    
    // Contar registros
    $sql_contar = "SELECT COUNT(*) as total FROM $nombre_tabla";
    $resultado_contar = mysqli_query($conexion_sad, $sql_contar);
    $fila_contar = mysqli_fetch_assoc($resultado_contar);
    echo "<p>Registros: <strong>" . $fila_contar['total'] . "</strong></p>";
    
    // Mostrar primeros registros (si no es demasiado grande)
    $sql_datos = "SELECT * FROM $nombre_tabla LIMIT 5";
    $resultado_datos = mysqli_query($conexion_sad, $sql_datos);
    
    if (mysqli_num_rows($resultado_datos) > 0) {
        echo "<h3>Primeros 5 registros:</h3>";
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse; margin-bottom:20px;'>";
        
        // Encabezados
        echo "<tr>";
        while ($campo = mysqli_fetch_field($resultado_datos)) {
            echo "<th>" . $campo->name . "</th>";
        }
        echo "</tr>";
        
        // Datos
        while ($fila = mysqli_fetch_assoc($resultado_datos)) {
            echo "<tr>";
            foreach ($fila as $valor) {
                echo "<td>" . htmlspecialchars(substr($valor ?? '', 0, 100)) . "</td>";
            }
            echo "</tr>";
        }
        
        echo "</table>";
    }
}

echo "</ul>";

mysqli_close($conexion_sad);
?>