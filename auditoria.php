<?php

/**
 * Sistema de Auditoría
 * 
 * Registra todos los movimientos que realizan los usuarios en el sistema.
 */

/**
 * Registra una acción en la tabla de auditoría
 * 
 * @param mysqli $conexion Conexión a la base de datos
 * @param string $accion Acción realizada (CREAR, EDITAR, ELIMINAR, VER, LOGIN, LOGOUT, ASIGNAR, CUMPLIMENTAR, SUBIR_PDF, DESCARGAR, BUSCAR)
 * @param string $modulo Módulo afectado (cedulas, involucrados, medidas, encargados, usuarios, archivos, casos)
 * @param string $tabla_afectada Nombre de la tabla
 * @param int $registro_id ID del registro afectado
 * @param string $descripcion Descripción legible del movimiento
 * @param array|null $datos_anteriores Datos antes del cambio (opcional)
 * @param array|null $datos_nuevos Datos después del cambio (opcional)
 * @return bool
 */
function auditar($conexion, $accion, $modulo, $tabla_afectada, $registro_id, $descripcion, $datos_anteriores = null, $datos_nuevos = null)
{
    // Obtener datos del usuario desde la sesión
    $usuario_id = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null;
    
    $usuario_nombre = '';
    if (isset($_SESSION['apellido']) || isset($_SESSION['nombre'])) {
        $usuario_nombre = trim(
            ($_SESSION['jerarquia'] ?? '') . ' ' .
            ($_SESSION['apellido'] ?? '') . ', ' .
            ($_SESSION['nombre'] ?? '')
        );
    }
    if ($usuario_nombre === '' && isset($_SESSION['usuario'])) {
        $usuario_nombre = $_SESSION['usuario'];
    }
    
    // Obtener IP
    $ip = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    // Obtener navegador
    $navegador = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (strlen($navegador) > 250) {
        $navegador = substr($navegador, 0, 250);
    }
    
    // Obtener URL
    $url = $_SERVER['REQUEST_URI'] ?? '';
    if (strlen($url) > 500) {
        $url = substr($url, 0, 500);
    }
    
    // Convertir datos a JSON
    $anteriores_json = null;
    if ($datos_anteriores !== null) {
        $anteriores_json = json_encode($datos_anteriores, JSON_UNESCAPED_UNICODE);
    }
    
    $nuevos_json = null;
    if ($datos_nuevos !== null) {
        $nuevos_json = json_encode($datos_nuevos, JSON_UNESCAPED_UNICODE);
    }
    
    // Preparar SQL
    $sql = "
        INSERT INTO auditoria (
            usuario_id,
            usuario_nombre,
            accion,
            modulo,
            tabla_afectada,
            registro_id,
            descripcion,
            datos_anteriores,
            datos_nuevos,
            ip,
            navegador,
            url,
            fecha
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ";
    
    $stmt = mysqli_prepare($conexion, $sql);
    
    if ($stmt === false) {
        return false;
    }
    
    mysqli_stmt_bind_param(
        $stmt,
        "issssissssss",
        $usuario_id,
        $usuario_nombre,
        $accion,
        $modulo,
        $tabla_afectada,
        $registro_id,
        $descripcion,
        $anteriores_json,
        $nuevos_json,
        $ip,
        $navegador,
        $url
    );
    
    $resultado = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    return $resultado;
}

?>