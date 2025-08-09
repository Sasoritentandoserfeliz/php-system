<?php
require_once 'config.php';
requireLogin();

$message = '';
$messageType = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_share':
                $result = handleCreateShare($_POST['photo_id'], $_POST['share_type'], $_POST['expires_days'] ?? null, $_POST['shared_with'] ?? null);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            
            case 'delete_share':
                $result = handleDeleteShare($_POST['share_id']);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
        }
    }
}

function handleCreateShare($photoId, $shareType, $expiresDays, $sharedWith) {
    try {
        $pdo = getConnection();
        
        // Verificar se a foto pertence ao usuário
        $stmt = $pdo->prepare("SELECT id FROM photos WHERE id = ? AND user_id = ?");
        $stmt->execute([$photoId, $_SESSION['user_id']]);
        if (!$stmt->fetch()) {
            return ['message' => 'Foto não encontrada.', 'type' => 'error'];
        }
        
        $shareToken = generateToken(32);
        $expiresAt = null;
        
        if ($expiresDays) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresDays} days"));
        }
        
        $isPublic = ($shareType === 'public');
        $sharedWithId = ($shareType === 'user' && $sharedWith) ? $sharedWith : null;
        
        $stmt = $pdo->prepare("
            INSERT INTO shared_photos (photo_id, shared_by, shared_with, share_token, is_public, expires_at) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$photoId, $_SESSION['user_id'], $sharedWithId, $shareToken, $isPublic, $expiresAt]);
        
        return ['message' => 'Link de compartilhamento criado com sucesso!', 'type' => 'success'];
    } catch (PDOException $e) {
        return ['message' => 'Erro ao criar compartilhamento: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function handleDeleteShare($shareId) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("DELETE FROM shared_photos WHERE id = ? AND shared_by = ?");
        $stmt->execute([$shareId, $_SESSION['user_id']]);
        
        return ['message' => 'Compartilhamento removido com sucesso!', 'type' => 'success'];
    } catch (PDOException $e) {
        return ['message' => 'Erro ao remover compartilhamento: ' . $e->getMessage(), 'type' => 'error'];
    }
}

// Buscar dados
try {
    $pdo = getConnection();
    
    // Buscar fotos do usuário
    $stmt = $pdo->prepare("
        SELECT p.*, a.name as album_name 
        FROM photos p 
        LEFT JOIN albums a ON p.album_id = a.id 
        WHERE p.user_id = ? 
        ORDER BY p.upload_datetime DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar compartilhamentos existentes
    $stmt = $pdo->prepare("
        SELECT sp.*, p.original_name, p.filename, u.username as shared_with_username
        FROM shared_photos sp 
        JOIN photos p ON sp.photo_id = p.id 
        LEFT JOIN users u ON sp.shared_with = u.id 
        WHERE sp.shared_by = ? 
        ORDER BY sp.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $shares = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar usuários para compartilhamento privado
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id != ? ORDER BY username");
    $stmt->execute([$_SESSION['user_id']]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $message = 'Erro ao carregar dados: ' . $e->getMessage();
    $messageType = 'error';
    $photos = [];
    $shares = [];
    $users = [];
}

$selectedPhotoId = $_GET['photo'] ?? null;
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compartilhar Fotos - <?php echo htmlspecialchars($_SESSION['username']); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="logo">📸 Compartilhar Fotos</div>
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

        <!-- Criar novo compartilhamento -->
        <div class="card">
            <h3>Compartilhar Foto</h3>
            
            <form method="POST">
                <input type="hidden" name="action" value="create_share">
                
                <div class="form-group">
                    <label>Selecionar Foto:</label>
                    <select name="photo_id" class="form-control" required>
                        <option value="">Escolha uma foto...</option>
                        <?php foreach ($photos as $photo): ?>
                            <option value="<?php echo $photo['id']; ?>" <?php echo $selectedPhotoId == $photo['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($photo['original_name']); ?>
                                <?php if ($photo['album_name']): ?>
                                    (<?php echo htmlspecialchars($photo['album_name']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Tipo de Compartilhamento:</label>
                    <select name="share_type" class="form-control" onchange="toggleShareOptions(this.value)" required>
                        <option value="public">Público (qualquer pessoa com o link)</option>
                        <option value="user">Privado (usuário específico)</option>
                    </select>
                </div>
                
                <div class="form-group" id="userSelect" style="display: none;">
                    <label>Compartilhar com:</label>
                    <select name="shared_with" class="form-control">
                        <option value="">Selecione um usuário...</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Expiração (opcional):</label>
                    <select name="expires_days" class="form-control">
                        <option value="">Nunca expira</option>
                        <option value="1">1 dia</option>
                        <option value="7">7 dias</option>
                        <option value="30">30 dias</option>
                        <option value="90">90 dias</option>
                    </select>
                </div>
                
                <button type="submit" class="btn">Criar Link de Compartilhamento</button>
            </form>
        </div>

        <!-- Lista de compartilhamentos -->
        <div class="card">
            <h3>Meus Compartilhamentos (<?php echo count($shares); ?>)</h3>
            
            <?php if (empty($shares)): ?>
                <div class="empty-state">
                    <h3>Nenhum compartilhamento ainda</h3>
                    <p>Crie seu primeiro link de compartilhamento acima!</p>
                </div>
            <?php else: ?>
                <div class="shares-list">
                    <?php foreach ($shares as $share): ?>
                        <div class="share-item">
                            <div class="share-photo">
                                <img src="uploads/<?php echo $_SESSION['user_id']; ?>/<?php echo htmlspecialchars($share['filename']); ?>" 
                                     alt="<?php echo htmlspecialchars($share['original_name']); ?>">
                            </div>
                            
                            <div class="share-info">
                                <h4><?php echo htmlspecialchars($share['original_name']); ?></h4>
                                <p>
                                    <strong>Tipo:</strong> 
                                    <?php if ($share['is_public']): ?>
                                        Público
                                    <?php else: ?>
                                        Privado (<?php echo htmlspecialchars($share['shared_with_username'] ?? 'Usuário não encontrado'); ?>)
                                    <?php endif; ?>
                                </p>
                                <p><strong>Criado:</strong> <?php echo date('d/m/Y H:i', strtotime($share['created_at'])); ?></p>
                                <?php if ($share['expires_at']): ?>
                                    <p><strong>Expira:</strong> <?php echo date('d/m/Y H:i', strtotime($share['expires_at'])); ?></p>
                                <?php endif; ?>
                                
                                <div class="share-link">
                                    <input type="text" 
                                           value="<?php echo $_SERVER['HTTP_HOST']; ?>/album-fotos/view.php?token=<?php echo $share['share_token']; ?>" 
                                           class="form-control" 
                                           readonly 
                                           onclick="this.select()">
                                    <button class="btn btn-small" onclick="copyToClipboard(this.previousElementSibling)">Copiar</button>
                                </div>
                            </div>
                            
                            <div class="share-actions">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_share">
                                    <input type="hidden" name="share_id" value="<?php echo $share['id']; ?>">
                                    <button type="submit" class="btn btn-small btn-danger" 
                                            onclick="return confirm('Remover este compartilhamento?')">
                                        Remover
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleShareOptions(shareType) {
            const userSelect = document.getElementById('userSelect');
            if (shareType === 'user') {
                userSelect.style.display = 'block';
                userSelect.querySelector('select').required = true;
            } else {
                userSelect.style.display = 'none';
                userSelect.querySelector('select').required = false;
            }
        }
        
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