/* ════════════════════════════════════════════════════════════════════════════
   stats.js — Dashboard statistiques Lengas
   Chart.js  → donuts (statut, temps, complétude)
   ApexCharts → treemaps (auteurs/éditeurs), barres top N, genres, catégories,
                valeur, courbes temporelles
   ════════════════════════════════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', function () {
    const S = window.STATS || {};

    // ── Palette (alignée sur _variables.css) ──────────────────────────────────
    const C = {
        primary:  '#c084fc',
        primary2: '#a855f7',
        teal:     '#34d399',
        sky:      '#38bdf8',
        amber:    '#fbbf24',
        red:      '#f87171',
        pink:     '#e879c6',
        grid:     'rgba(255,255,255,0.06)',
        text:     '#d4d4e8',
        textGray: '#8888a8',
        card:     '#181825',
    };

    const fmtInt = n => new Intl.NumberFormat('fr-FR').format(n);

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

        charts.authorsTree = new ApexCharts(document.getElementById('treemap-authors'), {
            ...apexBase,
            chart: { ...apexBase.chart, type: 'treemap', height: 380 },
            series: treemapSeries(S.authors, 'volumes'),
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
                    const metric = charts._authMetric || 'volumes';
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
            yaxis: { labels: { style: { colors: C.text }, maxWidth: 220 } },
            colors: [color],
            plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '68%', distributed: false } },
            dataLabels: { enabled: true, textAnchor: 'start', offsetX: 4, style: { colors: ['#fff'] } },
            legend: { show: false }
        });
        c.render();
        return c;
    }
    if (S.authors && S.authors.length) {
        const top = S.authors.slice(0, 10).map(a => ({ name: a.x, volumes: a.y, series: a.series }));
        charts.authorsBar = horizontalBar('bar-authors', top, 'volumes', C.primary, 'Tomes');
    }

    // ── 5. Treemap + barres éditeurs ──────────────────────────────────────────
    if (S.publishers && S.publishers.length && document.getElementById('treemap-publishers')) {
        // Index nom -> nb séries, pour des tooltips indépendants de l'ordre/de la métrique
        const pubSeriesByName = {};
        S.publishers.forEach(p => { pubSeriesByName[p.x] = p.series; });
        charts._pubSeriesByName = pubSeriesByName;

        charts.publishersTree = new ApexCharts(document.getElementById('treemap-publishers'), {
            ...apexBase,
            chart: { ...apexBase.chart, type: 'treemap', height: 360 },
            series: [{ data: S.publishers.map(d => ({ x: d.x, y: d.y })) }],
            legend: { show: false },
            colors: [C.sky],
            plotOptions: { treemap: { distributed: true, enableShades: true, shadeIntensity: 0.5 } },
            dataLabels: { enabled: true, style: { fontSize: '12px', colors: ['#fff'] } },
            tooltip: { theme: 'dark', custom: function ({ seriesIndex, dataPointIndex, w }) {
                const pt = w.config.series[seriesIndex].data[dataPointIndex];
                const series = pubSeriesByName[pt.x] ?? 0;
                const metric = charts._pubMetric || 'volumes';
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
        const top = S.publishers.slice(0, 10).map(p => ({ name: p.x, volumes: p.y, series: p.series }));
        charts.publishersBar = horizontalBar('bar-publishers', top, 'volumes', C.sky, 'Tomes');
    }

    // ── 6. Genres ─────────────────────────────────────────────────────────────
    if (((S.genres && S.genres.length) || S.genres_none > 0) && document.getElementById('genres-chart')) {
        // Liste des genres + tranche "Sans genre" en fin (couleur neutre)
        const genreList = (S.genres || []).slice();
        const NONE_COLOR = C.textGray;
        if (S.genres_none > 0) {
            genreList.push({ name: 'Sans genre', volumes: S.genres_none, _none: true });
        }
        const colorFor = (g, i) => g._none ? NONE_COLOR : rampColor(i);

        const useDonut = genreList.length <= 6;
        const el = document.getElementById('genres-chart');
        if (useDonut) {
            new ApexCharts(el, {
                ...apexBase,
                chart: { ...apexBase.chart, type: 'donut', height: 340 },
                series: genreList.map(g => g.volumes),
                labels: genreList.map(g => g.name),
                colors: genreList.map((g, i) => colorFor(g, i)),
                legend: { position: 'bottom', labels: { colors: C.text } },
                plotOptions: { pie: { donut: { size: '60%' } } },
                dataLabels: { enabled: true, formatter: (v) => Math.round(v) + '%' },
                tooltip: { theme: 'dark', y: { formatter: v => `${fmtInt(v)} tomes` } }
            }).render();
        } else {
            // Barres : on garde "Sans genre" visible en dernier même au-delà du top 14
            const named = genreList.filter(g => !g._none).slice(0, 14);
            const noneSlice = genreList.filter(g => g._none);
            const top = named.concat(noneSlice);
            new ApexCharts(el, {
                ...apexBase,
                chart: { ...apexBase.chart, type: 'bar', height: Math.max(260, top.length * 30) },
                series: [{ name: 'Tomes', data: top.map(g => g.volumes) }],
                xaxis: { categories: top.map(g => g.name), labels: { style: { colors: C.textGray } } },
                yaxis: { labels: { style: { colors: C.text }, maxWidth: 200 } },
                colors: top.map((g, i) => colorFor(g, i)),
                plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '70%', distributed: true } },
                dataLabels: { enabled: true, textAnchor: 'start', offsetX: 4, style: { colors: ['#fff'] } },
                legend: { show: false }
            }).render();
        }
    }

    // ── 6b. Catégories ────────────────────────────────────────────────────────
    if (S.categories && S.categories.length && document.getElementById('categories-chart')) {
        const cats = S.categories;
        const el = document.getElementById('categories-chart');
        if (cats.length <= 6) {
            new ApexCharts(el, {
                ...apexBase,
                chart: { ...apexBase.chart, type: 'donut', height: 340 },
                series: cats.map(c => c.volumes),
                labels: cats.map(c => c.name),
                colors: cats.map((c, i) => rampColor(i)),
                legend: { position: 'bottom', labels: { colors: C.text } },
                plotOptions: { pie: { donut: { size: '60%' } } },
                dataLabels: { enabled: true, formatter: v => Math.round(v) + '%' },
                tooltip: { theme: 'dark', y: { formatter: v => `${fmtInt(v)} tomes` } }
            }).render();
        } else {
            new ApexCharts(el, {
                ...apexBase,
                chart: { ...apexBase.chart, type: 'bar', height: Math.max(220, cats.length * 32) },
                series: [{ name: 'Tomes', data: cats.map(c => c.volumes) }],
                xaxis: { categories: cats.map(c => c.name), labels: { style: { colors: C.textGray } } },
                colors: [C.teal],
                plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '68%' } },
                dataLabels: { enabled: true, textAnchor: 'start', offsetX: 4, style: { colors: ['#fff'] } },
                legend: { show: false }
            }).render();
        }
    }

    // ── 7. Contributeurs (toggle top10 / tous) ────────────────────────────────
    if (S.contributors && S.contributors.length && document.getElementById('bar-contributors')) {
        function contribOpts(showAll) {
            const list = showAll ? S.contributors : S.contributors.slice(0, 10);
            return {
                ...apexBase,
                chart: { ...apexBase.chart, type: 'bar', height: Math.max(220, list.length * 34) },
                series: [{ name: 'Tomes', data: list.map(c => c.volumes) }],
                xaxis: { categories: list.map(c => c.name), labels: { style: { colors: C.textGray } } },
                yaxis: { labels: { style: { colors: C.text }, maxWidth: 220 } },
                colors: [C.pink],
                plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '68%' } },
                dataLabels: { enabled: true, textAnchor: 'start', offsetX: 4, style: { colors: ['#fff'] } },
                legend: { show: false }
            };
        }
        charts.contrib = new ApexCharts(document.getElementById('bar-contributors'), contribOpts(false));
        charts.contrib.render();
        charts._contribOpts = contribOpts;
    }

    // ── 8. Valeur ─────────────────────────────────────────────────────────────
    if (S.value && document.getElementById('value-chart')) {
        new ApexCharts(document.getElementById('value-chart'), {
            ...apexBase,
            chart: { ...apexBase.chart, type: 'bar', height: 200 },
            series: [{ name: 'Valeur', data: S.value.values }],
            xaxis: { categories: S.value.labels, labels: { style: { colors: C.textGray } } },
            colors: [C.amber],
            plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '55%', distributed: true } },
            colors: [C.primary, C.amber],
            dataLabels: { enabled: true, textAnchor: 'start', offsetX: 4,
                formatter: v => new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(v),
                style: { colors: ['#fff'] } },
            legend: { show: false },
            tooltip: { theme: 'dark', y: { formatter: v => new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(v) } }
        }).render();
    }

    // ── 9. Courbes temporelles ────────────────────────────────────────────────
    function lineChart(el, series, name, color) {
        if (!document.getElementById(el) || !series.length) return;
        new ApexCharts(document.getElementById(el), {
            ...apexBase,
            chart: { ...apexBase.chart, type: 'area', height: 280, zoom: { enabled: false } },
            series: [{ name, data: series.map(p => p.value) }],
            xaxis: { categories: series.map(p => p.month), labels: { style: { colors: C.textGray }, rotate: -45, rotateAlways: false, hideOverlappingLabels: true } },
            stroke: { curve: 'smooth', width: 2.5 },
            fill: { type: 'gradient', gradient: { shadeIntensity: 0.4, opacityFrom: 0.45, opacityTo: 0.05 } },
            colors: [color],
            dataLabels: { enabled: false },
            markers: { size: 0, hover: { size: 4 } }
        }).render();
    }
    lineChart('line-purchases', S.purchases || [], 'Tomes ajoutés', C.primary);
    lineChart('line-growth', S.growth || [], 'Total cumulé', C.teal);

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

    // Contributeurs : top10 / tous
    document.querySelectorAll('.toggle-group[data-target="contributors-view"] .toggle-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.toggle-group[data-target="contributors-view"] .toggle-btn').forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');
            const showAll = this.dataset.view === 'all';
            if (charts.contrib && charts._contribOpts) {
                const list = showAll ? S.contributors : S.contributors.slice(0, 10);
                charts.contrib.updateOptions({
                    chart: { height: Math.max(220, list.length * 34) },
                    xaxis: { categories: list.map(c => c.name) },
                    series: [{ name: 'Tomes', data: list.map(c => c.volumes) }]
                });
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
        input.addEventListener('input', function () {
            const term = this.value.trim();
            if (term.length < 2) { sugg.classList.remove('show'); return; }
            const n = norm(term);
            const set = new Set();
            searchData.forEach(s => {
                [s.name, s.author, s.publisher].forEach(v => { if (norm(v).includes(n)) set.add(v); });
                (s.categories || []).forEach(v => { if (norm(v).includes(n)) set.add(v); });
                (s.genres || []).forEach(v => { if (norm(v).includes(n)) set.add(v); });
                (s.other_contributors || []).forEach(v => { if (norm(v).includes(n)) set.add(v); });
            });
            sugg.innerHTML = '';
            if (set.size) {
                [...set].slice(0, 30).forEach(v => {
                    const d = document.createElement('div');
                    d.textContent = v;
                    d.addEventListener('click', () => { input.value = v; sugg.classList.remove('show'); });
                    sugg.appendChild(d);
                });
                sugg.classList.add('show');
            } else sugg.classList.remove('show');
        });

        document.addEventListener('click', e => { if (e.target !== input) sugg.classList.remove('show'); });

        function run() {
            const term = input.value.trim();
            if (term.length < 2) return;
            const n = norm(term);
            const r = { series: [], authors: new Set(), publishers: new Set(), categories: new Set(), genres: new Set(), contributors: new Set() };
            searchData.forEach(s => {
                if (norm(s.name).includes(n)) r.series.push(s);
                if (norm(s.author).includes(n)) r.authors.add(s.author);
                if (norm(s.publisher).includes(n)) r.publishers.add(s.publisher);
                (s.categories || []).forEach(c => { if (norm(c).includes(n)) r.categories.add(c); });
                (s.genres || []).forEach(g => { if (norm(g).includes(n)) r.genres.add(g); });
                (s.other_contributors || []).forEach(c => { if (norm(c).includes(n)) r.contributors.add(c); });
            });

            let html = '';
            const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
            const link = q => `index.php?search=${encodeURIComponent(q)}`;

            if (r.series.length) {
                html += `<h4>Séries (${r.series.length})</h4>`;
                r.series.forEach(s => {
                    html += `<div class="result-item"><strong>${esc(s.name)}</strong>
                        <span class="result-meta">${esc(s.author)} · ${esc(s.publisher)} · ${s.volumes_count} tomes</span>
                        <a class="result-link" href="${link(s.name)}">Voir →</a></div>`;
                });
            }
            const dim = (title, set) => {
                const arr = [...set];
                if (!arr.length) return;
                html += `<h4>${title} (${arr.length})</h4>`;
                arr.forEach(name => {
                    const inSeries = searchData.filter(s =>
                        norm(s.author) === norm(name) || norm(s.publisher) === norm(name) ||
                        (s.categories || []).some(c => norm(c) === norm(name)) ||
                        (s.genres || []).some(g => norm(g) === norm(name)) ||
                        (s.other_contributors || []).some(c => norm(c) === norm(name)));
                    const vols = inSeries.reduce((a, s) => a + s.volumes_count, 0);
                    html += `<div class="result-item"><strong>${esc(name)}</strong>
                        <span class="result-meta">${inSeries.length} série(s) · ${vols} tomes</span>
                        <a class="result-link" href="${link(name)}">Voir →</a></div>`;
                });
            };
            dim('Auteurs', r.authors);
            dim('Éditeurs', r.publishers);
            dim('Catégories', r.categories);
            dim('Genres', r.genres);
            dim('Contributeurs', r.contributors);

            results.innerHTML = html || '<p style="padding:12px;">Aucun résultat trouvé.</p>';
            results.classList.add('show');
        }

        btn.addEventListener('click', e => { e.preventDefault(); run(); });
        input.addEventListener('keypress', e => { if (e.key === 'Enter') { e.preventDefault(); run(); } });
    }
});
