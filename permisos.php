<?php

/**
 * Sistema de Permisos
 * 
 * Define los roles y verifica el acceso a cada módulo.
 */

/**
 * Verifica que el usuario tenga uno de los roles permitidos.
 * Si no tiene sesión o no tiene permiso, detiene la ejecución.
 * 
 * @param array $roles_permitidos Array de roles permitidos (ej: ['admin', 'supervisor'])
 */
function verificarPermiso($roles_permitidos = [])
{
    // Verificar que haya sesión activa
    if (!isset($_SESSION['usuario_id'])) {
        header("Location: ../login.php");
        exit;
    }
    
    // Si no se especifican roles, permitir todos los que tengan sesión
    if (empty($roles_permitidos)) {
        return;
    }
    
    // Obtener el rol del usuario
    $rol_usuario = $_SESSION['rol'] ?? '';
    
    // Si el rol no está en la lista permitida, denegar acceso
    if (!in_array($rol_usuario, $roles_permitidos)) {
        mostrarAccesoDenegado();
        exit;
    }
}

/**
 * Muestra una página de acceso denegado
 */
function mostrarAccesoDenegado()
{
    http_response_code(403);
    
    $rol = $_SESSION['rol'] ?? 'desconocido';
    $nombre = trim(
        ($_SESSION['jerarquia'] ?? '') . ' ' .
        ($_SESSION['apellido'] ?? '') . ', ' .
        ($_SESSION['nombre'] ?? '')
    );
    if ($nombre === ',') {
        $nombre = $_SESSION['usuario'] ?? 'Usuario';
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Acceso denegado</title>
        <style>
            * { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: Arial, Helvetica, sans-serif;
                background: #f3f4f6;
                color: #1f2937;
                padding: 20px;
            }
            .box {
                background: white;
                border-radius: 12px;
                padding: 40px;
                max-width: 500px;
                width: 100%;
                text-align: center;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                border-top: 5px solid #dc2626;
            }
            .icon {
                font-size: 60px;
                margin-bottom: 20px;
            }
            h1 {
                margin: 0 0 15px;
                color: #991b1b;
                font-size: 24px;
            }
            p {
                margin: 0 0 15px;
                color: #4b5563;
                line-height: 1.6;
                font-size: 15px;
            }
            .info {
                background: #fef3c7;
                border: 1px solid #fcd34d;
                color: #92400e;
                padding: 15px;
                border-radius: 8px;
                margin: 20px 0;
                font-size: 14px;
            }
            .info strong {
                display: block;
                margin-bottom: 5px;
            }
            .btn {
                display: inline-block;
                padding: 12px 25px;
                background: #1d4ed8;
                color: white;
                text-decoration: none;
                border-radius: 7px;
                font-weight: bold;
                font-size: 14px;
                margin: 5px;
            }
            .btn:hover {
                background: #1e40af;
            }
            .btn-secondary {
                background: #374151;
            }
            .btn-secondary:hover {
                background: #1f2937;
            }
        </style>
    </head>
    <body>
        <div class="box">
            <div class="icon">🚫</div>
            <h1>Acceso denegado</h1>
            <p>
                No tiene permisos para acceder a esta sección del sistema.
            </p>
            <div class="info">
                <strong>Usuario: <?= htmlspecialchars($nombre) ?></strong>
                Rol actual: <strong><?= htmlspecialchars(strtoupper($rol)) ?></strong>
            </div>
            <p style="font-size: 13px; color: #6b7280;">
                Si cree que es un error, contacte al administrador del sistema.
            </p>
            <div style="margin-top: 25px;">
                <a href="javascript:history.back()" class="btn btn-secondary">← Volver</a>
                <a href="/sistema_medidas/index.php" class="btn">Ir al inicio</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}

/**
 * Verifica si el usuario actual tiene uno de los roles indicados.
 * Devuelve true o false (sin detener la ejecución).
 * 
 * @param array $roles
 * @return bool
 */
function tienePermiso($roles = [])
{
    if (!isset($_SESSION['usuario_id'])) {
        return false;
    }
    
    if (empty($roles)) {
        return true;
    }
    
    $rol_usuario = $_SESSION['rol'] ?? '';
    
    return in_array($rol_usuario, $roles);
}

/**
 * Devuelve el rol del usuario actual
 */
function rolActual()
{
    return $_SESSION['rol'] ?? 'sin_rol';
}

/**
 * Verifica si el usuario actual es admin
 */
function esAdmin()
{
    return ($_SESSION['rol'] ?? '') === 'admin';
}

?>