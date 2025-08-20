<?php
include 'db_connection.php';

function getUsuarioFromSAP($nit) {
    // Configuración para conexión con SAP
    $serverName = "HERCULES";
    $connectionInfo = array(
        "Database" => "RBOSKY3",
        "UID" => "sa",
        "PWD" => "Sky2022*!"
    );

    $sap_conn = sqlsrv_connect($serverName, $connectionInfo);

    if (!$sap_conn) {
        die("Error conectando a la base de datos SAP: " . print_r(sqlsrv_errors(), true));
    }

    // Query para obtener el usuario basado en el NIT
    $sql_sap = "SELECT * FROM usuarios_sap WHERE nit = ?";
    $params_sap = array($nit);
    $stmt_sap = sqlsrv_query($sap_conn, $sql_sap, $params_sap);

    if ($stmt_sap === false) {
        return "Error al buscar el usuario en SAP: " . print_r(sqlsrv_errors(), true);
    }

    $usuario_sap = sqlsrv_fetch_array($stmt_sap, SQLSRV_FETCH_ASSOC);

    sqlsrv_close($sap_conn); // Cerrar conexión SAP

    return $usuario_sap ? $usuario_sap : null;
}
?>