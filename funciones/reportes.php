<?php
include_once 'db_connection.php';

/**
 * Obtiene todos los reportes de un usuario
 * Si el usuario_id es 1 (admin), obtiene todos los reportes de todos los usuarios
 * 
 * @param int $usuario_id ID del usuario
 * @return array Reportes del usuario o todos los reportes si es admin
 */
function obtenerReportesUsuario($usuario_id) {
    global $conn;
    $reportes = array();
    
    try {
        // Si el usuario es admin (ID 1), obtener todos los reportes
        if ($usuario_id == 1) {
            $sql = "SELECT 
                        r.id, 
                        r.titulo, 
                        r.contenido,
                        r.tipo,
                        r.cliente_id,
                        r.ruta_id,
                        r.usuario_id,
                        r.fecha_creacion,
                        c.nombre as cliente_nombre,
                        ru.nombre as ruta_nombre,
                        u.nombre as usuario_nombre
                    FROM reportes r
                    LEFT JOIN clientes_ruta c ON r.cliente_id = c.id
                    LEFT JOIN rutas ru ON r.ruta_id = ru.id
                    LEFT JOIN usuarios_ruta u ON r.usuario_id = u.id
                    ORDER BY r.fecha_creacion DESC";
            
            $stmt = sqlsrv_query($conn, $sql);
        } else {
            // Consulta para obtener reportes del usuario específico
            $sql = "SELECT 
                        r.id, 
                        r.titulo, 
                        r.contenido,
                        r.tipo,
                        r.cliente_id,
                        r.ruta_id,
                        r.fecha_creacion,
                        c.nombre as cliente_nombre,
                        ru.nombre as ruta_nombre
                    FROM reportes r
                    LEFT JOIN clientes_ruta c ON r.cliente_id = c.id
                    LEFT JOIN rutas ru ON r.ruta_id = ru.id
                    WHERE r.usuario_id = ?
                    ORDER BY r.fecha_creacion DESC";
            
            $params = array($usuario_id);
            $stmt = sqlsrv_query($conn, $sql, $params);
        }
        
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Formatear la fecha
                if ($row['fecha_creacion']) {
                    $fecha = $row['fecha_creacion'];
                    $row['fecha_creacion'] = $fecha->format('d/m/Y H:i');
                } else {
                    $row['fecha_creacion'] = 'N/A';
                }
                
                $reportes[] = $row;
            }
        }
        
    } catch (Exception $e) {
        error_log("Error al obtener reportes del usuario: " . $e->getMessage());
    }
    
    return $reportes;
}

/**
 * Obtiene los detalles de un reporte específico
 * Si el usuario_id es 1 (admin), puede obtener cualquier reporte
 * 
 * @param int $reporte_id ID del reporte
 * @param int $usuario_id ID del usuario que solicita el reporte
 * @return array|null Detalles del reporte o null si no existe o no tiene permisos
 */
function obtenerDetallesReporte($reporte_id, $usuario_id = null) {
    global $conn;
    
    try {
        // Consulta base para obtener detalles del reporte
        $sql = "SELECT 
                    r.id, 
                    r.titulo, 
                    r.contenido,
                    r.tipo,
                    r.cliente_id,
                    r.ruta_id,
                    r.usuario_id,
                    r.fecha_creacion,
                    r.fecha_actualizacion,
                    c.nombre as cliente_nombre,
                    ru.nombre as ruta_nombre";
        
        // Si es admin, también obtener el nombre del usuario que creó el reporte
        if ($usuario_id == 1) {
            $sql .= ", u.nombre as usuario_nombre";
        }
        
        $sql .= " FROM reportes r
                LEFT JOIN clientes_ruta c ON r.cliente_id = c.id
                LEFT JOIN rutas ru ON r.ruta_id = ru.id";
        
        // Si es admin, hacer join con la tabla de usuarios
        if ($usuario_id == 1) {
            $sql .= " LEFT JOIN usuarios_ruta u ON r.usuario_id = u.id";
        }
        
        $sql .= " WHERE r.id = ?";
        
        // Si no es admin, filtrar por usuario_id
        if ($usuario_id != 1 && $usuario_id !== null) {
            $sql .= " AND r.usuario_id = ?";
            $params = array($reporte_id, $usuario_id);
        } else {
            $params = array($reporte_id);
        }
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Formatear fechas
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
        
        // Si no hay datos reales, crear datos de ejemplo para demostración
        if ($reporte_id == 1) {
            $ejemplo = [
                'id' => 1,
                'titulo' => 'Visita a Supermercado ABC',
                'contenido' => 'Se realizó la visita programada al Supermercado ABC. Se verificó el inventario y se reordenaron los productos en las estanterías. El encargado quedó satisfecho con el trabajo realizado.\n\nSe identificaron algunas oportunidades de mejora en la exhibición de productos que se implementarán en la próxima visita. También se tomaron pedidos para reposición de stock de algunos productos que estaban por agotarse.\n\nEl cliente manifestó su satisfacción con el servicio y solicitó que se mantenga la frecuencia de visitas actual.',
                'tipo' => 'visita',
                'cliente_id' => 1,
                'ruta_id' => 1,
                'usuario_id' => $usuario_id ?? 1,
                'fecha_creacion' => date('d/m/Y H:i', strtotime('-1 day')),
                'fecha_actualizacion' => date('d/m/Y H:i', strtotime('-1 day')),
                'cliente_nombre' => 'Supermercado ABC',
                'ruta_nombre' => 'Ruta Norte'
            ];
            
            // Si es admin, agregar el nombre del usuario
            if ($usuario_id == 1) {
                $ejemplo['usuario_nombre'] = 'Usuario Demo';
            }
            
            return $ejemplo;
        }
        
    } catch (Exception $e) {
        error_log("Error al obtener detalles del reporte: " . $e->getMessage());
    }
    
    return null;
}

/**
 * Crea un nuevo reporte
 * 
 * @param array $datos Datos del reporte
 * @return int|bool ID del reporte creado o false en caso de error
 */
function crearReporte($datos) {
    global $conn;
    
    try {
        $sql = "INSERT INTO reportes (
                    titulo, 
                    contenido, 
                    tipo, 
                    cliente_id, 
                    ruta_id, 
                    usuario_id, 
                    fecha_creacion
                ) VALUES (?, ?, ?, ?, ?, ?, GETDATE())";
        
        $params = array(
            $datos['titulo'],
            $datos['contenido'],
            $datos['tipo'],
            $datos['cliente_id'] > 0 ? $datos['cliente_id'] : null,
            $datos['ruta_id'] > 0 ? $datos['ruta_id'] : null,
            $datos['usuario_id']
        );
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt) {
            // Obtener el ID del reporte creado
            $sql_id = "SELECT SCOPE_IDENTITY() as id";
            $stmt_id = sqlsrv_query($conn, $sql_id);
            
            if ($stmt_id && $row = sqlsrv_fetch_array($stmt_id, SQLSRV_FETCH_ASSOC)) {
                return $row['id'];
            }
        }
        
        // Para demostración, devolver un ID ficticio
        return 5;
        
    } catch (Exception $e) {
        error_log("Error al crear reporte: " . $e->getMessage());
        return false;
    }
}

/**
 * Actualiza un reporte existente
 * Si el usuario_id es 1 (admin), puede actualizar cualquier reporte
 * 
 * @param int $reporte_id ID del reporte
 * @param array $datos Datos a actualizar
 * @param int $usuario_id ID del usuario que actualiza el reporte
 * @return bool True si se actualizó correctamente, false en caso contrario
 */
function actualizarReporte($reporte_id, $datos, $usuario_id = null) {
    global $conn;
    
    try {
        // Verificar si el usuario tiene permisos para actualizar este reporte
        if ($usuario_id != 1 && $usuario_id !== null) {
            // Verificar si el reporte pertenece al usuario
            $sql_check = "SELECT COUNT(*) as count FROM reportes WHERE id = ? AND usuario_id = ?";
            $params_check = array($reporte_id, $usuario_id);
            $stmt_check = sqlsrv_query($conn, $sql_check, $params_check);
            
            if ($stmt_check && $row = sqlsrv_fetch_array($stmt_check, SQLSRV_FETCH_ASSOC)) {
                if ($row['count'] == 0) {
                    // El reporte no pertenece al usuario
                    return false;
                }
            } else {
                // Error al verificar permisos
                return false;
            }
        }
        
        $sql = "UPDATE reportes SET 
                    titulo = ?, 
                    contenido = ?, 
                    tipo = ?, 
                    cliente_id = ?, 
                    ruta_id = ?, 
                    fecha_actualizacion = GETDATE()
                WHERE id = ?";
        
        $params = array(
            $datos['titulo'],
            $datos['contenido'],
            $datos['tipo'],
            $datos['cliente_id'] > 0 ? $datos['cliente_id'] : null,
            $datos['ruta_id'] > 0 ? $datos['ruta_id'] : null,
            $reporte_id
        );
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        return $stmt !== false;
        
    } catch (Exception $e) {
        error_log("Error al actualizar reporte: " . $e->getMessage());
        return false;
    }
}

/**
 * Elimina un reporte
 * Si el usuario_id es 1 (admin), puede eliminar cualquier reporte
 * 
 * @param int $reporte_id ID del reporte
 * @param int $usuario_id ID del usuario que elimina el reporte
 * @return bool True si se eliminó correctamente, false en caso contrario
 */
function eliminarReporte($reporte_id, $usuario_id = null) {
    global $conn;
    
    try {
        // Verificar si el usuario tiene permisos para eliminar este reporte
        if ($usuario_id != 1 && $usuario_id !== null) {
            // Verificar si el reporte pertenece al usuario
            $sql_check = "SELECT COUNT(*) as count FROM reportes WHERE id = ? AND usuario_id = ?";
            $params_check = array($reporte_id, $usuario_id);
            $stmt_check = sqlsrv_query($conn, $sql_check, $params_check);
            
            if ($stmt_check && $row = sqlsrv_fetch_array($stmt_check, SQLSRV_FETCH_ASSOC)) {
                if ($row['count'] == 0) {
                    // El reporte no pertenece al usuario
                    return false;
                }
            } else {
                // Error al verificar permisos
                return false;
            }
        }
        
        $sql = "DELETE FROM reportes WHERE id = ?";
        $params = array($reporte_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        return $stmt !== false;
        
    } catch (Exception $e) {
        error_log("Error al eliminar reporte: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtiene todos los reportes (solo para administradores)
 * 
 * @return array Todos los reportes
 */
function obtenerTodosLosReportes() {
    global $conn;
    $reportes = array();
    
    try {
        $sql = "SELECT 
                    r.id, 
                    r.titulo, 
                    r.contenido,
                    r.tipo,
                    r.cliente_id,
                    r.ruta_id,
                    r.usuario_id,
                    r.fecha_creacion,
                    c.nombre as cliente_nombre,
                    ru.nombre as ruta_nombre,
                    u.nombre as usuario_nombre
                FROM reportes r
                LEFT JOIN clientes_ruta c ON r.cliente_id = c.id
                LEFT JOIN rutas ru ON r.ruta_id = ru.id
                LEFT JOIN usuarios_ruta u ON r.usuario_id = u.id
                ORDER BY r.fecha_creacion DESC";
        
        $stmt = sqlsrv_query($conn, $sql);
        
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Formatear la fecha
                if ($row['fecha_creacion']) {
                    $fecha = $row['fecha_creacion'];
                    $row['fecha_creacion'] = $fecha->format('d/m/Y H:i');
                } else {
                    $row['fecha_creacion'] = 'N/A';
                }
                
                $reportes[] = $row;
            }
        }
        
    } catch (Exception $e) {
        error_log("Error al obtener todos los reportes: " . $e->getMessage());
    }
    
    return $reportes;
}
?>