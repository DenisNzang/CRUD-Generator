<?php
// generator.php - Generador de Aplicaciones CRUD para SQLite
// Compatible con PHP 8.3.6

// Configuración inicial
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Función para sanitizar datos
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data ?? '')));
}

// Función para guardar la configuración
function saveConfiguration($config, $filename = '') {
    if (empty($filename)) {
        $filename = 'crud_config_' . date('Y-m-d_His') . '.json';
    }
    
    // Preparar datos para guardar (excluir archivos temporales)
    $saveData = [
        'app_title' => $config['app_title'] ?? '',
        'primary_color' => $config['primary_color'] ?? '#0d6efd',
        'selected_tables' => $config['selected_tables'] ?? [],
        'tables_config' => $config['tables_config'] ?? [],
        'db_filename' => $config['db_filename'] ?? '',
        'logo_filename' => $config['logo_filename'] ?? '',
        'saved_at' => date('Y-m-d H:i:s')
    ];
    
    $jsonData = json_encode($saveData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if (file_put_contents($filename, $jsonData)) {
        return $filename;
    }
    
    return false;
}

// Función para cargar la configuración
function loadConfiguration($filename) {
    if (!file_exists($filename)) {
        return false;
    }
    
    $jsonData = file_get_contents($filename);
    $config = json_decode($jsonData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }
    
    return $config;
}

// Función para obtener las tablas de la base de datos SQLite
function getTables($dbFile) {
    try {
        $db = new PDO("sqlite:$dbFile");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Filtrar tablas del sistema
        $tables = array_filter($tables, function($table) {
            return $table !== 'sqlite_sequence' && $table !== 'user';
        });
        
        return array_values($tables);
    } catch (PDOException $e) {
        return array();
    }
}

// Función para obtener la estructura de una tabla
function getTableStructure($dbFile, $tableName) {
    try {
        $db = new PDO("sqlite:$dbFile");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Obtener información de columnas
        $stmt = $db->query("PRAGMA table_info($tableName)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener claves foráneas
        $stmt = $db->query("PRAGMA foreign_key_list($tableName)");
        $foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array('columns' => $columns, 'foreign_keys' => $foreignKeys);
    } catch (PDOException $e) {
        return array('columns' => array(), 'foreign_keys' => array());
    }
}

// Función para determinar el tipo de control HTML basado en el tipo de columna
function getFormControlType($columnType, $columnName) {
    $type = strtolower($columnType ?? '');
    
    // Si es una clave foránea, usar select
    if (strpos($columnName, '_id') !== false || strpos($columnName, 'id_') !== false) {
        return 'select';
    }
    
    // Mapear tipos SQLite a controles HTML
    if (strpos($type, 'int') !== false || strpos($type, 'integer') !== false) {
        return 'number';
    } elseif (strpos($type, 'real') !== false || strpos($type, 'float') !== false || 
              strpos($type, 'double') !== false || strpos($type, 'decimal') !== false) {
        return 'number';
    } elseif (strpos($type, 'bool') !== false) {
        return 'checkbox';
    } elseif (strpos($type, 'date') !== false) {
        return 'date';
    } elseif (strpos($type, 'time') !== false) {
        return 'time';
    } elseif (strpos($type, 'datetime') !== false) {
        return 'datetime-local';
    } elseif (strpos($type, 'email') !== false || $columnName === 'email') {
        return 'email';
    } elseif (strpos($type, 'url') !== false || $columnName === 'url') {
        return 'url';
    } elseif (strpos($type, 'text') !== false || strpos($type, 'char') !== false) {
        return 'text';
    } else {
        return 'text';
    }
}

// Función para generar el código de la aplicación CRUD
function generateCRUDApp($config) {
    $outputDir = 'generated_app/';
    
    // Crear directorio si no existe
    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0777, true);
    }
    
    // Crear subdirectorio para la base de datos en assets/db
    $dbDir = $outputDir . 'assets/db/';
    if (!file_exists($dbDir)) {
        mkdir($dbDir, 0777, true);
    }
    
    // GUARDAR la ruta original del archivo de base de datos
    $originalDbPath = '';
    if (!empty($config['db_file']) && file_exists($config['db_file'])) {
        $originalDbPath = $config['db_file']; // Guardar ruta original
        $dbFileName = basename($config['db_file']);
        $newDbPath = $dbDir . $dbFileName;
        
        // Verificar y copiar el archivo
        if (copy($config['db_file'], $newDbPath)) {
            // Actualizar la ruta en la configuración para que apunte a assets/db/
            $config['db_file'] = 'assets/db/' . $dbFileName;
        } else {
            // Si falla la copia, mantener la ruta original
            error_log("Error al copiar la base de datos a: " . $newDbPath);
        }
    }
    
    // Pasar la ruta original a la configuración para uso en generateCRUDTable
    $config['original_db_file'] = $originalDbPath;

    // Añadir el color a la configuración
    $config['primary_color'] = $_POST['primary_color'] ?? '#0d6efd';
    
    // Mover el logo a assets/img/ si existe
    if (!empty($config['logo_path']) && file_exists($config['logo_path'])) {
        $imgDir = $outputDir . 'assets/img/';
        if (!file_exists($imgDir)) {
            mkdir($imgDir, 0777, true);
        }
        $logoFileName = basename($config['logo_path']);
        $newLogoPath = $imgDir . $logoFileName;
        if (copy($config['logo_path'], $newLogoPath)) {
            // Actualizar la ruta en la configuración para que apunte a assets/img/
            $config['logo_path'] = 'assets/img/' . $logoFileName;
        }
    }

    // Generar archivos
    generateIndex($outputDir, $config);
    generateHeader($outputDir, $config);
    generateFooter($outputDir, $config);
    generateLogin($outputDir, $config);
    generateLogout($outputDir, $config);
    generateAuth($outputDir, $config);
    generateConfig($outputDir, $config);
    
    // Generar CRUD para cada tabla
    foreach ($config['tables'] as $tableName => $tableConfig) {
        generateCRUDTable($outputDir, $config, $tableName, $tableConfig);
    }
    
    // Generar assets
    generateAssets($outputDir, $config);
    
    return $outputDir;
}

// Función para generar index.php
function generateIndex($outputDir, $config) {
    $primaryColor = $config['primary_color'] ?? '#667eea';
    $logoHtml = '';
    if (!empty($config['logo_path'])) {
        $logoPath = $config['logo_path'];
        $logoHtml = '<img src="' . $logoPath . '" alt="Logo" height="120">';
    }

    $content = <<<EOT
<?php
    // La verificación de autenticación se hace en config.php
    require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$config['app_title']}</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="assets/css/buttons.dataTables.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="container-fluid mt-4" style="padding-top: 80px; padding-bottom: 80px;">
        <div class="row">
            <div class="col-md-12">
                <div class="jumbotron p-5 rounded">
                    <center><h1 class="display-4">{$config['app_title']}</h1></center>
                    <center><p>$logoHtml</p></center>
                </div>
            </div>
        </div>
    </div>
    
    <?php include 'footer.php'; ?>
    
    <script src="assets/js/jquery-3.6.0.min.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/jquery.dataTables.min.js"></script>
    <script src="assets/js/dataTables.bootstrap5.min.js"></script>
    <script src="assets/js/dataTables.buttons.min.js"></script>
    <script src="assets/js/buttons.html5.min.js"></script>
    <script src="assets/js/buttons.print.min.js"></script>
</body>
</html>
EOT;
    
    file_put_contents($outputDir . 'index.php', $content);
}

// Función para generar header.php
function generateHeader($outputDir, $config) {
    $primaryColor = $config['primary_color'] ?? '#0d6efd';
    $logoHtml = '';
    if (!empty($config['logo_path'])) {
        $logoPath = $config['logo_path'];
        $logoHtml = '<img src="' . $logoPath . '" alt="Logo" height="40" class="d-inline-block align-text-top me-2">';
    }
    
    $menuItems = '';
    foreach ($config['tables'] as $tableName => $tableConfig) {
        $displayName = !empty($tableConfig['display_name']) ? $tableConfig['display_name'] : ucfirst(str_replace('_', ' ', $tableName));
        $menuItems .= '<li><a class="dropdown-item" href="crud_' . $tableName . '.php">' . $displayName . '</a></li>';
    }

    $content = <<<EOT
<nav class="navbar navbar-expand-lg navbar-dark fixed-top" style="background-color: $primaryColor;">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">
            $logoHtml
            {$config['app_title']}
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link active" href="index.php">Inicio</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Gestión
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
                        $menuItems
                    </ul>
                </li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link">
                        Bienvenido, <?php echo htmlspecialchars(\$_SESSION['username'] ?? ''); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">Cerrar Sesión</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
EOT;
    
    file_put_contents($outputDir . 'header.php', $content);
}

// Función para generar footer.php
function generateFooter($outputDir, $config) {
    $primaryColor = $config['primary_color'] ?? '#343a40';
    $content = <<<EOT
<footer class="text-light py-3 fixed-bottom" style="background-color: $primaryColor;">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12 text-center">
                <p>&copy; <?php echo date('Y'); ?> {$config['app_title']}. Todos los derechos reservados.</p>
            </div>
        </div>
    </div>
</footer>
EOT;
    
    file_put_contents($outputDir . 'footer.php', $content);
}

// Función para generar login.php
function generateLogin($outputDir, $config) {

    $primaryColor = $config['primary_color'] ?? '#0d6efd';
    $logoHtml = '';
    if (!empty($config['logo_path'])) {
        $logoPath = $config['logo_path'];
        $logoHtml = '<img src="' . $logoPath . '" alt="Logo" height="60" class="mb-4">';
    }
    
    $content = <<<EOT
<?php
session_start();
if (isset(\$_SESSION['authenticated']) && \$_SESSION['authenticated'] === true) {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - {$config['app_title']}</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow">
                    <div class="card-body p-5 text-center">
                        $logoHtml
                        <h2 class="card-title mb-4">{$config['app_title']}</h2>
                        <h5 class="text-muted mb-4">Iniciar Sesión</h5>
                        
                        <?php if (isset(\$_SESSION['error'])): ?>
                            <div class="alert alert-danger"><?php echo \$_SESSION['error']; unset(\$_SESSION['error']); ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" action="auth.php">
                            <div class="mb-3">
                                <input type="text" class="form-control" name="username" placeholder="Usuario" required>
                            </div>
                            <div class="mb-3">
                                <input type="password" class="form-control" name="password" placeholder="Contraseña" required>
                            </div>
                            <button type="submit" class="btn w-100" style="background-color: $primaryColor; border-color: $primaryColor; color: white;">Iniciar Sesión</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
EOT;
    
    file_put_contents($outputDir . 'login.php', $content);
}

// Función para generar logout.php
function generateLogout($outputDir, $config) {
    $content = <<<'EOT'
<?php
session_start();
// Destruir todas las variables de sesión
$_SESSION = array();
// Destruir la sesión
session_destroy();
header('Location: login.php');
exit();
?>
EOT;
    
    file_put_contents($outputDir . 'logout.php', $content);
}

// Función para generar auth.php
function generateAuth($outputDir, $config) {
    $content = <<<'EOT'
<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    require_once 'config.php';
    
    try {
        // Buscar usuario en la base de datos
        $stmt = $pdo->prepare("SELECT id, username, password FROM user WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verificar si el usuario existe y la contraseña es correcta
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['authenticated'] = true;
            header('Location: index.php');
            exit();
        } else {
            $_SESSION['error'] = 'Usuario o contraseña incorrectos';
            header('Location: login.php');
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Error al verificar credenciales: ' . $e->getMessage();
        header('Location: login.php');
        exit();
    }
}
?>
EOT;
    
    file_put_contents($outputDir . 'auth.php', $content);
}

// Función para generar config.php
function generateConfig($outputDir, $config) {
    $dbFileName = $config['db_file'];
    $content = <<<EOT
<?php
// Configuración de sesión
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// VERIFICACIÓN DE AUTENTICACIÓN PARA TODAS LAS PÁGINAS (excepto login y auth)
\$current_page = basename(\$_SERVER['PHP_SELF']);
\$excluded_pages = ['login.php', 'auth.php', 'logout.php'];

if (!in_array(\$current_page, \$excluded_pages)) {
    if (!isset(\$_SESSION['authenticated']) || \$_SESSION['authenticated'] !== true) {
        header('Location: login.php');
        exit();
    }
}

// Configuración de la base de datos
\$dbFile = '$dbFileName';

try {
    \$pdo = new PDO("sqlite:\$dbFile");
    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException \$e) {
    die("Error de conexión: " . \$e->getMessage());
}

// Configuración de la aplicación
\$app_title = "{$config['app_title']}";
?>
EOT;
    
    file_put_contents($outputDir . 'config.php', $content);
}

// Función para generar un campo de formulario
function generateFormField($name, $label, $type, $required, $tableName) {
    $fieldId = "field_$name";
    
    // Manejar campos requeridos
    $requiredAttr = $required ? 'required' : '';
    $requiredStar = $required ? ' <span class="text-danger">*</span>' : '';
    
    switch ($type) {
        case 'textarea':
            return <<<EOT
            <div class="col-md-12 mb-3">
                <label for="$fieldId" class="form-label">$label$requiredStar</label>
                <textarea class="form-control" id="$fieldId" name="$name" rows="3" $requiredAttr></textarea>
            </div>
EOT;
        
        case 'checkbox':
            return <<<EOT
            <div class="col-md-12 mb-3">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="$fieldId" name="$name" value="1">
                    <label for="$fieldId" class="form-check-label">$label</label>
                </div>
            </div>
EOT;
        
        case 'select':
            return <<<EOT
            <div class="col-md-6 mb-3">
                <label for="$fieldId" class="form-label">$label$requiredStar</label>
                <select class="form-select" id="$fieldId" name="$name" $requiredAttr>
                    <option value="">Seleccionar...</option>
                    <!-- Opciones se cargarían dinámicamente desde la tabla relacionada -->
                </select>
            </div>
EOT;
        
        default:
            $inputClass = "col-md-6 mb-3";
            if (in_array($type, ['date', 'time', 'datetime-local'])) {
                $inputClass = "col-md-6 mb-3";
            }
            
            // Para campos numéricos, agregar step apropiado
            $stepAttr = '';
            if ($type === 'number') {
                $stepAttr = 'step="any"';
            }
            
            return <<<EOT
            <div class="$inputClass">
                <label for="$fieldId" class="form-label">$label$requiredStar</label>
                <input type="$type" class="form-control" id="$fieldId" name="$name" $requiredAttr $stepAttr>
            </div>
EOT;
    }
}

// Función auxiliar para determinar el campo a mostrar de una tabla relacionada
function getDisplayFieldForRelatedTable($dbFile, $tableName) {
    if (empty($tableName)) return 'name';
    
    try {
        $db = new PDO("sqlite:$dbFile");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Obtener información de columnas
        $stmt = $db->query("PRAGMA table_info($tableName)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar campos preferidos en este orden (priorizar campos más descriptivos)
        $preferredFields = ['nombre', 'name', 'descripcion', 'description', 'title', 'titulo', 'label', 'etiqueta'];
        
        foreach ($preferredFields as $field) {
            foreach ($columns as $column) {
                if ($column['name'] === $field) {
                    return $field;
                }
            }
        }
        
        // Si no encuentra campos preferidos, usar el primer campo de texto que no sea ID
        foreach ($columns as $column) {
            $colName = $column['name'];
            $colType = strtolower($column['type'] ?? '');
            
            if ($colName !== 'id' && $colName !== 'created_at' && $colName !== 'updated_at' &&
                (strpos($colType, 'char') !== false || 
                 strpos($colType, 'text') !== false ||
                 strpos($colName, 'nombre') !== false ||
                 strpos($colName, 'name') !== false ||
                 strpos($colName, 'desc') !== false)) {
                return $colName;
            }
        }
        
        // Si todo falla, usar el primer campo que no sea ID ni campos de fecha
        foreach ($columns as $column) {
            $colName = $column['name'];
            if ($colName !== 'id' && $colName !== 'created_at' && $colName !== 'updated_at') {
                return $colName;
            }
        }
        
        return 'id';
    } catch (PDOException $e) {
        return 'name';
    }
}

// Función para generar el CRUD de una tabla específica
function generateCRUDTable($outputDir, $config, $tableName, $tableConfig) {
    $displayName = !empty($tableConfig['display_name']) ? $tableConfig['display_name'] : ucfirst(str_replace('_', ' ', $tableName));
    $dbFileRelative = $config['db_file'];
    $dbFileForStructure = $outputDir . $dbFileRelative;
    $primaryColor = $config['primary_color'] ?? '#0d6efd';
    
    // Verificar que el archivo existe
    if (!file_exists($dbFileForStructure)) {
        error_log("Archivo de base de datos no encontrado: " . $dbFileForStructure);
        // Usar el archivo original como fallback
        if (!empty($config['original_db_file']) && file_exists($config['original_db_file'])) {
            $dbFileForStructure = $config['original_db_file'];
        }
    }
    
    $structure = getTableStructure($dbFileForStructure, $tableName);
    
    $columns = $structure['columns'] ?? [];
    $foreignKeys = $structure['foreign_keys'] ?? [];
    
    // Crear un array para mapear campos relacionados con verificación
    $relatedFields = [];
    if (is_array($foreignKeys)) {
        foreach ($foreignKeys as $fk) {
            // Asegurarse de que $fk es un array antes de acceder a sus claves
            if (is_array($fk) && isset($fk['from'])) {
                $relatedFields[$fk['from']] = [
                    'table' => $fk['table'],
                    'to' => $fk['to']
                ];
            }
        }
    }
    
    // Generar código para listar registros
    $listColumns = '';
    $dataTableColumns = '';
    $formFields = '';
    $ajaxFunctions = '';
    
    foreach ($columns as $column) {
        $columnName = $column['name'];
        $columnType = $column['type'];
        
        // Verificar si la columna está seleccionada para mostrar
        if (in_array($columnName, $tableConfig['selected_columns'])) {
            $displayTitle = !empty($tableConfig['column_titles'][$columnName]) ? 
                $tableConfig['column_titles'][$columnName] : 
                ucfirst(str_replace('_', ' ', $columnName));
            
            $listColumns .= "<th>$displayTitle</th>\n";
            
            // Para campos relacionados, usar render function para mostrar campo descriptivo
            if (isset($relatedFields[$columnName])) {
                $dataTableColumns .= "{ 
                data: '$columnName',
                render: function(data, type, row, meta) {
                    // Para campos relacionados, mostrar el campo descriptivo si está disponible
                    return row.{$columnName}_display || data;
                }
            },\n";
            } else {
                $dataTableColumns .= "{ data: '$columnName' },\n";
            }
        }
        
        // Generar campos del formulario (excluir ID autoincremental)
        if ($columnName !== 'id' || ($column['pk'] ?? 0) != 1) {
            $controlType = !empty($tableConfig['control_types'][$columnName]) ? 
                $tableConfig['control_types'][$columnName] : 
                getFormControlType($columnType, $columnName);
            
            $required = ($column['notnull'] ?? false) ? 'required' : '';
            $displayTitle = !empty($tableConfig['column_titles'][$columnName]) ? 
                $tableConfig['column_titles'][$columnName] : 
                ucfirst(str_replace('_', ' ', $columnName));
            
            // Si es un campo relacionado (clave foránea), generar select con opciones dinámicas
            if (isset($relatedFields[$columnName]) || $controlType === 'select') {
                $relatedTable = isset($relatedFields[$columnName]) ? $relatedFields[$columnName]['table'] : '';
                $relatedColumn = isset($relatedFields[$columnName]) ? $relatedFields[$columnName]['to'] : 'id';
                
                // Determinar el campo a mostrar de la tabla relacionada
                $displayField = getDisplayFieldForRelatedTable($dbFileForStructure, $relatedTable);
                
                // Definir las variables requeridas para este contexto
                $requiredStar = $required ? ' <span class="text-danger">*</span>' : '';
                $requiredAttr = $required ? 'required' : '';
                
                $formFields .= <<<EOT
                <div class="col-md-6 mb-3">
                    <label for="field_$columnName" class="form-label">$displayTitle$requiredStar</label>
                    <select class="form-select" id="field_$columnName" name="$columnName" $requiredAttr>
                        <option value="">Seleccionar...</option>
                    </select>
                </div>
EOT;
                
                // Agregar función AJAX para cargar opciones
                $ajaxFunctions .= <<<EOT

        // Cargar opciones para $columnName
        function load{$columnName}Options() {
            $.ajax({
                url: 'crud_$tableName.php',
                type: 'POST',
                data: {
                    action: 'get_related_options',
                    related_table: '$relatedTable',
                    display_field: '$displayField',
                    value_field: '$relatedColumn'
                },
                dataType: 'json',
                success: function(response) {
                    var select = $('#field_$columnName');
                    select.empty().append('<option value="">Seleccionar...</option>');
                    
                    if (response.options && response.options.length > 0) {
                        $.each(response.options, function(index, option) {
                            select.append('<option value="' + option.value + '">' + option.text + '</option>');
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error al cargar opciones para $columnName:', error);
                }
            });
        }
        
        // Cargar opciones al abrir el modal
        $('#addModal').on('show.bs.modal', function() {
            load{$columnName}Options();
        });
EOT;
            } else {
                $formFields .= generateFormField($columnName, $displayTitle, $controlType, $required, $tableName);
            }
        }
    }
    
    // Agregar columna de acciones AL PRINCIPIO
    $listColumns = "<th style=\"width: 100px;\">Acciones</th>\n" . $listColumns;
$dataTableColumns = "{ 
    data: null, 
    orderable: false, 
    searchable: false,
    render: function(data, type, row) {
        return '<div class=\"btn-group btn-group-sm\" role=\"group\">' +
               '<button class=\"btn btn-minimal edit-btn\" data-id=\"' + row.id + '\" title=\"Editar\">✏️</button>' +
               '</div>';
    }
},\n" . $dataTableColumns;
    
    // Crear array de columnas seleccionadas para PHP
    $selectedColumnsArray = $tableConfig['selected_columns'];
    
    // Agregar código PHP para manejar la carga de opciones relacionadas
    $relatedOptionsCode = '';
    if (!empty($relatedFields)) {
        $relatedOptionsCode = <<<'EOT'

// Procesar solicitud de opciones relacionadas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_related_options') {
    header('Content-Type: application/json');
    
    $relatedTable = $_POST['related_table'] ?? '';
    $displayField = $_POST['display_field'] ?? 'name';
    $valueField = $_POST['value_field'] ?? 'id';
    
    if (!empty($relatedTable)) {
        try {
            // Obtener los campos disponibles en la tabla relacionada
            $stmt = $pdo->query("PRAGMA table_info($relatedTable)");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
            
            // Verificar si el campo display existe, si no usar el campo determinado por la función
            if (!in_array($displayField, $columns)) {
                $displayField = getDisplayFieldForRelatedTable($dbFile, $relatedTable);
            }
            
            // Obtener las opciones
            $stmt = $pdo->query("SELECT $valueField, $displayField FROM $relatedTable ORDER BY $displayField");
            $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $result = [];
            foreach ($options as $option) {
                $result[] = [
                    'value' => $option[$valueField],
                    'text' => $option[$displayField] ?: 'Sin nombre (ID: ' . $option[$valueField] . ')'
                ];
            }
            
            echo json_encode(['options' => $result]);
        } catch (PDOException $e) {
            echo json_encode(['error' => 'Error al cargar opciones: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['error' => 'Tabla relacionada no especificada']);
    }
    exit();
}
EOT;
    }
    
$content = <<<EOT
<?php
// La verificación de autenticación se hace en config.php
require_once 'config.php';

// Definir campos relacionados para esta tabla
\$relatedFields = [];

EOT;

    // Agregar los campos relacionados al código PHP
    if (!empty($relatedFields)) {
        foreach ($relatedFields as $field => $relation) {
            $content .= "\$relatedFields['$field'] = " . var_export($relation, true) . ";\n";
        }
    }

$content .= <<<EOT

// Función auxiliar para obtener campo a mostrar de tabla relacionada
function getDisplayFieldForRelatedTable(\$dbFile, \$tableName) {
    if (empty(\$tableName)) return 'name';
    
    try {
        \$db = new PDO("sqlite:\$dbFile");
        \$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Obtener información de columnas
        \$stmt = \$db->query("PRAGMA table_info(\$tableName)");
        \$columns = \$stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar campos preferidos
        \$preferredFields = ['nombre', 'name', 'descripcion', 'description', 'title', 'titulo'];
        
        foreach (\$preferredFields as \$field) {
            foreach (\$columns as \$column) {
                if (\$column['name'] === \$field) {
                    return \$field;
                }
            }
        }
        
        // Usar primer campo de texto que no sea ID
        foreach (\$columns as \$column) {
            \$colName = \$column['name'];
            \$colType = strtolower(\$column['type'] ?? '');
            
            if (\$colName !== 'id' && 
                (strpos(\$colType, 'char') !== false || 
                 strpos(\$colType, 'text') !== false)) {
                return \$colName;
            }
        }
        
        // Si todo falla, primer campo no ID
        foreach (\$columns as \$column) {
            if (\$column['name'] !== 'id') {
                return \$column['name'];
            }
        }
        
        return 'id';
    } catch (PDOException \$e) {
        return 'name';
    }
}

// Procesar operaciones AJAX para obtener registros
if (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_POST['action']) && \$_POST['action'] === 'get_record') {
    header('Content-Type: application/json');
    
    if (isset(\$_POST['id'])) {
        try {
            \$id = (int)\$_POST['id'];
            
            // Construir consulta con campos relacionados
            \$selectFields = ["t.*"];
            \$joinClauses = [];
            
            // Verificar que \$relatedFields es un array antes de iterar
            if (is_array(\$relatedFields)) {
                foreach (\$relatedFields as \$column => \$relation) {
                    // Verificar que \$relation es un array válido
                    if (is_array(\$relation) && isset(\$relation['table']) && isset(\$relation['to'])) {
                        \$relatedTable = \$relation['table'];
                        \$relatedColumn = \$relation['to'];
                        \$displayField = getDisplayFieldForRelatedTable(\$dbFile, \$relatedTable);
                        
                        \$selectFields[] = "rt_\$column.\$displayField as {\$column}_display";
                        \$joinClauses[] = "LEFT JOIN \$relatedTable rt_\$column ON t.\$column = rt_\$column.\$relatedColumn";
                    }
                }
            }
            
            \$selectClause = implode(', ', \$selectFields);
            \$joinClause = implode(' ', \$joinClauses);
            
            if (!empty(\$joinClauses)) {
                \$sql = "SELECT \$selectClause FROM $tableName t \$joinClause WHERE t.id = ?";
            } else {
                \$sql = "SELECT * FROM $tableName WHERE id = ?";
            }
            
            \$stmt = \$pdo->prepare(\$sql);
            \$stmt->execute([\$id]);
            \$record = \$stmt->fetch(PDO::FETCH_ASSOC);
            
            if (\$record) {
                // Limpiar y formatear los datos para JSON
                foreach (\$record as \$key => \$value) {
                    if (\$value === null) {
                        \$record[\$key] = '';
                    }
                    // Convertir a string para evitar problemas con números grandes
                    \$record[\$key] = (string)\$record[\$key];
                }
                
                echo json_encode(\$record, JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['error' => 'Registro no encontrado']);
            }
        } catch (PDOException \$e) {
            echo json_encode(['error' => 'Error de base de datos: ' . \$e->getMessage()]);
        }
    } else {
        echo json_encode(['error' => 'ID no proporcionado']);
    }
    exit();
}
$relatedOptionsCode
// Procesar operaciones CRUD normales
if (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_POST['action'])) {
    \$isAjax = isset(\$_POST['ajax']);
    
    switch (\$_POST['action']) {
        case 'create':
            // Crear nuevo registro
            \$data = [];
            \$placeholders = [];
            \$columns = [];
            
            foreach (\$_POST as \$key => \$value) {
                if (\$key !== 'action' && \$key !== 'id' && \$key !== 'ajax') {
                    \$columns[] = \$key;
                    \$data[] = \$value;
                    \$placeholders[] = '?';
                }
            }
            
            if (!empty(\$columns)) {
                \$sql = "INSERT INTO $tableName (" . implode(', ', \$columns) . ") VALUES (" . implode(', ', \$placeholders) . ")";
                \$stmt = \$pdo->prepare(\$sql);
                if (\$stmt->execute(\$data)) {
                    \$_SESSION['success'] = 'Registro creado exitosamente';
                } else {
                    \$_SESSION['error'] = 'Error al crear el registro';
                }
            }
            break;
            
        case 'update':
            // Actualizar registro
            if (isset(\$_POST['id'])) {
                \$updates = [];
                \$data = [];
                
                foreach (\$_POST as \$key => \$value) {
                    if (\$key !== 'action' && \$key !== 'id' && \$key !== 'ajax') {
                        \$updates[] = "\$key = ?";
                        \$data[] = \$value;
                    }
                }
                
                \$data[] = \$_POST['id'];
                
                if (!empty(\$updates)) {
                    \$sql = "UPDATE $tableName SET " . implode(', ', \$updates) . " WHERE id = ?";
                    \$stmt = \$pdo->prepare(\$sql);
                    if (\$stmt->execute(\$data)) {
                        \$_SESSION['success'] = 'Registro actualizado exitosamente';
                    } else {
                        \$_SESSION['error'] = 'Error al actualizar el registro';
                    }
                }
            }
            break;
            
        case 'delete':
            // Eliminar registro
            if (isset(\$_POST['id'])) {
                \$stmt = \$pdo->prepare("DELETE FROM $tableName WHERE id = ?");
                if (\$stmt->execute([\$_POST['id']])) {
                    \$_SESSION['success'] = 'Registro eliminado exitosamente';
                } else {
                    \$_SESSION['error'] = 'Error al eliminar el registro';
                }
            }
            break;
    }
    
    // Si es una petición AJAX, devolver JSON
    if (\$isAjax) {
        header('Content-Type: application/json');
        if (isset(\$_SESSION['success'])) {
            echo json_encode(['success' => \$_SESSION['success']]);
            unset(\$_SESSION['success']);
        } elseif (isset(\$_SESSION['error'])) {
            echo json_encode(['error' => \$_SESSION['error']]);
            unset(\$_SESSION['error']);
        } else {
            echo json_encode(['message' => 'Operación completada']);
        }
        exit();
    } else {
        // Redireccionar para peticiones normales
        header('Location: crud_$tableName.php'); 
        exit();
    }
}

// Obtener datos para mostrar con campos relacionados
\$selectFields = ["t.*"];
\$joinClauses = [];

// Construir consulta con JOINS para campos relacionados con verificación
if (is_array(\$relatedFields)) {
    foreach (\$relatedFields as \$column => \$relation) {
        // Verificar que \$relation es un array válido
        if (is_array(\$relation) && isset(\$relation['table']) && isset(\$relation['to'])) {
            \$relatedTable = \$relation['table'];
            \$relatedColumn = \$relation['to'];
            \$displayField = getDisplayFieldForRelatedTable(\$dbFile, \$relatedTable);
            
            \$selectFields[] = "rt_\$column.\$displayField as {\$column}_display";
            \$joinClauses[] = "LEFT JOIN \$relatedTable rt_\$column ON t.\$column = rt_\$column.\$relatedColumn";
        }
    }
}

\$selectClause = implode(', ', \$selectFields);
\$joinClause = implode(' ', \$joinClauses);

if (!empty(\$joinClauses)) {
    \$sql = "SELECT \$selectClause FROM $tableName t \$joinClause ORDER BY t.id";
} else {
    \$sql = "SELECT * FROM $tableName ORDER BY id";
}

\$stmt = \$pdo->query(\$sql);
\$records = \$stmt->fetchAll(PDO::FETCH_ASSOC);

// Definir columnas seleccionadas para esta tabla
\$selectedColumns = [];

EOT;

    // Agregar las columnas seleccionadas al código PHP
    $content .= "\n";
    foreach ($selectedColumnsArray as $column) {
        $content .= "\$selectedColumns[] = '$column';\n";
    }

$content .= <<<EOT

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$displayName - {$config['app_title']}</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="assets/css/buttons.dataTables.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="container-fluid mt-4" style="padding-top: 80px; padding-bottom: 80px;">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4>$displayName</h4>
                        <button type="button" class="btn" style="background-color: $primaryColor; border-color: $primaryColor; color: white;" data-bs-toggle="modal" data-bs-target="#addModal">
                            <i class="fas fa-plus"></i> Agregar Nuevo
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (isset(\$_SESSION['success'])): ?>
                            <div class="alert alert-success"><?php echo \$_SESSION['success']; unset(\$_SESSION['success']); ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset(\$_SESSION['error'])): ?>
                            <div class="alert alert-danger"><?php echo \$_SESSION['error']; unset(\$_SESSION['error']); ?></div>
                        <?php endif; ?>
                        
                        <!-- Botones de exportación -->
                        <div class="mb-3">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-primary border" id="exportExcelBtn">
                                    <i class="fas fa-file-excel"></i> Exportar a Excel
                                </button>
                                <button type="button" class="btn btn-primary border" id="exportPdfBtn">
                                    <i class="fas fa-file-pdf"></i> Exportar a PDF
                                </button>
                                <button type="button" class="btn btn-primary border" id="printBtn">
                                    <i class="fas fa-print"></i> Imprimir
                                </button>
                            </div>
                        </div>
                        
                        <table id="dataTable" class="table table-striped table-bordered">
                            <thead>
                                <tr>
                                    $listColumns
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (\$records as \$record): ?>
                                <tr>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-minimal edit-btn" data-id="<?php echo htmlspecialchars(\$record['id']); ?>" title="Editar">
                                                ✏️
                                            </button>
                                        </div>
                                    </td>
                                    <?php
                                    foreach (\$selectedColumns as \$columnName) {
                                        // Mostrar campo descriptivo para campos relacionados, si existe
                                        if (isset(\$relatedFields[\$columnName]) && isset(\$record[\$columnName . '_display'])) {
                                            echo "<td>" . htmlspecialchars(\$record[\$columnName . '_display'] ?? '') . "</td>";
                                        } else {
                                            echo "<td>" . htmlspecialchars(\$record[\$columnName] ?? '') . "</td>";
                                        }
                                    }
                                    ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para agregar/editar -->
    <div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addModalLabel">Agregar Nuevo Registro</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="recordForm" method="POST">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="recordId">
                    <div class="modal-body">
                        <div class="row">
                            $formFields
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn" style="background-color: $primaryColor; border-color: $primaryColor; color: white;">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php include 'footer.php'; ?>
    
    <script src="assets/js/jquery-3.6.0.min.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/jquery.dataTables.min.js"></script>
    <script src="assets/js/dataTables.bootstrap5.min.js"></script>
    <script src="assets/js/dataTables.buttons.min.js"></script>
    <script src="assets/js/buttons.html5.min.js"></script>
    <script src="assets/js/jszip.min.js"></script>
    <script src="assets/js/pdfmake.min.js"></script>
    <script src="assets/js/vfs_fonts.js"></script>
    
    <script>
        \$(document).ready(function() {
            // Inicializar DataTable con botones de exportación
            var table = \$('#dataTable').DataTable({
                language: {
                    url: 'assets/js/Spanish.json'
                },
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                     '<"row"<"col-sm-12"tr>>' +
                     '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>' +
                     '<"row"<"col-sm-12"B>>',
                buttons: [
                    {
                        extend: 'excel',
                        text: '📊 Excel',
                        className: 'btn btn-primary border btn-sm'
                    },
                    {
                        extend: 'pdf',
                        text: '📄 PDF',
                        className: 'btn btn-primary border btn-sm'
                    }
                    // Se eliminó el botón de imprimir de aquí
                ],
                pageLength: 10,
                lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, "Todos"]],
                responsive: true,
                // Configuración importante para mantener los eventos
                drawCallback: function(settings) {
                    // Re-inicializar eventos después de cada redibujado de DataTables
                    initializeButtonEvents();
                },
                initComplete: function(settings, json) {
                    // Inicializar eventos después de la inicialización completa
                    initializeButtonEvents();
                }
            });
            
            // Botones de exportación personalizados
            \$('#exportExcelBtn').on('click', function() {
                table.button('.buttons-excel').trigger();
            });
            
            \$('#exportPdfBtn').on('click', function() {
                table.button('.buttons-pdf').trigger();
            });
            
            \$('#printBtn').on('click', function() {
                window.print();
            });
            
            $ajaxFunctions
            
            // Inicializar todos los eventos de botones
            function initializeButtonEvents() {
                
                // Evento para botón EDITAR
                \$(document).off('click', '.edit-btn').on('click', '.edit-btn', function() {
                    var id = \$(this).data('id');
                    console.log('Editar clicked, ID:', id);
                    
                    if (!id) {
                        alert('ID no válido');
                        return;
                    }
                    
                    // Hacer petición AJAX para obtener los datos del registro
                    \$.ajax({
                        url: 'crud_$tableName.php',
                        type: 'POST',
                        data: {
                            action: 'get_record',
                            id: id
                        },
                        dataType: 'json',
                        success: function(response) {
                            console.log('Datos recibidos para editar:', response);
                            try {
                                if (response.error) {
                                    alert('Error: ' + response.error);
                                    return;
                                }
                                
                                var record = response;
                                
                                // Limpiar el formulario primero
                                \$('#recordForm')[0].reset();
                                
                                // Llenar el formulario con los datos del registro
                                for (var key in record) {
                                    if (record.hasOwnProperty(key)) {
                                        var field = \$('#recordForm [name=\"' + key + '\"]');
                                        if (field.length > 0) {
                                            var value = record[key];
                                            
                                            // Manejar valores NULL o undefined
                                            if (value === null || value === undefined) {
                                                value = '';
                                            }
                                            
                                            if (field.attr('type') === 'checkbox') {
                                                // Para checkboxes
                                                field.prop('checked', 
                                                    value == 1 || 
                                                    value === true || 
                                                    value === '1' || 
                                                    String(value).toLowerCase() === 'true'
                                                );
                                            } else if (field.is('select')) {
                                                // Para select, cargar opciones primero y luego seleccionar
                                                loadSelectOptions(field.attr('id'), function() {
                                                    field.val(value);
                                                });
                                            } else if (field.attr('type') === 'number') {
                                                // Para números
                                                field.val(value !== '' && value !== null ? parseFloat(value) : '');
                                            } else {
                                                // Para otros campos
                                                field.val(value);
                                            }
                                        }
                                    }
                                }
                                
                                // Cambiar el modo del formulario a edición
                                \$('#formAction').val('update');
                                \$('#recordId').val(id);
                                \$('#addModalLabel').text('Editar Registro');
                                \$('#addModal').modal('show');
                                
                            } catch (e) {
                                console.error('Error:', e);
                                alert('Error al procesar los datos del registro: ' + e.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX Error:', status, error);
                            alert('Error al cargar los datos del registro: ' + error);
                        }
                    });
                });
            }
            
            // Función auxiliar para cargar opciones de select
            function loadSelectOptions(selectId, callback) {
                var fieldName = selectId.replace('field_', '');
                if (typeof window['load' + fieldName + 'Options'] === 'function') {
                    window['load' + fieldName + 'Options']();
                    if (callback) setTimeout(callback, 500);
                } else if (callback) {
                    callback();
                }
            }
            
            // Inicializar eventos al cargar la página
            initializeButtonEvents();
            
            // Resetear formulario cuando se cierre el modal
            \$('#addModal').on('hidden.bs.modal', function () {
                \$('#recordForm')[0].reset();
                \$('#formAction').val('create');
                \$('#recordId').val('');
                \$('#addModalLabel').text('Agregar Nuevo Registro');
            });

            // Manejar envío del formulario para prevenir recarga de página
            \$('#recordForm').on('submit', function(e) {
                e.preventDefault();
                
                var formData = \$(this).serialize();
                
                \$.ajax({
                    url: 'crud_$tableName.php',
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        // Recargar la página para ver los cambios
                        window.location.reload();
                    },
                    error: function(xhr, status, error) {
                        alert('Error al guardar el registro: ' + error);
                    }
                });
            });
        });
    </script>
</body>
</html>
EOT;
    
    file_put_contents($outputDir . 'crud_' . $tableName . '.php', $content);
}

// Función para generar assets
function generateAssets($outputDir, $config) {
    $assetsDir = $outputDir . 'assets/';
    $cssDir = $assetsDir . 'css/';
    $jsDir = $assetsDir . 'js/';
    $dbDir = $assetsDir . 'db/';
    $imgDir = $assetsDir . 'img/';
    $webfontsDir = $assetsDir . 'webfonts/';
    
    // Crear directorios
    if (!file_exists($cssDir)) mkdir($cssDir, 0777, true);
    if (!file_exists($jsDir)) mkdir($jsDir, 0777, true);
    if (!file_exists($dbDir)) mkdir($dbDir, 0777, true);
    if (!file_exists($imgDir)) mkdir($imgDir, 0777, true);
    if (!file_exists($webfontsDir)) mkdir($webfontsDir, 0777, true);
    
    // Crear archivo CSS básico
    $primaryColor = $config['primary_color'] ?? '#0d6efd';
    $cssContent = <<<EOT
:root {
    --primary-color: $primaryColor;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
}

.min-vh-100 {
    min-height: 100vh;
}

.table th {
    background-color: #f8f9fa;
    font-weight: 600;
}

.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.card-header {
    background-color: #fff;
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.navbar-brand {
    font-weight: 600;
}

.dt-buttons {
    margin-bottom: 1rem;
}

.dt-buttons .btn {
    margin-right: 0.5rem;
    margin-bottom: 0.5rem;
}

.btn {
    border-radius: 0.375rem;
}

.btn-primary {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
}

.btn-primary:hover {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
    opacity: 0.9;
}

/* Botones minimalistas horizontales */
.btn-minimal {
    background: transparent;
    border: 1px solid #dee2e6;
    color: #6c757d;
    padding: 4px 8px;
    font-size: 0.875rem;
    margin: 0 1px;
    border-radius: 3px;
    transition: all 0.2s ease;
    width: auto;
}

.btn-minimal:hover {
    background: #f8f9fa;
    border-color: #adb5bd;
    color: #495057;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.btn-minimal:active {
    transform: translateY(0);
    box-shadow: none;
}

.btn-group.btn-group-sm {
    width: auto;
}

.btn-group.btn-group-sm > .btn {
    border-radius: 3px;
}

.navbar-dark {
    background-color: var(--primary-color) !important;
}

.bg-primary {
    background-color: var(--primary-color) !important;
}

.form-control, .form-select {
    border-radius: 0.375rem;
}

.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter {
    margin: 1rem 0;
},
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_processing,
.dataTables_wrapper .dataTables_paginate {
    margin: 1rem 0;
}

.pagination .page-item.active .page-link { background-color: var(--primary-color); }

div.dataTables_wrapper div.dataTables_paginate ul.pagination .page-item.active .page-link:focus {
background-color: var(--primary-color);
}

.pagination .page-item.active .page-link:hover {
background-color: var(--primary-color);
}

.btn-group .btn {
    margin-right: 0.25rem;
}

.modal-body table th {
    background-color: #f8f9fa;
    width: 30%;
}

/* Estilos para mejorar la visualización de datos */
#viewModalBody table {
    width: 100%;
}

#viewModalBody th {
    background-color: #f1f3f4;
    padding: 8px 12px;
}

#viewModalBody td {
    padding: 8px 12px;
    border-bottom: 1px solid #dee2e6;
}
EOT;
    
    file_put_contents($cssDir . 'style.css', $cssContent);
    
    // Crear archivo de idioma español para DataTables
    $spanishJson = <<<EOT
{
    "processing": "Procesando...",
    "lengthMenu": "Mostrar _MENU_ registros",
    "zeroRecords": "No se encontraron resultados",
    "emptyTable": "Ningún dato disponible en esta tabla",
    "info": "Mostrando registros del _START_ al _END_ de un total de _TOTAL_ registros",
    "infoEmpty": "Mostrando registros del 0 al 0 de un total de 0 registros",
    "infoFiltered": "(filtrado de un total de _MAX_ registros)",
    "search": "Buscar:",
    "loadingRecords": "Cargando...",
    "paginate": {
        "first": "Primero",
        "last": "Último",
        "next": "Siguiente",
        "previous": "Anterior"
    },
    "aria": {
        "sortAscending": ": Activar para ordenar la columna de manera ascendente",
        "sortDescending": ": Activar para ordenar la columna de manera descendente"
    },
    "buttons": {
        "copy": "Copiar",
        "colvis": "Visibilidad",
        "collection": "Colección",
        "colvisRestore": "Restaurar visibilidad",
        "copyKeys": "Presione ctrl o u2318 + C para copiar los datos de la tabla al portapapeles del sistema. <br \/> <br \/> Para cancelar, haga clic en este mensaje o presione escape.",
        "copySuccess": {
            "1": "Copiada 1 fila al portapapeles",
            "_": "Copiadas %d filas al portapapeles"
        },
        "copyTitle": "Copiar al portapapeles",
        "csv": "CSV",
        "excel": "Excel",
        "pageLength": {
            "-1": "Mostrar todas las filas",
            "_": "Mostrar %d filas"
        },
        "pdf": "PDF",
        "print": "Imprimir",
        "renameState": "Cambiar nombre",
        "updateState": "Actualizar",
        "createState": "Crear Estado",
        "removeAllStates": "Remover Estados",
        "removeState": "Remover",
        "savedStates": "Estados Guardados",
        "stateRestore": "Estado %d"
    }
}
EOT;
    
    file_put_contents($jsDir . 'Spanish.json', $spanishJson);
}

// Procesar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'load_tables':
            if (isset($_FILES['db_file'])) {
                $dbFile = $_FILES['db_file']['tmp_name'];
                $tables = getTables($dbFile);
                
                $html = '';
                foreach ($tables as $table) {
                    $displayName = ucfirst(str_replace('_', ' ', $table));
                    $html .= <<<EOT
                    <div class="form-check mb-2">
                        <input class="form-check-input table-checkbox" type="checkbox" name="selected_tables[]" value="$table" id="table_$table" checked>
                        <label class="form-check-label" for="table_$table">
                            $displayName
                        </label>
                    </div>
EOT;
                }
                
                echo $html;
            }
            exit;
            
        case 'load_columns':
            if (isset($_FILES['db_file']) && isset($_POST['tables'])) {
                $dbFile = $_FILES['db_file']['tmp_name'];
                $tables = json_decode($_POST['tables'], true);
                $result = array();
                
                foreach ($tables as $table) {
                    $structure = getTableStructure($dbFile, $table);
                    $result[$table] = array(
                        'displayName' => ucfirst(str_replace('_', ' ', $table)),
                        'columns' => array()
                    );
                    
                    foreach ($structure['columns'] as $column) {
                        $result[$table]['columns'][] = array(
                            'name' => $column['name'],
                            'type' => $column['type'],
                            'displayName' => ucfirst(str_replace('_', ' ', $column['name'])),
                            'defaultControl' => getFormControlType($column['type'], $column['name'])
                        );
                    }
                }
                
                echo json_encode($result);
            }
            exit;

        case 'save_config':
            $configData = json_decode($_POST['config_data'], true);
            if ($configData) {
                $filename = $_POST['config_filename'] ?? '';
                $savedFile = saveConfiguration($configData, $filename);
                if ($savedFile) {
                    echo json_encode(['success' => true, 'filename' => $savedFile]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'No se pudo guardar la configuración']);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Datos de configuración inválidos']);
            }
            exit;

        case 'load_config':
            if (isset($_FILES['config_file'])) {
                $configFile = $_FILES['config_file']['tmp_name'];
                $config = loadConfiguration($configFile);
                if ($config) {
                    echo json_encode(['success' => true, 'config' => $config]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'No se pudo cargar la configuración o el archivo es inválido']);
                }
            }
            exit;
    }
}

// Procesamiento del formulario principal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
    // Recoger datos del formulario
    $config = array(
        'db_file' => '',
        'logo_path' => '',
        'app_title' => $_POST['app_title'] ?? 'Mi Aplicación CRUD',
        'primary_color' => $_POST['primary_color'] ?? '#0d6efd',
        'tables' => array()
    );
    
    // Procesar archivo de base de datos
    if (isset($_FILES['db_file']) && $_FILES['db_file']['error'] === UPLOAD_ERR_OK) {
        // Guardar temporalmente para procesar la estructura
        $tempDbPath = basename($_FILES['db_file']['name']);
        move_uploaded_file($_FILES['db_file']['tmp_name'], $tempDbPath);
        $config['db_file'] = $tempDbPath;
        $config['db_filename'] = $_FILES['db_file']['name'];
    }

    // Procesar archivo de logo
    if (isset($_FILES['logo_path']) && $_FILES['logo_path']['error'] === UPLOAD_ERR_OK) {
        // Guardar temporalmente
        $tempLogoPath = basename($_FILES['logo_path']['name']);
        move_uploaded_file($_FILES['logo_path']['tmp_name'], $tempLogoPath);
        $config['logo_path'] = $tempLogoPath;
        $config['logo_filename'] = $_FILES['logo_path']['name'];
    }
    
    // Procesar selección de tablas y columnas
    if (isset($_POST['selected_tables'])) {
        $config['selected_tables'] = $_POST['selected_tables'];
        $config['tables_config'] = array();
        
        foreach ($_POST['selected_tables'] as $tableName) {
            $config['tables'][$tableName] = array(
                'selected_columns' => $_POST['columns'][$tableName] ?? array(),
                'column_titles' => $_POST['column_titles'][$tableName] ?? array(),
                'control_types' => $_POST['control_types'][$tableName] ?? array(),
                'display_name' => $_POST['display_names'][$tableName] ?? ucfirst(str_replace('_', ' ', $tableName))
            );

            $config['tables_config'][$tableName] = $config['tables'][$tableName];
        }
    }
    
    // Guardar configuración si se solicitó
    if (isset($_POST['save_config']) && $_POST['save_config'] === '1') {
        $configFilename = $_POST['config_filename'] ?? '';
        saveConfiguration($config, $configFilename);
    }
    
    // Generar la aplicación y obtener el directorio de salida
    $outputDir = generateCRUDApp($config);
    
    // Limpiar archivos temporales
    if (file_exists($config['db_file'])) {
        unlink($config['db_file']);
    }
    if (!empty($config['logo_path']) && file_exists($config['logo_path'])) {
        unlink($config['logo_path']);
    }
    
    // Mostrar mensaje de éxito
    $outputDirDisplay = $outputDir;

    echo "<!DOCTYPE html>
    <html lang='es'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Aplicación Generada</title>
        <link href='assets/css/bootstrap.min.css' rel='stylesheet'>
    </head>
    <body class='bg-light'>
        <div class='container py-5'>
            <div class='row justify-content-center'>
                <div class='col-md-8'>
                    <div class='card shadow'>
                        <div class='card-header bg-success text-white'>
                            <h2 class='text-center mb-0'>Aplicación Generada Exitosamente</h2>
                        </div>
                        <div class='card-body'>
                            <div class='alert alert-success'>
                                <h4>¡La aplicación CRUD ha sido generada exitosamente!</h4>
                                <p>La aplicación se ha guardado en el directorio: <code>$outputDirDisplay</code></p>
                                <p>La base de datos se ha guardado en: <code>assets/db/</code></p>
                            </div>
                            
                            <div class='mb-4'>
                                <h5>Archivos generados:</h5>
                                <ul>
                                    <li>index.php - Página principal</li>
                                    <li>login.php - Formulario de login</li>
                                    <li>header.php & footer.php - Cabecera y pie de página</li>
                                    <li>config.php - Configuración de la aplicación</li>
                                    <li>auth.php - Autenticación de usuarios</li>
                                    <li>logout.php - Cerrar sesión</li>";

    foreach ($config['tables'] as $tableName => $tableConfig) {
        $displayName = $tableConfig['display_name'];
        echo "<li>crud_$tableName.php - CRUD para la tabla $displayName</li>";
    }

    echo "                  </ul>
                        </div>
                        
                        <div class='alert alert-info'>
                            <h6>Instrucciones:</h6>
                            <ol class='mb-0'>
                                <li>La aplicación se ha generado en la carpeta <strong>$outputDirDisplay</strong></li>
                                <li>La base de datos está en <strong>assets/db/</strong></li>
                                <li>Accede a la aplicación mediante: <code>http://localhost/$outputDirDisplay</code></li>
                                <li>Asegúrate de que la base de datos tenga una tabla 'user' con usuarios para el login</li>
                            </ol>
                        </div>
                        
                        <div class='d-grid gap-2 d-md-flex justify-content-md-center'>
                            <a href='$outputDirDisplay' class='btn btn-primary btn-lg me-md-2'>Abrir Aplicación Generada</a>
                            <a href='generator.php' class='btn btn-secondary btn-lg'>Generar Otra Aplicación</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>";    
    exit();
}

// Mostrar formulario principal
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRUD Generator</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .step {
            display: none;
        }
        .step.active {
            display: block;
        }
        .nav-tabs .nav-link.active {
            font-weight: 600;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .config-section {
            border-left: 4px solid #0d6efd;
            padding-left: 15px;
            margin: 20px 0;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
    <div class="d-flex align-items-center">
        <img src="assets/img/sti_logo.png" alt="Logotipo STIBATA" width="200px" class="me-3">
        <h2 class="mb-0 text-center flex-grow-1">CRUD Generator by STIBATA</h2>
    </div>
</div>
                    <div class="card-body">
                        <!-- Sección de gestión de configuraciones -->
                        <div class="config-section mb-4">
                            <h5>Gestión de Proyectos</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <input type="file" class="form-control" id="load_config_file" accept=".json">
                                        <div class="form-text">Seleccione un archivo de Proyecto guardado previamente.</div>
                                    </div>
                                    <button type="button" class="btn btn-outline-primary" onclick="loadConfiguration()">
                                        <i class="fas fa-upload"></i> Abrir Proyecto
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <input type="text" class="form-control" id="config_filename" placeholder="crud_config.json">
                                        <div class="form-text">Nombre del archivo para guardar el Proyecto actual.</div>
                                    </div>
                                    <button type="button" class="btn btn-outline-success" onclick="saveConfiguration()">
                                        <i class="fas fa-save"></i> Guardar Proyecto
                                    </button>
                                </div>
                            </div>
                        </div>

                        <form id="generatorForm" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="save_config" id="save_config" value="0">
                            <input type="hidden" name="config_filename" id="hidden_config_filename" value="">
                            
                            <!-- Paso 1: Configuración básica -->
                            <div id="step1" class="step active">
                                <h4 class="mb-4">Configuración Básica</h4>
                                
                                <div class="mb-3">
                                    <label for="db_file" class="form-label">Archivo de Base de Datos SQLite *</label>
                                    <input type="file" class="form-control" id="db_file" name="db_file" accept=".sqlite,.db,.sqlite3" required>
                                    <div class="form-text">Seleccione el archivo de base de datos SQLite. Debe contener una tabla 'user' con campos 'username' y 'password' para el login.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="logo_path" class="form-label">Logotipo de la Aplicación</label>
                                    <input type="file" class="form-control" id="logo_path" name="logo_path" accept="image/*">
                                    <div class="form-text">Seleccione una imagen para usar como logotipo (opcional). Formatos: JPG, PNG, GIF.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="app_title" class="form-label">Título de la Aplicación *</label>
                                    <input type="text" class="form-control" id="app_title" name="app_title" value="Mi Aplicación CRUD" required>
                                    <div class="form-text">Este título aparecerá en la cabecera de todas las páginas.</div>
                                </div>

                                <div class="mb-3">
                                    <label for="primary_color" class="form-label">Color Principal de la Interfaz</label>
                                    <input type="color" class="form-control form-control-color" id="primary_color" name="primary_color" value="#0d6efd">
                                    <div class="form-text">Seleccione el color principal para la navbar, botones y elementos destacados.</div>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-4">
                                    <button type="button" class="btn btn-secondary" disabled>Anterior</button>
                                    <button type="button" class="btn btn-primary" onclick="nextStep(1)">Siguiente</button>
                                </div>
                            </div>
                            
                            <!-- Paso 2: Selección de tablas -->
                            <div id="step2" class="step">
                                <h4 class="mb-4">Selección de Tablas</h4>
                                <p class="text-muted mb-3">Seleccione las tablas que desea incluir en la aplicación CRUD:</p>
                                
                                <div id="tablesContainer" class="mb-3">
                                    <div class="text-center">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Cargando tablas...</span>
                                        </div>
                                        <p class="mt-2">Cargando tablas de la base de datos...</p>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-4">
                                    <button type="button" class="btn btn-secondary" onclick="prevStep(2)">Anterior</button>
                                    <button type="button" class="btn btn-primary" onclick="nextStep(2)">Siguiente</button>
                                </div>
                            </div>
                            
                            <!-- Paso 3: Configuración de columnas -->
                            <div id="step3" class="step">
                                <h4 class="mb-4">Configuración de Columnas</h4>
                                <p class="text-muted mb-3">Configure las columnas que desea mostrar y sus propiedades:</p>
                                
                                <ul class="nav nav-tabs" id="columnsTabs" role="tablist">
                                    <!-- Las pestañas se generarán dinámicamente -->
                                </ul>
                                
                                <div class="tab-content p-3 border border-top-0 bg-white" id="columnsTabsContent">
                                    <!-- El contenido de las pestañas se generará dinámicamente -->
                                </div>
                                
                                <div class="d-flex justify-content-between mt-4">
                                    <button type="button" class="btn btn-secondary" onclick="prevStep(3)">Anterior</button>
                                    <button type="button" class="btn btn-primary" onclick="nextStep(3)">Siguiente</button>
                                </div>
                            </div>
                            
                            <!-- Paso 4: Resumen y generación -->
                            <div id="step4" class="step">
                                <h4 class="mb-4">Resumen y Generación</h4>
                                
                                <div class="alert alert-info">
                                    <h5>Resumen de la configuración:</h5>
                                    <div id="summaryList">
                                        <!-- El resumen se generará dinámicamente -->
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="save_config_checkbox" name="save_config_checkbox">
                                        <label class="form-check-label" for="save_config_checkbox">
                                            Guardar configuración para uso futuro
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="alert alert-warning">
                                    <h6>Importante:</h6>
                                    <ul class='mb-0'>
                                        <li>La aplicación generada requerirá una tabla 'user' en la base de datos para el sistema de login</li>
                                        <li>Las contraseñas deben almacenarse usando password_hash() de PHP</li>
                                        <li>Todos los archivos CSS y JS se incluirán localmente en la carpeta assets/</li>
                                        <li>La aplicación funcionará completamente sin conexión a internet</li>
                                    </ul>
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <button type="button" class="btn btn-secondary" onclick="prevStep(4)">Anterior</button>
                                    <button type="submit" class="btn btn-success">Generar Aplicación</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/js/jquery-3.6.0.min.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let currentStep = 1;
        let tablesData = {};
        let currentConfig = {};
        
        // Función para guardar configuración
        function saveConfiguration() {
            const filename = document.getElementById('config_filename').value.trim();
            if (!filename) {
                alert('Por favor, ingrese un nombre para el archivo de configuración.');
                return;
            }

            // Recopilar datos de configuración actual
            const configData = {
                app_title: document.getElementById('app_title').value,
                primary_color: document.getElementById('primary_color').value,
                selected_tables: Array.from(document.querySelectorAll('.table-checkbox:checked')).map(cb => cb.value),
                tables_config: {}
            };

            // Agregar configuración de tablas si está disponible
            if (Object.keys(tablesData).length > 0) {
                Object.keys(tablesData).forEach(tableName => {
                    const displayNameElem = document.getElementById(`display_name_${tableName}`);
                    configData.tables_config[tableName] = {
                        display_name: displayNameElem ? displayNameElem.value : tablesData[tableName].displayName,
                        selected_columns: Array.from(document.querySelectorAll(`input[name="columns[${tableName}][]"]:checked`)).map(cb => cb.value),
                        column_titles: {},
                        control_types: {}
                    };

                    // Agregar títulos de columnas y tipos de control
                    tablesData[tableName].columns.forEach(column => {
                        const titleElem = document.querySelector(`input[name="column_titles[${tableName}][${column.name}]"]`);
                        const controlElem = document.querySelector(`select[name="control_types[${tableName}][${column.name}]"]`);
                        
                        if (titleElem) {
                            configData.tables_config[tableName].column_titles[column.name] = titleElem.value;
                        }
                        if (controlElem) {
                            configData.tables_config[tableName].control_types[column.name] = controlElem.value;
                        }
                    });
                });
            }

            const formData = new FormData();
            formData.append('config_data', JSON.stringify(configData));
            formData.append('config_filename', filename);

            fetch('?action=save_config', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert(`Configuración guardada exitosamente en: ${result.filename}`);
                } else {
                    alert('Error al guardar la configuración: ' + result.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al guardar la configuración.');
            });
        }

        // Función para cargar configuración
        function loadConfiguration() {
            const configFile = document.getElementById('load_config_file').files[0];
            if (!configFile) {
                alert('Por favor, seleccione un archivo de configuración.');
                return;
            }

            const formData = new FormData();
            formData.append('config_file', configFile);

            fetch('?action=load_config', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    currentConfig = result.config;
                    applyConfiguration(currentConfig);
                    alert('Configuración cargada exitosamente.');
                } else {
                    alert('Error al cargar la configuración: ' + result.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al cargar la configuración.');
            });
        }

        // Función para aplicar configuración cargada
        function applyConfiguration(config) {
            // Aplicar configuración básica
            document.getElementById('app_title').value = config.app_title || 'Mi Aplicación CRUD';
            document.getElementById('primary_color').value = config.primary_color || '#0d6efd';

            // Nota: Los archivos (base de datos y logo) deben ser subidos manualmente
            // ya que no podemos precargarlos por seguridad

            // Si hay tablas en la configuración, marcar para cargar columnas después
            if (config.selected_tables && config.selected_tables.length > 0) {
                // Esperar a que el usuario suba el archivo de base de datos
                alert('Configuración básica cargada. Por favor, suba el archivo de base de datos y proceda al siguiente paso.');
            }
        }

        // Modificar la función nextStep para aplicar configuración cuando esté disponible
        function nextStep(step) {
            if (step === 1) {
                const dbFile = document.getElementById('db_file').files[0];
                const appTitle = document.getElementById('app_title').value.trim();
                
                if (!dbFile) {
                    alert('Por favor, seleccione un archivo de base de datos.');
                    return;
                }
                
                if (!appTitle) {
                    alert('Por favor, ingrese un título para la aplicación.');
                    return;
                }
                
                loadTables(dbFile);
            } else if (step === 2) {
                const selectedTables = document.querySelectorAll('.table-checkbox:checked');
                if (selectedTables.length === 0) {
                    alert('Por favor, seleccione al menos una tabla.');
                    return;
                }
                
                loadColumnsInfo();
            } else if (step === 3) {
                generateSummary();
            }
            
            document.getElementById('step' + step).classList.remove('active');
            document.getElementById('step' + (step + 1)).classList.add('active');
            currentStep = step + 1;
        }

        // Modificar loadColumnsInfo para aplicar configuración de tablas
        function loadColumnsInfo() {
            const selectedTables = Array.from(document.querySelectorAll('.table-checkbox:checked'))
                .map(checkbox => checkbox.value);
            
            const dbFile = document.getElementById('db_file').files[0];
            const formData = new FormData();
            formData.append('db_file', dbFile);
            formData.append('tables', JSON.stringify(selectedTables));
            
            fetch('?action=load_columns', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                tablesData = data;
                generateColumnsTabs();
                
                // Aplicar configuración de tablas si está disponible
                if (currentConfig.tables_config) {
                    applyTablesConfiguration();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al cargar la información de columnas.');
            });
        }

        // Función para aplicar configuración de tablas
        function applyTablesConfiguration() {
            Object.keys(currentConfig.tables_config).forEach(tableName => {
                const tableConfig = currentConfig.tables_config[tableName];
                
                // Aplicar nombre para mostrar
                const displayNameElem = document.getElementById(`display_name_${tableName}`);
                if (displayNameElem && tableConfig.display_name) {
                    displayNameElem.value = tableConfig.display_name;
                }
                
                // Aplicar selección de columnas
                if (tableConfig.selected_columns) {
                    tableConfig.selected_columns.forEach(columnName => {
                        const checkbox = document.querySelector(`input[name="columns[${tableName}][]"][value="${columnName}"]`);
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                    });
                }
                
                // Aplicar títulos de columnas
                if (tableConfig.column_titles) {
                    Object.keys(tableConfig.column_titles).forEach(columnName => {
                        const titleInput = document.querySelector(`input[name="column_titles[${tableName}][${columnName}]"]`);
                        if (titleInput && tableConfig.column_titles[columnName]) {
                            titleInput.value = tableConfig.column_titles[columnName];
                        }
                    });
                }
                
                // Aplicar tipos de control
                if (tableConfig.control_types) {
                    Object.keys(tableConfig.control_types).forEach(columnName => {
                        const controlSelect = document.querySelector(`select[name="control_types[${tableName}][${columnName}]"]`);
                        if (controlSelect && tableConfig.control_types[columnName]) {
                            controlSelect.value = tableConfig.control_types[columnName];
                        }
                    });
                }
            });
        }
        
        function prevStep(step) {
            document.getElementById('step' + step).classList.remove('active');
            document.getElementById('step' + (step - 1)).classList.add('active');
            currentStep = step - 1;
        }
        
        // Cargar información de tablas
        function loadTables(dbFile) {
            const formData = new FormData();
            formData.append('db_file', dbFile);
            
            fetch('?action=load_tables', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Error en la respuesta del servidor');
                }
                return response.text();
            })
            .then(html => {
                if (html.trim() === '') {
                    throw new Error('No se recibieron datos de tablas');
                }
                
                // Agregar checkbox "Marcar Todas/Ninguna"
                const selectAllHtml = `
                    <div class="form-check mb-3 border-bottom pb-3">
                        <input class="form-check-input" type="checkbox" id="selectAllTables">
                        <label class="form-check-label fw-bold" for="selectAllTables">
                            <i class="fas fa-check-double"></i> Marcar Todas / Ninguna
                        </label>
                    </div>
                    <div class="table-list">
                        ${html}
                    </div>
                `;
                document.getElementById('tablesContainer').innerHTML = selectAllHtml;
                
                // Aplicar selección de tablas de la configuración cargada
                if (currentConfig.selected_tables) {
                    currentConfig.selected_tables.forEach(tableName => {
                        const checkbox = document.querySelector(`input.table-checkbox[value="${tableName}"]`);
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                    });
                    updateSelectAllCheckbox();
                }
                
                // Agregar evento al checkbox "Marcar Todas/Ninguna"
                const selectAllCheckbox = document.getElementById('selectAllTables');
                if (selectAllCheckbox) {
                    selectAllCheckbox.addEventListener('change', function() {
                        const checkboxes = document.querySelectorAll('.table-checkbox');
                        checkboxes.forEach(checkbox => {
                            checkbox.checked = this.checked;
                        });
                    });
                }
                
                // Agregar evento a los checkboxes individuales
                const checkboxes = document.querySelectorAll('.table-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        updateSelectAllCheckbox();
                    });
                });
                
                // Actualizar estado inicial
                updateSelectAllCheckbox();
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('tablesContainer').innerHTML = 
                    '<div class="alert alert-danger">Error al cargar las tablas de la base de datos. ' +
                    'Asegúrese de que el archivo sea una base de datos SQLite válida.</div>';
            });
        }

        // Función para actualizar el estado del checkbox "Marcar Todas/Ninguna"
        function updateSelectAllCheckbox() {
            const checkboxes = document.querySelectorAll('.table-checkbox');
            const selectAllCheckbox = document.getElementById('selectAllTables');
            
            if (!selectAllCheckbox || checkboxes.length === 0) return;
            
            const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            const totalCount = checkboxes.length;
            
            if (checkedCount === 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            } else if (checkedCount === totalCount) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            }
        }
        
        // Generar pestañas de columnas
        function generateColumnsTabs() {
            const tabsContainer = document.getElementById('columnsTabs');
            const contentContainer = document.getElementById('columnsTabsContent');
            
            tabsContainer.innerHTML = '';
            contentContainer.innerHTML = '';
            
            Object.keys(tablesData).forEach((tableName, index) => {
                // Crear pestaña
                const tabButton = document.createElement('li');
                tabButton.className = 'nav-item';
                tabButton.innerHTML = `
                    <button class="nav-link ${index === 0 ? 'active' : ''}" id="tab-${tableName}" data-bs-toggle="tab" 
                            data-bs-target="#content-${tableName}" type="button" role="tab">
                        ${tablesData[tableName].displayName}
                    </button>
                `;
                tabsContainer.appendChild(tabButton);
                
                // Crear contenido de pestaña
                const tabContent = document.createElement('div');
                tabContent.className = `tab-pane fade ${index === 0 ? 'show active' : ''}`;
                tabContent.id = `content-${tableName}`;
                tabContent.innerHTML = generateTableColumnsHTML(tableName, tablesData[tableName]);
                contentContainer.appendChild(tabContent);
            });
        }
        
        // Generar HTML para las columnas de una tabla
        function generateTableColumnsHTML(tableName, tableData) {
            let html = `
                <div class="mb-3">
                    <label for="display_name_${tableName}" class="form-label">Nombre para mostrar:</label>
                    <input type="text" class="form-control" id="display_name_${tableName}" 
                           name="display_names[${tableName}]" value="${tableData.displayName}">
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th width="50">Incluir</th>
                                <th>Columna</th>
                                <th>Tipo</th>
                                <th>Título para mostrar</th>
                                <th>Tipo de Control</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            tableData.columns.forEach(column => {
                const controlTypes = ['text', 'textarea', 'number', 'email', 'date', 'datetime-local', 'time', 'checkbox', 'select', 'url'];
                let controlOptions = '';
                
                controlTypes.forEach(type => {
                    const selected = column.defaultControl === type ? 'selected' : '';
                    controlOptions += `<option value="${type}" ${selected}>${type}</option>`;
                });
                
                html += `
                    <tr>
                        <td class="text-center">
                            <input type="checkbox" name="columns[${tableName}][]" value="${column.name}" checked>
                        </td>
                        <td><code>${column.name}</code></td>
                        <td><span class="badge bg-secondary">${column.type}</span></td>
                        <td>
                            <input type="text" class="form-control form-control-sm" name="column_titles[${tableName}][${column.name}]" 
                                   value="${column.displayName}">
                        </td>
                        <td>
                            <select class="form-select form-select-sm" name="control_types[${tableName}][${column.name}]">
                                ${controlOptions}
                            </select>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table></div>';
            return html;
        }
        
        // Generar resumen
        function generateSummary() {
            const summaryList = document.getElementById('summaryList');
            summaryList.innerHTML = '';
            
            // Título de la aplicación
            const appTitle = document.getElementById('app_title').value;
            summaryList.innerHTML += `<p><strong>Título de la aplicación:</strong> ${appTitle}</p>`;
            
            // Archivo de base de datos
            const dbFile = document.getElementById('db_file').files[0];
            summaryList.innerHTML += `<p><strong>Base de datos:</strong> ${dbFile.name}</p>`;
            
            // Tablas seleccionadas
            const selectedTables = Array.from(document.querySelectorAll('.table-checkbox:checked'))
                .map(checkbox => {
                    const tableName = checkbox.value;
                    const displayName = document.getElementById(`display_name_${tableName}`)?.value || tablesData[tableName]?.displayName;
                    return displayName;
                });
            
            summaryList.innerHTML += `<p><strong>Tablas seleccionadas:</strong> ${selectedTables.length}</p>`;
            
            // Detalles por tabla
            selectedTables.forEach(tableName => {
                const originalTableName = Object.keys(tablesData).find(key => 
                    tablesData[key].displayName === tableName || key === tableName
                );
                
                if (originalTableName && tablesData[originalTableName]) {
                    const selectedColumns = tablesData[originalTableName].columns.filter(col => {
                        const checkbox = document.querySelector(`input[name="columns[${originalTableName}][]"][value="${col.name}"]`);
                        return checkbox?.checked;
                    });
                    
                    summaryList.innerHTML += `
                        <div class="mt-3">
                            <h6>${tableName}</h6>
                            <ul>
                                <li>Columnas incluidas: ${selectedColumns.length}</li>
                                <li>Columnas: ${selectedColumns.map(col => col.name).join(', ')}</li>
                            </ul>
                        </div>
                    `;
                }
            });
        }
        
        // Manejar cambio de archivo de base de datos
        document.getElementById('db_file').addEventListener('change', function() {
            if (this.files.length > 0) {
                console.log('Archivo de base de datos seleccionado:', this.files[0].name);
            }
        });

        // Manejar el checkbox de guardar configuración
        document.getElementById('save_config_checkbox').addEventListener('change', function() {
            document.getElementById('save_config').value = this.checked ? '1' : '0';
            document.getElementById('hidden_config_filename').value = document.getElementById('config_filename').value;
        });
    </script>
</body>
</html>