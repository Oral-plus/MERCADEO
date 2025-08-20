<?php
// db_connection.php
$serverName = "HERCULES";
$connectionInfo = array("Database" => "Ruta", "UID" => "sa", "PWD" => "Sky2022*!");
$conn = sqlsrv_connect($serverName, $connectionInfo);

if (!$conn) {
    die("Error connecting to database: " . print_r(sqlsrv_errors(), true));
}

// Include functions file
include_once 'funciones/actualizar_ruta.php';

// Asegurarse de que las tablas existan
crearTablaRutas();

// Conexión a SAP
$serverName = "HERCULES";
$connectionInfo = array("Database" => "RBOSKY3", "UID" => "sa", "PWD" => "Sky2022*!");
$conn_sap = sqlsrv_connect($serverName, $connectionInfo);

// Verificar conexión
if (!$conn_sap) {
    $error_sap = "Error al conectar a SAP: " . print_r(sqlsrv_errors(), true);
}

// Obtener datos de clientes y vendedores de SAP
$clientes = [];
$vendedores = [];
$zonas = [];
$canales = [];
$clientes_vendedores = []; // Relación entre clientes y vendedores

if (isset($conn_sap) && $conn_sap) {
    // Obtener solo los clientes asociados al usuario actual
    $query = "SELECT T0.[CardCode], T0.[CardName], T1.[SlpCode], T1.[SlpName], 
              T0.[City] as Ciudad, T0.[GroupCode] as Canal, T0.[Territory] as Zona
              FROM OCRD T0  
              INNER JOIN OSLP T1 ON T0.[SlpCode] = T1.[SlpCode] 
              WHERE T0.[validFor] = 'Y'";
    
    // Si hay un filtro de vendedor para el usuario actual, aplicarlo
    if (isset($_SESSION['vendedor_id']) && !empty($_SESSION['vendedor_id'])) {
        $query .= " AND T1.[SlpCode] = " . $_SESSION['vendedor_id'];
    }
    
    $stmt = sqlsrv_query($conn_sap, $query);
    
    if ($stmt === false) {
        $error_sap = "Error al ejecutar la consulta: " . print_r(sqlsrv_errors(), true);
    } else {
        // Procesar resultados
        $vendedores_temp = [];
        $zonas_temp = [];
        $canales_temp = [];
        
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Agregar cliente con información completa
            $clientes[$row['CardCode']] = [
                'nombre' => $row['CardName'],
                'ciudad' => $row['Ciudad'] ?? 'Pereira',
                'zona' => $row['Zona'] ?? '',
                'canal' => $row['Canal'] ?? '',
                'vendedor' => $row['SlpName'],
                'vendedor_id' => $row['SlpCode']
            ];
            
            // Guardar la relación cliente-vendedor
            $clientes_vendedores[$row['CardCode']] = $row['SlpCode'];
            
            // Agregar vendedor (evitando duplicados)
            if (!isset($vendedores_temp[$row['SlpCode']])) {
                $vendedores_temp[$row['SlpCode']] = $row['SlpName'];
            }
            
            // Agregar zona (evitando duplicados)
            if (!empty($row['Zona']) && !in_array($row['Zona'], $zonas_temp)) {
                $zonas_temp[] = $row['Zona'];
            }
            
            // Agregar canal (evitando duplicados)
            if (!empty($row['Canal']) && !in_array($row['Canal'], $canales_temp)) {
                $canales_temp[] = $row['Canal'];
            }
        }
        
        // Convertir vendedores a formato final
        foreach ($vendedores_temp as $code => $name) {
            $vendedores[] = [
                'SlpCode' => $code,
                'SlpName' => $name
            ];
        }
        
        // Ordenar zonas y canales
        sort($zonas_temp);
        sort($canales_temp);
        
        $zonas = $zonas_temp;
        $canales = $canales_temp;
        
        sqlsrv_free_stmt($stmt);
    }
    
    // Cerrar conexión SAP
    sqlsrv_close($conn_sap);
}

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

// Obtener rutas para la semana
$rutas_semana = obtenerRutasSemana($fecha_lunes->format('Y-m-d'), $vendedor_id, $_SESSION['usuario_id'], $busqueda, $zona, $canal);

// Agrupar rutas por día
$rutas_por_dia = array(
    'lunes' => array(),
    'martes' => array(),
    'miercoles' => array(),
    'jueves' => array(),
    'viernes' => array(),
    'sabado' => array()
);

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
foreach ($rutas_semana as $ruta) {
    if (isset($ruta['dia_semana']) && isset($dias_semana[$ruta['dia_semana']])) {
        $dia = $dias_semana[$ruta['dia_semana']];
        $rutas_por_dia[$dia][] = $ruta;
    }
}

// Estado de colapso para cada día (inicialmente todos expandidos)
$dias_colapsados = isset($_COOKIE['dias_colapsados']) ? json_decode($_COOKIE['dias_colapsados'], true) : [
    'lunes' => false,
    'martes' => false,
    'miercoles' => false,
    'jueves' => false,
    'viernes' => false,
    'sabado' => false
];

// Asegurar que cada día tenga al menos 5 registros
foreach ($dias_semana as $dia_en => $dia_es) {
    while (count($rutas_por_dia[$dia_es]) < 5) {
        $rutas_por_dia[$dia_es][] = [
            'id' => -1 * (count($rutas_por_dia[$dia_es]) + 1), // ID negativo para identificar slots vacíos
            'nombre' => '',
            'cliente_id' => '',
            'cliente_nombre' => '',
            'cliente_ciudad' => '',
            'vendedor_id' => 0,
            'vendedor_nombre' => '',
            'fecha_programada' => '',
            'dia_semana' => $dia_en,
            'es_vacio' => true // Marcar como slot vacío
        ];
    }
}

// Procesar el formulario si se envió para crear nueva ruta
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear_ruta') {
    // Validar datos
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion'] ?? '');
    $distancia = floatval($_POST['distancia'] ?? 1.0);
    $cliente_id = isset($_POST['cliente_id']) ? trim($_POST['cliente_id']) : '';
    $vendedor_id = isset($_POST['vendedor_id']) && !empty($_POST['vendedor_id']) ? intval($_POST['vendedor_id']) : null;
    $dia_semana = isset($_POST['dia_semana']) ? trim($_POST['dia_semana']) : '';
    
    // Si no se proporcionó un vendedor_id, usar el asociado al cliente
    if (empty($vendedor_id) && !empty($cliente_id) && isset($clientes_vendedores[$cliente_id])) {
        $vendedor_id = $clientes_vendedores[$cliente_id];
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
    } elseif ($distancia <= 0) {
        $error = 'La distancia debe ser mayor a 0';
    } elseif (empty($cliente_id)) {
        $error = 'Debe seleccionar un cliente';
    } elseif (empty($dia_semana)) {
        $error = 'Debe seleccionar un día de la semana';
    } else {
        // Crear la ruta
        $datos = [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'distancia' => $distancia,
            'usuario_id' => $_SESSION['usuario_id'],
            'cliente_id' => $cliente_id,
            'vendedor_id' => $vendedor_id,
            'fecha_programada' => $fecha_programada
        ];
        
        $ruta_id = crearRuta($datos);
        
        if ($ruta_id) {
            $mensaje = 'Ruta creada exitosamente para el ' . ucfirst($dia_semana);
            
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
            
            // Asegurar que cada día tenga al menos 5 registros
            foreach ($dias_semana as $dia_en => $dia_es) {
                while (count($rutas_por_dia[$dia_es]) < 5) {
                    $rutas_por_dia[$dia_es][] = [
                        'id' => -1 * (count($rutas_por_dia[$dia_es]) + 1),
                        'nombre' => '',
                        'cliente_id' => '',
                        'cliente_nombre' => '',
                        'cliente_ciudad' => '',
                        'vendedor_id' => 0,
                        'vendedor_nombre' => '',
                        'fecha_programada' => '',
                        'dia_semana' => $dia_en,
                        'es_vacio' => true
                    ];
                }
            }
        } else {
            $error = 'Error al crear la ruta. Inténtalo de nuevo.';
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
            $vendedor_id = isset($_POST['vendedor_id']) && !empty($_POST['vendedor_id']) ? intval($_POST['vendedor_id']) : null;
            
            // Si no se proporcionó un vendedor_id, usar el asociado al cliente
            if (empty($vendedor_id) && !empty($cliente_id) && isset($clientes_vendedores[$cliente_id])) {
                $vendedor_id = $clientes_vendedores[$cliente_id];
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
                    'fecha_programada' => $fecha_programada
                ]);
                
                echo json_encode(['success' => $result, 'message' => $result ? 'Ruta actualizada correctamente' : 'Error al actualizar la ruta']);
            } else {
                // Crear nueva ruta
                $datos = [
                    'nombre' => $nombre,
                    'descripcion' => 'Ruta creada desde la programación semanal',
                    'distancia' => 1.0,
                    'usuario_id' => $_SESSION['usuario_id'],
                    'cliente_id' => $cliente_id,
                    'vendedor_id' => $vendedor_id,
                    'fecha_programada' => $fecha_programada
                ];
                
                $nuevo_id = crearRuta($datos);
                
                echo json_encode([
                    'success' => $nuevo_id > 0, 
                    'message' => $nuevo_id > 0 ? 'Ruta creada correctamente' : 'Error al crear la ruta',
                    'ruta_id' => $nuevo_id
                ]);
            }
            exit;
            
        case 'eliminar_ruta':
            $ruta_id = isset($_POST['ruta_id']) ? intval($_POST['ruta_id']) : 0;
            $result = eliminarRuta($ruta_id);
            
            echo json_encode(['success' => $result, 'message' => $result ? 'Ruta eliminada correctamente' : 'Error al eliminar la ruta']);
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
            require 'vendor/autoload.php'; // Asegúrate de tener PhpSpreadsheet instalado
            
            use PhpOffice\PhpSpreadsheet\Spreadsheet;
            use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
            
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // Establecer título
            $sheet->setCellValue('A1', 'REPORTE DE RUTAS');
            $sheet->mergeCells('A1:G1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            
            // Establecer encabezados
            $sheet->setCellValue('A3', 'CÓDIGO');
            $sheet->setCellValue('B3', 'CLIENTE');
            $sheet->setCellValue('C3', 'RUTA');
            $sheet->setCellValue('D3', 'CIUDAD');
            $sheet->setCellValue('E3', 'VENDEDOR');
            $sheet->setCellValue('F3', 'DÍA');
            $sheet->setCellValue('G3', 'FECHA');
            
            $sheet->getStyle('A3:G3')->getFont()->setBold(true);
            
            // Obtener datos para el reporte
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
            
            // Obtener rutas para el reporte
            $rutas_reporte = obtenerRutasSemana($fecha_desde, $vendedor_filtro, $_SESSION['usuario_id'], '', $zona_filtro, $canal_filtro);
            
            // Llenar datos
            $row = 4;
            foreach ($rutas_reporte as $ruta) {
                $sheet->setCellValue('A' . $row, $ruta['cliente_id']);
                $sheet->setCellValue('B' . $row, $ruta['cliente_nombre']);
                $sheet->setCellValue('C' . $row, $ruta['nombre']);
                $sheet->setCellValue('D' . $row, $ruta['cliente_ciudad']);
                $sheet->setCellValue('E' . $row, $ruta['vendedor_nombre']);
                $sheet->setCellValue('F' . $row, $ruta['dia_semana']);
                $sheet->setCellValue('G' . $row, $ruta['fecha_programada']);
                $row++;
            }
            
            // Ajustar anchos de columna
            $sheet->getColumnDimension('A')->setWidth(15);
            $sheet->getColumnDimension('B')->setWidth(30);
            $sheet->getColumnDimension('C')->setWidth(30);
            $sheet->getColumnDimension('D')->setWidth(15);
            $sheet->getColumnDimension('E')->setWidth(20);
            $sheet->getColumnDimension('F')->setWidth(15);
            $sheet->getColumnDimension('G')->setWidth(15);
            
            // Crear archivo
            $writer = new Xlsx($spreadsheet);
            $filename = 'Rutas_' . date('Y-m-d_H-i-s') . '.xlsx';
            $filepath = 'exports/' . $filename;
            
            // Asegurarse de que el directorio existe
            if (!is_dir('exports')) {
                mkdir('exports', 0777, true);
            }
            
            $writer->save($filepath);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Archivo Excel generado correctamente',
                'file_url' => $filepath
            ]);
            exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RUTERO MENSUAL</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        /* Estilos generales */
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
        }
        
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Estilos para la programación semanal */
        .programacion-container {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .programacion-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            background-color: #f8f9fa;
        }
        
        /* Estilos para el día de la semana */
        .dia-semana-header {
            background-color: #f0f8ff;
            font-weight: bold;
            text-align: center;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 4px 4px 0 0;
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .dia-semana-header .toggle-collapse {
            cursor: pointer;
            padding: 5px;
            border-radius: 3px;
        }
        
        .dia-semana-header .toggle-collapse:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        /* Estilos para el selector de semanas tipo calendario */
        .calendario-semanas {
            display: none;
            position: absolute;
            background-color: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
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
            border: 1px solid #dee2e6;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .semana-item:hover {
            background-color: #f8f9fa;
            border-color: #adb5bd;
        }
        
        .semana-item.active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        /* Estilos para celdas editables */
        .celda-editable {
            min-height: 40px;
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }
        
        .celda-editable:hover {
            background-color: #f0f0f0;
        }
        
        .celda-editable.vacia {
            color: #aaa;
            font-style: italic;
        }
        
        /* Estilos para datos existentes (no editables) */
        .celda-existente {
            background-color: #f8f9fa;
            border-left: 3px solid #28a745;
            padding: 5px;
            border-radius: 4px;
        }
        
        /* Estilos para el código automático */
        .codigo-auto {
            font-weight: bold;
            color: #495057;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>RUTERO MENSUAL</h1>
        <div>
            <button id="btn-exportar" class="btn btn-success me-2">
                <i class="fas fa-file-excel"></i> Exportar a Excel
            </button>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
    
    <?php if ($mensaje): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?php echo $mensaje; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <div class="programacion-container">
        <div class="programacion-header">
            <h2><i class="fas fa-calendar-week"></i> Programación Semanal</h2>
            
            <div class="d-flex justify-content-between align-items-center mt-3">
                <div class="d-flex align-items-center">
                    <button class="btn btn-outline-secondary btn-sm me-2" id="btn-semana-anterior">
                        <i class="fas fa-chevron-left"></i> Semana Anterior
                    </button>
                    
                    <div class="position-relative">
                        <button class="btn btn-primary btn-sm" id="btn-selector-semana">
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
                    
                    <button class="btn btn-outline-secondary btn-sm ms-2" id="btn-semana-siguiente">
                        Semana Siguiente <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                
                <div>
                    <button class="btn btn-primary btn-sm" id="btn-agregar-ruta">
                        <i class="fas fa-plus-circle"></i> Agregar Ruta
                    </button>
                </div>
            </div>
            
            <!-- Filtros -->
           
        
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
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th style="width: 15%">CÓDIGO</th>
                            <th style="width: 65%">RUTA</th>
                            <th style="width: 20%">CIUDAD</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rutas_por_dia['lunes'] as $index => $ruta): ?>
                            <tr>
                                <td class="codigo">
                                <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="cliente_id" class="form-label">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id" required>
                                <option value="">Seleccione un cliente</option>
                                <?php foreach ($clientes as $id => $cliente): ?>
                                    <option value="<?php echo htmlspecialchars($id); ?>" data-ciudad="<?php echo htmlspecialchars($cliente['ciudad']); ?>" data-vendedor="<?php echo htmlspecialchars($cliente['vendedor_id']); ?>">
                                        <?php echo htmlspecialchars($cliente['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                                </td>
                                <td>
                                <div class="col-md-6">
                            <label for="nombre" class="form-label">Nombre de la Ruta</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" required>
                        </div>
                                </td>
                                <td>  <div class="col-md-6">
                            <label for="ciudad" class="form-label">Ciudad</label>
                            <input type="text" class="form-control" id="ciudad" name="ciudad" readonly>
                        </div></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th style="width: 15%">CÓDIGO</th>
                            <th style="width: 65%">RUTA</th>
                            <th style="width: 20%">CIUDAD</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rutas_por_dia['lunes'] as $index => $ruta): ?>
                            <tr>
                                <td class="codigo">
                                <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="cliente_id" class="form-label">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id" required>
                                <option value="">Seleccione un cliente</option>
                                <?php foreach ($clientes as $id => $cliente): ?>
                                    <option value="<?php echo htmlspecialchars($id); ?>" data-ciudad="<?php echo htmlspecialchars($cliente['ciudad']); ?>" data-vendedor="<?php echo htmlspecialchars($cliente['vendedor_id']); ?>">
                                        <?php echo htmlspecialchars($cliente['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                                </td>
                                <td>
                                <div class="col-md-6">
                            <label for="nombre" class="form-label">Nombre de la Ruta</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" required>
                        </div>
                                </td>
                                <td>  <div class="col-md-6">
                            <label for="ciudad" class="form-label">Ciudad</label>
                            <input type="text" class="form-control" id="ciudad" name="ciudad" readonly>
                        </div></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th style="width: 15%">CÓDIGO</th>
                            <th style="width: 65%">RUTA</th>
                            <th style="width: 20%">CIUDAD</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rutas_por_dia['lunes'] as $index => $ruta): ?>
                            <tr>
                                <td class="codigo">
                                <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="cliente_id" class="form-label">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id" required>
                                <option value="">Seleccione un cliente</option>
                                <?php foreach ($clientes as $id => $cliente): ?>
                                    <option value="<?php echo htmlspecialchars($id); ?>" data-ciudad="<?php echo htmlspecialchars($cliente['ciudad']); ?>" data-vendedor="<?php echo htmlspecialchars($cliente['vendedor_id']); ?>">
                                        <?php echo htmlspecialchars($cliente['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                                </td>
                                <td>
                                <div class="col-md-6">
                            <label for="nombre" class="form-label">Nombre de la Ruta</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" required>
                        </div>
                                </td>
                                <td>  <div class="col-md-6">
                            <label for="ciudad" class="form-label">Ciudad</label>
                            <input type="text" class="form-control" id="ciudad" name="ciudad" readonly>
                        </div></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th style="width: 15%">CÓDIGO</th>
                            <th style="width: 65%">RUTA</th>
                            <th style="width: 20%">CIUDAD</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rutas_por_dia['lunes'] as $index => $ruta): ?>
                            <tr>
                                <td class="codigo">
                                <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="cliente_id" class="form-label">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id" required>
                                <option value="">Seleccione un cliente</option>
                                <?php foreach ($clientes as $id => $cliente): ?>
                                    <option value="<?php echo htmlspecialchars($id); ?>" data-ciudad="<?php echo htmlspecialchars($cliente['ciudad']); ?>" data-vendedor="<?php echo htmlspecialchars($cliente['vendedor_id']); ?>">
                                        <?php echo htmlspecialchars($cliente['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                                </td>
                                <td>
                                <div class="col-md-6">
                            <label for="nombre" class="form-label">Nombre de la Ruta</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" required>
                        </div>
                                </td>
                                <td>  <div class="col-md-6">
                            <label for="ciudad" class="form-label">Ciudad</label>
                            <input type="text" class="form-control" id="ciudad" name="ciudad" readonly>
                        </div></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th style="width: 15%">CÓDIGO</th>
                            <th style="width: 65%">RUTA</th>
                            <th style="width: 20%">CIUDAD</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rutas_por_dia['lunes'] as $index => $ruta): ?>
                            <tr>
                                <td class="codigo">
                                <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="cliente_id" class="form-label">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id" required>
                                <option value="">Seleccione un cliente</option>
                                <?php foreach ($clientes as $id => $cliente): ?>
                                    <option value="<?php echo htmlspecialchars($id); ?>" data-ciudad="<?php echo htmlspecialchars($cliente['ciudad']); ?>" data-vendedor="<?php echo htmlspecialchars($cliente['vendedor_id']); ?>">
                                        <?php echo htmlspecialchars($cliente['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                                </td>
                                <td>
                                <div class="col-md-6">
                            <label for="nombre" class="form-label">Nombre de la Ruta</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" required>
                        </div>
                                </td>
                                <td>  <div class="col-md-6">
                            <label for="ciudad" class="form-label">Ciudad</label>
                            <input type="text" class="form-control" id="ciudad" name="ciudad" readonly>
                        </div></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th style="width: 15%">CÓDIGO</th>
                            <th style="width: 65%">RUTA</th>
                            <th style="width: 20%">CIUDAD</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rutas_por_dia['lunes'] as $index => $ruta): ?>
                            <tr>
                                <td class="codigo">
                                <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="cliente_id" class="form-label">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id" required>
                                <option value="">Seleccione un cliente</option>
                                <?php foreach ($clientes as $id => $cliente): ?>
                                    <option value="<?php echo htmlspecialchars($id); ?>" data-ciudad="<?php echo htmlspecialchars($cliente['ciudad']); ?>" data-vendedor="<?php echo htmlspecialchars($cliente['vendedor_id']); ?>">
                                        <?php echo htmlspecialchars($cliente['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                                </td>
                                <td>
                                <div class="col-md-6">
                            <label for="nombre" class="form-label">Nombre de la Ruta</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" required>
                        </div>
                                </td>
                                <td>  <div class="col-md-6">
                            <label for="ciudad" class="form-label">Ciudad</label>
                            <input type="text" class="form-control" id="ciudad" name="ciudad" readonly>
                        </div></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal para agregar/editar ruta -->
<div class="modal fade" id="modal-ruta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-ruta-titulo">Agregar Ruta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="form-ruta">
                    <input type="hidden" id="ruta-id" name="ruta_id" value="0">
                    <input type="hidden" id="ruta-dia" name="dia" value="">
                    
                    <div class="mb-3">
                        <label for="ruta-cliente" class="form-label">Cliente</label>
                        <select class="form-select" id="ruta-cliente" name="cliente_id" required>
                            <option value="">Seleccione un cliente</option>
                            <?php foreach ($clientes as $code => $info): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>" 
                                        data-ciudad="<?php echo htmlspecialchars($info['ciudad']); ?>"
                                        data-vendedor="<?php echo $info['vendedor_id']; ?>">
                                    <?php echo htmlspecialchars("$code - {$info['nombre']}"); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="ruta-codigo" class="form-label">Código</label>
                        <input type="text" class="form-control" id="ruta-codigo" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="ruta-nombre" class="form-label">Nombre de la Ruta</label>
                        <input type="text" class="form-control" id="ruta-nombre" name="nombre" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="ruta-vendedor" class="form-label">Vendedor</label>
                        <select class="form-select" id="ruta-vendedor" name="vendedor_id">
                            <option value="">Sin vendedor</option>
                            <?php foreach ($vendedores as $vendedor): ?>
                                <option value="<?php echo $vendedor['SlpCode']; ?>">
                                    <?php echo htmlspecialchars($vendedor['SlpName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select  ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="ruta-ciudad" class="form-label">Ciudad</label>
                        <input type="text" class="form-control" id="ruta-ciudad" readonly>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-guardar-ruta">Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para ver documentos del cliente -->
<div class="modal fade" id="modal-documentos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Documentos del Cliente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="documentos-loading" class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <p>Cargando documentos...</p>
                </div>
                
                <div id="documentos-contenido" class="d-none">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Número</th>
                                <th>Tipo</th>
                                <th>Fecha</th>
                                <th>Total</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody id="documentos-lista"></tbody>
                    </table>
                </div>
                
                <div id="documentos-vacio" class="alert alert-info d-none">
                    No se encontraron documentos para este cliente.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para exportar a Excel -->
<div class="modal fade" id="modal-exportar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Exportar a Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="form-exportar">
                    <div class="mb-3">
                        <label class="form-label">Periodo a exportar</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="periodo" id="periodo-semana" value="semana" checked>
                            <label class="form-check-label" for="periodo-semana">
                                Semana actual
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="periodo" id="periodo-mes" value="mes">
                            <label class="form-check-label" for="periodo-mes">
                                Mes completo
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="periodo" id="periodo-personalizado" value="personalizado">
                            <label class="form-check-label" for="periodo-personalizado">
                                Periodo personalizado
                            </label>
                        </div>
                    </div>
                    
                    <div id="opciones-personalizado" class="d-none">
                        <div class="row mb-3">
                            <div class="col">
                                <label for="fecha-desde" class="form-label">Desde</label>
                                <input type="date" class="form-control" id="fecha-desde" name="fecha_desde" value="<?php echo $fecha_lunes->format('Y-m-d'); ?>">
                            </div>
                            <div class="col">
                                <label for="fecha-hasta" class="form-label">Hasta</label>
                                <input type="date" class="form-control" id="fecha-hasta" name="fecha_hasta" value="<?php echo $fecha_sabado->format('Y-m-d'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="exportar-vendedor" class="form-label">Vendedor</label>
                        <select id="exportar-vendedor" name="vendedor" class="form-select">
                            <option value="">Todos los vendedores</option>
                            <?php foreach ($vendedores as $vendedor): ?>
                                <option value="<?php echo $vendedor['SlpCode']; ?>">
                                    <?php echo htmlspecialchars($vendedor['SlpName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                 
                    
                  
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" id="iniciar-exportacion">
                    <i class="fas fa-file-excel"></i> Exportar
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    $(document).ready(function() {
        // Variables globales
        let modalRuta = new bootstrap.Modal(document.getElementById('modalRuta'));
        let modalDocumentos = new bootstrap.Modal(document.getElementById('modalDocumentos'));
        let modalExportar = new bootstrap.Modal(document.getElementById('modalExportar'));
        let calendarioSemanas = document.getElementById('calendario-semanas');
        
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
            
            // Mostrar modal
            modalRuta.show();
        });
        
        // Evento para editar ruta existente
        $('.editar-ruta').click(function(e) {
            e.stopPropagation(); // Evitar que se propague al header del día
            
            let rutaId = $(this).data('ruta-id');
            let clienteId = $(this).data('cliente-id');
            let nombre = $(this).data('nombre');
            let vendedorId = $(this).data('vendedor-id');
            let dia = $(this).data('dia');
            
            // Llenar formulario
            $('#ruta_id').val(rutaId);
            $('#cliente_id').val(clienteId);
            $('#nombre').val(nombre);
            $('#vendedor_id').val(vendedorId);
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
        
        //Evento para celdas editables (slots vacíos)
        $('.celda-editable').click(function() {
            let dia = $(this).data('dia');
            
            // Limpiar formulario
            $('#formRuta')[0].reset();
            $('#ruta_id').val(0);
            $('#dia_semana').val(dia);
            $('#modalRutaLabel').text('Agregar Ruta para ' + dia.charAt(0).toUpperCase() + dia.slice(1));
            $('#btn-eliminar-ruta').hide();
            
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
                            
                            // Actualizar nombre de la ruta si está vacío
                            if (!$('#nombre').val()) {
                                $('#nombre').val('Visita a ' + response.nombre);
                            }
                            
                            // Habilitar vendedor
                            $('#vendedor_id').prop('disabled', false);
                        } else {
                            // Si falla, intentar obtener del atributo data
                            let ciudad = $('option:selected', this).data('ciudad') || '';
                            let vendedor = $('option:selected', this).data('vendedor') || '';
                            
                            $('#ciudad').val(ciudad);
                            $('#vendedor_id').val(vendedor);
                            $('#vendedor_id').prop('disabled', false);
                        }
                    },
                    error: function() {
                        // Si falla, intentar obtener del atributo data
                        let ciudad = $('#cliente_id option:selected').data('ciudad') || '';
                        let vendedor = $('#cliente_id option:selected').data('vendedor') || '';
                        
                        $('#ciudad').val(ciudad);
                        $('#vendedor_id').val(vendedor);
                        $('#vendedor_id').prop('disabled', false);
                        
                        // Actualizar nombre de la ruta si está vacío
                        if (!$('#nombre').val()) {
                            let clienteNombre = $('#cliente_id option:selected').text().trim();
                            $('#nombre').val('Visita a ' + clienteNombre);
                        }
                    }
                });
            } else {
                // Limpiar campos si no hay cliente seleccionado
                $('#ciudad').val('');
                $('#vendedor_id').val('');
            }
        });
        
        // Evento para guardar ruta
        $('#btn-guardar-ruta').click(function() {
            // Validar formulario
            if (!$('#cliente_id').val()) {
                alert('Debe seleccionar un cliente');
                return;
            }
            
            if (!$('#nombre').val()) {
                alert('Debe ingresar un nombre para la ruta');
                return;
            }
            
            // Obtener datos del formulario
            let rutaId = $('#ruta_id').val();
            let clienteId = $('#cliente_id').val();
            let nombre = $('#nombre').val();
            let vendedorId = $('#vendedor_id').val();
            let dia = $('#dia_semana').val();
            
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
                    dia: dia
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Cerrar modal y recargar página
                        modalRuta.hide();
                        location.reload();
                    } else {
                        alert(response.message || 'Error al guardar la ruta');
                        $('#btn-guardar-ruta').prop('disabled', false).html('Guardar');
                    }
                },
                error: function() {
                    alert('Error de comunicación con el servidor');
                    $('#btn-guardar-ruta').prop('disabled', false).html('Guardar');
                }
            });
        });
        
        // Evento para eliminar ruta
        $('#btn-eliminar-ruta').click(function() {
            if (confirm('¿Está seguro de eliminar esta ruta?')) {
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
                            // Cerrar modal y recargar página
                            modalRuta.hide();
                            location.reload();
                        } else {
                            alert(response.message || 'Error al eliminar la ruta');
                            $('#btn-eliminar-ruta').prop('disabled', false).html('Eliminar');
                        }
                    },
                    error: function() {
                        alert('Error de comunicación con el servidor');
                        $('#btn-eliminar-ruta').prop('disabled', false).html('Eliminar');
                    }
                });
            }
        });
        
        // Evento para ver documentos del cliente
        $('.ver-documentos').click(function(e) {
            e.preventDefault();
            e.stopPropagation(); // Evitar que se propague al header del día
            
            let clienteId = $(this).data('cliente-id');
            
            // Limpiar tabla
            $('#tabla-documentos tbody').empty();
            
            // Mostrar indicador de carga
            $('#tabla-documentos tbody').html('<tr><td colspan="5" class="text-center"><i class="fas fa-spinner fa-spin"></i> Cargando documentos...</td></tr>');
            
            // Cargar documentos
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    action: 'obtener_documentos_cliente',
                    cliente_id: clienteId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Actualizar título
                        $('#modalDocumentosLabel').text('Documentos del Cliente ' + clienteId);
                        
                        // Limpiar tabla
                        $('#tabla-documentos tbody').empty();
                        
                        // Llenar tabla
                        if (response.documentos.length > 0) {
                            $.each(response.documentos, function(i, doc) {
                                let estado = '';
                                switch (doc.DocStatus) {
                                    case 'O':
                                        estado = '<span class="badge bg-success">Abierto</span>';
                                        break;
                                    case 'C':
                                        estado = '<span class="badge bg-secondary">Cerrado</span>';
                                        break;
                                    default:
                                        estado = '<span class="badge bg-info">Otro</span>';
                                }
                                
                                $('#tabla-documentos tbody').append(
                                    '<tr>' +
                                    '<td>' + doc.TipoDoc + '</td>' +
                                    '<td>' + doc.DocNum + '</td>' +
                                    '<td>' + doc.DocDate + '</td>' +
                                    '<td>$' + parseFloat(doc.DocTotal).toLocaleString('es-CO') + '</td>' +
                                    '<td>' + estado + '</td>' +
                                    '</tr>'
                                );
                            });
                        } else {
                            $('#tabla-documentos tbody').append(
                                '<tr><td colspan="5" class="text-center">No hay documentos recientes para este cliente</td></tr>'
                            );
                        }
                        
                        // Mostrar modal
                        modalDocumentos.show();
                    } else {
                        alert('Error al obtener documentos del cliente');
                    }
                },
                error: function() {
                    alert('Error de comunicación con el servidor');
                }
            });
        });
        
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
                    alert('Debe seleccionar fechas desde y hasta');
                    return;
                }
                
                if (new Date(fechaDesde) > new Date(fechaHasta)) {
                    alert('La fecha desde no puede ser mayor que la fecha hasta');
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
                        
                        // Descargar archivo
                        window.location.href = response.file_url;
                    } else {
                        alert(response.message || 'Error al exportar a Excel');
                    }
                    
                    $('#btn-confirmar-exportar').prop('disabled', false).html('Exportar');
                },
                error: function() {
                    alert('Error de comunicación con el servidor');
                    $('#btn-confirmar-exportar').prop('disabled', false).html('Exportar');
                }
            });
        });
    });
</script>

</body>
</html>
