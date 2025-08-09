/*
  # Sistema de Álbum de Fotos - Banco de Dados MySQL

  1. Novas Tabelas
    - `categories` - Categorias para organizar álbuns
    - `albums` - Álbuns organizados por categorias
    - `shared_photos` - Sistema de compartilhamento de fotos
    - `api_tokens` - Tokens para API REST
    - `backups` - Log de backups automáticos

  2. Modificações nas Tabelas Existentes
    - `photos` - Adicionado album_id, edited_at, backup_path
    - `users` - Adicionado api_enabled, backup_enabled

  3. Segurança
    - Índices para melhor performance
    - Constraints de integridade referencial
*/

-- Criar banco se não existir
CREATE DATABASE IF NOT EXISTS photo_album CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE photo_album;

-- Tabela de usuários
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    api_enabled BOOLEAN DEFAULT FALSE,
    backup_enabled BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de categorias
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    color VARCHAR(7) DEFAULT '#667eea',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_category (user_id, name),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de álbuns
CREATE TABLE IF NOT EXISTS albums (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    cover_photo_id INT,
    is_public BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    UNIQUE KEY unique_user_album (user_id, name),
    INDEX idx_user_id (user_id),
    INDEX idx_category_id (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de fotos
CREATE TABLE IF NOT EXISTS photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    album_id INT,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    width INT,
    height INT,
    edited_at TIMESTAMP NULL,
    backup_path VARCHAR(500),
    upload_datetime TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_album_id (album_id),
    INDEX idx_upload_datetime (upload_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de fotos compartilhadas
CREATE TABLE IF NOT EXISTS shared_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    photo_id INT NOT NULL,
    shared_by INT NOT NULL,
    shared_with INT,
    share_token VARCHAR(64) UNIQUE,
    is_public BOOLEAN DEFAULT FALSE,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_with) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_photo_id (photo_id),
    INDEX idx_shared_by (shared_by),
    INDEX idx_share_token (share_token),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de tokens da API
CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    permissions JSON,
    expires_at TIMESTAMP NULL,
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_token (token),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de backups
CREATE TABLE IF NOT EXISTS backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    backup_type ENUM('manual', 'automatic') DEFAULT 'automatic',
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT,
    photos_count INT DEFAULT 0,
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir categoria padrão para usuários existentes (se houver)
INSERT IGNORE INTO categories (user_id, name, description, color)
SELECT id, 'Geral', 'Categoria padrão', '#667eea' FROM users;

-- Inserir álbum padrão para usuários existentes (se houver)
INSERT IGNORE INTO albums (user_id, category_id, name, description)
SELECT u.id, c.id, 'Meu Álbum', 'Álbum principal'
FROM users u
JOIN categories c ON c.user_id = u.id AND c.name = 'Geral';

-- Atualizar fotos existentes para o álbum padrão (se houver)
UPDATE photos p
JOIN users u ON p.user_id = u.id
JOIN albums a ON a.user_id = u.id AND a.name = 'Meu Álbum'
SET p.album_id = a.id
WHERE p.album_id IS NULL;

-- Atualizar cover_photo_id dos álbuns (se houver fotos)
UPDATE albums a
SET cover_photo_id = (
    SELECT p.id FROM photos p 
    WHERE p.album_id = a.id 
    ORDER BY p.upload_datetime DESC 
    LIMIT 1
)
WHERE cover_photo_id IS NULL;