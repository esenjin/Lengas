// Ouverture de la modale "Livres prêtés"
document.getElementById('open-loan-modal').addEventListener('click', () => {
    modals['loan'] = { modal: document.getElementById('loan-modal'), closeBtn: document.getElementById('close-loan-modal') };
    modals['loan'].modal.classList.add('modal-active');
    loadLoanList();
});

// Fermeture de la modale "Livres prêtés"
document.getElementById('close-loan-modal').addEventListener('click', () => {
    modals['loan'].modal.classList.remove('modal-active');
});

// Recherche de série pour les prêts
setupSeriesSearch('loan-series-search', 'loan-series-results');
setupSeriesSearch('multiple-loan-series-search', 'multiple-loan-series-results');

// Normalisation d'une chaîne pour la recherche (sans accents, en minuscules)
function normalizeString(str) {
    return str
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "");
}

// Écouteur pour le champ de recherche des prêts
document.getElementById('loan-search').addEventListener('input', function() {
    const searchTerm = normalizeString(this.value);
    const loanItems = document.querySelectorAll('.loan-series-item');

    loanItems.forEach(item => {
        const seriesName = item.querySelector('h4') ? normalizeString(item.querySelector('h4').textContent) : '';
        const borrowerNames = Array.from(item.querySelectorAll('.loan-volumes-list li')).map(li =>
            normalizeString(li.textContent)
        );

        // Vérifie si le terme de recherche correspond au nom de la série ou à un emprunteur
        const matchesSeries = seriesName.includes(searchTerm);
        const matchesBorrower = borrowerNames.some(name => name.includes(searchTerm));

        if (matchesSeries || matchesBorrower) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
});

// Autocomplétion pour les prêts
setupAutocomplete('loan-series-search', 'series');
setupAutocomplete('multiple-loan-series-search', 'series');

// Sélection de série pour les prêts
setupSeriesSelection('loan-series-results', 'loan-selected-series-id', 'loan-series-search');
setupSeriesSelection('multiple-loan-series-results', 'multiple-loan-selected-series-id', 'multiple-loan-series-search');

// Afficher les résultats au focus du champ de recherche
document.getElementById('loan-series-search').addEventListener('focus', function() {
    document.getElementById('loan-series-results').style.display = 'block';
});

document.getElementById('multiple-loan-series-search').addEventListener('focus', function() {
    document.getElementById('multiple-loan-series-results').style.display = 'block';
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('#loan-series-search') && !e.target.closest('#loan-series-results')) {
        document.getElementById('loan-series-results').style.display = 'none';
    }
    if (!e.target.closest('#multiple-loan-series-search') && !e.target.closest('#multiple-loan-series-results')) {
        document.getElementById('multiple-loan-series-results').style.display = 'none';
    }
});

// Ajouter un prêt (un seul tome)
document.getElementById('add-single-loan-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('loan_action', 'add_single_loan');

    fetch('admin.php', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Prêt ajouté avec succès !');
            this.reset();
            loadLoanList();
        } else {
            let errorMessage = 'Une erreur est survenue.';
            if (data.message) {
                errorMessage = data.message;
            }
            alert(errorMessage);
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        alert('Une erreur est survenue.');
    });
});

// Ajouter un prêt (plusieurs tomes)
document.getElementById('add-multiple-loans-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('loan_action', 'add_multiple_loans');

    fetch('admin.php', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Prêts ajoutés avec succès !');
            this.reset();
            loadLoanList();
        } else {
            let errorMessage = 'Une erreur est survenue.';
            if (data.message) {
                errorMessage = data.message;
            }
            alert(errorMessage);
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        alert('Une erreur est survenue.');
    });
});

// Charger la liste des prêts
function loadLoanList() {
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'loan_action=get_loans'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayLoanList(data.loans);
        } else {
            console.error('Erreur lors du chargement des prêts.');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
    });
}

// Afficher la liste des prêts
function displayLoanList(loans) {
    const loanListDiv = document.getElementById('loan-list');
    loanListDiv.innerHTML = '';

    if (loans.length === 0) {
        loanListDiv.innerHTML = '<p>Aucun livre prêté.</p>';
        return;
    }

    loans.forEach(item => {
        const series = item.series;
        const loans = item.loans;
        const seriesExists = item.series_exists;

        const seriesDiv = document.createElement('div');
        seriesDiv.className = 'loan-series-item';

        let html = '';

        if (!seriesExists) {
            html += `
                <h4 style="color: #cf6679;">Série supprimée de la base</h4>
                <p>Cette série a été supprimée de votre base, mais des prêts sont encore enregistrés.</p>
            `;
        } else {
            html += `
                <h4>${series.name}</h4>
                <p><strong>Auteur :</strong> ${series.author}</p>
            `;
        }

        html += `
            <p><strong>Tomes prêtés :</strong></p>
            <ul class="loan-volumes-list">
        `;

        loans.forEach(loan => {
            html += `
                <li>
                    Tome ${loan.volume_number} (à ${loan.borrower_name})
                    <button class="remove-loan-btn" data-series-id="${loan.series_id}" data-volume-number="${loan.volume_number}">Retirer</button>
                </li>
            `;
        });

        html += `</ul>`;

        if (loans.length > 1) {
            html += `
                <button class="remove-all-loans-btn" data-series-id="${loans[0].series_id}">
                    Tout retirer
                </button>
            `;
        }

        seriesDiv.innerHTML = html;
        loanListDiv.appendChild(seriesDiv);
    });

    // Ajouter les événements aux boutons de suppression
    document.querySelectorAll('.remove-loan-btn').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;
            const volumeNumber = this.dataset.volumeNumber;

            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer ce tome ?').then((confirmed) => {
                if (confirmed) {
                    fetch('admin.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `loan_action=remove_loan&series_id=${seriesId}&volume_number=${volumeNumber}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            loadLoanList();
                        } else {
                            alert('Une erreur est survenue.');
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        alert('Une erreur est survenue.');
                    });
                }
            });
        });
    });

    // Ajouter les événements aux boutons "Tout retirer"
    document.querySelectorAll('.remove-all-loans-btn').forEach(button => {
        button.addEventListener('click', function() {
            const seriesId = this.dataset.seriesId;

            showCustomConfirm('Confirmation', 'Êtes-vous sûr de vouloir supprimer tous les prêts de cette série ?').then((confirmed) => {
                if (confirmed) {
                    fetch('admin.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `loan_action=remove_all_loans&series_id=${seriesId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            loadLoanList();
                        } else {
                            alert('Une erreur est survenue.');
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        alert('Une erreur est survenue.');
                    });
                }
            });
        });
    });
}