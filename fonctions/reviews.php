<?php
// ──────────────────────────────────────────────────────────────────────────────
// fonctions/reviews.php
// Gestion des critiques de séries : stockage, CRUD et rendu Markdown sécurisé.
//
// Le Markdown brut saisi par l'admin est stocké tel quel en base (source de
// vérité, ré-éditable). L'affichage se fait TOUJOURS via review_render_markdown()
// qui produit un HTML volontairement restreint et sûr (aucune balise brute de
// l'utilisateur n'est conservée : tout est reconstruit par le parseur).
// ──────────────────────────────────────────────────────────────────────────────

// ── Création de la table (idempotent) ────────────────────────────────────────
function reviews_init_table(): void {
    $db = get_db();
    $db->exec("
        CREATE TABLE IF NOT EXISTS reviews (
            series_id   TEXT PRIMARY KEY REFERENCES series(id) ON DELETE CASCADE,
            content     TEXT NOT NULL DEFAULT '',
            updated_at  TEXT NOT NULL DEFAULT ''
        )
    ");
}

// ── Récupération d'une critique (Markdown brut) ──────────────────────────────
function get_review(string $series_id): ?array {
    reviews_init_table();
    $db   = get_db();
    $stmt = $db->prepare("SELECT series_id, content, updated_at FROM reviews WHERE series_id = ?");
    $stmt->execute([$series_id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return [
        'series_id'  => $row['series_id'],
        'content'    => $row['content'],
        'updated_at' => $row['updated_at'],
    ];
}

// ── Liste des identifiants de séries possédant une critique ──────────────────
function get_review_series_ids(): array {
    reviews_init_table();
    $db = get_db();
    return $db->query("SELECT series_id FROM reviews WHERE content <> ''")
              ->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

// ── Liste des critiques (métadonnées + série associée) ───────────────────────
// Renvoie uniquement les critiques dont la série existe toujours dans $data.
// $data doit contenir TOUTES les séries (mangas + animés) : le filtrage par
// type se fait côté client (page-critiques.php propose « les deux »), cette
// fonction ne doit donc jamais recevoir un tableau déjà cloisonné par
// series_of_type().
function list_reviews(array $data): array {
    reviews_init_table();
    $db   = get_db();
    $rows = $db->query("SELECT series_id, content, updated_at FROM reviews WHERE content <> '' ORDER BY updated_at DESC")->fetchAll();

    // Index des séries par id pour retrouver nom / auteur / image / type
    $by_id = [];
    foreach ($data as $s) {
        $by_id[$s['id']] = $s;
    }

    $result = [];
    foreach ($rows as $r) {
        if (!isset($by_id[$r['series_id']])) continue; // série supprimée : ignorée
        $s = $by_id[$r['series_id']];
        // Sous-titre de carte : auteur pour un manga, studios pour un animé —
        // même rôle d'affichage, source différente selon le type.
        $subtitle = is_anime($s) ? series_studios_text($s) : (string)($s['author'] ?? '');
        $result[] = [
            'series_id'  => $r['series_id'],
            'name'       => $s['name'],
            'type'       => series_type($s),
            'author'     => $subtitle,
            'image'      => series_thumbnail($s),
            'updated_at' => $r['updated_at'],
            'excerpt'    => review_excerpt($r['content'], 160),
        ];
    }
    return $result;
}

// ── Enregistrement / mise à jour d'une critique ──────────────────────────────
// $data sert uniquement à vérifier que la série existe (collection ou lue ailleurs).
function save_review(array $data, string $series_id, string $content): array {
    reviews_init_table();

    $series_id = trim($series_id);
    if ($series_id === '') {
        return ['success' => false, 'message' => 'Aucune série sélectionnée.'];
    }

    // La série doit exister dans la collection.
    $exists = false;
    foreach ($data as $s) {
        if ($s['id'] === $series_id) { $exists = true; break; }
    }
    if (!$exists) {
        return ['success' => false, 'message' => "La série sélectionnée n'existe pas dans votre collection."];
    }

    // Normalisation des fins de ligne + garde-fou de taille (64 Ko de Markdown).
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    if (strlen($content) > 65536) {
        $content = substr($content, 0, 65536);
    }

    if (trim($content) === '') {
        return ['success' => false, 'message' => 'Le contenu de la critique est vide.'];
    }

    $updated_at = date('Y-m-d H:i:s');
    $db = get_db();
    $db->prepare("
        INSERT INTO reviews (series_id, content, updated_at)
        VALUES (?, ?, ?)
        ON CONFLICT(series_id) DO UPDATE SET
            content = excluded.content,
            updated_at = excluded.updated_at
    ")->execute([$series_id, $content, $updated_at]);

    return ['success' => true, 'message' => 'Critique enregistrée.', 'updated_at' => $updated_at];
}

// ── Suppression d'une critique ───────────────────────────────────────────────
function delete_review(string $series_id): array {
    reviews_init_table();
    $series_id = trim($series_id);
    if ($series_id === '') {
        return ['success' => false, 'message' => 'Aucune série sélectionnée.'];
    }
    $db = get_db();
    $db->prepare("DELETE FROM reviews WHERE series_id = ?")->execute([$series_id]);
    return ['success' => true, 'message' => 'Critique supprimée.'];
}

// ── État de lecture/visionnage d'une série (pour les alertes de l'éditeur) ───
// Renvoie 'none' (rien de lu/vu), 'partial' (une partie), 'complete' (tout).
// Un animé au visionnage abandonné compte comme 'partial' : il est cohérent
// d'alerter, exactement comme un manga jamais terminé, plutôt que de laisser
// croire que la série a été vue en intégralité.
function review_reading_state(array $series): string {
    if (is_anime($series)) {
        if (!empty($series['watching_abandoned'])) {
            $episodes = $series['volumes'] ?? [];
            foreach ($episodes as $e) {
                if (($e['status'] ?? '') === 'terminé') return 'partial';
            }
            return 'none';
        }
        switch (anime_watching_status($series)) {
            case 'completed':   return 'complete';
            case 'in_progress': return 'partial';
            default:            return 'none';
        }
    }

    $volumes = $series['volumes'] ?? [];
    if (empty($volumes)) return 'none';
    $total = 0; $read = 0;
    foreach ($volumes as $v) {
        $total++;
        if (($v['status'] ?? '') === 'terminé') $read++;
    }
    if ($read === 0)      return 'none';
    if ($read >= $total)  return 'complete';
    return 'partial';
}

// ──────────────────────────────────────────────────────────────────────────────
// Parseur Markdown → HTML sécurisé (zéro dépendance).
//
// Principe de sécurité : on n'exécute JAMAIS le HTML fourni par l'utilisateur.
// Tout le texte est échappé (htmlspecialchars) AVANT toute construction de
// balises. Seules les balises produites par le parseur lui-même existent dans
// la sortie. Les URL des médias/liens sont validées (schéma http/https + hôtes
// autorisés pour les embeds). Aucun attribut on*, aucun javascript:, aucune
// balise <script>/<style>/<iframe> arbitraire ne peut passer.
// ──────────────────────────────────────────────────────────────────────────────

// Valide une URL http(s) simple et renvoie une version échappée pour attribut,
// ou null si l'URL est refusée.
function review_safe_url(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    // Refuse tout ce qui n'est pas http/https (bloque javascript:, data:, etc.).
    if (!preg_match('#^https?://#i', $url)) return null;
    // Validation structurelle.
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return null;
    // Interdit les guillemets/chevrons résiduels.
    if (preg_match('/["\'<>\s]/', $url)) return null;
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

// Détermine le type d'embed d'une URL et renvoie l'iframe/audio/img/video adéquat,
// ou null si l'URL ne correspond à aucun média embarquable connu.
function review_render_embed(string $rawUrl): ?string {
    $url = trim($rawUrl);
    if (!preg_match('#^https?://#i', $url)) return null;

    $host = parse_url($url, PHP_URL_HOST);
    if ($host === null || $host === false) return null;
    $host = strtolower(preg_replace('/^www\./', '', $host));

    // ── YouTube ──────────────────────────────────────────────────────────────
    if (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'], true)) {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $vid = $q['v'] ?? '';
        if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $vid)) {
            return '<div class="review-embed review-embed--video"><iframe src="https://www.youtube-nocookie.com/embed/'
                 . htmlspecialchars($vid, ENT_QUOTES, 'UTF-8')
                 . '" loading="lazy" allowfullscreen referrerpolicy="no-referrer" '
                 . 'sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"></iframe></div>';
        }
    }
    if ($host === 'youtu.be') {
        $vid = ltrim((string)parse_url($url, PHP_URL_PATH), '/');
        if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $vid)) {
            return '<div class="review-embed review-embed--video"><iframe src="https://www.youtube-nocookie.com/embed/'
                 . htmlspecialchars($vid, ENT_QUOTES, 'UTF-8')
                 . '" loading="lazy" allowfullscreen referrerpolicy="no-referrer" '
                 . 'sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"></iframe></div>';
        }
    }

    // ── Vimeo ────────────────────────────────────────────────────────────────
    if ($host === 'vimeo.com') {
        $path = (string)parse_url($url, PHP_URL_PATH);
        if (preg_match('#/(\d{6,12})#', $path, $m)) {
            return '<div class="review-embed review-embed--video"><iframe src="https://player.vimeo.com/video/'
                 . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')
                 . '" loading="lazy" allowfullscreen referrerpolicy="no-referrer" '
                 . 'sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"></iframe></div>';
        }
    }

    // ── SoundCloud (embed via oEmbed player, URL de piste passée en paramètre) ─
    if ($host === 'soundcloud.com') {
        $safe = review_safe_url($url);
        if ($safe !== null) {
            $embed = 'https://w.soundcloud.com/player/?url=' . rawurlencode($url)
                   . '&color=%23a855f7&auto_play=false&hide_related=true&show_comments=false';
            return '<div class="review-embed review-embed--audio"><iframe src="'
                 . htmlspecialchars($embed, ENT_QUOTES, 'UTF-8')
                 . '" loading="lazy" scrolling="no" allow="autoplay" referrerpolicy="no-referrer" '
                 . 'sandbox="allow-scripts allow-same-origin allow-popups"></iframe></div>';
        }
    }

    // ── Fichiers médias directs (extension dans le chemin) ───────────────────
    $path = strtolower((string)parse_url($url, PHP_URL_PATH));
    $safe = review_safe_url($url);
    if ($safe === null) return null;

    if (preg_match('/\.(jpe?g|png|gif|webp|avif|bmp|svg)$/', $path)) {
        return '<div class="review-embed review-embed--image"><img src="' . $safe
             . '" alt="" loading="lazy" referrerpolicy="no-referrer"></div>';
    }
    if (preg_match('/\.(mp3|ogg|oga|wav|m4a|aac|flac)$/', $path)) {
        return '<div class="review-embed review-embed--audio"><audio controls preload="none" src="'
             . $safe . '"></audio></div>';
    }
    if (preg_match('/\.(mp4|webm|ogv|mov|m4v)$/', $path)) {
        return '<div class="review-embed review-embed--video"><video controls preload="none" src="'
             . $safe . '"></video></div>';
    }

    return null;
}

// Rendu inline (gras, italique, souligné, barré, code, liens, images inline).
// $text est du texte BRUT (non échappé) ; la fonction renvoie du HTML sûr.
function review_render_inline(string $text): string {
    // Jetons protégés : on remplace d'abord les motifs par des marqueurs, on
    // échappe le reste, puis on réinjecte le HTML des jetons. Cela évite qu'un
    // htmlspecialchars ne casse les balises générées.
    $tokens = [];
    $push = function (string $html) use (&$tokens): string {
        $key = "\x00T" . count($tokens) . "\x00";
        $tokens[$key] = $html;
        return $key;
    };

    // Images inline : ![alt](url)
    $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) use ($push) {
        $safe = review_safe_url($m[2]);
        if ($safe === null) return $m[0];
        $alt = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
        return $push('<img class="review-inline-img" src="' . $safe . '" alt="' . $alt . '" loading="lazy" referrerpolicy="no-referrer">');
    }, $text);

    // Liens : [texte](url)
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) use ($push) {
        $safe = review_safe_url($m[2]);
        $label = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
        if ($safe === null) return $label;
        return $push('<a href="' . $safe . '" target="_blank" rel="noopener nofollow ugc">' . $label . '</a>');
    }, $text);

    // Code inline : `code`
    $text = preg_replace_callback('/`([^`]+)`/', function ($m) use ($push) {
        return $push('<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>');
    }, $text);

    // Gras : **texte** ou __texte__
    $text = preg_replace_callback('/\*\*([^*]+)\*\*/', function ($m) use ($push) {
        return $push('<strong>' . review_render_inline($m[1]) . '</strong>');
    }, $text);
    $text = preg_replace_callback('/__([^_]+)__/', function ($m) use ($push) {
        return $push('<strong>' . review_render_inline($m[1]) . '</strong>');
    }, $text);

    // Italique : *texte* ou _texte_
    $text = preg_replace_callback('/\*([^*]+)\*/', function ($m) use ($push) {
        return $push('<em>' . review_render_inline($m[1]) . '</em>');
    }, $text);
    $text = preg_replace_callback('/(?<![A-Za-z0-9])_([^_]+)_(?![A-Za-z0-9])/', function ($m) use ($push) {
        return $push('<em>' . review_render_inline($m[1]) . '</em>');
    }, $text);

    // Barré : ~~texte~~
    $text = preg_replace_callback('/~~([^~]+)~~/', function ($m) use ($push) {
        return $push('<del>' . review_render_inline($m[1]) . '</del>');
    }, $text);

    // Souligné : ++texte++ (extension propre au site, pas de HTML brut accepté)
    $text = preg_replace_callback('/\+\+([^+]+)\+\+/', function ($m) use ($push) {
        return $push('<u>' . review_render_inline($m[1]) . '</u>');
    }, $text);

    // Échappe tout le texte restant (sécurité), puis réinjecte les jetons.
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    if (!empty($tokens)) {
        $text = strtr($text, $tokens);
    }
    return $text;
}

// Rendu complet d'un document Markdown en HTML sûr.
function review_render_markdown(string $md): string {
    // Neutralise les octets nuls (utilisés en interne comme marqueurs de jetons).
    $md = str_replace("\x00", '', $md);
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $lines = explode("\n", $md);

    $html   = [];
    $i      = 0;
    $n      = count($lines);

    // État des listes en cours
    $listStack = []; // pile de 'ul' / 'ol'

    $closeLists = function () use (&$listStack, &$html) {
        while (!empty($listStack)) {
            $html[] = '</' . array_pop($listStack) . '>';
        }
    };

    while ($i < $n) {
        $line = $lines[$i];
        $trim = trim($line);

        // Ligne vide → ferme les listes, saut de paragraphe.
        if ($trim === '') {
            $closeLists();
            $i++;
            continue;
        }

        // Bloc de code ``` … ```
        if (preg_match('/^```/', $trim)) {
            $closeLists();
            $code = [];
            $i++;
            while ($i < $n && !preg_match('/^```/', trim($lines[$i]))) {
                $code[] = $lines[$i];
                $i++;
            }
            $i++; // saute la clôture ```
            $html[] = '<pre class="review-code"><code>'
                    . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8')
                    . '</code></pre>';
            continue;
        }

        // Média sur sa propre ligne : @[média](url)  → embed
        if (preg_match('/^@\[[^\]]*\]\((\S+)\)$/', $trim, $m)) {
            $embed = review_render_embed($m[1]);
            if ($embed !== null) {
                $closeLists();
                $html[] = $embed;
                $i++;
                continue;
            }
            // Sinon on laisse la ligne être traitée comme paragraphe normal.
        }

        // Image seule sur sa propre ligne : ![alt](url) → bloc image centré
        if (preg_match('/^!\[([^\]]*)\]\((\S+)\)$/', $trim, $m)) {
            $safe = review_safe_url($m[2]);
            if ($safe !== null) {
                $closeLists();
                $alt = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                $html[] = '<div class="review-embed review-embed--image"><img src="' . $safe
                        . '" alt="' . $alt . '" loading="lazy" referrerpolicy="no-referrer"></div>';
                $i++;
                continue;
            }
        }

        // Titres # ## ###
        if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
            $closeLists();
            $level = min(6, max(1, strlen($m[1])));
            // On borne aux niveaux h1..h3 demandés visuellement mais on autorise
            // jusqu'à h6 en interne pour la robustesse.
            $tag = 'h' . $level;
            $html[] = "<$tag class=\"review-h\">" . review_render_inline($m[2]) . "</$tag>";
            $i++;
            continue;
        }

        // Citation > …
        if (preg_match('/^>\s?(.*)$/', $trim)) {
            $closeLists();
            $quote = [];
            while ($i < $n && preg_match('/^>\s?(.*)$/', trim($lines[$i]), $qm)) {
                $quote[] = $qm[1];
                $i++;
            }
            $html[] = '<blockquote class="review-quote">'
                    . review_render_inline(implode("\n", $quote))
                    . '</blockquote>';
            continue;
        }

        // Liste à puces - ou *
        if (preg_match('/^[-*]\s+(.*)$/', $trim, $m)) {
            if (empty($listStack) || end($listStack) !== 'ul') {
                $closeLists();
                $html[] = '<ul class="review-list">';
                $listStack[] = 'ul';
            }
            $html[] = '<li>' . review_render_inline($m[1]) . '</li>';
            $i++;
            continue;
        }

        // Liste numérotée 1. 2. …
        if (preg_match('/^\d+\.\s+(.*)$/', $trim, $m)) {
            if (empty($listStack) || end($listStack) !== 'ol') {
                $closeLists();
                $html[] = '<ol class="review-list">';
                $listStack[] = 'ol';
            }
            $html[] = '<li>' . review_render_inline($m[1]) . '</li>';
            $i++;
            continue;
        }

        // Règle horizontale --- ou ***
        if (preg_match('/^(-{3,}|\*{3,})$/', $trim)) {
            $closeLists();
            $html[] = '<hr class="review-hr">';
            $i++;
            continue;
        }

        // Paragraphe : agrège les lignes consécutives non spéciales.
        $closeLists();
        $para = [];
        while ($i < $n) {
            $l = $lines[$i];
            $t = trim($l);
            if ($t === '') break;
            if (preg_match('/^(#{1,6}\s|>\s?|[-*]\s|\d+\.\s|```|@\[[^\]]*\]\(|!\[[^\]]*\]\([^)\s]+\)$|(-{3,}|\*{3,})$)/', $t)) break;
            $para[] = $t;
            $i++;
        }
        // Les sauts de ligne simples internes au paragraphe deviennent <br>.
        $html[] = '<p>' . implode('<br>', array_map('review_render_inline', $para)) . '</p>';
    }

    $closeLists();
    return implode("\n", $html);
}

// Extrait de texte brut (pour la liste des critiques), sans balises Markdown.
function review_excerpt(string $md, int $len = 160): string {
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    // Retire les motifs Markdown les plus visibles.
    $t = preg_replace('/@\[[^\]]*\]\([^)]*\)/', '', $md);
    $t = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $t);
    $t = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $t);
    $t = preg_replace('/[#>*_~`+\-]+/', ' ', $t);
    $t = preg_replace('/\s+/', ' ', $t);
    $t = trim($t);
    if (mb_strlen($t, 'UTF-8') > $len) {
        $t = mb_substr($t, 0, $len, 'UTF-8') . '…';
    }
    return $t;
}
