@@ .. @@
 -- Inserir categoria padrão para usuários existentes (se houver)
-INSERT IGNORE INTO categories (user_id, name, description, color)
-SELECT id, 'Geral', 'Categoria padrão', '#667eea' FROM users;
+INSERT IGNORE INTO categories (user_id, name, description, color)
+SELECT id, 'Geral', 'Categoria padrão', '#667eea' FROM users
+WHERE NOT EXISTS (SELECT 1 FROM categories WHERE user_id = users.id);
 
 -- Inserir álbum padrão para usuários existentes (se houver)
-INSERT IGNORE INTO albums (user_id, category_id, name, description)
-SELECT u.id, c.id, 'Meu Álbum', 'Álbum principal'
-FROM users u
-JOIN categories c ON c.user_id = u.id AND c.name = 'Geral';
+INSERT IGNORE INTO albums (user_id, category_id, name, description)
+SELECT u.id, c.id, 'Meu Álbum', 'Álbum principal'
+FROM users u
+JOIN categories c ON c.user_id = u.id AND c.name = 'Geral'
+WHERE NOT EXISTS (SELECT 1 FROM albums WHERE user_id = u.id);
 
 -- Atualizar fotos existentes para o álbum padrão (se houver)
-UPDATE photos p
-JOIN users u ON p.user_id = u.id
-JOIN albums a ON a.user_id = u.id AND a.name = 'Meu Álbum'
-SET p.album_id = a.id
-WHERE p.album_id IS NULL;
+UPDATE photos p
+JOIN users u ON p.user_id = u.id
+JOIN albums a ON a.user_id = u.id
+SET p.album_id = a.id
+WHERE p.album_id IS NULL
+AND a.id = (SELECT MIN(id) FROM albums WHERE user_id = u.id);