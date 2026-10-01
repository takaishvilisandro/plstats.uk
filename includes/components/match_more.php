<?php
/**
 * Match page: "More from Round {n}" plus links to both team pages.
 * Expects $roundMatches, $round, $matchSeason, $home, $away, $match from the match page.
 */
if (!isset($match)) {
  return;
}
?>
<div class="summary_block match_more<?= empty($moreInSummary) ? ' match_more--standalone' : '' ?>">
  <?php if ($roundMatches): ?>
    <div class="section_head">
      <h3 class="section_title">More from Round <?= (int)$round ?></h3>
      <a class="section_link" href="<?= htmlspecialchars(plstats_url("/matches/$matchSeason/")) ?>">All results <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
    </div>
    <div class="card round_list">
      <?php foreach ($roundMatches as $rm):
        $rmPlayed = $rm['HomeTeamScore'] !== null && $rm['AwayTeamScore'] !== null;
        $rmHome = $rmAway = '';
        if ($rmPlayed && (int)$rm['HomeTeamScore'] !== (int)$rm['AwayTeamScore']) {
          $rmHome = (int)$rm['HomeTeamScore'] > (int)$rm['AwayTeamScore'] ? ' is_winner' : ' is_loser';
          $rmAway = (int)$rm['HomeTeamScore'] > (int)$rm['AwayTeamScore'] ? ' is_loser' : ' is_winner';
        }
      ?>
        <a class="round_row" href="<?= htmlspecialchars(plstats_match_url($rm['Date'], $rm['Round'], $rm['HomeSlug'], $rm['AwaySlug'])) ?>">
          <span class="round_team round_team--home<?= $rmHome ?>"><?= htmlspecialchars($rm['HomeName']) ?></span>
          <span class="round_score num"><?= $rmPlayed ? (int)$rm['HomeTeamScore'] . '–' . (int)$rm['AwayTeamScore'] : date('H:i', strtotime($rm['Date'])) ?></span>
          <span class="round_team<?= $rmAway ?>"><?= htmlspecialchars($rm['AwayName']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="link_tiles match_team_tiles">
    <a class="link_tile" href="<?= htmlspecialchars(plstats_team_url($match['HomeTeamSlug'])) ?>">
      <?= team_badge(['Name' => $home, 'Slug' => $match['HomeTeamSlug'], 'Logo' => $match['HomeTeamLogo']], 24) ?>
      <span><?= htmlspecialchars($home) ?></span>
    </a>
    <a class="link_tile" href="<?= htmlspecialchars(plstats_team_url($match['AwayTeamSlug'])) ?>">
      <?= team_badge(['Name' => $away, 'Slug' => $match['AwayTeamSlug'], 'Logo' => $match['AwayTeamLogo']], 24) ?>
      <span><?= htmlspecialchars($away) ?></span>
    </a>
  </div>
</div>
