<?php
// Charger les données de prêt
function load_loans() {
    if (file_exists(LOAN_FILE)) {
        $loans = json_decode(file_get_contents(LOAN_FILE), true);
        return $loans ?: [];
    }
    return [];
}

// Sauvegarder les données de prêt
function save_loans($loans) {
    file_put_contents(LOAN_FILE, json_encode($loans, JSON_PRETTY_PRINT));
}

// Ajouter un prêt (un seul tome)
function add_loan($data, $series_id, $volume_number, $borrower_name) {
    $loans = load_loans();

    // Vérifier si la série existe
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return [
            'success' => false,
            'error' => 'series_not_found',
            'message' => 'La série sélectionnée n\'existe pas dans votre base. Veuillez vérifier votre sélection.'
        ];
    }

    // Vérifier si le tome est possédé
    if (!is_volume_owned($data, $series_id, $volume_number)) {
        return [
            'success' => false,
            'error' => 'volume_not_owned',
            'message' => 'Vous ne possédez pas le tome ' . $volume_number . ' de cette série.'
        ];
    }

    // Vérifier si le tome est déjà en prêt
    foreach ($loans as $loan) {
        if ($loan['series_id'] === $series_id && $loan['volume_number'] == $volume_number) {
            return [
                'success' => false,
                'error' => 'volume_already_loaned',
                'message' => 'Le tome ' . $volume_number . ' est déjà en prêt.'
            ];
        }
    }

    $loans[] = [
        'series_id' => $series_id,
        'volume_number' => $volume_number,
        'borrower_name' => $borrower_name,
        'loan_date' => date('Y-m-d H:i:s')
    ];
    save_loans($loans);
    return ['success' => true];
}

// Ajouter un prêt (plusieurs tomes)
function add_multiple_loans($data, $series_id, $start_volume, $end_volume, $borrower_name) {
    $loans = load_loans();

    // Vérifier si la série existe
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return ['success' => false, 'error' => 'series_not_found', 'message' => 'La série sélectionnée n\'existe pas dans votre base. Veuillez vérifier votre sélection.'];
    }

    // Vérifier si tous les tomes sont possédés
    $ownership_check = are_volumes_owned($data, $series_id, $start_volume, $end_volume);
    if (!$ownership_check['owned']) {
        return ['success' => false, 'error' => 'volumes_not_owned', 'message' => 'Vous ne possédez pas tous les tomes sélectionnés. Tomes manquants : ' . implode(', ', $ownership_check['missing_volumes']), 'missing_volumes' => $ownership_check['missing_volumes']];
    }

    // Vérifier si certains tomes sont déjà en prêt
    $already_loaned = [];
    for ($i = $start_volume; $i <= $end_volume; $i++) {
        foreach ($loans as $loan) {
            if ($loan['series_id'] === $series_id && $loan['volume_number'] == $i) {
                $already_loaned[] = $i;
                break;
            }
        }
    }

    if (!empty($already_loaned)) {
        return [
            'success' => false,
            'error' => 'volumes_already_loaned',
            'message' => 'Les tomes ' . implode(', ', $already_loaned) . ' sont déjà en prêt.',
            'already_loaned' => $already_loaned
        ];
    }

    for ($i = $start_volume; $i <= $end_volume; $i++) {
        $loans[] = [
            'series_id' => $series_id,
            'volume_number' => $i,
            'borrower_name' => $borrower_name,
            'loan_date' => date('Y-m-d H:i:s')
        ];
    }
    save_loans($loans);
    return ['success' => true];
}

// Supprimer un prêt
function remove_loan($series_id, $volume_number) {
    $loans = load_loans();
    foreach ($loans as $index => $loan) {
        if ($loan['series_id'] === $series_id && $loan['volume_number'] == $volume_number) {
            array_splice($loans, $index, 1);
            save_loans($loans);
            return true;
        }
    }
    return false;
}

// Supprimer tous les prêts d'une série
function remove_all_loans($series_id) {
    $loans = load_loans();
    $loans = array_filter($loans, function($loan) use ($series_id) {
        return $loan['series_id'] !== $series_id;
    });
    save_loans(array_values($loans));
    return true;
}

// Vérifier si un tome est possédé
function is_volume_owned($data, $series_id, $volume_number) {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return false;
    }
    foreach ($series['series']['volumes'] as $volume) {
        if ($volume['number'] == $volume_number) {
            return true;
        }
    }
    return false;
}

// Vérifier si plusieurs tomes sont possédés
function are_volumes_owned($data, $series_id, $start_volume, $end_volume) {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return ['owned' => false, 'error' => 'series_not_found'];
    }

    $missing_volumes = [];
    for ($i = $start_volume; $i <= $end_volume; $i++) {
        if (!is_volume_owned($data, $series_id, $i)) {
            $missing_volumes[] = $i;
        }
    }

    if (empty($missing_volumes)) {
        return ['owned' => true];
    } else {
        return ['owned' => false, 'missing_volumes' => $missing_volumes];
    }
}

// Récupérer les prêts par série (y compris les séries supprimées)
function get_loans_by_series($data) {
    $loans = load_loans();
    $result = [];
    $series_ids = [];

    // Récupérer tous les IDs de séries existantes
    foreach ($data as $series) {
        $series_ids[] = $series['id'];
    }

    // Grouper les prêts par série
    $loans_by_series = [];
    foreach ($loans as $loan) {
        $series_id = $loan['series_id'];
        if (!isset($loans_by_series[$series_id])) {
            $loans_by_series[$series_id] = [];
        }
        $loans_by_series[$series_id][] = $loan;
    }

    // Créer le résultat
    foreach ($loans_by_series as $series_id => $loans) {
        $series = find_series_by_id($data, $series_id);
        $result[] = [
            'series' => $series ? $series['series'] : null,
            'loans' => $loans,
            'series_exists' => $series !== null
        ];
    }

    return $result;
}