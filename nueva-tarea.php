<?php
// Iniciar la sesión si aún no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar si el usuario está autenticado
if (!isset($_SESSION['usuario_id'])) {
    $_SESSION['usuario_id'] = 1; // Para pruebas, se establece un ID de usuario predeterminado
}

// Conexión a la base de datos del sistema Ruta
$serverName = "HERCULES";
$connectionInfo = ["Database" => "Ruta", "UID" => "sa", "PWD" => "Sky2022*!"];
$conn = sqlsrv_connect($serverName, $connectionInfo);

if (!$conn) {
    die("Error al conectar a la base de datos Ruta: " . print_r(sqlsrv_errors(), true));
}

// Conexión a la base de datos de SAP Business One
$serverName = "HERCULES";
$connectionInfo = array("Database" => "RBOSKY3", "UID" => "sa", "PWD" => "Sky2022*!");
$conn_sap = sqlsrv_connect($serverName, $connectionInfo);

if (!$conn_sap) {
    die("Error al conectar a la base de datos SAP: " . print_r(sqlsrv_errors(), true));
}

// Obtener lista de clientes desde SAP
// Obtener lista de clientes desde SAP usando CardFName con respaldo
$sql_clientes = "SELECT DISTINCT 
                T0.[CardCode] as id, 
                CASE 
                    WHEN T0.[CardFName] IS NOT NULL AND T0.[CardFName] != '' THEN T0.[CardFName]
                    WHEN T0.[CardFName] IS NOT NULL AND T0.[CardFName] != '' THEN T0.[CardFName]
                    ELSE T0.[CardCode]
                END as nombre 
                FROM OCRD T0 
                INNER JOIN OSLP T1 ON T0.[SlpCode] = T1.[SlpCode] 
                WHERE T0.[validFor] = 'Y'
                ORDER BY nombre ASC";

$stmt_clientes = sqlsrv_query($conn_sap, $sql_clientes);
$clientes = [];

if ($stmt_clientes) {
    while ($row = sqlsrv_fetch_array($stmt_clientes, SQLSRV_FETCH_ASSOC)) {
        // Limpieza adicional del nombre
        $row['nombre'] = trim($row['nombre']);
        $clientes[] = $row;
    }
    
    // Depuración (puedes eliminar esto después de verificar)
    error_log("Clientes obtenidos: " . print_r($clientes, true));
} else {
    $error_clientes = "Error al obtener la lista de clientes: " . print_r(sqlsrv_errors(), true);
    error_log($error_clientes);
}

// Obtener lista de vendedores desde SAP
$sql_vendedores = "SELECT DISTINCT T1.[SlpCode] as id, T1.[SlpName] as nombre 
                  FROM OSLP T1 
                  WHERE T1.[Active] = 'Y'
                  ORDER BY T1.[SlpName] ASC";
                  
$stmt_vendedores = sqlsrv_query($conn_sap, $sql_vendedores);
$vendedores = [];

if ($stmt_vendedores) {
    while ($row = sqlsrv_fetch_array($stmt_vendedores, SQLSRV_FETCH_ASSOC)) {
        $vendedores[] = $row;
    }
} else {
    $error_vendedores = "Error al obtener la lista de vendedores: " . print_r(sqlsrv_errors(), true);
}

// Procesar el formulario si se envió
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar datos del formulario
    $titulo = isset($_POST['titulo']) ? trim($_POST['titulo']) : '';
    $descripcion = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : '';
    $cliente_id = isset($_POST['cliente_id']) ? $_POST['cliente_id'] : null; // Ahora es CardCode, puede contener letras
    $vendedor_id = isset($_POST['vendedor_id']) ? (int)$_POST['vendedor_id'] : null;
    $fecha_vencimiento = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento'] : null;
    $prioridad = isset($_POST['prioridad']) ? $_POST['prioridad'] : 'media';
    $estado = "pendiente"; // Estado inicial predeterminado
    $fecha_creacion = date('Y-m-d H:i:s');
    $fecha_actualizacion = date('Y-m-d H:i:s');

    if (empty($titulo)) {
        $error = 'El título de la tarea es obligatorio';
    } elseif (empty($cliente_id)) {
        $error = 'Debe seleccionar un cliente para la tarea';
    } elseif (empty($vendedor_id)) {
        $error = 'Debe seleccionar un vendedor para asignar la tarea';
    } else {
        try {
            // Obtener el NIT del vendedor seleccionado desde SAP
            $sql_nit_vendedor = "SELECT SlpCode FROM OSLP WHERE SlpCode = ?";
            $params_nit = array($vendedor_id);
            $stmt_nit = sqlsrv_query($conn_sap, $sql_nit_vendedor, $params_nit);
            
            if ($stmt_nit && sqlsrv_has_rows($stmt_nit)) {
                $row_nit = sqlsrv_fetch_array($stmt_nit, SQLSRV_FETCH_ASSOC);
                $nit_vendedor = $row_nit['SlpCode'];
                
                // Preparar la consulta SQL para insertar la tarea con el NIT del vendedor
                $sql = "INSERT INTO tareas (titulo, descripcion, cliente_id, vendedor_id, fecha_vencimiento, prioridad, estado, usuario_id, fecha_creacion, fecha_actualizacion)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                // Convertir fecha_vencimiento a formato SQL Server si no es null
                if ($fecha_vencimiento !== null) {
                    $fecha_vencimiento = date('Y-m-d H:i:s', strtotime($fecha_vencimiento));
                }
                
                $params = [
                    $titulo,
                    $descripcion,
                    $cliente_id,
                    $nit_vendedor, // Usamos el NIT del vendedor en lugar del usuario de sesión
                    $fecha_vencimiento,
                    $prioridad,
                    $estado,
                    $_SESSION['usuario_id'],
                    $fecha_creacion,
                    $fecha_actualizacion
                ];

                $stmt = sqlsrv_query($conn, $sql, $params);

                if ($stmt) {
                    // Redirigir a la página de tareas con un mensaje de éxito
                    header("Location: tareas.php?creada=1");
                    exit();
                } else {
                    $error = 'Error al crear la tarea: ' . print_r(sqlsrv_errors(), true);
                }
            } else {
                $error = 'No se pudo obtener el NIT del vendedor seleccionado';
            }
        } catch (Exception $e) {
            $error = 'Error en el sistema: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="estilos-adicionales.css">
        
        <link rel="stylesheet" href="estilos-adicionales.css">
        <link rel="stylesheet" href="estilos-globales.css">
        
    <link rel="stylesheet" href="estilostabla.css">
    <title>Nueva Tarea</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
           [data-theme="oscuro"] {
            --primary-color: #3b82f6;
            --primary-hover: #60a5fa;
            --secondary-color: #1e293b;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --text-color: #e2e8f0;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --bg-color: #0f172a;
            --card-bg: #1e293b;
        }
        
        /* Tema sistema (detecta automáticamente) */
        @media (prefers-color-scheme: dark) {
            [data-theme="sistema"] {
                --primary-color: #3b82f6;
                --primary-hover: #60a5fa;
                --secondary-color: #1e293b;
                --success-color: #10b981;
                --danger-color: #ef4444;
                --text-color: #e2e8f0;
                --text-muted: #94a3b8;
                --border-color: #334155;
                --bg-color: #0f172a;
                --card-bg: #1e293b;
            }
        }
        /* Estilo general */
        :root {
            --color-primary: #4169e1;
            --color-primary-dark: #3051b5;
            --color-secondary: #6c757d;
            --color-success: #28a745;
            --color-danger: #dc3545;
            --color-warning: #ffc107;
            --color-info: #17a2b8;
            --color-light: #f8f9fa;
            --color-dark: #212529;
            --color-white: #ffffff;
            --color-gray: #e9ecef;
            --border-radius: 6px;
            --box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            margin: 0;
            padding: 0;
            color: var(--color-dark);
            line-height: 1.6;
        }

        /* Formulario */
        .form-container {
            max-width: 900px;
            margin: 0 auto 30px;
        }


        .form-header {
            margin-bottom: 25px;
            border-bottom: 1px solid var(--color-gray);
            padding-bottom: 15px;
        }

        .form-header h2 {
            color: var(--color-primary);
            margin: 0 0 10px;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-header p {
            color: var(--color-secondary);
            margin: 0;
            font-size: 14px;
        }


 

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--color-primary);
            outline: none;
            box-shadow: 0 0 0 2px rgba(65, 105, 225, 0.2);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .form-actions {
            grid-column: span 2;
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .btn-primary,
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 25px;
            border-radius: var(--border-radius);
            font-weight: 500;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--color-primary);
            color: var(--color-white);
        }

        .btn-secondary {
            background-color: var(--color-light);
            color: var(--color-dark);
            border: 1px solid #d1d5db;
        }

        .btn-primary:hover {
            background-color: var(--color-primary-dark);
        }

        .btn-secondary:hover {
            background-color: #e2e6ea;
        }

        /* Estilos para el selector de prioridad */
        .priority-options {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .priority-option {
            position: relative;
        }

        .priority-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .priority-option label {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 0.2s;
        }

        .priority-option-alta label {
            border-color: rgba(220, 53, 69, 0.3);
        }

        .priority-option-media label {
            border-color: rgba(255, 193, 7, 0.3);
        }

        .priority-option-baja label {
            border-color: rgba(23, 162, 184, 0.3);
        }

        .priority-option input[type="radio"]:checked + label {
            border-width: 2px;
        }

        .priority-option-alta input[type="radio"]:checked + label {
            border-color: var(--color-danger);
            background-color: rgba(220, 53, 69, 0.05);
        }

        .priority-option-media input[type="radio"]:checked + label {
            border-color: var(--color-warning);
            background-color: rgba(255, 193, 7, 0.05);
        }

        .priority-option-baja input[type="radio"]:checked + label {
            border-color: var(--color-info);
            background-color: rgba(23, 162, 184, 0.05);
        }

        .priority-icon {
            font-size: 22px;
            margin-bottom: 8px;
        }

        .priority-option-alta .priority-icon {
            color: var(--color-danger);
        }

        .priority-option-media .priority-icon {
            color: var(--color-warning);
        }

        .priority-option-baja .priority-icon {
            color: var(--color-info);
        }

        /* Alertas */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
        }

        .alert i {
            margin-right: 10px;
            font-size: 18px;
        }

        .alert-danger {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        /* Cabecera de página */
        .page-header {
            background-color: var(--color-white);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-radius: var(--border-radius);
        }
        select {
    width: 100%;
    padding: 10px 15px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background-color: white;
    color: #333;
    font-size: 15px;
    transition: border-color 0.2s;
}

select:focus {
    border-color: #4169e1;
    outline: none;
    box-shadow: 0 0 0 2px rgba(65, 105, 225, 0.2);
}
        .page-title {
            color: var(--color-primary);
            font-size: 24px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-date {
            color: var(--color-secondary);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Footer */
        .footer {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
            color: var(--color-secondary);
            font-size: 14px;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .task-form {
                grid-template-columns: 1fr;
            }

            .form-group:nth-child(3) {
                grid-column: 1;
            }

            .form-actions {
                grid-column: 1;
                flex-direction: column;
            }

            .priority-options {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-date {
                margin-top: 10px;
            }
        }
        .form-card{

            margin-left: 20px
        }
        [data-theme="oscuro"] {
            --primary-color: #3b82f6;
            --primary-hover: #60a5fa;
            --secondary-color: #1e293b;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --text-color: #e2e8f0;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --bg-color: #0f172a;
            --card-bg: #1e293b;
        }
        
        /* Tema sistema (detecta automáticamente) */
        @media (prefers-color-scheme: dark) {
            [data-theme="sistema"] {
                --primary-color: #3b82f6;
                --primary-hover: #60a5fa;
                --secondary-color: #1e293b;
                --success-color: #10b981;
                --danger-color: #ef4444;
                --text-color: #e2e8f0;
                --text-muted: #94a3b8;
                --border-color: #334155;
                --bg-color: #0f172a;
                --card-bg: #1e293b;
            }
        }
            /* Añade estos estilos para mejorar la visualización del select */
            .custom-select {
    width: 100%;
    padding: 12px 15px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background-color: white;
    color: #333;
    font-size: 15px;
    transition: all 0.3s ease;
    appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 15px center;
    background-size: 15px;
}

.custom-select:focus {
    border-color: #4169e1;
    outline: none;
    box-shadow: 0 0 0 3px rgba(65, 105, 225, 0.2);
}

.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #2d3748;
}

.error-message {
    color: #e53e3e;
    font-size: 14px;
    margin-top: 5px;
}

.required {
    color: #e53e3e;
    font-weight: bold;
}
    </style>
</head>
<body>
    <!-- Header principal -->
    <?php include 'header.php'; ?>

    <!-- Sidebar -->


    <!-- Contenido principal -->
   
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-tasks"></i> Gestión de Tareas</h1>
            <div class="page-date">
                <i class="far fa-calendar-alt"></i> <?php echo date('l d \d\e F \d\e Y | H:i'); ?>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($mensaje): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

    
            <div class="form-card">
                <div class="form-header">
                    <h2><i class="fas fa-plus-circle"></i> Nueva Tarea</h2>
                    <p>Completa el formulario para asignar una nueva tarea a un cliente</p>
                </div>
                
                <form method="POST" action="" class="task-form">
                    <div class="form-group">
                        <label for="titulo">Título de la Tarea <span class="required">*</span></label>
                        <input type="text" id="titulo" name="titulo" value="<?php echo isset($_POST['titulo']) ? htmlspecialchars($_POST['titulo']) : ''; ?>" required>
                    </div>
                    
   <!-- Modifica la sección del select de clientes así: -->
    <div class="form-group">
    <label for="cliente_id">Cliente <span class="required">*</span></label>
    <select id="cliente_id" name="cliente_id" required class="custom-select">
        <option value="">Seleccionar cliente...</option>
        <?php if (!empty($clientes)): ?>
            <?php foreach ($clientes as $cliente): ?>
                <?php if (!empty($cliente['nombre'])): ?>
                    <option value="<?php echo htmlspecialchars($cliente['id']); ?>" 
                        <?php echo (isset($_POST['cliente_id']) && $_POST['cliente_id'] == $cliente['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cliente['nombre']); ?> (<?php echo htmlspecialchars($cliente['id']); ?>)
                    </option>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <option value="">No se encontraron clientes</option>
        <?php endif; ?>
    </select>
    <?php if (isset($error_clientes)): ?>
        <div class="error-message"><?php echo $error_clientes; ?></div>
    <?php endif; ?>
</div>
                    
                    <div class="form-group">
                        <label for="descripcion">Descripción</label>
                        <textarea id="descripcion" name="descripcion" rows="4"><?php echo isset($_POST['descripcion']) ? htmlspecialchars($_POST['descripcion']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="vendedor_id">Asignar a Vendedor <span class="required">*</span></label>
                        <select id="vendedor_id" name="vendedor_id" required>
                            <option value="">Seleccionar vendedor...</option>
                            <?php if (!empty($vendedores)): ?>
                                <?php foreach ($vendedores as $vendedor): ?>
                                    <option value="<?php echo $vendedor['id']; ?>" <?php echo (isset($_POST['vendedor_id']) && $_POST['vendedor_id'] == $vendedor['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($vendedor['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="fecha_vencimiento">Fecha de Vencimiento <span class="required">*</span></label>
                        <input type="datetime-local" id="fecha_vencimiento" name="fecha_vencimiento" value="<?php echo isset($_POST['fecha_vencimiento']) ? htmlspecialchars($_POST['fecha_vencimiento']) : ''; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Prioridad <span class="required">*</span></label>
                        <div class="priority-options">
                            <div class="priority-option priority-option-alta">
                                <input type="radio" id="prioridad_alta" name="prioridad" value="alta" <?php echo (isset($_POST['prioridad']) && $_POST['prioridad'] === 'alta') ? 'checked' : ''; ?>>
                                <label for="prioridad_alta">
                                    <i class="fas fa-exclamation-circle priority-icon"></i>
                                    Alta
                                </label>
                            </div>
                            <div class="priority-option priority-option-media">
                                <input type="radio" id="prioridad_media" name="prioridad" value="media" <?php echo (!isset($_POST['prioridad']) || $_POST['prioridad'] === 'media') ? 'checked' : ''; ?>>
                                <label for="prioridad_media">
                                    <i class="fas fa-dot-circle priority-icon"></i>
                                    Media
                                </label>
                            </div>
                            <div class="priority-option priority-option-baja">
                                <input type="radio" id="prioridad_baja" name="prioridad" value="baja" <?php echo (isset($_POST['prioridad']) && $_POST['prioridad'] === 'baja') ? 'checked' : ''; ?>>
                                <label for="prioridad_baja">
                                    <i class="fas fa-arrow-down priority-icon"></i>
                                    Baja
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i> Guardar Tarea
                        </button>
                        <a href="tareas.php" class="btn-secondary">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
      

        <footer class="footer">
            <p>&copy; 2025 Sistema de Gestores de Ruta - Versión 1.0</p>
        </footer>


    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle del menú lateral (si existe)
            const menuToggle = document.getElementById('menu-toggle');
            const sidebar = document.getElementById('sidebar');
            
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                });
            }
            
            // Establecer fecha mínima para el campo de fecha de vencimiento
            const fechaVencimientoInput = document.getElementById('fecha_vencimiento');
            if (fechaVencimientoInput) {
                // Formato YYYY-MM-DDThh:mm
                const ahora = new Date();
                const year = ahora.getFullYear();
                const month = String(ahora.getMonth() + 1).padStart(2, '0');
                const day = String(ahora.getDate()).padStart(2, '0');
                const hours = String(ahora.getHours()).padStart(2, '0');
                const minutes = String(ahora.getMinutes()).padStart(2, '0');
                
                const fechaMinima = `${year}-${month}-${day}T${hours}:${minutes}`;
                fechaVencimientoInput.min = fechaMinima;
                
                // Si no hay una fecha establecida, establecer un valor predeterminado (3 días después)
                if (!fechaVencimientoInput.value) {
                    const fechaPredeterminada = new Date(ahora);
                    fechaPredeterminada.setDate(fechaPredeterminada.getDate() + 3);
                    
                    const yearPred = fechaPredeterminada.getFullYear();
                    const monthPred = String(fechaPredeterminada.getMonth() + 1).padStart(2, '0');
                    const dayPred = String(fechaPredeterminada.getDate()).padStart(2, '0');
                    const hoursPred = String(fechaPredeterminada.getHours()).padStart(2, '0');
                    const minutesPred = String(fechaPredeterminada.getMinutes()).padStart(2, '0');
                    
                    fechaVencimientoInput.value = `${yearPred}-${monthPred}-${dayPred}T${hoursPred}:${minutesPred}`;
                }
            }

            // Validación del formulario
            const taskForm = document.querySelector('.task-form');
            if (taskForm) {
                taskForm.addEventListener('submit', function(event) {
                    const tituloInput = document.getElementById('titulo');
                    const clienteSelect = document.getElementById('cliente_id');
                    const vendedorSelect = document.getElementById('vendedor_id');
                    const fechaVencimientoInput = document.getElementById('fecha_vencimiento');
                    
                    if (!tituloInput.value.trim()) {
                        event.preventDefault();
                        mostrarError('El título de la tarea es obligatorio');
                        tituloInput.focus();
                        return;
                    }
                    
                    if (!clienteSelect.value) {
                        event.preventDefault();
                        mostrarError('Debe seleccionar un cliente para la tarea');
                        clienteSelect.focus();
                        return;
                    }
                    
                    if (!vendedorSelect.value) {
                        event.preventDefault();
                        mostrarError('Debe seleccionar un vendedor para asignar la tarea');
                        vendedorSelect.focus();
                        return;
                    }
                    
                    if (!fechaVencimientoInput.value) {
                        event.preventDefault();
                        mostrarError('La fecha de vencimiento es obligatoria');
                        fechaVencimientoInput.focus();
                        return;
                    }
                });
            }

            // Función para mostrar errores
            function mostrarError(mensaje) {
                // Verificar si ya existe una alerta
                let alertaExistente = document.querySelector('.alert-danger');
                
                if (alertaExistente) {
                    // Actualizar el mensaje de la alerta existente
                    const iconoAlerta = alertaExistente.querySelector('i');
                    alertaExistente.innerHTML = '';
                    alertaExistente.appendChild(iconoAlerta);
                    alertaExistente.appendChild(document.createTextNode(' ' + mensaje));
                } else {
                    // Crear una nueva alerta
                    const alertaDiv = document.createElement('div');
                    alertaDiv.className = 'alert alert-danger';
                    
                    const iconoAlerta = document.createElement('i');
                    iconoAlerta.className = 'fas fa-exclamation-circle';
                    
                    alertaDiv.appendChild(iconoAlerta);
                    alertaDiv.appendChild(document.createTextNode(' ' + mensaje));
                    
                    // Insertar la alerta antes del formulario
                    const formContainer = document.querySelector('.form-container');
                    formContainer.parentNode.insertBefore(alertaDiv, formContainer);
                }
            }

            // Mostrar mensaje de éxito si se ha creado una tarea correctamente
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('creada') === '1') {
                // Crear un mensaje de éxito
                const mensajeDiv = document.createElement('div');
                mensajeDiv.className = 'alert alert-success';
                
                const iconoMensaje = document.createElement('i');
                iconoMensaje.className = 'fas fa-check-circle';
                
                mensajeDiv.appendChild(iconoMensaje);
                mensajeDiv.appendChild(document.createTextNode(' La tarea ha sido creada exitosamente.'));
                
                // Insertar el mensaje al inicio de la página
                const pageHeader = document.querySelector('.page-header');
                pageHeader.parentNode.insertBefore(mensajeDiv, pageHeader.nextSibling);
                
                // Eliminar el mensaje después de 5 segundos
                setTimeout(function() {
                    mensajeDiv.remove();
                }, 5000);
            }
        });
    </script>
</body>
</html>