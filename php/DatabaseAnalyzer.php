<?php
require_once 'DatabaseConnection.php';

class DatabaseAnalyzer {
    private $pdo;
    private $databaseType;
    
    public function __construct($pdo, $databaseType) {
        $this->pdo = $pdo;
        $this->databaseType = $databaseType;
    }
    
    public function analyze() {
        $tables = $this->getTables();
        $structure = [];
        
        foreach ($tables as $table) {
            $structure[$table] = [
                'columns' => $this->getTableColumns($table),
                'primaryKey' => $this->getPrimaryKey($table),
                'foreignKeys' => $this->getForeignKeys($table),
                'indexes' => $this->getIndexes($table)
            ];
        }
        
        return [
            'tables' => $structure,
            'relationships' => $this->analyzeRelationships($structure),
            'total_tables' => count($tables),
            'database_type' => $this->databaseType
        ];
    }
    
    private function getTables() {
        $tables = [];
        
        switch ($this->databaseType) {
            case 'sqlite':
                $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                break;
                
            case 'mysql':
                $stmt = $this->pdo->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                break;
                
            case 'postgresql':
                $stmt = $this->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                break;
        }
        
        return array_filter($tables); // Remover valores vacíos
    }
    
    private function getTableColumns($tableName) {
        $columns = [];
        
        switch ($this->databaseType) {
            case 'sqlite':
                $stmt = $this->pdo->query("PRAGMA table_info(`$tableName`)");
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($result as $col) {
                    $columns[] = [
                        'name' => $col['name'],
                        'type' => strtolower($col['type']),
                        'nullable' => !$col['notnull'],
                        'default' => $col['dflt_value'],
                        'primaryKey' => $col['pk'] > 0
                    ];
                }
                break;
                
            case 'mysql':
                $stmt = $this->pdo->query("DESCRIBE `$tableName`");
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($result as $col) {
                    $columns[] = [
                        'name' => $col['Field'],
                        'type' => strtolower($col['Type']),
                        'nullable' => $col['Null'] === 'YES',
                        'default' => $col['Default'],
                        'primaryKey' => $col['Key'] === 'PRI'
                    ];
                }
                break;
                
            case 'postgresql':
                $stmt = $this->pdo->query("
                    SELECT 
                        column_name, 
                        data_type, 
                        is_nullable, 
                        column_default,
                        CASE WHEN position('nextval' in column_default) > 0 THEN true ELSE false END as is_serial
                    FROM information_schema.columns 
                    WHERE table_name = '$tableName'
                    ORDER BY ordinal_position
                ");
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($result as $col) {
                    $columns[] = [
                        'name' => $col['column_name'],
                        'type' => strtolower($col['data_type']),
                        'nullable' => $col['is_nullable'] === 'YES',
                        'default' => $col['column_default'],
                        'primaryKey' => false // Se detectará separadamente
                    ];
                }
                break;
        }
        
        return $columns;
    }
    
    private function getPrimaryKey($tableName) {
        switch ($this->databaseType) {
            case 'sqlite':
                $stmt = $this->pdo->query("PRAGMA table_info(`$tableName`)");
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($columns as $col) {
                    if ($col['pk'] > 0) {
                        return $col['name'];
                    }
                }
                break;
                
            case 'mysql':
                $stmt = $this->pdo->query("SHOW KEYS FROM `$tableName` WHERE Key_name = 'PRIMARY'");
                $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($keys) > 0) {
                    return $keys[0]['Column_name'];
                }
                break;
                
            case 'postgresql':
                $stmt = $this->pdo->query("
                    SELECT a.attname
                    FROM pg_index i
                    JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                    WHERE i.indrelid = '$tableName'::regclass AND i.indisprimary
                ");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    return $result['attname'];
                }
                break;
        }
        
        return null;
    }
    
    private function getForeignKeys($tableName) {
        $foreignKeys = [];
        
        switch ($this->databaseType) {
            case 'sqlite':
                $stmt = $this->pdo->query("PRAGMA foreign_key_list(`$tableName`)");
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($result as $fk) {
                    $foreignKeys[] = [
                        'column' => $fk['from'],
                        'referenced_table' => $fk['table'],
                        'referenced_column' => $fk['to']
                    ];
                }
                break;
                
            case 'mysql':
                $stmt = $this->pdo->query("
                    SELECT 
                        COLUMN_NAME, 
                        REFERENCED_TABLE_NAME, 
                        REFERENCED_COLUMN_NAME 
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE TABLE_NAME = '$tableName' 
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ");
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($result as $fk) {
                    $foreignKeys[] = [
                        'column' => $fk['COLUMN_NAME'],
                        'referenced_table' => $fk['REFERENCED_TABLE_NAME'],
                        'referenced_column' => $fk['REFERENCED_COLUMN_NAME']
                    ];
                }
                break;
                
            case 'postgresql':
                $stmt = $this->pdo->query("
                    SELECT
                        kcu.column_name,
                        ccu.table_name AS referenced_table_name,
                        ccu.column_name AS referenced_column_name
                    FROM information_schema.table_constraints AS tc
                    JOIN information_schema.key_column_usage AS kcu
                      ON tc.constraint_name = kcu.constraint_name
                    JOIN information_schema.constraint_column_usage AS ccu
                      ON ccu.constraint_name = tc.constraint_name
                    WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = '$tableName'
                ");
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($result as $fk) {
                    $foreignKeys[] = [
                        'column' => $fk['column_name'],
                        'referenced_table' => $fk['referenced_table_name'],
                        'referenced_column' => $fk['referenced_column_name']
                    ];
                }
                break;
        }
        
        return $foreignKeys;
    }
    
    private function getIndexes($tableName) {
        // Implementación básica de índices
        return [];
    }
    
    private function analyzeRelationships($structure) {
        $relationships = [];
        
        foreach ($structure as $tableName => $tableInfo) {
            foreach ($tableInfo['foreignKeys'] as $foreignKey) {
                $relationships[] = [
                    'from_table' => $tableName,
                    'from_column' => $foreignKey['column'],
                    'to_table' => $foreignKey['referenced_table'],
                    'to_column' => $foreignKey['referenced_column']
                ];
            }
        }
        
        return $relationships;
    }
}

// Manejo de solicitudes - LIMPIAR SALIDA PARA SOLO JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Limpiar cualquier salida anterior
    if (ob_get_length()) {
        ob_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        switch ($action) {
            case 'analyze_database':
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
                
                // Crear conexión
                $dbConnection = new DatabaseConnection($databaseType, $connectionData);
                $pdo = $dbConnection->getPDO();
                
                // Analizar base de datos
                $analyzer = new DatabaseAnalyzer($pdo, $databaseType);
                $analysis = $analyzer->analyze();
                
                // Cerrar conexión
                $dbConnection->close();
                
                echo json_encode(['success' => true, 'data' => $analysis]);
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Acción no válida']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    // Asegurar que no hay más salida
    exit();
}
?>