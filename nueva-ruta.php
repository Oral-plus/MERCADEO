<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['usuario_id'])) {
    $_SESSION['usuario_id'] = 1; // For testing purposes, set a default user ID
}

// db_connection.php - Database connection file
$serverName = "HERCULES";
$connectionInfo = array("Database" => "Ruta", "UID" => "sa", "PWD" => "Sky2022*!");
$conn = sqlsrv_connect($serverName, $connectionInfo);

if (!$conn) {
    die("Error connecting to database: " . print_r(sqlsrv_errors(), true));
}

/**
* Obtiene todas las rutas de un usuario
* 
* @param int $usuario_id ID del usuario
* @return array Rutas del usuario
*/
function obtenerRutasUsuario($usuario_id) {
    global $conn;
    $rutas = array();
    
    try {
        // Get user's NIT
        $nit = obtenerNitUsuario($usuario_id, $conn);
        
        // Consulta para obtener rutas del usuario con información de cliente y vendedor
        $sql = "SELECT 
                    r.id, 
                    r.nombre, 
                    r.estado,
                    r.distancia,
                    r.progreso,
                    r.fecha_creacion,
                    r.fecha_programada,
                    r.hora_visita,
                    r.cliente_id,
                    r.vendedor_id,
                    r.ciudad
                FROM rutas r
                WHERE r.usuario_id = ?";
                
        // Add NIT filter if available
        if (!empty($nit)) {
            $sql .= " AND r.vendedor_id = ?";
            $params = array($usuario_id, $nit);
        } else {
            $params = array($usuario_id);
        }
        
        $sql .= " ORDER BY r.fecha_programada DESC, r.fecha_creacion DESC";
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Formatear la fecha
                if (isset($row['fecha_creacion']) && $row['fecha_creacion']) {
                    $fecha = $row['fecha_creacion'];
                    $row['fecha_creacion'] = $fecha->format('d/m/Y');
                } else {
                    $row['fecha_creacion'] = 'N/A';
                }
                
                // Formatear la fecha programada
                if (isset($row['fecha_programada']) && $row['fecha_programada']) {
                    $fecha = $row['fecha_programada'];
                    $row['fecha_programada'] = $fecha->format('d/m/Y');
                    $row['dia_semana'] = $fecha->format('l'); // Día de la semana en inglés
                } else {
                    $row['fecha_programada'] = 'N/A';
                    $row['dia_semana'] = 'N/A';
                }
                
                // Obtener cantidad de clientes
                $sql_clientes = "SELECT COUNT(*) as total FROM clientes_ruta WHERE ruta_id = ?";
                $params_clientes = array($row['id']);
                $stmt_clientes = sqlsrv_query($conn, $sql_clientes, $params_clientes);
                
                if ($stmt_clientes && $cliente_row = sqlsrv_fetch_array($stmt_clientes, SQLSRV_FETCH_ASSOC)) {
                    $row['clientes'] = $cliente_row['total'];
                    sqlsrv_free_stmt($stmt_clientes);
                } else {
                    $row['clientes'] = 0;
                }
                
                // Obtener información del cliente de SAP si existe
                if (!empty($row['cliente_id'])) {
                    $cliente_info = obtenerClienteSAP($row['cliente_id']);
                    if ($cliente_info) {
                        $row['cliente_nombre'] = $cliente_info['CardName'];
                        $row['cliente_ciudad'] = $row['ciudad'] ?? $cliente_info['City'] ?? ''; // Use stored city first
                        $row['cliente_zona'] = $cliente_info['Territory'] ?? '';
                        $row['cliente_canal'] = $cliente_info['GroupCode'] ?? '';
                    } else {
                        $row['cliente_nombre'] = 'Cliente no encontrado';
                        $row['cliente_ciudad'] = $row['ciudad'] ?? ''; // Use stored city
                        $row['cliente_zona'] = '';
                        $row['cliente_canal'] = '';
                    }
                } else {
                    $row['cliente_nombre'] = 'Sin cliente asignado';
                    $row['cliente_ciudad'] = $row['ciudad'] ?? ''; // Use stored city
                    $row['cliente_zona'] = '';
                    $row['cliente_canal'] = '';
                }
                
                // Obtener información del vendedor de SAP si existe
                if (!empty($row['vendedor_id'])) {
                    $vendedor_info = obtenerVendedorSAP($row['vendedor_id']);
                    if ($vendedor_info) {
                        $row['vendedor_nombre'] = $vendedor_info['SlpName'];
                    } else {
                        $row['vendedor_nombre'] = 'Vendedor no encontrado';
                    }
                } else {
                    $row['vendedor_nombre'] = 'Sin vendedor asignado';
                }
                
                $rutas[] = $row;
            }
            
            sqlsrv_free_stmt($stmt);
        }
    } catch (Exception $e) {
        error_log("Error al obtener rutas del usuario: " . $e->getMessage());
    }
    
    return $rutas;
}

/**
* Obtiene las rutas programadas para una semana específica
* 
* @param string $fecha_inicio Fecha de inicio de la semana (formato Y-m-d)
* @param int|null $vendedor_id ID del vendedor para filtrar (opcional)
* @param int $usuario_id ID del usuario
* @param string $busqueda Término de búsqueda para filtrar rutas (opcional)
* @param string $zona Zona para filtrar (opcional)
* @param string $canal Canal para filtrar (opcional)
* @return array Rutas de la semana
*/
function obtenerRutasSemana($fecha_inicio, $vendedor_id = null, $usuario_id, $busqueda = '', $zona = '', $canal = '') {
    global $conn;
    $rutas = array();
    
    try {
        // Verificar si la columna fecha_programada existe
        $sql_check = "IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas') AND name = 'fecha_programada')
                     BEGIN
                         ALTER TABLE rutas ADD fecha_programada DATE NULL;
                     END";
        sqlsrv_query($conn, $sql_check);
        
        // Verificar si la columna ciudad existe
        $sql_check_ciudad = "IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas') AND name = 'ciudad')
                     BEGIN
                         ALTER TABLE rutas ADD ciudad VARCHAR(100) NULL;
                     END";
        sqlsrv_query($conn, $sql_check_ciudad);
        
        // Verificar si la columna hora_visita existe
        $sql_check_hora = "IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas') AND name = 'hora_visita')
                     BEGIN
                         ALTER TABLE rutas ADD hora_visita TIME NULL;
                     END";
        sqlsrv_query($conn, $sql_check_hora);
        
        // Get user's NIT
        $nit = obtenerNitUsuario($usuario_id, $conn);
        
        // Calcular fecha fin (6 días después de la fecha inicio para incluir sábado)
        $fecha_inicio_obj = new DateTime($fecha_inicio);
        $fecha_fin_obj = clone $fecha_inicio_obj;
        $fecha_fin_obj->modify('+5 days');
        
        $fecha_fin = $fecha_fin_obj->format('Y-m-d');
        
        // Consulta base
        $sql = "SELECT 
                    r.id, 
                    r.nombre, 
                    r.estado,
                    r.distancia, 
                    r.progreso,
                    r.fecha_creacion,
                    r.fecha_programada,
                    r.hora_visita,
                    r.cliente_id,
                    r.vendedor_id,
                    r.ciudad
                FROM rutas r
                WHERE r.usuario_id = ?
                AND r.fecha_programada >= ?
                AND r.fecha_programada <= ?";
        
        $params = array($usuario_id, $fecha_inicio, $fecha_fin);
        
        // Add NIT filter if available
        if (!empty($nit)) {
            $sql .= " AND r.vendedor_id = ?";
            $params[] = $nit;
        }
        
        // Agregar filtro de vendedor si se proporciona
        if ($vendedor_id) {
            $sql .= " AND r.vendedor_id = ?";
            $params[] = $vendedor_id;
        }
        
        // Agregar filtro de búsqueda si se proporciona
        if (!empty($busqueda)) {
            $sql .= " AND (r.nombre LIKE ? OR r.cliente_id LIKE ?)";
            $params[] = '%' . $busqueda . '%';
            $params[] = '%' . $busqueda . '%';
        }
        
        $sql .= " ORDER BY r.fecha_programada ASC, r.hora_visita ASC";
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt === false) {
            error_log("Error al ejecutar consulta obtenerRutasSemana: " . print_r(sqlsrv_errors(), true));
            return $rutas;
        }
        
        $rutas_temp = array();
        
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Formatear la fecha
            if (isset($row['fecha_creacion']) && $row['fecha_creacion']) {
                $fecha = $row['fecha_creacion'];
                $row['fecha_creacion'] = $fecha->format('d/m/Y');
            } else {
                $row['fecha_creacion'] = 'N/A';
            }
            
            // Formatear la fecha programada
            if (isset($row['fecha_programada']) && $row['fecha_programada']) {
                $fecha = $row['fecha_programada'];
                $row['fecha_programada'] = $fecha->format('d/m/Y');
                $row['dia_semana'] = $fecha->format('l'); // Día de la semana en inglés
            } else {
                $row['fecha_programada'] = 'N/A';
                $row['dia_semana'] = 'N/A';
            }
            
            // Formatear la hora de visita
            if (isset($row['hora_visita']) && $row['hora_visita']) {
                $hora = $row['hora_visita'];
                $row['hora_visita'] = $hora->format('H:i');
            } else {
                $row['hora_visita'] = '';
            }
            
            // Obtener cantidad de clientes
            $sql_clientes = "SELECT COUNT(*) as total FROM clientes_ruta WHERE ruta_id = ?";
            $params_clientes = array($row['id']);
            $stmt_clientes = sqlsrv_query($conn, $sql_clientes, $params_clientes);
            
            if ($stmt_clientes && $cliente_row = sqlsrv_fetch_array($stmt_clientes, SQLSRV_FETCH_ASSOC)) {
                $row['clientes'] = $cliente_row['total'];
                sqlsrv_free_stmt($stmt_clientes);
            } else {
                $row['clientes'] = 0;
            }
            
            // Obtener información del cliente de SAP si existe
            if (!empty($row['cliente_id'])) {
                $cliente_info = obtenerClienteSAP($row['cliente_id']);
                if ($cliente_info) {
                    $row['cliente_nombre'] = $cliente_info['CardName'];
                    $row['cliente_ciudad'] = $row['ciudad'] ?? $cliente_info['City'] ?? ''; // Use stored city first
                    $row['cliente_zona'] = $cliente_info['Territory'] ?? '';
                    $row['cliente_canal'] = $cliente_info['GroupCode'] ?? '';
                } else {
                    $row['cliente_nombre'] = 'Cliente no encontrado';
                    $row['cliente_ciudad'] = $row['ciudad'] ?? ''; // Use stored city
                    $row['cliente_zona'] = '';
                    $row['cliente_canal'] = '';
                }
            } else {
                $row['cliente_nombre'] = 'Sin cliente asignado';
                $row['cliente_ciudad'] = $row['ciudad'] ?? ''; // Use stored city
                $row['cliente_zona'] = '';
                $row['cliente_canal'] = '';
            }
            
            // Obtener información del vendedor de SAP si existe
            if (!empty($row['vendedor_id'])) {
                $vendedor_info = obtenerVendedorSAP($row['vendedor_id']);
                if ($vendedor_info) {
                    $row['vendedor_nombre'] = $vendedor_info['SlpName'];
                } else {
                    $row['vendedor_nombre'] = 'Vendedor no encontrado';
                }
            } else {
                $row['vendedor_nombre'] = 'Sin vendedor asignado';
            }
            
            $rutas_temp[] = $row;
        }
        
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
        
        // Aplicar filtros adicionales (zona y canal) después de obtener la información completa
        foreach ($rutas_temp as $ruta) {
            $incluir = true;
            
            // Filtrar por zona si se especifica
            if (!empty($zona) && isset($ruta['cliente_zona']) && $ruta['cliente_zona'] != $zona) {
                $incluir = false;
            }
            
            // Filtrar por canal si se especifica
            if (!empty($canal) && isset($ruta['cliente_canal']) && $ruta['cliente_canal'] != $canal) {
                $incluir = false;
            }
            
            if ($incluir) {
                $rutas[] = $ruta;
            }
        }
    } catch (Exception $e) {
        error_log("Error en obtenerRutasSemana: " . $e->getMessage());
    }
    
    return $rutas;
}

/**
* Obtiene el nombre del usuario a partir de su ID
* 
* @param int $usuario_id ID del usuario
* @return string Nombre del usuario
*/
function obtenerNombreUsuario($usuario_id) {
    global $conn;
    
    try {
        $sql = "SELECT nombre FROM usuarios_ruta WHERE id = ?";
        $params = array($usuario_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            sqlsrv_free_stmt($stmt);
            return $row['nombre'];
        }
        
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
    } catch (Exception $e) {
        error_log("Error al obtener nombre de usuario: " . $e->getMessage());
    }
    
    return 'Usuario desconocido';
}

/**
* Crea una nueva ruta en la base de datos
* 
* @param array $datos Datos de la ruta a crear
* @return int|bool ID de la ruta creada o false en caso de error
*/
function crearRuta($datos) {
    global $conn;
    
    try {
        // Verificar si la tabla tiene la columna fecha_programada
        $sql_check = "IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'fecha_programada')
                     BEGIN
                         ALTER TABLE rutas1 ADD fecha_programada DATE NULL;
                     END";
        sqlsrv_query($conn, $sql_check);
        
        // Verificar si la tabla tiene la columna ciudad
        $sql_check_ciudad = "IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'ciudad')
                     BEGIN
                         ALTER TABLE rutas1 ADD ciudad VARCHAR(100) NULL;
                     END";
        sqlsrv_query($conn, $sql_check_ciudad);
        
        // Verificar si la tabla tiene la columna hora_visita
        $sql_check_hora = "IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'hora_visita')
                     BEGIN
                         ALTER TABLE rutas1 ADD hora_visita TIME NULL;
                     END";
        sqlsrv_query($conn, $sql_check_hora);
        
        // Obtener la ciudad del cliente si existe
        $ciudad = '';
        if (!empty($datos['cliente_id'])) {
            $cliente_info = obtenerClienteSAP($datos['cliente_id']);
            if ($cliente_info && isset($cliente_info['City'])) {
                $ciudad = $cliente_info['City'];
            }
        }
        
        // Si se proporciona una ciudad en los datos, usarla en lugar de la obtenida
        if (!empty($datos['ciudad'])) {
            $ciudad = $datos['ciudad'];
        }
        
        // Si no se proporciona un vendedor_id, usar el NIT del usuario
        if (empty($datos['vendedor_id'])) {
            $nit = obtenerNitUsuario($datos['usuario_id'], $conn);
            if (!empty($nit)) {
                $datos['vendedor_id'] = $nit;
            }
        }
        
        // Depuración: Imprimir los datos antes de la inserción
        error_log("Datos para crear ruta: " . print_r($datos, true));
        
        // Consulta SQL corregida con el número correcto de parámetros
        $sql = "INSERT INTO rutas1 (
                    nombre, 
                    estado, 
                    usuario_id,
                    cliente_id,
                    vendedor_id,
                    fecha_programada,
                    hora_visita,
                    fecha_creacion,
                    fecha_actualizacion,
                    ciudad
                ) VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE(), ?);
                SELECT SCOPE_IDENTITY() AS id";
        
        // Preparar fecha programada
        $fecha_programada = null;
        if (isset($datos['fecha_programada']) && !empty($datos['fecha_programada'])) {
            $fecha_programada = $datos['fecha_programada'];
        }
        
        // Preparar hora de visita
        $hora_visita = null;
        if (isset($datos['hora_visita']) && !empty($datos['hora_visita'])) {
            $hora_visita = $datos['hora_visita'];
        }
        
        // Preparar parámetros para la consulta
        $params = array(
            $datos['nombre'],
            'activa', // Estado inicial
            $datos['usuario_id'],
            isset($datos['cliente_id']) ? $datos['cliente_id'] : null,
            isset($datos['vendedor_id']) ? $datos['vendedor_id'] : null,
            $fecha_programada,
            $hora_visita,
            $ciudad // Guardar la ciudad
        );
        
        // Depuración: Imprimir la consulta y los parámetros
        error_log("SQL: " . $sql);
        error_log("Params: " . print_r($params, true));
        
        // Ejecutar la consulta
        $stmt = sqlsrv_query($conn, $sql, $params);
        

// Asegurarse de manejar el resultado correctamente
if ($stmt === false) {
    error_log("Error al ejecutar la consulta: " . print_r(sqlsrv_errors(), true));
    return false;
} else {
    // Verificar si la consulta fue de inserción
    $rows_affected = sqlsrv_rows_affected($stmt);
    
    if ($rows_affected === false || $rows_affected === 0) {
        error_log("La consulta no afectó ninguna fila");
        sqlsrv_free_stmt($stmt);
        return false;
    }
    
    // Intentar obtener el ID generado
    if (sqlsrv_fetch($stmt)) {
        $ruta_id = sqlsrv_get_field($stmt, 0);
        sqlsrv_free_stmt($stmt);
        
        if (is_numeric($ruta_id) && $ruta_id > 0) {
            error_log("Ruta creada exitosamente con ID: $ruta_id");
            return intval($ruta_id);
        }
    }
    
    // Si no se pudo obtener el ID directamente, intentar con SCOPE_IDENTITY()
    $sql_last_id = "SELECT SCOPE_IDENTITY() AS last_id";
    $stmt_last = sqlsrv_query($conn, $sql_last_id);
    
    if ($stmt_last && sqlsrv_fetch($stmt_last)) {
        $last_id = sqlsrv_get_field($stmt_last, 0);
        sqlsrv_free_stmt($stmt_last);
        
        if (is_numeric($last_id) && $last_id > 0) {
            error_log("Obtenido ID mediante SCOPE_IDENTITY(): $last_id");
            return intval($last_id);
        }
    }
    
    // Como último recurso, obtener el MAX(id)
    $sql_max_id = "SELECT MAX(id) AS last_id FROM rutas1";
    $stmt_max = sqlsrv_query($conn, $sql_max_id);
    
    if ($stmt_max && sqlsrv_fetch($stmt_max)) {
        $max_id = sqlsrv_get_field($stmt_max, 0);
        sqlsrv_free_stmt($stmt_max);
        
        if (is_numeric($max_id) && $max_id > 0) {
            error_log("Obtenido ID mediante MAX(id): $max_id");
            return intval($max_id);
        }
    }
    
    error_log("No se pudo determinar el ID generado, pero la inserción parece exitosa");
    return true;
}
        // Obtener el ID de la ruta creada
        if (sqlsrv_fetch($stmt)) {
            $ruta_id = sqlsrv_get_field($stmt, 0);
            sqlsrv_free_stmt($stmt);
            return $ruta_id;
        }
        
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Excepción al crear ruta: " . $e->getMessage());
        return false;
    }
}

/**
* Actualiza una ruta existente
* 
* @param int $ruta_id ID de la ruta
* @param array $datos Datos a actualizar
* @return bool True si se actualizó correctamente, false en caso contrario
*/
function actualizarRuta($ruta_id, $datos) {
    global $conn;
    
    try {
        // Verificar si la tabla tiene la columna ciudad
        $sql_check_ciudad = "IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'ciudad')
                     BEGIN
                         ALTER TABLE rutas1 ADD ciudad VARCHAR(100) NULL;
                     END";
        sqlsrv_query($conn, $sql_check_ciudad);
        
        // Verificar si la tabla tiene la columna hora_visita
        $sql_check_hora = "IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'hora_visita')
                     BEGIN
                         ALTER TABLE rutas1 ADD hora_visita TIME NULL;
                     END";
        sqlsrv_query($conn, $sql_check_hora);
        
        // Obtener la ciudad del cliente si existe y no se proporciona en los datos
        if (!isset($datos['ciudad']) && !empty($datos['cliente_id'])) {
            $cliente_info = obtenerClienteSAP($datos['cliente_id']);
            if ($cliente_info && isset($cliente_info['City'])) {
                $datos['ciudad'] = $cliente_info['City'];
            }
        }
        
        // Si no se proporciona un vendedor_id, usar el NIT del usuario
        if (empty($datos['vendedor_id']) && isset($datos['usuario_id'])) {
            $nit = obtenerNitUsuario($datos['usuario_id'], $conn);
            if (!empty($nit)) {
                $datos['vendedor_id'] = $nit;
            }
        }
        
        $sql = "UPDATE rutas1 SET ";
        $params = array();
        $sets = array();
        
        if (isset($datos['nombre'])) {
            $sets[] = "nombre = ?";
            $params[] = $datos['nombre'];
        }
        
        if (isset($datos['descripcion'])) {
            $sets[] = "descripcion = ?";
            $params[] = $datos['descripcion'];
        }
        
        if (isset($datos['cliente_id'])) {
            $sets[] = "cliente_id = ?";
            $params[] = $datos['cliente_id'];
        }
        
        if (isset($datos['vendedor_id'])) {
            $sets[] = "vendedor_id = ?";
            $params[] = $datos['vendedor_id'] ?: null;
        }
        
        if (isset($datos['fecha_programada'])) {
            $sets[] = "fecha_programada = ?";
            $params[] = $datos['fecha_programada'];
        }
        
        if (isset($datos['hora_visita'])) {
            $sets[] = "hora_visita = ?";
            $params[] = $datos['hora_visita'];
        }
        
        if (isset($datos['ciudad'])) {
            $sets[] = "ciudad = ?";
            $params[] = $datos['ciudad'];
        }
        
        $sets[] = "fecha_actualizacion = GETDATE()";
        
        if (empty($sets)) {
            return true; // No hay nada que actualizar
        }
        
        $sql .= implode(", ", $sets);
        $sql .= " WHERE id = ?";
        $params[] = $ruta_id;
        
        // Depuración: Imprimir la consulta y los parámetros
        error_log("SQL Update: " . $sql);
        error_log("Params Update: " . print_r($params, true));
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt === false) {
            error_log("Error al actualizar ruta: " . print_r(sqlsrv_errors(), true));
            return false;
        }
        
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error al actualizar ruta: " . $e->getMessage());
        return false;
    }
}

/**
* Elimina una ruta
* 
* @param int $ruta_id ID de la ruta
* @return bool True si se eliminó correctamente, false en caso contrario
*/
function eliminarRuta($ruta_id) {
    global $conn;

    try {
        // Iniciar transacción
        if (sqlsrv_begin_transaction($conn) === false) {
            error_log("Error al iniciar transacción: " . print_r(sqlsrv_errors(), true));
            return false;
        }
        
        // Primero eliminar los clientes asociados a la ruta
        $sql_clientes = "DELETE FROM clientes_ruta WHERE ruta_id = ?";
        $params_clientes = array($ruta_id);
        $stmt_clientes = sqlsrv_query($conn, $sql_clientes, $params_clientes);
        
        if ($stmt_clientes === false) {
            sqlsrv_rollback($conn);
            error_log("Error al eliminar clientes de la ruta: " . print_r(sqlsrv_errors(), true));
            return false;
        }
        
        sqlsrv_free_stmt($stmt_clientes);

        // Luego eliminar la ruta
        $sql = "DELETE FROM rutas1 WHERE id = ?";
        $params = array($ruta_id);
        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt === false) {
            sqlsrv_rollback($conn);
            error_log("Error al eliminar ruta: " . print_r(sqlsrv_errors(), true));
            return false;
        }
        
        sqlsrv_free_stmt($stmt);
        
        // Confirmar transacción
        if (sqlsrv_commit($conn) === false) {
            sqlsrv_rollback($conn);
            error_log("Error al confirmar transacción: " . print_r(sqlsrv_errors(), true));
            return false;
        }

        return true;

    } catch (Exception $e) {
        if (sqlsrv_begin_transaction($conn) !== false) {
            sqlsrv_rollback($conn);
        }
        error_log("Error al eliminar ruta: " . $e->getMessage());
        return false;
    }
}

/**
* Obtiene información de un cliente desde SAP
* 
* @param string $cliente_id ID del cliente en SAP (CardCode)
* @return array|null Información del cliente o null si no existe
*/
function obtenerClienteSAP($cliente_id) {
    // Conexión a SAP
    $serverName = "HERCULES";
    $connectionInfo = array("Database" => "RBOSKY3", "UID" => "sa", "PWD" => "Sky2022*!");
    $conn_sap = sqlsrv_connect($serverName, $connectionInfo);
    
    if (!$conn_sap) {
        error_log("Error al conectar a SAP: " . print_r(sqlsrv_errors(), true));
        return null;
    }
    
    try {
        $sql = "SELECT T0.[CardCode], T0.[CardName], T1.[SlpCode], T1.[SlpName], 
                T0.[City], T0.[Phone], T0.[Territory], T0.[GroupCode]
                FROM OCRD T0  
                INNER JOIN OSLP T1 ON T0.[SlpCode] = T1.[SlpCode] 
                WHERE T0.[CardCode] = ? AND T0.[validFor] = 'Y'";
        
        $params = array($cliente_id);
        $stmt = sqlsrv_query($conn_sap, $sql, $params);
        
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            sqlsrv_free_stmt($stmt);
            sqlsrv_close($conn_sap);
            return $row;
        }
        
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
    } catch (Exception $e) {
        error_log("Error al obtener cliente de SAP: " . $e->getMessage());
    }
    
    if (isset($conn_sap) && $conn_sap) {
        sqlsrv_close($conn_sap);
    }
    
    return null;
}

/**
* Obtiene información de un vendedor desde SAP
* 
* @param int $vendedor_id ID del vendedor en SAP (SlpCode)
* @return array|null Información del vendedor o null si no existe
*/
function obtenerVendedorSAP($vendedor_id) {
    // Conexión a SAP
    $serverName = "HERCULES";
    $connectionInfo = array("Database" => "RBOSKY3", "UID" => "sa", "PWD" => "Sky2022*!");
    $conn_sap = sqlsrv_connect($serverName, $connectionInfo);
    
    if (!$conn_sap) {
        error_log("Error al conectar a SAP: " . print_r(sqlsrv_errors(), true));
        return null;
    }
    
    try {
        $sql = "SELECT [SlpCode], [SlpName] FROM OSLP WHERE [SlpCode] = ?";
        
        $params = array($vendedor_id);
        $stmt = sqlsrv_query($conn_sap, $sql, $params);
        
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            sqlsrv_free_stmt($stmt);
            sqlsrv_close($conn_sap);
            return $row;
        }
        
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
    } catch (Exception $e) {
        error_log("Error al obtener vendedor de SAP: " . $e->getMessage());
    }
    
    if (isset($conn_sap) && $conn_sap) {
        sqlsrv_close($conn_sap);
    }
    
    return null;
}

/**
* Exporta las rutas a Excel
*
* @param array $filtros Filtros para la exportación
* @return string|bool Ruta del archivo generado o false en caso de error
*/
function exportarRutasExcel($filtros) {
    global $conn;
    
    try {
        // Verificar si existe la carpeta de exportaciones
        $export_dir = 'exports';
        if (!file_exists($export_dir)) {
            mkdir($export_dir, 0777, true);
        }
        
        // Generar nombre de archivo
        $filename = 'rutas_export_' . date('Ymd_His') . '.csv';
        $filepath = $export_dir . '/' . $filename;
        
        // Abrir archivo para escritura
        $file = fopen($filepath, 'w');
        
        // Escribir encabezados
        fputcsv($file, array(
            'ID', 'Nombre', 'Cliente', 'Ciudad', 'Vendedor', 'Fecha Programada', 'Hora Visita', 'Estado'
        ));
        
        // Construir consulta SQL
        $sql = "SELECT 
                    r.id, 
                    r.nombre, 
                    r.cliente_id,
                    r.vendedor_id,
                    r.fecha_programada,
                    r.hora_visita,
                    r.estado,
                    r.ciudad
                FROM rutas1 r
                WHERE r.usuario_id = ?";
        
        $params = array($filtros['usuario_id']);
        
        // Get user's NIT
        $nit = obtenerNitUsuario($filtros['usuario_id'], $conn);
        
        // Add NIT filter if available
        if (!empty($nit)) {
            $sql .= " AND r.vendedor_id = ?";
            $params[] = $nit;
        }
        
        // Aplicar filtros
        if (!empty($filtros['fecha_desde']) && !empty($filtros['fecha_hasta'])) {
            $sql .= " AND r.fecha_programada BETWEEN ? AND ?";
            $params[] = $filtros['fecha_desde'];
            $params[] = $filtros['fecha_hasta'];
        }
        
        if (!empty($filtros['vendedor_id'])) {
            $sql .= " AND r.vendedor_id = ?";
            $params[] = $filtros['vendedor_id'];
        }
        
        $sql .= " ORDER BY r.fecha_programada ASC, r.hora_visita ASC";
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt === false) {
            error_log("Error al ejecutar consulta para exportación: " . print_r(sqlsrv_errors(), true));
            return false;
        }
        
        // Procesar resultados
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Formatear fecha programada
            $fecha_programada = 'N/A';
            if (isset($row['fecha_programada']) && $row['fecha_programada']) {
                $fecha = $row['fecha_programada'];
                $fecha_programada = $fecha->format('d/m/Y');
            }
            
            // Formatear hora de visita
            $hora_visita = 'N/A';
            if (isset($row['hora_visita']) && $row['hora_visita']) {
                $hora = $row['hora_visita'];
                $hora_visita = $hora->format('H:i');
            }
            
            // Obtener información del cliente
            $cliente_nombre = 'Sin cliente';
            $cliente_ciudad = $row['ciudad'] ?? '';
            if (!empty($row['cliente_id'])) {
                $cliente_info = obtenerClienteSAP($row['cliente_id']);
                if ($cliente_info) {
                    $cliente_nombre = $cliente_info['CardName'];
                    if (empty($cliente_ciudad)) {
                        $cliente_ciudad = $cliente_info['City'] ?? '';
                    }
                }
            }
            
            // Obtener información del vendedor
            $vendedor_nombre = 'Sin vendedor';
            if (!empty($row['vendedor_id'])) {
                $vendedor_info = obtenerVendedorSAP($row['vendedor_id']);
                if ($vendedor_info) {
                    $vendedor_nombre = $vendedor_info['SlpName'];
                }
            }
            
            // Escribir fila
            fputcsv($file, array(
                $row['id'],
                $row['nombre'],
                $cliente_nombre,
                $cliente_ciudad,
                $vendedor_nombre,
                $fecha_programada,
                $hora_visita,
                $row['estado']
            ));
        }
        
        sqlsrv_free_stmt($stmt);
        fclose($file);
        
        return $filepath;
        
    } catch (Exception $e) {
        error_log("Error al exportar rutas a Excel: " . $e->getMessage());
        return false;
    }
}

/**
* Script para crear la tabla rutas y otras tablas necesarias
*/
function crearTablaRutas() {
    global $conn;
    
    // Crear la tabla usuarios_ruta si no existe
    $sql_usuarios = "IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='usuarios_ruta' AND xtype='U')
            BEGIN
                CREATE TABLE usuarios_ruta (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    nombre VARCHAR(100) NOT NULL,
                    usuario VARCHAR(50) NOT NULL UNIQUE,
                    password VARCHAR(255) NOT NULL,
                    es_admin BIT DEFAULT 0,
                    vendedor_id INT NULL,
                    nit VARCHAR(20) NULL,
                    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                PRINT 'Tabla usuarios_ruta creada correctamente.';
            END
            ELSE
            BEGIN
                // Verificar si la columna es_admin ya existe
                IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('usuarios_ruta') AND name = 'es_admin')
                BEGIN
                    ALTER TABLE usuarios_ruta ADD es_admin BIT DEFAULT 0;
                    PRINT 'Columna es_admin agregada a la tabla usuarios_ruta.';
                END
                
                // Verificar si la columna vendedor_id ya existe
                IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('usuarios_ruta') AND name = 'vendedor_id')
                BEGIN
                    ALTER TABLE usuarios_ruta ADD vendedor_id INT NULL;
                    PRINT 'Columna vendedor_id agregada a la tabla usuarios_ruta.';
                END
                
                // Verificar si la columna nit ya existe
                IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('usuarios_ruta') AND name = 'nit')
                BEGIN
                    ALTER TABLE usuarios_ruta ADD nit VARCHAR(20) NULL;
                    PRINT 'Columna nit agregada a la tabla usuarios_ruta.';
                END
                
                PRINT 'La tabla usuarios_ruta ya existe y ha sido actualizada.';
            END";

    $result_usuarios = sqlsrv_query($conn, $sql_usuarios);

    // Crear la tabla rutas si no existe (con campos para SAP)
    $sql_rutas = "IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='rutas1' AND xtype='U')
            BEGIN
                CREATE TABLE rutas1 (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    nombre VARCHAR(100) NOT NULL,
                    descripcion TEXT NULL,
                    estado VARCHAR(20) NOT NULL DEFAULT 'activa',
                    distancia FLOAT NOT NULL DEFAULT 0,
                    progreso INT NOT NULL DEFAULT 0,
                    usuario_id INT NOT NULL,
                    cliente_id VARCHAR(20) NULL,
                    vendedor_id INT NULL,
                    fecha_programada DATE NULL,
                    hora_visita TIME NULL,
                    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
                    fecha_actualizacion DATETIME NULL,
                    ciudad VARCHAR(100) NULL,
                    FOREIGN KEY (usuario_id) REFERENCES usuarios_ruta(id)
                );
                PRINT 'Tabla rutas creada correctamente.';
            END
            ELSE
            BEGIN
                // Verificar si la columna cliente_id ya existe en la tabla rutas
                IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'cliente_id')
                BEGIN
                    // Agregar columna cliente_id a la tabla rutas
                    ALTER TABLE rutas1 ADD cliente_id VARCHAR(20) NULL;
                    PRINT 'Columna cliente_id agregada a la tabla rutas.';
                END
                ELSE
                BEGIN
                    PRINT 'La columna cliente_id ya existe en la tabla rutas.';
                END

                // Verificar si la columna vendedor_id ya existe en la tabla rutas
                IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'vendedor_id')
                BEGIN
                    // Agregar columna vendedor_id a la tabla rutas
                    ALTER TABLE rutas1 ADD vendedor_id INT NULL;
                    PRINT 'Columna vendedor_id agregada a la tabla rutas.';
                END
                ELSE
                BEGIN
                    PRINT 'La columna vendedor_id ya existe en la tabla rutas.';
                END
                
                // Verificar si la columna fecha_programada ya existe en la tabla rutas
                IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'fecha_programada')
                BEGIN
                    // Agregar columna fecha_programada a la tabla rutas
                    ALTER TABLE rutas1 ADD fecha_programada DATE NULL;
                    PRINT 'Columna fecha_programada agregada a la tabla rutas.';
                END
                ELSE
                BEGIN
                    PRINT 'La columna fecha_programada ya existe en la tabla rutas.';
                END
                
                // Verificar si la columna hora_visita ya existe en la tabla rutas
                IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'hora_visita')
                BEGIN
                    // Agregar columna hora_visita a la tabla rutas
                    ALTER TABLE rutas1 ADD hora_visita TIME NULL;
                    PRINT 'Columna hora_visita agregada a la tabla rutas.';
                END
                ELSE
                BEGIN
                    PRINT 'La columna hora_visita ya existe en la tabla rutas.';
                END
                
                // Verificar si la columna ciudad ya existe en la tabla rutas
                IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'ciudad')
                BEGIN
                    // Agregar columna ciudad a la tabla rutas
                    ALTER TABLE rutas1 ADD ciudad VARCHAR(100) NULL;
                    PRINT 'Columna ciudad agregada a la tabla rutas.';
                END
                ELSE
                BEGIN
                    PRINT 'La columna ciudad ya existe en la tabla rutas.';
                END

                PRINT 'La tabla rutas ya existe y ha sido actualizada.';
            END";

    $result_rutas = sqlsrv_query($conn, $sql_rutas);

    // Crear la tabla clientes_ruta si no existe
    $sql_clientes = "IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='clientes_ruta' AND xtype='U')
            BEGIN
                CREATE TABLE clientes_ruta (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    nombre VARCHAR(100) NOT NULL,
                    direccion VARCHAR(255) NOT NULL,
                    telefono VARCHAR(20) NULL,
                    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                    ruta_id INT NOT NULL,
                    usuario_id INT NOT NULL,
                    cliente_sap_id VARCHAR(20) NULL,
                    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
                    fecha_actualizacion DATETIME NULL,
                    FOREIGN KEY (ruta_id) REFERENCES rutas1(id),
                    FOREIGN KEY (usuario_id) REFERENCES usuarios_ruta(id)
                );
                PRINT 'Tabla clientes_ruta creada correctamente.';
            END
            ELSE
            BEGIN
                // Verificar si la columna cliente_sap_id ya existe
                IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('clientes_ruta') AND name = 'cliente_sap_id')
                BEGIN
                    ALTER TABLE clientes_ruta ADD cliente_sap_id VARCHAR(20) NULL;
                    PRINT 'Columna cliente_sap_id agregada a la tabla clientes_ruta.';
                END
                ELSE
                BEGIN
                    PRINT 'La columna cliente_sap_id ya existe en la tabla clientes_ruta.';
                END
                
                PRINT 'La tabla clientes_ruta ya existe y ha sido actualizada.';
            END";

    $result_clientes = sqlsrv_query($conn, $sql_clientes);
    
    return ($result_usuarios !== false && $result_rutas !== false && $result_clientes !== false);
}

/**
* Obtiene el NIT (SlpCode) del usuario
* 
* @param int $usuario_id ID del usuario
* @param resource $conn Conexión a la base de datos
* @return string|null NIT del usuario o null si no existe
*/
function obtenerNitUsuario($usuario_id, $conn) {
    $sql = "SELECT nit FROM usuarios_ruta WHERE id = ?";
    $params = array($usuario_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        return $row['nit'];
    }
    return null;
}

/**
* Obtiene clientes por NIT (SlpCode) del usuario
* 
* @param string $nit NIT (SlpCode) del usuario
* @return array Clientes asociados al NIT
*/
function obtenerClientesPorNit($nit) {
    // Conexión a SAP
    $serverName = "HERCULES";
    $connectionInfo = array("Database" => "RBOSKY3", "UID" => "sa", "PWD" => "Sky2022*!");
    $conn_sap = sqlsrv_connect($serverName, $connectionInfo);
    
    $clientes = [];
    
    if (!$conn_sap) {
        error_log("Error al conectar a SAP: " . print_r(sqlsrv_errors(), true));
        return $clientes;
    }
    
    try {
        $sql = "SELECT distinct T0.[CardCode], T0.[CardFName], T0.[CardName], T1.[SlpCode], T1.[SlpName],
                T0.[City] as Ciudad, T0.[GroupCode] as Canal, T0.[Territory] as Zona
                FROM OCRD T0
                INNER JOIN OSLP T1 ON T0.[SlpCode] = T1.[SlpCode]
                WHERE T0.[validFor] = 'Y' AND T1.[SlpCode] = ?";
        
        $params = array($nit);
        $stmt = sqlsrv_query($conn_sap, $sql, $params);
        
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $clientes[$row['CardCode']] = [
                    'nombre' => $row['CardFName'],
                    'ciudad' => $row['Ciudad'] ?? '',
                    'zona' => $row['Zona'] ?? '',
                    'canal' => $row['Canal'] ?? '',
                    'vendedor' => $row['SlpName'],
                    'vendedor_id' => $row['SlpCode']
                ];
            }
            
            sqlsrv_free_stmt($stmt);
        }
    } catch (Exception $e) {
        error_log("Error al obtener clientes por NIT: " . $e->getMessage());
    }
    
    if (isset($conn_sap) && $conn_sap) {
        sqlsrv_close($conn_sap);
    }
    
    return $clientes;
}

/**
* Obtiene información detallada de una ruta
* 
* @param int $ruta_id ID de la ruta
* @return array|null Información detallada de la ruta o null si no existe
*/
function obtenerRutaDetalle($ruta_id) {
    global $conn;
    
    try {
        $sql = "SELECT 
                    r.id, 
                    r.nombre, 
                    r.estado,
                    r.distancia,
                    r.progreso,
                    r.fecha_creacion,
                    r.fecha_programada,
                    r.hora_visita,
                    r.cliente_id,
                    r.vendedor_id,
                    r.ciudad
                FROM rutas1 r
                WHERE r.id = ?";
        
        $params = array($ruta_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Formatear la fecha
            if (isset($row['fecha_creacion']) && $row['fecha_creacion']) {
                $fecha = $row['fecha_creacion'];
                $row['fecha_creacion'] = $fecha->format('d/m/Y');
            } else {
                $row['fecha_creacion'] = 'N/A';
            }
            
            // Formatear la fecha programada
            if (isset($row['fecha_programada']) && $row['fecha_programada']) {
                $fecha = $row['fecha_programada'];
                $row['fecha_programada'] = $fecha->format('d/m/Y');
                $row['dia_semana'] = $fecha->format('l'); // Día de la semana en inglés
            } else {
                $row['fecha_programada'] = 'N/A';
                $row['dia_semana'] = 'N/A';
            }
            
            // Formatear la hora de visita
            if (isset($row['hora_visita']) && $row['hora_visita']) {
                $hora = $row['hora_visita'];
                $row['hora_visita'] = $hora->format('H:i');
            } else {
                $row['hora_visita'] = '';
            }
            
            // Obtener información del cliente de SAP si existe
            if (!empty($row['cliente_id'])) {
                $cliente_info = obtenerClienteSAP($row['cliente_id']);
                if ($cliente_info) {
                    $row['cliente_nombre'] = $cliente_info['CardName'];
                    $row['cliente_ciudad'] = $row['ciudad'] ?? $cliente_info['City'] ?? '';
                } else {
                    $row['cliente_nombre'] = 'Cliente no encontrado';
                    $row['cliente_ciudad'] = $row['ciudad'] ?? '';
                }
            } else {
                $row['cliente_nombre'] = 'Sin cliente asignado';
                $row['cliente_ciudad'] = $row['ciudad'] ?? '';
            }
            
            // Obtener información del vendedor de SAP si existe
            if (!empty($row['vendedor_id'])) {
                $vendedor_info = obtenerVendedorSAP($row['vendedor_id']);
                if ($vendedor_info) {
                    $row['vendedor_nombre'] = $vendedor_info['SlpName'];
                } else {
                    $row['vendedor_nombre'] = 'Vendedor no encontrado';
                }
            } else {
                $row['vendedor_nombre'] = 'Sin vendedor asignado';
            }
            
            sqlsrv_free_stmt($stmt);
            return $row;
        }
        
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
    } catch (Exception $e) {
        error_log("Error al obtener detalle de ruta: " . $e->getMessage());
    }
    
    return null;
}

// Asegurarse de que las tablas existan
crearTablaRutas();

// Verificar si se proporcionó una fecha de inicio
$fecha_inicio = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d', strtotime('monday this week'));
$vendedor_id = isset($_GET['vendedor']) ? $_GET['vendedor'] : null;
$zona = isset($_GET['zona']) ? $_GET['zona'] : '';
$canal = isset($_GET['canal']) ? $_GET['canal'] : '';
$busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$semana = isset($_GET['semana']) ? intval($_GET['semana']) : date('W');
$mes = isset($_GET['mes']) ? intval($_GET['mes']) : date('n');
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : date('Y');

// Calcular fechas de la semana
$fecha_lunes = new DateTime($fecha_inicio);
$fecha_lunes->modify('monday this week'); // Asegurar que sea lunes
$fecha_martes = clone $fecha_lunes;
$fecha_martes->modify('+1 day');
$fecha_miercoles = clone $fecha_lunes;
$fecha_miercoles->modify('+2 days');
$fecha_jueves = clone $fecha_lunes;
$fecha_jueves->modify('+3 days');
$fecha_viernes = clone $fecha_lunes;
$fecha_viernes->modify('+4 days');
$fecha_sabado = clone $fecha_lunes;
$fecha_sabado->modify('+5 days');

// Formatear fechas para mostrar
$fecha_lunes_formato = $fecha_lunes->format('d/m/Y');
$fecha_martes_formato = $fecha_martes->format('d/m/Y');
$fecha_miercoles_formato = $fecha_miercoles->format('d/m/Y');
$fecha_jueves_formato = $fecha_jueves->format('d/m/Y');
$fecha_viernes_formato = $fecha_viernes->format('d/m/Y');
$fecha_sabado_formato = $fecha_sabado->format('d/m/Y');

// Calcular semana anterior y siguiente
$semana_anterior = clone $fecha_lunes;
$semana_anterior->modify('-7 days');
$semana_siguiente = clone $fecha_lunes;
$semana_siguiente->modify('+7 days');

// Generar datos para el selector de semanas (tipo calendario)
$semanas_mes = [];
$fecha_actual = new DateTime();
$fecha_actual->setDate($anio, $mes, 1);
$fecha_actual->modify('monday this week');

// Generar semanas para el mes actual y los próximos 2 meses
for ($i = 0; $i < 12; $i++) {
    $semana_num = $fecha_actual->format('W');
    $mes_num = $fecha_actual->format('n');
    $anio_num = $fecha_actual->format('Y');
    $mes_nombre = $fecha_actual->format('F');
    
    // Traducir nombre del mes
    $meses_es = [
        'January' => 'Enero',
        'February' => 'Febrero',
        'March' => 'Marzo',
        'April' => 'Abril',
        'May' => 'Mayo',
        'June' => 'Junio',
        'July' => 'Julio',
        'August' => 'Agosto',
        'September' => 'Septiembre',
        'October' => 'Octubre',
        'November' => 'Noviembre',
        'December' => 'Diciembre'
    ];
    
    $mes_nombre_es = $meses_es[$mes_nombre];
    
    // Calcular fecha de inicio y fin de la semana
    $inicio_semana = clone $fecha_actual;
    $fin_semana = clone $fecha_actual;
    $fin_semana->modify('+6 days');
    
    $semanas_mes[] = [
        'fecha' => $fecha_actual->format('Y-m-d'),
        'semana' => $semana_num,
        'mes' => $mes_num,
        'anio' => $anio_num,
        'texto' => "Semana $semana_num: " . $inicio_semana->format('d') . " - " . $fin_semana->format('d') . " de $mes_nombre_es",
        'inicio' => $inicio_semana->format('d/m/Y'),
        'fin' => $fin_semana->format('d/m/Y')
    ];
    
    // Avanzar a la siguiente semana
    $fecha_actual->modify('+7 days');
}

// Obtener NIT del usuario actual
$usuario_id = $_SESSION['usuario_id'];
$nit = obtenerNitUsuario($usuario_id, $conn);

// Obtener clientes filtrados por NIT
$clientes = [];
if ($nit !== null && $nit !== '') {
    $clientes = obtenerClientesPorNit($nit);
}

// Extraer zonas y canales de los clientes
$zonas = [];
$canales = [];
$vendedores = [];
$clientes_vendedores = [];

foreach ($clientes as $id => $cliente) {
    // Guardar la relación cliente-vendedor
    $clientes_vendedores[$id] = $cliente['vendedor_id'];
    
    // Agregar vendedor (evitando duplicados)
    if (!isset($vendedores[$cliente['vendedor_id']])) {
        $vendedores[$cliente['vendedor_id']] = [
            'SlpCode' => $cliente['vendedor_id'],
            'SlpName' => $cliente['vendedor']
        ];
    }
    
    // Agregar zona (evitando duplicados)
    if (!empty($cliente['zona']) && !in_array($cliente['zona'], $zonas)) {
        $zonas[] = $cliente['zona'];
    }
    
    // Agregar canal (evitando duplicados)
    if (!empty($cliente['canal']) && !in_array($cliente['canal'], $canales)) {
        $canales[] = $cliente['canal'];
    }
}

// Ordenar zonas y canales
sort($zonas);
sort($canales);

// Convertir vendedores a formato de array indexado
$vendedores_array = [];
foreach ($vendedores as $vendedor) {
    $vendedores_array[] = $vendedor;
}
$vendedores = $vendedores_array;

// Obtener rutas para la semana
$rutas_semana = obtenerRutasSemana($fecha_lunes->format('Y-m-d'), $vendedor_id, $_SESSION['usuario_id'], $busqueda, $zona, $canal);

// Mapeo de días en inglés a español
$dias_semana = array(
    'Monday' => 'lunes',
    'Tuesday' => 'martes',
    'Wednesday' => 'miercoles',
    'Thursday' => 'jueves',
    'Friday' => 'viernes',
    'Saturday' => 'sabado'
);

// Agrupar rutas por día
$rutas_por_dia = array(
    'lunes' => array(),
    'martes' => array(),
    'miercoles' => array(),
    'jueves' => array(),
    'viernes' => array(),
    'sabado' => array()
);

// Agrupar rutas por día
foreach ($rutas_semana as $ruta) {
    if (isset($ruta['dia_semana']) && isset($dias_semana[$ruta['dia_semana']])) {
        $dia = $dias_semana[$ruta['dia_semana']];
        $rutas_por_dia[$dia][] = $ruta;
    }
}

// Estado de colapso para cada día (inicialmente todos colapsados)
$dias_colapsados = isset($_COOKIE['dias_colapsados']) ? json_decode($_COOKIE['dias_colapsados'], true) : [
    'lunes' => true,
    'martes' => true,
    'miercoles' => true,
    'jueves' => true,
    'viernes' => true,
    'sabado' => true
];

// Procesar el formulario si se envió para crear nueva ruta
$mensaje = '';
$error = '';
$ruta_creada = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear_ruta') {
    // Validar datos
    $nombre = trim($_POST['nombre']);
    $cliente_id = isset($_POST['cliente_id']) ? trim($_POST['cliente_id']) : '';
    $vendedor_id = isset($_POST['vendedor_id']) && !empty($_POST['vendedor_id']) ? intval($_POST['vendedor_id']) : null;
    $dia_semana = isset($_POST['dia_semana']) ? trim($_POST['dia_semana']) : '';
    $hora_visita = isset($_POST['hora_visita']) ? trim($_POST['hora_visita']) : '';
    
    // Si no se proporcionó un vendedor_id, usar el asociado al cliente
    if (empty($vendedor_id) && !empty($cliente_id) && isset($clientes_vendedores[$cliente_id])) {
        $vendedor_id = $clientes_vendedores[$cliente_id];
    }
    
    // Obtener la ciudad del cliente
    $ciudad = '';
    if (!empty($cliente_id)) {
        $cliente_info = obtenerClienteSAP($cliente_id);
        if ($cliente_info && isset($cliente_info['City'])) {
            $ciudad = $cliente_info['City'];
        }
    }
    
    // Calcular fecha programada basada en el día de la semana seleccionado
    $fecha_programada = null;
    switch ($dia_semana) {
        case 'lunes':
            $fecha_programada = $fecha_lunes->format('Y-m-d');
            break;
        case 'martes':
            $fecha_programada = $fecha_martes->format('Y-m-d');
            break;
        case 'miercoles':
            $fecha_programada = $fecha_miercoles->format('Y-m-d');
            break;
        case 'jueves':
            $fecha_programada = $fecha_jueves->format('Y-m-d');
            break;
        case 'viernes':
            $fecha_programada = $fecha_viernes->format('Y-m-d');
            break;
        case 'sabado':
            $fecha_programada = $fecha_sabado->format('Y-m-d');
            break;
    }
    
    // Validaciones
    if (empty($nombre)) {
        $error = 'El nombre de la ruta es obligatorio';
    } elseif (empty($cliente_id)) {
        $error = 'Debe seleccionar un cliente';
    } elseif (empty($dia_semana)) {
        $error = 'Debe seleccionar un día de la semana';
    } else {
        // Crear la ruta
        $datos = [
            'nombre' => $nombre,
            'descripcion' => '',
            'distancia' => 1.0,
            'usuario_id' => $_SESSION['usuario_id'],
            'cliente_id' => $cliente_id,
            'vendedor_id' => $vendedor_id,
            'fecha_programada' => $fecha_programada,
            'hora_visita' => $hora_visita,
            'ciudad' => $ciudad
        ];
        

        $ruta_id = crearRuta($datos);

// Validar explícitamente que $ruta_id sea un valor numérico positivo
if ($ruta_id && is_numeric($ruta_id) && $ruta_id > 0) {
    $mensaje = 'Ruta creada exitosamente para el ' . ucfirst($dia_semana);
    
    // Asegurarse de que se guarde el mensaje de éxito
    $_SESSION['mensaje'] = $mensaje;
    $_SESSION['tipo_mensaje'] = 'success';
    
    // Debug para verificar
    error_log("Ruta creada exitosamente con ID: $ruta_id");
    
    // Obtener la ruta recién creada para mostrarla
    $ruta_creada = obtenerRutaDetalle($ruta_id);
    
    // Recargar las rutas para mostrar la nueva
    $rutas_semana = obtenerRutasSemana($fecha_lunes->format('Y-m-d'), $vendedor_id, $_SESSION['usuario_id'], $busqueda, $zona, $canal);
    
    // Reagrupar rutas por día
    $rutas_por_dia = array(
        'lunes' => array(),
        'martes' => array(),
        'miercoles' => array(),
        'jueves' => array(),
        'viernes' => array(),
        'sabado' => array()
    );
    
    foreach ($rutas_semana as $ruta) {
        if (isset($ruta['dia_semana']) && isset($dias_semana[$ruta['dia_semana']])) {
            $dia = $dias_semana[$ruta['dia_semana']];
            $rutas_por_dia[$dia][] = $ruta;
        }
    }
} else {
    $error = 'Error al crear la ruta. Inténtalo de nuevo.';
    $_SESSION['mensaje'] = $error;
    $_SESSION['tipo_mensaje'] = 'error';
    error_log("Error al crear ruta. ruta_id devuelto: " . var_export($ruta_id, true));
}
    }
}

// Procesar acciones AJAX
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'guardar_ruta':
            $ruta_id = isset($_POST['ruta_id']) ? intval($_POST['ruta_id']) : 0;
            $cliente_id = isset($_POST['cliente_id']) ? $_POST['cliente_id'] : '';
            $nombre = isset($_POST['nombre']) ? $_POST['nombre'] : '';
            $dia = isset($_POST['dia']) ? $_POST['dia'] : '';
            $hora_visita = isset($_POST['hora_visita']) ? $_POST['hora_visita'] : '';
            $vendedor_id = isset($_POST['vendedor_id']) && !empty($_POST['vendedor_id']) ? intval($_POST['vendedor_id']) : null;
            $ciudad = isset($_POST['ciudad']) ? $_POST['ciudad'] : '';
            
            // Si no se proporcionó un vendedor_id, usar el asociado al cliente
            if (empty($vendedor_id) && !empty($cliente_id) && isset($clientes_vendedores[$cliente_id])) {
                $vendedor_id = $clientes_vendedores[$cliente_id];
            }
            
            // Obtener la ciudad del cliente si no se proporcionó
            if (empty($ciudad) && !empty($cliente_id)) {
                $cliente_info = obtenerClienteSAP($cliente_id);
                if ($cliente_info && isset($cliente_info['City'])) {
                    $ciudad = $cliente_info['City'];
                }
            }
            
            // Calcular fecha programada basada en el día
            $fecha_programada = null;
            switch ($dia) {
                case 'lunes':
                    $fecha_programada = $fecha_lunes->format('Y-m-d');
                    break;
                case 'martes':
                    $fecha_programada = $fecha_martes->format('Y-m-d');
                    break;
                case 'miercoles':
                    $fecha_programada = $fecha_miercoles->format('Y-m-d');
                    break;
                case 'jueves':
                    $fecha_programada = $fecha_jueves->format('Y-m-d');
                    break;
                case 'viernes':
                    $fecha_programada = $fecha_viernes->format('Y-m-d');
                    break;
                case 'sabado':
                    $fecha_programada = $fecha_sabado->format('Y-m-d');
                    break;
            }
            
            if ($ruta_id > 0) {
                // Actualizar ruta existente
                $result = actualizarRuta($ruta_id, [
                    'nombre' => $nombre,
                    'cliente_id' => $cliente_id,
                    'vendedor_id' => $vendedor_id,
                    'fecha_programada' => $fecha_programada,
                    'hora_visita' => $hora_visita,
                    'ciudad' => $ciudad,
                    'usuario_id' => $_SESSION['usuario_id'] // Añadir usuario_id para obtener NIT si es necesario
                ]);
                
                if ($result) {
                    $ruta_actualizada = obtenerRutaDetalle($ruta_id);
                    echo json_encode([
                        'success' => true, 
                        'message' => '¡Ruta actualizada correctamente!',
                        'ruta' => $ruta_actualizada
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error al actualizar la ruta']);
                }
            } else {
                // Crear nueva ruta
                $datos = [
                    'nombre' => $nombre,
                    'descripcion' => 'Ruta creada desde la programación semanal',
                    'distancia' => 1.0,
                    'usuario_id' => $_SESSION['usuario_id'],
                    'cliente_id' => $cliente_id,
                    'vendedor_id' => $vendedor_id,
                    'fecha_programada' => $fecha_programada,
                    'hora_visita' => $hora_visita,
                    'ciudad' => $ciudad
                ];
                
                $nuevo_id = crearRuta($datos);
                
                if ($nuevo_id > 0) {
                    $ruta_nueva = obtenerRutaDetalle($nuevo_id);
                    echo json_encode([
                        'success' => true, 
                        'message' => '¡Ruta creada correctamente!',
                        'ruta' => $ruta_nueva,
                        'ruta_id' => $nuevo_id
                    ]);
                } else {
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Error al crear la ruta'
                    ]);
                }
            }
            exit;
            
        case 'eliminar_ruta':
            $ruta_id = isset($_POST['ruta_id']) ? intval($_POST['ruta_id']) : 0;
            $result = eliminarRuta($ruta_id);
            
            echo json_encode(['success' => $result, 'message' => $result ? '¡Ruta eliminada correctamente!' : 'Error al eliminar la ruta']);
            exit;
            
        case 'obtener_documentos_cliente':
            $cliente_id = isset($_POST['cliente_id']) ? $_POST['cliente_id'] : '';
            $documentos = obtenerDocumentosClienteSAP($cliente_id);
            
            echo json_encode(['success' => true, 'documentos' => $documentos]);
            exit;
            
        case 'toggle_dia_colapso':
            $dia = isset($_POST['dia']) ? $_POST['dia'] : '';
            if (isset($dias_colapsados[$dia])) {
                $dias_colapsados[$dia] = !$dias_colapsados[$dia];
                setcookie('dias_colapsados', json_encode($dias_colapsados), time() + 86400 * 30, '/');
                echo json_encode(['success' => true, 'colapsado' => $dias_colapsados[$dia]]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Día no válido']);
            }
            exit;
            
        case 'obtener_info_cliente':
            $cliente_id = isset($_POST['cliente_id']) ? $_POST['cliente_id'] : '';
            
            // Conexión a SAP para obtener información actualizada
            $serverName = "HERCULES";
            $connectionInfo = array("Database" => "RBOSKY3", "UID" => "sa", "PWD" => "Sky2022*!");
            $conn_sap_temp = sqlsrv_connect($serverName, $connectionInfo);
            
            $info_cliente = ['success' => false];
            
            if ($conn_sap_temp) {
                $query = "SELECT T0.[CardCode], T0.[CardName], T0.[City] as Ciudad, 
                          T0.[GroupCode] as Canal, T0.[Territory] as Zona, T1.[SlpCode], T1.[SlpName]
                          FROM OCRD T0 
                          INNER JOIN OSLP T1 ON T0.[SlpCode] = T1.[SlpCode]
                          WHERE T0.[CardCode] = ? AND T0.[validFor] = 'Y'";
                
                $params = array($cliente_id);
                $stmt = sqlsrv_query($conn_sap_temp, $query, $params);
                
                if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $info_cliente = [
                        'success' => true,
                        'ciudad' => $row['Ciudad'] ?? '',
                        'nombre' => $row['CardName'] ?? '',
                        'zona' => $row['Zona'] ?? '',
                        'canal' => $row['Canal'] ?? '',
                        'vendedor_id' => $row['SlpCode'] ?? '',
                        'vendedor_nombre' => $row['SlpName'] ?? ''
                    ];
                }
                
                if ($stmt) {
                    sqlsrv_free_stmt($stmt);
                }
                sqlsrv_close($conn_sap_temp);
            }
            
            echo json_encode($info_cliente);
            exit;
            
        case 'exportar_excel':
            // Implementación de exportación a Excel
            $periodo = isset($_POST['periodo']) ? $_POST['periodo'] : 'semana';
            $fecha_desde = isset($_POST['fecha_desde']) ? $_POST['fecha_desde'] : $fecha_lunes->format('Y-m-d');
            $fecha_hasta = isset($_POST['fecha_hasta']) ? $_POST['fecha_hasta'] : $fecha_sabado->format('Y-m-d');
            $vendedor_filtro = isset($_POST['vendedor']) ? $_POST['vendedor'] : $vendedor_id;
            $zona_filtro = isset($_POST['zona']) ? $_POST['zona'] : $zona;
            $canal_filtro = isset($_POST['canal']) ? $_POST['canal'] : $canal;
            
            // Si el periodo es mes, ajustar fechas
            if ($periodo === 'mes') {
                $fecha_desde = date('Y-m-01');
                $fecha_hasta = date('Y-m-t');
            }
            
            $filtros = [
                'usuario_id' => $_SESSION['usuario_id'],
                'fecha_desde' => $fecha_desde,
                'fecha_hasta' => $fecha_hasta,
                'vendedor_id' => $vendedor_filtro,
                'zona' => $zona_filtro,
                'canal' => $canal_filtro
            ];
            
            $archivo = exportarRutasExcel($filtros);
            
            if ($archivo) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Archivo Excel generado correctamente',
                    'file_url' => $archivo
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error al generar el archivo Excel'
                ]);
            }
            exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php include 'header.php'; ?>
    <link rel="stylesheet" href="estilos-adicionales.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RUTERO MENSUAL</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="icon" href="65x45.png" >

<!-- En la sección de head, después de los enlaces CSS existentes, agregar los siguientes enlaces para Select2 -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.min.css">
    <style>
  
        
        /* Tema oscuro */
        [data-theme="oscuro"] {
            --primary-color: #3b82f6;
            --primary-light: #60a5fa;
            --primary-hover: #2563eb;
            --secondary-color: #1e293b;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --text-color: #e2e8f0;
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
                --primary-color: #3b82f6;
                --primary-light: #60a5fa;
                --primary-hover: #2563eb;
                --secondary-color: #1e293b;
                --success-color: #10b981;
                --danger-color: #ef4444;
                --text-color: #e2e8f0;
                --text-light: #94a3b8;
                --text-muted: #64748b;
                --bg-color: #0f172a;
                --card-bg: #1e293b;
                --border-color: #334155;
                --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.2);
                --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -1px rgba(0, 0, 0, 0.2);
            }
        }
        
 
        
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Estilos para la programación semanal */
        .programacion-container {
            background-color: var(--card-bg);
            border-radius: 10px;
            box-shadow: var(--shadow-md);
            overflow: hidden;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
        }
        
        .programacion-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
         
        }
        
        /* Estilos para el día de la semana */
        .dia-semana-header {
            background: linear-gradient(to right, var(--primary-color), var(--primary-light));
            color: white;
            font-weight: 600;
            text-align: center;
            padding: 12px 15px;
            border-radius: 6px 6px 0 0;
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
        }
        
        .dia-semana-header .toggle-collapse {
            cursor: pointer;
            padding: 5px;
            border-radius: 3px;
            transition: all 0.2s;
        }
        
        .dia-semana-header .toggle-collapse:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }
        
        /* Estilos para el selector de semanas tipo calendario */
        .calendario-semanas {
            display: none;
            position: absolute;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: var(--shadow-md);
            padding: 15px;
            z-index: 1000;
            width: 80%;
            max-width: 800px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .calendario-semanas.show {
            display: block;
        }
        
        .calendario-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
        }
        
        .semana-item {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--text-color);
        }
        
        .semana-item:hover {
            background-color: var(--secondary-color);
            border-color: var(--primary-color);
        }
        
        .semana-item.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        /* Estilos para celdas editables */
        .celda-editable {
            min-height: 40px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 4px;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
        }
        
        .celda-editable:hover {
            background-color: var(--secondary-color);
        }
        
        .celda-editable.vacia {
            color: var(--text-light);
            font-style: italic;
        }
        
        /* Estilos para datos existentes (no editables) */
        .celda-existente {
            background-color: var(--secondary-color);
            border-left: 3px solid var(--primary-color);
            padding: 8px 12px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        /* Estilos para el código automático */
        .codigo-auto {
            font-weight: 600;
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .codigo-auto:hover {
            text-decoration: underline;
        }
        
        /* Estilos para las tablas */
        .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
            color: var(--text-color);
        }
        
        .table thead th {
            background: var(--secondary-color);
            color: var(--text-color);
            font-weight: 600;
            text-align: center;
            padding: 12px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .table tbody tr:hover {
            background-color: rgba(59, 130, 246, 0.05);
        }
        
        .table td {
            padding: 10px 12px;
            vertical-align: middle;
            border-color: var(--border-color);
        }
        
        /* Estilos para los botones */
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        .btn-secondary {
            background-color: var(--secondary-color);
            border-color: var(--border-color);
            color: var(--text-color);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-secondary:hover {
            background-color: var(--border-color);
        }
        
        .btn-success {
            background-color: var(--success-color);
            border-color: var(--success-color);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-success:hover {
            background-color: #0d9488;
            border-color: #0d9488;
        }
        
        .btn-danger {
            background-color: var(--danger-color);
            border-color: var(--danger-color);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-danger:hover {
            background-color: #b91c1c;
            border-color: #b91c1c;
        }
        
        /* Estilos para los modales */
        .modal-header {
            background: linear-gradient(to right, var(--primary-color), var(--primary-light));
            color: white;
            border-bottom: 1px solid var(--border-color);
            padding: 15px 20px;
        }
        
        .modal-title {
            font-weight: 600;
        }
        
        .modal-body {
            padding: 20px;
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        
        .modal-footer {
            background-color: var(--secondary-color);
            border-top: 1px solid var(--border-color);
            padding: 15px 20px;
        }
        
        /* Estilos para los formularios */
        .form-label {
            font-weight: 500;
            color: var(--text-color);
        }
        
        .form-control, .form-select {
            border-radius: 4px;
            border: 1px solid var(--border-color);
            padding: 8px 12px;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
        }
        
        /* Estilos para alertas */
        .alert {
            border-radius: 6px;
            box-shadow: var(--shadow-sm);
        }
        
        /* Estilos para la navegación de semanas */
        .semana-nav {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .semana-nav .btn {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* Estilos para los filtros */
        .filtros-container {
            background-color: var(--secondary-color);
            border-radius: 6px;
            padding: 15px;
            margin-top: 15px;
            border: 1px solid var(--border-color);
        }
        
        /* Estilos para el encabezado principal */
        .header-main {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .header-main h1 {
            font-weight: 600;
            color: var(--text-color);
            margin: 0;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        /* Estilos para los iconos */
        .icon-text {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* Estilos para los badges */
        .badge {
            font-weight: 500;
            padding: 5px 8px;
            border-radius: 4px;
        }
        
        /* Estilos para el botón de agregar ruta al final de cada día */
        .btn-agregar-dia {
            margin-top: 10px;
            margin-bottom: 20px;
            width: 100%;
            text-align: center;
        }
        
        /* Estilos para la animación de nueva ruta */
        .nueva-ruta {
            animation: highlight 2s ease-in-out;
        }
        
        @keyframes highlight {
            0% { background-color: rgba(40, 167, 69, 0.2); }
            50% { background-color: rgba(40, 167, 69, 0.1); }
            100% { background-color: transparent; }
        }
        
        /* Estilos para el botón dashboard */
        .btn-dashboard {
            background-color: var(--primary-color);
            color: #ffffff;
            padding: 12px 24px;
            border: none;
            border-radius: 0.75rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.3s ease, transform 0.2s ease;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-dashboard:hover {
            background-color: var(--primary-hover);
            transform: translateY(-2px);
        }

        .btn-dashboard:active {
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.4);
        }

        .btn-dashboard i {
            font-size: 1.2rem;
        }

/* Estilos para el select con búsqueda */
.select-search-container {
    position: relative;
    width: 100%;
}

.select-search-input {
    width: 100%;
    padding: 8px;
    margin-bottom: 5px;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background-color: var(--card-bg);
    color: var(--text-color);
}

.select-options-container {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background-color: var(--card-bg);
    position: absolute;
    width: 100%;
    z-index: 1000;
}

.select-option {
    padding: 8px 12px;
    cursor: pointer;
}

.select-option:hover {
    background-color: var(--secondary-color);
}

.select-option.hidden {
    display: none;
}
    </style>
</head>
<body>

<div class="container">

    
    <div class="programacion-container">
        <div class="programacion-header">
            <h2 class="mb-3"></i>Registrar rutas</h2>
            
            <div class="d-flex justify-content-between align-items-center">
                <div class="semana-nav">
                    <button  id="btn-semana-anterior">
                        <i class="fas fa-chevron-left"></i> Semana Anterior
                    </button>
                    <style>#btn-semana-anterior {
    background-color: #4e73df;
    color: white;
    border: none;
    border-radius: 5px;
    padding: 10px 15px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
    display: flex;
    align-items: center;
    gap: 8px;
}

#btn-semana-anterior:hover {
    background-color: #375ad3;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
}

#btn-semana-anterior:active {
    transform: translateY(0);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

#btn-semana-anterior i {
    font-size: 12px;
}
.btn-primary#btn-selector-semana {
    background-color: #4e73df;
    color: white;
    border: none;
    border-radius: 5px;
    padding: 10px 15px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary#btn-selector-semana:hover {
    background-color: #375ad3;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
}

.btn-primary#btn-selector-semana:active {
    transform: translateY(0);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

.btn-primary#btn-selector-semana .fa-calendar-alt {
    font-size: 14px;
}

/* Para asegurar que el texto más largo se muestre correctamente */
.btn-primary#btn-selector-semana {
    white-space: nowrap;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
}

</style>
                    <div class="position-relative">
                        <button class="btn btn-primary" id="btn-selector-semana">
                            <i class="fas fa-calendar-alt me-1"></i> 
                            Semana del <?php echo $fecha_lunes_formato; ?> al <?php echo $fecha_sabado_formato; ?>
                        </button>
                        
                        <!-- Selector de semanas tipo calendario -->
                        <div class="calendario-semanas" id="calendario-semanas">
                            <h5 class="mb-3">Seleccionar Semana</h5>
                            <div class="calendario-grid">
                                <?php foreach ($semanas_mes as $semana): ?>
                                    <div class="semana-item <?php echo ($semana['fecha'] == $fecha_inicio) ? 'active' : ''; ?>" 
                                         data-fecha="<?php echo $semana['fecha']; ?>">
                                        <?php echo $semana['texto']; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <button class="btn btn-primary" id="btn-semana-siguiente">
                        Semana Siguiente <i class="fas fa-chevron-right"></i>
                    </button>
                    <a href="tabla.php" class="btn-dashboard">
  </i> Ver mis rutas
</a>

<style>
  .btn-dashboard {
    background-color: #2563eb;
    color: #ffffff;
    padding: 12px 24px;
    border: none;
    border-radius: 0.75rem;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: background-color 0.3s ease, transform 0.2s ease;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }

  .btn-dashboard:hover {
    background-color: #1d4ed8;
    transform: translateY(-2px);
  }

  .btn-dashboard:active {
    transform: translateY(0);
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.4);
  }

  .btn-dashboard i {
    font-size: 1.2rem;
  }
  .table.table-bordered {
  background-color: transparent !important;
  background-image: none !important;
}


    /* Solo anulamos el background-color de tablas y celdas */
    #tabla-lunes,
    #tabla-lunes th,
    #tabla-lunes td,
    #tabla-martes,
    #tabla-martes th,
    #tabla-martes td,
    #tabla-miercoles,
    #tabla-miercoles th,
    #tabla-miercoles td,
    #tabla-jueves,
    #tabla-jueves th,
    #tabla-jueves td,
    #tabla-viernes,
    #tabla-viernes th,
    #tabla-viernes td,
    #tabla-sabado,
    #tabla-sabado th,
    #tabla-sabado td {
        background-color: transparent !important;
    }
    #guardar-todas-btn {
    background-color: #4CAF50;
    color: white;
    padding: 10px 20px;
    border-radius: 5px;
    border: none;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}

#guardar-todas-btn:hover {
    background-color: #45a049;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

#guardar-todas-btn i {
    margin-right: 8px;
}

</style>

<!-- Font Awesome para íconos -->


                </div>
            </div>
        </div>
        
        <div class="p-3">
            <!-- LUNES -->
            <div class="dia-semana-header" id="header-lunes" data-dia="lunes">
                <span>LUNES - <?php echo $fecha_lunes_formato; ?></span>
                <span class="toggle-collapse">
                    <?php if ($dias_colapsados['lunes']): ?>
                        <i class="fas fa-chevron-down"></i>
                    <?php else: ?>
                        <i class="fas fa-chevron-up"></i>
                    <?php endif; ?>
                </span>
            </div>
            
            <div id="contenido-lunes" class="<?php echo $dias_colapsados['lunes'] ? 'd-none' : ''; ?>">
                <table class="table table-bordered" id="tabla-lunes">
                    <thead>
                        <tr>
                            <th style="width: 15%">CLIENTE</th>
                            <th style="width: 45%">RUTA</th>
                            <th style="width: 20%">CIUDAD</th>
                            <th style="width: 10%">HORA</th>
                            <th style="width: 10%">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rutas_por_dia['lunes'])): ?>
                            <tr>
                                <td colspan="5" class="text-center"></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rutas_por_dia['lunes'] as $ruta): ?>
                                <tr id="ruta-<?php echo $ruta['id']; ?>" class="<?php echo ($ruta_creada && $ruta_creada['id'] == $ruta['id']) ? 'nueva-ruta' : ''; ?>">
                                    <td><?php echo htmlspecialchars($ruta['cliente_nombre']); ?></td>
                                    <td><?php echo htmlspecialchars($ruta['nombre']); ?></td>
                                    <td><?php echo htmlspecialchars($ruta['cliente_ciudad']); ?></td>
                                    <td><?php echo htmlspecialchars($ruta['hora_visita']); ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-primary editar-ruta" 
                                                data-ruta-id="<?php echo $ruta['id']; ?>"
                                                data-cliente-id="<?php echo htmlspecialchars($ruta['cliente_id']); ?>"
                                                data-nombre="<?php echo htmlspecialchars($ruta['nombre']); ?>"
                                                data-vendedor-id="<?php echo $ruta['vendedor_id']; ?>"
                                                data-hora-visita="<?php echo htmlspecialchars($ruta['hora_visita']); ?>"
                                                data-dia="lunes">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger eliminar-ruta" data-ruta-id="<?php echo $ruta['id']; ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="btn-agregar-dia">
                    <button class="btn btn-outline-primary agregar-fila-btn" data-dia="lunes">
                        <i class="fas fa-plus-circle"></i> Agregar Ruta
                    </button>
   
                </div>
            </div>
            
            <!-- MARTES -->
            <div class="dia-semana-header" id="header-martes" data-dia="martes">
                <span>MARTES - <?php echo $fecha_martes_formato; ?></span>
                <span class="toggle-collapse">
                    <?php if ($dias_colapsados['martes']): ?>
                        <i class="fas fa-chevron-down"></i>
                    <?php else: ?>
                        <i class="fas fa-chevron-up"></i>
                    <?php endif; ?>
                </span>
            </div>
            
            <div id="contenido-martes" class="<?php echo $dias_colapsados['martes'] ? 'd-none' : ''; ?>">
                <table class="table table-bordered" id="tabla-martes">
                    <thead>
                        <tr>
                            <th style="width: 15%">CLIENTE</th>
                            <th style="width: 45%">RUTA</th>
                            <th style="width: 20%">CIUDAD</th>
                            <th style="width: 10%">HORA</th>
                            <th style="width: 10%">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rutas_por_dia['martes'])): ?>
                           
                        <?php else: ?>
                            <?php foreach ($rutas_por_dia['martes'] as $ruta): ?>
                                <tr id="ruta-<?php echo $ruta['id']; ?>" class="<?php echo ($ruta_creada && $ruta_creada['id'] == $ruta['id']) ? 'nueva-ruta' : ''; ?>">
                                    <td><?php echo htmlspecialchars($ruta['cliente_nombre']); ?></td>
                                    <td><?php echo htmlspecialchars($ruta['nombre']); ?></td>
                                    <td><?php echo htmlspecialchars($ruta['cliente_ciudad']); ?></td>
                                    <td><?php echo htmlspecialchars($ruta['hora_visita']); ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-primary editar-ruta" 
                                                data-ruta-id="<?php echo $ruta['id']; ?>"
                                                data-cliente-id="<?php echo htmlspecialchars($ruta['cliente_id']); ?>"
                                                data-nombre="<?php echo htmlspecialchars($ruta['nombre']); ?>"
                                                data-vendedor-id="<?php echo $ruta['vendedor_id']; ?>"
                                                data-hora-visita="<?php echo htmlspecialchars($ruta['hora_visita']); ?>"
                                                data-dia="martes">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger eliminar-ruta" data-ruta-id="<?php echo $ruta['id']; ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="btn-agregar-dia">
                    <button class="btn btn-outline-primary agregar-fila-btn" data-dia="martes">
                        <i class="fas fa-plus-circle"></i> Agregar Ruta
                    </button>
      
                </div>
            </div>
            
            <!-- MIÉRCOLES -->
            <div class="dia-semana-header" id="header-miercoles" data-dia="miercoles">
               <span>MIÉRCOLES - <?php echo $fecha_miercoles_formato; ?></span>
                    <span class="toggle-collapse">
                <?php if ($dias_colapsados['miercoles']): ?>
            <i class="fas fa-chevron-down"></i>
        <?php else: ?>
            <i class="fas fa-chevron-up"></i>
        <?php endif; ?>
    </span>
</div>

<div id="contenido-miercoles" class="<?php echo $dias_colapsados['miercoles'] ? 'd-none' : ''; ?>">
    <table class="table table-bordered" id="tabla-miercoles">
        <thead>
            <tr>
                <th style="width: 15%">CLIENTE</th>
                <th style="width: 45%">RUTA</th>
                <th style="width: 20%">CIUDAD</th>
                <th style="width: 10%">HORA</th>
                <th style="width: 10%">ACCIONES</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rutas_por_dia['miercoles'])): ?>
                <tr>
                    <td colspan="5" class="text-center">,</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rutas_por_dia['miercoles'] as $ruta): ?>
                    <tr id="ruta-<?php echo $ruta['id']; ?>" class="<?php echo ($ruta_creada && $ruta_creada['id'] == $ruta['id']) ? 'nueva-ruta' : ''; ?>">
                        <td><?php echo htmlspecialchars($ruta['cliente_nombre']); ?></td>
                        <td><?php echo htmlspecialchars($ruta['nombre']); ?></td>
                        <td><?php echo htmlspecialchars($ruta['cliente_ciudad']); ?></td>
                        <td><?php echo htmlspecialchars($ruta['hora_visita']); ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-primary editar-ruta" 
                                    data-ruta-id="<?php echo $ruta['id']; ?>"
                                    data-cliente-id="<?php echo htmlspecialchars($ruta['cliente_id']); ?>"
                                    data-nombre="<?php echo htmlspecialchars($ruta['nombre']); ?>"
                                    data-vendedor-id="<?php echo $ruta['vendedor_id']; ?>"
                                    data-hora-visita="<?php echo htmlspecialchars($ruta['hora_visita']); ?>"
                                    data-dia="miercoles">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-danger eliminar-ruta" data-ruta-id="<?php echo $ruta['id']; ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="btn-agregar-dia">
        <button class="btn btn-outline-primary agregar-fila-btn" data-dia="miercoles">
            <i class="fas fa-plus-circle"></i> Agregar Ruta
        </button>

    </div>
</div>

            
            <!-- JUEVES -->
            <div class="dia-semana-header" id="header-jueves" data-dia="jueves">
    <span>JUEVES - <?php echo $fecha_jueves_formato; ?></span>
    <span class="toggle-collapse">
        <?php if ($dias_colapsados['jueves']): ?>
            <i class="fas fa-chevron-down"></i>
        <?php else: ?>
            <i class="fas fa-chevron-up"></i>
        <?php endif; ?>
    </span>
</div>

<div id="contenido-jueves" class="<?php echo $dias_colapsados['jueves'] ? 'd-none' : ''; ?>">
    <table class="table table-bordered" id="tabla-jueves">
        <thead>
            <tr>
                <th style="width: 15%">CLIENTE</th>
                <th style="width: 45%">RUTA</th>
                <th style="width: 20%">CIUDAD</th>
                <th style="width: 10%">HORA</th>
                <th style="width: 10%">ACCIONES</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rutas_por_dia['jueves'])): ?>
                <tr>
                    <td colspan="5" class="text-center">,</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rutas_por_dia['jueves'] as $ruta): ?>
                    <tr id="ruta-<?php echo $ruta['id']; ?>" class="<?php echo ($ruta_creada && $ruta_creada['id'] == $ruta['id']) ? 'nueva-ruta' : ''; ?>">
                        <td><?php echo htmlspecialchars($ruta['cliente_nombre']); ?></td>
                        <td><?php echo htmlspecialchars($ruta['nombre']); ?></td>
                        <td><?php echo htmlspecialchars($ruta['cliente_ciudad']); ?></td>
                        <td><?php echo htmlspecialchars($ruta['hora_visita']); ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-primary editar-ruta" 
                                    data-ruta-id="<?php echo $ruta['id']; ?>"
                                    data-cliente-id="<?php echo htmlspecialchars($ruta['cliente_id']); ?>"
                                    data-nombre="<?php echo htmlspecialchars($ruta['nombre']); ?>"
                                    data-vendedor-id="<?php echo $ruta['vendedor_id']; ?>"
                                    data-hora-visita="<?php echo htmlspecialchars($ruta['hora_visita']); ?>"
                                    data-dia="jueves">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-danger eliminar-ruta" data-ruta-id="<?php echo $ruta['id']; ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="btn-agregar-dia">
        <button class="btn btn-outline-primary agregar-fila-btn" data-dia="jueves">
            <i class="fas fa-plus-circle"></i> Agregar Ruta
        </button>
       
    </div>
</div>

            
            <!-- VIERNES -->
            <div class="dia-semana-header" id="header-viernes" data-dia="viernes">
    <span>VIERNES - <?php echo $fecha_viernes_formato; ?></span>
    <span class="toggle-collapse">
        <?php if ($dias_colapsados['viernes']): ?>
            <i class="fas fa-chevron-down"></i>
        <?php else: ?>
            <i class="fas fa-chevron-up"></i>
        <?php endif; ?>
    </span>
</div>

<div id="contenido-viernes" class="<?php echo $dias_colapsados['viernes'] ? 'd-none' : ''; ?>">
    <table class="table table-bordered" id="tabla-viernes">
        <thead>
            <tr>
                <th style="width: 15%">CLIENTE</th>
                <th style="width: 45%">RUTA</th>
                <th style="width: 20%">CIUDAD</th>
                <th style="width: 10%">HORA</th>
                <th style="width: 10%">ACCIONES</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rutas_por_dia['viernes'])): ?>
                <tr>
                    <td colspan="5" class="text-center">,</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rutas_por_dia['viernes'] as $ruta): ?>
                    <tr id="ruta-<?php echo $ruta['id']; ?>" class="<?php echo ($ruta_creada && $ruta_creada['id'] == $ruta['id']) ? 'nueva-ruta' : ''; ?>">
                        <td><?php echo htmlspecialchars($ruta['cliente_nombre']); ?></td>
                        <td><?php echo htmlspecialchars($ruta['nombre']); ?></td>
                        <td><?php echo htmlspecialchars($ruta['cliente_ciudad']); ?></td>
                        <td><?php echo htmlspecialchars($ruta['hora_visita']); ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-primary editar-ruta" 
                                    data-ruta-id="<?php echo $ruta['id']; ?>"
                                    data-cliente-id="<?php echo htmlspecialchars($ruta['cliente_id']); ?>"
                                    data-nombre="<?php echo htmlspecialchars($ruta['nombre']); ?>"
                                    data-vendedor-id="<?php echo $ruta['vendedor_id']; ?>"
                                    data-hora-visita="<?php echo htmlspecialchars($ruta['hora_visita']); ?>"
                                    data-dia="viernes">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-danger eliminar-ruta" data-ruta-id="<?php echo $ruta['id']; ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="btn-agregar-dia">
        <button class="btn btn-outline-primary agregar-fila-btn" data-dia="viernes">
            <i class="fas fa-plus-circle"></i> Agregar Ruta
        </button>
     
    </div>
</div>

            <!-- SÁBADO -->
            <div class="dia-semana-header" id="header-sabado" data-dia="sabado">
    <span>SÁBADO - <?php echo $fecha_sabado_formato; ?></span>
    <span class="toggle-collapse">
        <?php if ($dias_colapsados['sabado']): ?>
            <i class="fas fa-chevron-down"></i>
        <?php else: ?>
            <i class="fas fa-chevron-up"></i>
        <?php endif; ?>
    </span>
</div>

<div id="contenido-sabado" class="<?php echo $dias_colapsados['sabado'] ? 'd-none' : ''; ?>">
    <table class="table table-bordered" id="tabla-sabado">
        <thead>
            <tr>
                <th style="width: 15%">CLIENTE</th>
                <th style="width: 45%">RUTA</th>
                <th style="width: 20%">CIUDAD</th>
                <th style="width: 10%">HORA</th>
                <th style="width: 10%">ACCIONES</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rutas_por_dia['sabado'])): ?>
                <tr>
                    <td colspan="5" class="text-center">,</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rutas_por_dia['sabado'] as $ruta): ?>
                    <tr id="ruta-<?php echo $ruta['id']; ?>" class="<?php echo ($ruta_creada && $ruta_creada['id'] == $ruta['id']) ? 'nueva-ruta' : ''; ?>">
                        <td><?php echo htmlspecialchars($ruta['cliente_nombre']); ?></td>
                        <td><?php echo htmlspecialchars($ruta['nombre']); ?></td>
                        <td><?php echo htmlspecialchars($ruta['cliente_ciudad']); ?></td>
                        <td><?php echo htmlspecialchars($ruta['hora_visita']); ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-primary editar-ruta" 
                                    data-ruta-id="<?php echo $ruta['id']; ?>"
                                    data-cliente-id="<?php echo htmlspecialchars($ruta['cliente_id']); ?>"
                                    data-nombre="<?php echo htmlspecialchars($ruta['nombre']); ?>"
                                    data-vendedor-id="<?php echo $ruta['vendedor_id']; ?>"
                                    data-hora-visita="<?php echo htmlspecialchars($ruta['hora_visita']); ?>"
                                    data-dia="sabado">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-danger eliminar-ruta" data-ruta-id="<?php echo $ruta['id']; ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="btn-agregar-dia">
        <button class="btn btn-outline-primary agregar-fila-btn" data-dia="sabado">
            <i class="fas fa-plus-circle"></i> Agregar Ruta
      
    </div>
</div>


            </div>
            <center>
            <button id="guardar-todas-btn" class="btn btn-primary">
        <i class="fas fa-save"></i> Guardar Todas
    </button>
    </center>
        </div>
      
    </div>
    
</div>


<!-- Modal para agregar/editar ruta -->
<div class="modal fade" id="modalRuta" tabindex="-1" aria-labelledby="modalRutaLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalRutaLabel">Agregar Ruta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="formRuta">
                    <input type="hidden" id="ruta_id" name="ruta_id" value="0">
                    <input type="hidden" id="dia_semana" name="dia_semana" value="">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="cliente_id" class="form-label">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id" required data-live-search="true">
                                <option value="">Seleccione un cliente</option>
                                <?php foreach ($clientes as $id => $cliente): ?>
                                    <option value="<?php echo htmlspecialchars($id); ?>" data-ciudad="<?php echo htmlspecialchars($cliente['ciudad']); ?>" data-vendedor="<?php echo htmlspecialchars($cliente['vendedor_id']); ?>">
                                        <?php echo htmlspecialchars($cliente['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="vendedor_id" class="form-label">Vendedor</label>
                            <select class="form-select" id="vendedor_id" name="vendedor_id" readonly>
                                <option value="">Seleccione un vendedor</option>
                                <?php foreach ($vendedores as $vendedor): ?>
                                    <option value="<?php echo $vendedor['SlpCode']; ?>">
                                        <?php echo htmlspecialchars($vendedor['SlpName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="nombre" class="form-label">Nombre de la Ruta</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" required>
                        </div>
                        <div class="col-md-6">
                            <label for="ciudad" class="form-label">Ciudad</label>
                            <input type="text" class="form-control" id="ciudad" name="ciudad" readonly>
                        </div>
                        <div class="col-md-6">
                            <label for="ciudad" class="form-label">Ruta</label>
                            <input type="text" class="form-control" id="ciudad" name="ciudad" >
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="hora_visita" class="form-label">Hora de Visita</label>
                            <input type="time" class="form-control" id="hora_visita" name="hora_visita">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-eliminar-ruta" style="display: none;">Eliminar</button>
                <button type="button" class="btn btn-primary" id="btn-guardar-ruta">Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para exportar a Excel -->
<div class="modal fade" id="modalExportar" tabindex="-1" aria-labelledby="modalExportarLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalExportarLabel">Exportar a Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="formExportar">
                    <div class="mb-3">
                        <label class="form-label">Periodo a exportar</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="periodo" id="periodo-semana" value="semana" checked>
                            <label class="form-check-label" for="periodo-semana">
                                Semana actual (<?php echo $fecha_lunes_formato; ?> al <?php echo $fecha_sabado_formato; ?>)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="periodo" id="periodo-mes" value="mes">
                            <label class="form-check-label" for="periodo-mes">
                                Mes actual (<?php echo date('d/m/Y', strtotime('first day of this month')); ?> al <?php echo date('d/m/Y', strtotime('last day of this month')); ?>)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="periodo" id="periodo-personalizado" value="personalizado">
                            <label class="form-check-label" for="periodo-personalizado">
                                Periodo personalizado
                            </label>
                        </div>
                    </div>
                    
                    <div id="periodo-personalizado-fechas" style="display: none;">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="fecha-desde" class="form-label">Desde</label>
                                <input type="date" class="form-control" id="fecha-desde" name="fecha_desde" value="<?php echo $fecha_lunes->format('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="fecha-hasta" class="form-label">Hasta</label>
                                <input type="date" class="form-control" id="fecha-hasta" name="fecha_hasta" value="<?php echo $fecha_sabado->format('Y-m-d'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="exportar-vendedor" class="form-label">Vendedor</label>
                        <select class="form-select" id="exportar-vendedor" name="vendedor">
                            <option value="">Todos los vendedores</option>
                            <?php foreach ($vendedores as $v): ?>
                                <option value="<?php echo $v['SlpCode']; ?>" <?php echo ($vendedor_id == $v['SlpCode']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($v['SlpName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="exportar-zona" class="form-label">Zona</label>
                            <select class="form-select" id="exportar-zona" name="zona">
                                <option value="">Todas las zonas</option>
                                <?php foreach ($zonas as $z): ?>
                                    <option value="<?php echo htmlspecialchars($z); ?>" <?php echo ($zona == $z) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($z); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="exportar-canal" class="form-label">Canal</label>
                            <select class="form-select" id="exportar-canal" name="canal">
                                <option value="">Todos los canales</option>
                                <?php foreach ($canales as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo ($canal == $c) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-confirmar-exportar">Exportar</button>
            </div>
        </div>
    </div>
 
</div>


<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.all.min.js"></script>
<!-- En la sección de scripts, después de cargar jQuery pero antes de tu script personalizado, agregar el siguiente script para Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Reemplazar el script existente con el siguiente script actualizado -->
<script>
    $(document).ready(function() {
        // Variables globales
        let modalRuta = new bootstrap.Modal(document.getElementById('modalRuta'));
        let modalExportar = new bootstrap.Modal(document.getElementById('modalExportar'));
        let calendarioSemanas = document.getElementById('calendario-semanas');
        
        // Inicializar Select2 en los selects existentes
        initializeSelect2();
        
        // Mostrar mensaje de éxito si hay una ruta creada
        <?php if ($ruta_creada): ?>
        Swal.fire({
            title: '¡Ruta creada con éxito!',
            text: 'La ruta "<?php echo $ruta_creada['nombre']; ?>" ha sido guardada correctamente para el cliente <?php echo $ruta_creada['cliente_nombre']; ?>',
            icon: 'success',
            confirmButtonText: 'Aceptar',
            confirmButtonColor: '#28a745',
            timer: 5000,
            timerProgressBar: true
        });
        <?php endif; ?>
        
        // Función para inicializar Select2 en los selects
        function initializeSelect2() {
            // Aplicar Select2 a todos los selects con data-live-search="true"
            $('select[data-live-search="true"]').select2({
                theme: 'bootstrap-5',
                width: '100%',
                
                allowClear: true,
                language: {
                    noResults: function() {
                        return "No se encontraron resultados";
                    },
                    searching: function() {
                        return "Buscando...";
                    }
                }
            });
            
            // Aplicar Select2 al select de cliente en el modal
            $('#cliente_id').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#modalRuta .modal-body'),
                width: '100%',
                
                allowClear: true,
                language: {
                    noResults: function() {
                        return "No se encontraron resultados";
                    },
                    searching: function() {
                        return "Buscando...";
                    }
                }
            });
        }
        
        // Función para actualizar la URL con los filtros
        function actualizarURL() {
            let url = new URL(window.location.href);
            
            // Obtener valores de filtros
            let vendedor = $('#filtro-vendedor').val();
            let zona = $('#filtro-zona').val();
            let canal = $('#filtro-canal').val();
            let busqueda = $('#filtro-busqueda').val();
            
            // Actualizar parámetros
            url.searchParams.set('fecha', '<?php echo $fecha_lunes->format('Y-m-d'); ?>');
            
            if (vendedor) {
                url.searchParams.set('vendedor', vendedor);
            } else {
                url.searchParams.delete('vendedor');
            }
            
            if (zona) {
                url.searchParams.set('zona', zona);
            } else {
                url.searchParams.delete('zona');
            }
            
            if (canal) {
                url.searchParams.set('canal', canal);
            } else {
                url.searchParams.delete('canal');
            }
            
            if (busqueda) {
                url.searchParams.set('busqueda', busqueda);
            } else {
                url.searchParams.delete('busqueda');
            }
            
            // Redirigir a la nueva URL
            window.location.href = url.toString();
        }
        
        // Evento para cambiar de semana (anterior)
        $('#btn-semana-anterior').click(function() {
            window.location.href = '?fecha=<?php echo $semana_anterior->format('Y-m-d'); ?>&vendedor=<?php echo $vendedor_id ?: ''; ?>&zona=<?php echo urlencode($zona); ?>&canal=<?php echo urlencode($canal); ?>&busqueda=<?php echo urlencode($busqueda); ?>';
        });
        
        // Evento para cambiar de semana (siguiente)
        $('#btn-semana-siguiente').click(function() {
            window.location.href = '?fecha=<?php echo $semana_siguiente->format('Y-m-d'); ?>&vendedor=<?php echo $vendedor_id ?: ''; ?>&zona=<?php echo urlencode($zona); ?>&canal=<?php echo urlencode($canal); ?>&busqueda=<?php echo urlencode($busqueda); ?>';
        });
        
        // Evento para mostrar/ocultar el selector de semanas
        $('#btn-selector-semana').click(function(e) {
            e.stopPropagation();
            $('#calendario-semanas').toggleClass('show');
        });
        
        // Cerrar el selector de semanas al hacer clic fuera de él
        $(document).click(function(e) {
            if (!$(e.target).closest('#calendario-semanas, #btn-selector-semana').length) {
                $('#calendario-semanas').removeClass('show');
            }
        });
        
        // Evento para seleccionar una semana del calendario
        $('.semana-item').click(function() {
            let fecha = $(this).data('fecha');
            window.location.href = '?fecha=' + fecha + '&vendedor=<?php echo $vendedor_id ?: ''; ?>&zona=<?php echo urlencode($zona); ?>&canal=<?php echo urlencode($canal); ?>&busqueda=<?php echo urlencode($busqueda); ?>';
        });
        
        // Evento para cambiar filtro de vendedor
        $('#filtro-vendedor').change(function() {
            actualizarURL();
        });
        
        // Evento para cambiar filtro de zona
        $('#filtro-zona').change(function() {
            actualizarURL();
        });
        
        // Evento para cambiar filtro de canal
        $('#filtro-canal').change(function() {
            actualizarURL();
        });
        
        // Evento para buscar
        $('#btn-buscar').click(function() {
            actualizarURL();
        });
        
        // Evento para buscar al presionar Enter en el campo de búsqueda
        $('#filtro-busqueda').keypress(function(e) {
            if (e.which === 13) {
                actualizarURL();
            }
        });
        
        // Evento para colapsar/expandir días
        $('.dia-semana-header').click(function() {
            let dia = $(this).data('dia');
            let contenido = $('#contenido-' + dia);
            let icono = $(this).find('.toggle-collapse i');
            
            contenido.toggleClass('d-none');
            
            if (contenido.hasClass('d-none')) {
                icono.removeClass('fa-chevron-up').addClass('fa-chevron-down');
            } else {
                icono.removeClass('fa-chevron-down').addClass('fa-chevron-up');
            }
            
            // Guardar estado en el servidor
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    action: 'toggle_dia_colapso',
                    dia: dia
                },
                dataType: 'json'
            });
        });
        
        // Evento para abrir modal de agregar ruta
        $('#btn-agregar-ruta').click(function() {
            // Limpiar formulario
            $('#formRuta')[0].reset();
            $('#ruta_id').val(0);
            $('#modalRutaLabel').text('Agregar Ruta');
            $('#btn-eliminar-ruta').hide();
            
            // Resetear Select2
            $('#cliente_id').val(null).trigger('change');
            
            // Mostrar modal
            modalRuta.show();
        });
        
        // Evento para editar ruta existente
        $(document).on('click', '.editar-ruta', function() {
            let rutaId = $(this).data('ruta-id');
            let clienteId = $(this).data('cliente-id');
            let nombre = $(this).data('nombre');
            let vendedorId = $(this).data('vendedor-id');
            let horaVisita = $(this).data('hora-visita');
            let dia = $(this).data('dia');
            
            // Llenar formulario
            $('#ruta_id').val(rutaId);
            $('#cliente_id').val(clienteId).trigger('change'); // Importante para Select2
            $('#nombre').val(nombre);
            $('#vendedor_id').val(vendedorId);
            $('#hora_visita').val(horaVisita);
            $('#dia_semana').val(dia);
            
            // Actualizar ciudad desde la base de datos
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    action: 'obtener_info_cliente',
                    cliente_id: clienteId
                },
                dataType: 'json',
                beforeSend: function() {
                    // Mostrar indicador de carga
                    $('#ciudad').val('Cargando...');
                    $('#vendedor_id').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        $('#ciudad').val(response.ciudad);
                    } else {
                        // Si falla, intentar obtener del atributo data
                        let ciudad = $('#cliente_id option:selected').data('ciudad') || '';
                        $('#ciudad').val(ciudad);
                    }
                },
                error: function() {
                    // Si falla, intentar obtener del atributo data
                    let ciudad = $('#cliente_id option:selected').data('ciudad') || '';
                    $('#ciudad').val(ciudad);
                }
            });
            
            // Actualizar título y mostrar botón eliminar
            $('#modalRutaLabel').text('Editar Ruta');
            $('#btn-eliminar-ruta').show();
            
            // Mostrar modal
            modalRuta.show();
        });
        
        // Evento para cambiar cliente (actualizar ciudad y vendedor)
        $('#cliente_id').change(function() {
            let clienteId = $(this).val();
            
            if (clienteId) {
                // Obtener información del cliente desde SAP
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'obtener_info_cliente',
                        cliente_id: clienteId
                    },
                    dataType: 'json',
                    beforeSend: function() {
                        // Mostrar indicador de carga
                        $('#ciudad').val('Cargando...');
                        $('#vendedor_id').prop('disabled', true);
                    },
                    success: function(response) {
                        if (response.success) {
                            // Actualizar ciudad
                            $('#ciudad').val(response.ciudad);
                            
                            // Actualizar vendedor
                            $('#vendedor_id').val(response.vendedor_id);
                            
                            // Siempre actualizar el nombre de la ruta cuando se cambia el cliente
                            $('#nombre').val('Visita a ' + response.nombre);
                            
                            // Habilitar vendedor
                            $('#vendedor_id').prop('disabled', false);
                        } else {
                            // Si falla, intentar obtener del atributo data
                            let ciudad = $('#cliente_id option:selected').data('ciudad') || '';
                            let vendedor = $('#cliente_id option:selected').data('vendedor') || '';
                            
                            $('#ciudad').val(ciudad);
                            $('#vendedor_id').val(vendedor);
                            $('#vendedor_id').prop('disabled', false);
                            
                            // Siempre actualizar el nombre de la ruta cuando se cambia el cliente
                            let clienteNombre = $('#cliente_id option:selected').text().trim();
                            $('#nombre').val('Visita a ' + clienteNombre);
                        }
                    },
                    error: function() {
                        // Si falla, intentar obtener del atributo data
                        let ciudad = $('#cliente_id option:selected').data('ciudad') || '';
                        let vendedor = $('#cliente_id option:selected').data('vendedor') || '';
                        
                        $('#ciudad').val(ciudad);
                        $('#vendedor_id').val(vendedor);
                        $('#vendedor_id').prop('disabled', false);
                        
                        // Siempre actualizar el nombre de la ruta cuando se cambia el cliente
                        let clienteNombre = $('#cliente_id option:selected').text().trim();
                        $('#nombre').val('Visita a ' + clienteNombre);
                    }
                });
            } else {
                // Limpiar campos si no hay cliente seleccionado
                $('#ciudad').val('');
                $('#vendedor_id').val('');
                $('#nombre').val('');
            }
        });
        
        // Evento para guardar ruta desde el modal
        $('#btn-guardar-ruta').click(function() {
            // Validar formulario
            if (!$('#cliente_id').val()) {
                Swal.fire({
                    title: 'Error',
                    text: 'Debe seleccionar un cliente',
                    icon: 'error',
                    confirmButtonText: 'Aceptar'
                });
                return;
            }
            
            if (!$('#nombre').val()) {
                Swal.fire({
                    title: 'Error',
                    text: 'Debe ingresar un nombre para la ruta',
                    icon: 'error',
                    confirmButtonText: 'Aceptar'
                });
                return;
            }
            
            // Obtener datos del formulario
            let rutaId = $('#ruta_id').val();
            let clienteId = $('#cliente_id').val();
            let nombre = $('#nombre').val();
            let vendedorId = $('#vendedor_id').val();
            let horaVisita = $('#hora_visita').val();
            let dia = $('#dia_semana').val();
            let ciudad = $('#ciudad').val();
            
            // Mostrar indicador de carga
            $('#btn-guardar-ruta').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Guardando...');
            
            // Enviar datos al servidor
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    action: 'guardar_ruta',
                    ruta_id: rutaId,
                    cliente_id: clienteId,
                    nombre: nombre,
                    vendedor_id: vendedorId,
                    hora_visita: horaVisita,
                    dia: dia,
                    ciudad: ciudad
                },
                dataType: 'json',
                success: function(response) {
                    // Ocultar indicador de carga
                    $('#btn-guardar-ruta').prop('disabled', false).html('Guardar');
                    
                    if (response.success) {
                        // Cerrar modal
                        modalRuta.hide();
                        
                        // Mostrar mensaje de éxito
                        Swal.fire({
                            title: '¡Éxito!',
                            text: response.message,
                            icon: 'success',
                            confirmButtonText: 'Aceptar',
                            confirmButtonColor: '#28a745'
                        }).then((result) => {
                            // Si es una nueva ruta, agregarla a la tabla sin recargar
                            if (rutaId == 0 && response.ruta) {
                                let ruta = response.ruta;
                                let dia_tabla = ruta.dia_semana.toLowerCase();
                                
                                // Traducir día de inglés a español
                                if (dia_tabla === 'monday') dia_tabla = 'lunes';
                                else if (dia_tabla === 'tuesday') dia_tabla = 'martes';
                                else if (dia_tabla === 'wednesday') dia_tabla = 'miercoles';
                                else if (dia_tabla === 'thursday') dia_tabla = 'jueves';
                                else if (dia_tabla === 'friday') dia_tabla = 'viernes';
                                else if (dia_tabla === 'saturday') dia_tabla = 'sabado';
                                
                                // Verificar si hay filas vacías para eliminar
                                let tabla = $('#tabla-' + dia_tabla);
                                let filas_vacias = tabla.find('tbody tr.fila-ruta');
                                if (filas_vacias.length > 0) {
                                    filas_vacias.remove();
                                }
                                
                                // Crear nueva fila
                                let nuevaFila = `
                                    <tr id="ruta-${ruta.id}" class="nueva-ruta">
                                        <td>${ruta.cliente_nombre}</td>
                                        <td>${ruta.nombre}</td>
                                        <td>${ruta.cliente_ciudad}</td>
                                        <td>${ruta.hora_visita}</td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-primary editar-ruta" 
                                                    data-ruta-id="${ruta.id}"
                                                    data-cliente-id="${ruta.cliente_id}"
                                                    data-nombre="${ruta.nombre}"
                                                    data-vendedor-id="${ruta.vendedor_id}"
                                                    data-hora-visita="${ruta.hora_visita}"
                                                    data-dia="${dia}">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger eliminar-ruta" data-ruta-id="${ruta.id}">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                `;
                                
                                // Agregar fila a la tabla
                                tabla.find('tbody').append(nuevaFila);
                                
                                // Expandir el día si está colapsado
                                if ($('#contenido-' + dia_tabla).hasClass('d-none')) {
                                    $('#header-' + dia_tabla).click();
                                }
                                
                                // Hacer scroll a la nueva fila
                                $('html, body').animate({
                                    scrollTop: $('#ruta-' + ruta.id).offset().top - 100
                                }, 1000);
                            } else {
                                // Si es una actualización, recargar la página
                                location.reload();
                            }
                        });
                    } else {
                        Swal.fire({
                            title: 'Error',
                            text: response.message || 'Error al guardar la ruta',
                            icon: 'error',
                            confirmButtonText: 'Aceptar'
                        });
                        $('#btn-guardar-ruta').prop('disabled', false).html('Guardar');
                    }
                },
                error: function() {
                    Swal.fire({
                        title: 'Error',
                        text: 'Error de comunicación con el servidor',
                        icon: 'error',
                        confirmButtonText: 'Aceptar'
                    });
                    $('#btn-guardar-ruta').prop('disabled', false).html('Guardar');
                }
            });
        });
        
        // Evento para eliminar ruta desde el modal
        $('#btn-eliminar-ruta').click(function() {
            Swal.fire({
                title: '¿Está seguro?',
                text: 'Esta acción eliminará la ruta permanentemente',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    let rutaId = $('#ruta_id').val();
                    
                    // Mostrar indicador de carga
                    $('#btn-eliminar-ruta').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Eliminando...');
                    
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            action: 'eliminar_ruta',
                            ruta_id: rutaId
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                // Cerrar modal
                                modalRuta.hide();
                                
                                // Mostrar mensaje de éxito
                                Swal.fire({
                                    title: '¡Eliminada!',
                                    text: response.message,
                                    icon: 'success',
                                    confirmButtonText: 'Aceptar',
                                    confirmButtonColor: '#28a745'
                                }).then(() => {
                                    // Eliminar la fila sin recargar
                                    $('#ruta-' + rutaId).fadeOut(500, function() {
                                        $(this).remove();
                                    });
                                });
                            } else {
                                Swal.fire({
                                    title: 'Error',
                                    text: response.message || 'Error al eliminar la ruta',
                                    icon: 'error',
                                    confirmButtonText: 'Aceptar'
                                });
                                $('#btn-eliminar-ruta').prop('disabled', false).html('Eliminar');
                            }
                        },
                        error: function() {
                            Swal.fire({
                                title: 'Error',
                                text: 'Error de comunicación con el servidor',
                                icon: 'error',
                                confirmButtonText: 'Aceptar'
                            });
                            $('#btn-eliminar-ruta').prop('disabled', false).html('Eliminar');
                        }
                    });
                }
            });
        });
        
        // Evento para eliminar ruta directamente desde la tabla
        $(document).on('click', '.eliminar-ruta', function() {
            let rutaId = $(this).data('ruta-id');
            let boton = $(this);
            
            Swal.fire({
                title: '¿Está seguro?',
                text: 'Esta acción eliminará la ruta permanentemente',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Mostrar indicador de carga
                    boton.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
                    
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            action: 'eliminar_ruta',
                            ruta_id: rutaId
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                // Mostrar mensaje de éxito
                                Swal.fire({
                                    title: '¡Eliminada!',
                                    text: response.message,
                                    icon: 'success',
                                    confirmButtonText: 'Aceptar',
                                    confirmButtonColor: '#28a745'
                                }).then(() => {
                                    // Eliminar la fila sin recargar
                                    $('#ruta-' + rutaId).fadeOut(500, function() {
                                        $(this).remove();
                                    });
                                });
                            } else {
                                Swal.fire({
                                    title: 'Error',
                                    text: response.message || 'Error al eliminar la ruta',
                                    icon: 'error',
                                    confirmButtonText: 'Aceptar'
                                });
                                boton.prop('disabled', false).html('<i class="fas fa-trash"></i>');
                            }
                        },
                        error: function() {
                            Swal.fire({
                                title: 'Error',
                                text: 'Error de comunicación con el servidor',
                                icon: 'error',
                                confirmButtonText: 'Aceptar'
                            });
                            boton.prop('disabled', false).html('<i class="fas fa-trash"></i>');
                        }
                    });
                }
            });
        });
        
        // Evento para agregar fila a una tabla de día
        $('.agregar-fila-btn').click(function() {
            let dia = $(this).data('dia');
            let tabla = $('#tabla-' + dia + ' tbody');
            
            // Crear nueva fila
            let nuevaFila = `
                <tr class="fila-ruta" data-dia="${dia}">
                    <td>
                       <select class="form-select cliente-select-fila"  data-live-search="true ">
                            <option value="">Seleccione un cliente</option>
                            <?php foreach ($clientes as $id => $cliente): ?>
                                <option value="<?php echo htmlspecialchars($id); ?>" 
                                        data-ciudad="<?php echo htmlspecialchars($cliente['ciudad']); ?>" 
                                        data-vendedor="<?php echo htmlspecialchars($cliente['vendedor_id']); ?>">
                                    <?php echo htmlspecialchars($cliente['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" class="form-control nombre-input" placeholder="Nombre de la ruta" required>
                    </td>
                    <td>
                        <input type="text" class="form-control ciudad-input" readonly>
                    </td>
                    <td>
                        <input type="time" class="form-control hora-input">
                    </td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-success guardar-ruta-btn">
                            <i class="fas fa-save"></i>
                        </button>
                    </td>
                </tr>
            `;
            
            // Agregar fila a la tabla
            tabla.append(nuevaFila);
            
            // Inicializar Select2 en el nuevo select
            tabla.find('.cliente-select-fila').last().select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Buscar cliente...',
                allowClear: true,
                language: {
                    noResults: function() {
                        return "No se encontraron resultados";
                    },
                    searching: function() {
                        return "Buscando...";
                    }
                }
            });
            
            // Inicializar eventos para la nueva fila
            inicializarEventosFilaRuta();
        });
        
        // Evento para guardar todas las rutas
        $('#guardar-todas-btn').click(function() {
            // Selecciona todos los botones individuales de guardar
            const botonesGuardar = document.querySelectorAll('.guardar-ruta-btn');

            // Simula un click en cada botón
            botonesGuardar.forEach(function(boton) {
                boton.click();
            });
        });
        
        // Función para inicializar events en filas de ruta
        
// En la sección de JavaScript, reemplazar la función inicializarEventosFilaRuta() completa con esta versión mejorada:

function inicializarEventosFilaRuta() {
    // Evento para cambiar cliente (actualizar ciudad)
    $('.cliente-select-fila').off('change').on('change', function() {
        let clienteId = $(this).val();
        let fila = $(this).closest('tr');
        let ciudadInput = fila.find('.ciudad-input');
        let nombreInput = fila.find('.nombre-input');
        
        if (clienteId) {
            // Obtener información del cliente desde SAP
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    action: 'obtener_info_cliente',
                    cliente_id: clienteId
                },
                dataType: 'json',
                beforeSend: function() {
                    // Mostrar indicador de carga
                    ciudadInput.val('Cargando...');
                },
                success: function(response) {
                    if (response.success) {
                        // Actualizar ciudad
                        ciudadInput.val(response.ciudad);
                        
                        // Siempre actualizar el nombre de la ruta cuando se cambia el cliente
                        nombreInput.val('Visita a ' + response.nombre);
                    } else {
                        // Si falla, intentar obtener del atributo data
                        let opcionSeleccionada = fila.find('.cliente-select-fila option:selected');
                        let ciudad = opcionSeleccionada.data('ciudad') || '';
                        ciudadInput.val(ciudad);
                        
                        // Siempre actualizar el nombre de la ruta cuando se cambia el cliente
                        let clienteNombre = opcionSeleccionada.text().trim();
                        nombreInput.val('Visita a ' + clienteNombre);
                    }
                },
                error: function() {
                    // Si falla, intentar obtener del atributo data
                    let opcionSeleccionada = fila.find('.cliente-select-fila option:selected');
                    let ciudad = opcionSeleccionada.data('ciudad') || '';
                    ciudadInput.val(ciudad);
                    
                    // Siempre actualizar el nombre de la ruta cuando se cambia el cliente
                    let clienteNombre = opcionSeleccionada.text().trim();
                    nombreInput.val('Visita a ' + clienteNombre);
                }
            });
        } else {
            // Limpiar campos si no hay cliente seleccionado
            ciudadInput.val('');
            nombreInput.val('');
        }
    });
    
    // Evento para guardar ruta desde la fila
    $('.guardar-ruta-btn').off('click').on('click', function() {
        let fila = $(this).closest('tr');
        let dia = fila.data('dia');
        let clienteId = fila.find('.cliente-select-fila').val();
        let nombre = fila.find('.nombre-input').val();
        let horaVisita = fila.find('.hora-input').val();
        let vendedorId = fila.find('.cliente-select-fila option:selected').data('vendedor');
        let ciudad = fila.find('.ciudad-input').val();
        
        // Validar datos
        if (!clienteId) {
            Swal.fire({
                title: 'Error',
                text: 'Debe seleccionar un cliente',
                icon: 'error',
                confirmButtonText: 'Aceptar'
            });
            return;
        }
        
        if (!nombre) {
            Swal.fire({
                title: 'Error',
                text: 'Debe ingresar un nombre para la ruta',
                icon: 'error',
                confirmButtonText: 'Aceptar'
            });
            return;
        }
        
        // Mostrar indicador de carga
        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
        
        // Enviar datos al servidor
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                action: 'guardar_ruta',
                ruta_id: 0, // Nueva ruta
                cliente_id: clienteId,
                nombre: nombre,
                vendedor_id: vendedorId,
                hora_visita: horaVisita,
                dia: dia,
                ciudad: ciudad
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Mostrar mensaje de éxito
                    Swal.fire({
                        title: '¡Éxito!',
                        text: response.message,
                        icon: 'success',
                        confirmButtonText: 'Aceptar',
                        confirmButtonColor: '#28a745'
                    }).then(() => {
                        // Si hay información de la ruta, actualizar la fila sin recargar
                        if (response.ruta) {
                            let ruta = response.ruta;
                            
                            // Reemplazar la fila actual con la nueva ruta
                            let nuevaFila = `
                                <tr id="ruta-${ruta.id}" class="nueva-ruta">
                                    <td>${ruta.cliente_nombre}</td>
                                    <td>${ruta.nombre}</td>
                                    <td>${ruta.cliente_ciudad}</td>
                                    <td>${ruta.hora_visita}</td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-primary editar-ruta" 
                                                data-ruta-id="${ruta.id}"
                                                data-cliente-id="${ruta.cliente_id}"
                                                data-nombre="${ruta.nombre}"
                                                data-vendedor-id="${ruta.vendedor_id}"
                                                data-hora-visita="${ruta.hora_visita}"
                                                data-dia="${dia}">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger eliminar-ruta" data-ruta-id="${ruta.id}">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            `;
                            
                            fila.replaceWith(nuevaFila);
                        } else {
                            // Si no hay información, recargar la página
                            location.reload();
                        }
                    });
                } else {
                    Swal.fire({
                        title: 'Error',
                        text: response.message || 'Error al guardar la ruta',
                        icon: 'error',
                        confirmButtonText: 'Aceptar'
                    });
                    $(this).prop('disabled', false).html('<i class="fas fa-save"></i>');
                }
            },
            error: function() {
                Swal.fire({
                    title: 'Error',
                    text: 'Error de comunicación con el servidor',
                    icon: 'error',
                    confirmButtonText: 'Aceptar'
                });
                $(this).prop('disabled', false).html('<i class="fas fa-save"></i>');
            }
        });
    });
}

// También reemplazar el evento para cambiar cliente en el modal:

// Evento para cambiar cliente (actualizar ciudad y vendedor)
$('#cliente_id').change(function() {
    let clienteId = $(this).val();
    
    if (clienteId) {
        // Obtener información del cliente desde SAP
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                action: 'obtener_info_cliente',
                cliente_id: clienteId
            },
            dataType: 'json',
            beforeSend: function() {
                // Mostrar indicador de carga
                $('#ciudad').val('Cargando...');
                $('#vendedor_id').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    // Actualizar ciudad
                    $('#ciudad').val(response.ciudad);
                    
                    // Actualizar vendedor
                    $('#vendedor_id').val(response.vendedor_id);
                    
                    // Siempre actualizar el nombre de la ruta cuando se cambia el cliente
                    $('#nombre').val('Visita a ' + response.nombre);
                    
                    // Habilitar vendedor
                    $('#vendedor_id').prop('disabled', false);
                } else {
                    // Si falla, intentar obtener del atributo data
                    let ciudad = $('#cliente_id option:selected').data('ciudad') || '';
                    let vendedor = $('#cliente_id option:selected').data('vendedor') || '';
                    
                    $('#ciudad').val(ciudad);
                    $('#vendedor_id').val(vendedor);
                    $('#vendedor_id').prop('disabled', false);
                    
                    // Siempre actualizar el nombre de la ruta cuando se cambia el cliente
                    let clienteNombre = $('#cliente_id option:selected').text().trim();
                    $('#nombre').val('Visita a ' + clienteNombre);
                }
            },
            error: function() {
                // Si falla, intentar obtener del atributo data
                let ciudad = $('#cliente_id option:selected').data('ciudad') || '';
                let vendedor = $('#cliente_id option:selected').data('vendedor') || '';
                
                $('#ciudad').val(ciudad);
                $('#vendedor_id').val(vendedor);
                $('#vendedor_id').prop('disabled', false);
                
                // Siempre actualizar el nombre de la ruta cuando se cambia el cliente
                let clienteNombre = $('#cliente_id option:selected').text().trim();
                $('#nombre').val('Visita a ' + clienteNombre);
            }
        });
    } else {
        // Limpiar campos si no hay cliente seleccionado
        $('#ciudad').val('');
        $('#vendedor_id').val('');
        $('#nombre').val('');
    }
});
        
        // Inicializar eventos para filas existentes
        inicializarEventosFilaRuta();
        
        // Evento para mostrar/ocultar fechas personalizadas en exportación
        $('input[name="periodo"]').change(function() {
            if ($(this).val() === 'personalizado') {
                $('#periodo-personalizado-fechas').show();
            } else {
                $('#periodo-personalizado-fechas').hide();
            }
        });
        
        // Evento para abrir modal de exportación
        $('#btn-exportar').click(function() {
            modalExportar.show();
        });
        
        // Evento para confirmar exportación
        $('#btn-confirmar-exportar').click(function() {
            // Obtener datos del formulario
            let periodo = $('input[name="periodo"]:checked').val();
            let fechaDesde = $('#fecha-desde').val();
            let fechaHasta = $('#fecha-hasta').val();
            let vendedor = $('#exportar-vendedor').val();
            let zona = $('#exportar-zona').val();
            let canal = $('#exportar-canal').val();
            
            // Validar fechas si es periodo personalizado
            if (periodo === 'personalizado') {
                if (!fechaDesde || !fechaHasta) {
                    Swal.fire({
                        title: 'Error',
                        text: 'Debe seleccionar fechas desde y hasta',
                        icon: 'error',
                        confirmButtonText: 'Aceptar'
                    });
                    return;
                }
                
                if (new Date(fechaDesde) > new Date(fechaHasta)) {
                    Swal.fire({
                        title: 'Error',
                        text: 'La fecha desde no puede ser mayor que la fecha hasta',
                        icon: 'error',
                        confirmButtonText: 'Aceptar'
                    });
                    return;
                }
            }
            
            // Mostrar indicador de carga
            $('#btn-confirmar-exportar').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Exportando...');
            
            // Enviar datos al servidor
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    action: 'exportar_excel',
                    periodo: periodo,
                    fecha_desde: fechaDesde,
                    fecha_hasta: fechaHasta,
                    vendedor: vendedor,
                    zona: zona,
                    canal: canal
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Cerrar modal
                        modalExportar.hide();
                        
                        // Mostrar mensaje de éxito
                        Swal.fire({
                            title: '¡Exportación exitosa!',
                            text: 'El archivo Excel se ha generado correctamente',
                            icon: 'success',
                            confirmButtonText: 'Descargar',
                            confirmButtonColor: '#28a745'
                        }).then(() => {
                            // Descargar archivo
                            window.location.href = response.file_url;
                        });
                    } else {
                        Swal.fire({
                            title: 'Error',
                            text: response.message || 'Error al exportar a Excel',
                            icon: 'error',
                            confirmButtonText: 'Aceptar'
                        });
                    }
                    
                    $('#btn-confirmar-exportar').prop('disabled', false).html('Exportar');
                },
                error: function() {
                    Swal.fire({
                        title: 'Error',
                        text: 'Error de comunicación con el servidor',
                        icon: 'error',
                        confirmButtonText: 'Aceptar'
                    });
                    $('#btn-confirmar-exportar').prop('disabled', false).html('Exportar');
                }
            });
        });
    });













    document.getElementById('sidebar-toggle').addEventListener('click', function() {
            document.querySelector('.dashboard-container').classList.toggle('sidebar-collapsed');
        });
        
        // Actualizar hora en tiempo real
        function actualizarHora() {
            const ahora = new Date();
            const horas = ahora.getHours().toString().padStart(2, '0');
            const minutos = ahora.getMinutes().toString().padStart(2, '0');
            document.querySelector('.date-time span').textContent = 
                document.querySelector('.date-time span').textContent.split('|')[0] + '| ' + horas + ':' + minutos;
        }
        
        setInterval(actualizarHora, 60000); // Actualizar cada minuto
        
        // Animación para las tarjetas de estadísticas
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach((card, index) => {
            setTimeout(() => {
                card.classList.add('animate');
            }, 100 * index);
        });

        // Toggle notificaciones
        const notificationBtn = document.getElementById('notification-btn');
        const notificationDropdown = document.getElementById('notification-dropdown');
        
        if (notificationBtn && notificationDropdown) {
            notificationBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.classList.toggle('show');
            });
            
            document.addEventListener('click', function(e) {
                if (!notificationDropdown.contains(e.target) && e.target !== notificationBtn) {
                    notificationDropdown.classList.remove('show');
                }
            });
        }

        // Búsqueda
        const searchInput = document.getElementById('search-input');
        if (searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    window.location.href = 'busqueda.php?q=' + encodeURIComponent(this.value);
                }
            });
        }

        // Checkbox de tareas
        const taskCheckboxes = document.querySelectorAll('.task-checkbox input');
        taskCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const taskId = this.id.replace('task', '');
                if (this.checked) {
                    // Enviar solicitud AJAX para marcar tarea como completada
                    fetch('funciones/actualizar_tarea.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'id=' + taskId + '&estado=completada'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            this.closest('.task-item').style.opacity = '0.6';
                        }
                    });
                }
            });
        });






</script>

<!-- Estilos adicionales para Select2 -->
<style>
  /* Ajustes para Select2 en modo oscuro */
  .select2-container--bootstrap-5 .select2-selection {
      background-color: var(--card-bg);
      border-color: var(--border-color);
      color: var(--text-color);
  }
  
  .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
      color: var(--text-color);
  }
  
  .select2-container--bootstrap-5 .select2-dropdown {
      background-color: var(--card-bg);
      border-color: var(--border-color);
  }
  
  .select2-container--bootstrap-5 .select2-dropdown .select2-results__option {
      color: var(--text-color);
  }
  
  .select2-container--bootstrap-5 .select2-dropdown .select2-results__option--highlighted {
      background-color: var(--primary-color);
      color: white;
  }
  
  .select2-container--bootstrap-5 .select2-dropdown .select2-results__option[aria-selected=true] {
      background-color: var(--primary-color);
      color: white;
  }
  
  .select2-container--bootstrap-5 .select2-search--dropdown .select2-search__field {
      background-color: var(--card-bg);
      border-color: var(--border-color);
      color: var(--text-color);
  }
  
  /* Ajustes para el modal */
  .modal-content .select2-container {
      z-index: 1056 !important;
  }

</style>
