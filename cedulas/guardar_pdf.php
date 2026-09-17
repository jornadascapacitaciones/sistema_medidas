<?php

/**
 * Guarda un PDF en la carpeta correspondiente del involucrado
 * 
 * @param string $archivo_temporal Ruta temporal del PDF subido
 * @param string $apellido_nombre Apellido y nombre del involucrado
 * @param string $medida_abreviada Medida abreviada (ej: "SIT. PASIVA")
 * @param string $estado "A CUMPLIMENTAR" o "CUMPLIMENTADA"
 * @param string $fecha Fecha en formato dd-mm-yy
 * @param bool $es_copia Si es true, usa copy() en vez de move_uploaded_file()
 * @return array Resultado con éxito, ruta y mensaje
 */
function guardar_pdf_involucrado($archivo_temporal, $apellido_nombre, $medida_abreviada, $estado, $fecha, $es_copia = false)
{
    // Validar que sea PDF
    $tipo = mime_content_type($archivo_temporal);
    if ($tipo !== 'application/pdf') {
        return ['exito' => false, 'mensaje' => 'El archivo debe ser PDF'];
    }
    
    // Obtener la primera letra del apellido
    $primera_letra = strtoupper(substr(trim($apellido_nombre), 0, 1));
    
    // Sanitizar nombres para carpetas
    $apellido_nombre_limpio = limpiar_nombre_carpeta($apellido_nombre);
    $medida_limpia = limpiar_nombre_carpeta($medida_abreviada);
    $estado_limpio = limpiar_nombre_carpeta($estado);
    
    // Nombre base de la carpeta de la medida
    $nombre_carpeta_medida_base = $fecha . ' - ' . $medida_limpia . ' - ' . $estado_limpio;
    
    // Ruta base
    $ruta_base = __DIR__ . '/../archivos/';
    
    // Crear carpeta de la letra
    $ruta_letra = $ruta_base . $primera_letra;
    if (!is_dir($ruta_letra)) {
        mkdir($ruta_letra, 0777, true);
    }
    
    // Crear carpeta del efectivo (si existe, se reutiliza)
    $ruta_efectivo = $ruta_letra . '/' . $apellido_nombre_limpio;
    if (!is_dir($ruta_efectivo)) {
        mkdir($ruta_efectivo, 0777, true);
    }
    
    // Crear carpeta de la medida (SIEMPRE se crea, si existe se numera)
    $nombre_carpeta_medida = $nombre_carpeta_medida_base;
    $ruta_medida = $ruta_efectivo . '/' . $nombre_carpeta_medida;
    $contador = 1;
    
    while (is_dir($ruta_medida)) {
        $contador++;
        $nombre_carpeta_medida = $nombre_carpeta_medida_base . ' (' . $contador . ')';
        $ruta_medida = $ruta_efectivo . '/' . $nombre_carpeta_medida;
    }
    
    mkdir($ruta_medida, 0777, true);
    
    // Nombre del archivo PDF
    $nombre_pdf = $nombre_carpeta_medida . '.pdf';
    $ruta_pdf = $ruta_medida . '/' . $nombre_pdf;
    
    // Mover o copiar el archivo
    if ($es_copia) {
        if (!copy($archivo_temporal, $ruta_pdf)) {
            return ['exito' => false, 'mensaje' => 'Error al copiar el PDF'];
        }
    } else {
        if (!move_uploaded_file($archivo_temporal, $ruta_pdf)) {
            return ['exito' => false, 'mensaje' => 'Error al guardar el PDF'];
        }
    }
    
    return [
        'exito' => true,
        'ruta' => $ruta_pdf,
        'ruta_relativa' => 'archivos/' . $primera_letra . '/' . $apellido_nombre_limpio . '/' . $nombre_carpeta_medida . '/' . $nombre_pdf,
        'nombre_archivo' => $nombre_pdf,
        'nombre_sugerido' => $nombre_carpeta_medida . '.pdf'
    ];
}

/**
 * Limpia un nombre para usarlo como carpeta
 */
function limpiar_nombre_carpeta($nombre)
{
    // Reemplazar caracteres no válidos
    $nombre = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $nombre);
    // Reemplazar puntos al final
    $nombre = rtrim($nombre, '.');
    // Eliminar espacios múltiples
    $nombre = preg_replace('/\s+/', ' ', $nombre);
    // Trim
    $nombre = trim($nombre);
    // Convertir a mayúsculas
    $nombre = strtoupper($nombre);
    return $nombre;
}

/**
 * Abrevia el título de la medida
 * 
 * Ahora los tipos de medida YA vienen abreviados desde la base de datos,
 * así que solo necesitamos limpiarlos para el nombre de la carpeta.
 * 
 * @param string $titulo_medida Título completo (ej: "SIT. PASIVA 3 MESES")
 * @return string Medida abreviada
 */
function abreviar_medida($titulo_medida)
{
    $titulo = trim($titulo_medida);
    
    // Si está vacío
    if ($titulo === '') {
        return 'SIN MEDIDA';
    }
    
    // Eliminar el tiempo si está incluido al final
    // Ej: "SIT. PASIVA 3 MESES" → "SIT. PASIVA"
    // Ej: "SIT. PASIVA SIN ESTABLECER" → "SIT. PASIVA"
    $tiempos = [
        ' 1 MES', ' 2 MESES', ' 3 MESES', ' 4 MESES', ' 5 MESES',
        ' 6 MESES', ' 7 MESES', ' 8 MESES', ' 9 MESES', ' 10 MESES',
        ' 11 MESES', ' 12 MESES', ' SIN ESTABLECER'
    ];
    
    foreach ($tiempos as $tiempo) {
        if (substr($titulo, -strlen($tiempo)) === $tiempo) {
            $titulo = substr($titulo, 0, -strlen($tiempo));
            break;
        }
    }
    
    $titulo = trim($titulo);
    
    // Si quedó vacío después de quitar el tiempo
    if ($titulo === '') {
        return 'SIN MEDIDA';
    }
    
    return $titulo;
}

?>