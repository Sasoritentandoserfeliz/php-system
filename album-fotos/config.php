<?php
// Configurações do banco de dados
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'photo_album');

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
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        die("Erro na conexão: " . $e->getMessage());
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