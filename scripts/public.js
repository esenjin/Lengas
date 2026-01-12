// scripts/public.js

// Gestion des cartes cliquables
document.querySelectorAll('.series-card').forEach(card => {
    card.addEventListener('click', function() {
        const seriesIndex = this.dataset.seriesIndex;
        const series = seriesData[seriesIndex];

        // Remplir la modale avec les données de la série
        document.getElementById('modal-series-title').textContent = series.name;
        document.getElementById('modal-series-image').src = series.image;
        document.getElementById('modal-series-author').textContent = series.author;
        document.getElementById('modal-series-publisher').textContent = series.publisher;
        document.getElementById('modal-series-categories').textContent = series.categories ? series.categories.join(', ') : '';
        document.getElementById('modal-series-genres').textContent = series.genres ? series.genres.join(', ') : '';

        // Calculer les stats
        const totalVolumes = series.volumes.length;
        const readVolumes = series.volumes.filter(v => v.status === 'terminé').length;
        document.getElementById('modal-series-stats').innerHTML =
            `${totalVolumes} tome${totalVolumes > 1 ? 's' : ''} possédé${totalVolumes > 1 ? 's' : ''} ` +
            `(${readVolumes} lu${readVolumes > 1 ? 's' : ''})`;

        // Remplir la liste des tomes
        const volumesList = document.getElementById('modal-volumes-list');
        volumesList.innerHTML = '';

        // Trier les tomes par numéro
        const sortedVolumes = [...series.volumes].sort((a, b) => a.number - b.number);

        // Afficher les tomes triés
        sortedVolumes.forEach(volume => {
            const li = document.createElement('li');
            li.className = `status-${volume.status.replace(' ', '-')} ${volume.collector ? 'volume-collector' : ''} ${volume.last ? 'volume-last' : ''}`;
            li.textContent = volume.number;
            volumesList.appendChild(li);
        });

        // Afficher la modale
        document.getElementById('series-detail-modal').classList.add('modal-active');
    });
});

// Fermeture de la modale
document.getElementById('close-series-detail-modal').addEventListener('click', function() {
    document.getElementById('series-detail-modal').classList.remove('modal-active');
});

// Fermeture de la modale en cliquant à l'extérieur
window.addEventListener('click', (e) => {
    const modal = document.getElementById('series-detail-modal');
    if (e.target === modal) {
        modal.classList.remove('modal-active');
    }
});

// Gestion de la modale de légende
document.getElementById('open-legend-modal').addEventListener('click', function() {
    document.getElementById('legend-modal').classList.add('modal-active');
});

document.getElementById('close-legend-modal').addEventListener('click', function() {
    document.getElementById('legend-modal').classList.remove('modal-active');
});

// Fermeture de la modale en cliquant à l'extérieur
window.addEventListener('click', (e) => {
    const legendModal = document.getElementById('legend-modal');
    if (e.target === legendModal) {
        legendModal.classList.remove('modal-active');
    }
});