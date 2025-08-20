<?php
include 'header.php';
include 'funciones/nueva-ruta.php';

// Obtener todas las rutas del usuario
$usuario_id = $_SESSION['usuario_id'];
$rutas = obtenerRutasUsuario($usuario_id);

// Manejar filtros si existen
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
if (!empty($filtro_estado)) {
    $rutas = array_filter($rutas, function($ruta) use ($filtro_estado) {
        return $ruta['estado'] === $filtro_estado;
    });
}
?>

<div class="page-header">
<link rel="stylesheet" href="estilos-adicionales.css">
    <div class="header-actions">
        <a href="nueva-ruta.php" class="btn-primary">
            <i class="fas fa-plus"></i> Nueva Ruta
        </a>
    </div>
    <div class="filter-options">
        <div class="filter-group">
            <label for="estado">Estado:</label>
            <select id="estado" onchange="filtrarRutas()">
                <option value="">Todos</option>
                <option value="activa" <?php echo $filtro_estado === 'activa' ? 'selected' : ''; ?>>Activas</option>
                <option value="completada" <?php echo $filtro_estado === 'completada' ? 'selected' : ''; ?>>Completadas</option>
                <option value="pausada" <?php echo $filtro_estado === 'pausada' ? 'selected' : ''; ?>>Pausadas</option>
            </select>
        </div>
    </div>
</div>

<div class="rutas-container">
    <?php if (empty($rutas)): ?>
        <div class="empty-state">
            <i class="fas fa-route"></i>
            <h3>No hay rutas disponibles</h3>
            <p>No se encontraron rutas con los criterios seleccionados.</p>
            <a href="nueva-ruta.php" class="btn-primary">Crear Nueva Ruta</a>
        </div>
    <?php else: ?>
        <div class="rutas-grid">
            <?php foreach ($rutas as $ruta): ?>
                <div class="ruta-card">
                    <div class="ruta-header">
                        <h3><?php echo htmlspecialchars($ruta['nombre']); ?></h3>
                        <span class="estado-badge <?php echo $ruta['estado']; ?>">
                            <?php echo ucfirst($ruta['estado']); ?>
                        </span>
                    </div>
                    <div class="ruta-info">
                        <div class="info-item">
                            <i class="fas fa-store"></i>
                            <span><?php echo $ruta['clientes']; ?> clientes</span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-road"></i>
                            <span><?php echo $ruta['distancia']; ?> km</span>
                        </div>
                        <div class="info-item">
                            <i class="far fa-calendar-alt"></i>
                            <span><?php echo $ruta['fecha_creacion']; ?></span>
                        </div>
                    </div>
                    <div class="ruta-progress">
                        <div class="progress-label">
                            <span>Progreso</span>
                            <span><?php echo $ruta['progreso']; ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress" style="width: <?php echo $ruta['progreso']; ?>%"></div>
                        </div>
                    </div>
                    <div class="ruta-actions">
                        <a href="detalle-ruta.php?id=<?php echo $ruta['id']; ?>" class="btn-secondary">
                            <i class="fas fa-eye"></i> Ver Detalles
                        </a>
                        <div class="dropdown">
                            <button class="btn-icon dropdown-toggle">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a href="editar-ruta.php?id=<?php echo $ruta['id']; ?>">
                                    <i class="fas fa-edit"></i> Editar
                                </a>
                                <?php if ($ruta['estado'] === 'activa'): ?>
                                    <a href="funciones/actualizar_ruta.php?id=<?php echo $ruta['id']; ?>&accion=pausar" class="text-warning">
                                        <i class="fas fa-pause"></i> Pausar
                                    </a>
                                <?php elseif ($ruta['estado'] === 'pausada'): ?>
                                    <a href="funciones/actualizar_ruta.php?id=<?php echo $ruta['id']; ?>&accion=activar" class="text-success">
                                        <i class="fas fa-play"></i> Activar
                                    </a>
                                <?php endif; ?>
                                <a href="funciones/actualizar_ruta.php?id=<?php echo $ruta['id']; ?>&accion=eliminar" class="text-danger" onclick="return confirm('¿Estás seguro de eliminar esta ruta?')">
                                    <i class="fas fa-trash"></i> Eliminar
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    function filtrarRutas() {
        const estado = document.getElementById('estado').value;
        window.location.href = 'nueva-ruta.php' + (estado ? '?estado=' + estado : '');
    }
    
    // Inicializar dropdowns
    document.addEventListener('DOMContentLoaded', function() {
        const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
        dropdownToggles.forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                const dropdown = this.nextElementSibling;
                dropdown.classList.toggle('show');
            });
        });
        
        document.addEventListener('click', function() {
            const dropdownMenus = document.querySelectorAll('.dropdown-menu.show');
            dropdownMenus.forEach(menu => {
                menu.classList.remove('show');
            });
        });
    });
</script>

<?php include 'footer.php'; ?>