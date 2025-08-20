<?php
include 'funciones/reportes.php';
global $conn; // Asegúrate que tu conexión esté inicializada aquí

// Si tu función NO existe, déjala aquí:
if (!function_exists('obtenerReportePorId')) {
    function obtenerReportePorId($id) {
        global $conn;
        $sql = "SELECT r.*, 
                       c.nombre AS cliente_nombre, 
                       ru.nombre AS ruta_nombre
                FROM reportes r
                LEFT JOIN clientes_ruta c ON r.cliente_id = c.id
                LEFT JOIN rutas ru ON r.ruta_id = ru.id
                WHERE r.id = ?";
        $params = array($id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            return $row;
        }
        return false;
    }
}

if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "<div style='color:red'>ID de reporte inválido</div>";
    exit;
}

$reporte_id = intval($_GET['id']);
$reporte = obtenerReportePorId($reporte_id);

if (!$reporte) {
    echo "<div style='color:red'>Reporte no encontrado.</div>";
    exit;
}

// Formatea fechas si son objetos DateTime
if (isset($reporte['fecha_creacion']) && $reporte['fecha_creacion'] instanceof DateTime) {
    $reporte['fecha_creacion'] = $reporte['fecha_creacion']->format('Y-m-d H:i:s');
}
if (isset($reporte['fecha_actualizacion']) && $reporte['fecha_actualizacion'] instanceof DateTime) {
    $reporte['fecha_actualizacion'] = $reporte['fecha_actualizacion']->format('Y-m-d H:i:s');
}


?>
<div>
    <h4 style="margin-top:0;"><?php echo htmlspecialchars($reporte['titulo']); ?></h4>
    <div style="margin-bottom:1em;">
        <span class="tipo-badge <?php echo $reporte['tipo']; ?>"><?php echo ucfirst($reporte['tipo']); ?></span>
        <span style="margin-left:1em; color:#555;"><i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($reporte['fecha_creacion']); ?></span>
    </div>
    <div style="margin-bottom:1em;">
        <?php if (!empty($reporte['cliente_id'])): ?>
            <div><b>Cliente:</b> <?php echo htmlspecialchars($reporte['cliente_id']); ?></div>
        <?php endif; ?>
        <?php if (!empty($reporte['ruta_nombre'])): ?>
            <div><b>Ruta:</b> <?php echo htmlspecialchars($reporte['ruta_nombre']); ?></div>
        <?php endif; ?>
    </div>
    <div style="margin-bottom:1em;">
        <b>Contenido:</b>
        <div style=" border-radius:6px; padding:14px; margin-top:8px; white-space:pre-wrap;"><?php echo nl2br(htmlspecialchars($reporte['contenido'])); ?></div>
    </div>
    <div style="margin-bottom:1em;">
        <b>Fecha de creación:</b> <?php echo htmlspecialchars($reporte['fecha_creacion']); ?><br>
        <b>Última actualización:</b> <?php echo htmlspecialchars($reporte['fecha_actualizacion']); ?>
    </div>
</div>