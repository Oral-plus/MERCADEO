<?php
session_start();
include 'db_connection.php';

// Si el usuario ya está logueado, redirigir al index
if (isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

$mensaje = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $usuario = $_POST['usuario'];
    $password = $_POST['password'];
    
    // Buscar el usuario en la base de datos
    $sql = "SELECT id, nombre, usuario, password FROM usuarios_ruta WHERE usuario = ?";
    $params = array($usuario);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        $mensaje = "❌ Error al buscar usuario: " . print_r(sqlsrv_errors(), true);
    } else {
        if (sqlsrv_has_rows($stmt)) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            
            // Verificar la contraseña
            if (password_verify($password, $row['password'])) {
                // Iniciar sesión
                $_SESSION['usuario_id'] = $row['id'];
                $_SESSION['nombre'] = $row['nombre'];
                $_SESSION['usuario'] = $row['usuario'];
                
                // Redirigir al index
                header("Location: index.php");
                exit();
            } else {
                $mensaje = "❌ Contraseña incorrecta";
            }
        } else {
            $mensaje = "❌ Usuario no encontrado";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="65x45.png" type="image/x-icon">
    <title>Login Gestores</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Poppins', 'Arial', sans-serif;
            background: linear-gradient(135deg, #0066ff, #003366);
            color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            animation: gradientBackground 15s ease infinite;
            background-size: 400% 400%;
        }
        
        @keyframes gradientBackground {
            0% {
                background-position: 0% 50%;
            }
            50% {
                background-position: 100% 50%;
            }
            100% {
                background-position: 0% 50%;
            }
        }

        .container {
            max-width: 420px;
            width: 90%;
            background: rgba(255, 255, 255, 0.95);
            color: #333;
            padding: 35px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2), 
                        0 0 20px rgba(0, 102, 255, 0.2);
            backdrop-filter: blur(10px);
            transform: translateY(0);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .container:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25), 
                        0 0 25px rgba(0, 102, 255, 0.3);
        }

        .logo-container {
            text-align: center;
            margin-bottom: 25px;
            padding: 10px;
        }

        .logo-container img {
            max-width: 120px;
            height: auto;
            transition: transform 0.4s ease;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.15));
        }

        .logo-container img:hover {
            transform: scale(1.1) rotate(5deg);
        }

        h2 {
            text-align: center;
            margin-bottom: 25px;
            font-size: 28px;
            color: #0057cc;
            font-weight: 600;
            position: relative;
            padding-bottom: 10px;
        }
        
        h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 3px;
            background: linear-gradient(to right, #0066ff, #00a1ff);
            border-radius: 2px;
        }

        .mensaje {
            text-align: center;
            margin-bottom: 20px;
            border-radius: 8px;
            padding: 12px;
            font-weight: 500;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .mensaje.error {
            background-color: #fff0f0;
            color: #e60000;
            border-left: 4px solid #ff3333;
        }

        .form-group {
            margin-bottom: 24px;
            position: relative;
        }

        .form-group label {
            margin-bottom: 8px;
            display: block;
            font-weight: 600;
            color: #444;
            font-size: 15px;
            transition: color 0.3s;
        }
        
        .form-group:focus-within label {
            color: #0066ff;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e1e5e8;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background-color: #f9fafc;
        }

        .form-group input:focus {
            outline: none;
            border-color: #0066ff;
            background-color: #fff;
            box-shadow: 0 0 0 4px rgba(0, 102, 255, 0.1);
        }
        
        .form-group input::placeholder {
            color: #aab0b7;
        }

        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(to right, #0066ff, #0050cc);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 10px rgba(0, 102, 255, 0.2);
        }

        button:hover {
            background: linear-gradient(to right, #0050cc, #003d99);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 102, 255, 0.3);
        }
        
        button:active {
            transform: translateY(1px);
            box-shadow: 0 2px 6px rgba(0, 102, 255, 0.2);
        }
        
        button i {
            font-size: 18px;
        }

        .links {
            text-align: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .links a {
            color: #0066ff;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
        }

        .links a:hover {
            color: #003d99;
        }
        
        .links a::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -2px;
            left: 0;
            background-color: #0066ff;
            transition: width 0.3s ease;
        }
        
        .links a:hover::after {
            width: 100%;
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 25px 20px;
                width: 95%;
            }
            
            h2 {
                font-size: 24px;
            }
            
            .form-group input {
                padding: 12px 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-container">
            <img src="logosky.png" alt="Logo Sistema">
        </div>
        <h2>Rutero Mercadeo</h2>
        
        <?php if (!empty($mensaje)): ?>
            <div class="mensaje error">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <div class="form-group">
                <label for="usuario"><i class="fas fa-user"></i> Usuario:</label>
                <input type="text" id="usuario" name="usuario" placeholder="Ingresa tu nombre de usuario" required>
            </div>
            
            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Contraseña:</label>
                <input type="password" id="password" name="password" placeholder="Ingresa tu contraseña" required>
            </div>
            
            <div class="form-group">
                <button type="submit"><i class="fas fa-sign-in-alt"></i> Iniciar Sesión</button>
            </div>
            
            <div class="links">
                <a href="register.php">¿No tienes cuenta? Regístrate aquí</a>
            </div>
        </form>
    </div>
</body>
</html>