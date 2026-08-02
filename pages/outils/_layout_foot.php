<?php
// ────────────────────────────────────────────────────────────────────────────
// pages/outils/_layout_foot.php — Pied HTML commun des pages outils
//
// Ferme <main>/<body>/<html>, ajoute le bouton « Retour en haut » et charge
// le socle JS commun (assets/js/admin/tools/page.js), toujours nécessaire :
// il fournit showCustomAlert/showCustomConfirm, htmlEscape et la fermeture
// des modales, utilisés par tous les scripts d'outils.
//
// Chaque page outil doit avoir déclaré $tool_scripts (tableau de chemins,
// relatifs à assets/js/admin/tools/) AVANT d'inclure ce fichier, pour ses
// scripts propres (chargés après page.js).
// ────────────────────────────────────────────────────────────────────────────
$tool_scripts = $tool_scripts ?? [];
?>
    </main>

    <button id="back-to-top" title="Retour en haut">↑</button>

    <script src="../../assets/js/admin/tools/page.js"></script>
<?php foreach ($tool_scripts as $__script): ?>
    <script src="../../assets/js/admin/tools/<?= htmlspecialchars($__script) ?>"></script>
<?php endforeach; ?>

</body>
</html>
