// Fonction pour récupérer et afficher les séries à lire
function fetch_unread_series() {
    fetch('fonctions/unread.php?scan_unread=true')
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('unread-series-list');
            container.innerHTML = '';
            if (data.success && data.series.length > 0) {
                data.series.forEach(series => {
                    const seriesDiv = document.createElement('div');
                    seriesDiv.className = 'unread-series-item';
                    seriesDiv.innerHTML = `
                        <div class="series-title">${series.name}</div>
                        <div class="series-details">
                            <span>Dernier tome lu : ${series.last_read_volume}</span>
                            <span>Tomes restants : ${series.unread_volumes_count}</span>
                            <button class="mark-as-read-btn" data-series-id="${series.id}">Marquer comme lu</button>
                        </div>
                    `;
                    container.appendChild(seriesDiv);
                });

                // Écouteurs pour les boutons "Marquer comme lu"
                document.querySelectorAll('.mark-as-read-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const seriesId = this.dataset.seriesId;
                        const volumesToMark = prompt("Combien de tomes voulez-vous marquer comme lus ?");
                        if (volumesToMark && !isNaN(volumesToMark) && volumesToMark > 0) {
                            mark_volumes_as_read(seriesId, parseInt(volumesToMark));
                        }
                    });
                });
            } else {
                container.innerHTML = '<p>Aucune série avec des tomes à lire.</p>';
            }
        });
}

// Fonction pour marquer des tomes comme lus
function mark_volumes_as_read(seriesId, volumesToMark) {
    fetch('fonctions/unread.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `mark_as_read=true&series_id=${seriesId}&volumes_to_mark=${volumesToMark}`
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            fetch_unread_series(); // Rafraîchir la liste
        }
    });
}

// Écouteur pour le bouton de scan
document.getElementById('scan-unread-btn').addEventListener('click', () => {
    console.log("Bouton cliqué !"); // Ajoute cette ligne
    fetch_unread_series();
});