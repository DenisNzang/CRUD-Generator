<?php
require_once 'config.php';

class DatabaseConnection {
    private $pdo;
    private $databaseType;
    
    public function __construct($databaseType, $connectionData) {
        $this->databaseType = $databaseType;
        $this->connect($connectionData);
    }
    
    private function connect($connectionData) {
        try {
            switch ($this->databaseType) {
                case 'sqlite':
                    $this->connectSQLite($connectionData);
                    break;
                case 'mysql':
                    $this->connectMySQL($connectionData);
                    break;
                case 'postgresql':
                    $this->connectPostgreSQL($connectionData);
                    break;
                default:
                    throw new Exception("Tipo de base de datos no soportado");
            }
            
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
        } catch (PDOException $e) {
            throw new Exception("Error de conexión: " . $e->getMessage());
        }
    }
    
    private function connectSQLite($connectionData) {
        // Manejar tanto archivo subido como ruta directa
        if (isset($connectionData['sqlite_file']) && is_string($connectionData['sqlite_file'])) {
            // Es una ruta de archivo
            $filePath = $connectionData['sqlite_file'];
        } elseif (isset($connectionData['sqlite_file_upload'])) {
            // Es un archivo subido via $_FILES
            $filePath = $this->handleSQLiteUpload($connectionData['sqlite_file_upload']);
        } else {
            throw new Exception("No se proporcionó archivo SQLite");
        }
        
        // Verificar que el archivo existe
        if (!file_exists($filePath)) {
            throw new Exception("El archivo SQLite no existe: " . $filePath);
        }
        
        $this->pdo = new PDO("sqlite:$filePath");
    }
    
    private function connectMySQL($connectionData) {
        $host = $connectionData['host'] ?? 'localhost';
        $port = $connectionData['port'] ?? '3306';
        $database = $connectionData['database'] ?? '';
        $username = $connectionData['username'] ?? '';
        $password = $connectionData['password'] ?? '';
        
        $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
        $this->pdo = new PDO($dsn, $username, $password);
    }
    
    private function connectPostgreSQL($connectionData) {
        $host = $connectionData['host'] ?? 'localhost';
        $port = $connectionData['port'] ?? '5432';
        $database = $connectionData['database'] ?? '';
        $username = $connectionData['username'] ?? '';
        $password = $connectionData['password'] ?? '';
        
        $dsn = "pgsql:host=$host;port=$port;dbname=$database";
        $this->pdo = new PDO($dsn, $username, $password);
    }
    
    private function handleSQLiteUpload($fileData) {
        // Directorio para archivos subidos
        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Validar que es un archivo SQLite
        $allowedExtensions = ['db', 'sqlite', 'sqlite3'];
        $fileExtension = pathinfo($fileData['name'], PATHINFO_EXTENSION);
        
        if (!in_array(strtolower($fileExtension), $allowedExtensions)) {
            throw new Exception("Tipo de archivo no permitido. Use: " . implode(', ', $allowedExtensions));
        }
        
        // Generar nombre único
        $fileName = uniqid('sqlite_') . '.' . $fileExtension;
        $filePath = $uploadDir . $fileName;
        
        // Mover archivo subido
        if (!move_uploaded_file($fileData['tmp_name'], $filePath)) {
            throw new Exception("Error al subir el archivo SQLite");
        }
        
        return $filePath;
    }
    
    public function testConnection() {
        try {
            $this->pdo->query("SELECT 1");
            return ['success' => true, 'message' => 'Conexión exitosa'];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function getPDO() {
        return $this->pdo;
    }
    
    // MÉTODO CLOSE AÑADIDO
    public function close() {
        $this->pdo = null;
    }
}

// Manejo de solicitudes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'test_connection':
                $databaseType = $_POST['database_type'] ?? '';
                $connectionData = [];
                
                // Preparar datos de conexión según el tipo de BD
                if ($databaseType === 'sqlite') {
                    if (isset($_FILES['sqlite_file'])) {
                        $connectionData['sqlite_file_upload'] = $_FILES['sqlite_file'];
                    } else {
                        throw new Exception("No se proporcionó archivo SQLite");
                    }
                } else {
                    $connectionData = [
                        'host' => $_POST['host'] ?? '',
                        'port' => $_POST['port'] ?? '',
                        'database' => $_POST['database'] ?? '',
                        'schema' => $_POST['schema'] ?? 'public',
                        'username' => $_POST['username'] ?? '',
                        'password' => $_POST['password'] ?? ''
                    ];
                }
                
                $db = new DatabaseConnection($databaseType, $connectionData);
                $result = $db->testConnection();
                
                // Limpiar archivo temporal si es SQLite
                if ($databaseType === 'sqlite') {
                    $db->close();
                }
                
                echo json_encode($result);
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Acción no válida']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>