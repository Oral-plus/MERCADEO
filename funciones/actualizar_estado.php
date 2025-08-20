<?php
// Incluir archivo de configuración y conexión a la base de datos
require_once 'config/db.php';

// Incluir el archivo donde está definida la función actualizarEstadoTarea
require_once 'includes/funciones/tareas.php';

// Configuración para manejo de errores en desarrollo
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Verificar si se han recibido los datos necesarios
if (isset($_POST['id']) && isset($_POST['estado'])) {
    $id = intval($_POST['id']);
    // Convertir el valor del checkbox (true/false) al valor que espera tu función
    $estado = ($_POST['estado'] === 'true') ? 'completada' : 'pendiente';
    
    // Llamar a tu función existente
    $resultado = actualizarEstadoTarea($id, $estado);
    
    if ($resultado) {
        echo json_encode(['status' => 'success', 'message' => 'Estado actualizado correctamente']);
    } else {
        $error = "Error al actualizar el estado";
        if (function_exists('sqlsrv_errors')) {
            $sqlErrors = sqlsrv_errors();
            if ($sqlErrors) {
                $error .= ": " . $sqlErrors[0]['message'];
            }
        }
        echo json_encode(['status' => 'error', 'message' => $error]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'No se recibieron los datos necesarios']);
}
?>