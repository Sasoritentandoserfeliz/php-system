<?php
// Configurações do banco de dados
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'photo_album');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', 'utf8mb4_unicode_ci');

// Configurações do sistema
define('UPLOAD_DIR', 'uploads/');
define('BACKUP_DIR', 'backups/');
define('TEMP_DIR', 'temp/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_TYPES', ['jpg', 'jpeg', 'png', 'gif']);
define('API_VERSION', 'v1');
define('BACKUP_RETENTION_DAYS', 30);

// Iniciar sessão
session_start();

// Função para conectar ao banco
function getConnection() {
    static $pdo = null;
    
    // Retornar conexão existente se já estiver conectado
    if ($pdo !== null) {
        return $pdo;
    }
    
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE " . DB_COLLATE
        ];
        
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        
        // Testar conexão
        $pdo->query("SELECT 1");
        
        return $pdo;
    } catch(PDOException $e) {
        // Log do erro
        error_log("Erro de conexão com banco de dados: " . $e->getMessage());
        
        // Verificar se é erro de banco não existente
        if (strpos($e->getMessage(), 'Unknown database') !== false) {
            try {
                // Tentar criar o banco
                $pdo_temp = new PDO("mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, $options);
                $pdo_temp->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET " . DB_CHARSET . " COLLATE " . DB_COLLATE);
                
                // Reconectar com o banco criado
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                return $pdo;
            } catch(PDOException $e2) {
                die("Erro crítico: Não foi possível conectar ou criar o banco de dados. Verifique as configurações do MySQL.<br>Erro: " . $e2->getMessage());
            }
        }
        
        die("Erro na conexão com o banco de dados: " . $e->getMessage() . "<br>Verifique se o MySQL está rodando e as credenciais estão corretas.");
    }
}

// Função para verificar se usuário está logado
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Função para redirecionar se não estiver logado
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

// Função para criar diretório de upload do usuário
function createUserUploadDir($userId) {
    $userDir = UPLOAD_DIR . $userId . '/';
    if (!file_exists($userDir)) {
        mkdir($userDir, 0755, true);
    }
    return $userDir;
}

// Função para criar diretório de backup do usuário
function createUserBackupDir($userId) {
    $backupDir = BACKUP_DIR . $userId . '/';
    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    return $backupDir;
}

// Função para garantir que o usuário tenha pelo menos um álbum
function ensureUserHasAlbum($userId) {
    try {
        $pdo = getConnection();
        
        // Verificar se usuário tem pelo menos um álbum
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM albums WHERE user_id = ?");
        $stmt->execute([$userId]);
        $albumCount = $stmt->fetchColumn();
        
        if ($albumCount == 0) {
            // Criar categoria padrão se não existir
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = 'Geral'");
            $stmt->execute([$userId]);
            $categoryId = $stmt->fetchColumn();
            
            if (!$categoryId) {
                $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, description, color) VALUES (?, 'Geral', 'Categoria padrão', '#667eea')");
                $stmt->execute([$userId]);
                $categoryId = $pdo->lastInsertId();
            }
            
            // Criar álbum padrão
            $stmt = $pdo->prepare("INSERT INTO albums (user_id, category_id, name, description) VALUES (?, ?, 'Meu Álbum', 'Álbum principal')");
            $stmt->execute([$userId, $categoryId]);
            return $pdo->lastInsertId();
        }
        
        // Retornar o primeiro álbum do usuário
        $stmt = $pdo->prepare("SELECT id FROM albums WHERE user_id = ? ORDER BY created_at ASC LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn();
        
    } catch (PDOException $e) {
        error_log("Erro ao garantir álbum do usuário: " . $e->getMessage());
        return null;
    }
}

// Função para validar se álbum pertence ao usuário
function validateUserAlbum($albumId, $userId) {
    if (!$albumId) return false;
    
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT id FROM albums WHERE id = ? AND user_id = ?");
        $stmt->execute([$albumId, $userId]);
        return $stmt->fetchColumn() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

// Função para gerar token único
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

// Função para validar token da API
function validateApiToken($token) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("
            SELECT t.*, u.username 
            FROM api_tokens t 
            JOIN users u ON t.user_id = u.id 
            WHERE t.token = ? AND (t.expires_at IS NULL OR t.expires_at > NOW())
        ");
        $stmt->execute([$token]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tokenData) {
            // Atualizar último uso
            $updateStmt = $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?");
            $updateStmt->execute([$tokenData['id']]);
        }
        
        return $tokenData;
    } catch (PDOException $e) {
        return false;
    }
}

// Função para redimensionar imagem
function resizeImage($source, $destination, $maxWidth = 800, $maxHeight = 600, $quality = 85) {
    $imageInfo = getimagesize($source);
    if (!$imageInfo) return false;
    
    $originalWidth = $imageInfo[0];
    $originalHeight = $imageInfo[1];
    $imageType = $imageInfo[2];
    
    // Calcular novas dimensões mantendo proporção
    $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
    $newWidth = round($originalWidth * $ratio);
    $newHeight = round($originalHeight * $ratio);
    
    // Criar imagem a partir do arquivo
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $sourceImage = imagecreatefromjpeg($source);
            break;
        case IMAGETYPE_PNG:
            $sourceImage = imagecreatefrompng($source);
            break;
        case IMAGETYPE_GIF:
            $sourceImage = imagecreatefromgif($source);
            break;
        default:
            return false;
    }
    
    // Criar nova imagem
    $newImage = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preservar transparência para PNG e GIF
    if ($imageType == IMAGETYPE_PNG || $imageType == IMAGETYPE_GIF) {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
        imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    // Redimensionar
    imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
    
    // Salvar imagem
    $result = false;
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $result = imagejpeg($newImage, $destination, $quality);
            break;
        case IMAGETYPE_PNG:
            $result = imagepng($newImage, $destination, 9);
            break;
        case IMAGETYPE_GIF:
            $result = imagegif($newImage, $destination);
            break;
    }
    
    // Limpar memória
    imagedestroy($sourceImage);
    imagedestroy($newImage);
    
    return $result;
}
?>