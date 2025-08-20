<?php
// Incluir el archivo de conexión y verificar la sesión
include 'header.php';

// Conexión a la base de datos SAP
$serverNameSAP = "HERCULES";
$connectionInfoSAP = array("Database" => "RBOSKY3", "UID" => "sa", "PWD" => "Sky2022*!");

$connSAP = sqlsrv_connect($serverNameSAP, $connectionInfoSAP);

if (!$connSAP) {
    die("Error conectando a la base de datos SAP: " . print_r(sqlsrv_errors(), true));
}

// Crear tabla de configuracion si no existe
$sql_create_table = "
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='configuracion' AND xtype='U')
BEGIN
    CREATE TABLE configuracion (
        id INT IDENTITY(1,1) PRIMARY KEY,
        usuario_id INT NOT NULL,
        tema VARCHAR(50) DEFAULT 'claro',
        color_principal VARCHAR(50) DEFAULT 'blue',
        notif_email BIT DEFAULT 1,
        notif_sistema BIT DEFAULT 1,
        notif_tareas BIT DEFAULT 1,
        notif_recordatorios BIT DEFAULT 1,
        notif_actualizaciones BIT DEFAULT 1,
        fecha_actualizacion DATETIME DEFAULT GETDATE()
    )
END
";

$stmt_create = sqlsrv_query($connSAP, $sql_create_table);
if ($stmt_create === false) {
    echo "Error al crear la tabla de configuracion: " . print_r(sqlsrv_errors(), true);
}

// Función para obtener datos del usuario actual
function obtenerDatosUsuario() {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: login.php');
        exit;
    }
    
    return [
        'usuario_id' => $_SESSION['usuario_id'],
        'usuario' => $_SESSION['usuario'],
        'nombre' => $_SESSION['nombre']
    ];
}

// Función para obtener preferencias del usuario
function obtenerPreferenciasUsuario($connSAP, $usuario_id) {
    // Consulta para obtener las preferencias del usuario
    $sql = "SELECT * FROM configuracion WHERE usuario_id = ?";
    $params = array($usuario_id);
    $stmt = sqlsrv_query($connSAP, $sql, $params);
    
    $preferencias = [
        'tema' => 'claro',
        'color_principal' => 'blue',
        'notif_email' => 1,
        'notif_sistema' => 1,
        'notif_tareas' => 1,
        'notif_recordatorios' => 1,
        'notif_actualizaciones' => 1
    ];
    
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $preferencias['tema'] = $row['tema'];
        $preferencias['color_principal'] = $row['color_principal'];
        $preferencias['notif_email'] = $row['notif_email'];
        $preferencias['notif_sistema'] = $row['notif_sistema'];
        $preferencias['notif_tareas'] = $row['notif_tareas'];
        $preferencias['notif_recordatorios'] = $row['notif_recordatorios'];
        $preferencias['notif_actualizaciones'] = $row['notif_actualizaciones'];
    } else {
        // Si no existe, crear preferencias por defecto
        $sql_insert = "INSERT INTO configuracion 
                      (usuario_id, tema, color_principal, notif_email, notif_sistema, notif_tareas, notif_recordatorios, notif_actualizaciones) 
                      VALUES (?, 'claro', 'blue', 1, 1, 1, 1, 1)";
        $params_insert = array($usuario_id);
        sqlsrv_query($connSAP, $sql_insert, $params_insert);
    }
    
    return $preferencias;
}

// Función para actualizar el perfil del usuario
function actualizarPerfil($connSAP, $usuario_id, $nuevo_nombre, $nueva_password, $confirmar_password, $password_actual) {
    $mensaje = '';
    $error = '';
    
    // Validar datos
    if (empty($nuevo_nombre)) {
        return ['error' => 'El nombre es obligatorio', 'mensaje' => ''];
    } 
    
    if (!empty($nueva_password) && $nueva_password !== $confirmar_password) {
        return ['error' => 'Las contraseñas no coinciden', 'mensaje' => ''];
    }
    
    // Verificar contraseña actual
    $sql_check = "SELECT password FROM usuarios_ruta WHERE id = ?";
    $params_check = array($usuario_id);
    $stmt_check = sqlsrv_query($connSAP, $sql_check, $params_check);
    
    if ($stmt_check && $row = sqlsrv_fetch_array($stmt_check, SQLSRV_FETCH_ASSOC)) {
        if (password_verify($password_actual, $row['password'])) {
            // Actualizar perfil
            if (!empty($nueva_password)) {
                // Actualizar nombre y contraseña
                $hashed_password = password_hash($nueva_password, PASSWORD_DEFAULT);
                $sql_update = "UPDATE usuarios_ruta SET nombre = ?, password = ? WHERE id = ?";
                $params_update = array($nuevo_nombre, $hashed_password, $usuario_id);
            } else {
                // Actualizar solo nombre
                $sql_update = "UPDATE usuarios_ruta SET nombre = ? WHERE id = ?";
                $params_update = array($nuevo_nombre, $usuario_id);
            }
            
            $stmt_update = sqlsrv_query($connSAP, $sql_update, $params_update);
            
            if ($stmt_update) {
                $_SESSION['nombre'] = $nuevo_nombre;
                return ['error' => '', 'mensaje' => 'Perfil actualizado correctamente', 'nombre' => $nuevo_nombre];
            } else {
                return ['error' => 'Error al actualizar el perfil: ' . print_r(sqlsrv_errors(), true), 'mensaje' => ''];
            }
        } else {
            return ['error' => 'La contraseña actual es incorrecta', 'mensaje' => ''];
        }
    } else {
        return ['error' => 'Error al verificar la contraseña: ' . print_r(sqlsrv_errors(), true), 'mensaje' => ''];
    }
}

// Función para actualizar notificaciones
function actualizarNotificaciones($connSAP, $usuario_id, $datos) {
    $notif_email = isset($datos['notificaciones_email']) ? 1 : 0;
    $notif_sistema = isset($datos['notificaciones_sistema']) ? 1 : 0;
    $notif_tareas = isset($datos['notif_nuevas_tareas']) ? 1 : 0;
    $notif_recordatorios = isset($datos['notif_recordatorios']) ? 1 : 0;
    $notif_actualizaciones = isset($datos['notif_actualizaciones']) ? 1 : 0;
    
    // Verificar si ya existen preferencias para este usuario
    $sql_check = "SELECT COUNT(*) AS count FROM configuracion WHERE usuario_id = ?";
    $params_check = array($usuario_id);
    $stmt_check = sqlsrv_query($connSAP, $sql_check, $params_check);
    
    if ($stmt_check && $row = sqlsrv_fetch_array($stmt_check, SQLSRV_FETCH_ASSOC)) {
        if ($row['count'] > 0) {
            // Actualizar preferencias existentes
            $sql = "UPDATE configuracion SET 
                    notif_email = ?, 
                    notif_sistema = ?, 
                    notif_tareas = ?, 
                    notif_recordatorios = ?, 
                    notif_actualizaciones = ?,
                    fecha_actualizacion = GETDATE()
                    WHERE usuario_id = ?";
        } else {
            // Insertar nuevas preferencias
            $sql = "INSERT INTO configuracion 
                    (usuario_id, notif_email, notif_sistema, notif_tareas, notif_recordatorios, notif_actualizaciones) 
                    VALUES (?, ?, ?, ?, ?, ?)";
        }
        
        $params = array($notif_email, $notif_sistema, $notif_tareas, 
                        $notif_recordatorios, $notif_actualizaciones, $usuario_id);
        
        $stmt = sqlsrv_query($connSAP, $sql, $params);
        
        if ($stmt) {
            return ['error' => '', 'mensaje' => 'Preferencias de notificaciones actualizadas correctamente'];
        } else {
            return ['error' => 'Error al guardar las preferencias de notificaciones: ' . print_r(sqlsrv_errors(), true), 'mensaje' => ''];
        }
    } else {
        return ['error' => 'Error al verificar las preferencias existentes: ' . print_r(sqlsrv_errors(), true), 'mensaje' => ''];
    }
}

// Función para actualizar apariencia
function actualizarApariencia($connSAP, $usuario_id, $tema, $color_principal = 'blue') {
    // Verificar si ya existen preferencias para este usuario
    $sql_check = "SELECT COUNT(*) AS count FROM configuracion WHERE usuario_id = ?";
    $params_check = array($usuario_id);
    $stmt_check = sqlsrv_query($connSAP, $sql_check, $params_check);
    
    if ($stmt_check && $row = sqlsrv_fetch_array($stmt_check, SQLSRV_FETCH_ASSOC)) {
        if ($row['count'] > 0) {
            // Actualizar preferencias existentes
            $sql = "UPDATE configuracion SET tema = ?, color_principal = ?, fecha_actualizacion = GETDATE() WHERE usuario_id = ?";
        } else {
            // Insertar nuevas preferencias
            $sql = "INSERT INTO configuracion (usuario_id, tema, color_principal) VALUES (?, ?, ?)";
        }
        
        $params = array($tema, $color_principal, $usuario_id);
        $stmt = sqlsrv_query($connSAP, $sql, $params);
        
        if ($stmt) {
            // Guardar tema en sesión para uso inmediato
            $_SESSION['tema'] = $tema;
            $_SESSION['color_principal'] = $color_principal;
            return [
                'error' => '', 
                'mensaje' => 'Preferencias de apariencia actualizadas correctamente', 
                'tema' => $tema,
                'color_principal' => $color_principal
            ];
        } else {
            return ['error' => 'Error al guardar las preferencias de apariencia: ' . print_r(sqlsrv_errors(), true), 'mensaje' => ''];
        }
    } else {
        return ['error' => 'Error al verificar las preferencias existentes: ' . print_r(sqlsrv_errors(), true), 'mensaje' => ''];
    }
}

// Función para obtener iniciales del nombre
function obtenerIniciales($nombre) {
    $iniciales = '';
    $palabras = explode(' ', $nombre);
    foreach ($palabras as $palabra) {
        if (!empty($palabra)) {
            $iniciales .= strtoupper(substr($palabra, 0, 1));
            if (strlen($iniciales) >= 2) break;
        }
    }
    return $iniciales;
}

// Procesar formularios
$mensaje = '';
$error = '';
$datos_usuario = obtenerDatosUsuario();
$usuario_id = $datos_usuario['usuario_id'];
$usuario = $datos_usuario['usuario'];
$nombre = $datos_usuario['nombre'];

// Obtener preferencias del usuario
$preferencias = obtenerPreferenciasUsuario($connSAP, $usuario_id);
$tema_actual = $preferencias['tema'];
$color_principal = $preferencias['color_principal'];
$notif_email = $preferencias['notif_email'];
$notif_sistema = $preferencias['notif_sistema'];
$notif_tareas = $preferencias['notif_tareas'];
$notif_recordatorios = $preferencias['notif_recordatorios'];
$notif_actualizaciones = $preferencias['notif_actualizaciones'];

// Procesar cambio de tema por AJAX
if (isset($_POST['ajax_cambiar_tema'])) {
    $nuevo_tema = $_POST['tema'];
    $nuevo_color = isset($_POST['color_principal']) ? $_POST['color_principal'] : $color_principal;
    $resultado = actualizarApariencia($connSAP, $usuario_id, $nuevo_tema, $nuevo_color);
    
    // Devolver respuesta JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => empty($resultado['error']),
        'mensaje' => empty($resultado['error']) ? $resultado['mensaje'] : $resultado['error'],
        'tema' => $nuevo_tema,
        'color_principal' => $nuevo_color
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Procesar formulario de actualización de perfil
    if (isset($_POST['actualizar_perfil'])) {
        $nuevo_nombre = trim($_POST['nombre']);
        $nueva_password = trim($_POST['nueva_password']);
        $confirmar_password = trim($_POST['confirmar_password']);
        $password_actual = trim($_POST['password_actual']);
        
        $resultado = actualizarPerfil($connSAP, $usuario_id, $nuevo_nombre, $nueva_password, $confirmar_password, $password_actual);
        $error = $resultado['error'];
        $mensaje = $resultado['mensaje'];
        if (isset($resultado['nombre'])) {
            $nombre = $resultado['nombre'];
        }
    }
    
    // Procesar formulario de configuración de notificaciones
    if (isset($_POST['actualizar_notificaciones'])) {
        $resultado = actualizarNotificaciones($connSAP, $usuario_id, $_POST);
        $error = $resultado['error'];
        $mensaje = $resultado['mensaje'];
        
        // Actualizar variables locales si la actualización fue exitosa
        if (empty($error)) {
            $notif_email = isset($_POST['notificaciones_email']) ? 1 : 0;
            $notif_sistema = isset($_POST['notificaciones_sistema']) ? 1 : 0;
            $notif_tareas = isset($_POST['notif_nuevas_tareas']) ? 1 : 0;
            $notif_recordatorios = isset($_POST['notif_recordatorios']) ? 1 : 0;
            $notif_actualizaciones = isset($_POST['notif_actualizaciones']) ? 1 : 0;
        }
    }
    
    // Procesar formulario de configuración de apariencia
    if (isset($_POST['actualizar_apariencia'])) {
        $tema = $_POST['tema'];
        $nuevo_color = isset($_POST['color_principal']) ? $_POST['color_principal'] : $color_principal;
        $resultado = actualizarApariencia($connSAP, $usuario_id, $tema, $nuevo_color);
        $error = $resultado['error'];
        $mensaje = $resultado['mensaje'];
        if (isset($resultado['tema'])) {
            $tema_actual = $resultado['tema'];
            $color_principal = $resultado['color_principal'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es" data-theme="<?php echo htmlspecialchars($tema_actual); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Usuario</title>
    <link rel="stylesheet" href="estilos-adicionales.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-light: #3b82f6;
            --text-color: #1e293b;
            --text-light: #64748b;
            --bg-color: #f1f5f9;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --transition: all 0.3s ease;
        }
        
        /* Tema oscuro */
        [data-theme="oscuro"] {
            --primary-color: #3b82f6;
            --primary-light: #60a5fa;
            --text-color: #e2e8f0;
            --text-light: #94a3b8;
            --bg-color: #1e293b;
            --card-bg: #0f172a;
            --border-color: #334155;
        }
        
        /* Tema sistema (detecta automáticamente) */
        @media (prefers-color-scheme: dark) {
            [data-theme="sistema"] {
                --primary-color: #3b82f6;
                --primary-light: #60a5fa;
                --text-color: #e2e8f0;
                --text-light: #94a3b8;
                --bg-color: #1e293b;
                --card-bg: #0f172a;
                --border-color: #334155;
            }
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: var(--transition);
            margin: 0;
            padding: 20px;
        }
        
        .config-container {
            display: flex;
            gap: 30px;
            margin-bottom: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .config-sidebar {
            width: 250px;
            flex-shrink: 0;
        }
        
        .config-nav {
            background-color: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }
        
        .config-nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px 20px;
            color: var(--text-color);
            text-decoration: none;
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition);
        }
        
        .config-nav-item:last-child {
            border-bottom: none;
        }
        
        .config-nav-item:hover {
            background-color: var(--bg-color);
        }
        
        .config-nav-item.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .config-content {
            flex: 1;
            min-width: 0;
        }
        
        .config-section {
            display: none;
            background-color: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            padding: 30px;
        }
        
        .config-section.active {
            display: block;
        }
        
        .section-header {
            margin-bottom: 30px;
        }
        
        .section-header h2 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 10px;
            font-size: 24px;
            color: var(--primary-color);
        }
        
        .section-header p {
            color: var(--text-light);
            margin: 0;
        }
        
        .user-profile {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }
        
        .profile-avatar {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .avatar-large {
            width: 80px;
            height: 80px;
            background-color: var(--primary-light);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 32px;
            color: white;
        }
        
        .avatar-info h3 {
            margin: 0 0 5px;
            font-size: 20px;
        }
        
        .avatar-info p {
            margin: 0;
            color: var(--text-light);
        }
        
        .profile-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .form-group label {
            font-weight: 500;
        }
        
        .form-group input, 
        .form-group select {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: var(--bg-color);
            color: var(--text-color);
            font-size: 16px;
            transition: var(--transition);
        }
        
        .form-group input:focus, 
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }
        
        .form-group small {
            color: var(--text-light);
            font-size: 14px;
        }
        
        .form-divider {
            display: flex;
            align-items: center;
            margin: 10px 0;
        }
        
        .form-divider::before,
        .form-divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background-color: var(--border-color);
        }
        
        .form-divider span {
            padding: 0 15px;
            color: var(--text-light);
            font-size: 14px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        
        .theme-preview {
            display: flex;
            gap: 20px;
            margin: 20px 0;
        }
        
        .theme-option {
            text-align: center;
            cursor: pointer;
        }
        
        .theme-sample {
            width: 150px;
            height: 100px;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 10px;
            border: 2px solid transparent;
            position: relative;
        }
        
        .theme-option.active .theme-sample {
            border-color: var(--primary-color);
        }
        
        .light-theme {
            background-color: #f8fafc;
        }
        
        .dark-theme {
            background-color: #1e293b;
        }
        
        .sample-header {
            height: 20px;
            background-color: #2563eb;
        }
        
        .dark-theme .sample-header {
            background-color: #0f172a;
        }
        
        .sample-sidebar {
            position: absolute;
            left: 0;
            top: 20px;
            bottom: 0;
            width: 30px;
            background-color: #0f172a;
        }
        
        .dark-theme .sample-sidebar {
            background-color: #0f172a;
            border-right: 1px solid #334155;
        }
        
        .sample-content {
            position: absolute;
            left: 30px;
            top: 20px;
            right: 0;
            bottom: 0;
            background-color: #ffffff;
        }
        
        .dark-theme .sample-content {
            background-color: #1e293b;
        }
        
        .info-card {
            display: flex;
            align-items: center;
            gap: 20px;
            background-color: rgba(59, 130, 246, 0.1);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .info-icon {
            font-size: 24px;
            color: var(--primary-color);
        }
        
        .info-content h3 {
            margin: 0 0 5px;
            font-size: 18px;
        }
        
        .info-content p {
            margin: 0;
        }
        
        .security-options {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .security-option {
            background-color: var(--bg-color);
            border-radius: 12px;
            padding: 20px;
        }
        
        .security-option h3 {
            margin: 0 0 10px;
            font-size: 18px;
        }
        
        .security-option p {
            margin: 0 0 15px;
        }
        
        .help-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .help-card {
            display: flex;
            gap: 15px;
            background-color: var(--bg-color);
            border-radius: 12px;
            padding: 20px;
        }
        
        .help-icon {
            font-size: 24px;
            color: var(--primary-color);
        }
        
        .help-content h3 {
            margin: 0 0 10px;
            font-size: 18px;
        }
        
        .help-content p {
            margin: 0 0 15px;
        }
        
        .system-info {
            background-color: var(--bg-color);
            border-radius: 12px;
            padding: 20px;
        }
        
        .system-info h3 {
            margin: 0 0 15px;
            font-size: 18px;
        }
        
        .info-item {
            display: flex;
            margin-bottom: 10px;
        }
        
        .info-label {
            width: 150px;
            font-weight: 500;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-light);
        }
        
        .btn-secondary {
            background-color: var(--bg-color);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-secondary:hover {
            background-color: var(--border-color);
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background-color: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .alert-danger {
            background-color: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        /* Colores personalizados */
        .color-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .color-option {
            text-align: center;
            cursor: pointer;
        }
        
        .color-sample {
            height: 50px;
            border-radius: 8px;
            margin-bottom: 8px;
            border: 2px solid transparent;
        }
        
        .color-option.active .color-sample {
            border-color: var(--text-color);
        }
        
        .color-blue { background-color: #2563eb; }
        .color-purple { background-color: #8b5cf6; }
        .color-pink { background-color: #ec4899; }
        .color-red { background-color: #ef4444; }
        .color-orange { background-color: #f97316; }
        .color-green { background-color: #22c55e; }
        
        @media (max-width: 992px) {
            .config-container {
                flex-direction: column;
            }
            
            .config-sidebar {
                width: 100%;
            }
            
            .config-nav {
                display: flex;
                flex-wrap: wrap;
            }
            
            .config-nav-item {
                flex: 1;
                min-width: 150px;
                justify-content: center;
                border-bottom: none;
                border-right: 1px solid var(--border-color);
            }
            
            .config-nav-item:last-child {
                border-right: none;
            }
        }
        
        @media (max-width: 768px) {
            .help-options {
                grid-template-columns: 1fr;
            }
            
            .config-section {
                padding: 20px;
            }
            
            .color-options {
                grid-template-columns: repeat(3, 1fr);
            }
        }
  

     
        /* Estilos para notificaciones tipo WhatsApp */
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            width: 300px;
        }

        .notification {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            margin-bottom: 10px;
            overflow: hidden;
            transform: translateX(120%);
            transition: transform 0.4s ease;
            animation: slideIn 0.4s forwards;
        }

        .notification.hide {
            animation: slideOut 0.4s forwards;
        }

        @keyframes slideIn {
            to {
                transform: translateX(0);
            }
        }

        @keyframes slideOut {
            to {
                transform: translateX(120%);
            }
        }

        .notification-header {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            background-color: #075e54; /* Color de WhatsApp */
            color: white;
        }

        .notification-icon {
            width: 24px;
            height: 24px;
            margin-right: 10px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: white;
            color: #075e54;
        }

        .notification-title {
            font-weight: bold;
            flex-grow: 1;
        }

        .notification-close {
            cursor: pointer;
            padding: 5px;
        }

        .notification-body {
            padding: 12px 15px;
            border-left: 5px solid #25d366; /* Color verde de WhatsApp */
        }

        .notification-message {
            margin-bottom: 5px;
        }

        .notification-time {
            font-size: 0.75rem;
            color:rgb(0, 0, 0);
            text-align: right;
        }

        /* Diseño responsivo */
        @media (max-width: 600px) {
            .notification-container {
                width: calc(100% - 40px);
            }
        }
    </style>
</head>
<body>

<div class="config-container">
    <div class="config-sidebar">
        <div class="config-nav">
            <a href="#perfil" class="config-nav-item active" data-target="perfil">
                <i class="fas fa-user"></i> Perfil
            </a>
            <a href="#notificaciones" class="config-nav-item" data-target="notificaciones">
                <i class="fas fa-bell"></i> Notificaciones
            </a>
            <a href="#apariencia" class="config-nav-item" data-target="apariencia">
                <i class="fas fa-paint-brush"></i> Apariencia
            </a>
            <a href="#seguridad" class="config-nav-item" data-target="seguridad">
                <i class="fas fa-shield-alt"></i> Seguridad
            </a>
            <a href="#ayuda" class="config-nav-item" data-target="ayuda">
                <i class="fas fa-question-circle"></i> Ayuda
            </a>
        </div>
    </div>
    
    <div class="config-content">
        <?php if ($mensaje): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Sección de Perfil -->
        <div class="config-section active" id="perfil">
            <div class="section-header">
                <h2><i class="fas fa-user"></i> Perfil de Usuario</h2>
                <p>Actualiza tu información personal y contraseña</p>
            </div>
            
            <div class="user-profile">
                <div class="profile-avatar">
                    <div class="avatar-large">
                        <?php echo obtenerIniciales($nombre); ?>
                    </div>
                    <div class="avatar-info">
                        <h3><?php echo htmlspecialchars($nombre); ?></h3>
                        <p><?php echo htmlspecialchars($usuario); ?></p>
                    </div>
                </div>
                
                <form method="POST" action="" class="profile-form">
                    <div class="form-group">
                        <label for="nombre">Nombre Completo</label>
                        <input type="text" id="nombre" name="nombre" value="<?php echo htmlspecialchars($nombre); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="usuario">Nombre de Usuario</label>
                        <input type="text" id="usuario" value="<?php echo htmlspecialchars($usuario); ?>" disabled>
                        <small>El nombre de usuario no se puede cambiar</small>
                    </div>
                    
                    <div class="form-divider">
                        <span>Cambiar Contraseña</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="nueva_password">Nueva Contraseña</label>
                        <input type="password" id="nueva_password" name="nueva_password">
                        <small>Dejar en blanco para mantener la contraseña actual</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirmar_password">Confirmar Nueva Contraseña</label>
                        <input type="password" id="confirmar_password" name="confirmar_password">
                    </div>
                    
                    <div class="form-group">
                        <label for="password_actual">Contraseña Actual <span class="required">*</span></label>
                        <input type="password" id="password_actual" name="password_actual" required>
                        <small>Requerida para confirmar los cambios</small>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" name="actualizar_perfil" class="btn-primary">
                            <i class="fas fa-save"></i> Guardar Cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Sección de Notificaciones -->
        <div class="container">
    

        <div class="config-section" id="notificaciones">
            <div class="section-header">
                <h2><i class="fas fa-bell"></i> Configuración de Notificaciones</h2>
                <p>Personaliza cómo y cuándo recibes notificaciones</p>
            </div>
            
            <form id="notificacionesForm">
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="notificaciones_sistema" name="notificaciones_sistema" checked>
                    <label for="notificaciones_sistema">Mostrar notificaciones en el sistema</label>
                </div>
                
                <div class="form-divider">
                    <span>Tipos de Notificaciones</span>
                </div>
                
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="notif_nuevas_tareas" name="notif_nuevas_tareas" checked>
                    <label for="notif_nuevas_tareas">Nuevas tareas asignadas</label>
                </div>
                
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="notif_recordatorios" name="notif_recordatorios" checked>
                    <label for="notif_recordatorios">Recordatorios de tareas pendientes</label>
                </div>
                
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="notif_actualizaciones" name="notif_actualizaciones" checked>
                    <label for="notif_actualizaciones">Actualizaciones de rutas</label>
                </div>
                
                <div class="form-actions">
                    <button type="submit" id="guardarPreferencias" class="btn-primary">
                        <i class="fas fa-save"></i> Guardar Preferencias
                    </button>
                </div>
            </form>
        </div>

        <!-- Sección para probar las notificaciones -->
        <div class="config-section">
            <div class="section-header">
                <h2><i class="fas fa-flask"></i> Probar Notificaciones</h2>
                <p>Envía notificaciones de prueba para ver cómo funcionan</p>
            </div>
            
            <div class="form-actions" style="text-align: center;">
                <button id="testTarea" class="btn-primary" style="margin-right: 10px;">
                    <i class="fas fa-tasks"></i> Nueva Tarea
                </button>
                <button id="testRecordatorio" class="btn-primary" style="margin-right: 10px;">
                    <i class="fas fa-clock"></i> Recordatorio
                </button>
                <button id="testActualizacion" class="btn-primary">
                    <i class="fas fa-route"></i> Actualización
                </button>
            </div>
        </div>
    </div>
        <div class="notification-container" id="notificationContainer"></div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Cargar configuración guardada
        loadNotificationSettings();
        
        // Manejar el formulario de configuración
        document.getElementById('notificacionesForm').addEventListener('submit', (e) => {
            e.preventDefault();
            saveNotificationSettings();
            showNotification('Sistema', 'Preferencias de notificaciones guardadas correctamente.', 'fas fa-cog');
        });
        
        // Configurar botones de prueba
        document.getElementById('testTarea').addEventListener('click', () => {
            if (document.getElementById('notificaciones_sistema').checked && 
                document.getElementById('notif_nuevas_tareas').checked) {
                showNotification('Nueva Tarea', 'Se te ha asignado una nueva tarea: "Completar informe mensual".', 'fas fa-tasks');
            } else {
                alert('Las notificaciones de tareas están desactivadas. Actívalas en la configuración.');
            }
        });
        
        document.getElementById('testRecordatorio').addEventListener('click', () => {
            if (document.getElementById('notificaciones_sistema').checked && 
                document.getElementById('notif_recordatorios').checked) {
                showNotification('Recordatorio', 'Tienes una tarea pendiente: "Reunión con el equipo" a las 15:00.', 'fas fa-clock');
            } else {
                alert('Los recordatorios están desactivados. Actívalos en la configuración.');
            }
        });
        
        document.getElementById('testActualizacion').addEventListener('click', () => {
            if (document.getElementById('notificaciones_sistema').checked && 
                document.getElementById('notif_actualizaciones').checked) {
                showNotification('Actualización de Ruta', 'Se ha modificado tu ruta de entrega. Revisala ahora.', 'fas fa-route');
            } else {
                alert('Las actualizaciones de rutas están desactivadas. Actívalas en la configuración.');
            }
        });
        
        // Solicitar permisos para notificaciones nativas
        requestNotificationPermission();
    });

    // Función para mostrar notificaciones tipo WhatsApp
    function showNotification(title, message, iconClass) {
        const notificationContainer = document.getElementById('notificationContainer');
        const notification = document.createElement('div');
        notification.className = 'notification';
        
        const now = new Date();
        const timeString = now.getHours().toString().padStart(2, '0') + ':' + 
                          now.getMinutes().toString().padStart(2, '0');
        
        notification.innerHTML = `
            <div class="notification-header">
                <div class="notification-icon">
                    <i class="${iconClass}"></i>
                </div>
                <div class="notification-title">${title}</div>
                <div class="notification-close">
                    <i class="fas fa-times"></i>
                </div>
            </div>
            <div class="notification-body">
                <div class="notification-message">${message}</div>
                <div class="notification-time">${timeString}</div>
            </div>
        `;
        
        notificationContainer.appendChild(notification);
        
        // Manejar el cierre de la notificación
        notification.querySelector('.notification-close').addEventListener('click', () => {
            notification.classList.add('hide');
            setTimeout(() => {
                notificationContainer.removeChild(notification);
            }, 400);
        });
        
        // Auto-cerrar después de 5 segundos
        setTimeout(() => {
            if (notification.parentNode === notificationContainer) {
                notification.classList.add('hide');
                setTimeout(() => {
                    if (notification.parentNode === notificationContainer) {
                        notificationContainer.removeChild(notification);
                    }
                }, 400);
            }
        }, 5000);
        
        // También mostrar notificación nativa si está disponible
        showNativeNotification(title, message);
    }

    // Función para solicitar permiso y mostrar notificaciones nativas
    function requestNotificationPermission() {
        if ('Notification' in window) {
            if (Notification.permission !== 'granted' && Notification.permission !== 'denied') {
                Notification.requestPermission();
            }
        }
    }

    // Función para mostrar notificaciones nativas (del sistema)
    function showNativeNotification(title, message) {
        if ('Notification' in window && Notification.permission === 'granted') {
            const notification = new Notification(title, {
                body: message,
                icon: 'https://via.placeholder.com/64',
                badge: 'https://via.placeholder.com/24'
            });
            
            notification.onclick = function() {
                window.focus();
                notification.close();
            };
        }
    }

    // Guardar configuración en localStorage
    function saveNotificationSettings() {
        const settings = {
            sistema: document.getElementById('notificaciones_sistema').checked,
            nuevasTareas: document.getElementById('notif_nuevas_tareas').checked,
            recordatorios: document.getElementById('notif_recordatorios').checked,
            actualizaciones: document.getElementById('notif_actualizaciones').checked
        };
        
        localStorage.setItem('notificationSettings', JSON.stringify(settings));
    }

    // Cargar configuración desde localStorage
    function loadNotificationSettings() {
        const savedSettings = localStorage.getItem('notificationSettings');
        
        if (savedSettings) {
            const settings = JSON.parse(savedSettings);
            
            document.getElementById('notificaciones_sistema').checked = settings.sistema;
            document.getElementById('notif_nuevas_tareas').checked = settings.nuevasTareas;
            document.getElementById('notif_recordatorios').checked = settings.recordatorios;
            document.getElementById('notif_actualizaciones').checked = settings.actualizaciones;
        }
    }
</script>
        
        <!-- Sección de Apariencia -->
        <div class="config-section" id="apariencia">
            <div class="section-header">
                <h2><i class="fas fa-paint-brush"></i> Configuración de Apariencia</h2>
                <p>Personaliza la apariencia de tu panel de control</p>
            </div>
            
            <form method="POST" action="" id="form-apariencia">
                <div class="form-group">
                    <label for="tema">Tema</label>
                    <select id="tema" name="tema">
                        <option value="claro" <?php echo $tema_actual == 'claro' ? 'selected' : ''; ?>>Claro</option>
                        <option value="oscuro" <?php echo $tema_actual == 'oscuro' ? 'selected' : ''; ?>>Oscuro</option>
                        <option value="sistema" <?php echo $tema_actual == 'sistema' ? 'selected' : ''; ?>>Usar configuración del sistema</option>
                    </select>
                </div>
                
                <div class="theme-preview">
                    <div class="theme-option <?php echo $tema_actual == 'claro' ? 'active' : ''; ?>" data-theme="claro">
                        <div class="theme-sample light-theme">
                            <div class="sample-header"></div>
                            <div class="sample-sidebar"></div>
                            <div class="sample-content"></div>
                        </div>
                        <span>Claro</span>
                    </div>
                    
                    <div class="theme-option <?php echo $tema_actual == 'oscuro' ? 'active' : ''; ?>" data-theme="oscuro">
                        <div class="theme-sample dark-theme">
                            <div class="sample-header"></div>
                            <div class="sample-sidebar"></div>
                            <div class="sample-content"></div>
                        </div>
                        <span>Oscuro</span>
                    </div>
                </div>
 
                
                <input type="hidden" name="color_principal" id="color_principal" value="<?php echo htmlspecialchars($color_principal); ?>">
                
                <div class="form-actions">
                    <button type="submit" name="actualizar_apariencia" class="btn-primary">
                        <i class="fas fa-save"></i> Guardar Preferencias
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Sección de Seguridad -->
        <div class="config-section" id="seguridad">
            <div class="section-header">
                <h2><i class="fas fa-shield-alt"></i> Seguridad</h2>
                <p>Configura opciones de seguridad para tu cuenta</p>
            </div>
            
            <div class="info-card">
                <div class="info-icon">
                    <i class="fas fa-info-circle"></i>
                </div>
                <div class="info-content">
                    <h3>Información de Seguridad</h3>
                    <p>Tu última sesión fue iniciada el <?php echo date('d/m/Y H:i'); ?> desde la dirección IP <?php echo $_SERVER['REMOTE_ADDR']; ?>.</p>
                </div>
            </div>
            
            <div class="security-options">
                <div class="security-option">
                    <h3>Sesiones Activas</h3>
                    <p>Actualmente tienes 1 sesión activa (esta sesión).</p>
                    <button class="btn-secondary" id="cerrar-sesiones">
    <i class="fas fa-sign-out-alt"></i> Cerrar Todas las Sesiones
</button>

<!-- Agregar el script de SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>



<script>
document.getElementById('cerrar-sesiones').addEventListener('click', function() {
    // Mostrar la confirmación "¿Estás seguro?"
    Swal.fire({
        title: '¿Estás seguro?',
        text: 'Cerrarás todas las sesiones activas.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cerrar sesiones',
        cancelButtonText: 'No, cancelar',
    }).then((result) => {
        if (result.isConfirmed) {
            let segundos = 3; // Cuenta regresiva inicial

            // Muestra una alerta con la cuenta regresiva
            const timerInterval = setInterval(() => {
                if (segundos > 0) {
                    Swal.fire({
                        title: 'Cerrando sesión...',
                        html: `Serás redirigido en <b>${segundos}</b> segundos.`,
                        timer: 1000,
                        timerProgressBar: true,
                        didOpen: () => {
                            Swal.showLoading();
                        },
                    });
                    segundos--;
                } else {
                    clearInterval(timerInterval);

                    // Muestra la alerta final de redirección
                    Swal.fire({
                        icon: 'success',
                        title: '¡Sesiones cerradas!',
                        text: 'Serás redirigido al inicio de sesión.',
                        showConfirmButton: false,
                        timer: 1500
                    });

                    // Redirige después de 1.5 segundos
                    setTimeout(() => {
                        window.location.href = 'logout.php';
                    }, 1500);
                }
            }, 1000);
        } else {
            // Si el usuario cancela, muestra este mensaje
            Swal.fire({
                icon: 'info',
                title: 'Operación cancelada',
                text: 'No se han cerrado las sesiones.',
            });
        }
    });
});


</script>

                </div>
                
                <div class="security-option">
                    <h3>Historial de Acceso</h3>
                    <p>Puedes revisar el historial de acceso a tu cuenta.</p>
                    <button class="btn-secondary" id="ver-historial">
                        <i class="fas fa-history"></i> Ver Historial
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Sección de Ayuda -->
        <div class="config-section" id="ayuda">
            <div class="section-header">
                <h2><i class="fas fa-question-circle"></i> Ayuda y Soporte</h2>
                <p>Encuentra respuestas a tus preguntas y obtén soporte</p>
            </div>
            
            <div class="help-options">
                <div class="help-card">
                    <div class="help-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="help-content">
                        <h3>Manual de Usuario</h3>
                        <p>Consulta el manual completo del sistema de Gestores.</p>
                        <a href="#" class="btn-secondary">
                            <i class="fas fa-external-link-alt"></i> Ver Manual
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="system-info">
                <h3>Información del Sistema</h3>
                <div class="info-item">
                    <span class="info-label">Versión:</span>
                    <span class="info-value">1.0.0</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Última Actualización:</span>
                    <span class="info-value"><?php echo date('d/m/Y'); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Aplicar color principal al cargar la página
        aplicarColorPrincipal('<?php echo $color_principal; ?>');
        
        // Cambiar entre secciones de configuración
        const navItems = document.querySelectorAll('.config-nav-item');
        const sections = document.querySelectorAll('.config-section');
        
        navItems.forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                
                const targetId = this.getAttribute('data-target');
                
                // Desactivar todos los elementos de navegación y secciones
                navItems.forEach(navItem => navItem.classList.remove('active'));
                sections.forEach(section => section.classList.remove('active'));
                
                // Activar el elemento de navegación y sección seleccionados
                this.classList.add('active');
                document.getElementById(targetId).classList.add('active');
            });
        });
        
        // Selección de tema
        const themeOptions = document.querySelectorAll('.theme-option');
        const themeSelect = document.getElementById('tema');
        
        themeOptions.forEach(option => {
            option.addEventListener('click', function() {
                const theme = this.getAttribute('data-theme');
                themeSelect.value = theme;
                
                // Actualizar selección visual
                themeOptions.forEach(opt => {
                    opt.querySelector('.theme-sample').style.borderColor = 'transparent';
                    opt.classList.remove('active');
                });
                this.querySelector('.theme-sample').style.borderColor = 'var(--primary-color)';
                this.classList.add('active');
                
                // Aplicar tema inmediatamente
                document.documentElement.setAttribute('data-theme', theme);
                
                // Enviar cambio por AJAX
                actualizarTemaAjax(theme, document.getElementById('color_principal').value);
            });
        });
        
        // Selección de color principal
        const colorOptions = document.querySelectorAll('.color-option');
        const colorInput = document.getElementById('color_principal');
        
        colorOptions.forEach(option => {
            option.addEventListener('click', function() {
                const color = this.getAttribute('data-color');
                colorInput.value = color;
                
                // Actualizar selección visual
                colorOptions.forEach(opt => {
                    opt.classList.remove('active');
                });
                this.classList.add('active');
                
                // Aplicar color inmediatamente
                aplicarColorPrincipal(color);
                
                // Enviar cambio por AJAX
                actualizarTemaAjax(themeSelect.value, color);
            });
        });
        
        // Función para aplicar color principal
        function aplicarColorPrincipal(color) {
            let colorValue;
            
            switch(color) {
                case 'blue':
                    colorValue = '#2563eb';
                    break;
                case 'purple':
                    colorValue = '#8b5cf6';
                    break;
                case 'pink':
                    colorValue = '#ec4899';
                    break;
                case 'red':
                    colorValue = '#ef4444';
                    break;
                case 'orange':
                    colorValue = '#f97316';
                    break;
                case 'green':
                    colorValue = '#22c55e';
                    break;
                default:
                    colorValue = '#2563eb';
            }
            
            document.documentElement.style.setProperty('--primary-color', colorValue);
            
            // Calcular color más claro para --primary-light
            const lighterColor = getLighterColor(colorValue, 20);
            document.documentElement.style.setProperty('--primary-light', lighterColor);
        }
        
        // Función para obtener un color más claro
        function getLighterColor(hex, percent) {
            // Convertir hex a RGB
            let r = parseInt(hex.substring(1, 3), 16);
            let g = parseInt(hex.substring(3, 5), 16);
            let b = parseInt(hex.substring(5, 7), 16);
            
            // Hacer el color más claro
            r = Math.min(255, r + Math.floor(percent / 100 * (255 - r)));
            g = Math.min(255, g + Math.floor(percent / 100 * (255 - g)));
            b = Math.min(255, b + Math.floor(percent / 100 * (255 - b)));
            
            // Convertir de nuevo a hex
            return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`;
        }
        
        // Función para actualizar tema por AJAX
        function actualizarTemaAjax(tema, color) {
            const formData = new FormData();
            formData.append('ajax_cambiar_tema', '1');
            formData.append('tema', tema);
            formData.append('color_principal', color);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Mostrar mensaje de éxito
                    mostrarMensaje(data.mensaje, 'success');
                } else {
                    // Mostrar mensaje de error
                    mostrarMensaje(data.mensaje, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                mostrarMensaje('Error al actualizar el tema', 'error');
            });
        }
        
        // Función para mostrar mensajes temporales
        function mostrarMensaje(mensaje, tipo) {
            // Verificar si ya existe una alerta
            let alertaExistente = document.querySelector('.alert');
            if (alertaExistente) {
                alertaExistente.remove();
            }
            
            // Crear nueva alerta
            const alerta = document.createElement('div');
            alerta.className = tipo === 'success' ? 'alert alert-success' : 'alert alert-danger';
            
            const icono = document.createElement('i');
            icono.className = tipo === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
            
            alerta.appendChild(icono);
            alerta.appendChild(document.createTextNode(' ' + mensaje));
            
            // Insertar alerta al principio del contenido
            const contenido = document.querySelector('.config-content');
            contenido.insertBefore(alerta, contenido.firstChild);
            
            // Eliminar después de 3 segundos
            setTimeout(() => {
                alerta.remove();
            }, 3000);
        }
        

        
        document.getElementById('ver-historial').addEventListener('click', function() {
            // Aquí implementarías la lógica para mostrar el historial
            mostrarMensaje('Mostrando historial de acceso...', 'success');
            
            // Ejemplo: Mostrar un modal con historial ficticio
            const historialHTML = `
                <div class="historial-modal">
                    <div class="historial-header">
                        <h3>Historial de Acceso</h3>
                        <button id="cerrar-historial"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="historial-content">
                        <div class="historial-item">
                            <div class="historial-fecha">Hoy, ${new Date().toLocaleTimeString()}</div>
                            <div class="historial-ip">${window.location.hostname}</div>
                            <div class="historial-estado success">Activa</div>
                        </div>
                        <div class="historial-item">
                            <div class="historial-fecha">Ayer, 15:30</div>
                            <div class="historial-ip">192.168.1.1</div>
                            <div class="historial-estado">Cerrada</div>
                        </div>
                        <div class="historial-item">
                            <div class="historial-fecha">12/04/2023, 09:15</div>
                            <div class="historial-ip">192.168.1.1</div>
                            <div class="historial-estado">Cerrada</div>
                        </div>
                    </div>
                </div>
            `;
            
            // Crear modal
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.innerHTML = historialHTML;
            document.body.appendChild(modal);
            
            // Estilos para el modal
            const style = document.createElement('style');
            style.textContent = `
                .modal-overlay {
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background-color: rgba(0, 0, 0, 0.5);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 1000;
                }
                
                .historial-modal {
                    background-color: var(--card-bg);
                    border-radius: 12px;
                    width: 90%;
                    max-width: 600px;
                    max-height: 80vh;
                    overflow-y: auto;
                }
                
                .historial-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 15px 20px;
                    border-bottom: 1px solid var(--border-color);
                }
                
                .historial-header h3 {
                    margin: 0;
                }
                
                .historial-header button {
                    background: none;
                    border: none;
                    font-size: 18px;
                    cursor: pointer;
                    color: var(--text-color);
                }
                
                .historial-content {
                    padding: 20px;
                }
                
                .historial-item {
                    display: flex;
                    justify-content: space-between;
                    padding: 15px;
                    border-bottom: 1px solid var(--border-color);
                }
                
                .historial-item:last-child {
                    border-bottom: none;
                }
                
                .historial-estado.success {
                    color: #22c55e;
                }
            `;
            document.head.appendChild(style);
            
            // Cerrar modal
            document.getElementById('cerrar-historial').addEventListener('click', function() {
                modal.remove();
            });
        });
    });
</script>

</body>
</html>

<?php include 'footer.php'; ?>