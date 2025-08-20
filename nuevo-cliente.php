<?php
include 'header.php';
include 'funciones/clientes.php';
include 'funciones/nueva-ruta.php';

// Obtener rutas disponibles
$usuario_id = $_SESSION['usuario_id'];
$rutas = obtenerRutasParaFiltro($usuario_id);

// Verificar si se especificó una ruta
$ruta_id = isset($_GET['ruta_id']) ? intval($_GET['ruta_id']) : 0;

// Procesar el formulario si se envió
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar datos
    $nombre = trim($_POST['nombre']);
    $direccion = trim($_POST['direccion']);
    $telefono = trim($_POST['telefono']);
    $ruta_id = intval($_POST['ruta_id']);
    
    // Validaciones
    if (empty($nombre)) {
        $error = 'El nombre del cliente es obligatorio';
    } elseif (empty($direccion)) {
        $error = 'La dirección es obligatoria';
    } elseif ($ruta_id <= 0) {
        $error = 'Debe seleccionar una ruta';
    } else {
        // Crear el cliente
        $datos = [
            'nombre' => $nombre,
            'direccion' => $direccion,
            'telefono' => $telefono,
            'ruta_id' => $ruta_id,
            'usuario_id' => $_SESSION['usuario_id']
        ];
        
        $cliente_id = crearCliente($datos);
        
        if ($cliente_id) {
            // Redireccionar a la página de detalle de la ruta
            header("Location: detalle-ruta.php?id=$ruta_id&cliente_creado=1");
            exit();
        } else {
            $error = 'Error al crear el cliente. Inténtalo de nuevo.';
        }
    }
}
?>

<div class="page-header">
<link rel="stylesheet" href="estilos-adicionales.css">
    <div class="header-actions">
        <a href="<?php echo $ruta_id > 0 ? "detalle-ruta.php?id=$ruta_id" : 'clientes.php'; ?>" class="btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>
</div>

<div class="form-container">
    <div class="form-card">
        <div class="form-header">
            <h2><i class="fas fa-user-plus"></i> Nuevo Cliente</h2>
            <p>Completa el formulario para agregar un nuevo cliente</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($mensaje): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="nombre">Nombre del Cliente <span class="required">*</span></label>
                <input type="text" id="nombre" name="nombre" value="<?php echo isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : ''; ?>" required>
            </div>
            
            <div class="form-group">
                <label for="direccion">Dirección <span class="required">*</span></label>
                <input type="text" id="direccion" name="direccion" value="<?php echo isset($_POST['direccion']) ? htmlspecialchars($_POST['direccion']) : ''; ?>" required>
            </div>
            
            <div class="form-group">
                <label for="telefono">Teléfono</label>
                <input type="text" id="telefono" name="telefono" value="<?php echo isset($_POST['telefono']) ? htmlspecialchars($_POST['telefono']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="ruta_id">Ruta <span class="required">*</span></label>
                <select id="ruta_id" name="ruta_id" required>
                    <option value="">Seleccionar Ruta</option>
                    <?php foreach ($rutas as $ruta): ?>
                        <option value="<?php echo $ruta['id']; ?>" <?php echo ($ruta_id === $ruta['id'] || (isset($_POST['ruta_id']) && $_POST['ruta_id'] == $ruta['id'])) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ruta['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save"></i> Guardar Cliente
                </button>
                <a href="<?php echo $ruta_id > 0 ? "detalle-ruta.php?id=$ruta_id" : 'clientes.php'; ?>" class="btn-secondary">
                    <i class="fas fa-times"></i> Cancelar
                </a>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>
