<footer class="main-footer">
                <p>&copy; <?php echo date('Y'); ?> Sistema de Gestores de Ruta</p>
                <p>Versión 1.0</p>
            </footer>
        </main>
    </div>
    
    <script>
        // Toggle sidebar
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
</body>
</html>
