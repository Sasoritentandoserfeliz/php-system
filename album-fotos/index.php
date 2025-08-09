<?php
require_once 'config.php';

// Se estiver logado, redirecionar para o álbum
if (isLoggedIn()) {
    header('Location: album.php');
    exit();
}

// Caso contrário, redirecionar para login
header('Location: login.php');
exit();
?>