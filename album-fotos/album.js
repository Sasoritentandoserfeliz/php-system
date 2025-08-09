// Funções JavaScript para o álbum

function previewImage(input) {
    const preview = document.getElementById('preview');
    const previewImg = document.getElementById('previewImg');
    const fileName = document.getElementById('fileName');
    const uploadBtn = document.getElementById('uploadBtn');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        // Validar tamanho
        if (file.size > 5242880) { // 5MB
            alert('Arquivo muito grande! Máximo: 5MB');
            input.value = '';
            preview.style.display = 'none';
            uploadBtn.disabled = true;
            return;
        }
        
        // Validar tipo
        const allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
        const fileExtension = file.name.split('.').pop().toLowerCase();
        if (!allowedTypes.includes(fileExtension)) {
            alert('Tipo de arquivo não permitido! Use: ' + allowedTypes.join(', '));
            input.value = '';
            preview.style.display = 'none';
            uploadBtn.disabled = true;
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            fileName.textContent = file.name;
            preview.style.display = 'block';
            uploadBtn.disabled = false;
        };
        reader.readAsDataURL(file);
    }
}

// Drag and drop
const uploadArea = document.querySelector('.upload-area');

uploadArea.addEventListener('dragover', function(e) {
    e.preventDefault();
    uploadArea.classList.add('dragover');
});

uploadArea.addEventListener('dragleave', function(e) {
    e.preventDefault();
    uploadArea.classList.remove('dragover');
});

uploadArea.addEventListener('drop', function(e) {
    e.preventDefault();
    uploadArea.classList.remove('dragover');
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        document.getElementById('photoInput').files = files;
        previewImage(document.getElementById('photoInput'));
    }
});

// Modais
function showCreateCategoryModal() {
    document.getElementById('createCategoryModal').style.display = 'block';
}

function showCreateAlbumModal(categoryId) {
    document.getElementById('albumCategoryId').value = categoryId;
    document.getElementById('createAlbumModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Fechar modal clicando fora
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}

// Ações das fotos
function deletePhoto(photoId) {
    if (confirm('Tem certeza que deseja excluir esta foto?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_photo">
            <input type="hidden" name="photo_id" value="${photoId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function sharePhoto(photoId) {
    window.open(`share.php?photo=${photoId}`, '_blank');
}

function editPhoto(photoId) {
    window.open(`edit.php?photo=${photoId}`, '_blank');
}

function openPhotoModal(photoId) {
    // Implementar modal de visualização da foto
    console.log('Abrir foto:', photoId);
}