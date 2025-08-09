<?php
/**
 * Script para corrigir usuários existentes sem álbuns
 * Execute este script uma vez para corrigir dados existentes
 */

require_once 'config.php';

echo "<h2>Corrigindo usuários existentes...</h2>\n";

try {
    $pdo = getConnection();
    
    // Buscar usuários sem categoria
    $stmt = $pdo->query("
        SELECT u.id, u.username 
        FROM users u 
        LEFT JOIN categories c ON u.id = c.user_id 
        WHERE c.id IS NULL
    ");
    $usersWithoutCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Usuários sem categoria: " . count($usersWithoutCategory) . "</p>\n";
    
    foreach ($usersWithoutCategory as $user) {
        echo "<p>Criando categoria para usuário: {$user['username']}</p>\n";
        
        $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, description, color) VALUES (?, 'Geral', 'Categoria padrão', '#667eea')");
        $stmt->execute([$user['id']]);
    }
    
    // Buscar usuários sem álbum
    $stmt = $pdo->query("
        SELECT u.id, u.username 
        FROM users u 
        LEFT JOIN albums a ON u.id = a.user_id 
        WHERE a.id IS NULL
    ");
    $usersWithoutAlbum = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Usuários sem álbum: " . count($usersWithoutAlbum) . "</p>\n";
    
    foreach ($usersWithoutAlbum as $user) {
        echo "<p>Criando álbum para usuário: {$user['username']}</p>\n";
        
        // Buscar categoria do usuário
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user['id']]);
        $categoryId = $stmt->fetchColumn();
        
        if ($categoryId) {
            $stmt = $pdo->prepare("INSERT INTO albums (user_id, category_id, name, description) VALUES (?, ?, 'Meu Álbum', 'Álbum principal')");
            $stmt->execute([$user['id'], $categoryId]);
        }
    }
    
    // Corrigir fotos sem álbum
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM photos 
        WHERE album_id IS NULL
    ");
    $photosWithoutAlbum = $stmt->fetchColumn();
    
    echo "<p>Fotos sem álbum: {$photosWithoutAlbum}</p>\n";
    
    if ($photosWithoutAlbum > 0) {
        $stmt = $pdo->query("
            UPDATE photos p
            JOIN users u ON p.user_id = u.id
            JOIN albums a ON a.user_id = u.id
            SET p.album_id = a.id
            WHERE p.album_id IS NULL
            AND a.id = (SELECT MIN(id) FROM albums WHERE user_id = u.id)
        ");
        
        echo "<p>Fotos corrigidas: " . $stmt->rowCount() . "</p>\n";
    }
    
    echo "<h3 style='color: green;'>✅ Correção concluída com sucesso!</h3>\n";
    
} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ Erro: " . $e->getMessage() . "</h3>\n";
}
?>
```