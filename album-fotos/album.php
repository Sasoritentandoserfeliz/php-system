<?php
require_once 'config.php';
requireLogin();

$message = '';
$messageType = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'upload':
                $result = handlePhotoUpload();
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            
            case 'delete_photo':
                $result = handlePhotoDelete($_POST['photo_id']);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            
            case 'create_album':
                $result = handleCreateAlbum($_POST['album_name'], $_POST['category_id'], $_POST['description'] ?? '');
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            
            case 'create_category':
                $result = handleCreateCategory($_POST['category_name'], $_POST['description'] ?? '', $_POST['color'] ?? '#667eea');
                $message = $result['message'];
                $messageType = $result['type'];
                break;
        }
    }
}

function handlePhotoUpload() {
    if (!isset($_FILES['photo'])) {
        return ['message' => 'Nenhum arquivo enviado.', 'type' => 'error'];
    }
    
    $file = $_FILES['photo'];
    $albumId = $_POST['album_id'] ?? null;
    
    // Garantir que o usuário tenha pelo menos um álbum
    if (!$albumId) {
        $albumId = ensureUserHasAlbum($_SESSION['user_id']);
        if (!$albumId) {
            return ['message' => 'Erro ao criar álbum padrão.', 'type' => 'error'];
        }
    } else {
        // Validar se o álbum pertence ao usuário
        if (!validateUserAlbum($albumId, $_SESSION['user_id'])) {
            $albumId = ensureUserHasAlbum($_SESSION['user_id']);
            if (!$albumId) {
                return ['message' => 'Álbum inválido e erro ao criar álbum padrão.', 'type' => 'error'];
            }
        }
    }
    
    // Validações
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['message' => 'Erro no upload da imagem.', 'type' => 'error'];
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['message' => 'Arquivo muito grande. Máximo: ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB', 'type' => 'error'];
    }
    
    $fileInfo = pathinfo($file['name']);
    $extension = strtolower($fileInfo['extension']);
    
    if (!in_array($extension, ALLOWED_TYPES)) {
        return ['message' => 'Tipo de arquivo não permitido. Use: ' . implode(', ', ALLOWED_TYPES), 'type' => 'error'];
    }
    
    try {
        // Criar diretório do usuário
        $userDir = createUserUploadDir($_SESSION['user_id']);
        
        // Gerar nome único para o arquivo
        $filename = uniqid() . '_' . time() . '.' . $extension;
        $filepath = $userDir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Obter dimensões da imagem
            $imageInfo = getimagesize($filepath);
            $width = $imageInfo[0] ?? null;
            $height = $imageInfo[1] ?? null;
            
            // Salvar no banco de dados
            $pdo = getConnection();
            $stmt = $pdo->prepare("
                INSERT INTO photos (user_id, album_id, filename, original_name, file_size, width, height) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['user_id'], 
                $albumId, 
                $filename, 
                $file['name'], 
                $file['size'],
                $width,
                $height
            ]);
            
            return ['message' => 'Imagem enviada com sucesso!', 'type' => 'success'];
        } else {
            return ['message' => 'Erro ao salvar arquivo no servidor.', 'type' => 'error'];
        }
    } catch (PDOException $e) {
        return ['message' => 'Erro ao salvar no banco: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function handlePhotoDelete($photoId) {
    try {
        $pdo = getConnection();
        
        // Buscar foto do usuário
        $stmt = $pdo->prepare("SELECT * FROM photos WHERE id = ? AND user_id = ?");
        $stmt->execute([$photoId, $_SESSION['user_id']]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$photo) {
            return ['message' => 'Foto não encontrada.', 'type' => 'error'];
        }
        
        // Remover arquivo físico
        $filepath = UPLOAD_DIR . $_SESSION['user_id'] . '/' . $photo['filename'];
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
        // Remover do banco
        $stmt = $pdo->prepare("DELETE FROM photos WHERE id = ? AND user_id = ?");
        $stmt->execute([$photoId, $_SESSION['user_id']]);
        
        return ['message' => 'Foto excluída com sucesso!', 'type' => 'success'];
    } catch (PDOException $e) {
        return ['message' => 'Erro ao excluir foto: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function handleCreateAlbum($name, $categoryId, $description) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO albums (user_id, category_id, name, description) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$_SESSION['user_id'], $categoryId, $name, $description]);
        
        return ['message' => 'Álbum criado com sucesso!', 'type' => 'success'];
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            return ['message' => 'Já existe um álbum com este nome.', 'type' => 'error'];
        }
        return ['message' => 'Erro ao criar álbum: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function handleCreateCategory($name, $description, $color) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO categories (user_id, name, description, color) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$_SESSION['user_id'], $name, $description, $color]);
        
        return ['message' => 'Categoria criada com sucesso!', 'type' => 'success'];
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            return ['message' => 'Já existe uma categoria com este nome.', 'type' => 'error'];
        }
        return ['message' => 'Erro ao criar categoria: ' . $e->getMessage(), 'type' => 'error'];
    }
}

// Buscar dados do usuário
try {
    $pdo = getConnection();
    
    // Buscar categorias
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? ORDER BY name");
    $stmt->execute([$_SESSION['user_id']]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar álbuns com contagem de fotos
    $stmt = $pdo->prepare("
        SELECT a.*, c.name as category_name, c.color as category_color,
               COUNT(p.id) as photo_count,
               (SELECT filename FROM photos WHERE album_id = a.id ORDER BY upload_datetime DESC LIMIT 1) as cover_filename
        FROM albums a 
        LEFT JOIN categories c ON a.category_id = c.id 
        LEFT JOIN photos p ON a.id = p.album_id 
        WHERE a.user_id = ? 
        GROUP BY a.id 
        ORDER BY c.name, a.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $albums = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar fotos do álbum selecionado
    $selectedAlbumId = $_GET['album'] ?? ($albums[0]['id'] ?? null);
    $photos = [];
    
    if ($selectedAlbumId) {
        $stmt = $pdo->prepare("
            SELECT p.*, a.name as album_name 
            FROM photos p 
            LEFT JOIN albums a ON p.album_id = a.id 
            WHERE p.user_id = ? AND (p.album_id = ? OR ? IS NULL)
            ORDER BY p.upload_datetime DESC
        ");
        $stmt->execute([$_SESSION['user_id'], $selectedAlbumId, $selectedAlbumId]);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    $message = 'Erro ao carregar dados: ' . $e->getMessage();
    $messageType = 'error';
    $categories = [];
    $albums = [];
    $photos = [];
}

function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Álbum - <?php echo htmlspecialchars($_SESSION['username']); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="logo">📸 Meu Álbum</div>
                <div class="nav-links">
                    <a href="share.php">Compartilhar</a>
                    <a href="backup.php">Backup</a>
                    <a href="api.php">API</a>
                    <span class="user-info">Olá, <?php echo htmlspecialchars($_SESSION['username']); ?>!</span>
                    <a href="logout.php">Sair</a>
                </div>
            </div>
        </div>

        <!-- Sidebar com categorias e álbuns -->
        <div class="main-layout">
            <div class="sidebar">
                <div class="sidebar-section">
                    <h3>Categorias</h3>
                    <button class="btn btn-small" onclick="showCreateCategoryModal()">+ Nova Categoria</button>
                    
                    <?php foreach ($categories as $category): ?>
                        <div class="category-item" style="border-left: 4px solid <?php echo $category['color']; ?>">
                            <h4><?php echo htmlspecialchars($category['name']); ?></h4>
                            
                            <?php foreach ($albums as $album): ?>
                                <?php if ($album['category_id'] == $category['id']): ?>
                                    <div class="album-item <?php echo $selectedAlbumId == $album['id'] ? 'active' : ''; ?>">
                                        <a href="?album=<?php echo $album['id']; ?>">
                                            <div class="album-info">
                                                <span class="album-name"><?php echo htmlspecialchars($album['name']); ?></span>
                                                <span class="album-count"><?php echo $album['photo_count']; ?> fotos</span>
                                            </div>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            
                            <button class="btn btn-small btn-secondary" onclick="showCreateAlbumModal(<?php echo $category['id']; ?>)">
                                + Novo Álbum
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="main-content">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
                <?php endif; ?>

                <!-- Upload de fotos -->
                <div class="card">
                    <h3>Enviar Nova Foto</h3>
                    
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <input type="hidden" name="action" value="upload">
                        <input type="hidden" name="album_id" value="<?php echo $selectedAlbumId; ?>">
                        
                        <div class="upload-area" onclick="document.getElementById('photoInput').click()">
                            <p style="font-size: 1.2rem; margin-bottom: 0.5rem;">📷 Clique para selecionar uma foto</p>
                            <p style="color: #666; font-size: 0.9rem;">
                                Formatos aceitos: JPG, PNG, GIF | Tamanho máximo: <?php echo MAX_FILE_SIZE / 1024 / 1024; ?>MB
                            </p>
                            <input type="file" id="photoInput" name="photo" accept="image/*" style="display: none;" onchange="previewImage(this)">
                        </div>
                        
                        <div id="preview" style="margin-top: 1rem; display: none;">
                            <img id="previewImg" style="max-width: 200px; max-height: 200px; border-radius: 8px;">
                            <p id="fileName" style="margin-top: 0.5rem; font-weight: 500;"></p>
                        </div>
                        
                        <button type="submit" class="btn" style="margin-top: 1rem;" id="uploadBtn" disabled>
                            Enviar Foto
                        </button>
                    </form>
                </div>

                <!-- Galeria de fotos -->
                <div class="card">
                    <h3>
                        <?php 
                        $currentAlbum = array_filter($albums, function($a) use ($selectedAlbumId) { 
                            return $a['id'] == $selectedAlbumId; 
                        });
                        $currentAlbum = reset($currentAlbum);
                        echo $currentAlbum ? htmlspecialchars($currentAlbum['name']) : 'Todas as Fotos';
                        ?>
                        (<?php echo count($photos); ?> fotos)
                    </h3>
                    
                    <?php if (empty($photos)): ?>
                        <div class="empty-state">
                            <h3>Nenhuma foto ainda</h3>
                            <p>Envie sua primeira foto usando o formulário acima!</p>
                        </div>
                    <?php else: ?>
                        <div class="photos-grid">
                            <?php foreach ($photos as $photo): ?>
                                <div class="photo-item">
                                    <img src="uploads/<?php echo $_SESSION['user_id']; ?>/<?php echo htmlspecialchars($photo['filename']); ?>" 
                                         alt="<?php echo htmlspecialchars($photo['original_name']); ?>"
                                         loading="lazy"
                                         onclick="openPhotoModal(<?php echo $photo['id']; ?>)">
                                    
                                    <div class="photo-info">
                                        <div class="photo-date">
                                            <?php echo date('d/m/Y H:i', strtotime($photo['upload_datetime'])); ?>
                                        </div>
                                        <div class="photo-name">
                                            <?php echo htmlspecialchars($photo['original_name']); ?>
                                        </div>
                                        <div class="photo-size">
                                            <?php echo formatFileSize($photo['file_size']); ?>
                                            <?php if ($photo['width'] && $photo['height']): ?>
                                                | <?php echo $photo['width']; ?>x<?php echo $photo['height']; ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="photo-actions">
                                            <button class="btn btn-small" onclick="sharePhoto(<?php echo $photo['id']; ?>)">Compartilhar</button>
                                            <button class="btn btn-small btn-secondary" onclick="editPhoto(<?php echo $photo['id']; ?>)">Editar</button>
                                            <button class="btn btn-small btn-danger" onclick="deletePhoto(<?php echo $photo['id']; ?>)">Excluir</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modais -->
    <div id="createCategoryModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('createCategoryModal')">&times;</span>
            <h3>Nova Categoria</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_category">
                <div class="form-group">
                    <label>Nome:</label>
                    <input type="text" name="category_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Descrição:</label>
                    <textarea name="description" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label>Cor:</label>
                    <input type="color" name="color" class="form-control" value="#667eea">
                </div>
                <button type="submit" class="btn">Criar Categoria</button>
            </form>
        </div>
    </div>

    <div id="createAlbumModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('createAlbumModal')">&times;</span>
            <h3>Novo Álbum</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_album">
                <input type="hidden" name="category_id" id="albumCategoryId">
                <div class="form-group">
                    <label>Nome:</label>
                    <input type="text" name="album_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Descrição:</label>
                    <textarea name="description" class="form-control"></textarea>
                </div>
                <button type="submit" class="btn">Criar Álbum</button>
            </form>
        </div>
    </div>

    <script src="album.js"></script>
</body>
</html>