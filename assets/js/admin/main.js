// Fonction pour afficher une alerte personnalisée
function showCustomAlert(title, message) {
    const modal = document.getElementById('custom-alert-modal');
    const titleElement = document.getElementById('custom-alert-title');
    const messageElement = document.getElementById('custom-alert-message');
    const okButton = document.getElementById('custom-alert-ok');

    titleElement.textContent = title;
    messageElement.textContent = message;
    modal.classList.add('modal-active');

    return new Promise((resolve) => {
        okButton.onclick = () => {
            modal.classList.remove('modal-active');
            resolve();
        };
    });
}

// Fonction pour afficher une confirmation personnalisée
function showCustomConfirm(title, message) {
    const modal = document.getElementById('custom-confirm-modal');
    const titleElement = document.getElementById('custom-confirm-title');
    const messageElement = document.getElementById('custom-confirm-message');
    const okButton = document.getElementById('custom-confirm-ok');
    const cancelButton = document.getElementById('custom-confirm-cancel');

    titleElement.textContent = title;
    messageElement.textContent = message;
    modal.classList.add('modal-active');

    return new Promise((resolve) => {
        okButton.onclick = () => {
            modal.classList.remove('modal-active');
            resolve(true);
        };
        cancelButton.onclick = () => {
            modal.classList.remove('modal-active');
            resolve(false);
        };
    });
}

// Remplacer les alert/confirm natifs
window.alert = function(message) {
    showCustomAlert('Avertissement', message);
};

window.confirm = function(message) {
    return showCustomConfirm('Confirmation', message);
};

// Afficher un message d'erreur dans une modale
function showErrorModal(message) {
    showCustomAlert('Erreur', message);
}

// Afficher un message de succès dans une modale
function showSuccessModal(message) {
    showCustomAlert('Succès', message);
}

// Bouton "Retour en haut"
window.addEventListener('scroll', function() {
    const backToTop = document.getElementById('back-to-top');
    if (window.pageYOffset > 300) {
        backToTop.style.display = 'block';
    } else {
        backToTop.style.display = 'none';
    }
});
document.getElementById('back-to-top').addEventListener('click', function() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

// Retirer du DOM les toasts (erreur ET succès) une fois affichés.
// L'animation CSS « toastOut … forwards » les laisse à opacity:0 mais
// présents : sur mobile ils recouvraient le bouton hamburger et bloquaient
// les taps. On les supprime donc complètement après l'animation.
(function () {
    var toasts = document.querySelectorAll(
        '#error-message, .error-message, .alert-success, .alert-warning, .success-message'
    );
    toasts.forEach(function (toast) {
        setTimeout(function () {
            if (toast && toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 5200); // après la fin de toastOut (4.5s + 0.4s)
    });
})();

// Gestion du menu mobile
document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuButton = document.getElementById('mobile-menu-button');
    const adminMenu = document.getElementById('admin-menu');

    if (mobileMenuButton && adminMenu) {
        mobileMenuButton.addEventListener('click', function() {
            adminMenu.classList.toggle('active');
        });

        adminMenu.addEventListener('click', function(e) {
            if (e.target === adminMenu) {
                adminMenu.classList.remove('active');
            }
        });
    }
});

// ─────────────────────────────────────────────────────────────
// Blocage du défilement de l'arrière-plan quand une modale est ouverte
// (universel : couvre les modales via .modal-active et via display:flex inline)
(function () {
    function anyModalVisible() {
        return Array.from(document.querySelectorAll('.modal')).some(function (modal) {
            return getComputedStyle(modal).display !== 'none';
        });
    }
    function syncScrollLock() {
        document.body.classList.toggle('modal-open', anyModalVisible());
    }
    var observer = new MutationObserver(syncScrollLock);
    document.addEventListener('DOMContentLoaded', function () {
        observer.observe(document.body, {
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'style'],
            childList: true
        });
        syncScrollLock();
    });
})();
