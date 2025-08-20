<?Php
include 'header.php';
include 'funciones/estadisticas.php';

// Funciones para SAP
function obtenerNitUsuario($usuario_id, $conn) {
    $sql = "SELECT nit FROM usuarios_ruta WHERE id = ?";
    $params = array($usuario_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        return $row['nit'];
    }
    return null;
}

function conexionSAP() {
    $serverName = "HERCULES";
    $connectionInfo = array("Database" => "RBOSKY3", "UID" => "sa", "PWD" => "Sky2022*!");
    $connSAP = sqlsrv_connect($serverName, $connectionInfo);
    return $connSAP ?: false;
}

function obtenerClientesPorNit($nit, $es_admin = false) {
    $connSAP = conexionSAP();
    $clientes = [];
    if ($connSAP === false) {
        return $clientes;
    }

    if ($es_admin) {
        // Admin: obtener todos los clientes
        $sql = "SELECT DISTINCT T0.[CardCode], T0.[CardFName], T0.[CardName], T1.[SlpCode], T1.[SlpName]
                FROM OCRD T0
                INNER JOIN OSLP T1 ON T0.[SlpCode] = T1.[SlpCode]
                WHERE T0.[validFor] = 'Y'";
        $params = [];
    } else {
        // Usuario normal: solo clientes de su NIT
        $sql = "SELECT DISTINCT T0.[CardCode], T0.[CardFName], T0.[CardName], T1.[SlpCode], T1.[SlpName]
                FROM OCRD T0
                INNER JOIN OSLP T1 ON T0.[SlpCode] = T1.[SlpCode]
                WHERE T0.[validFor] = 'Y' AND T1.[SlpCode] = ?";
        $params = array($nit);
    }

    $stmt = sqlsrv_query($connSAP, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $clientes[] = array(
                'id' => $row['CardCode'],
                'nombre' => $row['CardFName'],
                'nombre1' => $row['CardName'],
                'slpcode' => $row['SlpCode'],
                'slpname' => $row['SlpName']
            );
        }
    }
    sqlsrv_close($connSAP);
    return $clientes;
}

// Función para actualizar el estado de una ruta
function actualizarEstadoRuta($ruta_id, $nuevo_estado) {
    $conn = conectarBaseDatos();
    if ($conn === false) return false;

    $sql = "UPDATE Ruta.dbo.rutas SET estado = ? WHERE id = ?";
    $params = array($nuevo_estado, $ruta_id);
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        sqlsrv_close($conn);
        return false;
    }

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    return true;
}

// Conexión a la base de datos principal
function conectarBaseDatos() {
    $serverName = "HERCULES";
    $connectionInfo = array("Database" => "Ruta", "UID" => "sa", "PWD" => "Sky2022*!");
    $conn = sqlsrv_connect($serverName, $connectionInfo);
    if ($conn === false) {
        error_log("Error al conectar a SQL Server: " . print_r(sqlsrv_errors(), true));
        return false;
    }
    return $conn;
}

// Procesar actualización de estado vía AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_estado') {
    $ruta_id = $_POST['ruta_id'] ?? 0;
    $nuevo_estado = $_POST['estado'] === 'true' ? 'completada' : 'activa';

    $resultado = actualizarEstadoRuta($ruta_id, $nuevo_estado);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => $resultado,
        'message' => $resultado ? 'Estado actualizado correctamente' : 'Error al actualizar el estado',
        'nuevo_estado' => $nuevo_estado
    ]);
    exit;
}

// Obtener datos del usuario
$usuario_id = $_SESSION['usuario_id'];
$es_admin = ($usuario_id == 1);

// Conexión y NIT (solo si no es admin)
$conn = conectarBaseDatos();
$nit = !$es_admin ? obtenerNitUsuario($usuario_id, $conn) : null;

// Obtener clientes (todos si admin, filtrados si usuario normal)
$clientesSAP = obtenerClientesPorNit($nit, $es_admin);
$cantidadClientesSAP = count($clientesSAP);

// Obtener estadísticas y rutas (modificar funciones si quieres lógica diferente para admin)
$estadisticas = obtenerEstadisticasUsuario($usuario_id);
$rutas_activas = obtenerRutasActivas($usuario_id);
$tareas_pendientes = obtenerTareasPendientes($usuario_id);

// Obtener preferencias del usuario para el tema
$tema_actual = $_SESSION['tema'] ?? 'claro';

if ($conn) sqlsrv_close($conn);
?>

<!DOCTYPE html>
<html lang="es" data-theme="<?php echo htmlspecialchars($tema_actual); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistema de Rutas</title>
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
        
        .welcome-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border-color);
        }
        
        .welcome-content h2 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 24px;
            color: var(--primary-color);
        }
        
        .welcome-content p {
            margin: 0;
            color: var(--text-light);
            max-width: 600px;
        }
        
        .welcome-image {
            font-size: 48px;
            color: var(--primary-color);
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border-color);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            background-color: var(--primary-light);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: white;
            font-size: 20px;
        }
        
        .stat-info h3 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        
        .stat-info p {
            margin: 5px 0 0;
            color: var(--text-light);
            font-size: 14px;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .grid-section {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border-color);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .section-header h2 {
            margin: 0;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .view-all {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .routes-list, .tasks-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .route-item, .task-item {
            background-color: var(--bg-color);
            border-radius: 8px;
            padding: 15px;
            display: flex;
            align-items: center;
            border-left: 4px solid var(--primary-color);
        }
        
        .route-info, .task-content {
            flex: 1;
        }
        
        .route-info h3, .task-content h3 {
            margin: 0 0 5px;
            font-size: 16px;
        }
        
        .route-info p, .task-content p {
            margin: 0;
            color: var(--text-light);
            font-size: 14px;
        }
        
        .route-progress {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-right: 15px;
        }
        
        .progress-bar {
            width: 100px;
            height: 8px;
            background-color: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress {
            height: 100%;
            background-color: var(--primary-color);
        }
        
        .route-action {
            color: var(--text-color);
            background-color: var(--card-bg);
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border: 1px solid var(--border-color);
        }
        
        .task-priority {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            margin-right: 15px;
        }
        
        .alta {
            background-color: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .media {
            background-color: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }
        
        .baja {
            background-color: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 30px;
            text-align: center;
            color: var(--text-light);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: var(--border-color);
        }
        
        .empty-state p {
            margin: 0 0 15px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .quick-actions {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border-color);
        }
        
        .quick-actions h2 {
            margin: 0 0 20px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .action-card {
            background-color: var(--bg-color);
            border-radius: 8px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            text-decoration: none;
            color: var(--text-color);
            transition: transform 0.2s;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
        }
        
        .action-card i {
            font-size: 24px;
            margin-bottom: 10px;
            color: var(--primary-color);
        }
        
        @media (max-width: 768px) {
            .welcome-banner {
                flex-direction: column;
                text-align: center;
            }
            
            .welcome-image {
                margin-top: 20px;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="welcome-banner">
    <div class="welcome-content">
        <h2>¡Bienvenido, <?php echo htmlspecialchars($nombre); ?>!</h2>
        <p>Te deseamos un excelente día de trabajo. Aquí encontrarás todo lo que necesitas para gestionar tus rutas de manera eficiente.</p>
    </div>
    <div class="welcome-image">
        <i class="fas fa-map-marked-alt"></i>
    </div>
</div>

<style>
.welcome-banner {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background-color: #4285f4;
    color: white;
    padding: 20px 30px;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    margin: 20px 0;
}

.welcome-content {
    flex: 1;
}

.welcome-content h2 {
    margin: 0 0 10px 0;
    font-size: 24px;
    font-weight: 600;
    color: white;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.welcome-content p {
    margin: 0;
    font-size: 16px;
    color: white;
    opacity: 0.95;
    text-shadow: 0 1px 1px rgba(0, 0, 0, 0.1);
}

.welcome-image {
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 48px;
    margin-left: 20px;
    color: white;
}
</style>

<div class="stats-container">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-route"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $estadisticas['rutas_activas']; ?></h3>
            <p>Rutas Activas</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-store"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $cantidadClientesSAP; ?></h3>
            <p>Clientes SAP</p>
        </div>
    </div>
    
    

</div>

<div class="dashboard-grid">
    

</div>

<div class="quick-actions">
    <h2><i class="fas fa-bolt"></i> Acciones Rápidas</h2>
    <div class="actions-grid">
        <a href="nueva-ruta.php" class="action-card">
            <i class="fas fa-plus-circle"></i>
            <span>Nueva Ruta</span>
        </a>
       
    </div>
</div>

<!-- Estilos para los checkboxes y rutas completadas -->
<style>
    /* Estilos para los checkboxes de rutas */
    .route-checkbox {
        position: relative;
        display: inline-block;
        margin-right: 12px;
    }
    
    .route-checkbox input[type="checkbox"] {
        opacity: 0;
        position: absolute;
        cursor: pointer;
        z-index: 1;
        width: 22px;
        height: 22px;
    }
    
    .route-checkbox label {
        position: relative;
        display: inline-block;
        width: 22px;
        height: 22px;
        border: 2px solid var(--primary-color);
        border-radius: 4px;
        background-color: var(--card-bg);
        cursor: pointer;
    }
    
    .route-checkbox input[type="checkbox"]:checked + label:after {
        content: '\f00c';
        font-family: 'Font Awesome 5 Free';
        font-weight: 900;
        position: absolute;
        top: 0;
        left: 0;
        width: 22px;
        height: 22px;
        text-align: center;
        line-height: 22px;
        color: white;
        background-color: var(--primary-color);
        border-radius: 2px;
    }
    
    /* Estilo para rutas completadas */
    .route-item.route-completed {
        background-color: rgba(16, 185, 129, 0.1);
        border-left: 4px solid #10b981;
    }
    
    [data-theme="oscuro"] .route-item.route-completed {
        background-color: rgba(16, 185, 129, 0.2);
    }
    
    .route-item.route-completed .route-info h3 {
        text-decoration: line-through;
        opacity: 0.7;
    }
    
    /* Modificación del layout de route-item para incluir checkbox */
    .route-item {
        display: flex;
        align-items: center;
        padding: 15px;
    }
    
    /* Notificaciones */
    .notificacion {
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 12px 20px;
        border-radius: 4px;
        color: white;
        font-weight: 500;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        transform: translateY(100px);
        opacity: 0;
        transition: transform 0.3s, opacity 0.3s;
        z-index: 1000;
    }
    
    .notificacion.visible {
        transform: translateY(0);
        opacity: 1;
    }
    
    .notificacion.success {
        background-color: #10b981;
    }
    
    .notificacion.error {
        background-color: #ef4444;
    }
</style>

<!-- Script para manejar la actualización de estado de rutas -->
<script>
    // Función para actualizar el estado de una ruta
    function actualizarEstadoRuta(checkbox) {
        const rutaId = checkbox.getAttribute('data-ruta-id');
        const estaCompletada = checkbox.checked;
        const routeItem = checkbox.closest('.route-item');
        
        // Mostrar indicador de carga
        routeItem.style.opacity = '0.7';
        
        // Crear FormData para enviar los datos
        const formData = new FormData();
        formData.append('accion', 'actualizar_estado');
        formData.append('ruta_id', rutaId);
        formData.append('estado', estaCompletada);
        
        // Enviar solicitud AJAX
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // Restaurar opacidad
            routeItem.style.opacity = '1';
            
            if (data.success) {
                // Actualizar la apariencia del elemento
                if (estaCompletada) {
                    routeItem.classList.add('route-completed');
                } else {
                    routeItem.classList.remove('route-completed');
                }
                
                // Mostrar notificación de éxito
                mostrarNotificacion('Ruta actualizada correctamente', 'success');
            } else {
                // Revertir el checkbox si hubo un error
                checkbox.checked = !estaCompletada;
                mostrarNotificacion('Error al actualizar la ruta', 'error');
            }
        })
        .catch(error => {
            // Restaurar opacidad y revertir checkbox
            routeItem.style.opacity = '1';
            checkbox.checked = !estaCompletada;
            mostrarNotificacion('Error de conexión', 'error');
            console.error('Error:', error);
        });
    }
    
    // Función para mostrar notificaciones
    function mostrarNotificacion(mensaje, tipo) {
        // Crear elemento de notificación
        const notificacion = document.createElement('div');
        notificacion.className = `notificacion ${tipo}`;
        notificacion.textContent = mensaje;
        
        // Añadir al DOM
        document.body.appendChild(notificacion);
        
        // Mostrar con animación
        setTimeout(() => {
            notificacion.classList.add('visible');
        }, 10);
        
        // Ocultar después de 3 segundos
        setTimeout(() => {
            notificacion.classList.remove('visible');
            setTimeout(() => {
                document.body.removeChild(notificacion);
            }, 300);
        }, 3000);
    }
</script>

<?php include 'footer.php'; ?>