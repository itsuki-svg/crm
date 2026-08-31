<?php
// ============================================================
//  pages/layout_end.php — レイアウト終端（</body>前）
// ============================================================
?>
</div><!-- end .main -->
</div><!-- end .layout -->

<!-- トーストコンテナ -->
<div id="toast-container" class="toast-container"></div>

<script src="<?= APP_URL ?>/public/js/app.js"></script>
<?php if (!empty($extraJs)): ?>
<script><?= $extraJs ?></script>
<?php endif; ?>
</body>
</html>
