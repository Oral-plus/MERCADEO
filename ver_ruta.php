<?php
// Archivo: ver_ruta.php
session_start();
ob_start(); // Iniciar buffer de salida para evitar problemas con header()

// Verificar si el usuario está autenticado
if (!isset($_SESSION['usuario_id'])) {
    // Redirigir al login si no hay sesión
    header("Location: login.php");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Usuario';

// Incluir funciones comunes
include 'header.php';

// Verificar si se proporcionó un ID de ruta
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: listar_rutas_por_dia.php");
    exit;
}

$ruta_id = $_GET['id'];

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

// Conexión a SAP
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
            direccion,
            DATENAME(weekday, fecha_programada) AS dia_semana,
            CONVERT(VARCHAR, fecha_creacion, 120) AS fecha_creacion,
            CONVERT(VARCHAR, fecha_actualizacion, 120) AS fecha_actualizacion
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

// Obtener la información de la ruta
$ruta = obtenerRutaPorId($ruta_id);

// Si la ruta no existe, redirigir
if (!$ruta) {
    header("Location: listar_rutas_por_dia.php");
    exit;
}

// Obtener información del cliente si existe
$cliente = null;
if (!empty($ruta['cliente_id'])) {
$cliente = obtenerClienteSAP($ruta['cliente_id']);
}

// Obtener documentos del cliente si existe
$documentos = [];
if ($cliente) {
$documentos = obtenerDocumentosClienteSAP($ruta['cliente_id']);
}

// Configuración de la página
$tituloPagina = "Detalles de Ruta: " . $ruta['nombre'];

// Mapeo de días en inglés a español
$diasMapInverso = [
'Monday' => 'Lunes',
'Tuesday' => 'Martes',
'Wednesday' => 'Miércoles',
'Thursday' => 'Jueves',
'Friday' => 'Viernes',
'Saturday' => 'Sábado',
'Sunday' => 'Domingo'
];

// Formatear día de la semana
$diaSemana = $diasMapInverso[$ruta['dia_semana']] ?? $ruta['dia_semana'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="estilos-adicionales.css">
<link rel="stylesheet" href="estilostabla.css">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($tituloPagina); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    .detail-card {
        background-color: white;
        border-radius: 0.5rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        margin-bottom: 1.5rem;
    }

    .detail-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .detail-title {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-color);
        margin: 0;
    }

    .detail-body {
        padding: 1.5rem;
    }

    .detail-section {
        margin-bottom: 2rem;
    }

    .detail-section:last-child {
        margin-bottom: 0;
    }

    .detail-section-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-color);
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid var(--border-color);
    }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
    }

    .detail-item {
        margin-bottom: 1rem;
    }

    .detail-label {
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--text-muted);
        margin-bottom: 0.25rem;
    }

    .detail-value {
        font-size: 1rem;
        color: var(--text-color);
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
        background-color: #d1fae5;
        color: #065f46;
    }

    .status-inactive {
        background-color: #fee2e2;
        color: #b91c1c;
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
        background-color: #f3f4f6;
        color: var(--text-color);
    }

    .btn-secondary:hover {
        background-color: #e5e7eb;
    }

    .btn-danger {
        background-color: var(--danger-color);
        color: white;
    }

    .btn-danger:hover {
        background-color: #dc2626;
    }

    .action-buttons {
        display: flex;
        gap: 0.5rem;
    }

    .action-buttons .btn {
        padding: 0.375rem 0.75rem;
    }

    .notes-box {
        background-color: #f9fafb;
        border-radius: 0.375rem;
        padding: 1rem;
        margin-top: 0.5rem;
        white-space: pre-line;
    }

    .documents-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
    }

    .documents-table th,
    .documents-table td {
        padding: 0.75rem 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
    }

    .documents-table th {
        font-weight: 600;
        color: var(--text-color);
        background-color: #f9fafb;
    }

    .documents-table tbody tr:hover {
        background-color: #f9fafb;
    }

    .no-documents {
        text-align: center;
        padding: 2rem;
        color: var(--text-muted);
        font-style: italic;
    }

    @media (max-width: 768px) {
        .detail-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
</head>
<body>
<div class="container">
    <div class="section-header">
        <h2 class="section-title">
            <i class="fas fa-map-marker-alt"></i> Detalles de Ruta
        </h2>
        <div class="action-buttons">
            <a href="listar_rutas_por_dia.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
            <a href="editar_ruta.php?id=<?php echo htmlspecialchars($ruta_id); ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i> Editar
            </a>
            <form method="post" action="listar_rutas_por_dia.php" style="display:inline;" onsubmit="return confirm('¿Está seguro de eliminar esta ruta?')">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="ruta_id" value="<?php echo htmlspecialchars($ruta_id); ?>">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Eliminar
                </button>
            </form>
        </div>
    </div>
    
    <div class="detail-card">
        <div class="detail-header">
            <h3 class="detail-title"><?php echo htmlspecialchars($ruta['nombre']); ?></h3>
            <?php $statusClass = ($ruta['estado'] == 'activa') ? 'status-active' : 'status-inactive'; ?>
            <span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($ruta['estado']); ?></span>
        </div>
        
        <div class="detail-body">
            <div class="detail-section">
                <h4 class="detail-section-title">Información General</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">Nombre</div>
                        <div class="detail-value"><?php echo htmlspecialchars($ruta['nombre']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Estado</div>
                        <div class="detail-value">
                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($ruta['estado']); ?></span>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Ciudad</div>
                        <div class="detail-value"><?php echo htmlspecialchars($ruta['ciudad']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Dirección</div>
                        <div class="detail-value"><?php echo htmlspecialchars($ruta['direccion'] ?? 'No especificada'); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Día de la semana</div>
                        <div class="detail-value"><?php echo htmlspecialchars($diaSemana); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Fecha programada</div>
                        <div class="detail-value"><?php echo htmlspecialchars($ruta['fecha_programada']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Hora de visita</div>
                        <div class="detail-value"><?php echo htmlspecialchars($ruta['hora_visita']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Vendedor ID</div>
                        <div class="detail-value"><?php echo htmlspecialchars($ruta['vendedor_id']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Fecha de creación</div>
                        <div class="detail-value"><?php echo htmlspecialchars($ruta['fecha_creacion'] ?? 'No disponible'); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Última actualización</div>
                        <div class="detail-value"><?php echo htmlspecialchars($ruta['fecha_actualizacion'] ?? 'No disponible'); ?></div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($ruta['notas'])): ?>
            <div class="detail-section">
                <h4 class="detail-section-title">Notas</h4>
                <div class="notes-box">
                    <?php echo nl2br(htmlspecialchars($ruta['notas'])); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($cliente): ?>
            <div class="detail-section">
                <h4 class="detail-section-title">Información del Cliente</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">Código</div>
                        <div class="detail-value"><?php echo htmlspecialchars($cliente['CardCode']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Nombre</div>
                        <div class="detail-value"><?php echo htmlspecialchars($cliente['CardFName']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Nombre Completo</div>
                        <div class="detail-value"><?php echo htmlspecialchars($cliente['CardName']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Ciudad</div>
                        <div class="detail-value"><?php echo htmlspecialchars($cliente['City'] ?? 'No especificada'); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Teléfono</div>
                        <div class="detail-value"><?php echo htmlspecialchars($cliente['Phone'] ?? 'No especificado'); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Vendedor</div>
                        <div class="detail-value"><?php echo htmlspecialchars($cliente['SlpName'] ?? 'No especificado'); ?></div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($documentos)): ?>
            <div class="detail-section">
                <h4 class="detail-section-title">Documentos Recientes</h4>
                <table class="documents-table">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Número</th>
                            <th>Fecha</th>
                            <th>Total</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documentos as $doc): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($doc['TipoDoc']); ?></td>
                            <td><?php echo htmlspecialchars($doc['DocNum']); ?></td>
                            <td><?php echo htmlspecialchars($doc['DocDate']); ?></td>
                            <td><?php echo number_format($doc['DocTotal'], 2, ',', '.'); ?></td>
                            <td><?php echo htmlspecialchars($doc['DocStatus']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="detail-section">
                <h4 class="detail-section-title">Documentos Recientes</h4>
                <div class="no-documents">No hay documentos recientes para este cliente</div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="footer">
        <p>Sistema de Gestión de Rutas © <?php echo date('Y'); ?> SKY S.A.S</p>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Cualquier inicialización de JavaScript aquí
    });
</script>
</body>
</html>
<?php
// Liberar el buffer de salida
ob_end_flush();
?>