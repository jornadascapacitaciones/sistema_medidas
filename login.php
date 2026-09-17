<?php

session_start();

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/auditoria.php";

$error = "";

/*
|--------------------------------------------------------------------------
| SI YA HAY SESIÓN
|--------------------------------------------------------------------------
*/

if (isset($_SESSION["usuario_id"])) {
    header("Location: index.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| PROCESAR LOGIN
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $usuario = trim($_POST["usuario"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($usuario === "" || $password === "") {

        $error = "Debe ingresar usuario y contraseña.";

    } else {

        $sql = "
            SELECT
                id,
                usuario,
                password,
                nombre,
                apellido,
                jerarquia,
                rol,
                activo
            FROM usuarios
            WHERE usuario = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conexion, $sql);

        if ($stmt === false) {

            $error = "Error SQL: " . mysqli_error($conexion);

        } else {

            mysqli_stmt_bind_param(
                $stmt,
                "s",
                $usuario
            );

            if (!mysqli_stmt_execute($stmt)) {

                $error = "Error al procesar el ingreso.";

            } else {

                $resultado = mysqli_stmt_get_result($stmt);

                $datos_usuario = mysqli_fetch_assoc($resultado);

                if (!$datos_usuario) {

                    $error = "Usuario o contraseña incorrectos.";

                    auditar(
                        $conexion,
                        'LOGIN_FALLIDO',
                        'usuarios',
                        'usuarios',
                        0,
                        'Intento de inicio de sesión fallido. Usuario no existe: ' . $usuario,
                        null,
                        ['usuario_intentado' => $usuario]
                    );

                } elseif ((int)$datos_usuario["activo"] !== 1) {

                    $error = "El usuario se encuentra desactivado.";

                    auditar(
                        $conexion,
                        'LOGIN_FALLIDO',
                        'usuarios',
                        'usuarios',
                        (int)$datos_usuario["id"],
                        'Intento de inicio de sesión fallido. Usuario desactivado: ' . $usuario,
                        null,
                        ['usuario_intentado' => $usuario]
                    );

                } elseif (
                    !password_verify(
                        $password,
                        $datos_usuario["password"]
                    )
                ) {

                    $error = "Usuario o contraseña incorrectos.";

                    auditar(
                        $conexion,
                        'LOGIN_FALLIDO',
                        'usuarios',
                        'usuarios',
                        (int)$datos_usuario["id"],
                        'Intento de inicio de sesión fallido. Contraseña incorrecta para: ' . $usuario,
                        null,
                        ['usuario_intentado' => $usuario]
                    );

                } else {

                    session_regenerate_id(true);

                    $_SESSION["usuario_id"] = (int)$datos_usuario["id"];
                    $_SESSION["usuario"] = $datos_usuario["usuario"];
                    $_SESSION["nombre"] = $datos_usuario["nombre"];
                    $_SESSION["apellido"] = $datos_usuario["apellido"];
                    $_SESSION["jerarquia"] = $datos_usuario["jerarquia"];
                    $_SESSION["rol"] = $datos_usuario["rol"] ?? 'operador';

                    auditar(
                        $conexion,
                        'LOGIN',
                        'usuarios',
                        'usuarios',
                        (int)$datos_usuario["id"],
                        'Inicio de sesión exitoso: ' . $usuario,
                        null,
                        [
                            'usuario' => $datos_usuario["usuario"],
                            'rol' => $datos_usuario["rol"] ?? 'operador',
                            'nombre_completo' => trim(
                                ($datos_usuario["jerarquia"] ?? '') . ' ' .
                                ($datos_usuario["apellido"] ?? '') . ', ' .
                                ($datos_usuario["nombre"] ?? '')
                            )
                        ]
                    );

                    header("Location: index.php");
                    exit;
                }
            }

            mysqli_stmt_close($stmt);
        }
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Iniciar sesión - Sistema Medidas
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {

            margin: 0;

            min-height: 100vh;

            display: flex;

            align-items: center;

            justify-content: center;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background:
                #111827;

            color:
                #1f2937;
        }

        .login-container {

            width: 100%;

            max-width: 420px;

            padding: 20px;
        }

        .login-box {

            background: white;

            border-radius: 12px;

            padding: 35px;

            box-shadow:
                0 15px 40px
                rgba(0, 0, 0, 0.25);
        }

        .logo {

            text-align: center;

            margin-bottom: 30px;
        }

        .logo h1 {

            margin: 0;

            font-size: 26px;

            color:
                #111827;
        }

        .logo p {

            margin: 8px 0 0;

            color:
                #6b7280;

            font-size: 14px;
        }

        .form-group {

            margin-bottom: 20px;
        }

        label {

            display: block;

            margin-bottom: 7px;

            font-size: 14px;

            font-weight: bold;

            color:
                #374151;
        }

        input {

            width: 100%;

            padding: 12px 13px;

            border:
                1px solid #d1d5db;

            border-radius: 7px;

            font-size: 15px;

            font-family: inherit;
        }

        input:focus {

            outline: none;

            border-color:
                #2563eb;

            box-shadow:
                0 0 0 3px
                rgba(37, 99, 235, 0.10);
        }

        .btn {

            width: 100%;

            border: none;

            border-radius: 7px;

            padding: 13px;

            cursor: pointer;

            font-size: 15px;

            font-weight: bold;

            background:
                #1d4ed8;

            color: white;
        }

        .btn:hover {

            background:
                #1e40af;
        }

        .alert {

            background:
                #fee2e2;

            border:
                1px solid #fecaca;

            color:
                #991b1b;

            padding: 12px;

            border-radius: 7px;

            margin-bottom: 20px;

            font-size: 14px;
        }

        .footer {

            text-align: center;

            margin-top: 20px;

            color:
                #9ca3af;

            font-size: 12px;
        }

    </style>

</head>

<body>

<div class="login-container">

    <div class="login-box">

        <div class="logo">

            <h1>
                SISTEMA MEDIDAS
            </h1>

            <p>
                Gestión y seguimiento de cédulas
            </p>

        </div>

        <?php if ($error !== ""): ?>

            <div class="alert">

                <?= htmlspecialchars($error) ?>

            </div>

        <?php endif; ?>

        <form
            method="POST"
            autocomplete="off"
        >

            <div class="form-group">

                <label for="usuario">
                    Usuario
                </label>

                <input
                    type="text"
                    id="usuario"
                    name="usuario"
                    required
                    autofocus
                    value="<?= htmlspecialchars(
                        $_POST["usuario"] ?? ""
                    ) ?>"
                >

            </div>

            <div class="form-group">

                <label for="password">
                    Contraseña
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                >

            </div>

            <button
                type="submit"
                class="btn"
            >
                INGRESAR
            </button>

        </form>

        <div class="footer">

            Sistema Medidas

        </div>

    </div>

</div>

</body>

</html>