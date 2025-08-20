<?php
session_start();
include '../db_connection.php';
include 'nueva-ruta.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login.php");
    exit();
}

// Verificar si se recibieron los parámetros necesarios
if (!isset($_GET['id']) || !isset($_GET['accion'])) {
    header("Location: ../nueva-ruta.php?error=parametros_faltantes");
    exit();
}

$ruta_id = intval($_GET['id']);
$accion = $_GET['accion'];
$usuario_id = $_SESSION['usuario_id'];

// Verificar que la ruta exista y pertenezca al usuario
$ruta = obtenerDetallesRuta($ruta_id);
if (!$ruta || $ruta['usuario_id'] != $usuario_id) {
    header("Location: ../nueva-ruta.php?error=ruta_no_encontrada");
    exit();
}

// Realizar la acción correspondiente
$resultado = false;

switch ($accion) {
    case 'activar':
        $resultado = actualizarEstadoRuta($ruta_id, 'activa');
        break;
    case 'pausar':
        $resultado = actualizarEstadoRuta($ruta_id, 'pausada');
        break;
    case 'completar':
        $resultado = actualizarEstadoRuta($ruta_id, 'completada');
        break;
    case 'eliminar':
        $resultado = eliminarRuta($ruta_id);
        if ($resultado) {
            header("Location: ../nueva-ruta.php?mensaje=ruta_eliminada");
            exit();
        }
        break;
    default:
        header("Location: ../nueva-ruta.php?error=accion_invalida");
        exit();
}

// Redireccionar según el resultado
if ($resultado) {
    if ($accion == 'eliminar') {
        header("Location: ../nueva-ruta.php?mensaje=ruta_eliminada");
    } else {
        header("Location: ../detalle-ruta.php?id=$ruta_id&mensaje=estado_actualizado");
    }
} else {
    header("Location: ../detalle-ruta.php?id=$ruta_id&error=error_actualizar");
}
exit();
?>
