<div class="task-list">
    <?php if (empty($tareas)): ?>
        <div class="empty-state">
            <i class="fas fa-check-double"></i>
            <p>¡No tienes tareas pendientes!</p>
            <a href="nueva-tarea.php" class="btn-primary">Crear Nueva Tarea</a>
        </div>
    <?php else: ?>
        <?php foreach ($tareas as $tarea): ?>
        <div class="task-card <?php echo $tarea['estado']; ?>">
            <div class="task-header">
                <div class="task-checkbox">
                    <input type="checkbox" id="task<?php echo $tarea['id']; ?>"
                        <?php echo $tarea['estado'] === 'completada' ? 'checked' : ''; ?>
                        onchange="actualizarEstadoTarea(<?php echo $tarea['id']; ?>, this.checked)">
                    <label for="task<?php echo $tarea['id']; ?>"></label>
                </div>
                <h3 class="task-title <?php echo $tarea['estado'] === 'completada' ? 'completed-task' : ''; ?>">
                    <?php echo htmlspecialchars($tarea['titulo']); ?>
                </h3>
                <div class="priority-badge priority-<?php echo $tarea['prioridad']; ?>">
                    <?php echo ucfirst($tarea['prioridad']); ?>
                </div>
            </div>
            
            <?php if (!empty($tarea['descripcion'])): ?>
            <div class="task-description">
                <?php echo nl2br(htmlspecialchars(substr($tarea['descripcion'], 0, 100) . (strlen($tarea['descripcion']) > 100 ? '...' : ''))); ?>
            </div>
            <?php endif; ?>
            
            <div class="task-meta">
                <?php if (!empty($tarea['fecha_vencimiento'])): ?>
                <div class="meta-item">
                    <i class="far fa-calendar-alt"></i>
                    <span>Vence: <?php echo date('d/m/Y H:i', strtotime($tarea['fecha_vencimiento'])); ?></span>
                </div>
                <?php endif; ?>
                <div class="meta-item">
                    <i class="far fa-clock"></i>
                    <span>Creada: <?php echo date('d/m/Y', strtotime($tarea['fecha_creacion'])); ?></span>
                </div>
            </div>
            
            <div class="task-actions">
                <a href="tareas.php?editar=<?php echo $tarea['id']; ?>" class="task-action-btn">
                    <i class="fas fa-edit"></i>
                </a>
                <a href="tareas.php?eliminar=<?php echo $tarea['id']; ?>" class="task-action-btn delete-btn" onclick="return confirmarEliminar(<?php echo $tarea['id']; ?>);">
                    <i class="fas fa-trash-alt"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>