<?php
include 'header.php';
include 'funciones/tareas.php';
include 'funciones/estadisticas.php';

$usuario_id = $_SESSION['usuario_id'];
$error = '';
$mensaje = '';
$modo = 'ver'; // ver, editar, crear, detalle
$titulo = '';
$descripcion = '';
$fecha_vencimiento = '';
$prioridad = 'media';
$tarea_id = null;
$es_admin = ($usuario_id == 1); // Verificar si es administrador

// Obtener el NIT del usuario actual
$nit_usuario = obtenerNitUsuario($usuario_id, $conn);

// --------- ELIMINAR ---------
$eliminacion_exitosa = false;
if (isset($_GET['eliminar']) && is_numeric($_GET['eliminar'])) {
    $tarea_id = intval($_GET['eliminar']);
    // Si es admin, puede eliminar cualquier tarea
    if ($es_admin) {
        if (eliminarTarea($tarea_id)) {
            $eliminacion_exitosa = true;
        } else {
            $error = "No se pudo eliminar la tarea.";
        }
    } else {
        // Usuario normal solo puede eliminar sus propias tareas
        if (eliminarTareaUsuario($tarea_id, $usuario_id)) {
            $eliminacion_exitosa = true;
        } else {
            $error = "No se pudo eliminar la tarea o no tienes permisos.";
        }
    }
}

// --------- VER DETALLE ---------
if (isset($_GET['detalle']) && is_numeric($_GET['detalle'])) {
    $modo = 'detalle';
    $tarea_id = intval($_GET['detalle']);
    
    // Si es admin, puede ver cualquier tarea
    if ($es_admin) {
        $tarea = obtenerTareaPorIdAdmin($tarea_id);
    } else {
        // Usuario normal solo puede ver sus propias tareas
        $tarea = obtenerTareaPorId($tarea_id, $usuario_id);
    }
    
    if ($tarea) {
        $titulo = $tarea['titulo'];
        $descripcion = $tarea['descripcion'];
        $fecha_vencimiento = !empty($tarea['fecha_vencimiento']) ? date('Y-m-d\TH:i', strtotime($tarea['fecha_vencimiento'])) : '';
        $prioridad = $tarea['prioridad'];
    } else {
        $error = "Tarea no encontrada o sin permisos.";
        $modo = 'ver';
    }
}

// --------- EDITAR (mostrar formulario) ---------
if (isset($_GET['editar']) && is_numeric($_GET['editar'])) {
    $modo = 'editar';
    $tarea_id = intval($_GET['editar']);
    
    // Si es admin, puede editar cualquier tarea
    if ($es_admin) {
        $tarea = obtenerTareaPorIdAdmin($tarea_id);
    } else {
        // Usuario normal solo puede editar sus propias tareas
        $tarea = obtenerTareaPorId($tarea_id, $usuario_id);
    }
    
    if ($tarea) {
        $titulo = $tarea['titulo'];
        $descripcion = $tarea['descripcion'];
        $fecha_vencimiento = !empty($tarea['fecha_vencimiento']) ? date('Y-m-d\TH:i', strtotime($tarea['fecha_vencimiento'])) : '';
        $prioridad = $tarea['prioridad'];
    } else {
        $error = "Tarea no encontrada o sin permisos.";
        $modo = 'ver';
    }
}

// --------- ACTUALIZAR (POST) ---------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tarea_id'])) {
    $id_editar = intval($_POST['tarea_id']);
    $titulo = trim($_POST['titulo']);
    $descripcion = trim($_POST['descripcion']);
    $fecha_vencimiento = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento'] : null;
    $prioridad = $_POST['prioridad'];

    if (empty($titulo)) {
        $error = "El título de la tarea es obligatorio";
        $modo = 'editar';
    } else {
        $datos = [
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'fecha_vencimiento' => $fecha_vencimiento,
            'prioridad' => $prioridad
        ];
        
        // Si es admin, puede actualizar cualquier tarea
        if ($es_admin) {
            $editada = actualizarTareaAdmin($id_editar, $datos);
        } else {
            // Usuario normal solo puede actualizar sus propias tareas
            $editada = actualizarTarea($id_editar, $usuario_id, $datos);
        }

        if ($editada) {
            echo "<script>
                window.location = 'tareas.php?editada=1';
            </script>";
            exit;
        } else {
            $error = "No se pudo editar la tarea o no tienes permisos.";
            $modo = 'editar';
        }
    }
}

// --------- OBTENER TAREAS Y FILTROS ---------
// Si es admin, obtener todas las tareas
if ($es_admin) {
    $tareas = obtenerTodasLasTareas();
} else {
    // Usuario normal solo ve las tareas asociadas a su NIT
    $tareas = obtenerTareasPorNit($nit_usuario);
}

$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$filtro_prioridad = isset($_GET['prioridad']) ? $_GET['prioridad'] : '';

if (!empty($filtro_estado)) {
    $tareas = array_filter($tareas, function($tarea) use ($filtro_estado) {
        return $tarea['estado'] === $filtro_estado;
    });
}

if (!empty($filtro_prioridad)) {
    $tareas = array_filter($tareas, function($tarea) use ($filtro_prioridad) {
        return $tarea['prioridad'] === $filtro_prioridad;
    });
}

$tarea_creada = isset($_GET['creada']) && $_GET['creada'] == '1';
$tarea_editada = isset($_GET['editada']) && $_GET['editada'] == '1';

// Función para obtener tarea por ID (usuario normal)
function obtenerTareaPorId($id, $usuario_id) {
    $tareas = obtenerTareasUsuario($usuario_id);
    foreach ($tareas as $tarea) {
        if ($tarea['id'] == $id) return $tarea;
    }
    return false;
}

// Función para obtener tarea por ID (admin)
function obtenerTareaPorIdAdmin($id) {
    $tareas = obtenerTodasLasTareas();
    foreach ($tareas as $tarea) {
        if ($tarea['id'] == $id) return $tarea;
    }
    return false;
}

// Función para eliminar tarea (usuario normal)
function eliminarTareaUsuario($id, $usuario_id) {
    return eliminarTarea($id, $usuario_id);
}

// Función para editar tarea (usuario normal)
function editarTareaUsuario($id, $usuario_id, $titulo, $descripcion, $fecha_vencimiento, $prioridad) {
    return editarTarea($id, $usuario_id, $titulo, $descripcion, $fecha_vencimiento, $prioridad);
}

// Función para obtener tareas por NIT
function obtenerTareasPorNit($nit) {
    global $conn;
    $tareas = [];
    
    try {
        $sql = "SELECT 
                    t.id, 
                    t.titulo, 
                    t.descripcion, 
                    t.fecha_vencimiento, 
                    t.fecha_creacion, 
                    t.estado, 
                    t.prioridad, 
                    t.usuario_id,
                    t.vendedor_id,
                    u.nombre as creador_nombre
                FROM tareas t
                LEFT JOIN usuarios_ruta u ON t.usuario_id = u.id
                WHERE t.vendedor_id = ?
                ORDER BY 
                    CASE 
                        WHEN t.prioridad = 'alta' THEN 1
                        WHEN t.prioridad = 'media' THEN 2
                        WHEN t.prioridad = 'baja' THEN 3
                    END,
                    t.fecha_vencimiento ASC";
        
        $params = [$nit];
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Formatear fecha
                $fecha_formateada = 'Sin fecha';
                if ($row['fecha_vencimiento'] instanceof DateTime) {
                    $hoy = new DateTime();
                    $fecha_vencimiento = $row['fecha_vencimiento'];
                    
                    if ($fecha_vencimiento->format('Y-m-d') == $hoy->format('Y-m-d')) {
                        $fecha_formateada = 'Hoy, ' . $fecha_vencimiento->format('h:i A');
                    } else if ($fecha_vencimiento->format('Y-m-d') == $hoy->modify('+1 day')->format('Y-m-d')) {
                        $fecha_formateada = 'Mañana, ' . $fecha_vencimiento->format('h:i A');
                    } else {
                        $fecha_formateada = $fecha_vencimiento->format('d/m/Y, h:i A');
                    }
                }
                
                $tareas[] = [
                    'id' => $row['id'],
                    'titulo' => $row['titulo'],
                    'descripcion' => $row['descripcion'] ?? '',
                    'fecha_vencimiento' => $row['fecha_vencimiento'],
                    'fecha_formateada' => $fecha_formateada,
                    'fecha_creacion' => $row['fecha_creacion'],
                    'estado' => $row['estado'],
                    'prioridad' => strtolower($row['prioridad']),
                    'usuario_id' => $row['usuario_id'],
                    'vendedor_id' => $row['vendedor_id'],
                    'creador_nombre' => $row['creador_nombre'] ?? 'Desconocido'
                ];
            }
            sqlsrv_free_stmt($stmt);
        }
    } catch (Exception $e) {
        error_log("Error en obtenerTareasPorNit: " . $e->getMessage());
    }
    
    return $tareas;
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

// Función para obtener todas las tareas (admin)
function obtenerTodasLasTareas() {
    global $conn;
    $tareas = [];
    
    try {
        $sql = "SELECT 
                    t.id, 
                    t.titulo, 
                    t.descripcion, 
                    t.fecha_vencimiento, 
                    t.fecha_creacion, 
                    t.estado, 
                    t.prioridad, 
                    t.usuario_id,
                    t.vendedor_id,
                    u.nombre as creador_nombre
                FROM tareas t
                LEFT JOIN usuarios_ruta u ON t.usuario_id = u.id
                ORDER BY 
                    CASE 
                        WHEN t.prioridad = 'alta' THEN 1
                        WHEN t.prioridad = 'media' THEN 2
                        WHEN t.prioridad = 'baja' THEN 3
                    END,
                    t.fecha_vencimiento ASC";
        
        $stmt = sqlsrv_query($conn, $sql);
        
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Formatear fecha
                $fecha_formateada = 'Sin fecha';
                if ($row['fecha_vencimiento'] instanceof DateTime) {
                    $hoy = new DateTime();
                    $fecha_vencimiento = $row['fecha_vencimiento'];
                    
                    if ($fecha_vencimiento->format('Y-m-d') == $hoy->format('Y-m-d')) {
                        $fecha_formateada = 'Hoy, ' . $fecha_vencimiento->format('h:i A');
                    } else if ($fecha_vencimiento->format('Y-m-d') == $hoy->modify('+1 day')->format('Y-m-d')) {
                        $fecha_formateada = 'Mañana, ' . $fecha_vencimiento->format('h:i A');
                    } else {
                        $fecha_formateada = $fecha_vencimiento->format('d/m/Y, h:i A');
                    }
                }
                
                $tareas[] = [
                    'id' => $row['id'],
                    'titulo' => $row['titulo'],
                    'descripcion' => $row['descripcion'] ?? '',
                    'fecha_vencimiento' => $row['fecha_vencimiento'],
                    'fecha_formateada' => $fecha_formateada,
                    'fecha_creacion' => $row['fecha_creacion'],
                    'estado' => $row['estado'],
                    'prioridad' => strtolower($row['prioridad']),
                    'usuario_id' => $row['usuario_id'],
                    'vendedor_id' => $row['vendedor_id'],
                    'creador_nombre' => $row['creador_nombre'] ?? 'Desconocido'
                ];
            }
            sqlsrv_free_stmt($stmt);
        }
    } catch (Exception $e) {
        error_log("Error en obtenerTodasLasTareas: " . $e->getMessage());
    }
    
    return $tareas;
}

// Función para actualizar tarea (admin)
function actualizarTareaAdmin($id, $datos) {
    global $conn;
    
    try {
        $sql = "UPDATE tareas SET 
                    titulo = ?, 
                    descripcion = ?, 
                    fecha_vencimiento = ?, 
                    prioridad = ?
                WHERE id = ?";
        
        $params = [
            $datos['titulo'],
            $datos['descripcion'],
            $datos['fecha_vencimiento'],
            $datos['prioridad'],
            $id
        ];
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt) {
            return true;
        }
    } catch (Exception $e) {
        error_log("Error en actualizarTareaAdmin: " . $e->getMessage());
    }
    
    return false;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="estilos-adicionales.css">
    <link rel="stylesheet" href="estilos-tareas.css">
  
    <!-- SweetAlert2 para alertas bonitas -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<style> /* Tema oscuro */
        /* Dark theme (oscuro) */
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
*[style*="background-color: #fff"] {
  background-color: none !important;
}


/* System theme - adapts to user preferences */
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

/* Apply the variables to elements */


.page-header {
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
}

.task-card {
    background-color: var(--card-bg);
 
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1rem;
}

/* Button styles */

        
        </style>
<body>
    <div >
        <h1>Gestión de Tareas</h1>
        <div class="date-display">
            <i class="far fa-calendar-alt"></i> <?php echo date('l j \d\e F \d\e Y | H:i'); ?>
        </div>
    </div>
    <?php if ($tarea_creada): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> La tarea se ha creado correctamente.
    </div>
    <?php endif; ?>
    <?php if ($tarea_editada): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> La tarea se ha editado correctamente.
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
    </div>
    <?php endif; ?>

    <?php if ($modo === 'editar'): ?>
    <div  style="max-width:600px;margin:0 auto 32px;">
        <div class="form-card">
            <div class="form-header">
                <h2><i class="fas fa-edit"></i> Editar Tarea</h2>
                <p>Modifica los datos de la tarea</p>
            </div>
            <form method="POST" action="tareas.php?editar=<?php echo $tarea_id; ?>" class="task-form">
                <input type="hidden" name="tarea_id" value="<?php echo htmlspecialchars($tarea_id); ?>">
                <div class="form-group">
                    <label for="titulo">Título de la Tarea <span class="required">*</span></label>
                    <input type="text" id="titulo" name="titulo" value="<?php echo htmlspecialchars($titulo); ?>" required>
                </div>
                <div class="form-group">
                    <label for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion" rows="4"><?php echo htmlspecialchars($descripcion); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="fecha_vencimiento">Fecha de Vencimiento</label>
                    <input type="datetime-local" id="fecha_vencimiento" name="fecha_vencimiento" value="<?php echo htmlspecialchars($fecha_vencimiento); ?>">
                </div>
                <div class="form-group">
                    <label for="prioridad">Prioridad <span class="required">*</span></label>
                    <select id="prioridad" name="prioridad" required>
                        <option value="alta" <?php echo ($prioridad === 'alta' ? 'selected' : ''); ?>>Alta</option>
                        <option value="media" <?php echo ($prioridad === 'media' ? 'selected' : ''); ?>>Media</option>
                        <option value="baja" <?php echo ($prioridad === 'baja' ? 'selected' : ''); ?>>Baja</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Actualizar Tarea
                    </button>
                    <a href="tareas.php" class="btn-secondary"><i class="fas fa-times"></i> Cancelar</a>
                    <?php if ($es_admin): ?>
                    <a href="tareas.php?eliminar=<?php echo $tarea_id; ?>" class="btn-secondary" onclick="return confirmarEliminar(<?php echo $tarea_id; ?>);">
                        <i class="fas fa-trash"></i> Eliminar
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($modo === 'detalle'): ?>
    <div style="max-width:600px;margin:0 auto 32px;">
        <div class="form-card">
            <div class="form-header">
                <h2><i class="fas fa-info-circle"></i> Detalle de Tarea</h2>
                <p>Información detallada de la tarea</p>
            </div>
            <div class="task-detail">
                <div class="detail-group">
                    <label>Título:</label>
                    <p><?php echo htmlspecialchars($titulo); ?></p>
                </div>
                <div class="detail-group">
                    <label>Descripción:</label>
                    <p><?php echo nl2br(htmlspecialchars($descripcion)); ?></p>
                </div>
                <div class="detail-group">
                    <label>Fecha de Vencimiento:</label>
                    <p><?php echo !empty($fecha_vencimiento) ? date('d/m/Y H:i', strtotime($fecha_vencimiento)) : 'Sin fecha'; ?></p>
                </div>
                <div class="detail-group">
                    <label>Prioridad:</label>
                    <p><?php echo ucfirst($prioridad); ?></p>
                </div>
            </div>
            <div class="form-actions">
                <a href="tareas.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> Volver</a>
                <?php if ($es_admin): ?>
                <a href="tareas.php?editar=<?php echo $tarea_id; ?>" class="btn-primary">
                    <i class="fas fa-edit"></i> Editar
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
<br><br>
    <div class="content-container">
        <div class="actions-bar">
        <?php if ($usuario_id == 1): ?>
<a href="nueva-tarea.php?editar=nuevo" class="btn-new-task">
    <i class="fas fa-plus"></i> Nueva Tarea
</a>
<?php endif; ?>

            <div class="task-filters">
                <div class="filter-group">
                    <span class="filter-label">Estado:</span>
                    <select id="estado" class="filter-select" onchange="filtrarTareas()">
                        <option value="">Todos</option>
                        <option value="pendiente" <?php echo $filtro_estado === 'pendiente' ? 'selected' : ''; ?>>Pendientes</option>
                        <option value="completada" <?php echo $filtro_estado === 'completada' ? 'selected' : ''; ?>>Completadas</option>
                    </select>
                </div>
                <div class="filter-group">
                    <span class="filter-label">Prioridad:</span>
                    <select id="prioridad" class="filter-select" onchange="filtrarTareas()">
                        <option value="">Todas</option>
                        <option value="alta" <?php echo $filtro_prioridad === 'alta' ? 'selected' : ''; ?>>Alta</option>
                        <option value="media" <?php echo $filtro_prioridad === 'media' ? 'selected' : ''; ?>>Media</option>
                        <option value="baja" <?php echo $filtro_prioridad === 'baja' ? 'selected' : ''; ?>>Baja</option>
                    </select>
                </div>
            </div>
        </div>

        <?php if (empty($tareas)): ?>
            <div class="task-empty-state" style="
    background-color: transparent !important;
">
    <i class="fas fa-clipboard-list"></i>
    <h3>No hay tareas disponibles</h3>
    <p>No tienes tareas asignadas.</p>
</div>

        <?php else: ?>
            <div class="task-list">
    <?php if (empty($tareas)): ?>
        <div class="empty-state">
            <i class="fas fa-check-double"></i>
            
            <a href="nueva-tarea.php" class="btn-primary">Crear Nueva Tarea</a>
        </div>
    <?php else: ?>
        <?php foreach ($tareas as $tarea): ?>
        <div class="task-card <?php echo $tarea['estado']; ?>" id="taskCard<?php echo $tarea['id']; ?>">
            <div class="task-header">
                <div class="task-checkbox">
                    <input type="checkbox" id="task<?php echo $tarea['id']; ?>"
                        <?php echo $tarea['estado'] === 'completada' ? 'checked' : ''; ?>
                        onchange="handleTaskStatusChange(<?php echo $tarea['id']; ?>, this)">
                    <label for="task<?php echo $tarea['id']; ?>"></label>
                </div>
                <h3 class="task-title <?php echo $tarea['estado'] === 'completada' ? 'completed-task' : ''; ?>">
                    <a href="tareas.php?detalle=<?php echo $tarea['id']; ?>" class="task-title-link">
                        <?php echo htmlspecialchars($tarea['titulo']); ?>
                    </a>
                </h3>
                <div class="priority-badge priority-<?php echo $tarea['prioridad']; ?>">
                    <?php echo ucfirst($tarea['prioridad']); ?>
                </div>
            </div>
            
            <?php if (!empty($tarea['descripcion'])): ?>
            <div class="task-description">
                <?php echo nl2br(htmlspecialchars(substr($tarea['descripcion'], 0, 100) . (strlen($tarea['descripcion']) > 100 ? '...' : ''))); ?>
            </div>
            <?php endif; ?>
            
            <div class="task-meta">
                <?php if (!empty($tarea['fecha_vencimiento'])): ?>
                <div class="meta-item">
                    <i class="far fa-calendar-alt"></i>
                    <span>Vence: <?php echo $tarea['fecha_formateada']; ?></span>
                </div>
                <?php endif; ?>
                <div class="meta-item">
                    <i class="far fa-clock"></i>
                    <span>Creada: <?php echo $tarea['fecha_creacion']->format('d/m/Y H:i'); ?></span>
                </div>
                <?php if ($es_admin && isset($tarea['creador_nombre'])): ?>
                <div class="meta-item">
                    <i class="far fa-user"></i>
                    <span>Creada por: <?php echo htmlspecialchars($tarea['creador_nombre']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($es_admin): ?>
<div class="task-actions">
    <a href="tareas.php?editar=<?php echo $tarea['id']; ?>" class="task-action-btn">
        <i class="fas fa-edit"></i>
    </a>
    <a href="tareas.php?eliminar=<?php echo $tarea['id']; ?>" class="task-action-btn delete-btn" onclick="return confirmarEliminar(<?php echo $tarea['id']; ?>);">
        <i class="fas fa-trash-alt"></i>
    </a>
</div>
<?php endif; ?>

        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function handleTaskStatusChange(taskId, checkbox) {
    const newStatus = checkbox.checked ? 'completada' : 'pendiente';
    const taskCard = document.getElementById(`taskCard${taskId}`);
    
    if (!checkbox.checked) {
        // Solo mostrar confirmación cuando se DESMARCA el checkbox
        if (!confirm('¿Estás seguro que quieres marcar esta tarea como pendiente?')) {
            checkbox.checked = true; // Revertir el cambio si cancela
            return;
        }
    }

    // Actualizar visualmente primero para mejor experiencia de usuario
    if (newStatus === 'completada') {
        taskCard.classList.remove('pendiente');
        taskCard.classList.add('completada');
        taskCard.querySelector('.task-title').classList.add('completed-task');
    } else {
        taskCard.classList.remove('completada');
        taskCard.classList.add('pendiente');
        taskCard.querySelector('.task-title').classList.remove('completed-task');
    }

    // Enviar la actualización al servidor
    updateTaskStatusOnServer(taskId, newStatus);
}

function updateTaskStatusOnServer(taskId, newStatus) {
    // AJAX para actualizar el estado en el servidor
    fetch('actualizar_estado.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `id=${taskId}&estado=${newStatus}`
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert('Error al actualizar la tarea: ' + (data.message || ''));
            // Revertir cambios visuales si hay error
            const checkbox = document.getElementById(`task${taskId}`);
            checkbox.checked = !checkbox.checked;
            handleTaskStatusChange(taskId, checkbox);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error de conexión al actualizar la tarea');
        const checkbox = document.getElementById(`task${taskId}`);
        checkbox.checked = !checkbox.checked;
        handleTaskStatusChange(taskId, checkbox);
    });
}
</script>
        <?php endif; ?>
    </div>

    <script>
    function filtrarTareas() {
        const estado = document.getElementById('estado').value;
        const prioridad = document.getElementById('prioridad').value;
        let url = 'tareas.php';
        const params = [];
        if (estado) params.push('estado=' + estado);
        if (prioridad) params.push('prioridad=' + prioridad);
        if (params.length > 0) { url += '?' + params.join('&'); }
        window.location.href = url;
    }

    // ALERTA SWEETALERT2 AL ELIMINAR
    <?php if ($eliminacion_exitosa): ?>
    Swal.fire({
        icon: 'success',
        title: 'Tarea eliminada',
        text: 'La tarea ha sido eliminada correctamente.',
        showConfirmButton: false,
        timer: 2000
    }).then(() => { window.location = "tareas.php"; });
    <?php endif; ?>

    // Confirmar eliminación con SweetAlert2
    function confirmarEliminar(id) {
        Swal.fire({
            title: "¿Eliminar tarea?",
            text: "Esta acción no se puede deshacer.",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#dc3545",
            cancelButtonColor: "#6c757d",
            confirmButtonText: "Sí, eliminar",
            cancelButtonText: "Cancelar"
        }).then((result) => {
            if (result.isConfirmed) {
                window.location = 'tareas.php?eliminar=' + id;
            }
        });
        return false;
    }
    </script>
<?php include 'footer.php'; ?>
</body>
</html>