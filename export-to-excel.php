<?php
// Archivo: export-to-excel.php
session_start();

// Verificar si el usuario está autenticado
if (!isset($_SESSION['usuario_id'])) {
    // Redirigir al login si no hay sesión
    header("Location: login.php");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Usuario';
$es_admin = ($usuario_id == 1); // Verificar si es administrador

// ==================== FUNCIONES DE CONEXIÓN Y UTILIDADES ====================

// Establecer conexión a la base de datos principal
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

// Establecer conexión a la base de datos SAP
function conectarBaseDatosSAP() {
    $serverName = "HERCULES";
    $connectionInfo = array("Database" => "RBOSKY3", "UID" => "sa", "PWD" => "Sky2022*!");
    $conn = sqlsrv_connect($serverName, $connectionInfo);
    
    if ($conn === false) {
        error_log("Error al conectar a SQL Server SAP: " . print_r(sqlsrv_errors(), true));
        return false;
    }
    
    return $conn;
}

// Función para obtener el NIT del usuario
function obtenerNitUsuario($usuario_id, $conn) {
    $sql = "SELECT nit FROM usuarios_ruta WHERE id = ?";
    $params = array($usuario_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        return $row['nit'];
    }
    return null;
}

// Función para obtener vendedores y coaches
function obtenerVendedores() {
    $conn = conectarBaseDatosSAP();
    
    if ($conn === false) {
        return [];
    }
    
    $sql = "SELECT T1.SlpCode, T1.SlpName, T2.[Code] as CoachCode, T2.[Name] as CoachName
            FROM OSLP T1 
            LEFT JOIN [dbo].[@COACH] T2 ON T2.[Code] IN (
                SELECT DISTINCT [U_COACH] 
                FROM OCRD 
                WHERE SlpCode = T1.SlpCode AND validFor = 'Y'
            )
            WHERE T1.Active = 'Y'
            ORDER BY T1.SlpName";
    
    $stmt = sqlsrv_query($conn, $sql);
    
    if ($stmt === false) {
        error_log("Error al ejecutar consulta de vendedores: " . print_r(sqlsrv_errors(), true));
        return [];
    }

    $vendedores = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Agregar solo si no existe ya en el array
        $key = $row['SlpCode'] . '-' . ($row['CoachCode'] ?? '');
        if (!isset($vendedores[$key])) {
            $vendedores[$key] = [
                'SlpCode' => $row['SlpCode'],
                'SlpName' => $row['SlpName'],
            ];
        }
    }

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);

    return array_values($vendedores);
}

// Función para obtener rutas por rango de fechas y NIT del usuario o vendedor seleccionado
function obtenerRutasPorRangoFechasYNit($fechaInicio, $fechaFin, $nit, $es_admin = false, $vendedor_seleccionado = null) {
    $conn = conectarBaseDatos();
    
    if ($conn === false) {
        return [];
    }
    
    if ($es_admin) {
        if ($vendedor_seleccionado) {
            // Si el admin seleccionó un vendedor específico
            $sql = "SELECT 
                        r.id,
                        r.nombre,
                        r.estado,
                        r.ciudad,
                        CONVERT(VARCHAR(5), r.hora_visita, 108) AS hora_visita,
                        CONVERT(VARCHAR(10), r.fecha_programada, 120) AS fecha_programada,
                        r.cliente_id,
                        r.vendedor_id,
                        DATENAME(weekday, r.fecha_programada) AS dia_semana,
                        u.nombre as vendedor_nombre
                    FROM Ruta.dbo.rutas1 r
                    LEFT JOIN usuarios_ruta u ON r.vendedor_id = u.nit
                    WHERE r.fecha_programada BETWEEN ? AND ?
                    AND r.vendedor_id = ?
                    ORDER BY r.fecha_programada, r.hora_visita ASC";
            
            $params = array($fechaInicio, $fechaFin, $vendedor_seleccionado);
        } else {
            // Si es admin y no seleccionó un vendedor, obtener todas las rutas
            $sql = "SELECT 
                        r.id,
                        r.nombre,
                        r.estado,
                        r.ciudad,
                        CONVERT(VARCHAR(5), r.hora_visita, 108) AS hora_visita,
                        CONVERT(VARCHAR(10), r.fecha_programada, 120) AS fecha_programada,
                        r.cliente_id,
                        r.vendedor_id,
                        DATENAME(weekday, r.fecha_programada) AS dia_semana,
                        u.nombre as vendedor_nombre
                    FROM Ruta.dbo.rutas1 r
                    LEFT JOIN usuarios_ruta u ON r.vendedor_id = u.nit
                    WHERE r.fecha_programada BETWEEN ? AND ?
                    ORDER BY r.fecha_programada, r.hora_visita ASC";
            
            $params = array($fechaInicio, $fechaFin);
        }
        
        $stmt = sqlsrv_query($conn, $sql, $params);
    } else {
        // Si es usuario normal, filtrar por NIT
        $sql = "SELECT 
                    r.id,
                    r.nombre,
                    r.estado,
                    r.ciudad,
                    CONVERT(VARCHAR(5), r.hora_visita, 108) AS hora_visita,
                    CONVERT(VARCHAR(10), r.fecha_programada, 120) AS fecha_programada,
                    r.cliente_id,
                    r.vendedor_id,
                    DATENAME(weekday, r.fecha_programada) AS dia_semana
                FROM Ruta.dbo.rutas1 r
                WHERE r.fecha_programada BETWEEN ? AND ?
                AND r.vendedor_id = ?
                ORDER BY r.fecha_programada, r.hora_visita ASC";
        
        $params = array($fechaInicio, $fechaFin, $nit);
        $stmt = sqlsrv_query($conn, $sql, $params);
    }
    
    if ($stmt === false) {
        error_log("Error al ejecutar consulta: " . print_r(sqlsrv_errors(), true));
        return [];
    }

    $rutas = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rutas[] = $row;
    }

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);

    return $rutas;
}

// Función para convertir fecha de formato Y-m-d a d/m/Y (formato español)
function formatearFechaEspanol($fecha) {
    $fecha_obj = DateTime::createFromFormat('Y-m-d', $fecha);
    if ($fecha_obj) {
        return $fecha_obj->format('d/m/Y');
    }
    return $fecha;
}

// Función para traducir el día de la semana al español
function traducirDiaSemana($dia_ingles) {
    $dias = [
        'Monday' => 'Lunes',
        'Tuesday' => 'Martes',
        'Wednesday' => 'Miércoles',
        'Thursday' => 'Jueves',
        'Friday' => 'Viernes',
        'Saturday' => 'Sábado',
        'Sunday' => 'Domingo'
    ];
    
    return isset($dias[$dia_ingles]) ? $dias[$dia_ingles] : $dia_ingles;
}

// ==================== CÓDIGO PARA EXPORTAR A CSV (Excel) ====================

// Verificar si se está solicitando la exportación a Excel
if (isset($_GET['exportar']) && $_GET['exportar'] === 'excel') {
    // Obtener fechas del formulario o usar valores predeterminados
    $fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d');
    $fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
    
    // Formatear fechas para mostrar en español
    $fechaInicioEspanol = formatearFechaEspanol($fechaInicio);
    $fechaFinEspanol = formatearFechaEspanol($fechaFin);
    
    // Obtener el NIT del usuario
    $conn = conectarBaseDatos();
    $nit_usuario = obtenerNitUsuario($usuario_id, $conn);
    if ($conn) sqlsrv_close($conn);
    
    // Obtener vendedor seleccionado (solo para admin)
    $vendedor_seleccionado = ($es_admin && isset($_GET['vendedor'])) ? $_GET['vendedor'] : null;
    
    // Obtener rutas para el rango de fechas seleccionado
    $rutas = obtenerRutasPorRangoFechasYNit($fechaInicio, $fechaFin, $nit_usuario, $es_admin, $vendedor_seleccionado);
    
    // Configurar encabezados para la descarga
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Rutas_' . $fechaInicio . '_' . $fechaFin . '.csv"');
    
    // Crear un archivo temporal
    $output = fopen('php://output', 'w');
    
    // Añadir BOM para que Excel reconozca correctamente los caracteres UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Escribir título con el rango de fechas
    fputcsv($output, ["REPORTE DE RUTAS DESDE " . $fechaInicioEspanol . " HASTA " . $fechaFinEspanol], ';');
    
    // Línea en blanco después del título
    fputcsv($output, [""], ';');
    
    // Escribir encabezados
    if ($es_admin) {
        fputcsv($output, ['CODIGO', 'PUNTO DE VENTA', 'PDV SM', 'NOMBRE PDV', 'NOMBRE EN SM', 'FECHA PROGRAMADA', 'PROGRAMADO', 'MES ASIGNADO', 'AÑO ASIGNADO', 'MERCADERISTA-HISTORICO', 'RUTA', 'OBSERVACIONES'], ';');
    } else {
        fputcsv($output, ['Nombre', 'Ciudad', 'Hora', 'Fecha', 'Día', 'Cliente ID', 'Estado'], ';');
    }
    
    // Escribir datos
    foreach ($rutas as $ruta) {
        // Convertir la fecha al formato español
        $fecha_formateada = formatearFechaEspanol($ruta['fecha_programada']);
        
        // Traducir el día de la semana
        $dia_semana_espanol = traducirDiaSemana($ruta['dia_semana']);
        
        if ($es_admin && isset($ruta['vendedor_nombre'])) {
            fputcsv($output, [
                $ruta['cliente_id'],
                $ruta['nombre'] = trim(str_ireplace('Visita a ', '', $ruta['nombre'])),
                $ruta['cliente_id'],
                $ruta['nombre'] = trim(str_ireplace('Visita a ', '', $ruta['nombre'])),
                $ruta['nombre'] = '',
                $fecha_formateada,
                $ruta['nombre'] = 'SI',
                $ruta['nombre'] = '',
                $ruta['nombre'] = date('Y'),
                $ruta['vendedor_nombre'],
                $ruta['nombre'] = '',
            ], ';');
        } else {
            fputcsv($output, [
                $ruta['nombre'],
                $ruta['ciudad'],
                $ruta['hora_visita'],
                $fecha_formateada,
                $dia_semana_espanol,
                $ruta['cliente_id'],
                $ruta['estado']
            ], ';');
        }
    }
    
    fclose($output);
    exit;
}

// ==================== FORMULARIO DE EXPORTACIÓN ====================

// Si no se está solicitando la exportación, mostrar el formulario
include 'header.php';

// Si es admin, obtener la lista de vendedores para el selector
$vendedores = [];
if ($es_admin) {
    $vendedores = obtenerVendedores();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exportar Rutas a Excel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="estilos-adicionales.css">
    <link rel="stylesheet" href="estilostabla.css">
    <style>
        :root {
            --primary-color: #4f46e5;
            --primary-light: #6366f1;
            --primary-hover: #4338ca;
            --secondary-color: #475569;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            
            --text-color: #1e293b;
            --text-light: #64748b;
            --text-muted: #94a3b8;
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            
            --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            --border-radius: 0.5rem;
            --transition: all 0.2s ease-in-out;
        }
        
        /* Tema oscuro */
        [data-theme="oscuro"] {
            --primary-color: #6366f1;
            --primary-light: #818cf8;
            --primary-hover: #4f46e5;
            --secondary-color: #1e293b;
            --success-color: #10b981;
            --danger-color: #ef4444;
            
            --text-color: #f1f5f9;
            --text-light: #94a3b8;
            --text-muted: #64748b;
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --border-color: #334155;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.2);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -1px rgba(0, 0, 0, 0.2);
        }
        
        /* Tema sistema (detecta automáticamente) */
        @media (prefers-color-scheme: dark) {
            [data-theme="sistema"] {
                --primary-color: #6366f1;
                --primary-light: #818cf8;
                --primary-hover: #4f46e5;
                --secondary-color: #1e293b;
                --success-color: #10b981;
                --danger-color: #ef4444;
                
                --text-color: #f1f5f9;
                --text-light: #94a3b8;
                --text-muted: #64748b;
                --bg-color: #0f172a;
                --card-bg: #1e293b;
                --border-color: #334155;
                --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.2);
                --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -1px rgba(0, 0, 0, 0.2);
            }
        }

        /* Estilos generales */
        body {
            font-family: var(--font-family);
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 0;
            line-height: 1.5;
            transition: var(--transition);
        }
        
        /* Contenedor principal */
        .export-container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 1.5rem;
        }
        
        /* Tarjeta */
        .card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: var(--transition);
        }
        
        .card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background-color: rgba(79, 70, 229, 0.05);
        }
        
        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Formulario */
        .export-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .form-label {
            font-weight: 500;
            color: var(--text-color);
            font-size: 0.95rem;
        }
        
        .form-control {
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            font-size: 1rem;
            background-color: var(--card-bg);
            color: var(--text-color);
            transition: var(--transition);
            width: 100%;
            box-sizing: border-box;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
        }
        
        /* Botones */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            font-weight: 500;
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            border: none;
        }
        
        .btn-export {
            grid-column: span 2;
            background-color: var(--success-color);
            color: white;
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: 0.375rem;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
        }
        
        .btn-export:hover {
            background-color: #059669;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #334155;
            transform: translateY(-1px);
        }
        
        /* Información de exportación */
        .export-info {
            background-color: rgba(79, 70, 229, 0.05);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-top: 2rem;
            border-left: 4px solid var(--primary-color);
        }
        
        .export-info h3 {
            margin-top: 0;
            color: var(--primary-color);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .export-info ul {
            margin-bottom: 0;
            padding-left: 1.5rem;
        }
        
        .export-info li {
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }
        
        .export-info li:last-child {
            margin-bottom: 0;
        }
        
        /* Selector de vendedor */
        .vendedor-selector {
            grid-column: span 2;
            padding: 1.5rem;
            background-color: rgba(59, 130, 246, 0.05);
            border-radius: var(--border-radius);
            border: 1px solid rgba(59, 130, 246, 0.2);
            margin-bottom: 1.5rem;
            transition: var(--transition);
        }
        
        .vendedor-selector:hover {
            border-color: rgba(59, 130, 246, 0.3);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .vendedor-selector h3 {
            margin-top: 0;
            color: var(--info-color);
            font-size: 1.1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .vendedor-options {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 1rem;
        }
        
        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }
        
        .form-check input[type="radio"] {
            cursor: pointer;
            width: 1.1rem;
            height: 1.1rem;
            accent-color: var(--primary-color);
        }
        
        .form-check label {
            cursor: pointer;
            font-weight: 500;
        }
        
        .select-vendedor {
            flex-grow: 1;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            font-size: 1rem;
            background-color: var(--card-bg);
            color: var(--text-color);
            transition: var(--transition);
            width: 100%;
            box-sizing: border-box;
        }
        
        .select-vendedor:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
        }
        
        /* Animaciones */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .card {
            animation: fadeIn 0.3s ease-out;
        }
        
        /* Overlay de carga */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
        }
        
        .loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .loading-spinner {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 6px solid rgba(255, 255, 255, 0.1);
            border-top-color: var(--success-color);
            animation: spin 1s linear infinite;
        }
        
        .loading-text {
            color: white;
            font-size: 1.25rem;
            font-weight: 600;
            margin-top: 1.5rem;
            text-align: center;
        }
        
        .loading-progress {
            width: 250px;
            height: 8px;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 4px;
            margin-top: 1rem;
            overflow: hidden;
        }
        
        .loading-progress-bar {
            height: 100%;
            background-color: var(--success-color);
            border-radius: 4px;
            width: 0%;
            transition: width 0.3s ease-out;
        }
        
        /* Botón para cerrar el overlay */
        .close-overlay {
            position: absolute;
            top: 20px;
            right: 20px;
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.25rem;
            transition: all 0.2s;
        }
        
        .close-overlay:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }
        
        .loading-message {
            color: white;
            margin-top: 1rem;
            font-size: 0.9rem;
            max-width: 80%;
            text-align: center;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Efecto de onda en el botón */
        .btn-export .btn-wave {
            position: absolute;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }
        
        @keyframes ripple {
            to {
                transform: scale(2.5);
                opacity: 0;
            }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .export-form {
                grid-template-columns: 1fr;
            }
            
            .btn-export, .vendedor-selector {
                grid-column: span 1;
            }
            
            .vendedor-options {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .export-container {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Overlay de carga -->
    <div class="loading-overlay" id="loadingOverlay">
        <button class="close-overlay" id="closeOverlay" title="Cerrar">
            <i class="fas fa-times"></i>
        </button>
        <div class="loading-spinner"></div>
        <div class="loading-text">Generando Excel...</div>
        <div class="loading-progress">
            <div class="loading-progress-bar" id="progressBar"></div>
        </div>
        <div class="loading-message" id="loadingMessage">
            La descarga comenzará automáticamente en unos segundos.
        </div>
    </div>

    <div class="export-container">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-file-excel"></i> Exportar Rutas a Excel
                </div>
            </div>
            <div class="card-body">
                <form action="export-to-excel.php" method="get" class="export-form" id="exportForm">
                    <input type="hidden" name="exportar" value="excel">
                    
                    <?php if ($es_admin): ?>
                    <div class="vendedor-selector">
                        <h3><i class="fas fa-user-tie"></i> Opciones de Exportación</h3>
                        <div class="vendedor-options">
                            <div class="form-check">
                                <input type="radio" id="opcion-todos" name="opcion_exportacion" value="todos" checked 
                                       onchange="document.getElementById('selector-vendedor').style.display = this.checked ? 'none' : 'block';">
                                <label for="opcion-todos">Exportar todas las rutas</label>
                            </div>
                            <div class="form-check">
                                <input type="radio" id="opcion-vendedor" name="opcion_exportacion" value="vendedor" 
                                       onchange="document.getElementById('selector-vendedor').style.display = this.checked ? 'block' : 'none';">
                                <label for="opcion-vendedor">Filtrar por vendedor</label>
                            </div>
                        </div>
                        
                        <div id="selector-vendedor" style="display: none; margin-top: 1rem;">
                            <label for="vendedor" class="form-label">Seleccionar Vendedor:</label>
                            <select id="vendedor" name="vendedor" class="select-vendedor">
                                <option value="">-- Seleccione un vendedor --</option>
                                <?php foreach ($vendedores as $vendedor): ?>
                                    <option value="<?php echo htmlspecialchars($vendedor['SlpCode']); ?>">
                                        <?php echo htmlspecialchars($vendedor['SlpName']); ?>
                                        <?php if (!empty($vendedor['CoachName'])): ?>
                                            (Coach: <?php echo htmlspecialchars($vendedor['CoachName']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="fecha_inicio" class="form-label">
                            <i class="fas fa-calendar-alt"></i> Fecha Inicio:
                        </label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio" class="form-control" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="fecha_fin" class="form-label">
                            <i class="fas fa-calendar-alt"></i> Fecha Fin:
                        </label>
                        <input type="date" id="fecha_fin" name="fecha_fin" class="form-control" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <button type="submit" class="btn-export" id="btnExport">
                        <i class="fas fa-file-excel"></i> Generar Excel
                    </button>
                </form>
                
                <div class="export-info">
                    <h3><i class="fas fa-info-circle"></i> Información sobre la exportación</h3>
                    <ul>
                        <?php if ($es_admin): ?>
                        <li>Como administrador, puede elegir exportar todas las rutas o filtrar por un vendedor específico.</li>
                        <li>Si selecciona "Exportar todas las rutas", el archivo CSV incluirá <strong>todas las rutas</strong> programadas en el rango de fechas seleccionado.</li>
                        <li>Si selecciona "Filtrar por vendedor", el archivo CSV incluirá solo las rutas del vendedor seleccionado.</li>
                        <li>Se incluirá información sobre el vendedor asignado a cada ruta.</li>
                        <?php else: ?>
                        <li>El archivo CSV incluirá todas las rutas programadas en el rango de fechas seleccionado.</li>
                        <li>Solo se exportarán las rutas asociadas a su usuario.</li>
                        <?php endif; ?>
                        <li>El archivo se descargará automáticamente al hacer clic en "Generar Excel".</li>
                        <li>Puede seleccionar cualquier rango de fechas: día, semana, mes personalizado.</li>
                        <li>El archivo CSV se puede abrir directamente con Excel.</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div style="margin-top: 1.5rem; text-align: center;">
            <a href="tabla.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver al listado de rutas
            </a>
        </div>
    </div>
    
    <script>
        // Script para manejar la validación del formulario cuando se selecciona un vendedor
        document.querySelector('form').addEventListener('submit', function(e) {
            const opcionVendedor = document.getElementById('opcion-vendedor');
            const selectVendedor = document.getElementById('vendedor');
            
            // Si se seleccionó filtrar por vendedor pero no se eligió ninguno
            if (opcionVendedor && opcionVendedor.checked && selectVendedor && selectVendedor.value === '') {
                e.preventDefault();
                alert('Por favor seleccione un vendedor para continuar.');
                return;
            }
            
            // Mostrar el overlay de carga
            showLoadingOverlay();
            
            // NO prevenir el envío del formulario para permitir la descarga normal
            // Configurar un temporizador para ocultar el overlay después de un tiempo
            setTimeout(function() {
                hideLoadingOverlay();
            }, 5000); // Ocultar después de 5 segundos
        });
        
        // Validar que la fecha fin no sea anterior a la fecha inicio
        document.getElementById('fecha_fin').addEventListener('change', function() {
            const fechaInicio = document.getElementById('fecha_inicio').value;
            const fechaFin = this.value;
            
            if (fechaFin < fechaInicio) {
                alert('La fecha fin no puede ser anterior a la fecha inicio.');
                this.value = fechaInicio;
            }
        });
        
        // Validar que la fecha inicio no sea posterior a la fecha fin
        document.getElementById('fecha_inicio').addEventListener('change', function() {
            const fechaFin = document.getElementById('fecha_fin').value;
            const fechaInicio = this.value;
            
            if (fechaInicio > fechaFin && fechaFin !== '') {
                alert('La fecha inicio no puede ser posterior a la fecha fin.');
                this.value = fechaFin;
            }
        });
        
        // Función para mostrar el overlay de carga con animación de progreso
        function showLoadingOverlay() {
            const overlay = document.getElementById('loadingOverlay');
            const progressBar = document.getElementById('progressBar');
            
            // Mostrar el overlay
            overlay.classList.add('active');
            
            // Simular progreso
            let progress = 0;
            window.progressInterval = setInterval(() => {
                // Incrementar progreso
                progress += Math.random() * 15;
                
                // Si estamos cerca del final, ralentizar
                if (progress > 70) {
                    progress += Math.random() * 2;
                }
                
                // Limitar al 95% para dar sensación de que está procesando
                if (progress > 95) {
                    progress = 95;
                    clearInterval(window.progressInterval);
                }
                
                // Actualizar barra de progreso
                progressBar.style.width = progress + '%';
            }, 300);
            
            // Limpiar intervalo después de un tiempo máximo (por si acaso)
            setTimeout(() => {
                if (window.progressInterval) {
                    clearInterval(window.progressInterval);
                    // Completar la barra de progreso
                    progressBar.style.width = '100%';
                }
            }, 5000);
        }
        
        // Función para ocultar el overlay de carga
        function hideLoadingOverlay() {
            const overlay = document.getElementById('loadingOverlay');
            const progressBar = document.getElementById('progressBar');
            
            // Completar la barra de progreso antes de ocultar
            progressBar.style.width = '100%';
            
            // Limpiar el intervalo si existe
            if (window.progressInterval) {
                clearInterval(window.progressInterval);
            }
            
            // Ocultar el overlay después de una pequeña pausa
            setTimeout(() => {
                overlay.classList.remove('active');
                
                // Reiniciar la barra de progreso después de que se oculte
                setTimeout(() => {
                    progressBar.style.width = '0%';
                }, 300);
            }, 500);
        }
        
        // Agregar evento para cerrar el overlay manualmente
        document.getElementById('closeOverlay').addEventListener('click', function() {
            hideLoadingOverlay();
        });
        
        // Efecto de onda al hacer clic en el botón
        document.getElementById('btnExport').addEventListener('click', function(e) {
            // Crear elemento de onda
            const wave = document.createElement('span');
            wave.classList.add('btn-wave');
            
            // Posicionar la onda donde se hizo clic
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            wave.style.left = x + 'px';
            wave.style.top = y + 'px';
            
            // Agregar al botón
            this.appendChild(wave);
            
            // Eliminar después de la animación
            setTimeout(() => {
                wave.remove();
            }, 600);
        });
    </script>
</body>
</html>