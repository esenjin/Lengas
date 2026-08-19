// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/integrity.js — Outil « Vérification d'intégrité du site »
//
// Compare l'instance au dépôt Gitea (au tag de la version installée) :
// présence ET contenu (hash git-blob) de chaque fichier versionné, fichiers
// facultatifs (Vestikan/Babengas), fichiers « intrus » (présents localement,
// absents du dépôt). Met aussi en forme permissions, base de données, thèmes,
// API MangaUpdates, infos serveur… et les actions de nettoyage proposées.
// ──────────────────────────────────────────────────────────────────────────

// Échappe le HTML pour l'affichage de chemins de fichiers.
function escHtml(str) {
    return String(str).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

// Regroupe un objet { chemin => infos } par sa propriété "category".
function groupByCategory(filesObj) {
    const groups = {};
    for (const [path, info] of Object.entries(filesObj)) {
        const cat = info.category || 'Autres';
        (groups[cat] = groups[cat] || []).push([path, info]);
    }
    return groups;
}

// Rend l'état d'un fichier requis (présence + hash).
function renderRequiredFileLine(path, info) {
    let statusClass, statusText;
    if (!info.exists) {
        statusClass = 'error';
        statusText  = 'Manquant';
    } else if (!info.hash_ok) {
        statusClass = 'error';
        statusText  = 'Modifié';
    } else {
        statusClass = 'ok';
        statusText  = 'OK';
    }
    return `<li>${escHtml(path)}: <span class="${statusClass}">${statusText}</span></li>`;
}

// Rend l'état d'un fichier facultatif (absence non bloquante = orange).
function renderOptionalFileLine(path, info) {
    let statusClass, statusText;
    if (!info.exists) {
        statusClass = 'warn';
        statusText  = 'Absent';
    } else if (!info.hash_ok) {
        statusClass = 'error';
        statusText  = 'Modifié';
    } else {
        statusClass = 'ok';
        statusText  = 'OK';
    }
    return `<li>${escHtml(path)}: <span class="${statusClass}">${statusText}</span></li>`;
}

// Fonction pour afficher les résultats de la vérification d'intégrité
function displayIntegrityResults(results) {
    const container = document.getElementById('integrity-results-container');
    if (!container) return;

    let html = `
        <div class="integrity-header">
            <h3>Résultats de la vérification d'intégrité</h3>
        </div>
        <div class="integrity-results">
    `;

    // ── 0. État de la comparaison avec le dépôt Gitea ─────────────────────────
    const repo = results.repo || {};
    html += `<div class="integrity-section"><h3>Comparaison avec le dépôt</h3>`;
    if (!repo.reachable) {
        html += `
            <p class="hint error">⚠️ Impossible de récupérer l'arborescence du dépôt Gitea.
            La vérification des fichiers (présence et contenu) n'a pas pu être effectuée.
            Vérifiez la connectivité du serveur à <code>git.crystalyx.net</code> et la constante
            <code>URL_GITEA</code>.</p>
        `;
    } else {
        html += `<ul>`;
        html += `<li>Tag de référence utilisé : <span class="ok">${escHtml(repo.checked_tag || '?')}</span></li>`;
        html += `<li>Fichiers versionnés analysés : ${repo.file_count ?? 0}</li>`;
        if (repo.used_fallback) {
            html += `<li class="warn">La version installée (${escHtml(results.version.current)}) n'a pas de tag correspondant sur le dépôt : comparaison effectuée avec le tag le plus récent (${escHtml(repo.checked_tag || '?')}).</li>`;
        }
        html += `</ul>`;
    }
    html += `</div>`;

    // ── 1. Fichiers requis (présence + contenu), regroupés par catégorie ──────
    if (repo.reachable && results.files) {
        html += `
            <div class="integrity-section">
                <h3>Fichiers du site (présence et contenu)</h3>
                <p class="hint">Chaque fichier versionné est comparé au dépôt : « Manquant » s'il est absent, « Modifié » si son contenu diffère du dépôt, « OK » sinon.</p>
                <div class="file-categories">
        `;
        const groups = groupByCategory(results.files);
        // Ordre d'affichage privilégié, puis le reste alphabétiquement.
        const preferredOrder = [
            'Fichiers racines', 'Pages', 'Includes', 'Fonctions', 'Fonctions (outils)',
            'CSS', 'JS (général)', 'JS (admin)', 'JS (outils)', 'Images', 'Assets', 'Autres'
        ];
        const cats = Object.keys(groups).sort((a, b) => {
            const ia = preferredOrder.indexOf(a), ib = preferredOrder.indexOf(b);
            if (ia === -1 && ib === -1) return a.localeCompare(b);
            if (ia === -1) return 1;
            if (ib === -1) return -1;
            return ia - ib;
        });
        cats.forEach(cat => {
            const entries = groups[cat].sort((a, b) => a[0].localeCompare(b[0]));
            html += `<div class="file-category"><h4>${escHtml(cat)}</h4><ul>`;
            entries.forEach(([path, info]) => { html += renderRequiredFileLine(path, info); });
            html += `</ul></div><br>`;
        });
        html += `</div>`;

        // Récapitulatif des problèmes.
        const problems = Object.entries(results.files).filter(([, i]) => !i.exists || !i.hash_ok);
        if (problems.length === 0) {
            html += `<p class="hint ok">✔️ Tous les fichiers versionnés sont présents et conformes au dépôt.</p>`;
        } else {
            const missing  = problems.filter(([, i]) => !i.exists).length;
            const modified = problems.filter(([, i]) => i.exists && !i.hash_ok).length;
            html += `<p class="hint error">⚠️ ${missing} fichier(s) manquant(s), ${modified} fichier(s) modifié(s) par rapport au dépôt.</p>`;
        }
        html += `</div>`;
    }

    // ── 2. Fichiers facultatifs (Vestikan / Babengas) ─────────────────────────
    if (repo.reachable && results.optional_files && Object.keys(results.optional_files).length > 0) {
        html += `
            <div class="integrity-section">
                <h3>Modules facultatifs (Vestikan / Babengas)</h3>
                <p class="hint">Ces fichiers sont facultatifs : absents, le module concerné est simplement désactivé et le site reste 100% fonctionnel. S'ils sont présents, leur contenu est tout de même comparé au dépôt.</p>
                <div class="file-categories">
        `;
        const groups = groupByCategory(results.optional_files);
        Object.keys(groups).sort().forEach(cat => {
            const entries = groups[cat].sort((a, b) => a[0].localeCompare(b[0]));
            html += `<div class="file-category"><h4>${escHtml(cat)}</h4><ul>`;
            entries.forEach(([path, info]) => { html += renderOptionalFileLine(path, info); });
            html += `</ul></div><br>`;
        });
        html += `</div></div>`;
    }

    // ── 2bis. État d'activation et de fonctionnement des modules ──────────────
    if (results.modules_status) {
        const renderModule = (name, m, disabledHint) => {
            if (!m) return '';
            let stateClass, stateText;
            if (!m.installed) {
                stateClass = 'warn';
                stateText  = 'Non installé';
            } else if (!m.enabled) {
                stateClass = 'warn';
                stateText  = name === 'Syngas' ? 'Installé mais pas encore provisionné' : 'Installé mais désactivé';
            } else if (m.functional === true) {
                stateClass = 'ok';
                stateText  = 'Activé et fonctionnel';
            } else if (m.functional === false) {
                stateClass = 'error';
                stateText  = 'Activé mais NON fonctionnel';
            } else {
                stateClass = 'warn';
                stateText  = 'Activé (fonctionnement non testé)';
            }
            if (name === 'Syngas' && m.banned) {
                stateClass = 'error';
                stateText  = 'Connexion suspendue (banni)';
            }
            let extra = '';
            if (name === 'Vestikan' && m.base_url) {
                extra = `<li>Serveur : <a href="${m.base_url}" target="_blank">${escHtml(m.base_url)}</a></li>`;
            }
            if ((name === 'Babengas' || name === 'Syngas') && m.version) {
                extra = `<li>Version du service : ${escHtml(m.version)}</li>`;
            }
            return `
                <div class="file-category">
                    <h4>${escHtml(name)}</h4>
                    <ul>
                        <li>État : <span class="${stateClass}">${stateText}</span></li>
                        ${m.detail ? `<li>${escHtml(m.detail)}</li>` : ''}
                        ${extra}
                    </ul>
                </div>
            `;
        };
        html += `
            <div class="integrity-section">
                <h3>Modules facultatifs — activation et fonctionnement</h3>
                <p class="hint">Vérifie si Vestikan (SSO), Babengas (décompte VF) et Syngas (base commune des mangathèques) sont installés, réellement activés, et — le cas échéant — si leur service distant répond.</p>
                <div class="file-categories">
                    ${renderModule('Vestikan', results.modules_status.vestikan)}
                    ${renderModule('Babengas', results.modules_status.babengas)}
                    ${renderModule('Syngas', results.modules_status.syngas)}
                </div>
            </div>
        `;
    }

    // ── 3. Fichiers intrus (présents localement, absents du dépôt) ────────────
    if (repo.reachable) {
        html += `
            <div class="integrity-section">
                <h3>Fichiers étrangers au dépôt</h3>
                <p class="hint">Fichiers présents sur l'instance mais absents du dépôt (hors données : <code>uploads/</code>, <code>saves/</code>, <code>bdd/</code>, config Vestikan, thèmes personnalisés, photo de profil). Ils ne devraient normalement pas être là — vérifiez-les avant de les supprimer manuellement.</p>
                <ul>
        `;
        const extras = results.extra_files || [];
        if (extras.length > 0) {
            extras.forEach(f => {
                html += `<li>${escHtml(f)} <span class="warn">(non versionné)</span></li>`;
            });
        } else {
            html += `<li>Aucun fichier étranger détecté</li>`;
        }
        html += `</ul></div>`;
    }

    // ── 4. Fichiers interdits ─────────────────────────────────────────────────
    html += `
        <div class="integrity-section">
            <h3>Fichiers interdits</h3>
            <p class="hint">Fichiers d'installation/migration à supprimer une fois le site en place.</p>
            <ul>
    `;
    for (const [file, ok] of Object.entries(results.forbidden_files)) {
        html += `<li>${escHtml(file)}: <span class="${ok ? 'ok' : 'error'}">${ok ? 'Absent' : 'Présent'}</span></li>`;
    }
    html += `</ul>`;
    if (Object.values(results.forbidden_files).some(ok => !ok)) {
        html += `
            <button id="clean-forbidden-files-btn" class="button button-opt">
                Supprimer les fichiers interdits
            </button>
        `;
    }
    html += `</div>`;

    // ── 5. Permissions ────────────────────────────────────────────────────────
    html += `
        <div class="integrity-section">
            <h3>Permissions des fichiers/dossiers</h3>
            <table class="permissions-table">
                <thead>
                    <tr>
                        <th>Fichier/Dossier</th>
                        <th>Permission actuelle</th>
                        <th>Permission attendue</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
    `;
    for (const [file, data] of Object.entries(results.permissions)) {
        html += `
            <tr>
                <td>${escHtml(file)}</td>
                <td>${data.current}</td>
                <td>${data.expected}</td>
                <td class="${data.ok ? 'ok' : 'error'}">${data.ok ? 'OK' : 'Incorrect'}</td>
            </tr>
        `;
    }
    html += `</tbody></table></div>`;

    // ── 6. Accès externe aux dossiers sensibles ───────────────────────────────
    html += `
        <div class="integrity-section">
            <h3>Accès externe aux dossiers sensibles</h3>
            <ul>
    `;
    for (const [folder, info] of Object.entries(results.external_access)) {
        let statusClass, statusText;
        if (info.ok === null) {
            statusClass = 'warn';
            statusText  = `Indéterminé (code HTTP ${info.status})`;
        } else if (info.ok) {
            statusClass = 'ok';
            statusText  = `Bloqué (${info.status})`;
        } else {
            statusClass = 'error';
            statusText  = `Accessible ! (code HTTP ${info.status})`;
        }
        html += `<li>${escHtml(folder)} : <span class="${statusClass}">${statusText}</span></li>`;
    }
    html += `</ul>`;
    if (Object.values(results.external_access).some(i => i.ok === false)) {
        html += `<p class="hint error">⚠️ Un ou plusieurs dossiers sensibles sont accessibles depuis l'extérieur. Vérifiez votre fichier <code>.htaccess</code>.</p>`;
    }
    html += `</div>`;

    // ── 7. Doublons ───────────────────────────────────────────────────────────
    html += `
        <div class="integrity-section">
            <h3>Doublons</h3>
            <ul>
    `;
    if (results.duplicates.collection_wishlist.length > 0) {
        html += `<li>Séries présentes à la fois dans la collection et la liste d'envies: <span class="error">${escHtml(results.duplicates.collection_wishlist.join(', '))}</span></li>`;
    } else {
        html += `<li>Aucun doublon collection/envies</li>`;
    }
    if (results.duplicates.deleted_loans.length > 0) {
        html += `<li>Séries supprimées mais encore en prêt: <span class="error">${escHtml(results.duplicates.deleted_loans.join(', '))}</span></li>`;
    } else {
        html += `<li>Aucune série supprimée en prêt</li>`;
    }
    html += `</ul>`;
    if (results.duplicates.collection_wishlist.length > 0 || results.duplicates.deleted_loans.length > 0) {
        html += `
            <button id="clean-duplicates-btn" class="button button-opt">
                Nettoyer les doublons
            </button>
        `;
    }
    html += `</div>`;

    // ── 8. Images orphelines ──────────────────────────────────────────────────
    html += `
        <div class="integrity-section">
            <h3>Images orphelines</h3>
            <ul>
    `;
    if (results.orphaned_images.length > 0) {
        results.orphaned_images.forEach(image => {
            html += `<li>${escHtml(image)} <span class="error">(orpheline)</span></li>`;
        });
    } else {
        html += `<li>Aucune image orpheline</li>`;
    }
    html += `</ul>`;
    if (results.orphaned_images.length > 0) {
        html += `
            <button id="clean-orphaned-images-btn" class="button button-opt">
                Nettoyer les images orphelines
            </button>
        `;
    }
    html += `</div>`;

    // ── 9. Thèmes personnalisés ───────────────────────────────────────────────
    html += `
        <div class="integrity-section">
            <h3>Thèmes personnalisés</h3>
            <ul>
    `;
    if (results.custom_themes && results.custom_themes.length > 0) {
        results.custom_themes.forEach(theme => {
            html += `<li>${escHtml(theme.label)} <span class="ok">(${escHtml(theme.file)})</span></li>`;
        });
    } else {
        html += `<li>Aucun thème personnalisé détecté</li>`;
    }
    html += `</ul></div>`;

    // ── 10. Structure de la base de données (MangaUpdates) ────────────────────
    if (results.db_structure) {
        html += `
            <div class="integrity-section">
                <h3>Base de données (MangaUpdates)</h3>
                <ul>
        `;
        for (const [label, ok] of Object.entries(results.db_structure)) {
            html += `<li>${escHtml(label)} : <span class="${ok ? 'ok' : 'error'}">${ok ? 'OK' : 'Manquant'}</span></li>`;
        }
        html += `</ul></div>`;
    }

    // ── 11. Connectivité de l'API MangaUpdates ────────────────────────────────
    if (results.mangaupdates_api) {
        const api = results.mangaupdates_api;
        html += `
            <div class="integrity-section">
                <h3>API MangaUpdates</h3>
                <ul>
                    <li>Accès à l'API : <span class="${api.ok ? 'ok' : 'error'}">${api.ok ? 'OK' : 'Échec'}</span>${(!api.ok && api.http) ? ` (HTTP ${api.http})` : ''}</li>
                    ${(!api.ok && api.error) ? `<li class="error">Erreur : ${escHtml(api.error)}</li>` : ''}
                    <li>Entrées en cache : ${api.cache_count ?? 0}</li>
                </ul>
            </div>
        `;
    }

    // ── 11bis. Structure de la base de données (Anilist, V4) ──────────────────
    if (results.db_structure_anilist && Object.keys(results.db_structure_anilist).length > 0) {
        html += `
            <div class="integrity-section">
                <h3>Base de données (Anilist)</h3>
                <p class="hint">Colonnes et tables introduites par la V4 pour l'intégration des animés. Une entrée manquante signale une migration incomplète.</p>
                <ul>
        `;
        for (const [label, ok] of Object.entries(results.db_structure_anilist)) {
            html += `<li>${escHtml(label)} : <span class="${ok ? 'ok' : 'error'}">${ok ? 'OK' : 'Manquant'}</span></li>`;
        }
        html += `</ul></div>`;
    }

    // ── 12bis. Connectivité de l'API Anilist (V4) ─────────────────────────────
    if (results.anilist_api) {
        const api = results.anilist_api;
        html += `
            <div class="integrity-section">
                <h3>API Anilist</h3>
                <ul>
                    <li>Accès à l'API : <span class="${api.ok ? 'ok' : 'error'}">${api.ok ? 'OK' : 'Échec'}</span>${(!api.ok && api.http) ? ` (HTTP ${api.http})` : ''}</li>
                    ${(!api.ok && api.error) ? `<li class="error">Erreur : ${escHtml(api.error)}</li>` : ''}
                    <li>Fiches en cache : ${api.cache_count ?? 0}</li>
                </ul>
            </div>
        `;
    }

    // ── 12. Version ───────────────────────────────────────────────────────────
    html += `
        <div class="integrity-section">
            <h3>Version du site</h3>
            <ul>
                <li>Version actuelle : ${escHtml(results.version.current)}</li>
                <li>Dernière version : ${results.version.latest ? escHtml(results.version.latest) : 'Inconnue'}</li>
                ${results.version.needs_update ?
                    `<li class="error">Une nouvelle version est disponible !</li>` : ''}
            </ul>
        </div>
    `;

    // ── 13. Informations sur le site ──────────────────────────────────────────
    html += `
        <div class="integrity-section">
            <h3>Informations sur le site</h3>
            <ul>
                <li>URL du site : <a href="${results.site_info.site_url}" target="_blank">${escHtml(results.site_info.site_url)}</a></li>
                <li>HTTPS : <span class="${results.site_info.uses_https ? 'ok' : 'error'}">${results.site_info.uses_https ? 'Activé' : 'Non activé'}</span></li>
                <li>Taille du dossier uploads (vignettes) : ${results.site_info.uploads_size}</li>
                <li>Taille maximale des fichiers téléversés : ${results.site_info.max_upload_size}</li>
                <li>Taille effective maximale : ${results.site_info.effective_max_upload_size}</li>
            </ul>
        </div>
    `;

    // ── 14. Informations serveur ──────────────────────────────────────────────
    html += `
        <div class="integrity-section">
            <h3>Informations sur le serveur</h3>
            <ul>
                <li>Architecture serveur : ${escHtml(results.site_info.server_info.server_architecture)}</li>
                <li>Serveur web : ${escHtml(results.site_info.server_info.server_software)}</li>
                <li>Version de PHP : ${escHtml(results.site_info.server_info.php_version)}</li>
                <li>Limite d'exécution PHP : ${results.site_info.server_info.max_execution_time} secondes</li>
                <li>Limite de mémoire PHP : ${results.site_info.server_info.memory_limit}</li>
            </ul>
        </div>
    `;

    html += `</div>`;
    container.innerHTML = html;

    // ── Événements des boutons de nettoyage ───────────────────────────────────
    // Après un nettoyage, on relance la vérification en AJAX (et on réaffiche
    // directement les nouveaux résultats) plutôt que de recharger la page :
    // un rechargement complet perdait les résultats déjà affichés et obligeait
    // à recliquer sur « Vérifier l'intégrité » pour les revoir.
    const postClean = (action, confirmMsg) => {
        showCustomConfirm('Confirmation', confirmMsg).then((confirmed) => {
            if (!confirmed) return;
            fetch('outil-integrite.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'tool_action=' + encodeURIComponent(action)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccessModal(data.message);
                    return fetch('outil-integrite.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'tool_action=check_integrity'
                    })
                    .then(response => response.json())
                    .then(refreshed => {
                        if (refreshed.success) {
                            displayIntegrityResults(refreshed.results);
                        } else {
                            showErrorModal('Une erreur est survenue lors de la ré-vérification.');
                        }
                    });
                } else {
                    showErrorModal(data.message);
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showErrorModal('Une erreur est survenue.');
            });
        });
    };

    if (results.duplicates.collection_wishlist.length > 0 || results.duplicates.deleted_loans.length > 0) {
        document.getElementById('clean-duplicates-btn').addEventListener('click', () => {
            postClean('clean_duplicates', 'Êtes-vous sûr de vouloir nettoyer les doublons ?');
        });
    }

    if (results.orphaned_images.length > 0) {
        document.getElementById('clean-orphaned-images-btn').addEventListener('click', () => {
            postClean('clean_orphaned_images', 'Êtes-vous sûr de vouloir supprimer les images orphelines ?');
        });
    }

    if (Object.values(results.forbidden_files).some(ok => !ok)) {
        document.getElementById('clean-forbidden-files-btn').addEventListener('click', () => {
            postClean('clean_forbidden_files', 'Êtes-vous sûr de vouloir supprimer les fichiers interdits ?');
        });
    }
}

// Vérification d'intégrité du site : bouton de la section dédiée de la page « Outils »
document.addEventListener('click', (e) => {
    if (!e.target.closest('#check-integrity-btn')) return;
    const button   = document.getElementById('check-integrity-btn');
    const textSpan = document.getElementById('check-integrity-text');
    const spinner  = document.getElementById('check-integrity-spinner');

    button.disabled = true;
    textSpan.textContent = 'Vérification en cours...';
    spinner.style.display = 'inline-block';

    fetch('outil-integrite.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'tool_action=check_integrity'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayIntegrityResults(data.results);
        } else {
            showErrorModal('Une erreur est survenue lors de la vérification.');
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        showErrorModal('Une erreur est survenue.');
    })
    .finally(() => {
        button.disabled = false;
        textSpan.textContent = "Vérifier l'intégrité";
        spinner.style.display = 'none';
    });
});
