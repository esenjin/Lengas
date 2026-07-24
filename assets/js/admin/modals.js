// Gestion des modales (ouverture/fermeture)
const modals = {
    'add-series': { modal: document.getElementById('add-series-modal'), closeBtn: document.getElementById('close-add-series-modal') },
    'add-multiple-volumes': { modal: document.getElementById('add-multiple-volumes-modal'), closeBtn: document.getElementById('close-add-multiple-volumes-modal') },
    'edit-volume': { modal: document.getElementById('edit-volume-modal'), closeBtn: document.getElementById('close-edit-volume-modal') },
    'edit-series': { modal: document.getElementById('edit-series-modal'), closeBtn: document.getElementById('close-edit-series-modal') },
    'read': { modal: document.getElementById('read-modal'), closeBtn: document.getElementById('close-read-modal') },
    'edit-read': { modal: document.getElementById('edit-read-modal'), closeBtn: document.getElementById('close-edit-read-modal') },
    'options': { modal: document.getElementById('options-modal'), closeBtn: document.getElementById('close-options-modal') }
};

// Ouverture des modales
document.getElementById('open-add-series-modal')?.addEventListener('click', () => modals['add-series'].modal.classList.add('modal-active'));
document.getElementById('open-add-multiple-volumes-modal')?.addEventListener('click', () => {
    modals['add-multiple-volumes'].modal.classList.add('modal-active');
    document.getElementById('multiple-series-results').style.display = 'block';
});
document.getElementById('open-options-modal')?.addEventListener('click', () => modals['options'].modal.classList.add('modal-active'));

// Fonction pour fermer une modale et recharger la page si c'est la modale d'options
function closeModalAndReloadIfTools(modal) {
    modal.classList.remove('modal-active');
    if (modal.id === 'options-modal') {
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