<?php
// Verificar si la sesión ya está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}
$usuario_id = $_SESSION['usuario_id'] ?? null;
$usuario_nit = $_SESSION['usuario_nit'] ?? null;
$nombre = $_SESSION['nombre'];
$usuario = $_SESSION['usuario'];

// Conexión a la base de datos SAP para obtener preferencias de tema
$serverNameSAP = "HERCULES";
$connectionInfoSAP = array("Database" => "RBOSKY3", "UID" => "sa", "PWD" => "Sky2022*!");

$connSAP = sqlsrv_connect($serverNameSAP, $connectionInfoSAP);

if (!$connSAP) {
    // Si hay error de conexión, usar valores por defecto
    $tema_actual = 'claro';
    $color_principal = 'blue';
} else {
    // Cargar preferencias de tema si el usuario está autenticado
    if (!isset($_SESSION['tema']) || !isset($_SESSION['color_principal'])) {
        // Consultar preferencias del usuario
        $sql = "SELECT tema, color_principal FROM configuracion WHERE usuario_id = ?";
        $params = array($usuario_id);
        $stmt = sqlsrv_query($connSAP, $sql, $params);
        
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $_SESSION['tema'] = $row['tema'];
            $_SESSION['color_principal'] = $row['color_principal'];
        } else {
            // Valores por defecto si no hay preferencias guardadas
            $_SESSION['tema'] = 'claro';
            $_SESSION['color_principal'] = 'blue';
        }
    }
    
    // Asignar variables para uso en la página
    $tema_actual = $_SESSION['tema'];
    $color_principal = $_SESSION['color_principal'];
}

// Función para obtener el valor del color principal
function obtenerValorColor($color) {
    switch($color) {
        case 'blue':
            return '#2563eb';
        case 'purple':
            return '#8b5cf6';
        case 'pink':
            return '#ec4899';
        case 'red':
            return '#ef4444';
        case 'orange':
            return '#f97316';
        case 'green':
            return '#22c55e';
        default:
            return '#2563eb';
    }
}

// Obtener el valor del color principal
$color_principal_valor = obtenerValorColor($color_principal);

// Calcular color más claro para --primary-light
function getLighterColor($hex, $percent) {
    // Convertir hex a RGB
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    
    // Hacer el color más claro
    $r = min(255, $r + floor($percent / 100 * (255 - $r)));
    $g = min(255, $g + floor($percent / 100 * (255 - $g)));
    $b = min(255, $b + floor($percent / 100 * (255 - $b)));
    
    // Convertir de nuevo a hex
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

$color_principal_light = getLighterColor($color_principal_valor, 20);

// Obtener la fecha actual en español
setlocale(LC_TIME, 'es_ES.UTF-8', 'es_ES', 'esp');
$fecha_actual = strftime("%A %d de %B de %Y");
$hora_actual = date("H:i");

// Obtener la página actual para marcar el menú activo
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?php echo htmlspecialchars($tema_actual); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="estilos-globales.css">
    <link rel="stylesheet" href="estilos-adicionales.css">
    <title>Panel de Mercadeo | Sistema de Rutas</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="icon" href="65x45.png" type="image/x-icon">

    <link rel="stylesheet" href="dashboard.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome para iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: <?php echo $color_principal_valor; ?>;
            --primary-light: <?php echo $color_principal_light; ?>;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
         
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                <img src="65x45.png" alt="Ruta" style="margin-left: -9px;">


                </div>
                <button id="sidebar-toggle" class="sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            
            <div class="sidebar-user">
                <div class="avatar">
                    <?php 
                    // Obtener iniciales del nombre
                    $iniciales = '';
                    $palabras = explode(' ', $nombre);
                    foreach ($palabras as $palabra) {
                        if (!empty($palabra)) {
                            $iniciales .= strtoupper(substr($palabra, 0, 1));
                            if (strlen($iniciales) >= 2) break;
                        }
                    }
                    echo $iniciales;
                    ?>
                </div>
                <div class="user-details">
                    <h3><?php echo htmlspecialchars($nombre); ?></h3>
                    <p><?php echo htmlspecialchars($usuario); ?></p>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
<style>
    /* Estilo base para los elementos del menú */
    .sidebar li a {
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
        z-index: 1;
    }
    
    /* Efecto de relleno para los elementos del menú */
    .sidebar li a::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, rgba(255,255,255,0.1), rgba(255,255,255,0.2));
        transition: all 0.5s ease;
        z-index: -1;
    }
    
    .sidebar li a:hover::before {
        left: 0;
        width: 100%;
        background: linear-gradient(90deg, rgba(255,255,255,0.2), rgba(255,255,255,0.3));
    }
    
    /* Efecto adicional al pasar el cursor */
    .sidebar li a:hover {
        color: #ffffff; /* Color del texto al pasar el cursor */
        text-shadow: 0 0 5px rgba(255,255,255,0.5);
    }
    
    .sidebar li a:hover i {
        transform: scale(1.2);
        color: rgb(255, 255, 255); /* Color del icono al pasar el cursor */
    }
</style>

<li class="<?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
    <a href="index.php">
        <i class="fas fa-home"></i>
        <span>Inicio</span>
    </a>
</li>
<style>
    /* Estilo base para los elementos del menú */
    .sidebar li a {
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
        z-index: 1;
    }
    
    /* Efecto de relleno para el elemento Rutas */
    .sidebar li.ruta-menu a::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, rgba(255,255,255,0.1), rgba(255,255,255,0.2));
        transition: all 0.5s ease;
        z-index: -1;
    }
    
    .sidebar li.ruta-menu a:hover::before {
        left: 0;
        width: 100%;
        background: linear-gradient(90deg, rgba(255,255,255,0.2), rgba(255,255,255,0.3));
    }
    
    /* Efecto adicional al pasar el cursor */
    .sidebar li.ruta-menu a:hover {
        color: #ffffff; /* Color del texto al pasar el cursor */
        text-shadow: 0 0 5px rgba(255,255,255,0.5);
    }
    
    .sidebar li.ruta-menu a:hover i {
        transform: scale(1.2);
        color:rgb(255, 255, 255); /* Color del icono al pasar el cursor */
        
    }
</style>
<?php
if ($usuario_id != 1 ):
    ?>
        <li class="<?php echo ($current_page == 'nueva-ruta.php') ? 'active' : ''; ?> ruta-menu">
            <a href="nueva-ruta.php">
            <i class="fa-solid fa-plus"></i>
                
                <span>Registrar ruta</span>
            </a>
        </li>
    <?php endif; ?>


<style>
    /* Estilo base para los elementos del menú */
    .sidebar li a {
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
        z-index: 1;
    }
    
    /* Efecto de relleno para el elemento Rutas */
    .sidebar li.ruta-menu a::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, rgba(255,255,255,0.1), rgba(255,255,255,0.2));
        transition: all 0.5s ease;
        z-index: -1;
    }
    
 
    
    /* Efecto adicional al pasar el cursor */
    .sidebar li.ruta-menu a:hover {
        color: #ffffff; /* Color del texto al pasar el cursor */
        text-shadow: 0 0 5px rgba(255,255,255,0.5);
    }
    
    .sidebar li.ruta-menu a:hover i {
        transform: scale(1.2);
        color:rgb(255, 255, 255); /* Color del icono al pasar el cursor */
       
    }
</style>

<li class="<?php echo ($current_page == 'tabla.php') ? 'active' : ''; ?> ruta-menu">
    <a href="tabla.php">
    <i class="fa-solid fa-eye"></i> 
    
        <span>Ver rutas</span>
    </a>
</li>




                   
                    
                 
                    <li class="<?php echo ($current_page == 'configuracion.php') ? 'active' : ''; ?>">
                        <a href="configuracion.php">
                            <i class="fas fa-cog"></i>
                            <span>Configuración</span>
                        </a>
                    </li>
                </ul>
            </nav>
            
            <div class="sidebar-footer">
                <a href="logout.php" class="logout-link">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Cerrar Sesión</span>
                </a>
            </div>
        </aside>
        
        <!-- Contenido principal -->
        <main class="main-content">
            <header class="main-header">
                <div class="header-left">
                    <h1><?php echo getPageTitle($current_page); ?></h1>
                    <div class="date-time">
                        <i class="far fa-calendar-alt"></i>
                        <span><?php echo ucfirst($fecha_actual); ?> | <?php echo $hora_actual; ?></span>
                    </div>
                </div>
                
                <div class="header-right">
                    <div class="search-box">
                        
                       
                    </div>
                    
                    <div class="notifications">
                        
                            
                            
                        </button>
                       
                    </div>
                </div>
            </header>

<?php
// Función para obtener el título de la página según el archivo actual
function getPageTitle($page) {
    switch ($page) {
        case 'index.php':
            return 'Panel de Control';
        case 'nueva-ruta.php':
            return 'Nueva Ruta';
        case 'tabla.php':
            return 'Ver Rutas';
        case 'nueva-ruta.php':
            return 'Nueva Ruta';
        case 'detalle-ruta.php':
            return 'Detalle de Ruta';
        case 'reportes.php':
            return 'Reportes';
        case 'nuevo-reporte.php':
            return 'Nuevo Reporte';
        case 'clientes.php':
            return 'Gestión de Clientes';
        case 'nuevo-cliente.php':
            return 'Nuevo Cliente';
        case 'tareas.php':
            return 'Gestión de Tareas';
        case 'nueva-tarea.php':
            return 'Nueva Tarea';
        case 'configuracion.php':
            return 'Configuración';
        case 'perfil.php':
            return 'Mi Perfil';
        case 'agendar-visita.php':
            return 'Agendar Visita';
        default:
            return 'Sistema de Gestores';
    }
}
?>