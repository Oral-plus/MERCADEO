<?php
$serverName = "HERCULES";
$connectionInfo = array("Database" => "calidad", "UID" => "sa", "PWD" => "Sky2022*!");
$conn = sqlsrv_connect($serverName, $connectionInfo);

// Verificar conexión
if (!$conn) {
    die("❌ Error al conectar: " . print_r(sqlsrv_errors(), true));
}
?>