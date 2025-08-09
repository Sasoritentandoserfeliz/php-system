<?php
/**
 * Script de tarefas automáticas (CRON)
 * 
 * Este script deve ser executado periodicamente pelo servidor
 * para realizar tarefas de manutenção automática.
 * 
 * Configurar no crontab:
 * 0 2 * * 0 /usr/bin/php /caminho/para/album-fotos/cron.php
 * (Executa todo domingo às 2h da manhã)
 */

require_once 'config.php';

// Log de execução
$logFile = __DIR__ . '/cron.log';
$startTime = microtime(true);

function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

logMessage("=== Iniciando tarefas automáticas ===");

try {
    $pdo = getConnection();
    
    // 1. Criar backups automáticos para usuários que habilitaram
    logMessage("Iniciando backups automáticos...");
    
    $stmt = $pdo->prepare("
        SELECT id, username FROM users 
        WHERE backup_enabled = 1 
        AND (
            SELECT COUNT(*) FROM backups 
            WHERE user_id = users.id 
            AND backup_type = 'automatic' 
            AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ) = 0
    ");
    $stmt->execute();
    $usersForBackup = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($usersForBackup as $user) {
        try {
            logMessage("Criando backup para usuário: {$user['username']} (ID: {$user['id']})");
            
            // Buscar fotos do usuário
            $stmt = $pdo->prepare("SELECT * FROM photos WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($photos)) {
                logMessage("Usuário {$user['username']} não tem fotos para backup");
                continue;
            }
            
            // Criar diretório de backup
            $backupDir = createUserBackupDir($user['id']);
            $timestamp = date('Y-m-d_H-i-s');
            $backupFileName = "backup_auto_{$user['id']}_{$timestamp}.zip";
            $backupPath = $backupDir . $backupFileName;
            
            // Criar entrada no banco
            $stmt = $pdo->prepare("
                INSERT INTO backups (user_id, backup_type, file_path, status) 
                VALUES (?, 'automatic', ?, 'pending')
            ");
            $stmt->execute([$user['id'], $backupPath]);
            $backupId = $pdo->lastInsertId();
            
            // Criar arquivo ZIP
            $zip = new ZipArchive();
            if ($zip->open($backupPath, ZipArchive::CREATE) === TRUE) {
                $totalSize = 0;
                $photoCount = 0;
                
                foreach ($photos as $photo) {
                    $photoPath = UPLOAD_DIR . $user['id'] . '/' . $photo['filename'];
                    if (file_exists($photoPath)) {
                        $zip->addFile($photoPath, 'photos/' . $photo['original_name']);
                        $totalSize += $photo['file_size'];
                        $photoCount++;
                    }
                }
                
                // Adicionar metadados
                $metadata = [
                    'backup_date' => date('Y-m-d H:i:s'),
                    'backup_type' => 'automatic',
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'photos_count' => $photoCount,
                    'total_size' => $totalSize,
                    'photos' => $photos
                ];
                
                $zip->addFromString('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));
                $zip->close();
                
                // Atualizar banco
                $backupFileSize = filesize($backupPath);
                $stmt = $pdo->prepare("
                    UPDATE backups 
                    SET status = 'completed', file_size = ?, photos_count = ?, completed_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$backupFileSize, $photoCount, $backupId]);
                
                logMessage("Backup criado com sucesso para {$user['username']}: {$photoCount} fotos, " . formatFileSize($backupFileSize));
            } else {
                // Falha ao criar ZIP
                $stmt = $pdo->prepare("UPDATE backups SET status = 'failed' WHERE id = ?");
                $stmt->execute([$backupId]);
                logMessage("ERRO: Falha ao criar arquivo ZIP para {$user['username']}");
            }
            
        } catch (Exception $e) {
            logMessage("ERRO no backup do usuário {$user['username']}: " . $e->getMessage());
        }
    }
    
    // 2. Limpar backups antigos
    logMessage("Limpando backups antigos...");
    
    $stmt = $pdo->prepare("
        SELECT * FROM backups 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        AND status = 'completed'
    ");
    $stmt->execute([BACKUP_RETENTION_DAYS]);
    $oldBackups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($oldBackups as $backup) {
        try {
            // Remover arquivo
            if (file_exists($backup['file_path'])) {
                unlink($backup['file_path']);
                logMessage("Arquivo removido: {$backup['file_path']}");
            }
            
            // Remover do banco
            $stmt = $pdo->prepare("DELETE FROM backups WHERE id = ?");
            $stmt->execute([$backup['id']]);
            
        } catch (Exception $e) {
            logMessage("ERRO ao remover backup antigo ID {$backup['id']}: " . $e->getMessage());
        }
    }
    
    logMessage("Removidos " . count($oldBackups) . " backups antigos");
    
    // 3. Limpar tokens de compartilhamento expirados
    logMessage("Limpando compartilhamentos expirados...");
    
    $stmt = $pdo->prepare("DELETE FROM shared_photos WHERE expires_at IS NOT NULL AND expires_at < NOW()");
    $stmt->execute();
    $expiredShares = $stmt->rowCount();
    
    logMessage("Removidos {$expiredShares} compartilhamentos expirados");
    
    // 4. Limpar tokens de API expirados
    logMessage("Limpando tokens de API expirados...");
    
    $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE expires_at IS NOT NULL AND expires_at < NOW()");
    $stmt->execute();
    $expiredTokens = $stmt->rowCount();
    
    logMessage("Removidos {$expiredTokens} tokens de API expirados");
    
    // 5. Otimizar tabelas do banco
    logMessage("Otimizando tabelas do banco de dados...");
    
    $tables = ['users', 'photos', 'albums', 'categories', 'shared_photos', 'api_tokens', 'backups'];
    foreach ($tables as $table) {
        try {
            $pdo->exec("OPTIMIZE TABLE {$table}");
            logMessage("Tabela {$table} otimizada");
        } catch (Exception $e) {
            logMessage("ERRO ao otimizar tabela {$table}: " . $e->getMessage());
        }
    }
    
} catch (Exception $e) {
    logMessage("ERRO CRÍTICO: " . $e->getMessage());
}

$endTime = microtime(true);
$executionTime = round($endTime - $startTime, 2);

logMessage("=== Tarefas concluídas em {$executionTime} segundos ===\n");

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>