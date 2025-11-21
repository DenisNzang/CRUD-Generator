<?php
require_once 'DatabaseConnection.php';

class CRUDGenerator {
    private $appData;
    private $outputDir;
    
    public function __construct($appData) {
        $this->appData = $appData;
        $this->outputDir = __DIR__ . '/../generated-app/' . uniqid('app_');
    }
    
    public function generate() {
        // Crear directorio de salida
        if (!mkdir($this->outputDir, 0755, true)) {
            throw new Exception("No se pudo crear el directorio de salida");
        }
        
        // Generar archivos principales
        $this->generateIndexFile();
        $this->generateConfigFile();
        $this->generateCRUDFiles();
        $this->generateAssets();
        $this->copyAssets();
        
        // Crear archivo ZIP
        $zipPath = $this->createZip();
        
        return [
            'success' => true,
            'downloadUrl' => $zipPath,
            'outputDir' => $this->outputDir
        ];
    }
    
    private function generateIndexFile() {
        $appTitle = $this->appData['appCustomization']['title'] ?? 'Mi App CRUD';
        $primaryColor = $this->appData['appCustomization']['primaryColor'] ?? '#fd7e14';
        
        // Generar el menú estáticamente basado en las tablas seleccionadas
        $menuItems = '';
        
        // Agregar tablas al menú
        foreach ($this->appData['selectedTables'] as $table) {
            $formattedName = $this->formatTableName($table);
            $menuItems .= '<a href="#" class="list-group-item list-group-item-action" data-view="table-' . $table . '">' . $formattedName . '</a>';
        }
        
        // Agregar consultas al menú
        foreach ($this->appData['customQueries'] as $query) {
            $queryId = $query['id'] ?? uniqid('query_');
            $queryName = $query['name'] ?? 'Consulta Personalizada';
            $menuItems .= '<a href="#" class="list-group-item list-group-item-action" data-view="query-' . $queryId . '">' . htmlspecialchars($queryName) . '</a>';
        }
        
        // Si no hay elementos, mostrar mensaje
        if (empty($menuItems)) {
            $menuItems = '<div class="list-group-item text-muted">No hay elementos para mostrar</div>';
        }

        $content = <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{APP_TITLE}}</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-icons.css" rel="stylesheet">
    <link href="css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: {{PRIMARY_COLOR}};
        }
        .navbar {
            background-color: var(--primary-color) !important;
        }
        .navbar-brand, .navbar-nav .nav-link {
            color: white !important;
        }
        .navbar-brand:hover, .navbar-nav .nav-link:hover {
            color: rgba(255,255,255,0.8) !important;
        }
        .btn-primary {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
        }
        .btn-primary:hover {
            background-color: {{PRIMARY_COLOR_DARK}} !important;
            border-color: {{PRIMARY_COLOR_DARK}} !important;
        }
        .list-group-item.active {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: white !important;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: {{PRIMARY_COLOR}} !important;">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold d-flex align-items-center" href="#">
                {{LOGO}}
                <span class="ms-2">{{APP_TITLE}}</span>
            </a>
        </div>
    </nav>
    
    <div class="container-fluid mt-3">
        <div class="row">
            <div class="col-md-3">
                <div class="list-group" id="sidebar">
                    {{MENU_ITEMS}}
                </div>
            </div>
            <div class="col-md-9">
                <div id="content">
                    <div class="alert alert-info">
                        <h5>Bienvenido a {{APP_TITLE}}</h5>
                        <p class="mb-0">Selecciona una opción del menú para comenzar.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="js/jquery-3.6.0.min.js"></script>
    <script src="js/bootstrap.bundle.min.js"></script>
    <script src="js/dataTables.bootstrap5.min.js"></script>
    <script src="js/app.js"></script>
</body>
</html>
HTML;

        // Calcular color más oscuro para hover
        $primaryColorDark = $this->darkenColor($primaryColor, 20);
        
        $content = str_replace('{{APP_TITLE}}', htmlspecialchars($appTitle), $content);
        $content = str_replace('{{PRIMARY_COLOR}}', $primaryColor, $content);
        $content = str_replace('{{PRIMARY_COLOR_DARK}}', $primaryColorDark, $content);
        $content = str_replace('{{MENU_ITEMS}}', $menuItems, $content);
        
        // Manejar logo
        $logoHtml = '';
        $logoPath = $this->handleLogoUpload();
        if ($logoPath && file_exists($this->outputDir . '/' . $logoPath)) {
            $logoHtml = '<img src="' . $logoPath . '" height="30" class="d-inline-block align-top me-2" alt="Logo">';
        } else {
            // Logo por defecto si no se proporcionó o hubo error
            $logoHtml = '<i class="bi bi-database me-2"></i>';
            // Crear logo por defecto
            $this->createDefaultLogo();
        }
        $content = str_replace('{{LOGO}}', $logoHtml, $content);
        
        file_put_contents($this->outputDir . '/index.html', $content);
    }
    
    private function darkenColor($color, $percent) {
        // Convertir hex a RGB
        $color = str_replace('#', '', $color);
        if (strlen($color) == 3) {
            $color = $color[0].$color[0].$color[1].$color[1].$color[2].$color[2];
        }
        
        $r = hexdec(substr($color, 0, 2));
        $g = hexdec(substr($color, 2, 2));
        $b = hexdec(substr($color, 4, 2));
        
        // Oscurecer
        $r = max(0, min(255, $r - ($r * $percent / 100)));
        $g = max(0, min(255, $g - ($g * $percent / 100)));
        $b = max(0, min(255, $b - ($b * $percent / 100)));
        
        // Convertir de vuelta a hex
        return '#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT) 
                   . str_pad(dechex($g), 2, '0', STR_PAD_LEFT) 
                   . str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
    }
    
    private function formatTableName($name) {
        return ucwords(str_replace('_', ' ', $name));
    }
    
    private function generateConfigFile() {
        $config = [
            'database' => [
                'type' => $this->appData['databaseType'],
                'connection' => $this->appData['connectionData']
            ],
            'tables' => $this->appData['selectedTables'],
            'queries' => $this->appData['customQueries'],
            'field_configurations' => $this->appData['fieldConfigurations'],
            'app' => $this->appData['appCustomization']
        ];
        
        // Crear directorio config si no existe
        $this->createDirectory($this->outputDir . '/config');
        
        // Guardar como PHP
        file_put_contents($this->outputDir . '/config/config.php', '<?php return ' . var_export($config, true) . '; ?>');
        
        // También guardar como JSON para JavaScript
        file_put_contents($this->outputDir . '/config/config.json', json_encode($config, JSON_PRETTY_PRINT));
    }
    
    private function generateCRUDFiles() {
        // Crear directorios necesarios
        $this->createDirectory($this->outputDir . '/php');
        $this->createDirectory($this->outputDir . '/templates');
        
        foreach ($this->appData['selectedTables'] as $table) {
            $this->generateTableCRUD($table);
        }
        
        foreach ($this->appData['customQueries'] as $query) {
            $this->generateQueryCRUD($query);
        }
    }
    
    private function generateTableCRUD($tableName) {
        // Generar archivo PHP para manejar CRUD de la tabla
        $phpContent = $this->generateTablePHP($tableName);
        file_put_contents($this->outputDir . "/php/{$tableName}.php", $phpContent);
        
        // Generar template HTML para la tabla
        $htmlContent = $this->generateTableHTML($tableName);
        file_put_contents($this->outputDir . "/templates/{$tableName}.html", $htmlContent);
    }
    
    private function generateQueryCRUD($query) {
        // Generar archivo PHP para manejar la consulta personalizada
        $phpContent = $this->generateQueryPHP($query);
        $queryId = $query['id'] ?? uniqid('query_');
        file_put_contents($this->outputDir . "/php/query_{$queryId}.php", $phpContent);
        
        // Generar template HTML para la consulta
        $htmlContent = $this->generateQueryHTML($query);
        file_put_contents($this->outputDir . "/templates/query_{$queryId}.html", $htmlContent);
    }
    
    private function generateTablePHP($tableName) {
        return <<<PHP
<?php
require_once '../config/Database.php';

class {$tableName}Controller {
    private \$db;
    private \$table = '{$tableName}';
    
    public function __construct() {
        \$this->db = new Database();
    }
    
    public function read() {
        \$query = "SELECT * FROM {\$this->table}";
        return \$this->db->query(\$query);
    }
    
    public function create(\$data) {
        \$columns = implode(', ', array_keys(\$data));
        \$values = ':' . implode(', :', array_keys(\$data));
        \$query = "INSERT INTO {\$this->table} (\$columns) VALUES (\$values)";
        
        return \$this->db->execute(\$query, \$data);
    }
    
    public function update(\$id, \$data) {
        \$setClause = [];
        foreach (\$data as \$key => \$value) {
            \$setClause[] = "\$key = :\$key";
        }
        \$setClause = implode(', ', \$setClause);
        
        \$query = "UPDATE {\$this->table} SET \$setClause WHERE id = :id";
        \$data['id'] = \$id;
        
        return \$this->db->execute(\$query, \$data);
    }
    
    public function delete(\$id) {
        \$query = "DELETE FROM {\$this->table} WHERE id = :id";
        return \$this->db->execute(\$query, ['id' => \$id]);
    }
    
    public function find(\$id) {
        \$query = "SELECT * FROM {\$this->table} WHERE id = :id";
        \$result = \$this->db->query(\$query, ['id' => \$id]);
        return \$result[0] ?? null;
    }
}

// Manejo de solicitudes AJAX
if (\$_SERVER['REQUEST_METHOD'] === 'POST') {
    \$action = \$_POST['action'] ?? '';
    \$controller = new {$tableName}Controller();
    
    switch (\$action) {
        case 'read':
            \$data = \$controller->read();
            echo json_encode(['success' => true, 'data' => \$data]);
            break;
            
        case 'create':
            \$data = \$_POST;
            unset(\$data['action']);
            \$result = \$controller->create(\$data);
            echo json_encode(['success' => \$result]);
            break;
            
        case 'update':
            \$id = \$_POST['id'];
            \$data = \$_POST;
            unset(\$data['action'], \$data['id']);
            \$result = \$controller->update(\$id, \$data);
            echo json_encode(['success' => \$result]);
            break;
            
        case 'delete':
            \$id = \$_POST['id'];
            \$result = \$controller->delete(\$id);
            echo json_encode(['success' => \$result]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
}
?>
PHP;
    }
    
    private function generateTableHTML($tableName) {
        return <<<HTML
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Gestión de {$tableName}</h5>
        <button class="btn btn-primary btn-sm" onclick="showCreateModal('{$tableName}')">
            <i class="bi bi-plus-circle"></i> Nuevo
        </button>
    </div>
    <div class="card-body">
        <table id="table-{$tableName}" class="table table-striped table-bordered" style="width:100%">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- Modal para crear/editar -->
<div class="modal fade" id="modal-{$tableName}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle-{$tableName}">Nuevo Registro</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="form-{$tableName}">
                    <input type="hidden" id="editId-{$tableName}" name="id">
                    <div id="formFields-{$tableName}">
                        <!-- Campos generados dinámicamente -->
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="save{$tableName}()">Guardar</button>
            </div>
        </div>
    </div>
</div>

<script>
console.log('Script de tabla {$tableName} ejecutándose...');

function load{$tableName}Table() {
    console.log('Inicializando DataTable para {$tableName}');
    try {
        $('#table-{$tableName}').DataTable({
            ajax: {
                url: 'php/{$tableName}.php',
                type: 'POST',
                data: { action: 'read' },
                dataSrc: 'data'
            },
            columns: [
                { data: 'id' },
                {
                    data: null,
                    render: function(data, type, row) {
                        return \`
                            <button class="btn btn-sm btn-warning me-1" onclick="edit{$tableName}(\${row.id})">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="delete{$tableName}(\${row.id})">
                                <i class="bi bi-trash"></i>
                            </button>
                        \`;
                    }
                }
            ],
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json'
            }
        });
        console.log('DataTable inicializado correctamente');
    } catch (error) {
        console.error('Error inicializando DataTable:', error);
        showAlert('Error al cargar la tabla: ' + error.message, 'danger');
    }
}

function showCreateModal() {
    $('#editId-{$tableName}').val('');
    $('#modalTitle-{$tableName}').text('Nuevo Registro');
    $('#form-{$tableName}')[0].reset();
    $('#modal-{$tableName}').modal('show');
}

function edit{$tableName}(id) {
    console.log('Editando registro:', id);
    // Implementar edición
    showAlert('Función de edición no implementada para el registro: ' + id, 'warning');
}

function delete{$tableName}(id) {
    if (confirm('¿Estás seguro de eliminar este registro?')) {
        console.log('Eliminando registro:', id);
        \$.post('php/{$tableName}.php', { action: 'delete', id: id }, function(response) {
            if (response.success) {
                showAlert('Registro eliminado correctamente', 'success');
                $('#table-{$tableName}').DataTable().ajax.reload();
            } else {
                showAlert('Error al eliminar el registro: ' + (response.error || 'Error desconocido'), 'danger');
            }
        }).fail(function(xhr, status, error) {
            showAlert('Error de conexión: ' + error, 'danger');
        });
    }
}

function save{$tableName}() {
    const formData = new FormData(document.getElementById('form-{$tableName}'));
    formData.append('action', $('#editId-{$tableName}').val() ? 'update' : 'create');
    
    \$.ajax({
        url: 'php/{$tableName}.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                showAlert('Registro guardado correctamente', 'success');
                $('#modal-{$tableName}').modal('hide');
                $('#table-{$tableName}').DataTable().ajax.reload();
            } else {
                showAlert('Error al guardar el registro: ' + (response.error || 'Error desconocido'), 'danger');
            }
        },
        error: function(xhr, status, error) {
            showAlert('Error de conexión: ' + error, 'danger');
        }
    });
}

// Inicializar cuando el template se cargue
console.log('Template {$tableName} cargado, inicializando DataTable...');
setTimeout(function() {
    load{$tableName}Table();
}, 100);
</script>
HTML;
    }
    
    private function generateQueryPHP($query) {
        $queryName = $query['name'];
        $queryId = $query['id'] ?? uniqid('query_');
        $sql = $query['sql'];
        $type = $query['type'];
        
        return <<<PHP
<?php
require_once '../config/Database.php';

class Query{$queryId}Controller {
    private \$db;
    
    public function __construct() {
        \$this->db = new Database();
    }
    
    public function execute() {
        try {
            \$sql = "{$sql}";
            \$result = \$this->db->query(\$sql);
            return ['success' => true, 'data' => \$result];
        } catch (Exception \$e) {
            return ['success' => false, 'error' => \$e->getMessage()];
        }
    }
}

// Manejo de solicitudes AJAX
if (\$_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    \$action = \$_POST['action'] ?? '';
    \$controller = new Query{$queryId}Controller();
    
    switch (\$action) {
        case 'read':
            \$result = \$controller->execute();
            echo json_encode(\$result);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
    exit();
}
?>
PHP;
    }
    
    private function generateQueryHTML($query) {
    $queryName = $query['name'];
    $queryId = $query['id'] ?? uniqid('query_');
    $type = $query['type'];
    
    return <<<HTML
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">{$queryName}</h5>
    </div>
    <div class="card-body">
        <table id="table-query-{$queryId}" class="table table-striped table-bordered" style="width:100%">
            <thead>
                <tr>
                    <th>#</th>
                    <!-- Columnas generadas dinámicamente -->
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<script>
function loadQuery{$queryId}Table() {
    console.log('Inicializando DataTable para consulta {$queryName}');
    try {
        $('#table-query-{$queryId}').DataTable({
            ajax: {
                url: 'php/query_{$queryId}.php',
                type: 'POST',
                data: { action: 'read' },
                dataSrc: function(response) {
                    console.log('Respuesta del servidor para consulta:', response);
                    if (response.success && response.data) {
                        return response.data;
                    } else {
                        console.error('Error en respuesta de consulta:', response.error);
                        showAlert('Error al cargar datos de consulta: ' + (response.error || 'Error desconocido'), 'danger');
                        return [];
                    }
                }
            },
            columns: [
                {
                    data: null,
                    render: function(data, type, row, meta) {
                        return meta.row + 1;
                    }
                }
                // Columnas generadas dinámicamente basadas en los resultados
            ],
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json'
            },
            responsive: true,
            processing: true,
            serverSide: false
        });
        console.log('DataTable de consulta inicializado correctamente');
    } catch (error) {
        console.error('Error inicializando DataTable de consulta:', error);
        showAlert('Error al cargar la consulta: ' + error.message, 'danger');
    }
}

// Cargar tabla cuando el documento esté listo
console.log('Template de consulta {$queryName} cargado, inicializando DataTable...');
setTimeout(function() {
    loadQuery{$queryId}Table();
}, 100);
</script>
HTML;
}
    
    private function generateAssets() {
        // Copiar/Crear archivos CSS y JS necesarios
        $this->createDirectory($this->outputDir . '/css');
        $this->createDirectory($this->outputDir . '/js');
        
        // Crear archivo JS principal
        file_put_contents($this->outputDir . '/js/app.js', $this->generateMainJS());
        
        // Crear archivo de base de datos
        file_put_contents($this->outputDir . '/config/Database.php', $this->generateDatabaseClass());
    }
    
    private function generateMainJS() {
        return <<<'JS'
// Aplicación CRUD Generada - Archivo Principal
class CRUDApp {
    constructor() {
        this.currentView = '';
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.loadFirstView();
    }
    
    bindEvents() {
        // Vincular eventos de clic en el menú
        document.addEventListener('click', (e) => {
            if (e.target.matches('#sidebar a[data-view]') || 
                e.target.closest('#sidebar a[data-view]')) {
                e.preventDefault();
                const menuItem = e.target.matches('#sidebar a[data-view]') ? 
                    e.target : e.target.closest('#sidebar a[data-view]');
                this.loadView(menuItem.getAttribute('data-view'), menuItem);
            }
        });
    }
    
    loadFirstView() {
        // Cargar la primera vista disponible
        const firstMenuItem = document.querySelector('#sidebar a[data-view]');
        if (firstMenuItem) {
            this.loadView(firstMenuItem.getAttribute('data-view'), firstMenuItem);
        } else {
            // Si no hay elementos en el menú, mostrar mensaje
            document.getElementById('content').innerHTML = `
                <div class="alert alert-warning">
                    <h5>No hay elementos disponibles</h5>
                    <p class="mb-0">No se han configurado tablas ni consultas para esta aplicación.</p>
                </div>
            `;
        }
    }
    
    loadView(viewId, menuItem) {
        console.log('Cargando vista:', viewId);
        this.currentView = viewId;
        const content = document.getElementById('content');
        
        // Mostrar carga
        content.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-2">Cargando...</p>
            </div>
        `;
        
        // Actualizar navegación activa
        document.querySelectorAll('#sidebar a').forEach(item => {
            item.classList.remove('active');
        });
        if (menuItem) {
            menuItem.classList.add('active');
        }
        
        // Determinar tipo de vista y cargar contenido
        if (viewId.startsWith('table-')) {
            const tableName = viewId.replace('table-', '');
            this.loadTableView(tableName);
        } else if (viewId.startsWith('query-')) {
            const queryId = viewId.replace('query-', '');
            this.loadQueryView(queryId);
        } else {
            // Vista no reconocida
            content.innerHTML = `
                <div class="alert alert-danger">
                    <h5>Error</h5>
                    <p class="mb-0">Tipo de vista no reconocido: ${viewId}</p>
                </div>
            `;
        }
    }
    
    loadTableView(tableName) {
        const content = document.getElementById('content');
        console.log('Cargando tabla:', tableName);
        
        // Cargar template de la tabla
        fetch(`templates/${tableName}.html`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Template no encontrado (${response.status}): ${response.statusText}`);
                }
                return response.text();
            })
            .then(html => {
                console.log('Template cargado correctamente');
                content.innerHTML = html;
                this.executeScripts(content);
            })
            .catch(error => {
                console.error('Error cargando tabla:', error);
                content.innerHTML = `
                    <div class="alert alert-danger">
                        <h5>Error al cargar la tabla "${tableName}"</h5>
                        <p>No se pudo cargar la interfaz para esta tabla.</p>
                        <div class="mt-2">
                            <p class="mb-1 small text-muted">Posibles causas:</p>
                            <ul class="small text-muted mb-0">
                                <li>El archivo templates/${tableName}.html no existe</li>
                                <li>Error de red o permisos</li>
                                <li>Problema con el servidor web</li>
                            </ul>
                        </div>
                        <p class="mt-2 mb-0 small"><strong>Error técnico:</strong> ${error.message}</p>
                    </div>
                `;
            });
    }
    
    loadQueryView(queryId) {
        const content = document.getElementById('content');
        console.log('Cargando consulta:', queryId);
        
        // Cargar template de la consulta
        fetch(`templates/query_${queryId}.html`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Template no encontrado (${response.status}): ${response.statusText}`);
                }
                return response.text();
            })
            .then(html => {
                console.log('Template de consulta cargado correctamente');
                content.innerHTML = html;
                this.executeScripts(content);
            })
            .catch(error => {
                console.error('Error cargando consulta:', error);
                content.innerHTML = `
                    <div class="alert alert-danger">
                        <h5>Error al cargar la consulta</h5>
                        <p>No se pudo cargar la interfaz para esta consulta personalizada.</p>
                        <div class="mt-2">
                            <p class="mb-1 small text-muted">Posibles causas:</p>
                            <ul class="small text-muted mb-0">
                                <li>El archivo templates/query_${queryId}.html no existe</li>
                                <li>Error de red o permisos</li>
                                <li>Problema con el servidor web</li>
                            </ul>
                        </div>
                        <p class="mt-2 mb-0 small"><strong>Error técnico:</strong> ${error.message}</p>
                    </div>
                `;
            });
    }
    
    executeScripts(container) {
        const scripts = container.querySelectorAll('script');
        console.log('Ejecutando', scripts.length, 'scripts');
        
        scripts.forEach((script, index) => {
            try {
                const newScript = document.createElement('script');
                
                // Copiar todos los atributos
                for (let attr of script.attributes) {
                    newScript.setAttribute(attr.name, attr.value);
                }
                
                // Si tiene src, cargar el script externo
                if (script.src) {
                    newScript.src = script.src;
                    newScript.onload = () => console.log('Script externo cargado:', script.src);
                    newScript.onerror = (e) => console.error('Error cargando script externo:', script.src, e);
                } else {
                    newScript.textContent = script.textContent;
                }
                
                document.body.appendChild(newScript);
                console.log('Script ejecutado:', index);
                
            } catch (error) {
                console.error('Error ejecutando script:', index, error);
            }
        });
    }
}

// Inicializar la aplicación cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
    console.log('Inicializando aplicación CRUD...');
    window.crudApp = new CRUDApp();
});

// Utilidades globales
function showAlert(message, type = 'info') {
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show`;
    alert.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const content = document.getElementById('content');
    if (content) {
        content.prepend(alert);
        
        setTimeout(() => {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 5000);
    }
}

function formatDate(dateString) {
    if (!dateString) return '';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('es-ES');
    } catch (error) {
        return dateString;
    }
}

function formatCurrency(amount) {
    if (!amount) return '';
    try {
        return new Intl.NumberFormat('es-ES', {
            style: 'currency',
            currency: 'EUR'
        }).format(amount);
    } catch (error) {
        return amount;
    }
}

// Función global para debugging
function debugApp() {
    console.log('Estado de la aplicación:', {
        currentView: window.crudApp?.currentView,
        menuItems: document.querySelectorAll('#sidebar a[data-view]').length,
        content: document.getElementById('content')?.innerHTML?.substring(0, 100) + '...'
    });
}
JS;
    }
    
    private function generateDatabaseClass() {
        return <<<'PHP'
<?php
class Database {
    private $pdo;
    private $config;
    
    public function __construct() {
        $this->config = require __DIR__ . '/config.php';
        $this->connect();
    }
    
    private function connect() {
        $dbConfig = $this->config['database'];
        
        try {
            switch ($dbConfig['type']) {
                case 'sqlite':
                    $this->connectSQLite($dbConfig['connection']);
                    break;
                case 'mysql':
                    $this->connectMySQL($dbConfig['connection']);
                    break;
                case 'postgresql':
                    $this->connectPostgreSQL($dbConfig['connection']);
                    break;
                default:
                    throw new Exception("Tipo de base de datos no soportado: " . $dbConfig['type']);
            }
            
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
        } catch (PDOException $e) {
            throw new Exception("Error de conexión: " . $e->getMessage());
        }
    }
    
    private function connectSQLite($connection) {
        $filePath = $connection['file'] ?? 'database.sqlite';
        $this->pdo = new PDO("sqlite:" . $filePath);
    }
    
    private function connectMySQL($connection) {
        $dsn = "mysql:host={$connection['host']};port={$connection['port']};dbname={$connection['database']};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $connection['username'], $connection['password']);
    }
    
    private function connectPostgreSQL($connection) {
        $dsn = "pgsql:host={$connection['host']};port={$connection['port']};dbname={$connection['database']}";
        $this->pdo = new PDO($dsn, $connection['username'], $connection['password']);
    }
    
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Error en consulta: " . $e->getMessage());
        }
    }
    
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            throw new Exception("Error en ejecución: " . $e->getMessage());
        }
    }
    
    public function getLastInsertId() {
        return $this->pdo->lastInsertId();
    }
}
?>
PHP;
    }
    
    private function copyAssets() {
        // Crear directorios para assets
        $this->createDirectory($this->outputDir . '/css');
        $this->createDirectory($this->outputDir . '/js');
        $this->createDirectory($this->outputDir . '/assets');
        
        // Copiar archivos CSS desde el generador a la aplicación generada
        $cssFiles = [
            'bootstrap.min.css',
            'bootstrap-icons.css', 
            'dataTables.bootstrap5.min.css'
        ];
        
        foreach ($cssFiles as $cssFile) {
            $source = __DIR__ . '/../css/' . $cssFile;
            $dest = $this->outputDir . '/css/' . $cssFile;
            if (file_exists($source)) {
                copy($source, $dest);
            }
        }
        
        // Copiar archivos JS desde el generador a la aplicación generada
        $jsFiles = [
            'jquery-3.6.0.min.js',
            'bootstrap.bundle.min.js',
            'dataTables.bootstrap5.min.js'
        ];
        
        foreach ($jsFiles as $jsFile) {
            $source = __DIR__ . '/../js/' . $jsFile;
            $dest = $this->outputDir . '/js/' . $jsFile;
            if (file_exists($source)) {
                copy($source, $dest);
            }
        }
    }
    
    private function handleLogoUpload() {
        // Verificar si hay un logo en los datos de la aplicación
        if (isset($this->appData['appCustomization']['logo']) && $this->appData['appCustomization']['logo']) {
            $logoData = $this->appData['appCustomization']['logo'];
            
            // Si es un array (archivo subido via $_FILES en el generador)
            if (is_array($logoData) && isset($logoData['tmp_name']) && file_exists($logoData['tmp_name'])) {
                $uploadDir = $this->outputDir . '/assets/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                // Obtener extensión del archivo
                $fileExtension = pathinfo($logoData['name'], PATHINFO_EXTENSION);
                $fileName = 'logo.' . $fileExtension;
                $filePath = $uploadDir . $fileName;
                
                if (move_uploaded_file($logoData['tmp_name'], $filePath)) {
                    return 'assets/' . $fileName;
                }
            }
            // Si es un string (ruta directa o datos base64)
            elseif (is_string($logoData)) {
                // Verificar si es una ruta de archivo existente
                if (file_exists($logoData)) {
                    $uploadDir = $this->outputDir . '/assets/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileExtension = pathinfo($logoData, PATHINFO_EXTENSION);
                    $fileName = 'logo.' . $fileExtension;
                    $destPath = $uploadDir . $fileName;
                    
                    if (copy($logoData, $destPath)) {
                        return 'assets/' . $fileName;
                    }
                }
                // Verificar si es base64
                elseif (strpos($logoData, 'data:image') === 0) {
                    $uploadDir = $this->outputDir . '/assets/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    // Extraer datos base64
                    $parts = explode(',', $logoData);
                    if (count($parts) === 2) {
                        $imageData = base64_decode($parts[1]);
                        $finfo = finfo_open();
                        $mimeType = finfo_buffer($finfo, $imageData, FILEINFO_MIME_TYPE);
                        finfo_close($finfo);
                        
                        $extensions = [
                            'image/jpeg' => 'jpg',
                            'image/jpg' => 'jpg',
                            'image/png' => 'png',
                            'image/gif' => 'gif',
                            'image/svg+xml' => 'svg',
                            'image/webp' => 'webp'
                        ];
                        
                        $fileExtension = $extensions[$mimeType] ?? 'png';
                        $fileName = 'logo.' . $fileExtension;
                        $filePath = $uploadDir . $fileName;
                        
                        if (file_put_contents($filePath, $imageData)) {
                            return 'assets/' . $fileName;
                        }
                    }
                }
            }
        }
        
        // Si no hay logo válido, crear uno por defecto
        $this->createDefaultLogo();
        return 'assets/default-logo.png';
    }
    
    private function createDefaultLogo() {
        $assetsDir = $this->outputDir . '/assets/';
        if (!is_dir($assetsDir)) {
            mkdir($assetsDir, 0755, true);
        }
        
        $logoPath = $assetsDir . 'default-logo.png';
        
        // Crear un logo SVG simple por defecto
        $svgContent = '<?xml version="1.0" encoding="UTF-8"?>
<svg width="30" height="30" viewBox="0 0 30 30" xmlns="http://www.w3.org/2000/svg">
    <rect width="30" height="30" fill="#fd7e14" rx="4"/>
    <text x="15" y="20" font-family="Arial, sans-serif" font-size="12" font-weight="bold" fill="white" text-anchor="middle">CRUD</text>
</svg>';
        
        file_put_contents($logoPath, $svgContent);
    }
    
    private function createZip() {
        $zip = new ZipArchive();
        $zipPath = $this->outputDir . '/application.zip';
        
        if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
            $this->addFolderToZip($this->outputDir, $zip);
            $zip->close();
            
            // En una implementación real, devolverías la URL para descargar
            return 'generated-app/' . basename($this->outputDir) . '/application.zip';
        }
        
        throw new Exception("No se pudo crear el archivo ZIP");
    }
    
    private function addFolderToZip($folder, &$zip, $parent = '') {
        $files = scandir($folder);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $filePath = $folder . '/' . $file;
            $localPath = $parent . $file;
            
            if (is_dir($filePath)) {
                $zip->addEmptyDir($localPath);
                $this->addFolderToZip($filePath, $zip, $localPath . '/');
            } else {
                $zip->addFile($filePath, $localPath);
            }
        }
    }
    
    private function createDirectory($path) {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}

// MANEJO DE SOLICITUDES CON SALIDA JSON LIMPIA
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // LIMPIAR CUALQUIER SALIDA ANTERIOR
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // ESTABLECER HEADERS PARA JSON
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        switch ($action) {
            case 'generate_app':
                $appData = json_decode($_POST['app_data'], true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Error al decodificar JSON: " . json_last_error_msg());
                }
                
                // Manejar archivo de logo si existe
                if (isset($_FILES['app_logo']) && $_FILES['app_logo']['error'] === UPLOAD_ERR_OK) {
                    $appData['appCustomization']['logo'] = $_FILES['app_logo'];
                }
                
                $generator = new CRUDGenerator($appData);
                $result = $generator->generate();
                
                echo json_encode($result);
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Acción no válida']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    // ASEGURAR QUE NO HAY MÁS SALIDA
    exit();
}
?>