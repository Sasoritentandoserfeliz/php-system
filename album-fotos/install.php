<?php
/**
 * Script de instalação do Sistema de Álbum de Fotos
 * 
 * Este script verifica e configura automaticamente o banco de dados
 */

require_once 'config.php';

$messages = [];
$errors = [];

// Função para adicionar mensagem
function addMessage($message, $type = 'info') {
    global $messages;
    $messages[] = ['message' => $message, 'type' => $type];
}

// Função para adicionar erro
function addError($error) {
    global $errors;
    $errors[] = $error;
}

// Verificar se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
    try {
        // 1. Testar conexão básica com MySQL
        addMessage("Testando conexão com MySQL...");
        
        try {
            $pdo_test = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
            $pdo_test->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            addMessage("✓ Conexão com MySQL estabelecida", 'success');
        } catch (PDOException $e) {
            addError("✗ Erro ao conectar com MySQL: " . $e->getMessage());
            throw new Exception("Falha na conexão com MySQL");
        }
        
        // 2. Criar banco de dados se não existir
        addMessage("Verificando/criando banco de dados...");
        
        try {
            $pdo_test->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            addMessage("✓ Banco de dados '" . DB_NAME . "' verificado/criado", 'success');
        } catch (PDOException $e) {
            addError("✗ Erro ao criar banco: " . $e->getMessage());
            throw new Exception("Falha ao criar banco de dados");
        }
        
        // 3. Conectar com o banco específico
        addMessage("Conectando com o banco de dados...");
        
        try {
            $pdo = getConnection();
            addMessage("✓ Conexão com banco estabelecida", 'success');
        } catch (Exception $e) {
            addError("✗ Erro ao conectar com banco: " . $e->getMessage());
            throw new Exception("Falha na conexão com banco");
        }
        
        // 4. Executar script SQL
        addMessage("Executando script de criação das tabelas...");
        
        $sqlFile = __DIR__ . '/database.sql';
        if (!file_exists($sqlFile)) {
            addError("✗ Arquivo database.sql não encontrado");
            throw new Exception("Script SQL não encontrado");
        }
        
        $sql = file_get_contents($sqlFile);
        
        // Dividir em comandos individuais
        $commands = array_filter(array_map('trim', explode(';', $sql)));
        
        foreach ($commands as $command) {
            if (empty($command) || strpos($command, '--') === 0 || strpos($command, '/*') === 0) {
                continue;
            }
            
            try {
                $pdo->exec($command);
            } catch (PDOException $e) {
                // Ignorar erros de "já existe" mas reportar outros
                if (strpos($e->getMessage(), 'already exists') === false && 
                    strpos($e->getMessage(), 'Duplicate entry') === false) {
                    addError("Erro ao executar comando SQL: " . $e->getMessage());
                }
            }
        }
        
        addMessage("✓ Tabelas criadas com sucesso", 'success');
        
        // 5. Verificar estrutura das tabelas
        addMessage("Verificando estrutura das tabelas...");
        
        $requiredTables = ['users', 'categories', 'albums', 'photos', 'shared_photos', 'api_tokens', 'backups'];
        
        foreach ($requiredTables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                addMessage("✓ Tabela '$table' encontrada", 'success');
            } else {
                addError("✗ Tabela '$table' não encontrada");
            }
        }
        
        // 6. Criar diretórios necessários
        addMessage("Criando diretórios necessários...");
        
        $directories = [
            UPLOAD_DIR,
            BACKUP_DIR,
            TEMP_DIR
        ];
        
        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                if (mkdir($dir, 0755, true)) {
                    addMessage("✓ Diretório '$dir' criado", 'success');
                } else {
                    addError("✗ Erro ao criar diretório '$dir'");
                }
            } else {
                addMessage("✓ Diretório '$dir' já existe", 'success');
            }
        }
        
        // 7. Verificar permissões
        addMessage("Verificando permissões dos diretórios...");
        
        foreach ($directories as $dir) {
            if (is_writable($dir)) {
                addMessage("✓ Diretório '$dir' tem permissão de escrita", 'success');
            } else {
                addError("✗ Diretório '$dir' não tem permissão de escrita");
            }
        }
        
        if (empty($errors)) {
            addMessage("🎉 Instalação concluída com sucesso!", 'success');
            addMessage("Você pode agora acessar o sistema em: <a href='index.php'>index.php</a>", 'success');
        }
        
    } catch (Exception $e) {
        addError("Erro durante a instalação: " . $e->getMessage());
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - Sistema de Álbum de Fotos</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .install-container {
            max-width: 800px;
            margin: 2rem auto;
        }
        
        .message {
            padding: 0.75rem 1rem;
            margin: 0.5rem 0;
            border-radius: 8px;
            border-left: 4px solid;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border-color: #28a745;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-color: #dc3545;
        }
        
        .message.info {
            background: #cce7ff;
            color: #004085;
            border-color: #007bff;
        }
        
        .requirements {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        
        .req-item {
            display: flex;
            align-items: center;
            margin: 0.5rem 0;
        }
        
        .req-status {
            margin-right: 1rem;
            font-weight: bold;
        }
        
        .req-ok { color: #28a745; }
        .req-error { color: #dc3545; }
        .req-warning { color: #ffc107; }
    </style>
</head>
<body>
    <div class="container install-container">
        <div class="header">
            <div class="header-content">
                <div class="logo">📸 Instalação do Sistema</div>
            </div>
        </div>

        <div class="card">
            <h2>Sistema de Álbum de Fotos - Instalação</h2>
            
            <div class="requirements">
                <h3>Verificação de Requisitos</h3>
                
                <div class="req-item">
                    <span class="req-status <?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? 'req-ok' : 'req-error'; ?>">
                        <?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? '✓' : '✗'; ?>
                    </span>
                    PHP 7.4+ (Atual: <?php echo PHP_VERSION; ?>)
                </div>
                
                <div class="req-item">
                    <span class="req-status <?php echo extension_loaded('pdo') ? 'req-ok' : 'req-error'; ?>">
                        <?php echo extension_loaded('pdo') ? '✓' : '✗'; ?>
                    </span>
                    Extensão PDO
                </div>
                
                <div class="req-item">
                    <span class="req-status <?php echo extension_loaded('pdo_mysql') ? 'req-ok' : 'req-error'; ?>">
                        <?php echo extension_loaded('pdo_mysql') ? '✓' : '✗'; ?>
                    </span>
                    Extensão PDO MySQL
                </div>
                
                <div class="req-item">
                    <span class="req-status <?php echo extension_loaded('gd') ? 'req-ok' : 'req-error'; ?>">
                        <?php echo extension_loaded('gd') ? '✓' : '✗'; ?>
                    </span>
                    Extensão GD (para manipulação de imagens)
                </div>
                
                <div class="req-item">
                    <span class="req-status <?php echo extension_loaded('zip') ? 'req-ok' : 'req-error'; ?>">
                        <?php echo extension_loaded('zip') ? '✓' : '✗'; ?>
                    </span>
                    Extensão ZIP (para backups)
                </div>
                
                <div class="req-item">
                    <span class="req-status <?php echo is_writable(__DIR__) ? 'req-ok' : 'req-error'; ?>">
                        <?php echo is_writable(__DIR__) ? '✓' : '✗'; ?>
                    </span>
                    Permissão de escrita no diretório
                </div>
            </div>

            <?php if (!empty($messages)): ?>
                <div class="install-messages">
                    <h3>Log de Instalação</h3>
                    <?php foreach ($messages as $msg): ?>
                        <div class="message <?php echo $msg['type']; ?>">
                            <?php echo $msg['message']; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="install-errors">
                    <h3>Erros Encontrados</h3>
                    <?php foreach ($errors as $error): ?>
                        <div class="message error">
                            <?php echo $error; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="margin-top: 2rem;">
                <h3>Configurações do Banco</h3>
                <p><strong>Host:</strong> <?php echo DB_HOST; ?></p>
                <p><strong>Usuário:</strong> <?php echo DB_USER; ?></p>
                <p><strong>Banco:</strong> <?php echo DB_NAME; ?></p>
                <p><strong>Charset:</strong> <?php echo DB_CHARSET; ?></p>
            </div>

            <?php if (empty($messages) || !empty($errors)): ?>
                <form method="POST" style="margin-top: 2rem;">
                    <button type="submit" name="install" class="btn" style="width: 100%;">
                        🚀 Iniciar Instalação
                    </button>
                </form>
            <?php else: ?>
                <div style="margin-top: 2rem; text-align: center;">
                    <a href="index.php" class="btn">Acessar Sistema</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>