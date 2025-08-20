<?php
// Archivo: tabla.php
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
include 'header.php';
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

// ==================== FUNCIONES PARA CLIENTES SAP ====================

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

// Obtener información completa de un cliente SAP
function obtenerClienteSAP($cliente_id) {
    $connSAP = conexionSAP();
    if (!$connSAP) return null;
    $sql = "SELECT T0.[CardCode], T0.[CardFName],T0.[CardName],T1.[SlpCode], T1.[SlpName], 
                   T0.[City], T0.[Phone], T0.[Territory], T0.[GroupCode]
            FROM OCRD T0  
            INNER JOIN OSLP T1 ON T0.[SlpCode] = T1.[SlpCode] 
            WHERE T0.[CardCode] = ? AND T0.[validFor] = 'Y'";
    $params = array($cliente_id);
    $stmt = sqlsrv_query($connSAP, $sql, $params);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($connSAP);
        return $row;
    }
    if ($stmt) sqlsrv_free_stmt($stmt);
    sqlsrv_close($connSAP);
    return null;
}

// Obtener documentos de un cliente SAP (facturas, pedidos, entregas últimos 3 meses)
function obtenerDocumentosClienteSAP($cliente_id) {
    $connSAP = conexionSAP();
    $documentos = array();
    if (!$connSAP) return $documentos;

    // Facturas
    $sql_facturas = "SELECT T0.DocNum, T0.DocDate, T0.DocTotal, T0.DocStatus, 'Factura' as TipoDoc
                     FROM OINV T0
                     WHERE T0.CardCode = ? AND T0.DocDate >= DATEADD(month, -3, GETDATE())
                     ORDER BY T0.DocDate DESC";
    $stmt_facturas = sqlsrv_query($connSAP, $sql_facturas, array($cliente_id));
    if ($stmt_facturas) {
        while ($row = sqlsrv_fetch_array($stmt_facturas, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['DocDate']) && $row['DocDate']) {
                $row['DocDate'] = $row['DocDate']->format('d/m/Y');
            }
            $documentos[] = $row;
        }
        sqlsrv_free_stmt($stmt_facturas);
    }

    // Pedidos
    $sql_pedidos = "SELECT T0.DocNum, T0.DocDate, T0.DocTotal, T0.DocStatus, 'Pedido' as TipoDoc
                    FROM ORDR T0
                    WHERE T0.CardCode = ? AND T0.DocDate >= DATEADD(month, -3, GETDATE())
                    ORDER BY T0.DocDate DESC";
    $stmt_pedidos = sqlsrv_query($connSAP, $sql_pedidos, array($cliente_id));
    if ($stmt_pedidos) {
        while ($row = sqlsrv_fetch_array($stmt_pedidos, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['DocDate']) && $row['DocDate']) {
                $row['DocDate'] = $row['DocDate']->format('d/m/Y');
            }
            $documentos[] = $row;
        }
        sqlsrv_free_stmt($stmt_pedidos);
    }

    // Entregas
    $sql_entregas = "SELECT T0.DocNum, T0.DocDate, T0.DocTotal, T0.DocStatus, 'Entrega' as TipoDoc
                     FROM ODLN T0
                     WHERE T0.CardCode = ? AND T0.DocDate >= DATEADD(month, -3, GETDATE())
                     ORDER BY T0.DocDate DESC";
    $stmt_entregas = sqlsrv_query($connSAP, $sql_entregas, array($cliente_id));
    if ($stmt_entregas) {
        while ($row = sqlsrv_fetch_array($stmt_entregas, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['DocDate']) && $row['DocDate']) {
                $row['DocDate'] = $row['DocDate']->format('d/m/Y');
            }
            $documentos[] = $row;
        }
        sqlsrv_free_stmt($stmt_entregas);
    }
    sqlsrv_close($connSAP);
    return $documentos;
}

// ==================== FUNCIONES CRUD PARA RUTAS ====================

// Función para obtener rutas por rango de fechas y NIT del usuario
// Si es admin, obtiene todas las rutas sin filtrar por NIT
function obtenerRutasPorRangoFechasYNit($fechaInicio, $fechaFin, $nit, $es_admin = false) {
    $conn = conectarBaseDatos();
    
    if ($conn === false) {
        return [];
    }
    
    if ($es_admin) {
        // Si es admin, obtener todas las rutas sin filtrar por vendedor_id
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

// Función para obtener todas las rutas filtradas por NIT
// Si es admin, obtiene todas las rutas sin filtrar por NIT
function obtenerTodasLasRutasPorNit($nit, $es_admin = false) {
    $conn = conectarBaseDatos();
    
    if ($conn === false) {
        return [];
    }
    
    if ($es_admin) {
        // Si es admin, obtener todas las rutas sin filtrar por vendedor_id
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
                ORDER BY r.fecha_programada, r.hora_visita ASC";
        
        $stmt = sqlsrv_query($conn, $sql);
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
                WHERE r.vendedor_id = ?
                ORDER BY r.fecha_programada, r.hora_visita ASC";
        
        $params = array($nit);
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
            FROM Ruta.dbo.rutas1
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

// Función para crear una nueva ruta
function crearRuta($datos) {
    $conn = conectarBaseDatos();
    
    if ($conn === false) {
        return false;
    }
    
    $sql = "INSERT INTO Ruta.dbo.rutas1 (
                nombre, 
                estado, 
                ciudad, 
                hora_visita, 
                fecha_programada, 
                cliente_id, 
                vendedor_id, 
                notas, 
                direccion
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $params = array(
        $datos['nombre'],
        $datos['estado'],
        $datos['ciudad'],
        $datos['hora_visita'],
        $datos['fecha_programada'],
        $datos['cliente_id'],
        $datos['vendedor_id'],
        $datos['notas'] ?? null,
        $datos['direccion'] ?? null
    );
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        error_log("Error al crear ruta: " . print_r(sqlsrv_errors(), true));
        return false;
    }

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);

    return true;
}

// Función para actualizar una ruta existente
function actualizarRuta($ruta_id, $datos) {
    $conn = conectarBaseDatos();
    
    if ($conn === false) {
        return false;
    }
    
    $sql = "UPDATE Ruta.dbo.rutas1 SET 
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

// Función para actualizar el estado de una ruta
function actualizarEstadoRuta($ruta_id, $estado) {
    $conn = conectarBaseDatos();
    
    if ($conn === false) {
        return false;
    }
    
    $sql = "UPDATE Ruta.dbo.rutas1 SET estado = ? WHERE id = ?";
    $params = array($estado, $ruta_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        error_log("Error al actualizar estado de ruta: " . print_r(sqlsrv_errors(), true));
        return false;
    }

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);

    return true;
}

// Función para eliminar una ruta
function eliminarRuta($ruta_id) {
    $conn = conectarBaseDatos();
    
    if ($conn === false) {
        return false;
    }
    
    $sql = "DELETE FROM Ruta.dbo.rutas1 WHERE id = ?";
    
    $params = array($ruta_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        error_log("Error al eliminar ruta: " . print_r(sqlsrv_errors(), true));
        return false;
    }

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);

    return true;
}

// ==================== CONFIGURACIÓN DE LA PÁGINA ====================

// Manejar navegación entre semanas
$semanaOffset = isset($_GET['semana']) ? intval($_GET['semana']) : 0;
$fechaActual = new DateTime();
$fechaActual->modify($semanaOffset . ' weeks');

// Calcular fecha de inicio y fin de la semana
$inicioSemana = clone $fechaActual;
$inicioSemana->modify('monday this week');
$finSemana = clone $inicioSemana;
$finSemana->modify('+6 days');

// Formatear fechas para SQL Server
$inicioSemanaSQL = $inicioSemana->format('Y-m-d');
$finSemanaSQL = $finSemana->format('Y-m-d');

// Calcular semanas anterior y siguiente
$semanaAnterior = $semanaOffset - 1;
$semanaSiguiente = $semanaOffset + 1;

// Formatear fechas para mostrar
$inicioSemanaFormato = $inicioSemana->format('d/m/Y');
$finSemanaFormato = $finSemana->format('d/m/Y');

// Configuración de la página
$tituloPagina = "Gestión de Rutas";

// Definir los días de la semana
$diasSemana = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

// Determinar si mostrar todas las rutas o filtrar por día
$mostrarTodas = isset($_GET['mostrar']) && $_GET['mostrar'] === 'todas';

// Obtener el NIT del usuario para mostrar en la interfaz
$conn = conectarBaseDatos();
$nit_usuario = ($conn && $usuario_id) ? obtenerNitUsuario($usuario_id, $conn) : null;
if ($conn) sqlsrv_close($conn);

// Obtener tema actual del usuario
$tema_actual = $_SESSION['tema'] ?? 'claro';

// Obtener rutas según la vista seleccionada y filtradas por NIT (o todas si es admin)
$rutasSemana = [];
if (!$mostrarTodas) {
    // Obtener rutas para la semana seleccionada
    $rutasSemana = obtenerRutasPorRangoFechasYNit($inicioSemanaSQL, $finSemanaSQL, $nit_usuario, $es_admin);
} else {
    // Obtener todas las rutas
    $todasLasRutas = obtenerTodasLasRutasPorNit($nit_usuario, $es_admin);
}

// Procesar acciones CRUD si se solicitan
$mensaje = '';
$tipoMensaje = '';

// Procesar la actualización de ruta desde el modal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accion'])) {
        switch ($_POST['accion']) {
            case 'eliminar':
                if (isset($_POST['ruta_id']) && eliminarRuta($_POST['ruta_id'])) {
                    $mensaje = "Ruta eliminada correctamente.";
                    $tipoMensaje = "success";
                } else {
                    $mensaje = "Error al eliminar la ruta.";
                    $tipoMensaje = "danger";
                }
                break;
            case 'actualizar_estado':
                if (isset($_POST['ruta_id']) && isset($_POST['estado'])) {
                    $nuevoEstado = $_POST['estado'] == '1' ? 'Completado' : 'Pendiente';
                    if (actualizarEstadoRuta($_POST['ruta_id'], $nuevoEstado)) {
                        $mensaje = "Estado de la ruta actualizado correctamente.";
                        $tipoMensaje = "success";
                    } else {
                        $mensaje = "Error al actualizar el estado de la ruta.";
                        $tipoMensaje = "danger";
                    }
                }
                break;
            case 'actualizar_ruta':
                if (isset($_POST['ruta_id'])) {
                    $ruta_id = $_POST['ruta_id'];
                    $datos = array(
                        'nombre' => $_POST['nombre'],
                        'estado' => $_POST['estado'],
                        'ciudad' => $_POST['ciudad'],
                        'hora_visita' => $_POST['hora_visita'],
                        'fecha_programada' => $_POST['fecha_programada'],
                        'cliente_id' => $_POST['cliente_id'],
                        'vendedor_id' => $_POST['vendedor_id'],
                        'notas' => $_POST['notas'] ?? null,
                        'direccion' => $_POST['direccion'] ?? null
                    );
                    
                    if (actualizarRuta($ruta_id, $datos)) {
                        $mensaje = "Ruta actualizada correctamente.";
                        $tipoMensaje = "success";
                    } else {
                        $mensaje = "Error al actualizar la ruta.";
                        $tipoMensaje = "danger";
                    }
                }
                break;
        }
    }
}

// Contar estadísticas
$rutasActivas = 0;
$rutasPendientes = 0;
$rutasCompletadas = 0;

if ($mostrarTodas) {
    foreach ($todasLasRutas as $ruta) {
        if ($ruta['estado'] === 'Completado') {
            $rutasCompletadas++;
        } elseif ($ruta['estado'] === 'Pendiente') {
            $rutasPendientes++;
        }
    }
    $totalRutas = count($todasLasRutas);
} else {
    $totalRutas = count($rutasSemana);
    foreach ($rutasSemana as $ruta) {
        if ($ruta['estado'] === 'Completado') {
            $rutasCompletadas++;
        } elseif ($ruta['estado'] === 'Pendiente') {
            $rutasPendientes++;
        }
    }
}

// Calcular porcentaje de cumplimiento
$porcentajeCumplimiento = $totalRutas > 0 ? round(($rutasCompletadas / $totalRutas) * 100) : 0;

// Obtener cantidad de clientes SAP (si está disponible el NIT)
$clientesSAP = $nit_usuario ? count(obtenerClientesPorNit($nit_usuario, $es_admin)) : 0;

// Organizar rutas por día de la semana para la vista semanal
$rutasPorDia = [];
if (!$mostrarTodas) {
    // Inicializar array para cada día
    foreach ($diasSemana as $dia) {
        $rutasPorDia[$dia] = [];
    }
    
    // Mapeo de días en inglés a español
    $diasMapInverso = [
        'Monday' => 'lunes',
        'Tuesday' => 'martes',
        'Wednesday' => 'miércoles',
        'Thursday' => 'jueves',
        'Friday' => 'viernes',
        'Saturday' => 'sábado',
    ];
    
    // Agrupar rutas por día
    foreach ($rutasSemana as $ruta) {
        $diaSemana = $diasMapInverso[$ruta['dia_semana']] ?? 'lunes';
        $rutasPorDia[$diaSemana][] = $ruta;
    }
}

// Procesar solicitud AJAX para obtener datos de ruta
if (isset($_GET['ajax']) && $_GET['ajax'] === 'obtener_ruta' && isset($_GET['id'])) {
    $ruta_id = $_GET['id'];
    $ruta = obtenerRutaPorId($ruta_id);
    
    if ($ruta) {
        header('Content-Type: application/json');
        echo json_encode($ruta);
        exit;
    } else {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['error' => 'Ruta no encontrada']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?php echo htmlspecialchars($tema_actual); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="estilos-adicionales.css">
    <link rel="stylesheet" href="estilostabla.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($tituloPagina); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Estilos base (tema claro) */

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
            --modal-bg: #1e293b;
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
                --modal-bg: #1e293b;
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

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            font-weight: 600;
            color: #fff;
            background-color: var(--secondary-color);
        }

        tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }

        [data-theme="oscuro"] tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .day-header {
            background-color: var(--secondary-color);
            font-weight: 600;
            color: #fff;
            text-align: center;
            cursor: pointer;
        }

       

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-active {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .status-inactive {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
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
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
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

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #dc2626;
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background-color: #059669;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .action-buttons .btn {
            padding: 0.375rem 0.5rem;
        }

        .no-routes {
            text-align: center;
            padding: 2rem;
            color: var(--text-muted);
            font-style: italic;
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
        }

        .pagination-info {
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .pagination-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .pagination-buttons .btn {
            padding: 0.375rem 0.75rem;
        }

        .footer {
            text-align: center;
            padding: 1.5rem 0;
            color: var(--text-muted);
            font-size: 0.875rem;
            border-top: 1px solid var(--border-color);
            margin-top: 2rem;
        }

        /* Estilos para días colapsables */
        .day-content {
            display: none;
        }

        .day-header .toggle-icon {
            margin-left: 8px;
            transition: transform 0.3s ease;
        }

        .day-header.expanded .toggle-icon {
            transform: rotate(180deg);
        }

        .day-header.expanded + .day-content {
            display: table-row;
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

        .tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            color: var(--text-muted);
            text-decoration: none;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }

        .tab:hover {
            color: var(--primary-color);
        }

        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
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
        }

        .card-title {
            font-weight: 600;
            margin: 0;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Estilos para el checkbox de estado */
        .estado-checkbox {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 20px;
        }

        .estado-checkbox input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .estado-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 20px;
        }

        .estado-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .estado-slider {
            background-color: var(--success-color);
        }

        input:checked + .estado-slider:before {
            transform: translateX(20px);
        }

        .estado-label {
            margin-left: 10px;
            font-size: 0.875rem;
            color: var(--text-color);
        }

        .estado-container {
            display: flex;
            align-items: center;
        }

        .form-estado {
            display: inline;
            margin: 0;
        }

        /* Estilos para el botón de exportar */
        .btn-export {
            background-color: #10b981;
            color: #ffffff;
            padding: 10px 20px;
            border: none;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.3s ease, transform 0.2s ease;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .btn-export:hover {
            background-color: #059669;
            transform: translateY(-2px);
        }

        .export-button-container {
            display: flex;
            justify-content: flex-end;
        }

        /* Estilos para el botón de nueva ruta */
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

        /* Estilos para el modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--modal-overlay);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            overflow-y: auto;
            padding: 2rem 1rem;
        }

        .modal-container {
            background-color: var(--modal-bg);
            border-radius: 0.5rem;
            box-shadow: var(--modal-shadow);
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-color);
            margin: 0;
        }

        .modal-close {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s;
        }

        .modal-close:hover {
            color: var(--danger-color);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            padding: 1.25rem 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        /* Estilos para el formulario del modal */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-color);
        }

        .form-control {
            width: 100%;
            padding: 0.625rem 0.75rem;
            font-size: 0.875rem;
            line-height: 1.5;
            color: var(--text-color);
            background-color: var(--bg-color);
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: 0;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        /* Indicador de carga */
        .loading-spinner {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .welcome-card p {
                max-width: 100%;
            }

            .welcome-card .map-icon {
                display: none;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                overflow-x: auto;
                white-space: nowrap;
                -webkit-overflow-scrolling: touch;
            }

            .tab {
                padding: 0.75rem 1rem;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            .modal-container {
                width: 95%;
            }
        }
    </style>
</head>
<body>
        
        <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipoMensaje; ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
        <?php endif; ?>
        
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-calendar-alt"></i> Rutas Programadas
            </h2>
            <div class="tabs">
                <a href="?semana=<?php echo $semanaOffset; ?>" class="tab <?php echo !$mostrarTodas ? 'active' : ''; ?>">Por Semana</a>
                <a href="?mostrar=todas" class="tab <?php echo $mostrarTodas ? 'active' : ''; ?>">Todas las Rutas</a>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <?php if (!$mostrarTodas): ?>
                <div class="card-title">Semana: <?php echo $inicioSemanaFormato; ?> - <?php echo $finSemanaFormato; ?></div>
                <?php else: ?>
                <div class="card-title">Listado Completo de Rutas</div>
                <?php endif; ?>
            </div>
            
            <div class="card-body">
                <?php if (!$mostrarTodas): ?>
                    <?php
    $semanaActual = isset($_GET['semana']) ? intval($_GET['semana']) : 0;
?>

<div class="pagination">
    <div class="pagination-buttons">
        <a href="?semana=<?php echo $semanaAnterior; ?>" 
           class="btn <?php echo ($semanaActual == $semanaAnterior) ? 'btn-primary' : 'btn-secondary'; ?>">
            <i class="fas fa-chevron-left"></i> Semana Anterior
        </a>

        <a href="?semana=0" 
           class="btn <?php echo ($semanaActual == 0) ? 'btn-primary' : 'btn-secondary'; ?>">
            Semana Actual
        </a>

        <a href="?semana=<?php echo $semanaSiguiente; ?>" 
           class="btn <?php echo ($semanaActual == $semanaSiguiente) ? 'btn-primary' : 'btn-secondary'; ?>">
            Semana Siguiente <i class="fas fa-chevron-right"></i>
        </a>
    </div>
</div>
<div class="export-button-container" style="margin-top: 1rem; margin-bottom: 1rem;">
    <a href="export-to-excel.php" class="btn-export">
        <i class="fas fa-file-excel"></i> Exportar a Excel
    </a>
</div>

<?php if ($usuario_id != 1): ?>
    <a href="nueva-ruta.php" class="btn-dashboard">
        <i class="fas fa-plus-circle"></i> Registrar Nueva Ruta
    </a>
<?php endif; ?>
                
                <br><br>
                
<!-- Vista por días de la semana con días colapsables -->
<table>
    <thead>
        <tr>
            <th>Día</th>
            <th>Nombre</th>
            <th>Estado</th>
            <th>Ciudad</th>
            <th>Hora</th>
            <th>Fecha</th>
            <th>Cliente</th>
            <?php if ($es_admin): ?>
            <th>Mercaderista</th>
            <?php endif; ?>
           
        </tr>
    </thead>
    <tbody>
        <?php
        // Iterar por cada día de la semana
        foreach ($diasSemana as $dia) {
            // Calcular la fecha específica para este día en la semana seleccionada
            $fechaDia = clone $inicioSemana;
            $diferenciaEnDias = array_search($dia, $diasSemana);
            $fechaDia->modify("+$diferenciaEnDias days");
            
            // Obtener las rutas para este día
            $rutasDia = $rutasPorDia[$dia] ?? [];
            
            // Generar un ID único para este día
            $diaId = 'dia-' . strtolower($dia);
            
            // Determinar si hay rutas para este día
            $tieneRutas = !empty($rutasDia);
            
            // Mostrar el encabezado del día (colapsable)
            echo "<tr>";
            echo "<td class='day-header' onclick=\"toggleDay('$diaId')\">";
            echo ucfirst($dia) . " <br><small>" . $fechaDia->format('d/m/Y') . "</small>";
            echo "<i class='fas fa-chevron-down toggle-icon'></i>";
            echo "</td>";
            echo "<td colspan='" . ($es_admin ? '8' : '7') . "'></td>";
            echo "</tr>";
            
            // Contenedor para las rutas del día (inicialmente oculto)
            echo "<tr id='$diaId' class='day-content' style='display: none;'>";
            echo "<td colspan='" . ($es_admin ? '9' : '8') . "'>";
            
            if ($tieneRutas) {
                echo "<table style='width: 100%;'>";
                echo "<tbody>";
                foreach ($rutasDia as $ruta) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($ruta['nombre']) . "</td>";
                    
                    // Celda con el checkbox de estado
                    echo "<td>";
                    echo "<form class='form-estado' method='post' onsubmit='return confirm(\"¿Estás seguro de que deseas actualizar el estado de esta ruta?\");'>";
                    echo "<input type='hidden' name='accion' value='actualizar_estado'>";
                    echo "<input type='hidden' name='ruta_id' value='" . $ruta['id'] . "'>";
                    echo "<div class='estado-container'>";
                    echo "<label class='estado-checkbox'>";
                    echo "<input type='checkbox' name='estado' value='1' " . ($ruta['estado'] == 'Completado' ? 'checked' : '') . " onchange='this.form.submit()'>";
                    echo "<span class='estado-slider'></span>";
                    echo "</label>";
                    echo "<span class='estado-label'>" . htmlspecialchars($ruta['estado']) . "</span>";
                    echo "</div>";
                    echo "</form>";
                    echo "</td>";
                    
                    echo "<td>" . htmlspecialchars($ruta['ciudad']) . "</td>";
                    echo "<td>" . (!empty($ruta['hora_visita']) ? htmlspecialchars($ruta['hora_visita']) : "No hay hora registrada") . "</td>";
                    echo "<td>" . htmlspecialchars($ruta['fecha_programada']) . "</td>";
                    echo "<td>" . htmlspecialchars($ruta['cliente_id']) . "</td>";
                    if ($es_admin && isset($ruta['vendedor_nombre'])) {
                        echo "<td>" . htmlspecialchars($ruta['vendedor_nombre']) . "</td>";
                    }
                    
                    // Botones de acción
                    echo "<td class='action-buttons'>";

                    echo "</form>";
                    echo "</td>";
                    
                    echo "</tr>";
                }
                echo "</tbody>";
                echo "</table>";
            } else {
                echo "<div class='no-routes'>No hay rutas programadas para este día</div>";
            }
            
            echo "</td>";
            echo "</tr>";
        }
        ?>
    </tbody>
</table>
<?php else: ?>
<!-- Vista de todas las rutas -->
<table>
    <thead>
        <tr>
            <th>Nombre</th>
            <th>Estado</th>
            <th>Ciudad</th>
            <th>Hora</th>
            <th>Fecha</th>
            <th>Cliente</th>
            <?php if ($es_admin): ?>
            <th>Mercaderista</th>
            <?php endif; ?>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($todasLasRutas)): ?>
        <tr>
            <td colspan="<?php echo $es_admin ? '8' : '7'; ?>" class="no-routes">No hay rutas programadas</td>
        </tr>
        <?php else: ?>
        <?php foreach ($todasLasRutas as $ruta): ?>
        <tr>
            <td><?php echo htmlspecialchars($ruta['nombre']); ?></td>
            
            <!-- Celda con el checkbox de estado -->
            <td>
                <form class="form-estado" method="post" onsubmit="return confirm('¿Estás seguro de que deseas actualizar el estado de esta ruta?');">
                    <input type="hidden" name="accion" value="actualizar_estado">
                    <input type="hidden" name="ruta_id" value="<?php echo $ruta['id']; ?>">
                    <div class="estado-container">
                        <label class="estado-checkbox">
                            <input type="checkbox" name="estado" value="1" <?php echo ($ruta['estado'] == 'Completado') ? 'checked' : ''; ?> onchange="this.form.submit()">
                            <span class="estado-slider"></span>
                        </label>
                        <span class="estado-label"><?php echo htmlspecialchars($ruta['estado']); ?></span>
                    </div>
                </form>
            </td>
            
            <td><?php echo htmlspecialchars($ruta['ciudad']); ?></td>
            <td>
                <?php echo !empty($ruta['hora_visita']) ? htmlspecialchars($ruta['hora_visita']) : 'No hay hora registrada'; ?>
            </td>
            <td><?php echo htmlspecialchars($ruta['fecha_programada']); ?></td>
            <td><?php echo htmlspecialchars($ruta['cliente_id']); ?></td>
            <?php if ($es_admin && isset($ruta['vendedor_nombre'])): ?>
            <td><?php echo htmlspecialchars($ruta['vendedor_nombre']); ?></td>
            <?php endif; ?>
            
            <!-- Botones de acción -->
            <td class="action-buttons">
                <button type="button" class="btn btn-primary btn-sm" onclick="abrirModalEditar(<?php echo $ruta['id']; ?>)"><i class="fas fa-edit"></i></button>
                <form method="post" onsubmit="return confirm('¿Estás seguro de que deseas eliminar esta ruta?');">
                    <input type="hidden" name="accion" value="eliminar">
                    <input type="hidden" name="ruta_id" value="<?php echo $ruta['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
<?php endif; ?>
            </div>
        </div>
      
        <!-- Modal para editar ruta -->
        <div id="modalEditar" class="modal-overlay">
            <div class="modal-container">
                <div class="modal-header">
                    <h3 class="modal-title">Editar Ruta</h3>
                    <button type="button" class="modal-close" onclick="cerrarModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="formEditarRuta" method="post">
                        <input type="hidden" name="accion" value="actualizar_ruta">
                        <input type="hidden" id="ruta_id" name="ruta_id" value="">
                        
                        <div class="form-group">
                            <label for="nombre" class="form-label">Nombre:</label>
                            <input type="text" id="nombre" name="nombre" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="estado" class="form-label">Estado:</label>
                            <select id="estado" name="estado" class="form-control">
                                <option value="Pendiente">Pendiente</option>
                                <option value="Completado">Completado</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="ciudad" class="form-label">Ciudad:</label>
                            <input type="text" id="ciudad" name="ciudad" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="hora_visita" class="form-label">Hora de Visita:</label>
                            <input type="time" id="hora_visita" name="hora_visita" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="fecha_programada" class="form-label">Fecha Programada:</label>
                            <input type="date" id="fecha_programada" name="fecha_programada" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="cliente_id" class="form-label">Cliente ID:</label>
                            <input type="text" id="cliente_id" name="cliente_id" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="vendedor_id" class="form-label">Vendedor ID:</label>
                            <input type="text" id="vendedor_id" name="vendedor_id" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="notas" class="form-label">Notas:</label>
                            <textarea id="notas" name="notas" class="form-control"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="direccion" class="form-label">Dirección:</label>
                            <input type="text" id="direccion" name="direccion" class="form-control">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="guardarRuta()">Guardar Cambios</button>
                </div>
            </div>
        </div>

        <!-- Indicador de carga -->
        <div id="loadingSpinner" class="loading-spinner">
            <div class="spinner"></div>
        </div>

        <div class="footer">
            <p>Sistema de Gestión de Rutas © <?php echo date('Y'); ?> SKY S.A.S</p>
        </div>
    </div>
    
    <script>
        // Script para manejar la expansión/colapso de los días
        function toggleDay(diaId) {
            const dayContent = document.getElementById(diaId);
            const isExpanded = dayContent.style.display !== 'none';
            
            // Ocultar todos los contenidos de días
            const allDayContents = document.querySelectorAll('.day-content');
            allDayContents.forEach(content => {
                content.style.display = 'none';
            });
            
            // Quitar la clase 'expanded' de todos los encabezados
            const allDayHeaders = document.querySelectorAll('.day-header');
            allDayHeaders.forEach(header => {
                header.classList.remove('expanded');
            });
            
            // Si el día no estaba expandido, mostrarlo
            if (!isExpanded) {
                dayContent.style.display = 'table-row';
                
                // Encontrar y añadir la clase 'expanded' al encabezado correspondiente
                const header = document.querySelector(`[onclick="toggleDay('${diaId}')"]`);
                if (header) {
                    header.classList.add('expanded');
                }
            }
        }
        
        // Script para resaltar la fila al pasar el mouse y manejar alertas
        document.addEventListener('DOMContentLoaded', function() {
            // Mostrar alerta y ocultarla después de 5 segundos
            const alertElement = document.querySelector('.alert');
            if (alertElement) {
                setTimeout(function() {
                    alertElement.style.opacity = '0';
                    alertElement.style.transition = 'opacity 0.5s';
                    setTimeout(function() {
                        alertElement.style.display = 'none';
                    }, 500);
                }, 5000);
            }
        });

        // Funciones para el modal de edición
        function abrirModalEditar(rutaId) {
            // Mostrar indicador de carga
            document.getElementById('loadingSpinner').style.display = 'flex';
            
            console.log("Abriendo modal para editar ruta ID:", rutaId);
            
            // Hacer una petición AJAX para obtener los datos de la ruta
            fetch(`?ajax=obtener_ruta&id=${rutaId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Error al obtener datos de la ruta');
                    }
                    return response.json();
                })
                .then(data => {
                    console.log("Datos recibidos:", data);
                    
                    // Llenar el formulario con los datos obtenidos
                    document.getElementById('ruta_id').value = data.id;
                    document.getElementById('nombre').value = data.nombre || '';
                    document.getElementById('estado').value = data.estado || 'Pendiente';
                    document.getElementById('ciudad').value = data.ciudad || '';
                    document.getElementById('hora_visita').value = data.hora_visita || '';
                    document.getElementById('fecha_programada').value = data.fecha_programada || '';
                    document.getElementById('cliente_id').value = data.cliente_id || '';
                    document.getElementById('vendedor_id').value = data.vendedor_id || '';
                    document.getElementById('notas').value = data.notas || '';
                    document.getElementById('direccion').value = data.direccion || '';
                    
                    // Ocultar indicador de carga
                    document.getElementById('loadingSpinner').style.display = 'none';
                    
                    // Mostrar el modal
                    document.getElementById('modalEditar').style.display = 'flex';
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al cargar los datos de la ruta. Por favor, inténtelo de nuevo.');
                    
                    // Ocultar indicador de carga
                    document.getElementById('loadingSpinner').style.display = 'none';
                });
        }

        function cerrarModal() {
            document.getElementById('modalEditar').style.display = 'none';
        }

        function guardarRuta() {
            // Validar el formulario
            const form = document.getElementById('formEditarRuta');
            if (form.checkValidity()) {
                // Mostrar indicador de carga
                document.getElementById('loadingSpinner').style.display = 'flex';
                
                form.submit();
            } else {
                // Mostrar mensajes de validación
                form.reportValidity();
            }
        }

        // Cerrar el modal si se hace clic fuera de él
        window.onclick = function(event) {
            const modal = document.getElementById('modalEditar');
            if (event.target === modal) {
                cerrarModal();
            }
        }
    </script>
</body>
</html>