<?php
include_once 'db_connection.php';

/**
* Obtiene todos los clientes de un usuario
* 
* @param int $usuario_id ID del usuario
* @return array Clientes del usuario
*/
function obtenerClientesUsuario($usuario_id) {
    global $conn;
    $clientes = array();
    
    try {
        // Consulta para obtener clientes del usuario
        $sql = "SELECT 
                    c.id, 
                    c.nombre, 
                    c.direccion,
                    c.telefono,
                    c.estado,
                    c.ruta_id,
                    r.nombre as ruta_nombre
                FROM clientes_ruta c
                JOIN rutas r ON c.ruta_id = r.id
                WHERE c.usuario_id = ? OR r.usuario_id = ?
                ORDER BY c.nombre ASC";
        
        $params = array($usuario_id, $usuario_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $clientes[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Error al obtener clientes del usuario: " . $e->getMessage());
    }
    
    return $clientes;
}

/**
* Obtiene los detalles de un cliente específico
* 
* @param int $cliente_id ID del cliente
* @return array|null Detalles del cliente o null si no existe
*/
function obtenerDetallesCliente($cliente_id) {
    global $conn;
    
    try {
        // Consulta para obtener detalles del cliente
        $sql = "SELECT 
                    c.id, 
                    c.nombre, 
                    c.direccion,
                    c.telefono,
                    c.estado,
                    c.ruta_id,
                    c.usuario_id,
                    r.nombre as ruta_nombre
                FROM clientes_ruta c
                JOIN rutas r ON c.ruta_id = r.id
                WHERE c.id = ?";
        
        $params = array($cliente_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            return $row;
        }
    } catch (Exception $e) {
        error_log("Error al obtener detalles del cliente: " . $e->getMessage());
    }
    
    return null;
}

/**
* Crea un nuevo cliente
* 
* @param array $datos Datos del cliente
* @return int|bool ID del cliente creado o false en caso de error
*/
function crearCliente($datos) {
    global $conn;
    
    try {
        $sql = "INSERT INTO clientes_ruta (
                    nombre, 
                    direccion, 
                    telefono, 
                    estado, 
                    ruta_id, 
                    usuario_id
                ) VALUES (?, ?, ?, ?, ?, ?)";
        
        $params = array(
            $datos['nombre'],
            $datos['direccion'],
            $datos['telefono'],
            'pendiente', // Estado inicial
            $datos['ruta_id'],
            $datos['usuario_id']
        );
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt) {
            // Obtener el ID del cliente creado
            $sql_id = "SELECT SCOPE_IDENTITY() as id";
            $stmt_id = sqlsrv_query($conn, $sql_id);
            
            if ($stmt_id && $row = sqlsrv_fetch_array($stmt_id, SQLSRV_FETCH_ASSOC)) {
                return $row['id'];
            }
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error al crear cliente: " . $e->getMessage());
        return false;
    }
}

/**
* Actualiza un cliente existente
* 
* @param int $cliente_id ID del cliente
* @param array $datos Datos a actualizar
* @return bool True si se actualizó correctamente, false en caso contrario
*/
function actualizarCliente($cliente_id, $datos) {
    global $conn;
    
    try {
        $sql = "UPDATE clientes_ruta SET 
                    nombre = ?, 
                    direccion = ?, 
                    telefono = ?, 
                    ruta_id = ?
                WHERE id = ?";
        
        $params = array(
            $datos['nombre'],
            $datos['direccion'],
            $datos['telefono'],
            $datos['ruta_id'],
            $cliente_id
        );
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        return $stmt !== false;
        
    } catch (Exception $e) {
        error_log("Error al actualizar cliente: " . $e->getMessage());
        return false;
    }
}

/**
* Actualiza el estado de un cliente
* 
* @param int $cliente_id ID del cliente
* @param string $estado Nuevo estado (pendiente, visitado)
* @return bool True si se actualizó correctamente, false en caso contrario
*/
function actualizarEstadoCliente($cliente_id, $estado) {
    global $conn;
    
    try {
        $sql = "UPDATE clientes_ruta SET estado = ? WHERE id = ?";
        
        $params = array($estado, $cliente_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt !== false) {
            // Si se marca como visitado, actualizar el progreso de la ruta
            if ($estado === 'visitado') {
                // Obtener la ruta del cliente
                $sql_ruta = "SELECT ruta_id FROM clientes_ruta WHERE id = ?";
                $params_ruta = array($cliente_id);
                $stmt_ruta = sqlsrv_query($conn, $sql_ruta, $params_ruta);
                
                if ($stmt_ruta && $row = sqlsrv_fetch_array($stmt_ruta, SQLSRV_FETCH_ASSOC)) {
                    $ruta_id = $row['ruta_id'];
                    
                    // Calcular el nuevo progreso
                    $sql_progreso = "SELECT 
                                        COUNT(*) as total,
                                        SUM(CASE WHEN estado = 'visitado' THEN 1 ELSE 0 END) as visitados
                                    FROM clientes_ruta
                                    WHERE ruta_id = ?";
                    
                    $params_progreso = array($ruta_id);
                    $stmt_progreso = sqlsrv_query($conn, $sql_progreso, $params_progreso);
                    
                    if ($stmt_progreso && $row = sqlsrv_fetch_array($stmt_progreso, SQLSRV_FETCH_ASSOC)) {
                        $total = $row['total'];
                        $visitados = $row['visitados'];
                        
                        if ($total > 0) {
                            $progreso = round(($visitados / $total) * 100);
                            
                            // Actualizar el progreso de la ruta
                            $sql_update = "UPDATE rutas SET progreso = ? WHERE id = ?";
                            $params_update = array($progreso, $ruta_id);
                            sqlsrv_query($conn, $sql_update, $params_update);
                        }
                    }
                }
            }
            
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error al actualizar estado del cliente: " . $e->getMessage());
        return false;
    }
}

/**
* Elimina un cliente
* 
* @param int $cliente_id ID del cliente
* @return bool True si se eliminó correctamente, false en caso contrario
*/
function eliminarCliente($cliente_id) {
    global $conn;
    
    try {
        // Obtener la ruta del cliente antes de eliminarlo
        $sql_ruta = "SELECT ruta_id FROM clientes_ruta WHERE id = ?";
        $params_ruta = array($cliente_id);
        $stmt_ruta = sqlsrv_query($conn, $sql_ruta, $params_ruta);
        
        $ruta_id = null;
        if ($stmt_ruta && $row = sqlsrv_fetch_array($stmt_ruta, SQLSRV_FETCH_ASSOC)) {
            $ruta_id = $row['ruta_id'];
        }
        
        // Eliminar el cliente
        $sql = "DELETE FROM clientes_ruta WHERE id = ?";
        $params = array($cliente_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt !== false && $ruta_id) {
            // Actualizar el progreso de la ruta
            $sql_progreso = "SELECT 
                                COUNT(*) as total,
                                SUM(CASE WHEN estado = 'visitado' THEN 1 ELSE 0 END) as visitados
                            FROM clientes_ruta
                            WHERE ruta_id = ?";
            
            $params_progreso = array($ruta_id);
            $stmt_progreso = sqlsrv_query($conn, $sql_progreso, $params_progreso);
            
            if ($stmt_progreso && $row = sqlsrv_fetch_array($stmt_progreso, SQLSRV_FETCH_ASSOC)) {
                $total = $row['total'];
                $visitados = $row['visitados'];
                
                $progreso = 0;
                if ($total > 0) {
                    $progreso = round(($visitados / $total) * 100);
                }
                
                // Actualizar el progreso de la ruta
                $sql_update = "UPDATE rutas SET progreso = ? WHERE id = ?";
                $params_update = array($progreso, $ruta_id);
                sqlsrv_query($conn, $sql_update, $params_update);
            }
        }
        
        return $stmt !== false;
        
    } catch (Exception $e) {
        error_log("Error al eliminar cliente: " . $e->getMessage());
        return false;
    }
}

/**
* Obtiene las rutas disponibles para asignar a un cliente
* 
* @param int $usuario_id ID del usuario
* @return array Rutas disponibles
*/
function obtenerRutasParaFiltro($usuario_id) {
    global $conn;
    $rutas = array();
    
    try {
        // Consulta para obtener rutas del usuario
        $sql = "SELECT id, nombre FROM rutas WHERE usuario_id = ? ORDER BY nombre ASC";
        
        $params = array($usuario_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rutas[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Error al obtener rutas para filtro: " . $e->getMessage());
    }
    
    return $rutas;
}
?>