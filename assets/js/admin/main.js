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
// Pop-up (tooltip) stylisée au survol des tomes
// Fonctionne à l'admin comme dans les modales de détail des séries à l'index.
// On délègue depuis document pour couvrir les tomes injectés dynamiquement,
// et on lit l'attribut data-title (échappé côté serveur / JS).
(function () {
    var tip = null;

    function ensureTip() {
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'volume-tooltip';
            document.body.appendChild(tip);
        }
        return tip;
    }

    function showTip(target) {
        var text = target.getAttribute('data-title');
        if (!text) return;

        var el = ensureTip();
        el.textContent = text;
        el.classList.remove('is-below');
        el.classList.add('is-visible');

        var r = target.getBoundingClientRect();
        var tr = el.getBoundingClientRect();
        var margin = 8;

        // Position horizontale : centrée sur le tome, bornée à la fenêtre
        var left = r.left + r.width / 2 - tr.width / 2;
        left = Math.max(margin, Math.min(left, window.innerWidth - tr.width - margin));

        // Par défaut au-dessus ; bascule en dessous s'il manque de place
        var top = r.top - tr.height - margin;
        if (top < margin) {
            top = r.bottom + margin;
            el.classList.add('is-below');
        }
        el.style.left = left + 'px';
        el.style.top = top + 'px';

        // Flèche alignée sur le centre du tome
        var arrow = r.left + r.width / 2 - left;
        arrow = Math.max(12, Math.min(arrow, tr.width - 12));
        el.style.setProperty('--arrow-left', arrow + 'px');
    }

    function hideTip() {
        if (tip) tip.classList.remove('is-visible');
    }

    document.addEventListener('mouseover', function (e) {
        var target = e.target.closest('.volumes-list li[data-title]');
        if (target) showTip(target);
    });
    document.addEventListener('mouseout', function (e) {
        if (e.target.closest('.volumes-list li[data-title]')) hideTip();
    });
    // On masque aussi au défilement (position fixe => sinon décalage)
    window.addEventListener('scroll', hideTip, true);
})();

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
