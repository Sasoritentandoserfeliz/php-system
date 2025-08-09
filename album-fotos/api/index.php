<?php
require_once '../config.php';

// Headers para API REST
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Função para resposta JSON
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit();
}

// Função para erro JSON
function jsonError($message, $status = 400) {
    jsonResponse(['error' => $message], $status);
}

// Autenticação
$token = null;
$authHeader = '';

// Tentar diferentes formas de obter o header de autorização
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (isset($_SERVER['HTTP_X_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_X_AUTHORIZATION'];
} elseif (function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
    }
}

if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    $token = $matches[1];
}

if (!$token) {
    jsonError('Token de autorização necessário', 401);
}

$tokenData = validateApiToken($token);
if (!$tokenData) {
    jsonError('Token inválido ou expirado', 401);
}

$userId = $tokenData['user_id'];
$permissions = json_decode($tokenData['permissions'], true) ?: [];

// Roteamento
$requestUri = $_SERVER['REQUEST_URI'];
$basePath = '/album-fotos/api';
$path = str_replace($basePath, '', parse_url($requestUri, PHP_URL_PATH));
$method = $_SERVER['REQUEST_METHOD'];

// Remover barra inicial
$path = ltrim($path, '/');
$pathParts = explode('/', $path);

try {
    // Verificar conexão com banco
    $pdo = getConnection();
    
    switch ($pathParts[0]) {
        case 'photos':
            handlePhotosEndpoint($method, $pathParts, $userId, $permissions);
            break;
            
        case 'albums':
            handleAlbumsEndpoint($method, $pathParts, $userId, $permissions);
            break;
            
        case 'categories':
            handleCategoriesEndpoint($method, $pathParts, $userId, $permissions);
            break;
            
        default:
            jsonError('Endpoint não encontrado', 404);
    }
} catch (Exception $e) {
    error_log("Erro na API: " . $e->getMessage());
    jsonError('Erro interno: ' . $e->getMessage(), 500);
}

function handlePhotosEndpoint($method, $pathParts, $userId, $permissions) {
    $pdo = getConnection();
    
    switch ($method) {
        case 'GET':
            if (!in_array('read', $permissions)) {
                jsonError('Permissão insuficiente', 403);
            }
            
            if (isset($pathParts[1])) {
                // GET /photos/{id}
                $photoId = $pathParts[1];
                $stmt = $pdo->prepare("
                    SELECT p.*, a.name as album_name 
                    FROM photos p 
                    LEFT JOIN albums a ON p.album_id = a.id 
                    WHERE p.id = ? AND p.user_id = ?
                ");
                $stmt->execute([$photoId, $userId]);
                $photo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$photo) {
                    jsonError('Foto não encontrada', 404);
                }
                
                $photo['url'] = $_SERVER['HTTP_HOST'] . '/album-fotos/uploads/' . $userId . '/' . $photo['filename'];
                jsonResponse($photo);
            } else {
                // GET /photos
                $stmt = $pdo->prepare("
                    SELECT p.*, a.name as album_name 
                    FROM photos p 
                    LEFT JOIN albums a ON p.album_id = a.id 
                    WHERE p.user_id = ? 
                    ORDER BY p.upload_datetime DESC
                ");
                $stmt->execute([$userId]);
                $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($photos as &$photo) {
                    $photo['url'] = $_SERVER['HTTP_HOST'] . '/album-fotos/uploads/' . $userId . '/' . $photo['filename'];
                }
                
                jsonResponse(['photos' => $photos, 'count' => count($photos)]);
            }
            break;
            
        case 'POST':
            if (!in_array('upload', $permissions)) {
                jsonError('Permissão insuficiente', 403);
            }
            
            if (!isset($_FILES['photo'])) {
                jsonError('Arquivo de foto necessário');
            }
            
            $file = $_FILES['photo'];
            $albumId = $_POST['album_id'] ?? null;
            
            // Garantir que o usuário tenha pelo menos um álbum
            if (!$albumId) {
                $albumId = ensureUserHasAlbum($userId);
                if (!$albumId) {
                    jsonError('Erro ao criar álbum padrão');
                }
            } else {
                // Validar se o álbum pertence ao usuário
                if (!validateUserAlbum($albumId, $userId)) {
                    $albumId = ensureUserHasAlbum($userId);
                    if (!$albumId) {
                        jsonError('Álbum inválido e erro ao criar álbum padrão');
                    }
                }
            }
            
            // Validações
            if ($file['error'] !== UPLOAD_ERR_OK) {
                jsonError('Erro no upload da imagem');
            }
            
            if ($file['size'] > MAX_FILE_SIZE) {
                jsonError('Arquivo muito grande. Máximo: ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB');
            }
            
            $fileInfo = pathinfo($file['name']);
            $extension = strtolower($fileInfo['extension']);
            
            if (!in_array($extension, ALLOWED_TYPES)) {
                jsonError('Tipo de arquivo não permitido. Use: ' . implode(', ', ALLOWED_TYPES));
            }
            
            // Upload
            $userDir = createUserUploadDir($userId);
            $filename = uniqid() . '_' . time() . '.' . $extension;
            $filepath = $userDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $imageInfo = getimagesize($filepath);
                $width = $imageInfo[0] ?? null;
                $height = $imageInfo[1] ?? null;
                
                $stmt = $pdo->prepare("
                    INSERT INTO photos (user_id, album_id, filename, original_name, file_size, width, height) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $albumId, $filename, $file['name'], $file['size'], $width, $height]);
                
                $photoId = $pdo->lastInsertId();
                
                jsonResponse([
                    'message' => 'Foto enviada com sucesso',
                    'photo_id' => $photoId,
                    'url' => $_SERVER['HTTP_HOST'] . '/album-fotos/uploads/' . $userId . '/' . $filename
                ], 201);
            } else {
                jsonError('Erro ao salvar arquivo');
            }
            break;
            
        case 'DELETE':
            if (!in_array('delete', $permissions)) {
                jsonError('Permissão insuficiente', 403);
            }
            
            if (!isset($pathParts[1])) {
                jsonError('ID da foto necessário');
            }
            
            $photoId = $pathParts[1];
            
            // Buscar foto
            $stmt = $pdo->prepare("SELECT * FROM photos WHERE id = ? AND user_id = ?");
            $stmt->execute([$photoId, $userId]);
            $photo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$photo) {
                jsonError('Foto não encontrada', 404);
            }
            
            // Remover arquivo
            $filepath = UPLOAD_DIR . $userId . '/' . $photo['filename'];
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            
            // Remover do banco
            $stmt = $pdo->prepare("DELETE FROM photos WHERE id = ? AND user_id = ?");
            $stmt->execute([$photoId, $userId]);
            
            jsonResponse(['message' => 'Foto excluída com sucesso']);
            break;
            
        default:
            jsonError('Método não permitido', 405);
    }
}

function handleAlbumsEndpoint($method, $pathParts, $userId, $permissions) {
    $pdo = getConnection();
    
    switch ($method) {
        case 'GET':
            if (!in_array('read', $permissions)) {
                jsonError('Permissão insuficiente', 403);
            }
            
            $stmt = $pdo->prepare("
                SELECT a.*, c.name as category_name, c.color as category_color,
                       COUNT(p.id) as photo_count
                FROM albums a 
                LEFT JOIN categories c ON a.category_id = c.id 
                LEFT JOIN photos p ON a.id = p.album_id 
                WHERE a.user_id = ? 
                GROUP BY a.id 
                ORDER BY c.name, a.name
            ");
            $stmt->execute([$userId]);
            $albums = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(['albums' => $albums, 'count' => count($albums)]);
            break;
            
        case 'POST':
            if (!in_array('albums', $permissions)) {
                jsonError('Permissão insuficiente', 403);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? '';
            $categoryId = $input['category_id'] ?? null;
            $description = $input['description'] ?? '';
            
            if (empty($name)) {
                jsonError('Nome do álbum é obrigatório');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO albums (user_id, category_id, name, description) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $categoryId, $name, $description]);
            
            jsonResponse(['message' => 'Álbum criado com sucesso', 'album_id' => $pdo->lastInsertId()], 201);
            break;
            
        default:
            jsonError('Método não permitido', 405);
    }
}

function handleCategoriesEndpoint($method, $pathParts, $userId, $permissions) {
    $pdo = getConnection();
    
    switch ($method) {
        case 'GET':
            if (!in_array('read', $permissions)) {
                jsonError('Permissão insuficiente', 403);
            }
            
            $stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? ORDER BY name");
            $stmt->execute([$userId]);
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(['categories' => $categories, 'count' => count($categories)]);
            break;
            
        default:
            jsonError('Método não permitido', 405);
    }
}
?>