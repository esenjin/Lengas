<?php
// ────────────────────────────────────────────────────────────────────────────
// fonctions/tools/grouping.php — Outil « Groupage de licences »
//
// Suggère des regroupements de séries SANS LICENCE qui semblent appartenir à
// la même œuvre (ex. le manga et la saison animée d'un même titre), à partir
// d'une similarité de titres (nom + titres alternatifs Anilist pour les
// animés) et d'un signal secondaire (auteur commun entre deux mangas, studio
// commun entre deux animés).
//
// Rien n'est automatique : cet outil ne fait que PROPOSER des groupes, dont
// la validation (création/rattachement à une licence) passe toujours par les
// fonctions déjà existantes de fonctions/licenses.php (create_license(),
// add_series_to_license()). Aucune nouvelle table, aucune persistance des
// suggestions ignorées : tout est recalculé à chaque analyse, à la demande,
// selon le seuil choisi par l'utilisateur.
// ────────────────────────────────────────────────────────────────────────────

// ── Normalisation d'un titre pour comparaison ────────────────────────────────
// Minuscules, accents retirés, ponctuation retirée, quelques articles vides
// en tête (FR/EN) retirés, mots génériques de titres d'anime/manga retirés,
// espaces compressés. Volontairement simple : le but n'est pas une
// normalisation linguistique parfaite, juste de neutraliser les écarts les
// plus courants entre deux formulations d'un même titre.
function grouping_normalize_title(string $title): string {
    $title = mb_strtolower(trim($title));
    if ($title === '') return '';

    // Compte les caractères "de contenu" (lettres/chiffres, tous alphabets)
    // AVANT translitération, pour pouvoir détecter ensuite une perte massive.
    preg_match_all('/[\p{L}\p{N}]/u', $title, $before_matches);
    $before_count = count($before_matches[0]);

    // Translittération des accents (Frieren, Bocchi… restent inchangés, mais
    // couvre les titres français/anglais accentués).
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);

    // ── Garde-fou : alphabets non-latins (grec, arabe, hébreu, chinois,
    // cyrillique, thaï…) ──────────────────────────────────────────────────
    // iconv(..., 'ASCII//TRANSLIT//IGNORE', ...) ne sait rien faire de ces
    // caractères et les IGNORE silencieusement plutôt que de renvoyer une
    // erreur : un titre grec entier comme « Ο Μεγάλος Υποκριτής » peut ainsi
    // se retrouver réduit à un résidu d'une seule lettre insignifiante (« u »
    // provenant d'un accent isolé mal interprété), qui matche alors par pur
    // hasard n'importe quel autre titre réduit au même résidu. On compare le
    // nombre de caractères de contenu avant/après : une perte de plus de 40%
    // signale une translitération non fiable, la variante est alors écartée
    // (chaîne vide) plutôt que comparée sur un résidu trompeur.
    if ($translit !== false && $translit !== '') {
        preg_match_all('/[a-z0-9]/', $translit, $after_matches);
        $after_count = count($after_matches[0]);
        if ($before_count > 0 && ($after_count / $before_count) < 0.6) {
            return '';
        }
        $title = $translit;
    } elseif ($before_count > 0) {
        // iconv a échoué ou tout supprimé : titre non-latin non exploitable.
        return '';
    }

    // Ponctuation et symboles → espace (garde lettres, chiffres, espaces).
    $title = preg_replace('/[^a-z0-9\s]/u', ' ', $title);

    // ── Mots génériques retirés (n'importe où dans le titre) ────────────────
    // Ces mots sont si fréquents dans les titres d'anime/manga (formats de
    // diffusion, indicateurs de saison/support) qu'ils font grimper
    // artificiellement le score de similar_text() entre deux séries SANS
    // AUCUN rapport, dès lors que leurs titres sont courts (ex. « CITY THE
    // ANIMATION » et « Ping Pong THE ANIMATION » partagent ~18 caractères de
    // suffixe générique, suffisant pour dépasser 70% alors que « CITY » et
    // « Ping Pong » n'ont rien de commun). On les retire complètement plutôt
    // que de les laisser peser dans la comparaison.
    $generic_words = [
        'the animation', 'the movie', 'the series',
        'animation', 'movie', 'special', 'specials',
        'ova', 'oad', 'ona', 'tv', 'season', 'saison',
        'part', 'partie', 'cour', 'final', 'complete',
    ];
    foreach ($generic_words as $word) {
        $title = preg_replace('/\b' . preg_quote($word, '/') . '\b/u', ' ', $title);
    }

    // Numéros de saison/partie isolés (ex. « season 2 » déjà couvert
    // ci-dessus a laissé le « 2 » : un chiffre seul entre espaces n'est pas
    // retiré ici, il reste comparable — un vrai numéro de saison différencie
    // deux séries plutôt que de fausser leur ressemblance).

    // Articles vides en tête de titre.
    $title = preg_replace('/^(the|a|an|le|la|les|l)\s+/u', '', $title);

    // Espaces multiples → un seul, trim.
    $title = trim(preg_replace('/\s+/', ' ', $title));

    return $title;
}

// ── Variantes de titres comparables pour une série ───────────────────────────
// Manga : uniquement son nom. Anime : nom + alt_titles (romaji/english/
// native/synonymes), déjà décodés en tableau par load_data(). Toujours au
// moins une entrée (le nom), jamais de tableau vide. Chaque variante est
// accompagnée d'un indicateur is_primary (true seulement pour le nom
// principal de la série).
function grouping_title_variants(array $series): array {
    $variants = [['text' => (string)($series['name'] ?? ''), 'is_primary' => true]];

    if (is_anime($series)) {
        $alt = $series['alt_titles'] ?? [];
        if (!is_array($alt)) $alt = [];
        foreach ($alt as $t) {
            $t = trim((string)$t);
            if ($t !== '') $variants[] = ['text' => $t, 'is_primary' => false];
        }
    }

    // Dédoublonnage en conservant l'ordre (le principal reste prioritaire
    // s'il apparaît aussi comme synonyme).
    $seen = [];
    $out  = [];
    foreach ($variants as $v) {
        $text = trim($v['text']);
        if ($text === '' || isset($seen[$text])) continue;
        $seen[$text] = true;
        $out[] = ['text' => $text, 'is_primary' => $v['is_primary']];
    }
    return $out;
}

// ── Mots vides à ignorer dans la comparaison par mots ────────────────────────
// Particules japonaises très fréquentes en romaji (no, wa, wo, ga, ni, de,
// to, ha…) et quelques mots de liaison anglais courants. Aucune valeur
// distinctive : « Kimi ni Todoke » et « Kimi to Boku » ne partagent RIEN de
// significatif, mais si on les compare caractère à caractère, le « kimi »
// commun (+ la structure très similaire de titres japonais courts) suffit à
// pousser un score de similarité brut au-dessus de n'importe quel seuil
// raisonnable. On neutralise ces mots avant toute comparaison.
function grouping_stopwords(): array {
    return [
        'no', 'wa', 'wo', 'ga', 'ni', 'de', 'to', 'ha', 'o', 'na', 'da',
        'and', 'of', 'in', 'on', 'at', 'for', 'with',
    ];
}

// ── Ensemble de mots significatifs d'un titre déjà normalisé ─────────────────
function grouping_words_set(string $normalized): array {
    if ($normalized === '') return [];
    $stopwords = grouping_stopwords();
    $words = array_filter(explode(' ', $normalized), fn($w) => $w !== '' && !in_array($w, $stopwords, true));
    return array_values(array_unique($words));
}

// ── Indice de Jaccard (0-100) entre deux ensembles de mots ───────────────────
// |intersection| / |union|. Contrairement à similar_text() (comparaison
// caractère à caractère), un mot entier doit être partagé pour compter : deux
// titres qui n'ont en commun qu'un fragment de lettres au milieu d'un mot
// différent ne se rapprochent pas artificiellement.
function grouping_jaccard(array $wordsA, array $wordsB): float {
    if (empty($wordsA) || empty($wordsB)) return 0.0;
    $intersection = count(array_intersect($wordsA, $wordsB));
    $union        = count(array_unique(array_merge($wordsA, $wordsB)));
    if ($union === 0) return 0.0;
    return ($intersection / $union) * 100.0;
}

// ── Score de similarité (0-100) entre deux titres déjà normalisés ────────────
// Jaccard (mots entiers) pèse l'essentiel du score (85%) ; similar_text()
// (caractère à caractère) ne pèse que 15% et sert uniquement à départager des
// titres déjà proches par leurs mots (fautes de frappe, ordre différent) —
// jamais assez à lui seul pour faire basculer une paire sans mot commun
// au-dessus d'un seuil raisonnable.
function grouping_normalized_title_score(string $a, string $b): float {
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 100.0;

    $wordsA = grouping_words_set($a);
    $wordsB = grouping_words_set($b);
    $jaccard = grouping_jaccard($wordsA, $wordsB);

    similar_text($a, $b, $char_pct);

    return min(100.0, $jaccard * 0.85 + $char_pct * 0.15);
}

// ── Longueur minimale (caractères hors espaces) pour qu'un titre soit ───────
// comparable de façon fiable. Un résidu de 1-3 caractères (acronyme comme
// « S;G » → « s g », ou artefact de translitération) matche trop facilement
// n'importe quel autre titre par un seul mot ou fragment isolé — Jaccard sur
// un ensemble d'un seul mot très court n'est pas fiable. Ce plancher n'est
// appliqué qu'aux titres ALTERNATIFS (synonymes, alt_titles) : le nom
// PRINCIPAL d'une série reste toujours comparable même s'il est court
// (« K », « GTO », « AIR »), sinon ces séries ne pourraient plus jamais être
// suggérées du tout, y compris entre elles.
const GROUPING_MIN_TITLE_LENGTH = 3;

// ── Score de similarité (0-100) entre deux séries ────────────────────────────
// Maximum, sur toutes les paires de variantes normalisées, du score combiné
// Jaccard + similar_text (cf. grouping_normalized_title_score()). Les
// variantes alternatives trop courtes (< GROUPING_MIN_TITLE_LENGTH) sont
// ignorées ; le nom principal reste toujours utilisable en dernier recours.
function grouping_title_similarity(array $seriesA, array $seriesB): float {
    $variantsA = grouping_title_variants($seriesA);
    $variantsB = grouping_title_variants($seriesB);

    $best = 0.0;
    foreach ($variantsA as $va) {
        $a = grouping_normalize_title($va['text']);
        if ($a === '') continue;
        $len_a = strlen(str_replace(' ', '', $a));
        if (!$va['is_primary'] && $len_a < GROUPING_MIN_TITLE_LENGTH) continue;

        foreach ($variantsB as $vb) {
            $b = grouping_normalize_title($vb['text']);
            if ($b === '') continue;
            $len_b = strlen(str_replace(' ', '', $b));
            if (!$vb['is_primary'] && $len_b < GROUPING_MIN_TITLE_LENGTH) continue;

            $score = grouping_normalized_title_score($a, $b);
            if ($score > $best) $best = $score;
            if ($best >= 100.0) return 100.0; // égalité exacte déjà trouvée
        }
    }
    return $best;
}

// ── Pré-calcul des données normalisées d'une série (performance) ────────────
// Calculé UNE SEULE FOIS par série avant toute comparaison de paires, plutôt
// que re-normalisé à chaque paire testée (qui répétait le même travail
// O(n) fois pour chaque série sur une collection de n séries). Renvoie :
//   - 'variants' : [['norm' => string normalisée, 'words' => string[],
//                    'is_primary' => bool], ...] pour les variantes
//                  suffisamment longues (nom principal toujours inclus)
//   - 'all_words' : union de tous les mots de toutes les variantes, pour
//                   l'indexation par mot (préfiltre rapide des paires)
//   - 'secondary' : auteur normalisé (manga) ou liste de studios normalisés
//                   (anime), pour le bonus secondaire
function grouping_precompute(array $series): array {
    $variants = [];
    $all_words = [];

    foreach (grouping_title_variants($series) as $v) {
        $norm = grouping_normalize_title($v['text']);
        if ($norm === '') continue;
        $len = strlen(str_replace(' ', '', $norm));
        if (!$v['is_primary'] && $len < GROUPING_MIN_TITLE_LENGTH) continue;

        $words = grouping_words_set($norm);
        $variants[] = ['norm' => $norm, 'words' => $words, 'is_primary' => $v['is_primary']];
        foreach ($words as $w) $all_words[$w] = true;
    }

    $secondary = [];
    if (is_anime($series)) {
        $studios = is_array($series['studios'] ?? null) ? $series['studios'] : [];
        foreach ($studios as $s) {
            $n = grouping_normalize_title($s);
            if ($n !== '') $secondary[] = $n;
        }
    } else {
        $author = grouping_normalize_title((string)($series['author'] ?? ''));
        if ($author !== '') $secondary[] = $author;
    }

    return [
        'series'     => $series,
        'variants'   => $variants,
        'all_words'  => array_keys($all_words),
        'secondary'  => $secondary,
    ];
}

// ── Score de similarité (0-100) entre deux séries PRÉ-CALCULÉES ─────────────
// Même logique que grouping_title_similarity(), mais opère directement sur
// les structures déjà normalisées par grouping_precompute() : aucun appel à
// iconv()/preg_replace() ici, tout le travail coûteux a déjà été fait une
// seule fois par série en amont.
function grouping_precomputed_title_similarity(array $pa, array $pb): float {
    $best = 0.0;
    foreach ($pa['variants'] as $va) {
        foreach ($pb['variants'] as $vb) {
            if ($va['norm'] === $vb['norm']) return 100.0;
            $jaccard = grouping_jaccard($va['words'], $vb['words']);
            // Préfiltre : si Jaccard est nul (aucun mot commun), similar_text()
            // seul (poids 15%) ne peut de toute façon jamais dépasser un score
            // significatif pour des titres de longueur normale — on saute son
            // calcul (le plus coûteux de la fonction) dans ce cas très fréquent.
            if ($jaccard == 0.0) {
                continue;
            }
            similar_text($va['norm'], $vb['norm'], $char_pct);
            $score = min(100.0, $jaccard * 0.85 + $char_pct * 0.15);
            if ($score > $best) $best = $score;
            if ($best >= 100.0) return 100.0;
        }
    }
    return $best;
}

// ── Bonus secondaire entre deux séries PRÉ-CALCULÉES ─────────────────────────
function grouping_precomputed_secondary_bonus(array $pa, array $pb): float {
    $bonus = 10.0;
    $animeA = is_anime($pa['series']);
    $animeB = is_anime($pb['series']);
    if ($animeA !== $animeB) return 0.0; // manga vs anime : jamais de bonus

    foreach ($pa['secondary'] as $s) {
        if ($s !== '' && in_array($s, $pb['secondary'], true)) return $bonus;
    }
    return 0.0;
}

// ── Score combiné (titre + bonus) entre deux séries PRÉ-CALCULÉES ───────────
function grouping_precomputed_pair_score(array $pa, array $pb): float {
    $score = grouping_precomputed_title_similarity($pa, $pb) + grouping_precomputed_secondary_bonus($pa, $pb);
    return min(100.0, $score);
}


// Ne compare jamais un auteur à un studio (signaux non comparables entre les
// deux types). Renvoie un bonus de points de score, 0 si non applicable ou
// si aucun signal partagé.
function grouping_secondary_bonus(array $seriesA, array $seriesB): float {
    $bonus = 10.0;

    if (!is_anime($seriesA) && !is_anime($seriesB)) {
        $a = grouping_normalize_title((string)($seriesA['author'] ?? ''));
        $b = grouping_normalize_title((string)($seriesB['author'] ?? ''));
        return ($a !== '' && $a === $b) ? $bonus : 0.0;
    }

    if (is_anime($seriesA) && is_anime($seriesB)) {
        $studiosA = is_array($seriesA['studios'] ?? null) ? $seriesA['studios'] : [];
        $studiosB = is_array($seriesB['studios'] ?? null) ? $seriesB['studios'] : [];
        $normA = array_map('grouping_normalize_title', $studiosA);
        $normB = array_map('grouping_normalize_title', $studiosB);
        foreach ($normA as $s) {
            if ($s !== '' && in_array($s, $normB, true)) return $bonus;
        }
        return 0.0;
    }

    // Un manga et un anime : signal non comparable, aucun bonus.
    return 0.0;
}

// ── Score combiné (titre + bonus), plafonné à 100 ────────────────────────────
function grouping_pair_score(array $seriesA, array $seriesB): float {
    $score = grouping_title_similarity($seriesA, $seriesB) + grouping_secondary_bonus($seriesA, $seriesB);
    return min(100.0, $score);
}

// ── Repère de calibrage : score moyen des paires au sein des licences ───────
// existantes (séries à 2 membres ou plus). Sert uniquement à orienter le
// curseur de seuil de l'utilisateur ; ne modifie rien à l'algorithme.
// Renvoie null si aucune licence existante n'a au moins 2 séries membres.
function grouping_calibration_reference(array $data): ?float {
    if (!function_exists('list_licenses')) return null;

    $licenses = list_licenses($data);
    if (empty($licenses)) return null;

    $by_id = [];
    foreach ($data as $s) {
        $by_id[$s['id']] = $s;
    }

    $scores = [];
    foreach ($licenses as $lic) {
        $detail = get_license_detail($data, $lic['id']);
        $members = $detail['series'] ?? [];
        if (count($members) < 2) continue;

        // get_license_detail() renvoie des séries déjà décorées pour
        // l'affichage (champs allégés) : on retrouve les séries complètes
        // par id pour appliquer exactement le même calcul de score.
        $full_members = [];
        foreach ($members as $m) {
            if (isset($by_id[$m['id']])) $full_members[] = $by_id[$m['id']];
        }
        if (count($full_members) < 2) continue;

        for ($i = 0; $i < count($full_members); $i++) {
            for ($j = $i + 1; $j < count($full_members); $j++) {
                $scores[] = grouping_pair_score($full_members[$i], $full_members[$j]);
            }
        }
    }

    if (empty($scores)) return null;
    return round(array_sum($scores) / count($scores), 1);
}

// ── Meilleur score d'une série (ou d'un groupe de séries) contre chaque ─────
// licence existante ayant au moins un membre. Pour une licence donnée, le
// score retenu est le MEILLEUR obtenu parmi tous ses membres (cf. exemple
// Frieren : le manga ET l'anime S1 sont membres, seule l'anime S1 donnera un
// bon score contre « Sousou no Frieren 2 », c'est celui-là qui doit compter).
//
// $candidates : une ou plusieurs séries PRÉ-CALCULÉES à tester ensemble (un
// cluster entier compte comme candidat unique). $licenses_with_members :
// licences dont les membres sont déjà pré-calculés (cf. find_license_
// grouping_suggestions()).
//
// Renvoie ['license_id', 'license_name', 'score'] pour la meilleure licence
// trouvée au-dessus du seuil, ou null si aucune licence ne correspond.
function grouping_best_matching_license(array $candidates, array $licenses_with_members, float $threshold): ?array {
    $best = null;

    foreach ($licenses_with_members as $lic) {
        $lic_score = 0.0;
        foreach ($candidates as $cand) {
            foreach ($lic['members'] as $member) {
                $score = grouping_precomputed_pair_score($cand, $member);
                if ($score > $lic_score) $lic_score = $score;
            }
        }
        if ($lic_score >= $threshold && ($best === null || $lic_score > $best['score'])) {
            $best = [
                'license_id'   => $lic['id'],
                'license_name' => $lic['name'],
                'score'        => $lic_score,
            ];
        }
    }

    return $best;
}



// ── Suggestions de groupes ───────────────────────────────────────────────────
// $data : collection complète (mangas + animés), comme list_reviews()/
// list_licenses(). $threshold : score minimal (0-100) pour qu'une paire (ou
// qu'un match série↔licence) soit retenu(e). Renvoie une liste de groupes :
//
//   [ 'type'         => 'existing' | 'new',
//     'license_id'   => string  (uniquement si type === 'existing')
//     'license_name' => string  (uniquement si type === 'existing')
//     'series'       => [ ['id','name','type','detail','image'], ... ],
//     'score'        => float ]
//
// triée par score décroissant.
//
// Logique en deux passes :
//   1. Les séries sans licence sont d'abord regroupées en clusters entre
//      elles (union-find, comme avant : A~B et B~C fusionnent en {A,B,C}).
//   2. Chaque cluster (y compris les séries isolées, considérées comme un
//      cluster à un seul membre) est ensuite comparé à chaque licence
//      existante ; si le meilleur score dépasse le seuil, le cluster ENTIER
//      devient une suggestion « ajouter à la licence X » plutôt que
//      « créer une nouvelle licence » — un match contre une licence déjà
//      validée par l'utilisateur est plus fiable qu'un simple regroupement
//      entre séries orphelines.
function find_license_grouping_suggestions(array $data, float $threshold = 50.0): array {
    if (!function_exists('get_series_license_map')) return [];

    $licensed = get_series_license_map();
    $targets  = array_values(array_filter($data, fn($s) => !isset($licensed[$s['id']])));
    $n = count($targets);
    if ($n === 0) return [];

    // ── Pré-calcul : chaque série n'est normalisée qu'UNE SEULE FOIS ────────
    // (auparavant refait à chaque paire testée, soit O(n) fois par série sur
    // une collection de n séries — le vrai goulot d'étranglement qui causait
    // des timeouts sur de grandes bibliothèques).
    $precomputed = array_map('grouping_precompute', $targets);

    // ── Licences existantes, avec leurs séries membres pré-calculées ────────
    $licenses_with_members = [];
    if (function_exists('list_licenses')) {
        $by_id = [];
        foreach ($data as $s) {
            $by_id[$s['id']] = $s;
        }
        foreach (list_licenses($data) as $lic) {
            $detail = get_license_detail($data, $lic['id']);
            $members = [];
            foreach (($detail['series'] ?? []) as $m) {
                if (isset($by_id[$m['id']])) $members[] = grouping_precompute($by_id[$m['id']]);
            }
            if (!empty($members)) {
                $licenses_with_members[] = ['id' => $lic['id'], 'name' => $lic['name'], 'members' => $members];
            }
        }
    }

    // ── Index inversé mot → indices de séries ────────────────────────────────
    // Deux séries qui ne partagent AUCUN mot significatif dans leurs titres
    // auront nécessairement un Jaccard nul, donc un score de titre qui ne
    // peut jamais dépasser un seuil raisonnable à lui seul (similar_text() ne
    // pèse que 15%, et le bonus secondaire max +10 — insuffisant seul, cf.
    // tests). Plutôt que de tester les n×(n-1)/2 paires possibles, on ne
    // compare que les paires qui partagent déjà au moins un mot : sur une
    // collection de titres variés, ça réduit drastiquement le nombre de
    // comparaisons réellement effectuées (l'écrasante majorité des paires
    // arbitraires ne partagent aucun mot).
    $word_index = [];
    foreach ($precomputed as $idx => $p) {
        foreach ($p['all_words'] as $w) {
            $word_index[$w][] = $idx;
        }
    }

    // Paires candidates : au moins un mot commun, jamais testées deux fois.
    $candidate_pairs = [];
    foreach ($word_index as $indices) {
        $count = count($indices);
        if ($count < 2) continue;
        // Mot bien trop fréquent (générique malgré le filtre stopwords) :
        // le nombre de paires induites explose sans apporter de signal utile
        // (ex. un mot présent dans 50+ titres générerait ~1225 paires à lui
        // seul). Ignoré au-delà d'un seuil de fréquence raisonnable.
        if ($count > 40) continue;
        for ($x = 0; $x < $count; $x++) {
            for ($y = $x + 1; $y < $count; $y++) {
                $i = $indices[$x];
                $j = $indices[$y];
                if ($i > $j) { [$i, $j] = [$j, $i]; }
                $candidate_pairs["$i-$j"] = true;
            }
        }
    }

    // ── Passe 1 : appariement des séries sans licence entre elles ──────────
    // Calcul du score uniquement sur les paires candidates (cf. ci-dessus),
    // puis extraction de CLIQUES STRICTES (tous les membres d'un groupe
    // doivent être ≥ seuil deux à deux) plutôt qu'un simple regroupement par
    // transitivité : sans cette contrainte, une chaîne A~B~C~D~E où seuls les
    // maillons voisins se ressemblent finit par regrouper des séries
    // totalement étrangères les unes aux autres aux deux bouts de la chaîne.
    $pair_scores = []; // "i-j" => score (i<j), pour les paires retenues uniquement
    $neighbors   = array_fill(0, $n, []); // graphe d'adjacence, indices ≥ seuil
    foreach (array_keys($candidate_pairs) as $key) {
        [$i, $j] = array_map('intval', explode('-', $key));
        $score = grouping_precomputed_pair_score($precomputed[$i], $precomputed[$j]);
        if ($score >= $threshold) {
            $pair_scores[$key] = $score;
            $neighbors[$i][] = $j;
            $neighbors[$j][] = $i;
        }
    }

    $cliques = grouping_extract_cliques($n, $neighbors, $pair_scores);

    // ── Passe 2 : chaque clique (y compris les séries isolées, traitées ────
    // comme un groupe à un seul membre) est testée contre les licences
    // existantes en priorité.
    $groups = [];
    $seen_as_isolated = array_fill(0, $n, false);
    foreach ($cliques as $clique) {
        foreach ($clique['indexes'] as $i) $seen_as_isolated[$i] = true;

        $candidates = array_map(fn($i) => $precomputed[$i], $clique['indexes']);

        $license_match = grouping_best_matching_license($candidates, $licenses_with_members, $threshold);
        if ($license_match !== null) {
            $groups[] = [
                'type'         => 'existing',
                'license_id'   => $license_match['license_id'],
                'license_name' => $license_match['license_name'],
                'series'       => grouping_format_members(array_map(fn($c) => $c['series'], $candidates)),
                'score'        => round($license_match['score'], 1),
            ];
            continue;
        }

        // Pas de match avec une licence existante : ne reste intéressant que
        // si la clique a au moins 2 membres (sinon rien à proposer pour une
        // série isolée qui ne matche ni une autre série, ni une licence).
        if (count($clique['indexes']) < 2) continue;

        $groups[] = [
            'type'   => 'new',
            'series' => grouping_format_members(array_map(fn($c) => $c['series'], $candidates)),
            // Score honnête : par construction d'une clique stricte, TOUTES
            // les paires du groupe sont ≥ seuil — le minimum réel est donc
            // toujours renseigné, plus besoin de valeur de repli arbitraire.
            'score'  => round($clique['min_score'], 1),
        ];
    }

    // Séries isolées (aucune paire retenue) : testées individuellement
    // contre les licences existantes, comme des cliques à un seul membre.
    for ($i = 0; $i < $n; $i++) {
        if ($seen_as_isolated[$i]) continue;
        $candidates = [$precomputed[$i]];
        $license_match = grouping_best_matching_license($candidates, $licenses_with_members, $threshold);
        if ($license_match !== null) {
            $groups[] = [
                'type'         => 'existing',
                'license_id'   => $license_match['license_id'],
                'license_name' => $license_match['license_name'],
                'series'       => grouping_format_members([$targets[$i]]),
                'score'        => round($license_match['score'], 1),
            ];
        }
    }

    usort($groups, fn($a, $b) => $b['score'] <=> $a['score']);

    return $groups;
}

// ── Extraction de cliques maximales strictes à partir du graphe d'adjacence ──
// Approche gloutonne, largement suffisante à l'échelle d'une collection
// personnelle (quelques centaines de séries au plus, et le graphe ne compte
// une arête que pour les paires déjà ≥ seuil, donc très creux en pratique) :
//   1. Trier les arêtes par score décroissant.
//   2. Pour chaque arête non encore couverte, démarrer une clique à partir
//      de ses deux sommets et l'étendre avec tout sommet connecté à TOUS les
//      membres déjà présents (donc ≥ seuil avec chacun d'eux).
//   3. Un sommet déjà utilisé dans une clique précédente n'est plus proposé
//      pour une nouvelle clique (chaque série n'apparaît que dans UNE seule
//      suggestion, la plus forte).
// Renvoie une liste de ['indexes' => int[], 'min_score' => float].
function grouping_extract_cliques(int $n, array $neighbors, array $pair_scores): array {
    $edges = [];
    foreach ($pair_scores as $key => $score) {
        [$i, $j] = array_map('intval', explode('-', $key));
        $edges[] = [$i, $j, $score];
    }
    usort($edges, fn($a, $b) => $b[2] <=> $a[2]);

    $used = array_fill(0, $n, false);
    $cliques = [];

    $pair_score = function (int $a, int $b) use ($pair_scores): ?float {
        $key = $a < $b ? "$a-$b" : "$b-$a";
        return $pair_scores[$key] ?? null;
    };

    foreach ($edges as [$i, $j, $score]) {
        if ($used[$i] || $used[$j]) continue;

        $members = [$i, $j];
        $min_score = $score;

        // Étend la clique : tout sommet non utilisé, connecté à CHAQUE
        // membre déjà présent, avec le score le plus élevé en priorité.
        $candidates = array_values(array_unique(array_merge($neighbors[$i], $neighbors[$j])));
        usort($candidates, function ($a, $b) use ($pair_score, $i, $j) {
            $sa = ($pair_score($a, $i) ?? 0) + ($pair_score($a, $j) ?? 0);
            $sb = ($pair_score($b, $i) ?? 0) + ($pair_score($b, $j) ?? 0);
            return $sb <=> $sa;
        });

        foreach ($candidates as $c) {
            if ($used[$c] || in_array($c, $members, true)) continue;
            $fits = true;
            $worst_with_c = null;
            foreach ($members as $m) {
                $s = $pair_score($c, $m);
                if ($s === null) { $fits = false; break; }
                if ($worst_with_c === null || $s < $worst_with_c) $worst_with_c = $s;
            }
            if (!$fits) continue;
            $members[] = $c;
            $min_score = min($min_score, $worst_with_c);
        }

        foreach ($members as $m) $used[$m] = true;
        $cliques[] = ['indexes' => $members, 'min_score' => $min_score];
    }

    return $cliques;
}

// ── Mise en forme des séries d'un groupe pour l'affichage front ─────────────
function grouping_format_members(array $series_list): array {
    $members = [];
    foreach ($series_list as $s) {
        $members[] = [
            'id'     => $s['id'],
            'name'   => $s['name'],
            'type'   => series_type($s),
            'detail' => is_anime($s) ? series_studios_text($s) : (string)($s['author'] ?? ''),
            'image'  => function_exists('series_thumbnail') ? series_thumbnail($s) : '',
        ];
    }
    usort($members, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $members;
}
