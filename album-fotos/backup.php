<?php
require_once 'config.php';
requireLogin();

$message = '';
$messageType = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_backup':
                $result = handleCreateBackup($_POST['backup_type'] ?? 'manual');
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            
            case 'download_backup':
                handleDownloadBackup($_POST['backup_id']);
                break;
            
            case 'delete_backup':
                $result = handleDeleteBackup($_POST['backup_id']);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            
            case 'toggle_auto_backup':
                $result = handleToggleAutoBackup($_POST['backup_enabled']);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
        }
    }
}

function handleCreateBackup($backupType) {
    try {
        $pdo = getConnection();
        
        // Criar diretório de backup
        $backupDir = createUserBackupDir($_SESSION['user_id']);
        $timestamp = date('Y-m-d_H-i-s');
        $backupFileName = "backup_{$_SESSION['user_id']}_{$timestamp}.zip";
        $backupPath = $backupDir . $backupFileName;
        
        // Criar entrada no banco
        $stmt = $pdo->prepare("
            INSERT INTO backups (user_id, backup_type, file_path, status) 
            VALUES (?, ?, ?, 'pending')
        ");
        $stmt->execute([$_SESSION['user_id'], $backupType, $backupPath]);
        $backupId = $pdo->lastInsertId();
        
        // Buscar fotos do usuário
        $stmt = $pdo->prepare("SELECT * FROM photos WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($photos)) {
            $stmt = $pdo->prepare("UPDATE backups SET status = 'failed' WHERE id = ?");
            $stmt->execute([$backupId]);
            return ['message' => 'Nenhuma foto para fazer backup.', 'type' => 'error'];
        }
        
        // Criar arquivo ZIP
        $zip = new ZipArchive();
        if ($zip->open($backupPath, ZipArchive::CREATE) !== TRUE) {
            $stmt = $pdo->prepare("UPDATE backups SET status = 'failed' WHERE id = ?");
            $stmt->execute([$backupId]);
            return ['message' => 'Erro ao criar arquivo de backup.', 'type' => 'error'];
        }
        
        $totalSize = 0;
        $photoCount = 0;
        
        foreach ($photos as $photo) {
            $photoPath = UPLOAD_DIR . $_SESSION['user_id'] . '/' . $photo['filename'];
            if (file_exists($photoPath)) {
                $zip->addFile($photoPath, 'photos/' . $photo['original_name']);
                $totalSize += $photo['file_size'];
                $photoCount++;
            }
        }
        
        // Adicionar metadados
        $metadata = [
            'backup_date' => date('Y-m-d H:i:s'),
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
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
        
        return ['message' => "Backup criado com sucesso! {$photoCount} fotos incluídas.", 'type' => 'success'];
        
    } catch (Exception $e) {
        return ['message' => 'Erro ao criar backup: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function handleDownloadBackup($backupId) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT * FROM backups WHERE id = ? AND user_id = ? AND status = 'completed'");
        $stmt->execute([$backupId, $_SESSION['user_id']]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$backup || !file_exists($backup['file_path'])) {
            header('Location: backup.php?error=backup_not_found');
            exit();
        }
        
        $fileName = basename($backup['file_path']);
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($backup['file_path']));
        
        readfile($backup['file_path']);
        exit();
        
    } catch (Exception $e) {
        header('Location: backup.php?error=download_failed');
        exit();
    }
}

function handleDeleteBackup($backupId) {
    try {
        $pdo = getConnection();
        
        // Buscar backup
        $stmt = $pdo->prepare("SELECT * FROM backups WHERE id = ? AND user_id = ?");
        $stmt->execute([$backupId, $_SESSION['user_id']]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$backup) {
            return ['message' => 'Backup não encontrado.', 'type' => 'error'];
        }
        
        // Remover arquivo
        if (file_exists($backup['file_path'])) {
            unlink($backup['file_path']);
        }
        
        // Remover do banco
        $stmt = $pdo->prepare("DELETE FROM backups WHERE id = ? AND user_id = ?");
        $stmt->execute([$backupId, $_SESSION['user_id']]);
        
        return ['message' => 'Backup removido com sucesso!', 'type' => 'success'];
    } catch (Exception $e) {
        return ['message' => 'Erro ao remover backup: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function handleToggleAutoBackup($enabled) {
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare("UPDATE users SET backup_enabled = ? WHERE id = ?");
        $stmt->execute([$enabled ? 1 : 0, $_SESSION['user_id']]);
        
        $message = $enabled ? 'Backup automático habilitado!' : 'Backup automático desabilitado!';
        return ['message' => $message, 'type' => 'success'];
    } catch (PDOException $e) {
        return ['message' => 'Erro ao atualizar configuração: ' . $e->getMessage(), 'type' => 'error'];
    }
}

// Buscar dados
try {
    $pdo = getConnection();
    
    // Buscar configuração do usuário
    $stmt = $pdo->prepare("SELECT backup_enabled FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userConfig = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Buscar backups existentes
    $stmt = $pdo->prepare("
        SELECT * FROM backups 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $backups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estatísticas
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_photos, SUM(file_size) as total_size FROM photos WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $message = 'Erro ao carregar dados: ' . $e->getMessage();
    $messageType = 'error';
    $userConfig = ['backup_enabled' => false];
    $backups = [];
    $stats = ['total_photos' => 0, 'total_size' => 0];
}

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

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup - <?php echo htmlspecialchars($_SESSION['username']); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="logo">💾 Backup</div>
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

        <!-- Estatísticas -->
        <div class="card">
            <h3>Estatísticas</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 2rem;">
                <div style="text-align: center;">
                    <h2 style="color: #667eea; margin-bottom: 0.5rem;"><?php echo $stats['total_photos']; ?></h2>
                    <p>Fotos Total</p>
                </div>
                <div style="text-align: center;">
                    <h2 style="color: #667eea; margin-bottom: 0.5rem;"><?php echo formatFileSize($stats['total_size']); ?></h2>
                    <p>Espaço Usado</p>
                </div>
                <div style="text-align: center;">
                    <h2 style="color: #667eea; margin-bottom: 0.5rem;"><?php echo count($backups); ?></h2>
                    <p>Backups Criados</p>
                </div>
            </div>
        </div>

        <!-- Configurações -->
        <div class="card">
            <h3>Configurações de Backup</h3>
            
            <form method="POST">
                <input type="hidden" name="action" value="toggle_auto_backup">
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="backup_enabled" value="1" 
                               <?php echo $userConfig['backup_enabled'] ? 'checked' : ''; ?>
                               onchange="this.form.submit()">
                        Habilitar backup automático semanal
                    </label>
                    <p style="color: #666; font-size: 0.9rem; margin-top: 0.5rem;">
                        Cria automaticamente um backup de todas as suas fotos toda semana.
                    </p>
                </div>
            </form>
        </div>

        <!-- Criar backup -->
        <div class="card">
            <h3>Criar Novo Backup</h3>
            
            <p style="margin-bottom: 1.5rem; color: #666;">
                Crie um backup completo de todas as suas fotos. O arquivo ZIP incluirá todas as imagens e metadados.
            </p>
            
            <form method="POST">
                <input type="hidden" name="action" value="create_backup">
                <input type="hidden" name="backup_type" value="manual">
                
                <button type="submit" class="btn" onclick="this.disabled=true; this.textContent='Criando backup...'; this.form.submit();">
                    📦 Criar Backup Agora
                </button>
            </form>
        </div>

        <!-- Lista de backups -->
        <div class="card">
            <h3>Meus Backups (<?php echo count($backups); ?>)</h3>
            
            <?php if (empty($backups)): ?>
                <div class="empty-state">
                    <h3>Nenhum backup ainda</h3>
                    <p>Crie seu primeiro backup usando o botão acima!</p>
                </div>
            <?php else: ?>
                <div class="backups-list">
                    <?php foreach ($backups as $backup): ?>
                        <div class="backup-item" style="background: #f8f9fa; padding: 1.5rem; border-radius: 10px; margin-bottom: 1rem;">
                            <div style="display: flex; justify-content: between; align-items: center; gap: 1rem;">
                                <div style="flex: 1;">
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem;">
                                        <div>
                                            <strong>Tipo:</strong><br>
                                            <?php echo $backup['backup_type'] === 'automatic' ? '🤖 Automático' : '👤 Manual'; ?>
                                        </div>
                                        <div>
                                            <strong>Status:</strong><br>
                                            <?php 
                                            switch ($backup['status']) {
                                                case 'completed':
                                                    echo '✅ Concluído';
                                                    break;
                                                case 'pending':
                                                    echo '⏳ Processando';
                                                    break;
                                                case 'failed':
                                                    echo '❌ Falhou';
                                                    break;
                                            }
                                            ?>
                                        </div>
                                        <div>
                                            <strong>Criado:</strong><br>
                                            <?php echo date('d/m/Y H:i', strtotime($backup['created_at'])); ?>
                                        </div>
                                        <?php if ($backup['status'] === 'completed'): ?>
                                            <div>
                                                <strong>Fotos:</strong><br>
                                                <?php echo $backup['photos_count']; ?> fotos
                                            </div>
                                            <div>
                                                <strong>Tamanho:</strong><br>
                                                <?php echo formatFileSize($backup['file_size']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div style="display: flex; gap: 0.5rem;">
                                    <?php if ($backup['status'] === 'completed'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="download_backup">
                                            <input type="hidden" name="backup_id" value="<?php echo $backup['id']; ?>">
                                            <button type="submit" class="btn btn-small">
                                                📥 Download
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_backup">
                                        <input type="hidden" name="backup_id" value="<?php echo $backup['id']; ?>">
                                        <button type="submit" class="btn btn-small btn-danger" 
                                                onclick="return confirm('Remover este backup?')">
                                            🗑️ Remover
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Informações sobre backup -->
        <div class="card">
            <h3>ℹ️ Sobre o Backup</h3>
            
            <div style="color: #666; line-height: 1.6;">
                <p><strong>O que é incluído no backup:</strong></p>
                <ul style="margin-left: 2rem; margin-bottom: 1rem;">
                    <li>Todas as suas fotos em qualidade original</li>
                    <li>Metadados das fotos (nome, data, tamanho, etc.)</li>
                    <li>Informações dos álbuns e categorias</li>
                    <li>Arquivo JSON com todos os dados estruturados</li>
                </ul>
                
                <p><strong>Retenção de backups:</strong></p>
                <p>Backups são mantidos por <?php echo BACKUP_RETENTION_DAYS; ?> dias e depois removidos automaticamente para economizar espaço.</p>
                
                <p><strong>Backup automático:</strong></p>
                <p>Quando habilitado, um backup completo é criado automaticamente toda semana às 2h da manhã.</p>
            </div>
        </div>
    </div>
</body>
</html>