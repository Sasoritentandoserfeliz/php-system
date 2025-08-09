<?php
require_once 'config.php';
requireLogin();

$message = '';
$messageType = '';
$photo = null;

// Buscar foto
if (isset($_GET['photo'])) {
    $photoId = $_GET['photo'];
    
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT * FROM photos WHERE id = ? AND user_id = ?");
        $stmt->execute([$photoId, $_SESSION['user_id']]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$photo) {
            $message = 'Foto não encontrada.';
            $messageType = 'error';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao carregar foto: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Processar edição
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $photo) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'resize':
            $result = handleResize($photo, $_POST['width'], $_POST['height']);
            $message = $result['message'];
            $messageType = $result['type'];
            break;
            
        case 'rotate':
            $result = handleRotate($photo, $_POST['angle']);
            $message = $result['message'];
            $messageType = $result['type'];
            break;
    }
    
    // Recarregar foto após edição
    if ($messageType === 'success') {
        try {
            $pdo = getConnection();
            $stmt = $pdo->prepare("SELECT * FROM photos WHERE id = ? AND user_id = ?");
            $stmt->execute([$photoId, $_SESSION['user_id']]);
            $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Ignorar erro de recarga
        }
    }
}

function handleResize($photo, $newWidth, $newHeight) {
    try {
        $sourcePath = UPLOAD_DIR . $photo['user_id'] . '/' . $photo['filename'];
        
        if (!file_exists($sourcePath)) {
            return ['message' => 'Arquivo original não encontrado.', 'type' => 'error'];
        }
        
        // Criar backup
        $backupPath = createUserBackupDir($photo['user_id']) . 'backup_' . $photo['filename'];
        copy($sourcePath, $backupPath);
        
        // Redimensionar
        if (resizeImage($sourcePath, $sourcePath, $newWidth, $newHeight)) {
            // Atualizar banco
            $pdo = getConnection();
            $stmt = $pdo->prepare("
                UPDATE photos 
                SET width = ?, height = ?, edited_at = NOW(), backup_path = ? 
                WHERE id = ?
            ");
            $stmt->execute([$newWidth, $newHeight, $backupPath, $photo['id']]);
            
            return ['message' => 'Foto redimensionada com sucesso!', 'type' => 'success'];
        } else {
            return ['message' => 'Erro ao redimensionar a foto.', 'type' => 'error'];
        }
    } catch (Exception $e) {
        return ['message' => 'Erro: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function handleRotate($photo, $angle) {
    try {
        $sourcePath = UPLOAD_DIR . $photo['user_id'] . '/' . $photo['filename'];
        
        if (!file_exists($sourcePath)) {
            return ['message' => 'Arquivo original não encontrado.', 'type' => 'error'];
        }
        
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return ['message' => 'Arquivo de imagem inválido.', 'type' => 'error'];
        }
        
        // Criar backup
        $backupPath = createUserBackupDir($photo['user_id']) . 'backup_' . $photo['filename'];
        copy($sourcePath, $backupPath);
        
        // Carregar imagem
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = imagecreatefromgif($sourcePath);
                break;
            default:
                return ['message' => 'Formato de imagem não suportado para rotação.', 'type' => 'error'];
        }
        
        // Rotacionar
        $rotatedImage = imagerotate($sourceImage, -$angle, 0);
        
        // Salvar
        $result = false;
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $result = imagejpeg($rotatedImage, $sourcePath, 85);
                break;
            case IMAGETYPE_PNG:
                $result = imagepng($rotatedImage, $sourcePath, 9);
                break;
            case IMAGETYPE_GIF:
                $result = imagegif($rotatedImage, $sourcePath);
                break;
        }
        
        if ($result) {
            // Obter novas dimensões
            $newImageInfo = getimagesize($sourcePath);
            
            // Atualizar banco
            $pdo = getConnection();
            $stmt = $pdo->prepare("
                UPDATE photos 
                SET width = ?, height = ?, edited_at = NOW(), backup_path = ? 
                WHERE id = ?
            ");
            $stmt->execute([$newImageInfo[0], $newImageInfo[1], $backupPath, $photo['id']]);
            
            // Limpar memória
            imagedestroy($sourceImage);
            imagedestroy($rotatedImage);
            
            return ['message' => 'Foto rotacionada com sucesso!', 'type' => 'success'];
        } else {
            return ['message' => 'Erro ao salvar foto rotacionada.', 'type' => 'error'];
        }
    } catch (Exception $e) {
        return ['message' => 'Erro: ' . $e->getMessage(), 'type' => 'error'];
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Foto - <?php echo $photo ? htmlspecialchars($photo['original_name']) : 'Não encontrada'; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .edit-container {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 2rem;
        }
        
        .photo-preview {
            text-align: center;
        }
        
        .photo-preview img {
            max-width: 100%;
            max-height: 500px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .edit-tools {
            background: rgba(255, 255, 255, 0.95);
            padding: 1.5rem;
            border-radius: 15px;
            height: fit-content;
        }
        
        .tool-section {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #eee;
        }
        
        .tool-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .rotate-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .edit-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="logo">📸 Editar Foto</div>
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

        <?php if (!$photo): ?>
            <div class="card">
                <div class="empty-state">
                    <h3>Foto não encontrada</h3>
                    <p>A foto que você está tentando editar não foi encontrada.</p>
                    <a href="album.php" class="btn">Voltar ao Álbum</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="edit-container">
                    <div class="photo-preview">
                        <h3><?php echo htmlspecialchars($photo['original_name']); ?></h3>
                        <img src="uploads/<?php echo $photo['user_id']; ?>/<?php echo htmlspecialchars($photo['filename']); ?>?v=<?php echo time(); ?>" 
                             alt="<?php echo htmlspecialchars($photo['original_name']); ?>"
                             id="photoPreview">
                        
                        <div style="margin-top: 1rem; color: #666; font-size: 0.9rem;">
                            <p>Dimensões: <?php echo $photo['width']; ?> x <?php echo $photo['height']; ?> pixels</p>
                            <p>Tamanho: <?php echo number_format($photo['file_size'] / 1024, 2); ?> KB</p>
                            <?php if ($photo['edited_at']): ?>
                                <p>Última edição: <?php echo date('d/m/Y H:i', strtotime($photo['edited_at'])); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="edit-tools">
                        <!-- Redimensionar -->
                        <div class="tool-section">
                            <h4>Redimensionar</h4>
                            <form method="POST">
                                <input type="hidden" name="action" value="resize">
                                
                                <div class="form-group">
                                    <label>Largura (px):</label>
                                    <input type="number" name="width" class="form-control" 
                                           value="<?php echo $photo['width']; ?>" min="50" max="2000" required>
                                </div>
                                
                                <div class="form-group">
                                    <label>Altura (px):</label>
                                    <input type="number" name="height" class="form-control" 
                                           value="<?php echo $photo['height']; ?>" min="50" max="2000" required>
                                </div>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                                    <button type="button" class="btn btn-secondary btn-small" onclick="resetDimensions()">
                                        Original
                                    </button>
                                    <button type="submit" class="btn btn-small">Aplicar</button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Rotacionar -->
                        <div class="tool-section">
                            <h4>Rotacionar</h4>
                            <div class="rotate-buttons">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="rotate">
                                    <input type="hidden" name="angle" value="90">
                                    <button type="submit" class="btn btn-small">↻ 90°</button>
                                </form>
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="rotate">
                                    <input type="hidden" name="angle" value="-90">
                                    <button type="submit" class="btn btn-small">↺ 90°</button>
                                </form>
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="rotate">
                                    <input type="hidden" name="angle" value="180">
                                    <button type="submit" class="btn btn-small">↻ 180°</button>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Presets de redimensionamento -->
                        <div class="tool-section">
                            <h4>Tamanhos Predefinidos</h4>
                            <div style="display: grid; gap: 0.5rem;">
                                <button class="btn btn-secondary btn-small" onclick="setDimensions(800, 600)">
                                    800x600 (4:3)
                                </button>
                                <button class="btn btn-secondary btn-small" onclick="setDimensions(1024, 768)">
                                    1024x768 (4:3)
                                </button>
                                <button class="btn btn-secondary btn-small" onclick="setDimensions(1920, 1080)">
                                    1920x1080 (16:9)
                                </button>
                                <button class="btn btn-secondary btn-small" onclick="setDimensions(500, 500)">
                                    500x500 (1:1)
                                </button>
                            </div>
                        </div>
                        
                        <?php if ($photo['backup_path'] && file_exists($photo['backup_path'])): ?>
                            <!-- Restaurar backup -->
                            <div class="tool-section">
                                <h4>Restaurar Original</h4>
                                <p style="font-size: 0.9rem; color: #666; margin-bottom: 1rem;">
                                    Restaurar a foto para o estado original antes das edições.
                                </p>
                                <button class="btn btn-secondary btn-small" onclick="restoreBackup()">
                                    Restaurar Original
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function setDimensions(width, height) {
            document.querySelector('input[name="width"]').value = width;
            document.querySelector('input[name="height"]').value = height;
        }
        
        function resetDimensions() {
            // Valores originais da foto
            setDimensions(<?php echo $photo['width'] ?? 0; ?>, <?php echo $photo['height'] ?? 0; ?>);
        }
        
        function restoreBackup() {
            if (confirm('Tem certeza que deseja restaurar a foto original? Todas as edições serão perdidas.')) {
                // Implementar restauração via AJAX ou form
                alert('Funcionalidade de restauração será implementada em breve.');
            }
        }
        
        // Manter proporção ao alterar dimensões
        const widthInput = document.querySelector('input[name="width"]');
        const heightInput = document.querySelector('input[name="height"]');
        const originalRatio = <?php echo $photo['width'] / $photo['height']; ?>;
        
        widthInput.addEventListener('input', function() {
            if (event.target.dataset.keepRatio !== 'false') {
                heightInput.value = Math.round(this.value / originalRatio);
            }
        });
        
        heightInput.addEventListener('input', function() {
            if (event.target.dataset.keepRatio !== 'false') {
                widthInput.value = Math.round(this.value * originalRatio);
            }
        });
    </script>
</body>
</html>