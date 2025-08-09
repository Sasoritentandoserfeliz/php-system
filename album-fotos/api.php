<?php
require_once 'config.php';
requireLogin();

$message = '';
$messageType = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_token':
                $result = handleCreateToken($_POST['token_name'], $_POST['permissions'] ?? [], $_POST['expires_days'] ?? null);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            
            case 'delete_token':
                $result = handleDeleteToken($_POST['token_id']);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            
            case 'toggle_api':
                $result = handleToggleApi($_POST['api_enabled']);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
        }
    }
}

function handleCreateToken($name, $permissions, $expiresDays) {
    try {
        $pdo = getConnection();
        
        $token = generateToken(32);
        $expiresAt = null;
        
        if ($expiresDays) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresDays} days"));
        }
        
        $permissionsJson = json_encode($permissions);
        
        $stmt = $pdo->prepare("
            INSERT INTO api_tokens (user_id, token, name, permissions, expires_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$_SESSION['user_id'], $token, $name, $permissionsJson, $expiresAt]);
        
        return ['message' => 'Token da API criado com sucesso!', 'type' => 'success'];
    } catch (PDOException $e) {
        return ['message' => 'Erro ao criar token: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function handleDeleteToken($tokenId) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE id = ? AND user_id = ?");
        $stmt->execute([$tokenId, $_SESSION['user_id']]);
        
        return ['message' => 'Token removido com sucesso!', 'type' => 'success'];
    } catch (PDOException $e) {
        return ['message' => 'Erro ao remover token: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function handleToggleApi($enabled) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("UPDATE users SET api_enabled = ? WHERE id = ?");
        $stmt->execute([$enabled ? 1 : 0, $_SESSION['user_id']]);
        
        $message = $enabled ? 'API habilitada com sucesso!' : 'API desabilitada com sucesso!';
        return ['message' => $message, 'type' => 'success'];
    } catch (PDOException $e) {
        return ['message' => 'Erro ao atualizar configuração: ' . $e->getMessage(), 'type' => 'error'];
    }
}

// Buscar dados
try {
    $pdo = getConnection();
    
    // Buscar configuração do usuário
    $stmt = $pdo->prepare("SELECT api_enabled FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userConfig = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Buscar tokens existentes
    $stmt = $pdo->prepare("
        SELECT * FROM api_tokens 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $message = 'Erro ao carregar dados: ' . $e->getMessage();
    $messageType = 'error';
    $userConfig = ['api_enabled' => false];
    $tokens = [];
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API REST - <?php echo htmlspecialchars($_SESSION['username']); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .api-docs {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        
        .endpoint {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid #667eea;
        }
        
        .method {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.8rem;
            margin-right: 0.5rem;
        }
        
        .method.get { background: #28a745; color: white; }
        .method.post { background: #007bff; color: white; }
        .method.put { background: #ffc107; color: black; }
        .method.delete { background: #dc3545; color: white; }
        
        .code-block {
            background: #2d3748;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="logo">🔌 API REST</div>
                <div class="nav-links">
                    <a href="album.php">Voltar ao Álbum</a>
                    <span class="user-info">Olá, <?php echo htmlspecialchars($_SESSION['username']); ?>!</span>
                    <a href="logout.php">Sair</a>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Configurações da API -->
        <div class="card">
            <h3>Configurações da API</h3>
            
            <form method="POST">
                <input type="hidden" name="action" value="toggle_api">
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="api_enabled" value="1" 
                               <?php echo $userConfig['api_enabled'] ? 'checked' : ''; ?>
                               onchange="this.form.submit()">
                        Habilitar API REST
                    </label>
                    <p style="color: #666; font-size: 0.9rem; margin-top: 0.5rem;">
                        Permite acesso programático às suas fotos através de tokens de autenticação.
                    </p>
                </div>
            </form>
        </div>

        <?php if ($userConfig['api_enabled']): ?>
            <!-- Criar novo token -->
            <div class="card">
                <h3>Criar Novo Token</h3>
                
                <form method="POST">
                    <input type="hidden" name="action" value="create_token">
                    
                    <div class="form-group">
                        <label>Nome do Token:</label>
                        <input type="text" name="token_name" class="form-control" 
                               placeholder="Ex: App Mobile, Site Pessoal..." required>
                    </div>
                    
                    <div class="form-group">
                        <label>Permissões:</label>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.5rem;">
                            <label><input type="checkbox" name="permissions[]" value="read"> Ler fotos</label>
                            <label><input type="checkbox" name="permissions[]" value="upload"> Upload de fotos</label>
                            <label><input type="checkbox" name="permissions[]" value="delete"> Excluir fotos</label>
                            <label><input type="checkbox" name="permissions[]" value="albums"> Gerenciar álbuns</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Expiração:</label>
                        <select name="expires_days" class="form-control">
                            <option value="">Nunca expira</option>
                            <option value="30">30 dias</option>
                            <option value="90">90 dias</option>
                            <option value="365">1 ano</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn">Criar Token</button>
                </form>
            </div>

            <!-- Lista de tokens -->
            <div class="card">
                <h3>Meus Tokens (<?php echo count($tokens); ?>)</h3>
                
                <?php if (empty($tokens)): ?>
                    <div class="empty-state">
                        <h3>Nenhum token ainda</h3>
                        <p>Crie seu primeiro token de API acima!</p>
                    </div>
                <?php else: ?>
                    <div class="tokens-list">
                        <?php foreach ($tokens as $token): ?>
                            <div class="token-item" style="background: #f8f9fa; padding: 1.5rem; border-radius: 10px; margin-bottom: 1rem;">
                                <div style="display: flex; justify-content: between; align-items: start; gap: 1rem;">
                                    <div style="flex: 1;">
                                        <h4><?php echo htmlspecialchars($token['name']); ?></h4>
                                        <p><strong>Token:</strong></p>
                                        <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 1rem;">
                                            <input type="text" value="<?php echo $token['token']; ?>" 
                                                   class="form-control" readonly onclick="this.select()" 
                                                   style="font-family: monospace; font-size: 0.9rem;">
                                            <button class="btn btn-small" onclick="copyToClipboard(this.previousElementSibling)">
                                                Copiar
                                            </button>
                                        </div>
                                        
                                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; font-size: 0.9rem;">
                                            <div>
                                                <strong>Permissões:</strong><br>
                                                <?php 
                                                $permissions = json_decode($token['permissions'], true) ?: [];
                                                echo empty($permissions) ? 'Nenhuma' : implode(', ', $permissions);
                                                ?>
                                            </div>
                                            <div>
                                                <strong>Criado:</strong><br>
                                                <?php echo date('d/m/Y H:i', strtotime($token['created_at'])); ?>
                                            </div>
                                            <?php if ($token['expires_at']): ?>
                                                <div>
                                                    <strong>Expira:</strong><br>
                                                    <?php echo date('d/m/Y H:i', strtotime($token['expires_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($token['last_used_at']): ?>
                                                <div>
                                                    <strong>Último uso:</strong><br>
                                                    <?php echo date('d/m/Y H:i', strtotime($token['last_used_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_token">
                                            <input type="hidden" name="token_id" value="<?php echo $token['id']; ?>">
                                            <button type="submit" class="btn btn-small btn-danger" 
                                                    onclick="return confirm('Remover este token?')">
                                                Remover
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Documentação da API -->
            <div class="card">
                <h3>Documentação da API</h3>
                
                <div class="api-docs">
                    <p><strong>Base URL:</strong> <code><?php echo $_SERVER['HTTP_HOST']; ?>/album-fotos/api/</code></p>
                    <p><strong>Autenticação:</strong> Inclua o header <code>Authorization: Bearer SEU_TOKEN</code></p>
                    
                    <div class="endpoint">
                        <span class="method get">GET</span>
                        <strong>/photos</strong>
                        <p>Lista todas as fotos do usuário</p>
                        <div class="code-block">
curl -H "Authorization: Bearer SEU_TOKEN" \
     <?php echo $_SERVER['HTTP_HOST']; ?>/album-fotos/api/photos
                        </div>
                    </div>
                    
                    <div class="endpoint">
                        <span class="method get">GET</span>
                        <strong>/photos/{id}</strong>
                        <p>Obtém detalhes de uma foto específica</p>
                        <div class="code-block">
curl -H "Authorization: Bearer SEU_TOKEN" \
     <?php echo $_SERVER['HTTP_HOST']; ?>/album-fotos/api/photos/123
                        </div>
                    </div>
                    
                    <div class="endpoint">
                        <span class="method post">POST</span>
                        <strong>/photos</strong>
                        <p>Faz upload de uma nova foto</p>
                        <div class="code-block">
curl -X POST \
     -H "Authorization: Bearer SEU_TOKEN" \
     -F "photo=@imagem.jpg" \
     -F "album_id=1" \
     <?php echo $_SERVER['HTTP_HOST']; ?>/album-fotos/api/photos
                        </div>
                    </div>
                    
                    <div class="endpoint">
                        <span class="method delete">DELETE</span>
                        <strong>/photos/{id}</strong>
                        <p>Exclui uma foto</p>
                        <div class="code-block">
curl -X DELETE \
     -H "Authorization: Bearer SEU_TOKEN" \
     <?php echo $_SERVER['HTTP_HOST']; ?>/album-fotos/api/photos/123
                        </div>
                    </div>
                    
                    <div class="endpoint">
                        <span class="method get">GET</span>
                        <strong>/albums</strong>
                        <p>Lista todos os álbuns do usuário</p>
                        <div class="code-block">
curl -H "Authorization: Bearer SEU_TOKEN" \
     <?php echo $_SERVER['HTTP_HOST']; ?>/album-fotos/api/albums
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function copyToClipboard(input) {
            input.select();
            document.execCommand('copy');
            
            const button = input.nextElementSibling;
            const originalText = button.textContent;
            button.textContent = 'Copiado!';
            button.style.background = '#28a745';
            
            setTimeout(() => {
                button.textContent = originalText;
                button.style.background = '';
            }, 2000);
        }
    </script>
</body>
</html>