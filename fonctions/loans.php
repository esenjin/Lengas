<?php
// Charger les données de prêt
function load_loans(): array {
    $db   = get_db();
    $rows = $db->query("SELECT * FROM loans ORDER BY id")->fetchAll();
    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'series_id'     => $r['series_id'],
            'volume_number' => (int)$r['volume_number'],
            'borrower_name' => $r['borrower_name'],
            'loan_date'     => $r['loan_date'],
        ];
    }
    return $result;
}

// Sauvegarder les données de prêt (remplacement complet)
function save_loans(array $loans): void {
    $db = get_db();
    $db->beginTransaction();
    try {
        $db->exec("DELETE FROM loans");
        $stmt = $db->prepare("
            INSERT INTO loans (series_id, volume_number, borrower_name, loan_date)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($loans as $l) {
            $stmt->execute([
                $l['series_id'],
                (int)$l['volume_number'],
                $l['borrower_name'],
                $l['loan_date'],
            ]);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// Ajouter un prêt (un seul tome)
function add_loan(array $data, string $series_id, int $volume_number, string $borrower_name): array {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return [
            'success' => false,
            'error'   => 'series_not_found',
            'message' => "La série sélectionnée n'existe pas dans votre base. Veuillez vérifier votre sélection.",
        ];
    }

    if (!is_volume_owned($data, $series_id, $volume_number)) {
        return [
            'success' => false,
            'error'   => 'volume_not_owned',
            'message' => "Vous ne possédez pas le tome $volume_number de cette série.",
        ];
    }

    $db = get_db();
    $existing = $db->prepare("SELECT id FROM loans WHERE series_id = ? AND volume_number = ?");
    $existing->execute([$series_id, $volume_number]);
    if ($existing->fetch()) {
        return [
            'success' => false,
            'error'   => 'volume_already_loaned',
            'message' => "Le tome $volume_number est déjà en prêt.",
        ];
    }

    $db->prepare("
        INSERT INTO loans (series_id, volume_number, borrower_name, loan_date)
        VALUES (?, ?, ?, ?)
    ")->execute([$series_id, $volume_number, $borrower_name, date('Y-m-d H:i:s')]);

    return ['success' => true];
}

// Ajouter un prêt (plusieurs tomes)
function add_multiple_loans(array $data, string $series_id, int $start_volume, int $end_volume, string $borrower_name): array {
    $series = find_series_by_id($data, $series_id);
    if (!$series) {
        return ['success' => false, 'error' => 'series_not_found', 'message' => "La série sélectionnée n'existe pas dans votre base. Veuillez vérifier votre sélection."];
    }

    $ownership_check = are_volumes_owned($data, $series_id, $start_volume, $end_volume);
    if (!$ownership_check['owned']) {
        return ['success' => false, 'error' => 'volumes_not_owned', 'message' => 'Vous ne possédez pas tous les tomes sélectionnés. Tomes manquants : ' . implode(', ', $ownership_check['missing_volumes']), 'missing_volumes' => $ownership_check['missing_volumes']];
    }

    $db = get_db();
    $already_loaned = [];
    for ($i = $start_volume; $i <= $end_volume; $i++) {
        $stmt = $db->prepare("SELECT id FROM loans WHERE series_id = ? AND volume_number = ?");
        $stmt->execute([$series_id, $i]);
        if ($stmt->fetch()) {
            $already_loaned[] = $i;
        }
    }

    if (!empty($already_loaned)) {
        return [
            'success'        => false,
            'error'          => 'volumes_already_loaned',
            'message'        => 'Les tomes ' . implode(', ', $already_loaned) . ' sont déjà en prêt.',
            'already_loaned' => $already_loaned,
        ];
    }

    $stmt = $db->prepare("
        INSERT INTO loans (series_id, volume_number, borrower_name, loan_date)
        VALUES (?, ?, ?, ?)
    ");
    $loan_date = date('Y-m-d H:i:s');
    for ($i = $start_volume; $i <= $end_volume; $i++) {
        $stmt->execute([$series_id, $i, $borrower_name, $loan_date]);
    }

    return ['success' => true];
}

// Supprimer un prêt
function remove_loan(string $series_id, int $volume_number): bool {
    $db   = get_db();
    $stmt = $db->prepare("DELETE FROM loans WHERE series_id = ? AND volume_number = ?");
    $stmt->execute([$series_id, $volume_number]);
    return $stmt->rowCount() > 0;
}

// Supprimer tous les prêts d'une série
function remove_all_loans(string $series_id): bool {
    $db = get_db();
    $db->prepare("DELETE FROM loans WHERE series_id = ?")->execute([$series_id]);
    return true;
}

// Vérifier si un tome est possédé
function is_volume_owned(array $data, string $series_id, int $volume_number): bool {
    $series = find_series_by_id($data, $series_id);
    if (!$series) return false;
    foreach ($series['data']['volumes'] as $volume) {
        if ((int)$volume['number'] === $volume_number) return true;
    }
    return false;
}

// Vérifier si plusieurs tomes sont possédés
function are_volumes_owned(array $data, string $series_id, int $start_volume, int $end_volume): array {
    $series = find_series_by_id($data, $series_id);
    if (!$series) return ['owned' => false, 'error' => 'series_not_found'];

    $missing_volumes = [];
    for ($i = $start_volume; $i <= $end_volume; $i++) {
        if (!is_volume_owned($data, $series_id, $i)) {
            $missing_volumes[] = $i;
        }
    }

    return empty($missing_volumes)
        ? ['owned' => true]
        : ['owned' => false, 'missing_volumes' => $missing_volumes];
}

// Récupérer les prêts par série (y compris les séries supprimées)
function get_loans_by_series(array $data): array {
    $loans  = load_loans();
    $result = [];

    $loans_by_series = [];
    foreach ($loans as $loan) {
        $sid = $loan['series_id'];
        $loans_by_series[$sid][] = $loan;
    }

    foreach ($loans_by_series as $series_id => $series_loans) {
        $series = find_series_by_id($data, $series_id);
        $result[] = [
            'series'        => $series ? $series['data'] : null,
            'loans'         => $series_loans,
            'series_exists' => $series !== null,
        ];
    }

    return $result;
}
