<?php
include 'header.php';
include 'funciones/reportes.php';

// Obtener tema del usuario desde la sesión o usar el predeterminado
$tema_actual = isset($_SESSION['tema']) ? $_SESSION['tema'] : 'claro';

// Obtener todos los reportes del usuario
$usuario_id = $_SESSION['usuario_id'];
$reportes = obtenerReportesUsuario($usuario_id);

// Manejar filtros si existen
$filtro_tipo = isset($_GET['tipo']) ? $_GET['tipo'] : '';
$filtro_fecha = isset($_GET['fecha']) ? $_GET['fecha'] : '';

if (!empty($filtro_tipo)) {
    $reportes = array_filter($reportes, function($reporte) use ($filtro_tipo) {
        return $reporte['tipo'] === $filtro_tipo;
    });
}

if (!empty($filtro_fecha)) {
    $reportes = array_filter($reportes, function($reporte) use ($filtro_fecha) {
        return substr(
            is_object($reporte['fecha_creacion']) && method_exists($reporte['fecha_creacion'], 'format') 
                ? $reporte['fecha_creacion']->format('Y-m-d')
                : substr($reporte['fecha_creacion'], 0, 10),
            0, 10
        ) === $filtro_fecha;
    });
}
?>

<!DOCTYPE html>
<html lang="es" data-theme="<?php echo htmlspecialchars($tema_actual); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Filtrar Reportes</title>
    <link rel="stylesheet" href="estilos-adicionales.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-light: #3b82f6;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --text-color: #1e293b;
            --text-light: #64748b;
            --bg-color: #f1f5f9;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --transition: all 0.3s ease;
        }
        
        /* Tema oscuro */
        [data-theme="oscuro"] {
            --primary-color: #3b82f6;
            --primary-light: #60a5fa;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --text-color: #e2e8f0;
            --text-light: #94a3b8;
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
                --success-color: #10b981;
                --danger-color: #ef4444;
                --text-color: #e2e8f0;
                --text-light: #94a3b8;
                --bg-color: #0f172a;
                --card-bg: #1e293b;
                --border-color: #334155;
                --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.2);
                --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -1px rgba(0, 0, 0, 0.2);
            }
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: var(--transition);
            margin: 0;
            padding: 0;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 20px;
            background-color: var(--card-bg);
            border-radius: 10px;
            box-shadow: var(--shadow-sm);
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .filter-options {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-group label {
            font-weight: 500;
            color: var(--text-color);
        }
        
        .filter-group select, 
        .filter-group input {
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 10px 16px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-light);
        }
        
        .reportes-container {
            padding: 20px;
        }
        
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            background-color: var(--card-bg);
            border-radius: 10px;
            box-shadow: var(--shadow-sm);
            text-align: center;
        }
        
        .empty-state i {
            font-size: 48px;
            color: var(--text-light);
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 10px;
            color: var(--text-color);
        }
        
        .empty-state p {
            color: var(--text-light);
            margin-bottom: 20px;
        }
        
        .reportes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .reporte-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            overflow: hidden;
            transition: var(--transition);
        }
        
        .reporte-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-3px);
        }
        
        .reporte-header {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .reporte-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .reporte-title h3 {
            font-size: 18px;
            margin: 0;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .tipo-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .tipo-badge.visita {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }
        
        .tipo-badge.incidencia {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }
        
        .tipo-badge.inventario {
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--primary-color);
        }
        
        .reporte-date {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text-light);
        }
        
        .reporte-content {
            padding: 15px;
            min-height: 100px;
        }
        
        .reporte-content p {
            margin: 0;
            color: var(--text-color);
            line-height: 1.6;
        }
        
        .reporte-footer {
            padding: 15px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .reporte-meta {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text-light);
        }
        
        .reporte-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-secondary {
            background-color: var(--bg-color);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 12px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .btn-secondary:hover {
            background-color: var(--border-color);
        }
        
        .btn-icon {
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-size: 16px;
            padding: 5px;
            border-radius: 4px;
        }
        
        .btn-icon:hover {
            background-color: var(--bg-color);
        }
        
        .dropdown {
            position: relative;
        }
        
        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            box-shadow: var(--shadow-md);
            min-width: 150px;
            z-index: 10;
        }
        
        .dropdown-menu.show {
            display: block;
        }
        
        .dropdown-menu a {
            display: block;
            padding: 8px 12px;
            color: var(--text-color);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .dropdown-menu a:hover {
            background-color: var(--bg-color);
        }
        
        .dropdown-menu a.text-danger {
            color: var(--danger-color);
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1500;
            left: 0;
            top: 0;
            width: 100vw;
            height: 100vh;
            overflow: auto;
            background: rgba(0,0,0,0.45);
            align-items: center;
            justify-content: center;
        }
        
        .modal-open {
            overflow: hidden;
        }
        
        .modal-dialog {
            margin: 4rem auto;
            max-width: 700px;
        }
        
        .modal-content {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 0;
            box-shadow: var(--shadow-md);
            overflow: hidden;
            position: relative;
        }
        
        .modal-header, .modal-footer {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .modal-header {
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-color);
        }
        
        .modal-title {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .modal-body {
            padding: 2rem 1.5rem;
            color: var(--text-color);
        }
        
        .modal-footer {
            border-top: 1px solid var(--border-color);
            text-align: right;
            background: var(--bg-color);
        }
        
        .close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-color);
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .reportes-grid {
                grid-template-columns: 1fr;
            }
            
            .reporte-footer {
                flex-direction: column;
                gap: 15px;
            }
            
            .reporte-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .modal-dialog {
                max-width: 96vw;
            }
        }
    </style>
</head>
<body>

<div class="page-header">
    <div class="header-actions">
        <a href="nuevo-reporte.php" class="btn-primary">
            <i class="fas fa-plus"></i> Nuevo Reporte
        </a>
    </div>
    <div class="filter-options">
        <div class="filter-group">
            <label for="tipo">Tipo:</label>
            <select id="tipo" onchange="filtrarReportes()">
                <option value="">Todos</option>
                <option value="visita" <?php echo $filtro_tipo === 'visita' ? 'selected' : ''; ?>>Visita</option>
                <option value="incidencia" <?php echo $filtro_tipo === 'incidencia' ? 'selected' : ''; ?>>Incidencia</option>
                <option value="inventario" <?php echo $filtro_tipo === 'inventario' ? 'selected' : ''; ?>>Inventario</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="fecha">Fecha:</label>
            <input type="date" id="fecha" value="<?php echo $filtro_fecha; ?>" onchange="filtrarReportes()">
        </div>
    </div>
</div>

<div class="reportes-container">
    <?php if (empty($reportes)): ?>
        <div class="empty-state">
            <i class="fas fa-file-alt"></i>
            <h3>No hay reportes disponibles</h3>
            <p>No se encontraron reportes con los criterios seleccionados.</p>
            <a href="nuevo-reporte.php" class="btn-primary">Crear Nuevo Reporte</a>
        </div>
    <?php else: ?>
        <div class="reportes-grid">
            <?php foreach ($reportes as $reporte): ?>
                <div class="reporte-card">
                    <div class="reporte-header">
                        <div class="reporte-title">
                            <h3><?php echo htmlspecialchars($reporte['titulo']); ?></h3>
                            <span class="tipo-badge <?php echo $reporte['tipo']; ?>">
                                <?php echo ucfirst($reporte['tipo']); ?>
                            </span>
                        </div>
                        <div class="reporte-date">
                            <i class="far fa-calendar-alt"></i>
                            <span>
                                <?php 
                                if (isset($reporte['fecha_creacion']) && $reporte['fecha_creacion'] instanceof DateTime) {
                                    echo $reporte['fecha_creacion']->format('Y-m-d H:i:s');
                                } else {
                                    echo htmlspecialchars($reporte['fecha_creacion']);
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                    <div class="reporte-content">
                        <p><?php echo nl2br(htmlspecialchars(substr($reporte['contenido'], 0, 150) . (strlen($reporte['contenido']) > 150 ? '...' : ''))); ?></p>
                    </div>
                    <div class="reporte-footer">
                        <div class="reporte-meta">
                            <?php if (!empty($reporte['cliente_nombre'])): ?>
                                <div class="meta-item">
                                    <i class="fas fa-store"></i>
                                    <span><?php echo htmlspecialchars($reporte['cliente_nombre']); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($reporte['ruta_nombre'])): ?>
                                <div class="meta-item">
                                    <i class="fas fa-map-marked-alt"></i>
                                    <span><?php echo htmlspecialchars($reporte['ruta_nombre']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="reporte-actions">
                            <button 
                                class="btn-secondary" 
                                onclick="verDetalleReporte(<?php echo $reporte['id']; ?>)">
                                <i class="fas fa-eye"></i> Ver Detalles
                            </button>
                        
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL PARA DETALLE DE REPORTE -->
<div class="modal fade" id="detalleModal" tabindex="-1" role="dialog" aria-labelledby="detalleModalLabel" aria-hidden="true" style="display:none;">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="detalleModalLabel">Detalle del Reporte</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar" onclick="cerrarModal()">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" id="detalleModalBody">
        <!-- Aquí se carga el detalle vía AJAX -->
        <div style="text-align:center;">
            <i class="fas fa-spinner fa-spin fa-2x"></i>
            <p>Cargando...</p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="cerrarModal()">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script>
    function filtrarReportes() {
        const tipo = document.getElementById('tipo').value;
        const fecha = document.getElementById('fecha').value;
        let url = 'reportes.php';
        
        const params = [];
        if (tipo) params.push('tipo=' + tipo);
        if (fecha) params.push('fecha=' + fecha);
        
        if (params.length > 0) {
            url += '?' + params.join('&');
        }
        
        window.location.href = url;
    }
    
    // Dropdowns
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

    // MODAL FUNCIONES
    function verDetalleReporte(id) {
        // Mostrar modal
        document.getElementById('detalleModal').style.display = 'block';
        document.getElementById('detalleModalBody').innerHTML = '<div style="text-align:center;"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Cargando...</p></div>';
        
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'ajax_detalle_reporte.php?id=' + id, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if(xhr.status === 200) {
                    document.getElementById('detalleModalBody').innerHTML = xhr.responseText;
                } else {
                    document.getElementById('detalleModalBody').innerHTML = "<div style='color:red;text-align:center;'>Error al cargar el detalle del reporte</div>";
                }
            }
        };
        xhr.send();
        // Fondo modal
        document.body.classList.add('modal-open');
    }

    function cerrarModal() {
        document.getElementById('detalleModal').style.display = 'none';
        document.body.classList.remove('modal-open');
    }
</script>

<?php include 'footer.php'; ?>
</body>
</html>