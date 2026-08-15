/* ════════════════════════════════════════════════════════════════════════════
   stats.js — Dashboard statistiques Lengas
   Chart.js  → donuts (statut, temps, complétude)
   ApexCharts → treemaps (auteurs/éditeurs), barres top N, genres, catégories,
                valeur, courbes temporelles
   ════════════════════════════════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', function () {
    const S = window.STATS || {};

    // ── Palette (lue depuis le thème actif via les variables CSS :root) ───────
    // On récupère les couleurs réelles du thème appliqué (sombre, clair ou
    // personnalisé) pour que les graphiques s'y adaptent automatiquement.
    // Chaque valeur possède un repli identique à l'ancienne palette sombre.
    const cssVar = (name, fallback) => {
        const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    };
    const C = {
        primary:  cssVar('--primary-color',       '#c084fc'),
        primary2: cssVar('--primary-hover',       '#a855f7'),
        teal:     cssVar('--success-color',       '#34d399'),
        sky:      cssVar('--button-otl',          '#38bdf8'),
        amber:    cssVar('--warning-color',       '#fbbf24'),
        red:      cssVar('--error-color',         '#f87171'),
        pink:     cssVar('--series-title-color',  '#e879c6'),
        grid:     cssVar('--input-border',        'rgba(255,255,255,0.06)'),
        text:     cssVar('--text-color',          '#d4d4e8'),
        textGray: cssVar('--text-gray',           '#8888a8'),
        card:     cssVar('--background-card',      '#181825'),
    };

    const fmtInt = n => new Intl.NumberFormat('fr-FR').format(n);

    // Détection mobile : réduit la largeur réservée aux libellés d'axe Y
    // des graphiques en barres horizontales, qui sinon peuvent forcer le
    // graphique (et donc toute la page) à dépasser la largeur de l'écran.
    const isMobile = window.matchMedia('(max-width: 600px)').matches;
    const yAxisMaxWidth = (full) => isMobile ? Math.min(full, 110) : full;

    // Couleurs dégradées pour les listes (treemaps, barres)
    const RAMP = ['#c084fc', '#a855f7', '#8b5cf6', '#7c6df2', '#6d8bf2', '#38bdf8', '#34d399', '#5ad1a8', '#fbbf24', '#fb923c'];
    function rampColor(i, total) {
        return RAMP[i % RAMP.length];
    }

    // ── Minutes → texte court ─────────────────────────────────────────────────
    function minutesToText(min) {
        min = Math.round(min);
        if (min <= 0) return '0 min';
        const d = Math.floor(min / 1440);
        const h = Math.floor((min % 1440) / 60);
        const m = min % 60;
        const parts = [];
        if (d) parts.push(d + ' j');
        if (h) parts.push(h + ' h');
        if (m) parts.push(m + ' min');
        return parts.join(' ');
    }

    // ── 0. Menu mobile ────────────────────────────────────────────────────────
    const menuBtn = document.getElementById('mobile-menu-button');
    const menu = document.getElementById('public-menu');
    if (menuBtn && menu) {
        menuBtn.addEventListener('click', () => menu.classList.toggle('active'));
    }

    // ── 0b. Bouton "Retour en haut" ───────────────────────────────────────────
    const backToTop = document.getElementById('back-to-top');
    if (backToTop) {
        window.addEventListener('scroll', function () {
            backToTop.style.display = window.pageYOffset > 300 ? 'block' : 'none';
        });
        backToTop.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // ── 0c. Onglets Mangathèque / Animethèque ─────────────────────────────────
    // Mémorisés dans l'URL (#manga / #anime), sur le même principe que les
    // onglets de la page Outils (cf. assets/js/page.js) : survit à un
    // rechargement, se partage en lien direct. Non memorisé au-delà (pas de
    // localStorage) : cohérent avec le reste du site, qui ne mémorise jamais
    // ces choix de vue d'une visite à l'autre.
    (function initStatsTabs() {
        const tabs   = document.querySelectorAll('.stats-tab');
        const panels = document.querySelectorAll('.stats-tab-panel');
        if (!tabs.length) return; // Animethèque vide : les onglets ne sont pas rendus

        function activate(name) {
            const known = Array.from(tabs).some(t => t.dataset.statsTab === name);
            if (!known) return;
            tabs.forEach(t => {
                const active = t.dataset.statsTab === name;
                t.classList.toggle('stats-tab--active', active);
                t.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(p => p.classList.toggle('stats-tab-panel--active', p.dataset.statsTabPanel === name));
        }

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                activate(tab.dataset.statsTab);
                history.replaceState(null, '', '#' + tab.dataset.statsTab);
            });
        });

        const hash = (window.location.hash || '').replace('#', '');
        if (hash === 'anime' || hash === 'manga') activate(hash);
    })();

    // ══════════════════════════════════════════════════════════════════════════
    //  CHART.JS — Donuts
    // ══════════════════════════════════════════════════════════════════════════
    const donutDefaults = {
        type: 'doughnut',
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: C.text, padding: 14, font: { family: 'Inter, sans-serif', size: 12 } }
                }
            }
        }
    };

    // 2. Statut des tomes
    if (S.status && document.getElementById('chart-status')) {
        const vals = S.status.values.slice();
        const labels = S.status.labels.slice();
        if (S.status.elsewhere > 0) { labels.push('Non possédé'); vals.push(S.status.elsewhere); }
        new Chart(document.getElementById('chart-status'), {
            ...donutDefaults,
            data: {
                labels,
                datasets: [{
                    data: vals,
                    backgroundColor: [C.teal, C.primary, C.red, C.textGray],
                    borderColor: C.card, borderWidth: 2
                }]
            },
            options: {
                ...donutDefaults.options,
                plugins: {
                    ...donutDefaults.options.plugins,
                    tooltip: { callbacks: { label: ctx => `${ctx.label}: ${fmtInt(ctx.raw)} tomes` } }
                }
            }
        });
    }

    // 3. Temps de lecture
    if (S.time && document.getElementById('chart-time')) {
        new Chart(document.getElementById('chart-time'), {
            ...donutDefaults,
            data: {
                labels: S.time.labels,
                datasets: [{
                    data: S.time.values,
                    backgroundColor: [C.teal, C.primary, C.red, C.textGray],
                    borderColor: C.card, borderWidth: 2
                }]
            },
            options: {
                ...donutDefaults.options,
                plugins: {
                    ...donutDefaults.options.plugins,
                    tooltip: { callbacks: { label: ctx => `${ctx.label}: ${minutesToText(ctx.raw)}` } }
                }
            }
        });
    }

    // 10. Complétude des séries
    if (S.completion && document.getElementById('chart-completion')) {
        new Chart(document.getElementById('chart-completion'), {
            ...donutDefaults,
            data: {
                labels: S.completion.labels,
                datasets: [{
                    data: S.completion.values,
                    backgroundColor: [C.teal, C.primary, C.amber, C.red],
                    borderColor: C.card, borderWidth: 2
                }]
            },
            options: {
                ...donutDefaults.options,
                plugins: {
                    ...donutDefaults.options.plugins,
                    tooltip: { callbacks: { label: ctx => `${ctx.label}: ${fmtInt(ctx.raw)} séries` } }
                }
            }
        });
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  CHART.JS — Donuts (Animethèque)
    // ══════════════════════════════════════════════════════════════════════════
    const A = window.ANIME_STATS || {};

    // Statut des épisodes
    if (A.status && document.getElementById('anime-chart-status')) {
        new Chart(document.getElementById('anime-chart-status'), {
            ...donutDefaults,
            data: {
                labels: A.status.labels,
                datasets: [{
                    data: A.status.values,
                    backgroundColor: [C.teal, C.primary, C.red],
                    borderColor: C.card, borderWidth: 2
                }]
            },
            options: {
                ...donutDefaults.options,
                plugins: {
                    ...donutDefaults.options.plugins,
                    tooltip: { callbacks: { label: ctx => `${ctx.label}: ${fmtInt(ctx.raw)} épisodes` } }
                }
            }
        });
    }

    // Temps de visionnage
    if (A.time && document.getElementById('anime-chart-time')) {
        new Chart(document.getElementById('anime-chart-time'), {
            ...donutDefaults,
            data: {
                labels: A.time.labels,
                datasets: [{
                    data: A.time.values,
                    backgroundColor: [C.teal, C.primary, C.red],
                    borderColor: C.card, borderWidth: 2
                }]
            },
            options: {
                ...donutDefaults.options,
                plugins: {
                    ...donutDefaults.options.plugins,
                    tooltip: { callbacks: { label: ctx => `${ctx.label}: ${minutesToText(ctx.raw)}` } }
                }
            }
        });
    }

    // Statut de diffusion
    if (A.airing && document.getElementById('anime-chart-completion')) {
        new Chart(document.getElementById('anime-chart-completion'), {
            ...donutDefaults,
            data: {
                labels: A.airing.labels,
                datasets: [{
                    data: A.airing.values,
                    backgroundColor: [C.teal, C.primary, C.amber, C.red],
                    borderColor: C.card, borderWidth: 2
                }]
            },
            options: {
                ...donutDefaults.options,
                plugins: {
                    ...donutDefaults.options.plugins,
                    tooltip: { callbacks: { label: ctx => `${ctx.label}: ${fmtInt(ctx.raw)} séries` } }
                }
            }
        });
    }

    // Statut de visionnage
    if (A.watch_status && document.getElementById('anime-chart-watch-status')) {
        new Chart(document.getElementById('anime-chart-watch-status'), {
            ...donutDefaults,
            data: {
                labels: A.watch_status.labels,
                datasets: [{
                    data: A.watch_status.values,
                    backgroundColor: [C.textGray, C.primary, C.teal, C.red],
                    borderColor: C.card, borderWidth: 2
                }]
            },
            options: {
                ...donutDefaults.options,
                plugins: {
                    ...donutDefaults.options.plugins,
                    tooltip: { callbacks: { label: ctx => `${ctx.label}: ${fmtInt(ctx.raw)} séries` } }
                }
            }
        });
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  APEXCHARTS — communs
    // ══════════════════════════════════════════════════════════════════════════
    const apexBase = {
        chart: {
            background: 'transparent',
            foreColor: C.text,
            fontFamily: 'Inter, system-ui, sans-serif',
            toolbar: { show: false },
            animations: { enabled: true, speed: 400 }
        },
        theme: { mode: 'dark' },
        grid: { borderColor: C.grid, strokeDashArray: 3 },
        tooltip: { theme: 'dark' },
        dataLabels: { style: { fontSize: '11px', fontWeight: 600 } }
    };

    const charts = {}; // registre pour mises à jour (toggles)

    // ── 4. Treemap auteurs (toggle tomes/séries) ──────────────────────────────
    function treemapSeries(list, metric) {
        return [{
            data: list.map(d => ({ x: d.x, y: metric === 'series' ? d.series : d.y }))
        }];
    }
    if (S.authors && S.authors.length && document.getElementById('treemap-authors')) {
        const authSeriesByName = {};
        S.authors.forEach(a => { authSeriesByName[a.x] = a.series; });
        charts._authSeriesByName = authSeriesByName;
        charts._authMetric = 'series';

        charts.authorsTree = new ApexCharts(document.getElementById('treemap-authors'), {
            ...apexBase,
            chart: { ...apexBase.chart, type: 'treemap', height: 380 },
            series: treemapSeries(S.authors, 'series'),
            legend: { show: false },
            colors: [C.primary],
            plotOptions: {
                treemap: {
                    distributed: true,
                    enableShades: true, shadeIntensity: 0.55,
                    colorScale: { ranges: [] }
                }
            },
            dataLabels: { enabled: true, style: { fontSize: '12px', colors: ['#fff'] } },
            tooltip: {
                theme: 'dark',
                custom: function ({ seriesIndex, dataPointIndex, w }) {
                    const pt = w.config.series[seriesIndex].data[dataPointIndex];
                    const series = authSeriesByName[pt.x] ?? 0;
                    const metric = charts._authMetric || 'series';
                    const main = metric === 'series' ? `${fmtInt(pt.y)} série(s)` : `${fmtInt(pt.y)} tomes`;
                    const second = metric === 'series' ? '' : ` · ${fmtInt(series)} série(s)`;
                    return `<div class="apex-tip"><b>${pt.x}</b><br>${main}${second}</div>`;
                }
            }
        });
        charts.authorsTree.render();
    }

    // ── 4b. Barres top 10 auteurs ─────────────────────────────────────────────
    function horizontalBar(el, list, key, color, unit) {
        const top = list.slice(0, 10);
        const c = new ApexCharts(document.getElementById(el), {
            ...apexBase,
            chart: { ...apexBase.chart, type: 'bar', height: Math.max(220, top.length * 38) },
            series: [{ name: unit, data: top.map(d => d[key]) }],
            xaxis: { categories: top.map(d => d.name || d.x), labels: { style: { colors: C.textGray } } },
            yaxis: { labels: { style: { colors: C.text }, maxWidth: yAxisMaxWidth(220) } },
            colors: [color],
            plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '68%', distributed: false } },
            dataLabels: { enabled: true, textAnchor: 'start', offsetX: 4, style: { colors: ['#fff'] } },
            legend: { show: false }
        });
        c.render();
        return c;
    }
    if (S.authors && S.authors.length) {
        const top = S.authors.slice().sort((a, b) => b.series - a.series).slice(0, 10)
            .map(a => ({ name: a.x, volumes: a.y, series: a.series }));
        charts.authorsBar = horizontalBar('bar-authors', top, 'series', C.primary, 'Séries');
    }

    // ── 5. Treemap + barres éditeurs ──────────────────────────────────────────
    if (S.publishers && S.publishers.length && document.getElementById('treemap-publishers')) {
        // Index nom -> nb séries, pour des tooltips indépendants de l'ordre/de la métrique
        const pubSeriesByName = {};
        S.publishers.forEach(p => { pubSeriesByName[p.x] = p.series; });
        charts._pubSeriesByName = pubSeriesByName;
        charts._pubMetric = 'series';

        charts.publishersTree = new ApexCharts(document.getElementById('treemap-publishers'), {
            ...apexBase,
            chart: { ...apexBase.chart, type: 'treemap', height: 360 },
            series: [{ data: S.publishers.map(d => ({ x: d.x, y: d.series })) }],
            legend: { show: false },
            colors: [C.sky],
            plotOptions: { treemap: { distributed: true, enableShades: true, shadeIntensity: 0.5 } },
            dataLabels: { enabled: true, style: { fontSize: '12px', colors: ['#fff'] } },
            tooltip: { theme: 'dark', custom: function ({ seriesIndex, dataPointIndex, w }) {
                const pt = w.config.series[seriesIndex].data[dataPointIndex];
                const series = pubSeriesByName[pt.x] ?? 0;
                const metric = charts._pubMetric || 'series';
                const main = metric === 'series'
                    ? `${fmtInt(pt.y)} série(s)`
                    : `${fmtInt(pt.y)} tomes`;
                const second = metric === 'series'
                    ? '' // en mode séries, pt.y EST déjà le nb de séries
                    : ` · ${fmtInt(series)} série(s)`;
                return `<div class="apex-tip"><b>${pt.x}</b><br>${main}${second}</div>`;
            } }
        });
        charts.publishersTree.render();
    }
    if (S.publishers && S.publishers.length) {
        const top = S.publishers.slice().sort((a, b) => b.series - a.series).slice(0, 10)
            .map(p => ({ name: p.x, volumes: p.y, series: p.series }));
        charts.publishersBar = horizontalBar('bar-publishers', top, 'series', C.sky, 'Séries');
    }

    // ── 6. Genres (toggle par tomes / par séries) ─────────────────────────────
    if (((S.genres && S.genres.length) || S.genres_none > 0) && document.getElementById('genres-chart')) {
        const NONE_COLOR = C.textGray;
        const el = document.getElementById('genres-chart');

        function buildGenres(metric) {
            const valOf = g => metric === 'series' ? (g.series || 0) : g.volumes;
            const noneVal = metric === 'series' ? (S.genres_none_series || 0) : (S.genres_none || 0);
            const unit = metric === 'series' ? 'séries' : 'tomes';

            // Liste triée selon la métrique + tranche "Sans genre" en fin
            const genreList = (S.genres || []).slice()
                .map(g => ({ name: g.name, volumes: g.volumes, series: g.series || 0 }))
                .sort((a, b) => valOf(b) - valOf(a));
            if (noneVal > 0) {
                genreList.push({ name: 'Sans genre', volumes: S.genres_none || 0, series: S.genres_none_series || 0, _none: true });
            }
            const colorFor = (g, i) => g._none ? NONE_COLOR : rampColor(i);
            const useDonut = genreList.length <= 6;

            if (useDonut) {
                return {
                    ...apexBase,
                    chart: { ...apexBase.chart, type: 'donut', height: 340 },
                    series: genreList.map(valOf),
                    labels: genreList.map(g => g.name),
                    colors: genreList.map((g, i) => colorFor(g, i)),
                    legend: { position: 'bottom', labels: { colors: C.text } },
                    plotOptions: { pie: { donut: { size: '60%' } } },
                    dataLabels: { enabled: true, formatter: (v) => Math.round(v) + '%' },
                    tooltip: { theme: 'dark', y: { formatter: v => `${fmtInt(v)} ${unit}` } }
                };
            }
            // Barres : "Sans genre" reste visible en dernier même au-delà du top 14
            const named = genreList.filter(g => !g._none).slice(0, 14);
            const noneSlice = genreList.filter(g => g._none);
            const top = named.concat(noneSlice);
            return {
                ...apexBase,
                chart: { ...apexBase.chart, type: 'bar', height: Math.max(260, top.length * 30) },
                series: [{ name: metric === 'series' ? 'Séries' : 'Tomes', data: top.map(valOf) }],
                xaxis: { categories: top.map(g => g.name), labels: { style: { colors: C.textGray } } },
                yaxis: { labels: { style: { colors: C.text }, maxWidth: yAxisMaxWidth(200) } },
                colors: top.map((g, i) => colorFor(g, i)),
                plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '70%', distributed: true } },
                dataLabels: { enabled: true, textAnchor: 'start', offsetX: 4, style: { colors: ['#fff'] } },
                legend: { show: false },
                tooltip: { theme: 'dark', custom: function ({ dataPointIndex }) {
                    const g = top[dataPointIndex];
                    return `<div class="apex-tip"><b>${g.name}</b><br>${fmtInt(g.volumes)} tome(s) · ${fmtInt(g.series)} série(s)</div>`;
                } }
            };
        }

        charts.genres = new ApexCharts(el, buildGenres('series'));
        charts.genres.render();
        charts._buildGenres = buildGenres;
    }

    // ── 6b. Catégories (toggle par tomes / par séries) ────────────────────────
    if (S.categories && S.categories.length && document.getElementById('categories-chart')) {
        const el = document.getElementById('categories-chart');

        function buildCategories(metric) {
            const valOf = c => metric === 'series' ? (c.series || 0) : c.volumes;
            const unit = metric === 'series' ? 'séries' : 'tomes';
            const cats = S.categories.slice().sort((a, b) => valOf(b) - valOf(a));

            if (cats.length <= 6) {
                return {
                    ...apexBase,
                    chart: { ...apexBase.chart, type: 'donut', height: 340 },
                    series: cats.map(valOf),
                    labels: cats.map(c => c.name),
                    colors: cats.map((c, i) => rampColor(i)),
                    legend: { position: 'bottom', labels: { colors: C.text } },
                    plotOptions: { pie: { donut: { size: '60%' } } },
                    dataLabels: { enabled: true, formatter: v => Math.round(v) + '%' },
                    tooltip: { theme: 'dark', y: { formatter: v => `${fmtInt(v)} ${unit}` } }
                };
            }
            return {
                ...apexBase,
                chart: { ...apexBase.chart, type: 'bar', height: Math.max(220, cats.length * 32) },
                series: [{ name: metric === 'series' ? 'Séries' : 'Tomes', data: cats.map(valOf) }],
                xaxis: { categories: cats.map(c => c.name), labels: { style: { colors: C.textGray } } },
                yaxis: { labels: { style: { colors: C.text }, maxWidth: yAxisMaxWidth(200) } },
                colors: [C.teal],
                plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '68%' } },
                dataLabels: { enabled: true, textAnchor: 'start', offsetX: 4, style: { colors: ['#fff'] } },
                legend: { show: false },
                tooltip: { theme: 'dark', custom: function ({ dataPointIndex }) {
                    const c = cats[dataPointIndex];
                    return `<div class="apex-tip"><b>${c.name}</b><br>${fmtInt(c.volumes)} tome(s) · ${fmtInt(c.series)} série(s)</div>`;
                } }
            };
        }

        charts.categories = new ApexCharts(el, buildCategories('series'));
        charts.categories.render();
        charts._buildCategories = buildCategories;
    }

    // ── 7. Contributeurs (toggle par tomes / par séries) ──────────────────────
    if (S.contributors && S.contributors.length && document.getElementById('bar-contributors')) {
        function contribOpts(metric) {
            const valOf = c => metric === 'series' ? c.series : c.volumes;
            // Tri selon la métrique puis top 10
            const list = S.contributors.slice().sort((a, b) => valOf(b) - valOf(a)).slice(0, 10);
            return {
                ...apexBase,
                chart: { ...apexBase.chart, type: 'bar', height: Math.max(220, list.length * 34) },
                series: [{ name: metric === 'series' ? 'Séries' : 'Tomes', data: list.map(valOf) }],
                xaxis: { categories: list.map(c => c.name), labels: { style: { colors: C.textGray } } },
                yaxis: { labels: { style: { colors: C.text }, maxWidth: yAxisMaxWidth(220) } },
                colors: [C.pink],
                plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '68%' } },
                dataLabels: { enabled: true, textAnchor: 'start', offsetX: 4, style: { colors: ['#fff'] } },
                legend: { show: false },
                tooltip: { theme: 'dark', custom: function ({ dataPointIndex }) {
                    const c = list[dataPointIndex];
                    return `<div class="apex-tip"><b>${c.name}</b><br>${fmtInt(c.volumes)} tome(s) · ${fmtInt(c.series)} série(s)</div>`;
                } }
            };
        }
        charts.contrib = new ApexCharts(document.getElementById('bar-contributors'), contribOpts('series'));
        charts.contrib.render();
        charts._contribOpts = contribOpts;
    }

    // ── 8. Valeur ─────────────────────────────────────────────────────────────
    if (S.value && S.value.labels && S.value.labels.length && document.getElementById('value-chart')) {
        const valHeight = Math.max(200, S.value.labels.length * 64);
        new ApexCharts(document.getElementById('value-chart'), {
            ...apexBase,
            chart: { ...apexBase.chart, type: 'bar', height: valHeight },
            series: S.value.series,
            xaxis: { categories: S.value.labels, labels: { style: { colors: C.textGray },
                formatter: v => new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(v) } },
            yaxis: { labels: { style: { colors: C.textGray } } },
            colors: [C.primary, C.amber],
            plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '70%' } },
            dataLabels: { enabled: true, textAnchor: 'start', offsetX: 4,
                formatter: v => (v == null ? '' : new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(v)),
                style: { colors: ['#fff'] } },
            legend: { show: true, labels: { colors: C.textGray } },
            tooltip: { theme: 'dark', y: { formatter: v => (v == null ? '—' : new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(v)) } }
        }).render();
    }

    // ── 9. Courbes temporelles ────────────────────────────────────────────────
    // Le premier point de chaque courbe correspond souvent au mois de création
    // de la bibliothèque (import initial de toute la collection d'un coup) :
    // sa valeur est fréquemment démesurée par rapport aux mois suivants et
    // écrase l'échelle du graphique, rendant l'évolution réelle illisible.
    // On l'exclut donc systématiquement de l'affichage (mangas ET animés),
    // sans recalculer les valeurs suivantes : pour les courbes cumulées
    // (growth/reading_growth/watched_growth), la progression à partir du 2e
    // point reste juste, seul le point de départ n'est plus tracé.
    //
    // Largeur minimale réservée à chaque mois sur l'axe des abscisses. Les
    // libellés de mois (« 2024-03 ») sont affichés presque à la verticale
    // (rotate: -80, cf. plus bas) pour rester lisibles même serrés ; ce
    // nombre de pixels par point est le minimum en dessous duquel deux
    // libellés voisins se chevaucheraient malgré cette rotation.
    const MONTH_PX = 40;

    // ApexCharts dessine l'axe des ordonnées DANS le même SVG que la courbe :
    // un conteneur scrollable classique ferait donc défiler l'échelle avec
    // le reste, la faisant disparaître du cadre visible. On construit donc
    // DEUX graphiques par courbe :
    //   - un graphique "axe" (id + '-axis'), étroit et fixe, qui ne montre
    //     que l'échelle Y (courbe transparente, axe X masqué) ;
    //   - le graphique complet, large et scrollable, avec son échelle Y
    //     masquée (yaxis.show:false) pour ne jamais la dupliquer visuellement.
    // Les deux partagent un min/max Y calculés une seule fois et forcés à
    // l'identique (yaxis.min/max), pour que leurs échelles coïncident
    // parfaitement — sans ça, ApexCharts pourrait arrondir chaque graphique
    // différemment et désynchroniser visuellement l'axe fixe de la courbe.
    function lineChart(el, series, name, color, annotation) {
        const container = document.getElementById(el);
        const axisContainer = document.getElementById(el + '-axis');
        if (!container || !series.length) return;
        // Exclut le premier point (mois de création) : ne garde que la suite,
        // pour ne pas fausser l'échelle du graphique avec un import massif.
        const trimmed = series.length > 1 ? series.slice(1) : series;
        if (!trimmed.length) return;

        const values = trimmed.map(p => p.value);
        const dataMin = Math.min(0, ...values);
        const dataMax = Math.max(...values);
        // Une petite marge au-dessus du maximum, comme le ferait ApexCharts
        // automatiquement — mais calculée à la main pour être certain que
        // les deux graphiques (axe + plot) retiennent exactement la même
        // valeur, plutôt que de laisser chacun l'arrondir séparément.
        const yMax = dataMax === dataMin ? dataMin + 1 : dataMax + Math.ceil((dataMax - dataMin) * 0.1);
        const yMin = dataMin;

        // Le rendu effectif (largeur réelle vs mesure du conteneur, qui peut
        // valoir 0 tant que le panel est dans un onglet caché — cf. la
        // vérification hiddenPanel plus bas) est isolé dans doRender() pour
        // être rejouable une fois l'onglet affiché.
        function doRender() {
            container.innerHTML = '';
            if (axisContainer) axisContainer.innerHTML = '';

            const chartWidth = trimmed.length * MONTH_PX;

            const plotOpts = {
                ...apexBase,
                chart: { ...apexBase.chart, type: 'area', height: 280, width: chartWidth, zoom: { enabled: false }, toolbar: { show: false } },
                series: [{ name, data: values }],
                xaxis: {
                    categories: trimmed.map(p => p.month),
                    labels: {
                        style: { colors: C.textGray, fontSize: '11px' },
                        // Quasi-vertical (légèrement oblique) plutôt qu'à 45°,
                        // pour rester lisible mois par mois sans qu'un
                        // libellé n'empiète sur son voisin.
                        rotate: -80,
                        rotateAlways: true,
                        hideOverlappingLabels: false,
                        trim: false
                    }
                },
                yaxis: { min: yMin, max: yMax, show: false },
                stroke: { curve: 'smooth', width: 2.5 },
                fill: { type: 'gradient', gradient: { shadeIntensity: 0.4, opacityFrom: 0.45, opacityTo: 0.05 } },
                colors: [color],
                dataLabels: { enabled: false },
                markers: { size: 0, hover: { size: 4 } },
                grid: { ...apexBase.grid, padding: { left: 4, right: 4 } }
            };
            const chart = new ApexCharts(container, plotOpts);
            chart.render().then(() => {
                // Place le défilement tout à droite (dates les plus
                // récentes), le sens de lecture naturel pour une courbe
                // d'évolution : sans ça, un conteneur scrollable s'ouvre à
                // gauche (mois les plus anciens) par défaut.
                container.scrollLeft = container.scrollWidth;
            }).catch(() => {});

            if (axisContainer) {
                const axisOpts = {
                    ...apexBase,
                    chart: { ...apexBase.chart, type: 'area', height: 280, width: '100%', zoom: { enabled: false }, toolbar: { show: false }, sparkline: { enabled: false } },
                    series: [{ name, data: values }],
                    xaxis: {
                        categories: trimmed.map(p => p.month),
                        // Labels invisibles (opacity:0) plutôt que show:false :
                        // ApexCharts réserve alors la même hauteur de zone
                        // sous l'axe X que le graphique large (qui, lui,
                        // affiche vraiment ses libellés pivotés), ce qui
                        // maintient les deux tracés Y strictement à la même
                        // échelle verticale — show:false aurait réduit cette
                        // zone à zéro et légèrement désaligné les deux axes.
                        labels: { style: { colors: 'transparent' }, rotate: -80, rotateAlways: true },
                        axisTicks: { show: false },
                        axisBorder: { show: false }
                    },
                    yaxis: { min: yMin, max: yMax, show: true, labels: { style: { colors: C.textGray, fontSize: '11px' } } },
                    stroke: { curve: 'smooth', width: 0 },
                    fill: { type: 'solid', opacity: 0 },
                    colors: [color],
                    dataLabels: { enabled: false },
                    markers: { size: 0 },
                    tooltip: { enabled: false },
                    grid: { show: false, padding: { left: 0, right: 0 } },
                    legend: { show: false }
                };
                new ApexCharts(axisContainer, axisOpts).render().catch(() => {});
            }
        }

        // Si le panel est dans un onglet actuellement caché (display:none,
        // cf. .stats-tab-panel), le rendre maintenant produirait un
        // graphique à largeur nulle, cassé de façon persistante même après
        // affichage de l'onglet (limitation connue d'ApexCharts). On diffère
        // alors le rendu jusqu'au premier affichage de cet onglet.
        const hiddenPanel = container.closest('.stats-tab-panel:not(.stats-tab-panel--active)');
        if (hiddenPanel) {
            const onShow = () => {
                if (hiddenPanel.classList.contains('stats-tab-panel--active')) {
                    doRender();
                    observer.disconnect();
                }
            };
            const observer = new MutationObserver(onShow);
            observer.observe(hiddenPanel, { attributes: true, attributeFilter: ['class'] });
        } else {
            doRender();
        }
    }
    lineChart('line-purchases', S.purchases || [], 'Tomes ajoutés', C.primary);
    lineChart('line-growth', S.growth || [], 'Total cumulé', C.teal);
    lineChart('line-reads', S.reads || [], 'Tomes lus', C.pink, false);
    lineChart('line-reading-growth', S.reading_growth || [], 'Total lu cumulé', C.sky, false);

    // ══════════════════════════════════════════════════════════════════════════
    //  APEXCHARTS — Animethèque
    // ══════════════════════════════════════════════════════════════════════════
    const animeCharts = {}; // registre pour les toggles (même principe que `charts` ci-dessus)

    // ── Genres (toggle épisodes / séries) ─────────────────────────────────────
    if (((A.genres && A.genres.length) || A.genres_none > 0) && document.getElementById('anime-genres-chart')) {
        const NONE_COLOR = C.textGray;
        const el = document.getElementById('anime-genres-chart');

        function buildAnimeGenres(metric) {
            const valOf = g => metric === 'series' ? (g.series || 0) : g.volumes;
            const noneVal = metric === 'series' ? (A.genres_none_series || 0) : (A.genres_none || 0);
            const unit = metric === 'series' ? 'séries' : 'épisodes';

            const genreList = (A.genres || []).slice()
                .map(g => ({ name: g.name, volumes: g.volumes, series: g.series || 0 }))
                .sort((a, b) => valOf(b) - valOf(a));
            if (noneVal > 0) {
                genreList.push({ name: 'Sans genre', volumes: A.genres_none || 0, series: A.genres_none_series || 0, _none: true });
            }
            const colorFor = (g, i) => g._none ? NONE_COLOR : rampColor(i);
            const useDonut = genreList.length <= 6;

            if (useDonut) {
                return {
                    ...apexBase,
                    chart: { ...apexBase.chart, type: 'donut', height: 340 },
                    series: genreList.map(valOf),
                    labels: genreList.map(g => g.name),
                    colors: genreList.map((g, i) => colorFor(g, i)),
                    legend: { position: 'bottom', labels: { colors: C.text } },
                    plotOptions: { pie: { donut: { size: '60%' } } },
                    dataLabels: { enabled: true, formatter: (v) => Math.round(v) + '%' },
                    tooltip: { theme: 'dark', y: { formatter: v => `${fmtInt(v)} ${unit}` } }
                };
            }
            const named = genreList.filter(g => !g._none).slice(0, 14);
            const noneSlice = genreList.filter(g => g._none);
            const top = named.concat(noneSlice);
            return {
                ...apexBase,
                chart: { ...apexBase.chart, type: 'bar', height: Math.max(260, top.length * 30) },
                series: [{ name: metric === 'series' ? 'Séries' : 'Épisodes', data: top.map(valOf) }],
                xaxis: { categories: top.map(g => g.name), labels: { style: { colors: C.textGray } } },
                yaxis: { labels: { style: { colors: C.text }, maxWidth: yAxisMaxWidth(200) } },
                colors: top.map((g, i) => colorFor(g, i)),
                plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '70%', distributed: true } },
                dataLabels: { enabled: true, textAnchor: 'start', offsetX: 4, style: { colors: ['#fff'] } },
                legend: { show: false },
                tooltip: { theme: 'dark', custom: function ({ dataPointIndex }) {
                    const g = top[dataPointIndex];
                    return `<div class="apex-tip"><b>${g.name}</b><br>${fmtInt(g.volumes)} épisode(s) · ${fmtInt(g.series)} série(s)</div>`;
                } }
            };
        }

        animeCharts.genres = new ApexCharts(el, buildAnimeGenres('series'));
        animeCharts.genres.render();
        animeCharts._buildGenres = buildAnimeGenres;
    }

    // ── Formats (toggle épisodes / séries) ────────────────────────────────────
    if (A.formats && A.formats.length && document.getElementById('anime-formats-chart')) {
        const el = document.getElementById('anime-formats-chart');

        function buildAnimeFormats(metric) {
            const valOf = f => metric === 'series' ? (f.series || 0) : f.volumes;
            const unit = metric === 'series' ? 'séries' : 'épisodes';
            const fmts = A.formats.slice().sort((a, b) => valOf(b) - valOf(a));

            if (fmts.length <= 6) {
                return {
                    ...apexBase,
                    chart: { ...apexBase.chart, type: 'donut', height: 340 },
                    series: fmts.map(valOf),
                    labels: fmts.map(f => f.name),
                    colors: fmts.map((f, i) => rampColor(i)),
                    legend: { position: 'bottom', labels: { colors: C.text } },
                    plotOptions: { pie: { donut: { size: '60%' } } },
                    dataLabels: { enabled: true, formatter: v => Math.round(v) + '%' },
                    tooltip: { theme: 'dark', y: { formatter: v => `${fmtInt(v)} ${unit}` } }
                };
            }
            return {
                ...apexBase,
                chart: { ...apexBase.chart, type: 'bar', height: Math.max(220, fmts.length * 32) },
                series: [{ name: metric === 'series' ? 'Séries' : 'Épisodes', data: fmts.map(valOf) }],
                xaxis: { categories: fmts.map(f => f.name), labels: { style: { colors: C.textGray } } },
                yaxis: { labels: { style: { colors: C.text }, maxWidth: yAxisMaxWidth(200) } },
                colors: [C.sky],
                plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '68%' } },
                dataLabels: { enabled: true, textAnchor: 'start', offsetX: 4, style: { colors: ['#fff'] } },
                legend: { show: false },
                tooltip: { theme: 'dark', custom: function ({ dataPointIndex }) {
                    const f = fmts[dataPointIndex];
                    return `<div class="apex-tip"><b>${f.name}</b><br>${fmtInt(f.volumes)} épisode(s) · ${fmtInt(f.series)} série(s)</div>`;
                } }
            };
        }

        animeCharts.formats = new ApexCharts(el, buildAnimeFormats('series'));
        animeCharts.formats.render();
        animeCharts._buildFormats = buildAnimeFormats;
    }

    // ── Top studios (toggle épisodes / séries) ────────────────────────────────
    if (A.studios && A.studios.length && document.getElementById('anime-bar-studios')) {
        function studiosOpts(metric) {
            const valOf = s => metric === 'series' ? s.series : s.volumes;
            const list = A.studios.slice().sort((a, b) => valOf(b) - valOf(a)).slice(0, 10);
            return {
                ...apexBase,
                chart: { ...apexBase.chart, type: 'bar', height: Math.max(220, list.length * 34) },
                series: [{ name: metric === 'series' ? 'Séries' : 'Épisodes', data: list.map(valOf) }],
                xaxis: { categories: list.map(s => s.name), labels: { style: { colors: C.textGray } } },
                yaxis: { labels: { style: { colors: C.text }, maxWidth: yAxisMaxWidth(220) } },
                colors: [C.sky],
                plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '68%' } },
                dataLabels: { enabled: true, textAnchor: 'start', offsetX: 4, style: { colors: ['#fff'] } },
                legend: { show: false },
                tooltip: { theme: 'dark', custom: function ({ dataPointIndex }) {
                    const s = list[dataPointIndex];
                    return `<div class="apex-tip"><b>${s.name}</b><br>${fmtInt(s.volumes)} épisode(s) · ${fmtInt(s.series)} série(s)</div>`;
                } }
            };
        }
        animeCharts.studios = new ApexCharts(document.getElementById('anime-bar-studios'), studiosOpts('series'));
        animeCharts.studios.render();
        animeCharts._studiosOpts = studiosOpts;
    }

    // ── Notation ────────────────────────────────────────────────────────────
    if (A.rating && A.rating.values.some(v => v > 0) && document.getElementById('anime-rating-chart')) {
        new ApexCharts(document.getElementById('anime-rating-chart'), {
            ...apexBase,
            chart: { ...apexBase.chart, type: 'donut', height: 300 },
            series: A.rating.values,
            labels: A.rating.labels,
            colors: [C.teal, C.amber, C.red, C.textGray],
            legend: { position: 'bottom', labels: { colors: C.text } },
            plotOptions: { pie: { donut: { size: '58%' } } },
            dataLabels: { enabled: true, formatter: v => Math.round(v) + '%' },
            tooltip: { theme: 'dark', y: { formatter: v => `${fmtInt(v)} série(s)` } }
        }).render();
    }

    // ── Courbes temporelles ────────────────────────────────────────────────────
    lineChart('anime-line-added', A.added || [], 'Épisodes ajoutés', C.sky);
    lineChart('anime-line-growth', A.growth || [], 'Total cumulé', C.teal);
    lineChart('anime-line-watched', A.watched || [], 'Épisodes vus', C.pink, false);
    lineChart('anime-line-watched-growth', A.watched_growth || [], 'Total vu cumulé', C.primary, false);

    // ── Toggles Animethèque ────────────────────────────────────────────────────
    document.querySelectorAll('.toggle-group[data-target="anime-genres"] .toggle-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.toggle-group[data-target="anime-genres"] .toggle-btn').forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');
            const metric = this.dataset.metric;
            const el = document.getElementById('anime-genres-chart');
            if (animeCharts.genres && animeCharts._buildGenres && el) {
                animeCharts.genres.destroy();
                animeCharts.genres = new ApexCharts(el, animeCharts._buildGenres(metric));
                animeCharts.genres.render();
            }
        });
    });

    document.querySelectorAll('.toggle-group[data-target="anime-formats"] .toggle-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.toggle-group[data-target="anime-formats"] .toggle-btn').forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');
            const metric = this.dataset.metric;
            const el = document.getElementById('anime-formats-chart');
            if (animeCharts.formats && animeCharts._buildFormats && el) {
                animeCharts.formats.destroy();
                animeCharts.formats = new ApexCharts(el, animeCharts._buildFormats(metric));
                animeCharts.formats.render();
            }
        });
    });

    document.querySelectorAll('.toggle-group[data-target="anime-studios"] .toggle-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.toggle-group[data-target="anime-studios"] .toggle-btn').forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');
            const metric = this.dataset.metric;
            if (animeCharts.studios && animeCharts._studiosOpts) {
                const valOf = s => metric === 'series' ? s.series : s.volumes;
                const list = A.studios.slice().sort((a, b) => valOf(b) - valOf(a)).slice(0, 10);
                animeCharts.studios.updateOptions({
                    chart: { height: Math.max(220, list.length * 34) },
                    xaxis: { categories: list.map(s => s.name) },
                    series: [{ name: metric === 'series' ? 'Séries' : 'Épisodes', data: list.map(valOf) }],
                    tooltip: { theme: 'dark', custom: function ({ dataPointIndex }) {
                        const s = list[dataPointIndex];
                        return `<div class="apex-tip"><b>${s.name}</b><br>${fmtInt(s.volumes)} épisode(s) · ${fmtInt(s.series)} série(s)</div>`;
                    } }
                });
            }
        });
    });

    // ══════════════════════════════════════════════════════════════════════════
    //  TOGGLES
    // ══════════════════════════════════════════════════════════════════════════
    // Auteurs : tomes / séries
    document.querySelectorAll('.toggle-group[data-target="authors"] .toggle-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.toggle-group[data-target="authors"] .toggle-btn').forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');
            const metric = this.dataset.metric;
            const valOf = d => metric === 'series' ? d.series : d.y;
            charts._authMetric = metric;

            // Re-trier selon la métrique active (du plus grand au plus petit)
            const sorted = S.authors.slice().sort((a, b) => valOf(b) - valOf(a));

            if (charts.authorsTree) {
                charts.authorsTree.updateSeries([{
                    data: sorted.map(d => ({ x: d.x, y: valOf(d) }))
                }]);
            }
            if (charts.authorsBar) {
                const top = sorted.slice(0, 10);
                // updateOptions réordonne les libellés (catégories) + les valeurs ensemble
                charts.authorsBar.updateOptions({
                    xaxis: { categories: top.map(d => d.x) },
                    series: [{ name: metric === 'series' ? 'Séries' : 'Tomes', data: top.map(valOf) }]
                });
            }
        });
    });

    // Éditeurs : tomes / séries
    document.querySelectorAll('.toggle-group[data-target="publishers"] .toggle-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.toggle-group[data-target="publishers"] .toggle-btn').forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');
            const metric = this.dataset.metric;
            const valOf = d => metric === 'series' ? d.series : d.y;
            charts._pubMetric = metric;

            const sorted = S.publishers.slice().sort((a, b) => valOf(b) - valOf(a));

            if (charts.publishersTree) {
                charts.publishersTree.updateSeries([{
                    data: sorted.map(d => ({ x: d.x, y: valOf(d) }))
                }]);
            }
            if (charts.publishersBar) {
                const top = sorted.slice(0, 10);
                charts.publishersBar.updateOptions({
                    xaxis: { categories: top.map(d => d.x) },
                    series: [{ name: metric === 'series' ? 'Séries' : 'Tomes', data: top.map(valOf) }]
                });
            }
        });
    });

    // Contributeurs : par tomes / par séries (top 10 dans chaque cas)
    document.querySelectorAll('.toggle-group[data-target="contributors-view"] .toggle-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.toggle-group[data-target="contributors-view"] .toggle-btn').forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');
            const metric = this.dataset.metric;
            if (charts.contrib && charts._contribOpts) {
                const valOf = c => metric === 'series' ? c.series : c.volumes;
                const list = S.contributors.slice().sort((a, b) => valOf(b) - valOf(a)).slice(0, 10);
                charts.contrib.updateOptions({
                    chart: { height: Math.max(220, list.length * 34) },
                    xaxis: { categories: list.map(c => c.name) },
                    series: [{ name: metric === 'series' ? 'Séries' : 'Tomes', data: list.map(valOf) }],
                    tooltip: { theme: 'dark', custom: function ({ dataPointIndex }) {
                        const c = list[dataPointIndex];
                        return `<div class="apex-tip"><b>${c.name}</b><br>${fmtInt(c.volumes)} tome(s) · ${fmtInt(c.series)} série(s)</div>`;
                    } }
                });
            }
        });
    });

    // Genres : par tomes / par séries (rebuild car le type peut passer de donut à barres)
    document.querySelectorAll('.toggle-group[data-target="genres"] .toggle-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.toggle-group[data-target="genres"] .toggle-btn').forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');
            const metric = this.dataset.metric;
            const el = document.getElementById('genres-chart');
            if (charts.genres && charts._buildGenres && el) {
                charts.genres.destroy();
                charts.genres = new ApexCharts(el, charts._buildGenres(metric));
                charts.genres.render();
            }
        });
    });

    // Catégories : par tomes / par séries
    document.querySelectorAll('.toggle-group[data-target="categories"] .toggle-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.toggle-group[data-target="categories"] .toggle-btn').forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');
            const metric = this.dataset.metric;
            const el = document.getElementById('categories-chart');
            if (charts.categories && charts._buildCategories && el) {
                charts.categories.destroy();
                charts.categories = new ApexCharts(el, charts._buildCategories(metric));
                charts.categories.render();
            }
        });
    });

    // ══════════════════════════════════════════════════════════════════════════
    //  RECHERCHE (réintégrée)
    // ══════════════════════════════════════════════════════════════════════════
    const searchData = window.SEARCH_DATA || [];
    const input = document.getElementById('search-input');
    const sugg = document.getElementById('search-suggestions');
    const btn = document.getElementById('search-button');
    const results = document.getElementById('search-results');

    const norm = s => (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

    if (input) {
        let suggItems = [];   // valeurs courantes des suggestions
        let activeIdx = -1;    // index surligné au clavier

        function renderSuggestions(values) {
            sugg.innerHTML = '';
            suggItems = values;
            activeIdx = -1;
            if (!values.length) { sugg.classList.remove('show'); return; }
            values.forEach((v, i) => {
                const d = document.createElement('div');
                d.textContent = v;
                d.dataset.idx = i;
                d.addEventListener('mouseenter', () => setActive(i));
                d.addEventListener('mousedown', e => {
                    // mousedown plutôt que click pour devancer le blur
                    e.preventDefault();
                    input.value = v;
                    sugg.classList.remove('show');
                    run();
                });
                sugg.appendChild(d);
            });
            sugg.classList.add('show');
        }

        function setActive(i) {
            const items = sugg.querySelectorAll('div');
            items.forEach(el => el.classList.remove('autocomplete-active'));
            activeIdx = i;
            if (i >= 0 && items[i]) {
                items[i].classList.add('autocomplete-active');
                items[i].scrollIntoView({ block: 'nearest' });
            }
        }

        function buildSuggestions(term) {
            const n = norm(term);
            const set = new Set();
            searchData.forEach(s => {
                // `author` porte déjà les studios pour un animé (cf. stats.php,
                // construction de window.SEARCH_DATA) : rien de spécifique à
                // faire ici pour que les deux collections remontent ensemble.
                [s.name, s.author, s.publisher].forEach(v => { if (norm(v).includes(n)) set.add(v); });
                (s.categories || []).forEach(v => { if (norm(v).includes(n)) set.add(v); });
                (s.genres || []).forEach(v => { if (norm(v).includes(n)) set.add(v); });
                (s.other_contributors || []).forEach(v => { if (norm(v).includes(n)) set.add(v); });
                // Titres alternatifs (romaji/anglais/natif/synonymes, animés
                // uniquement) : suggérés comme des noms de série à part entière,
                // pour que taper un titre anglais propose bien la série même si
                // son nom affiché est resté en romaji.
                (s.alt_titles || []).forEach(v => { if (norm(v).includes(n)) set.add(v); });
            });
            return [...set].slice(0, 30);
        }

        input.addEventListener('input', function () {
            const term = this.value.trim();
            if (term.length < 2) { sugg.classList.remove('show'); return; }
            renderSuggestions(buildSuggestions(term));
        });

        // Navigation clavier dans les suggestions + lancement de la recherche
        input.addEventListener('keydown', function (e) {
            const open = sugg.classList.contains('show') && suggItems.length > 0;
            switch (e.key) {
                case 'ArrowDown':
                    if (open) { e.preventDefault(); setActive((activeIdx + 1) % suggItems.length); }
                    break;
                case 'ArrowUp':
                    if (open) { e.preventDefault(); setActive((activeIdx - 1 + suggItems.length) % suggItems.length); }
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (open && activeIdx >= 0) {
                        input.value = suggItems[activeIdx];
                        sugg.classList.remove('show');
                    }
                    run();
                    break;
                case 'Escape':
                    sugg.classList.remove('show');
                    break;
                case 'Tab':
                    if (open && activeIdx >= 0) { input.value = suggItems[activeIdx]; sugg.classList.remove('show'); }
                    break;
            }
        });

        input.addEventListener('focus', function () {
            const term = this.value.trim();
            if (term.length >= 2) renderSuggestions(buildSuggestions(term));
        });

        document.addEventListener('click', e => { if (e.target !== input) sugg.classList.remove('show'); });

        function run() {
            const term = input.value.trim();
            if (term.length < 2) return;
            const n = norm(term);
            const r = {
                series: [], authors: new Set(), publishers: new Set(), studios: new Set(),
                categories: new Set(), genres: new Set(), contributors: new Set()
            };
            searchData.forEach(s => {
                // Le nom affiché OU un titre alternatif (romaji/anglais/natif/
                // synonymes, animés uniquement) suffit à faire remonter la
                // série dans les résultats.
                const altMatch = (s.alt_titles || []).find(t => norm(t).includes(n));
                if (norm(s.name).includes(n) || altMatch) {
                    r.series.push(altMatch && !norm(s.name).includes(n) ? { ...s, _matchedAltTitle: altMatch } : s);
                }
                // Auteurs/éditeurs : notion propre aux mangas (les animés ont
                // author/publisher vides, cf. construction de SEARCH_DATA).
                if (s.type !== 'anime' && norm(s.author).includes(n)) r.authors.add(s.author);
                if (s.type !== 'anime' && norm(s.publisher).includes(n)) r.publishers.add(s.publisher);
                // Studios : le champ `author` d'une entrée animé porte déjà les
                // studios (cf. stats.php). Dimension distincte à l'affichage,
                // pour ne pas mélanger auteurs et studios dans la même liste.
                if (s.type === 'anime' && norm(s.author).includes(n)) r.studios.add(s.author);
                (s.categories || []).forEach(c => { if (norm(c).includes(n)) r.categories.add(c); });
                (s.genres || []).forEach(g => { if (norm(g).includes(n)) r.genres.add(g); });
                (s.other_contributors || []).forEach(c => { if (norm(c).includes(n)) r.contributors.add(c); });
            });

            let html = '';
            const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
            // index.php retombe sur la vue Mangathèque (type=manga) si aucun
            // type n'est précisé (cf. sanitize_series_type() côté PHP) : un
            // résultat animé sans `type=anime` explicite pointe donc vers une
            // vue où la série n'apparaît jamais. Le type est obligatoire ici.
            const link = (q, type) => `index.php?search=${encodeURIComponent(q)}&type=${type === 'anime' ? 'anime' : 'manga'}`;

            // Libellé du statut de publication/diffusion d'une série
            const statusLabel = st => ({
                'terminée': '✅ Terminée',
                'en cours': '⏳ En cours',
                'en pause': '⏸️ En pause',
                'abandonnée': '⛔ Abandonnée'
            }[st] || '⏳ En cours');

            // Badge de type (même registre que partout ailleurs sur le site :
            // window.seriesTypes, alimenté par includes/helpers.php,
            // series_types_for_js() — cf. reviews.js / page-wishlist.php).
            const typeBadge = s => {
                const def = (window.seriesTypes && window.seriesTypes[s.type || 'manga']) || null;
                return def
                    ? `<span class="suggestion-type-badge result-type-badge" style="--type-color:${def.color}">${esc(def.label)}</span>`
                    : '';
            };
            const itemWord = (s, n) => (s.type === 'anime' ? 'épisode' : 'tome') + (n > 1 ? 's' : '');
            const doneWord = s => s.type === 'anime' ? 'vus' : 'lus';

            if (r.series.length) {
                html += `<h4>Séries (${r.series.length})</h4>`;
                r.series.forEach(s => {
                    // Avancement de lecture/visionnage
                    const pct = s.volumes_count > 0 ? Math.round((s.read_count / s.volumes_count) * 100) : 0;
                    const tags = [];
                    tags.push(`${s.read_count}/${s.volumes_count} ${doneWord(s)} (${pct}%)`);
                    if (s.collector_count > 0) tags.push(`${s.collector_count} collector${s.collector_count > 1 ? 's' : ''}`);
                    tags.push(statusLabel(s.status));
                    if (s.complete) tags.push('série complète');
                    if (s.read_elsewhere) tags.push('lue ailleurs');
                    if (s.mature) tags.push('mature');
                    // Trouvé par un titre alternatif plutôt que le nom affiché :
                    // le préciser, sinon le résultat semble sorti de nulle part.
                    if (s._matchedAltTitle) tags.push(`aussi connu comme « ${s._matchedAltTitle} »`);
                    const meta = s.type === 'anime'
                        ? [esc(s.author), `${s.volumes_count} ${itemWord(s, s.volumes_count)}`].filter(Boolean).join(' · ')
                        : `${esc(s.author)} · ${esc(s.publisher)} · ${s.volumes_count} ${itemWord(s, s.volumes_count)}`;
                    html += `<div class="result-item"><strong>${typeBadge(s)}${esc(s.name)}</strong>
                        <span class="result-meta">${meta}</span>
                        <span class="result-sub">${tags.map(esc).join(' · ')}</span>
                        <a class="result-link" href="${link(s.name, s.type)}">Voir →</a></div>`;
                });
            }
            // Regroupement générique par dimension (auteurs, éditeurs, studios,
            // catégories, genres, contributeurs). Chaque ligne agrège séries et
            // épisodes/tomes à travers les DEUX collections quand la dimension
            // leur est commune (catégories, genres) ; les dimensions propres à
            // un seul type (auteurs/éditeurs pour les mangas, studios pour les
            // animés) ne peuvent de toute façon matcher que ce type-là.
            const dim = (title, set, role) => {
                const arr = [...set];
                if (!arr.length) return;
                html += `<h4>${title} (${arr.length})</h4>`;
                arr.forEach(name => {
                    const inSeries = searchData.filter(s =>
                        (role === 'author'      && s.type !== 'anime' && norm(s.author) === norm(name)) ||
                        (role === 'publisher'   && s.type !== 'anime' && norm(s.publisher) === norm(name)) ||
                        (role === 'studio'      && s.type === 'anime' && norm(s.author) === norm(name)) ||
                        (role === 'category'    && (s.categories || []).some(c => norm(c) === norm(name))) ||
                        (role === 'genre'       && (s.genres || []).some(g => norm(g) === norm(name))) ||
                        (role === 'contributor' && (s.other_contributors || []).some(c => norm(c) === norm(name))));
                    const vols = inSeries.reduce((a, s) => a + s.volumes_count, 0);
                    const read = inSeries.reduce((a, s) => a + s.read_count, 0);
                    const pct = vols > 0 ? Math.round((read / vols) * 100) : 0;
                    // Mélange manga/anime au sein d'une même dimension (catégories,
                    // genres) : « élément » reste neutre plutôt que de choisir
                    // arbitrairement tome ou épisode.
                    const mixed = inSeries.some(s => s.type === 'anime') && inSeries.some(s => s.type !== 'anime');
                    const unit  = mixed ? 'élément' + (vols > 1 ? 's' : '') : itemWord(inSeries[0], vols);
                    // Aperçu des séries concernées (max 3), avec badge de type
                    const names = inSeries.slice(0, 3).map(s => `${typeBadge(s)}${esc(s.name)}`).join(', ');
                    const more = inSeries.length > 3 ? `, +${inSeries.length - 3}` : '';
                    // Type vers lequel pointer le lien "Voir" : celui du groupe
                    // s'il est homogène (author/publisher/studio le sont déjà
                    // par construction du filtre ci-dessus) ; en cas de mélange
                    // (catégorie ou genre partagé par les deux collections), le
                    // type le mieux représenté l'emporte plutôt que de risquer
                    // un lien qui n'affiche aucun résultat.
                    const animeCount = inSeries.filter(s => s.type === 'anime').length;
                    const linkType = animeCount * 2 > inSeries.length ? 'anime' : 'manga';
                    html += `<div class="result-item"><strong>${esc(name)}</strong>
                        <span class="result-meta">${inSeries.length} série${inSeries.length > 1 ? 's' : ''} · ${vols} ${unit} · ${read}/${vols} (${pct}%)</span>
                        <span class="result-sub">${names}${more}</span>
                        <a class="result-link" href="${link(name, linkType)}">Voir →</a></div>`;
                });
            };
            dim('Auteurs', r.authors, 'author');
            dim('Éditeurs', r.publishers, 'publisher');
            dim('Studios', r.studios, 'studio');
            dim('Catégories', r.categories, 'category');
            dim('Genres', r.genres, 'genre');
            dim('Contributeurs', r.contributors, 'contributor');

            results.innerHTML = html || '<p style="padding:12px;">Aucun résultat trouvé.</p>';
            results.classList.add('show');
        }

        btn.addEventListener('click', e => { e.preventDefault(); run(); });
    }
});

// ─────────────────────────────────────────────────────────────
// Blocage du défilement de l'arrière-plan quand une modale est ouverte
// (universel : couvre les modales via .modal-active et via display:flex inline)
(function () {
    function anyModalVisible() {
        return Array.from(document.querySelectorAll('.modal')).some(function (modal) {
            return getComputedStyle(modal).display !== 'none';
        });
    }
    function syncScrollLock() {
        document.body.classList.toggle('modal-open', anyModalVisible());
    }
    var observer = new MutationObserver(syncScrollLock);
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
