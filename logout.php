<?php

session_start();

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/auditoria.php";

/*
|--------------------------------------------------------------------------
| AUDITORÍA: LOGOUT
|--------------------------------------------------------------------------
*/

if (isset($_SESSION["usuario_id"])) {

    $usuario_id = (int)$_SESSION["usuario_id"];

    auditar(
        $conexion,
        'LOGOUT',
        'usuarios',
        'usuarios',
        $usuario_id,
        'Cierre de sesión: ' . ($_SESSION["usuario"] ?? 'desconocido'),
        null,
        [
            'usuario' => $_SESSION["usuario"] ?? '',
            'nombre_completo' => trim(
                ($_SESSION["jerarquia"] ?? '') . ' ' .
                ($_SESSION["apellido"] ?? '') . ', ' .
                ($_SESSION["nombre"] ?? '')
            )
        ]
    );
}

/*
|--------------------------------------------------------------------------
| CERRAR SESIÓN
|--------------------------------------------------------------------------
*/

$_SESSION = [];

/*
|--------------------------------------------------------------------------
| ELIMINAR COOKIE DE SESIÓN
|--------------------------------------------------------------------------
*/

if (ini_get("session.use_cookies")) {

    $parametros = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $parametros["path"],
        $parametros["domain"],
        $parametros["secure"],
        $parametros["httponly"]
    );
}

/*
|--------------------------------------------------------------------------
| DESTRUIR SESIÓN
|--------------------------------------------------------------------------
*/

session_destroy();

/*
|--------------------------------------------------------------------------
| VOLVER AL LOGIN
|--------------------------------------------------------------------------
*/

header("Location: login.php");
exit;