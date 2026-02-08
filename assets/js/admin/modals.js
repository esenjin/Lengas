// Gestion des modales (ouverture/fermeture)
const modals = {
    'add-series': { modal: document.getElementById('add-series-modal'), closeBtn: document.getElementById('close-add-series-modal') },
    'add-multiple-volumes': { modal: document.getElementById('add-multiple-volumes-modal'), closeBtn: document.getElementById('close-add-multiple-volumes-modal') },
    'edit-volume': { modal: document.getElementById('edit-volume-modal'), closeBtn: document.getElementById('close-edit-volume-modal') },
    'edit-series': { modal: document.getElementById('edit-series-modal'), closeBtn: document.getElementById('close-edit-series-modal') },
    'read': { modal: document.getElementById('read-modal'), closeBtn: document.getElementById('close-read-modal') },
    'edit-read': { modal: document.getElementById('edit-read-modal'), closeBtn: document.getElementById('close-edit-read-modal') },
    'wishlist': { modal: document.getElementById('wishlist-modal'), closeBtn: document.getElementById('close-wishlist-modal') },
    'edit-wishlist': { modal: document.getElementById('edit-wishlist-modal'), closeBtn: document.getElementById('close-edit-wishlist-modal') },
    'tools': { modal: document.getElementById('tools-modal'), closeBtn: document.getElementById('close-tools-modal') },
    'options': { modal: document.getElementById('options-modal'), closeBtn: document.getElementById('close-options-modal') },
    'current-series': { modal: document.getElementById('current-series-modal'), closeBtn: document.getElementById('close-current-series-modal')},
    'incomplete-series': { modal: document.getElementById('incomplete-series-modal'), closeBtn: document.getElementById('close-incomplete-series-modal') },
    'loan': { modal: document.getElementById('loan-modal'), closeBtn: document.getElementById('close-loan-modal') }
};

// Ouverture des modales
document.getElementById('open-add-series-modal').addEventListener('click', () => modals['add-series'].modal.classList.add('modal-active'));
document.getElementById('open-add-multiple-volumes-modal').addEventListener('click', () => {
    modals['add-multiple-volumes'].modal.classList.add('modal-active');
    document.getElementById('multiple-series-results').style.display = 'block';
});
document.getElementById('open-wishlist-modal').addEventListener('click', () => modals['wishlist'].modal.classList.add('modal-active'));
document.getElementById('open-options-modal').addEventListener('click', () => modals['options'].modal.classList.add('modal-active'));
document.getElementById('open-incomplete-series-modal').addEventListener('click', () => modals['incomplete-series'].modal.classList.add('modal-active'));
document.getElementById('open-current-series-modal').addEventListener('click', () => {
    fetch_current_series();
    modals['current-series'].modal.classList.add('modal-active');
});

// Fermeture de la modale de la liste d'envies
document.getElementById('close-edit-wishlist-modal').addEventListener('click', () => {
    document.getElementById('edit-wishlist-modal').classList.remove('modal-active');
});

// Fonction pour fermer une modale et recharger la page si c'est la modale d'outils ou d'options
function closeModalAndReloadIfTools(modal) {
    modal.classList.remove('modal-active');
    if (modal.id === 'tools-modal' || modal.id === 'options-modal') {
        window.location.reload();
    }
}

// Fermeture des modales via la croix
Object.values(modals).forEach(({ closeBtn, modal }) => {
    if (closeBtn && modal) {
        closeBtn.addEventListener('click', () => {
            closeModalAndReloadIfTools(modal);
        });
    }
});

// Fermeture des modales en cliquant à l'extérieur
window.addEventListener('click', (e) => {
    Object.values(modals).forEach(({ modal }) => {
        if (e.target === modal) {
            closeModalAndReloadIfTools(modal);
        }
    });
});