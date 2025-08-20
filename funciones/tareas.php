<?php
include_once 'db_connection.php';

/**
* Obtiene todas las tareas de un usuario
* 
* @param int $usuario_id ID del usuario
* @return array Tareas del usuario
*/
function obtenerTareasUsuario($usuario_id) {
    global $conn;
    $tareas = array();
    
    try {
        // Consulta para obtener tareas del usuario
        $sql = "SELECT 
                    id, 
                    titulo, 
                    descripcion,
                    fecha_vencimiento,
                    prioridad,
                    estado,
                    fecha_creacion,
                    fecha_actualizacion
                FROM tareas
                WHERE usuario_id = ?
                ORDER BY 
                    CASE 
                        WHEN estado = 'pendiente' THEN 0
                        WHEN estado = 'completada' THEN 1
                    END,
                    CASE 
                        WHEN prioridad = 'alta' THEN 0
                        WHEN prioridad = 'media' THEN 1
                        WHEN prioridad = 'baja' THEN 2
                    END,
                    fecha_vencimiento ASC";
        
        $params = array($usuario_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Formatear fechas
                if ($row['fecha_vencimiento']) {
                    $fecha = $row['fecha_vencimiento'];
                    $hoy = new DateTime();
                    $fecha_vencimiento = new DateTime($fecha->format('Y-m-d H:i:s'));
                    
                    if ($fecha_vencimiento->format('Y-m-d') == $hoy->format('Y-m-d')) {
                        $row['fecha_vencimiento'] = 'Hoy, ' . $fecha_vencimiento->format('h:i A');
                    } else if ($fecha_vencimiento->format('Y-m-d') == $hoy->modify('+1 day')->format('Y-m-d')) {
                        $row['fecha_vencimiento'] = 'Mañana, ' . $fecha_vencimiento->format('h:i A');
                    } else {
                        $row['fecha_vencimiento'] = $fecha_vencimiento->format('d/m/Y, h:i A');
                    }
                } else {
                    $row['fecha_vencimiento'] = 'Sin fecha';
                }
                
                if ($row['fecha_creacion']) {
                    $fecha = $row['fecha_creacion'];
                    $row['fecha_creacion'] = $fecha->format('d/m/Y');
                } else {
                    $row['fecha_creacion'] = 'N/A';
                }
                
                $tareas[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Error al obtener tareas del usuario: " . $e->getMessage());
    }
    
    return $tareas;
}

/**
* Obtiene los detalles de una tarea específica
* 
* @param int $tarea_id ID de la tarea
* @return array|null Detalles de la tarea o null si no existe
*/
function obtenerDetallesTarea($tarea_id) {
    global $conn;
    
    try {
        // Consulta para obtener detalles de la tarea
        $sql = "SELECT 
                    id, 
                    titulo, 
                    descripcion,
                    fecha_vencimiento,
                    prioridad,
                    estado,
                    usuario_id,
                    fecha_creacion,
                    fecha_actualizacion
                FROM tareas
                WHERE id = ?";
        
        $params = array($tarea_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Formatear fechas
            if ($row['fecha_vencimiento']) {
                $fecha = $row['fecha_vencimiento'];
                $row['fecha_vencimiento_raw'] = $fecha->format('Y-m-d\TH:i');
                $row['fecha_vencimiento'] = $fecha->format('d/m/Y H:i');
            } else {
                $row['fecha_vencimiento_raw'] = '';
                $row['fecha_vencimiento'] = 'Sin fecha';
            }
            
            if ($row['fecha_creacion']) {
                $fecha = $row['fecha_creacion'];
                $row['fecha_creacion'] = $fecha->format('d/m/Y H:i');
            } else {
                $row['fecha_creacion'] = 'N/A';
            }
            
            if ($row['fecha_actualizacion']) {
                $fecha = $row['fecha_actualizacion'];
                $row['fecha_actualizacion'] = $fecha->format('d/m/Y H:i');
            } else {
                $row['fecha_actualizacion'] = 'N/A';
            }
            
            return $row;
        }
    } catch (Exception $e) {
        error_log("Error al obtener detalles de la tarea: " . $e->getMessage());
    }
    
    return null;
}

/**
* Crea una nueva tarea
* 
* @param array $datos Datos de la tarea
* @return int|bool ID de la tarea creada o false en caso de error
*/
function crearTarea($datos) {
    global $conn;
    
    try {
        $sql = "INSERT INTO tareas (
                    titulo, 
                    descripcion, 
                    fecha_vencimiento, 
                    prioridad, 
                    estado, 
                    usuario_id, 
                    fecha_creacion
                ) VALUES (?, ?, ?, ?, ?, ?, GETDATE())";
        
        $params = array(
            $datos['titulo'],
            $datos['descripcion'],
            !empty($datos['fecha_vencimiento']) ? $datos['fecha_vencimiento'] : null,
            $datos['prioridad'],
            'pendiente', // Estado inicial
            $datos['usuario_id']
        );
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt) {
            // Obtener el ID de la tarea creada
            $sql_id = "SELECT SCOPE_IDENTITY() as id";
            $stmt_id = sqlsrv_query($conn, $sql_id);
            
            if ($stmt_id && $row = sqlsrv_fetch_array($stmt_id, SQLSRV_FETCH_ASSOC)) {
                return $row['id'];
            }
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error al crear tarea: " . $e->getMessage());
        return false;
    }
}

/**
* Actualiza una tarea existente
* 
* @param int $tarea_id ID de la tarea
* @param array $datos Datos a actualizar
* @return bool True si se actualizó correctamente, false en caso contrario
*/
function actualizarTarea($tarea_id, $datos) {
    global $conn;
    
    try {
        $sql = "UPDATE tareas SET 
                    titulo = ?, 
                    descripcion = ?, 
                    fecha_vencimiento = ?, 
                    prioridad = ?, 
                    fecha_actualizacion = GETDATE()
                WHERE id = ?";
        
        $params = array(
            $datos['titulo'],
            $datos['descripcion'],
            !empty($datos['fecha_vencimiento']) ? $datos['fecha_vencimiento'] : null,
            $datos['prioridad'],
            $tarea_id
        );
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        return $stmt !== false;
        
    } catch (Exception $e) {
        error_log("Error al actualizar tarea: " . $e->getMessage());
        return false;
    }
}

/**
* Actualiza el estado de una tarea
* 
* @param int $tarea_id ID de la tarea
* @param string $estado Nuevo estado (pendiente, completada)
* @return bool True si se actualizó correctamente, false en caso contrario
*/
function actualizarEstadoTarea($tarea_id, $estado) {
    global $conn;
    
    try {
        $sql = "UPDATE tareas SET 
                    estado = ?, 
                    fecha_actualizacion = GETDATE()
                WHERE id = ?";
        
        $params = array($estado, $tarea_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        return $stmt !== false;
        
    } catch (Exception $e) {
        error_log("Error al actualizar estado de la tarea: " . $e->getMessage());
        return false;
    }
}

/**
* Elimina una tarea
* 
* @param int $tarea_id ID de la tarea
* @return bool True si se eliminó correctamente, false en caso contrario
*/
function eliminarTarea($tarea_id) {
    global $conn;
    
    try {
        $sql = "DELETE FROM tareas WHERE id = ?";
        $params = array($tarea_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        return $stmt !== false;
        
    } catch (Exception $e) {
        error_log("Error al eliminar tarea: " . $e->getMessage());
        return false;
    }
}
?>
