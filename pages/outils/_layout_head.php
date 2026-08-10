<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/_layout_head.php — En-tête HTML commun des pages outils
//
// À inclure après avoir défini :
//   $tool_title    (string) Titre affiché en <h1> et dans <title>
//   $tool_subtitle (string) Phrase d'intro sous le titre (facultative)
//
// Requiert que _bootstrap.php ait déjà été inclus ($options doit exister).
// ────────────────────────────────────────────────────────────────────────────
$tool_subtitle = $tool_subtitle ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($tool_title) ?> — <?= htmlspecialchars($options['site_name'] ?? 'Lengas') ?></title>
    <meta name="description" content="Outils de maintenance et de vérification de la collection.">
    <?= opengraph_tags($options) ?>
    <link rel="icon" type="image/x-icon" href="../../assets/img/favicon.ico">
    <link rel="stylesheet" href="../../assets/css/main.css">
    <?= theme_link_tag($options) ?>
</head>
<body class="with-sidebar tools-page">

    <?php include 'includes/sidebar.php'; ?>

    <main class="page-main">
        <div class="page-header">
            <a href="../page-outils.php" class="tool-back-link">← Retour aux outils</a>
            <h1><?= htmlspecialchars($tool_title) ?></h1>
            <?php if ($tool_subtitle !== ''): ?>
            <p class="page-subtitle"><?= htmlspecialchars($tool_subtitle) ?></p>
            <?php endif; ?>
        </div>
