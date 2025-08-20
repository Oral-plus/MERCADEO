<?php
// Conexión a la base de datos
$serverName = "HERCULES";
$connectionInfo = array("Database" => "Ruta", "UID" => "sa", "PWD" => "Sky2022*!");
$conn = sqlsrv_connect($serverName, $connectionInfo);

// Verificar conexión
if (!$conn) {
    die("❌ Error de conexión: " . print_r(sqlsrv_errors(), true));
}

// SQL para crear o actualizar la tabla rutas1
$sql_rutas = "
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='rutas1' AND xtype='U')
BEGIN
    CREATE TABLE rutas1 (
        id INT IDENTITY(1,1) PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        descripcion TEXT NULL,
        estado VARCHAR(20) NOT NULL DEFAULT 'activa',
        distancia FLOAT NOT NULL DEFAULT 0,
        progreso INT NOT NULL DEFAULT 0,
        usuario_id INT NOT NULL,
        cliente_id VARCHAR(20) NULL,
        vendedor_id INT NULL,
        fecha_programada DATE NULL,
        hora_visita TIME NULL,
        fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
        fecha_actualizacion DATETIME NULL,
        ciudad VARCHAR(100) NULL,
        FOREIGN KEY (usuario_id) REFERENCES usuarios_ruta(id)
    );
    PRINT '✅ Tabla rutas1 creada correctamente.';
END
ELSE
BEGIN
    IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'cliente_id')
        ALTER TABLE rutas1 ADD cliente_id VARCHAR(20) NULL;

    IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'vendedor_id')
        ALTER TABLE rutas1 ADD vendedor_id INT NULL;

    IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'fecha_programada')
        ALTER TABLE rutas1 ADD fecha_programada DATE NULL;

    IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'hora_visita')
        ALTER TABLE rutas1 ADD hora_visita TIME NULL;

    IF NOT EXISTS (SELECT * FROM syscolumns WHERE id = OBJECT_ID('rutas1') AND name = 'ciudad')
        ALTER TABLE rutas1 ADD ciudad VARCHAR(100) NULL;

    PRINT 'ℹ️ La tabla rutas1 ya existe y ha sido verificada/actualizada.';
END
";

// Ejecutar la consulta
$stmt = sqlsrv_query($conn, $sql_rutas);

// Verificar ejecución
if ($stmt === false) {
    die("❌ Error al ejecutar la consulta: " . print_r(sqlsrv_errors(), true));
} else {
    echo "✅ Operación completada correctamente.";
}

// Cerrar conexión
sqlsrv_close($conn);
?>
