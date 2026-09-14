// ──────────────────────────────────────────────────────────────────────────
// assets/js/admin/tools/cache.js — Outil « Vider le cache »
//
// Un seul bouton : demande au serveur de rafraîchir l'horodatage des CSS/JS
// du site (voir fonctions/tools/cache.php, clear_site_cache()) et affiche
// son résultat. Pas de rechargement automatique de la page : le message
// (succès, ou détail d'une limitation de droits sur le disque) reste
// affiché tant que l'utilisateur ne recharge pas lui-même la page.
// ──────────────────────────────────────────────────────────────────────────

document.getElementById('clear-cache-btn').addEventListener('click', () => {
    const button   = document.getElementById('clear-cache-btn');
    const textSpan = document.getElementById('clear-cache-text');
    const spinner  = document.getElementById('clear-cache-spinner');
    const result   = document.getElementById('clear-cache-result');

    button.disabled = true;
    textSpan.textContent = 'Vidage en cours…';
    spinner.style.display = 'inline-block';
    result.innerHTML = '';

    fetch('outil-cache.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'tool_action=clear_cache'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            result.innerHTML = `<p class="coherences-ok">✅ ${htmlEscape(data.message)}</p>`;
        } else {
            result.innerHTML = `<p class="error-text">${htmlEscape(data.message || 'Une erreur est survenue.')}</p>`;
        }
    })
    .catch(() => {
        result.innerHTML = '<p class="error-text">Erreur réseau. Veuillez réessayer.</p>';
    })
    .finally(() => {
        button.disabled = false;
        textSpan.textContent = 'Vider le cache';
        spinner.style.display = 'none';
    });
});
