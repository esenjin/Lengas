// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/page.js — Socle commun de la page « Outils »
//
// Chargé avant les scripts des outils. Fournit les helpers partagés
// (alertes/confirmations, échappement HTML), la gestion des onglets, la
// fermeture des modales et le bouton « Retour en haut ».
//
// Ces helpers sont dupliqués depuis assets/js/admin/main.js à dessein : la
// page « Outils » est autonome et ne charge pas les scripts de la
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

    if (modal.id === 'coherence-edit-modal' && window.coherenceEditDirty) {
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

// ── Onglets ───────────────────────────────────────────────────────────────
// L'onglet actif est mémorisé dans l'URL (#onglet) afin de survivre à un
// rechargement et de pouvoir être partagé en lien direct.

function activateToolTab(name) {
    const tabs   = document.querySelectorAll('.tools-tab');
    const panels = document.querySelectorAll('.tools-tab-panel');
    if (!tabs.length) return;

    const known = Array.from(tabs).some(t => t.dataset.tab === name);
    if (!known) return;

    tabs.forEach(t => t.classList.toggle('tools-tab--active', t.dataset.tab === name));
    panels.forEach(p => p.classList.toggle('tools-tab-panel--active', p.dataset.tabPanel === name));
}

// Sous-onglets (imbriqués dans un onglet). Le sous-onglet actif est mémorisé
// dans l'URL au format « #onglet:sous-onglet » (ex. #completude:babengas).
function activateToolSubTab(name) {
    const subtabs = document.querySelectorAll('.tools-subtab');
    const panels  = document.querySelectorAll('.tools-subtab-panel');
    if (!subtabs.length) return;

    const known = Array.from(subtabs).some(t => t.dataset.subtab === name);
    if (!known) return;

    subtabs.forEach(t => t.classList.toggle('tools-subtab--active', t.dataset.subtab === name));
    panels.forEach(p => p.classList.toggle('tools-subtab-panel--active', p.dataset.subtabPanel === name));
}

// Reconstruit le hash « #onglet » ou « #onglet:sous-onglet » à partir de
// l'onglet et du sous-onglet actuellement actifs.
function updateToolHash() {
    const tab    = document.querySelector('.tools-tab--active');
    const subtab = document.querySelector('.tools-subtab--active');
    if (!tab) return;
    let hash = '#' + tab.dataset.tab;
    if (subtab && tab.dataset.tab === 'completude') hash += ':' + subtab.dataset.subtab;
    history.replaceState(null, '', hash);
}

document.addEventListener('click', (e) => {
    const subtab = e.target.closest('.tools-subtab');
    if (subtab) {
        activateToolSubTab(subtab.dataset.subtab);
        updateToolHash();
        return;
    }
    const tab = e.target.closest('.tools-tab');
    if (!tab) return;
    activateToolTab(tab.dataset.tab);
    updateToolHash();
});

document.addEventListener('DOMContentLoaded', () => {
    const hash = (window.location.hash || '').replace('#', '');
    if (!hash) return;
    const [tab, subtab] = hash.split(':');
    if (tab) activateToolTab(tab);
    if (subtab) activateToolSubTab(subtab);
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
