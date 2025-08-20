<?php
// Archivo: editar-ruta.php
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

// Conexión a SAPin
function conexionSAP() {
    $serverName = "HERCULES"; // Cambia si tu SQL Server SAP está en otro host
    $connectionInfo = array("Database" => "RBOSKY3", "UID" => "sa", "PWD" => "Sky2022*!");
    $connSAP = sqlsrv_connect($serverName, $connectionInfo);
    if ($connSAP === false) {
        echo "<div class='alert alert-danger'><b>Error de conexión a SAP:</b><br>";
        print_r(sqlsrv_errors());
        echo "</div>";
        return false;
    }
    return $connSAP;
}

// Traer clientes de SAP cuyo SlpCode coincide con el NIT (SlpCode del vendedor) del usuario
// Si es admin, trae todos los clientes
function obtenerClientesPorNit($nit, $es_admin = false) {
    $connSAP = conexionSAP();
    $clientes = [];
    if ($connSAP === false) {
        return $clientes;
    }
    
    if ($es_admin) {
        // Si es admin, obtener todos los clientes
        $sql = "SELECT distinct T0.[CardCode], T0.[CardFName],T0.[CardName], T1.[SlpCode], T1.[SlpName]
                FROM OCRD T0
                INNER JOIN OSLP T1 ON T0.[SlpCode] = T1.[SlpCode]
                WHERE T0.[validFor] = 'Y'";
        $stmt = sqlsrv_query($connSAP, $sql);
    } else {
        // Si es usuario normal, filtrar por NIT
        $sql = "SELECT distinct T0.[CardCode], T0.[CardFName],T0.[CardName], T1.[SlpCode], T1.[SlpName]
                FROM OCRD T0
                INNER JOIN OSLP T1 ON T0.[SlpCode] = T1.[SlpCode]
                WHERE T0.[validFor] = 'Y' AND T1.[SlpCode] = ?";
        $params = array($nit);
        $stmt = sqlsrv_query($connSAP, $sql, $params);
    }
    
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

// Función para obtener una ruta específica por ID
function obtenerRutaPorId($ruta_id) {
    $conn = conectarBaseDatos();
    
    if ($conn === false) {
        return null;
    }
    
    $sql = "SELECT 
                id,
                nombre,
                estado,
                ciudad,
                CONVERT(VARCHAR(5), hora_visita, 108) AS hora_visita,
                CONVERT(VARCHAR(10), fecha_programada, 120) AS fecha_programada,
                cliente_id,
                vendedor_id,
                notas,
                direccion
            FROM Ruta.dbo.rutas
            WHERE id = ?";
    
    $params = array($ruta_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        error_log("Error al ejecutar consulta: " . print_r(sqlsrv_errors(), true));
        return null;
    }

    $ruta = null;
    if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $ruta = $row;
    }

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);

    return $ruta;
}

// Función para actualizar una ruta existente
function actualizarRuta($ruta_id, $datos) {
    $conn = conectarBaseDatos();
    
    if ($conn === false) {
        return false;
    }
    
    $sql = "UPDATE Ruta.dbo.rutas SET 
                nombre = ?, 
                estado = ?, 
                ciudad = ?, 
                hora_visita = ?, 
                fecha_programada = ?, 
                cliente_id = ?, 
                vendedor_id = ?, 
                notas = ?, 
                direccion = ?
            WHERE id = ?";
    
    $params = array(
        $datos['nombre'],
        $datos['estado'],
        $datos['ciudad'],
        $datos['hora_visita'],
        $datos['fecha_programada'],
        $datos['cliente_id'],
        $datos['vendedor_id'],
        $datos['notas'] ?? null,
        $datos['direccion'] ?? null,
        $ruta_id
    );
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        error_log("Error al actualizar ruta: " . print_r(sqlsrv_errors(), true));
        return false;
    }

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);

    return true;
}

// Obtener el NIT del usuario para mostrar en la interfaz
$conn = conectarBaseDatos();
$nit_usuario = ($conn && $usuario_id) ? obtenerNitUsuario($usuario_id, $conn) : null;
if ($conn) sqlsrv_close($conn);

// Obtener tema actual del usuario
$tema_actual = $_SESSION['tema'] ?? 'claro';

// Verificar si se ha enviado un ID de ruta
$ruta_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$ruta = null;
$clientes = [];

if ($ruta_id > 0) {
    // Obtener datos de la ruta
    $ruta = obtenerRutaPorId($ruta_id);
    
    // Obtener lista de clientes para el selector
    if ($nit_usuario) {
        $clientes = obtenerClientesPorNit($nit_usuario, $es_admin);
    }
}

// Si no se encontró la ruta, redirigir a la página principal
if (!$ruta) {
    // Guardar mensaje en sesión en lugar de usar header redirect
    $_SESSION['mensaje'] = "Ruta no encontrada.";
    $_SESSION['tipo_mensaje'] = "danger";
    echo "<script>window.location.href = 'tabla.php';</script>";
    exit;
}

// Procesar el formulario si se ha enviado
$mensaje = '';
$tipoMensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'editar_ruta') {
    // Validar datos del formulario
    $datos = array(
        'nombre' => $_POST['nombre'] ?? '',
        'estado' => $_POST['estado'] ?? 'Pendiente',
        'ciudad' => $_POST['ciudad'] ?? '',
        'hora_visita' => $_POST['hora_visita'] ?? null,
        'fecha_programada' => $_POST['fecha_programada'] ?? null,
        'cliente_id' => $_POST['cliente_id'] ?? '',
        'vendedor_id' => $_POST['vendedor_id'] ?? $nit_usuario,
        'notas' => $_POST['notas'] ?? null,
        'direccion' => $_POST['direccion'] ?? null
    );
    
    // Actualizar la ruta
    $resultado = actualizarRuta($ruta_id, $datos);
    
    if ($resultado) {
        // Usar JavaScript para redirigir en lugar de header()
        echo "<script>window.location.href = 'tabla.php?mensaje=actualizado&tipo=success';</script>";
        exit;
    } else {
        $mensaje = "Error al actualizar la ruta.";
        $tipoMensaje = "danger";
    }
}

// Ahora incluimos el header después de toda la lógica de redirección
include 'header.php';
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?php echo htmlspecialchars($tema_actual); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="estilos-adicionales.css">
    <link rel="stylesheet" href="estilostabla.css">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="dashboard.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Ruta</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Tema oscuro */
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
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            line-height: 1.5;
            margin: 0;
            padding: 0;
            transition: var(--transition);
        }

        .alert {
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
        }

        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .alert-danger {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }

        .card {
            background-color: var(--card-bg);
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
        }

        .card-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: var(--secondary-color);
            border-top-left-radius: 0.5rem;
            border-top-right-radius: 0.5rem;
        }

        .card-title {
            font-weight: 600;
            margin: 0;
            color: var(--text-color);
        }

        .card-body {
            padding: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            background-color: var(--bg-color);
            color: var(--text-color);
            font-size: 1rem;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25);
        }

        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            background-color: var(--bg-color);
            color: var(--text-color);
            font-size: 1rem;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1rem;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 500;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-secondary {
            background-color: var(--border-color);
            color: var(--text-color);
        }

        .btn-secondary:hover {
            background-color: var(--border-color);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .footer {
            text-align: center;
            padding: 1.5rem 0;
            color: var(--text-muted);
            font-size: 0.875rem;
            border-top: 1px solid var(--border-color);
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipoMensaje; ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
        <?php endif; ?>
        
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-edit"></i> Editar Ruta
            </h2>
        </div>
        
        <div class="card">
            <div class="card-header">
                <div class="card-title">Información de la Ruta</div>
            </div>
            <div class="card-body">
                <form id="formEditarRuta" method="post">
                    <input type="hidden" name="accion" value="editar_ruta">
                    <input type="hidden" name="ruta_id" value="<?php echo $ruta['id']; ?>">
                    <input type="hidden" name="vendedor_id" value="<?php echo $ruta['vendedor_id']; ?>">
                    
                    <div class="form-group">
                        <label for="nombre" class="form-label">Nombre de la Ruta</label>
                        <input type="text" id="nombre" name="nombre" class="form-control" value="<?php echo htmlspecialchars($ruta['nombre']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="estado" class="form-label">Estado</label>
                        <select id="estado" name="estado" class="form-select">
                            <option value="Pendiente" <?php echo $ruta['estado'] === 'Pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                            <option value="Completado" <?php echo $ruta['estado'] === 'Completado' ? 'selected' : ''; ?>>Completado</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="ciudad" class="form-label">Ciudad</label>
                        <input type="text" id="ciudad" name="ciudad" class="form-control" value="<?php echo htmlspecialchars($ruta['ciudad'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="hora_visita" class="form-label">Hora de Visita</label>
                        <input type="time" id="hora_visita" name="hora_visita" class="form-control" value="<?php echo htmlspecialchars($ruta['hora_visita'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="fecha_programada" class="form-label">Fecha Programada</label>
                        <input type="date" id="fecha_programada" name="fecha_programada" class="form-control" value="<?php echo htmlspecialchars($ruta['fecha_programada'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="cliente_id" class="form-label">Cliente</label>
                        <select id="cliente_id" name="cliente_id" class="form-select" required>
                            <option value="">Seleccione un cliente</option>
                            <?php foreach ($clientes as $cliente): ?>
                            <option value="<?php echo htmlspecialchars($cliente['id']); ?>" <?php echo $ruta['cliente_id'] === $cliente['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cliente['nombre']); ?> (<?php echo htmlspecialchars($cliente['id']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="direccion" class="form-label">Dirección</label>
                        <input type="text" id="direccion" name="direccion" class="form-control" value="<?php echo htmlspecialchars($ruta['direccion'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="notas" class="form-label">Notas</label>
                        <textarea id="notas" name="notas" class="form-control" rows="3"><?php echo htmlspecialchars($ruta['notas'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <a href="tabla.php" class="btn btn-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="footer">
            <p>Sistema de Gestión de Rutas © <?php echo date('Y'); ?> SKY S.A.S</p>
        </div>
    </div>
</body>
</html>