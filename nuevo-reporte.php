<?php
include 'header.php';
include 'funciones/reportes.php';
include 'funciones/clientes.php';

// Conexión local a tu base de datos
global $conn; // Asegúrate de tener $conn inicializado correctamente

// ======================= FUNCIONES AUXILIARES SAP =======================

// 1. Obtener el NIT del usuario logueado
function obtenerNitUsuario($usuario_id, $conn) {
    $sql = "SELECT nit FROM usuarios_ruta WHERE id = ?";
    $params = array($usuario_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        return $row['nit'];
    }
    return null;
}

// 2. Conexión a SAPin
function conexionSAP() {
    $serverName = "HERCULES"; // Cambia si tu SQL Server SAP está en otro host
    $connectionInfo = array("Database" => "RBOSKY3", "UID" => "sa", "PWD" => "Sky2022*!");
    $connSAP = sqlsrv_connect($serverName, $connectionInfo);
    if ($connSAP === false) {
        echo "<div style='color:red;'><b>Error de conexión a SAP:</b><br>";
        print_r(sqlsrv_errors());
        echo "</div>";
        return false;
    }
    return $connSAP;
}

// 3. Traer clientes de SAP cuyo SlpCode coincide con el NIT (SlpCode del vendedor) del usuario
function obtenerClientesPorNit($nit) {
    $connSAP = conexionSAP();
    $clientes = [];
    if ($connSAP === false) {
        return $clientes;
    }
    // Cambiado: ahora busca por T1.[SlpCode]
    $sql = "SELECT distinct T0.[CardCode], T0.[CardFName],T0.[CardName], T1.[SlpCode], T1.[SlpName]
            FROM OCRD T0
            INNER JOIN OSLP T1 ON T0.[SlpCode] = T1.[SlpCode]
            WHERE T0.[validFor] = 'Y' AND T1.[SlpCode] = ?";
    $params = array($nit);
    $stmt = sqlsrv_query($connSAP, $sql, $params);
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $clientes[] = array(
                'id' => $row['CardCode'],
                'nombre' => $row['CardFName'],
                'nombre1' => $row['CardName'],
                'slpcode' => $row['SlpCode'],
                'slpname' => $row['SlpName']
            );
        }
    }
    sqlsrv_close($connSAP);
    return $clientes;
}

// 4. Obtener información completa de un cliente SAP
function obtenerClienteSAP($cliente_id) {
    $connSAP = conexionSAP();
    if (!$connSAP) return null;
    $sql = "SELECT T0.[CardCode], T0.[CardFName],T0.[CardName],T1.[SlpCode], T1.[SlpName], 
                   T0.[City], T0.[Phone], T0.[Territory], T0.[GroupCode]
            FROM OCRD T0  
            INNER JOIN OSLP T1 ON T0.[SlpCode] = T1.[SlpCode] 
            WHERE T0.[CardCode] = ? AND T0.[validFor] = 'Y'";
    $params = array($cliente_id);
    $stmt = sqlsrv_query($connSAP, $sql, $params);
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($connSAP);
        return $row;
    }
    if ($stmt) sqlsrv_free_stmt($stmt);
    sqlsrv_close($connSAP);
    return null;
}

// 5. Obtener documentos de un cliente SAP (facturas, pedidos, entregas últimos 3 meses)
function obtenerDocumentosClienteSAP($cliente_id) {
    $connSAP = conexionSAP();
    $documentos = array();
    if (!$connSAP) return $documentos;

    // Facturas
    $sql_facturas = "SELECT T0.DocNum, T0.DocDate, T0.DocTotal, T0.DocStatus, 'Factura' as TipoDoc
                     FROM OINV T0
                     WHERE T0.CardCode = ? AND T0.DocDate >= DATEADD(month, -3, GETDATE())
                     ORDER BY T0.DocDate DESC";
    $stmt_facturas = sqlsrv_query($connSAP, $sql_facturas, array($cliente_id));
    if ($stmt_facturas) {
        while ($row = sqlsrv_fetch_array($stmt_facturas, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['DocDate']) && $row['DocDate']) {
                $row['DocDate'] = $row['DocDate']->format('d/m/Y');
            }
            $documentos[] = $row;
        }
        sqlsrv_free_stmt($stmt_facturas);
    }

    // Pedidos
    $sql_pedidos = "SELECT T0.DocNum, T0.DocDate, T0.DocTotal, T0.DocStatus, 'Pedido' as TipoDoc
                    FROM ORDR T0
                    WHERE T0.CardCode = ? AND T0.DocDate >= DATEADD(month, -3, GETDATE())
                    ORDER BY T0.DocDate DESC";
    $stmt_pedidos = sqlsrv_query($connSAP, $sql_pedidos, array($cliente_id));
    if ($stmt_pedidos) {
        while ($row = sqlsrv_fetch_array($stmt_pedidos, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['DocDate']) && $row['DocDate']) {
                $row['DocDate'] = $row['DocDate']->format('d/m/Y');
            }
            $documentos[] = $row;
        }
        sqlsrv_free_stmt($stmt_pedidos);
    }

    // Entregas
    $sql_entregas = "SELECT T0.DocNum, T0.DocDate, T0.DocTotal, T0.DocStatus, 'Entrega' as TipoDoc
                     FROM ODLN T0
                     WHERE T0.CardCode = ? AND T0.DocDate >= DATEADD(month, -3, GETDATE())
                     ORDER BY T0.DocDate DESC";
    $stmt_entregas = sqlsrv_query($connSAP, $sql_entregas, array($cliente_id));
    if ($stmt_entregas) {
        while ($row = sqlsrv_fetch_array($stmt_entregas, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['DocDate']) && $row['DocDate']) {
                $row['DocDate'] = $row['DocDate']->format('d/m/Y');
            }
            $documentos[] = $row;
        }
        sqlsrv_free_stmt($stmt_entregas);
    }
    sqlsrv_close($connSAP);
    return $documentos;
}

// ======================= FLUJO PRINCIPAL =======================

$usuario_id = $_SESSION['usuario_id'];
$nit = obtenerNitUsuario($usuario_id, $conn);

// Variable para controlar la redirección
$reporte_creado = false;
$mensaje = '';
$error = '';

// DEBUG: Mostrar el NIT traído para el usuario actual
echo '<div style="background: #eaf5ff; color:#00529b; padding:8px 16px; margin:10px 0; border-left: 5px solid #00529b;">';
echo '<b>Debug NIT (SlpCode) usuario actual:</b> ';
if($nit !== null && $nit !== '') {
    echo htmlspecialchars($nit);
} else {
    echo '<span style="color:red;">NO SE ENCONTRÓ NIT</span>';
}
echo '</div>';

$clientes = [];
if ($nit !== null && $nit !== '') {
    $clientes = obtenerClientesPorNit($nit);
}

$rutas = obtenerRutasParaFiltro($usuario_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = trim($_POST['titulo']);
    $contenido = trim($_POST['contenido']);
    $tipo = $_POST['tipo'];
    $cliente_id = $_POST['cliente_id'];
    $ruta_id = intval($_POST['ruta_id']);

    if (empty($titulo)) {
        $error = 'El título del reporte es obligatorio';
    } elseif (empty($contenido)) {
        $error = 'El contenido del reporte es obligatorio';
    } elseif (empty($cliente_id) || $cliente_id == "0") {
        $error = 'Debes seleccionar un cliente.';
    } else {
        try {
            // SOLUCIÓN: Usar NULL para cliente_id en lugar del valor alfanumérico
            // Esto evitará el error de conversión
            
            // Crear el reporte sin cliente_id (usando NULL)
            $sql = "INSERT INTO [Ruta].[dbo].[reportes] (titulo, contenido, tipo, usuario_id, cliente_id, fecha_creacion) 
                    VALUES (?,?, ?, ?, ?, GETDATE())";
            
            $params = array(
                $titulo,
                $contenido,
                $tipo,
                $usuario_id,
                $cliente_id
            );
            
            $stmt = sqlsrv_query($conn, $sql, $params);
            
            if ($stmt === false) {
                $errors = sqlsrv_errors();
                throw new Exception('Error al crear el reporte: ' . $errors[0]['message']);
            }
            
            // Obtener el ID del reporte recién creado
            $sql_id = "SELECT MAX(id) as ultimo_id FROM [Ruta].[dbo].[reportes] WHERE usuario_id = ?";
            $params_id = array($usuario_id);
            $stmt_id = sqlsrv_query($conn, $sql_id, $params_id);
            
            if ($stmt_id && $row = sqlsrv_fetch_array($stmt_id, SQLSRV_FETCH_ASSOC)) {
                $reporte_id = $row['ultimo_id'];
                
                // Ahora, guardar la relación con el cliente en otra tabla o de otra manera
                // Por ejemplo, podrías crear una tabla reportes_clientes para almacenar esta relación
                
                // Alternativamente, puedes guardar solo el código numérico del cliente
                // si es que el problema es con la parte alfabética
                if (preg_match('/^[A-Za-z](\d+)$/', $cliente_id, $matches)) {
                    $cliente_id_numerico = $matches[1]; // Extraer solo la parte numérica
                    
                    // Actualizar el reporte con el cliente_id numérico
                    $sql_update = "UPDATE [Ruta].[dbo].[reportes] SET cliente_id = ? WHERE id = ?";
                    $params_update = array($cliente_id_numerico, $reporte_id);
                    sqlsrv_query($conn, $sql_update, $params_update);
                }
                
                // Si se seleccionó una ruta, actualizar el reporte con la ruta_id
                if ($ruta_id > 0) {
                    $sql_update_ruta = "UPDATE [Ruta].[dbo].[reportes] SET ruta_id = ? WHERE id = ?";
                    $params_update_ruta = array($ruta_id, $reporte_id);
                    sqlsrv_query($conn, $sql_update_ruta, $params_update_ruta);
                }
            }
            
            // En lugar de redireccionar, establecer una variable para mostrar un mensaje
            $reporte_creado = true;
            $mensaje = 'Reporte creado exitosamente.';
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Si el reporte fue creado, mostrar un mensaje y un enlace para volver
if ($reporte_creado) {
    echo '<div class="alert alert-success" style="background-color: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px; border: 1px solid #c3e6cb; border-radius: 4px;">';
    echo '<i class="fas fa-check-circle"></i> ' . $mensaje;
    echo '<br><br><a href="reportes.php" class="btn-primary" style="display: inline-block; padding: 8px 16px; background-color: #007bff; color: white; text-decoration: none; border-radius: 4px;">Volver a Reportes</a>';
    echo '</div>';
} else {
?>
<style> [data-theme="oscuro"] {
            --primary-color: #3b82f6;
            --primary-light: #60a5fa;
            --primary-hover: #2563eb;
            --secondary-color: #1e293b;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --text-color: #e2e8f0;
            --text-light: #94a3b8;
            --text-muted: #64748b;
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
                --primary-hover: #2563eb;
                --secondary-color: #1e293b;
                --success-color: #10b981;
                --danger-color: #ef4444;
                --text-color: #e2e8f0;
                --text-light: #94a3b8;
                --text-muted: #64748b;
                --bg-color: #0f172a;
                --card-bg: #1e293b;
                --border-color: #334155;
                --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.2);
                --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -1px rgba(0, 0, 0, 0.2);
            }
        }</style>
<div class="page-header">
    <div class="header-actions">
 
        <a href="reportes.php" class="btn-secondary">
        <link rel="stylesheet" href="estilos-adicionales.css">
            <i class="fas fa-arrow-left"></i> Volver a Reportes
        </a>
    </div>
</div>

<div class="form-container">
    <div class="form-card">
        <div class="form-header">
            <h2><i class="fas fa-file-alt"></i> Nuevo Reporte</h2>
            <p>Completa el formulario para crear un nuevo reporte</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($mensaje && !$reporte_creado): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="titulo">Título del Reporte <span class="required">*</span></label>
                <input type="text" id="titulo" name="titulo" value="<?php echo isset($_POST['titulo']) ? htmlspecialchars($_POST['titulo']) : ''; ?>" required>
            </div>
            
            <div class="form-group">
                <label for="tipo">Tipo de Reporte <span class="required">*</span></label>
                <select id="tipo" name="tipo" required>
                    <option value="visita" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'visita') ? 'selected' : ''; ?> selected>Visita</option>
                    <option value="incidencia" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'incidencia') ? 'selected' : ''; ?>>Incidencia</option>
                    <option value="inventario" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'inventario') ? 'selected' : ''; ?>>Inventario</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="cliente_id">Cliente (solo los asignados a tu NIT/SlpCode) <span class="required">*</span></label>
                <select id="cliente_id" name="cliente_id" required>
                    <option value="0">Seleccionar Cliente</option>
                    <?php if (empty($clientes)): ?>
                        <option value="">No hay clientes asociados a tu NIT/SlpCode</option>
                    <?php else: ?>
                        <?php foreach ($clientes as $cliente): ?>
                            <option value="<?php echo $cliente['id']; ?>" <?php echo (isset($_POST['cliente_id']) && $_POST['cliente_id'] == $cliente['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cliente['nombre']) . " - " . htmlspecialchars($cliente['nombre1']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="ruta_id">Ruta (Opcional)</label>
                <select id="ruta_id" name="ruta_id">
                    <option value="0">Seleccionar Ruta</option>
                    <?php foreach ($rutas as $ruta): ?>
                        <option value="<?php echo $ruta['id']; ?>" <?php echo (isset($_POST['ruta_id']) && $_POST['ruta_id'] == $ruta['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ruta['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            
            <div class="form-group">
                <label for="contenido">Contenido del Reporte <span class="required">*</span></label>
                <textarea id="contenido" name="contenido" rows="10" required><?php echo isset($_POST['contenido']) ? htmlspecialchars($_POST['contenido']) : ''; ?></textarea>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save"></i> Guardar Reporte
                </button>
                <a href="reportes.php" class="btn-secondary">
                    <i class="fas fa-times"></i> Cancelar
                </a>
            </div>
        </form>
    </div>
</div>

<?php 
} // Cierre del else (si el reporte no fue creado)
include 'footer.php'; 
?>