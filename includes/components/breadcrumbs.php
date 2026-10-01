<?php
/**
 * Visible breadcrumb trail.
 *
 * Expects $breadcrumbs = [['name' => 'Home', 'url' => plstats_url('/')], ..., ['name' => 'Current page']]
 * — the same array passed to plstats_schema_breadcrumb(), so the markup and
 * the structured data always agree. The last item is the current page (no link).
 */
if (empty($breadcrumbs) || !is_array($breadcrumbs)) {
  return;
}
?>
<nav class="breadcrumbs" aria-label="Breadcrumb">
  <ol>
    <?php foreach ($breadcrumbs as $crumb): ?>
      <li>
        <?php if (!empty($crumb['url'])): ?>
          <a href="<?= htmlspecialchars($crumb['url']) ?>"><?= htmlspecialchars($crumb['name']) ?></a>
        <?php else: ?>
          <span aria-current="page"><?= htmlspecialchars($crumb['name']) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</nav>
