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

// Masquer le message d'erreur après 3 secondes
setTimeout(function() {
    var errorMessage = document.getElementById('error-message');
    if (errorMessage) {
        errorMessage.style.display = 'none';
    }
}, 3000);

// Recherche des séries incomplètes
document.getElementById('search-incomplete-series')?.addEventListener('click', function() {
    const resultsDiv = document.getElementById('incomplete-series-results');
    resultsDiv.innerHTML = '<p>Recherche en cours...</p>';

    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_incomplete_series'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.text();
    })
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                displayIncompleteSeries(data.incomplete_series);
            } else {
                resultsDiv.innerHTML = '<p>Une erreur est survenue lors de la recherche des séries incomplètes.</p>';
            }
        } catch (error) {
            console.error('Erreur de parsing JSON:', error);
            resultsDiv.innerHTML = '<p>Une erreur est survenue lors de la recherche des séries incomplètes.</p>';
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        resultsDiv.innerHTML = '<p>Une erreur est survenue lors de la recherche des séries incomplètes. Veuillez réessayer plus tard.</p>';
    });
});

// Affichage des séries incomplètes
function displayIncompleteSeries(incomplete_series) {
    const resultsDiv = document.getElementById('incomplete-series-results');
    resultsDiv.innerHTML = '';

    if (incomplete_series.length === 0) {
        resultsDiv.innerHTML = '<p>Aucune série incomplète trouvée.</p>';
        return;
    }

    incomplete_series.forEach(series => {
        const seriesDiv = document.createElement('div');
        seriesDiv.className = 'incomplete-series-item';

        let html = `
            <h3>${series.name}</h3>
            <p><strong>Auteur :</strong> ${series.author}</p>
            <p><strong>Éditeur :</strong> ${series.publisher}</p>
            <p><strong>Tomes possédés :</strong> ${series.volumes.length}</p>
        `;

        if (series.missing_volumes && series.missing_volumes.length > 0) {
            html += `<p><strong>Tomes manquants :</strong> ${series.missing_volumes.join(', ')}</p>`;
        } else if (series.has_more_volumes) {
            html += `<p><strong>Tomes manquants :</strong> Aucun</p>`;
            html += `<p class="issues-list"><strong>Attention :</strong> Votre série contient plus de tomes que ce qui est indiqué sur Anilist.</p>`;
        }

        html += `<div class="missing-volumes-actions">`;

        if (series.missing_volumes && series.missing_volumes.length > 0) {
            series.missing_volumes.forEach(volume => {
                html += `
                    <button class="add-missing-volume" data-series-id="${series.id}" data-volume-number="${volume}">
                        + Tome ${volume}
                    </button>
                `;
            });

            html += `
                <button class="add-all-missing-volumes" data-series-id="${series.id}" data-missing-volumes="${series.missing_volumes.join(',')}">
                    Tout ajouter
                </button>
            `;
        }

        html += `</div>`;

        seriesDiv.innerHTML = html;
        resultsDiv.appendChild(seriesDiv);
    });

    // Ajouter les événements aux boutons
    document.querySelectorAll('.add-missing-volume').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            const volumeNumber = parseInt(this.dataset.volumeNumber);

            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=add_missing_volume&series_id=${seriesId}&volume_number=${volumeNumber}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Tome ajouté avec succès !');
                    fetch('admin.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=get_incomplete_series'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            displayIncompleteSeries(data.incomplete_series);
                        }
                    });
                } else {
                    alert('Une erreur est survenue lors de l\'ajout du tome.');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Une erreur est survenue lors de l\'ajout du tome.');
            });
        });
    });

    document.querySelectorAll('.add-all-missing-volumes').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            const missingVolumes = this.dataset.missingVolumes.split(',').map(Number);

            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=add_all_missing_volumes&series_id=${seriesId}&missing_volumes=${missingVolumes.join(',')}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Tomes ajoutés avec succès !');
                    fetch('admin.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=get_incomplete_series'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            displayIncompleteSeries(data.incomplete_series);
                        }
                    });
                } else {
                    alert('Une erreur est survenue lors de l\'ajout des tomes.');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Une erreur est survenue lors de l\'ajout des tomes.');
            });
        });
    });
}

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