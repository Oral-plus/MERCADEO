<?php
include_once 'db_connection.php';

/**
* Obtiene las estadísticas del usuario
* 
* @param int $usuario_id ID del usuario
* @return array Estadísticas del usuario
*/
function obtenerEstadisticasUsuario($usuario_id) {
    global $conn;
    
    // Valores por defecto
    $estadisticas = [
        'rutas_activas' => 0,
        'clientes_asignados' => 0,
        'porcentaje_cumplimiento' => 0,
        'tareas_pendientes' => 0
    ];
    
    try {
        // Si el ID es 1, no aplicamos filtros (administrador)
        if ($usuario_id == 1) {
            // Consulta para obtener todas las rutas activas
            $sql_rutas = "SELECT COUNT(*) as total FROM rutas WHERE estado = 'activa'";
            $stmt_rutas = sqlsrv_query($conn, $sql_rutas);
            
            // Consulta para obtener todos los clientes asignados
            $sql_clientes = "SELECT COUNT(*) as total FROM clientes_ruta";
            $stmt_clientes = sqlsrv_query($conn, $sql_clientes);
            
            // Consulta para porcentaje de cumplimiento global
            $sql_cumplimiento = "SELECT 
                                CASE 
                                    WHEN COUNT(*) = 0 THEN 0
                                    ELSE CAST(SUM(CASE WHEN estado = 'completada' THEN 1 ELSE 0 END) * 100.0 / COUNT(*) AS INT)
                                END as porcentaje
                                FROM visitas 
                                WHERE fecha >= DATEADD(day, -30, GETDATE())";
            $stmt_cumplimiento = sqlsrv_query($conn, $sql_cumplimiento);
            
            // Consulta para todas las tareas pendientes
            $sql_tareas = "SELECT COUNT(*) as total FROM tareas WHERE estado = 'pendiente'";
            $stmt_tareas = sqlsrv_query($conn, $sql_tareas);
        } else {
            // Consulta para obtener rutas activas del usuario específico
            $sql_rutas = "SELECT COUNT(*) as total FROM rutas WHERE usuario_id = ? AND estado = 'activa'";
            $stmt_rutas = sqlsrv_query($conn, $sql_rutas, array($usuario_id));
            
            // Consulta para obtener clientes asignados al usuario específico
            $sql_clientes = "SELECT COUNT(*) as total FROM clientes_ruta WHERE usuario_id = ?";
            $stmt_clientes = sqlsrv_query($conn, $sql_clientes, array($usuario_id));
            
            // Consulta para porcentaje de cumplimiento del usuario específico
            $sql_cumplimiento = "SELECT 
                                CASE 
                                    WHEN COUNT(*) = 0 THEN 0
                                    ELSE CAST(SUM(CASE WHEN estado = 'completada' THEN 1 ELSE 0 END) * 100.0 / COUNT(*) AS INT)
                                END as porcentaje
                                FROM visitas 
                                WHERE usuario_id = ? AND fecha >= DATEADD(day, -30, GETDATE())";
            $stmt_cumplimiento = sqlsrv_query($conn, $sql_cumplimiento, array($usuario_id));
            
            // Consulta para tareas pendientes del usuario específico
            $sql_tareas = "SELECT COUNT(*) as total FROM tareas WHERE usuario_id = ? AND estado = 'pendiente'";
            $stmt_tareas = sqlsrv_query($conn, $sql_tareas, array($usuario_id));
        }
        
        // Procesar resultados de rutas activas
        if ($stmt_rutas && $row = sqlsrv_fetch_array($stmt_rutas, SQLSRV_FETCH_ASSOC)) {
            $estadisticas['rutas_activas'] = (int)$row['total'];
        }
        if ($stmt_rutas) sqlsrv_free_stmt($stmt_rutas);

        // Procesar resultados de clientes asignados
        if ($stmt_clientes && $row = sqlsrv_fetch_array($stmt_clientes, SQLSRV_FETCH_ASSOC)) {
            $estadisticas['clientes_asignados'] = (int)$row['total'];
        }
        if ($stmt_clientes) sqlsrv_free_stmt($stmt_clientes);

        // Procesar resultados de porcentaje de cumplimiento
        if ($stmt_cumplimiento && $row = sqlsrv_fetch_array($stmt_cumplimiento, SQLSRV_FETCH_ASSOC)) {
            $estadisticas['porcentaje_cumplimiento'] = (int)$row['porcentaje'];
        }
        if ($stmt_cumplimiento) sqlsrv_free_stmt($stmt_cumplimiento);

        // Procesar resultados de tareas pendientes
        if ($stmt_tareas && $row = sqlsrv_fetch_array($stmt_tareas, SQLSRV_FETCH_ASSOC)) {
            $estadisticas['tareas_pendientes'] = (int)$row['total'];
        }
        if ($stmt_tareas) sqlsrv_free_stmt($stmt_tareas);

    } catch (Exception $e) {
        error_log("Error en obtenerEstadisticasUsuario: " . $e->getMessage());
    }
    
    return $estadisticas;
}

/**
* Obtiene las rutas activas del usuario
* 
* @param int $usuario_id ID del usuario
* @return array Rutas activas
*/
function obtenerRutasActivas($usuario_id) {
    // Usar la función de conexión existente
    $conn = conectarBaseDatos();
    
    if ($conn === false) {
        return [];
    }
    
    // Si el ID es 1, no aplicamos filtros (administrador)
    if ($usuario_id == 1) {
        // Consulta de todas las rutas activas sin filtrar por vendedor_id
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
                    (SELECT COUNT(*) FROM Ruta.dbo.rutas_clientes WHERE ruta_id = r.id) as clientes,
                    (SELECT COUNT(*) FROM Ruta.dbo.rutas_clientes WHERE ruta_id = r.id AND estado = 'completado') as clientes_completados
                FROM Ruta.dbo.rutas r
                WHERE r.estado = 'activa'
                ORDER BY r.fecha_programada, r.hora_visita ASC";
        
        $params = array();
        $stmt = sqlsrv_query($conn, $sql);
    } else {
        // Obtener el NIT del usuario
        $nit = obtenerNitUsuario($usuario_id, $conn);
        
        if (!$nit) {
            error_log("Error: No se pudo obtener el NIT para el usuario ID: " . $usuario_id);
            return [];
        }
        
        // Consulta de rutas activas filtradas por vendedor_id
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
                    (SELECT COUNT(*) FROM Ruta.dbo.rutas_clientes WHERE ruta_id = r.id) as clientes,
                    (SELECT COUNT(*) FROM Ruta.dbo.rutas_clientes WHERE ruta_id = r.id AND estado = 'completado') as clientes_completados
                FROM Ruta.dbo.rutas r
                WHERE r.vendedor_id = ?
                AND r.estado = 'activa'
                ORDER BY r.fecha_programada, r.hora_visita ASC";
        
        $params = array($nit);
        $stmt = sqlsrv_query($conn, $sql, $params);
    }
    
    if ($stmt === false) {
        error_log("Error al ejecutar consulta de rutas activas: " . print_r(sqlsrv_errors(), true));
        return [];
    }

    $rutas = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Calcular el progreso de la ruta
        $progreso = ($row['clientes'] > 0) ? round(($row['clientes_completados'] / $row['clientes']) * 100) : 0;
        
        // Calcular la distancia - puedes ajustar esto según necesites
        $distancia = obtenerDistanciaRuta($row['id']);
        
        $rutas[] = [
            'id' => $row['id'],
            'nombre' => $row['nombre'],
            'estado' => $row['estado'],
            'fecha_programada' => $row['fecha_programada'],
            'hora_visita' => $row['hora_visita'],
            'ciudad' => $row['ciudad'],
            'cliente_id' => $row['cliente_id'],
            'vendedor_id' => $row['vendedor_id'],
            'dia_semana' => $row['dia_semana'],
            'clientes' => $row['clientes'],
            'progreso' => $progreso,
            'distancia' => $distancia
        ];
    }

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);

    return $rutas;
}

/**
* Obtiene las tareas pendientes del usuario
* 
* @param int $usuario_id ID del usuario
* @return array Tareas pendientes
*/
function obtenerTareasPendientes($usuario_id) {
    global $conn;
    $tareas = [];
    
    try {
        // Si el ID es 1, no aplicamos filtros (administrador) y mostramos quién creó cada tarea
        if ($usuario_id == 1) {
            $sql = "SELECT 
                        t.id, 
                        t.titulo, 
                        t.fecha_vencimiento,
                        t.prioridad,
                        t.descripcion,
                        t.usuario_id,
                        u.nombre as creador_nombre
                    FROM tareas t
                    LEFT JOIN usuarios_ruta u ON t.usuario_id = u.id
                    WHERE t.estado = 'pendiente'
                    ORDER BY 
                        CASE 
                            WHEN t.prioridad = 'alta' THEN 1
                            WHEN t.prioridad = 'media' THEN 2
                            WHEN t.prioridad = 'baja' THEN 3
                        END,
                        t.fecha_vencimiento ASC";
            
            $stmt = sqlsrv_query($conn, $sql);
        } else {
            $sql = "SELECT 
                        id, 
                        titulo, 
                        fecha_vencimiento,
                        prioridad,
                        descripcion,
                        usuario_id
                    FROM tareas
                    WHERE usuario_id = ? AND estado = 'pendiente'
                    ORDER BY 
                        CASE 
                            WHEN prioridad = 'alta' THEN 1
                            WHEN prioridad = 'media' THEN 2
                            WHEN prioridad = 'baja' THEN 3
                        END,
                        fecha_vencimiento ASC";
            
            $stmt = sqlsrv_query($conn, $sql, array($usuario_id));
        }
        
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
                
                $tarea = [
                    'id' => $row['id'],
                    'titulo' => $row['titulo'],
                    'fecha_vencimiento' => $row['fecha_vencimiento'],
                    'fecha_formateada' => $fecha_formateada,
                    'prioridad' => strtolower($row['prioridad']),
                    'descripcion' => $row['descripcion'] ?? '',
                    'usuario_id' => $row['usuario_id']
                ];
                
                // Si es administrador (ID 1), incluimos el nombre del creador
                if ($usuario_id == 1 && isset($row['creador_nombre'])) {
                    $tarea['creador_nombre'] = $row['creador_nombre'];
                }
                
                $tareas[] = $tarea;
            }
            sqlsrv_free_stmt($stmt);
        }
    } catch (Exception $e) {
        error_log("Error en obtenerTareasPendientes: " . $e->getMessage());
    }
    
    return $tareas;
}
?>