<?php
/**
 * Script para testar conexão com banco de dados
 */

require_once 'config.php';

header('Content-Type: application/json');

$result = [
    'success' => false,
    'message' => '',
    'details' => []
];

try {
    // Teste 1: Verificar extensões PHP
    $result['details']['php_version'] = PHP_VERSION;
    $result['details']['pdo_available'] = extension_loaded('pdo');
    $result['details']['pdo_mysql_available'] = extension_loaded('pdo_mysql');
    
    if (!extension_loaded('pdo')) {
        throw new Exception('Extensão PDO não está disponível');
    }
    
    if (!extension_loaded('pdo_mysql')) {
        throw new Exception('Extensão PDO MySQL não está disponível');
    }
    
    // Teste 2: Conexão básica com MySQL
    try {
        $pdo_test = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
        $pdo_test->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $result['details']['mysql_connection'] = true;
    } catch (PDOException $e) {
        $result['details']['mysql_connection'] = false;
        $result['details']['mysql_error'] = $e->getMessage();
        throw new Exception('Erro ao conectar com MySQL: ' . $e->getMessage());
    }
    
    // Teste 3: Verificar se banco existe
    try {
        $stmt = $pdo_test->query("SHOW DATABASES LIKE '" . DB_NAME . "'");
        $result['details']['database_exists'] = $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $result['details']['database_exists'] = false;
    }
    
    // Teste 4: Tentar conectar com o banco específico
    try {
        $pdo = getConnection();
        $result['details']['database_connection'] = true;
        
        // Teste 5: Verificar tabelas
        $tables = ['users', 'categories', 'albums', 'photos', 'shared_photos', 'api_tokens', 'backups'];
        $existing_tables = [];
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                $existing_tables[] = $table;
            }
        }
        
        $result['details']['existing_tables'] = $existing_tables;
        $result['details']['missing_tables'] = array_diff($tables, $existing_tables);
        
    } catch (Exception $e) {
        $result['details']['database_connection'] = false;
        $result['details']['database_error'] = $e->getMessage();
    }
    
    // Teste 6: Verificar diretórios
    $directories = [
        'uploads' => UPLOAD_DIR,
        'backups' => BACKUP_DIR,
        'temp' => TEMP_DIR
    ];
    
    $result['details']['directories'] = [];
    
    foreach ($directories as $name => $path) {
        $result['details']['directories'][$name] = [
            'path' => $path,
            'exists' => file_exists($path),
            'writable' => is_writable($path)
        ];
    }
    
    $result['success'] = true;
    $result['message'] = 'Todos os testes passaram com sucesso!';
    
} catch (Exception $e) {
    $result['success'] = false;
    $result['message'] = $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT);
?>