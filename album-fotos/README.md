# Sistema de Álbum de Fotos - PHP/MySQL

Sistema web completo para gerenciamento de álbuns de fotos pessoais com autenticação de usuários.

## 🚀 Funcionalidades

- ✅ **Cadastro e Login de usuários** com senhas criptografadas
- ✅ **Upload de imagens** com validação de tipo e tamanho
- ✅ **Álbum pessoal** - cada usuário vê apenas suas fotos
- ✅ **Organização automática** em pastas por usuário
- ✅ **Interface responsiva** e moderna
- ✅ **Drag & Drop** para upload de imagens
- ✅ **Preview** das imagens antes do upload
- ✅ **Informações detalhadas** das fotos (data, tamanho, nome original)

## 📋 Requisitos

- **XAMPP** (ou WAMP/LAMP)
- **PHP 7.4+**
- **MySQL 5.7+**
- **Apache** com mod_rewrite habilitado

## 🛠️ Instalação

### 1. Preparar o ambiente
1. Instale o XAMPP
2. Inicie os serviços **Apache** e **MySQL**

### 2. Configurar o projeto
1. Extraia todos os arquivos na pasta `htdocs/album-fotos/` do XAMPP
2. Acesse o phpMyAdmin: `http://localhost/phpmyadmin`
3. Execute o script `database.sql` para criar o banco de dados

### 3. Configurar permissões
```bash
# No terminal, dentro da pasta do projeto:
mkdir uploads
chmod 755 uploads
```

### 4. Acessar o sistema
- Abra o navegador e acesse: `http://localhost/album-fotos/`
- Será redirecionado para a página de login
- Clique em "Cadastrar" para criar sua primeira conta

## 📁 Estrutura do Projeto

```
album-fotos/
├── config.php          # Configurações do banco e sistema
├── database.sql        # Script de criação do banco
├── style.css          # Estilos CSS
├── index.php          # Página inicial (redireciona)
├── register.php       # Cadastro de usuários
├── login.php          # Login de usuários
├── album.php          # Página principal do álbum
├── logout.php         # Logout
├── 404.php           # Página de erro 404
├── .htaccess         # Configurações do Apache
├── uploads/          # Pasta para armazenar imagens
│   ├── 1/           # Pasta do usuário ID 1
│   ├── 2/           # Pasta do usuário ID 2
│   └── ...
└── README.md         # Este arquivo
```

## ⚙️ Configurações

### Banco de Dados
Edite o arquivo `config.php` se necessário:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'photo_album');
```

### Upload de Arquivos
Configurações atuais em `config.php`:
- **Tamanho máximo**: 5MB por arquivo
- **Formatos aceitos**: JPG, JPEG, PNG, GIF
- **Pasta de upload**: `uploads/`

## 🔒 Segurança

- ✅ Senhas criptografadas com `password_hash()`
- ✅ Prepared statements para prevenir SQL Injection
- ✅ Validação de tipos de arquivo
- ✅ Controle de sessão PHP
- ✅ Proteção contra acesso direto a arquivos sensíveis
- ✅ Sanitização de dados de entrada

## 🎨 Interface

- **Design moderno** com gradientes e efeitos visuais
- **Totalmente responsiva** para mobile e desktop
- **Drag & Drop** para facilitar uploads
- **Preview instantâneo** das imagens
- **Animações suaves** e micro-interações
- **Grid adaptativo** para exibição das fotos

## 🐛 Solução de Problemas

### Erro de upload
- Verifique se a pasta `uploads/` existe e tem permissão de escrita
- Confirme se o arquivo não excede 5MB
- Verifique se o formato é suportado (JPG, PNG, GIF)

### Erro de conexão com banco
- Confirme se o MySQL está rodando
- Verifique as credenciais em `config.php`
- Execute o script `database.sql` no phpMyAdmin

### Imagens não aparecem
- Verifique se as pastas dos usuários foram criadas em `uploads/`
- Confirme as permissões das pastas (755)

## 📱 Uso

1. **Cadastro**: Crie uma conta com username, email e senha
2. **Login**: Entre com seu username/email e senha
3. **Upload**: Clique na área de upload ou arraste imagens
4. **Visualização**: Suas fotos aparecerão em um grid organizado
5. **Informações**: Cada foto mostra data, hora, nome original e tamanho

## 📞 Suporte

Para dúvidas ou problemas:
1. Verifique se todos os requisitos estão atendidos
2. Consulte a seção "Solução de Problemas"
3. Verifique os logs de erro do Apache/PHP

---

**Desenvolvido com ❤️ usando PHP, MySQL, HTML, CSS e JavaScript**