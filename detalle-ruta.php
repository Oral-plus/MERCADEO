<?php
include 'header.php';
include 'funciones/nueva-ruta.php';
include 'sap_utils.php';

// Verificar si se proporcionó un ID de ruta
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: nueva-ruta.php?error=id_invalido");
    exit();
}

$ruta_id = $_GET['id'];
$ruta = obtenerDetallesRuta($ruta_id);

// Verificar si la ruta existe
if (!$ruta) {
    header("Location: nueva-ruta.php?error=ruta_no_encontrada");
    exit();
}

// Verificar si se acaba de crear la ruta
$recien_creada = isset($_GET['creada']) && $_GET['creada'] == 1;

// Obtener información del cliente y vendedor de SAP si existen
$cliente_info = null;
$vendedor_info = null;

if (!empty($ruta['cliente_id'])) {
    $cliente_info = obtenerClienteSAP($ruta['cliente_id']);
}

if (!empty($ruta['vendedor_id'])) {
    $vendedor_info = obtenerVendedorSAP($ruta['vendedor_id']);
}
?>

<div class="page-header">
    <div class="header-actions">
    <link rel="stylesheet" href="estilos-adicionales.css">
        <a href="nueva-ruta.php" class="btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver a Rutas
        </a>
    </div>
</div>

<?php if ($recien_creada): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        ¡Ruta creada correctamente!
    </div>
<?php endif; ?>

<div class="detail-container">
    <div class="detail-header">
        <div class="detail-title">
            <h2><?php echo htmlspecialchars($ruta['nombre']); ?></h2>
            <span class="badge badge-<?php echo $ruta['estado'] == 'activa' ? 'success' : ($ruta['estado'] == 'pausada' ? 'warning' : 'secondary'); ?>">
                <?php echo ucfirst($ruta['estado']); ?>
            </span>
        </div>
        <div class="detail-actions">
            <a href="editar-ruta.php?id=<?php echo $ruta_id; ?>" class="btn-primary">
                <i class="fas fa-edit"></i> Editar
            </a>
            <button class="btn-danger" onclick="confirmarEliminar(<?php echo $ruta_id; ?>)">
                <i class="fas fa-trash"></i> Eliminar
            </button>
        </div>
    </div>
    
    <div class="detail-content">
        <div class="detail-section">
            <h3>Información General</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Distancia:</span>
                    <span class="detail-value"><?php echo number_format($ruta['distancia'], 1); ?> km</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Progreso:</span>
                    <div class="progress-bar">
                        <div class="progress" style="width: <?php echo $ruta['progreso']; ?>%"></div>
                    </div>
                    <span class="progress-text"><?php echo $ruta['progreso']; ?>%</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Fecha de creación:</span>
                    <span class="detail-value"><?php echo $ruta['fecha_creacion']; ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Última actualización:</span>
                    <span class="detail-value"><?php echo $ruta['fecha_actualizacion']; ?></span>
                </div>
            </div>
        </div>
        
        <div class="detail-section">
            <h3>Cliente SAP</h3>
            <?php if ($cliente_info): ?>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Código:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($cliente_info['CardCode']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Nombre:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($cliente_info['CardName']); ?></span>
                    </div>
                </div>
            <?php else: ?>
                <p class="detail-empty">No hay información de cliente de SAP disponible.</p>
            <?php endif; ?>
        </div>
        
        <div class="detail-section">
            <h3>Vendedor SAP</h3>
            <?php if ($vendedor_info): ?>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Código:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($vendedor_info['SlpCode']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Nombre:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($vendedor_info['SlpName']); ?></span>
                    </div>
                </div>
            <?php else: ?>
                <p class="detail-empty">No hay información de vendedor de SAP disponible.</p>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($ruta['descripcion'])): ?>
            <div class="detail-section">
                <h3>Descripción</h3>
                <p class="detail-description"><?php echo nl2br(htmlspecialchars($ruta['descripcion'])); ?></p>
            </div>
        <?php endif; ?>
        
        <div class="detail-section">
            <div class="section-header">
                <h3>Clientes en la Ruta</h3>
                <a href="agregar-cliente-ruta.php?ruta_id=<?php echo $ruta_id; ?>" class="btn-sm btn-primary">
                    <i class="fas fa-plus"></i> Agregar Cliente
                </a>
            </div>
            
            <?php if (count($ruta['clientes']) > 0): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Dirección</th>
                                <th>Teléfono</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ruta['clientes'] as $cliente): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cliente['nombre']); ?></td>
                                    <td><?php echo htmlspecialchars($cliente['direccion']); ?></td>
                                    <td><?php echo htmlspecialchars($cliente['telefono']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $cliente['estado'] == 'visitado' ? 'success' : ($cliente['estado'] == 'en_proceso' ? 'warning' : 'secondary'); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $cliente['estado'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="editar-cliente-ruta.php?id=<?php echo $cliente['id']; ?>" class="btn-sm btn-secondary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button class="btn-sm btn-danger" onclick="confirmarEliminarCliente(<?php echo $cliente['id']; ?>, <?php echo $ruta_id; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="detail-empty">No hay clientes asignados a esta ruta.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function confirmarEliminar(rutaId) {
    if (confirm('¿Estás seguro de que deseas eliminar esta ruta? Esta acción no se puede deshacer.')) {
        window.location.href = 'eliminar-ruta.php?id=' + rutaId;
    }
}

function confirmarEliminarCliente(clienteId, rutaId) {
    if (confirm('¿Estás seguro de que deseas eliminar este cliente de la ruta? Esta acción no se puede deshacer.')) {
        window.location.href = 'eliminar-cliente-ruta.php?id=' + clienteId + '&ruta_id=' + rutaId;
    }
}
</script>

<?php include 'footer.php'; ?>
