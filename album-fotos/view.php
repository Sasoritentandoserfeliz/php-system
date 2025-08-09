<?php
require_once 'config.php';

$error = '';
$photo = null;
$shareInfo = null;

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    try {
        $pdo = getConnection();
        
        // Buscar compartilhamento
        $stmt = $pdo->prepare("
            SELECT sp.*, p.*, u.username as owner_username,
                   ush.username as shared_with_username
            FROM shared_photos sp 
            JOIN photos p ON sp.photo_id = p.id 
            JOIN users u ON sp.shared_by = u.id 
            LEFT JOIN users ush ON sp.shared_with = ush.id 
            WHERE sp.share_token = ? AND (sp.expires_at IS NULL OR sp.expires_at > NOW())
        ");
        $stmt->execute([$token]);
        $shareInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$shareInfo) {
            $error = 'Link de compartilhamento inválido ou expirado.';
        } else {
            // Verificar se é compartilhamento privado e se o usuário tem acesso
            if (!$shareInfo['is_public'] && $shareInfo['shared_with']) {
                if (!isLoggedIn() || $_SESSION['user_id'] != $shareInfo['shared_with']) {
                    $error = 'Você não tem permissão para ver esta foto.';
                }
            }
            
            if (!$error) {
                $photo = $shareInfo;
            }
        }
        
    } catch (PDOException $e) {
        $error = 'Erro ao carregar foto: ' . $e->getMessage();
    }
} else {
    $error = 'Token de compartilhamento não fornecido.';
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $photo ? htmlspecialchars($photo['original_name']) : 'Foto Compartilhada'; ?> - Álbum de Fotos</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .photo-viewer {
            max-width: 800px;
            margin: 0 auto;
            text-align: center;
        }
        
        .photo-viewer img {
            max-width: 100%;
            max-height: 70vh;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }
        
        .photo-meta {
            margin-top: 2rem;
            text-align: left;
            background: rgba(255, 255, 255, 0.9);
            padding: 1.5rem;
            border-radius: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="logo">📸 Foto Compartilhada</div>
                <div class="nav-links">
                    <?php if (isLoggedIn()): ?>
                        <a href="album.php">Meu Álbum</a>
                        <span class="user-info">Olá, <?php echo htmlspecialchars($_SESSION['username']); ?>!</span>
                        <a href="logout.php">Sair</a>
                    <?php else: ?>
                        <a href="login.php">Login</a>
                        <a href="register.php">Cadastrar</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="card">
                <div class="alert alert-error"><?php echo $error; ?></div>
                <p style="text-align: center;">
                    <a href="index.php" class="btn">Voltar ao Início</a>
                </p>
            </div>
        <?php else: ?>
            <div class="card photo-viewer">
                <img src="uploads/<?php echo $photo['user_id']; ?>/<?php echo htmlspecialchars($photo['filename']); ?>" 
                     alt="<?php echo htmlspecialchars($photo['original_name']); ?>">
                
                <div class="photo-meta">
                    <h3><?php echo htmlspecialchars($photo['original_name']); ?></h3>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem;">
                        <div>
                            <strong>Compartilhado por:</strong><br>
                            <?php echo htmlspecialchars($photo['owner_username']); ?>
                        </div>
                        
                        <div>
                            <strong>Data do upload:</strong><br>
                            <?php echo date('d/m/Y H:i', strtotime($photo['upload_datetime'])); ?>
                        </div>
                        
                        <?php if ($photo['width'] && $photo['height']): ?>
                            <div>
                                <strong>Dimensões:</strong><br>
                                <?php echo $photo['width']; ?> x <?php echo $photo['height']; ?> pixels
                            </div>
                        <?php endif; ?>
                        
                        <div>
                            <strong>Tamanho:</strong><br>
                            <?php 
                            if ($photo['file_size'] >= 1048576) {
                                echo number_format($photo['file_size'] / 1048576, 2) . ' MB';
                            } elseif ($photo['file_size'] >= 1024) {
                                echo number_format($photo['file_size'] / 1024, 2) . ' KB';
                            } else {
                                echo $photo['file_size'] . ' bytes';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <?php if ($shareInfo['expires_at']): ?>
                        <div style="margin-top: 1rem; padding: 1rem; background: #fff3cd; border-radius: 8px; color: #856404;">
                            <strong>⚠️ Este link expira em:</strong> 
                            <?php echo date('d/m/Y H:i', strtotime($shareInfo['expires_at'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>