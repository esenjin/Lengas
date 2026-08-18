// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/page.js — Socle commun des pages « Outils »
//
// Chaque outil vit désormais sur sa propre page (pages/outils/outil-*.php).
// Ce fichier reste chargé avant le script propre à chaque outil : il fournit
// les helpers partagés (alertes/confirmations, échappement HTML), la
// fermeture des modales et le bouton « Retour en haut ».
//
// Ces helpers sont dupliqués depuis assets/js/admin/main.js à dessein : les
// pages « Outils » sont autonomes et ne chargent pas les scripts de la
// bibliothèque.
// ──────────────────────────────────────────────────────────────────────────

// ── Alertes et confirmations ──────────────────────────────────────────────

function showCustomAlert(title, message) {
    const modal          = document.getElementById('custom-alert-modal');
    const titleElement   = document.getElementById('custom-alert-title');
    const messageElement = document.getElementById('custom-alert-message');
    const okButton       = document.getElementById('custom-alert-ok');

    titleElement.textContent   = title;
    messageElement.textContent = message;
    modal.classList.add('modal-active');

    return new Promise((resolve) => {
        okButton.onclick = () => {
            modal.classList.remove('modal-active');
            resolve();
        };
    });
}

function showCustomConfirm(title, message) {
    const modal          = document.getElementById('custom-confirm-modal');
    const titleElement   = document.getElementById('custom-confirm-title');
    const messageElement = document.getElementById('custom-confirm-message');
    const okButton       = document.getElementById('custom-confirm-ok');
    const cancelButton   = document.getElementById('custom-confirm-cancel');

    titleElement.textContent   = title;
    messageElement.textContent = message;
    modal.classList.add('modal-active');

    return new Promise((resolve) => {
        okButton.onclick     = () => { modal.classList.remove('modal-active'); resolve(true);  };
        cancelButton.onclick = () => { modal.classList.remove('modal-active'); resolve(false); };
    });
}

function showErrorModal(message)   { showCustomAlert('Erreur', message); }
function showSuccessModal(message) { showCustomAlert('Succès', message); }

// ── Registre minimal des modales de la page ───────────────────────────────
// Les scripts des outils s'appuient sur cet objet (comme sur admin.php).
const modals = {
    'coherence-edit': {
        modal:    document.getElementById('coherence-edit-modal'),
        closeBtn: document.getElementById('close-coherence-edit-modal')
    },
    'add-mu-url': {
        modal:    document.getElementById('add-mu-url-modal'),
        closeBtn: document.getElementById('close-add-mu-url-modal')
    },
    'add-babelio-url': {
        modal:    document.getElementById('add-babelio-url-modal'),
        closeBtn: document.getElementById('close-add-babelio-url-modal')
    }
};

// Fermeture d'une modale : si des corrections ont été enregistrées depuis
// l'édition rapide, on relance l'analyse des incohérences.
function closeToolModal(modal) {
    modal.classList.remove('modal-active');

    const isCoherenceModal = (modal.id === 'coherence-edit-modal');
    if (isCoherenceModal && window.coherenceEditDirty) {
        window.coherenceEditDirty = false;
        if (typeof loadCoherences === 'function') loadCoherences();
    }
}

Object.values(modals).forEach(({ closeBtn, modal }) => {
    if (closeBtn && modal) {
        closeBtn.addEventListener('click', () => closeToolModal(modal));
    }
});

// Fermeture en cliquant à l'extérieur
window.addEventListener('click', (e) => {
    Object.values(modals).forEach(({ modal }) => {
        if (modal && e.target === modal) closeToolModal(modal);
    });
    ['custom-confirm-modal', 'custom-alert-modal'].forEach(id => {
        const m = document.getElementById(id);
        if (m && e.target === m) m.classList.remove('modal-active');
    });
});

// Fermeture avec Échap
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.modal.modal-active').forEach(m => closeToolModal(m));
});

// ── Divers ────────────────────────────────────────────────────────────────

function htmlEscape(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// Bouton « Retour en haut »
window.addEventListener('scroll', function () {
    const backToTop = document.getElementById('back-to-top');
    if (backToTop) backToTop.style.display = window.pageYOffset > 300 ? 'block' : 'none';
});
document.getElementById('back-to-top')?.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

// Blocage du défilement de l'arrière-plan quand une modale est ouverte
(function () {
    function anyModalVisible() {
        return Array.from(document.querySelectorAll('.modal')).some(function (modal) {
            return getComputedStyle(modal).display !== 'none';
        });
    }
    function syncScrollLock() {
        document.body.classList.toggle('modal-open', anyModalVisible());
    }
    const observer = new MutationObserver(syncScrollLock);
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
