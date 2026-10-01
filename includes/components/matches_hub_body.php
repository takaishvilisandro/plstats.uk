<?php
/**
 * Matches hub body (shared by matches/index.php and matches/season.php).
 * Expects the variables from includes/functions/matches_hub.php plus
 * $hubHeading (the page's H1 text, unchanged per page).
 */
if (!isset($hubRounds)) {
  return;
}

$statusLabels = ['upcoming' => 'Upcoming', 'in_progress' => 'In progress', 'complete' => 'Complete'];
?>
<div class="content hub_page">

  <?php
  // Same trail as each page's BreadcrumbList: Home › Matches (› {season} Season)
  $breadcrumbs = [['name' => 'Home', 'url' => plstats_url('/')]];
  if ($selectedSeason === $activeSeason) {
    $breadcrumbs[] = ['name' => 'Matches'];
  } else {
    $breadcrumbs[] = ['name' => 'Matches', 'url' => plstats_url('/matches/')];
    $breadcrumbs[] = ['name' => "$selectedSeason Season"];
  }
  include __DIR__ . '/breadcrumbs.php';
  ?>

  <!-- HEADER + FILTERS -->
  <header class="hub_header">
    <div class="hub_title_block">
      <h1 class="hub_title"><?= htmlspecialchars($hubHeading) ?></h1>
      <p class="updated_label num"><?= htmlspecialchars($selectedSeason) ?><?= $hubUpdated !== '' ? ' · Updated ' . htmlspecialchars($hubUpdated) : '' ?></p>
    </div>

    <!-- Without JS: a GET form with an Apply button. With JS it submits on change. -->
    <form class="hub_filters" id="hubFilters" method="get" action="<?= htmlspecialchars(plstats_url($hubPath)) ?>">
      <?php if (count($seasonList) > 1): ?>
        <label class="hub_select hub_select--season">
          <span class="hub_select_label">Season</span>
          <select name="select_season">
            <?php foreach ($seasonList as $s): ?>
              <option value="<?= htmlspecialchars($s) ?>"<?= $s === $selectedSeason ? ' selected' : '' ?>><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
          </select>
          <i class="fas fa-chevron-down hub_select_icon" aria-hidden="true"></i>
        </label>
      <?php endif; ?>

      <label class="hub_select hub_select--team">
        <span class="hub_select_label">Team</span>
        <select name="team">
          <option value="">All teams</option>
          <?php foreach ($hubTeams as $slug => $name): ?>
            <option value="<?= htmlspecialchars($slug) ?>"<?= $slug === $filterTeam ? ' selected' : '' ?>><?= htmlspecialchars($name) ?></option>
          <?php endforeach; ?>
        </select>
        <i class="fas fa-chevron-down hub_select_icon" aria-hidden="true"></i>
      </label>

      <fieldset class="hub_status" role="radiogroup" aria-label="Show">
        <?php foreach (['' => 'All', 'results' => 'Results', 'fixtures' => 'Fixtures'] as $value => $label): ?>
          <label class="hub_status_option">
            <input type="radio" name="status" value="<?= $value ?>"<?= $filterStatus === $value ? ' checked' : '' ?>>
            <span><?= $label ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>

      <button type="submit" class="hub_apply">Apply</button>
    </form>
  </header>

  <!-- ROUNDS -->
  <?php if ($hubRounds): ?>
    <div class="hub_rounds" id="hubRounds">
      <?php foreach ($hubRounds as $i => $round): ?>
        <section class="hub_round<?= $i >= HUB_ROUNDS_FIRST ? ' is_extra' : '' ?>" id="round-<?= (int)$round['round'] ?>" aria-labelledby="round-<?= (int)$round['round'] ?>-title">
          <div class="hub_round_head">
            <h2 class="section_title" id="round-<?= (int)$round['round'] ?>-title" tabindex="-1">Round <?= (int)$round['round'] ?></h2>
            <p class="hub_round_meta">
              <span class="num"><?= hub_date_range($round['from'], $round['to']) ?></span>
              <span class="round_pill round_pill--<?= $round['status'] ?>"><?= $statusLabels[$round['status']] ?></span>
            </p>
          </div>
          <div class="card hub_round_card">
            <?php foreach ($round['days'] as $day => $dayRows): ?>
              <h3 class="card_subhead hub_day num"><?= date('D j M', strtotime($day)) ?></h3>
              <div class="hub_day_rows">
                <?php foreach ($dayRows as $r): ?>
                  <?= hub_match_row($r) ?>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>

    <?php if (count($hubRounds) > HUB_ROUNDS_FIRST): ?>
      <div class="hub_more_wrap">
        <button type="button" class="hub_more" id="hubMore" hidden>Load earlier rounds</button>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <p class="hub_empty">No matches for these filters. <a href="<?= htmlspecialchars(plstats_url($hubPath)) ?>">Show all matches</a></p>
  <?php endif; ?>

</div>

<script>
  (function() {
    /* ── Filters: submit on change (Apply stays for no-JS) ── */
    var form = document.getElementById('hubFilters');
    if (form) {
      form.classList.add('js_autosubmit');
      var submit = function() {
        if (form.requestSubmit) {
          form.requestSubmit();
        } else {
          form.submit();
        }
      };
      Array.prototype.forEach.call(form.querySelectorAll('select, input[type="radio"]'), function(el) {
        el.addEventListener('change', submit);
      });
    }

    /* ── Load earlier rounds: reveal the next rounds already in the HTML ── */
    var wrap = document.getElementById('hubRounds');
    var more = document.getElementById('hubMore');
    if (wrap && more) {
      var STEP = <?= (int)HUB_ROUNDS_STEP ?>;
      wrap.classList.add('js_reveal');
      more.hidden = false;

      more.addEventListener('click', function() {
        var hidden = wrap.querySelectorAll('.hub_round.is_extra:not(.is_shown)');
        var first = null;
        for (var i = 0; i < hidden.length && i < STEP; i++) {
          hidden[i].classList.add('is_shown');
          if (!first) first = hidden[i];
        }
        if (first) {
          var title = first.querySelector('h2');
          if (title) title.focus();
        }
        if (wrap.querySelectorAll('.hub_round.is_extra:not(.is_shown)').length === 0) {
          more.parentNode.removeChild(more);
        }
      });
    }
  }());
</script>
