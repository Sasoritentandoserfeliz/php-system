<?php
require_once 'config.php';

// Se já estiver logado, redirecionar para o álbum
if (isLoggedIn()) {
    header('Location: album.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login = trim($_POST['login']); // pode ser username ou email
    $password = $_POST['password'];
    
    if (empty($login) || empty($password)) {
        $error = 'Todos os campos são obrigatórios.';
    } else {
        try {
            $pdo = getConnection();
            
            // Buscar usuário por username ou email
            $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$login, $login]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Login bem-sucedido
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                
                // Garantir que o usuário tenha pelo menos um álbum
                ensureUserHasAlbum($user['id']);
                
                header('Location: album.php');
                exit();
            } else {
                $error = 'Usuário ou senha incorretos.';
            }
        } catch (PDOException $e) {
            $error = 'Erro no banco de dados: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Álbum de Fotos</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="logo">📸 Álbum de Fotos</div>
                <div class="nav-links">
                    <a href="register.php">Cadastrar</a>
                </div>
            </div>
        </div>

        <div class="card" style="max-width: 500px; margin: 0 auto;">
            <h2 style="text-align: center; margin-bottom: 2rem; color: #333;">Entrar</h2>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label for="login">Usuário ou Email:</label>
                    <input type="text" id="login" name="login" class="form-control" 
                           value="<?php echo htmlspecialchars($_POST['login'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Senha:</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn" style="width: 100%;">Entrar</button>
            </form>
            
            <p style="text-align: center; margin-top: 1.5rem;">
                Não tem uma conta? <a href="register.php" style="color: #667eea;">Cadastrar</a>
            </p>
        </div>
    </div>
</body>
</html>