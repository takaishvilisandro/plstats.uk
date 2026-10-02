<?php

/**
 * Stats hub (/stats/) and stats pages (/stats/{page}/, /stats/{page}/{season}/).
 *
 * One config for the seven pages plus the Leaderboards queries both templates use.
 * Boards come from Leaderboards (rebuilt by every run; never written here) and
 * labels and definitions from MetricDefinitions.
 */

/**
 * The seven stats pages, in hub order.
 *   main       board the page ranks by (top 50, totals and per 90)
 *   columns    extra figures from the same players' rows on other boards
 *   boards     more player boards (top 10 each)
 *   teamBoards team boards (top 10 each)
 *   chart      "{Leader} leads the Premier League {season} {chart}"
 */
function plstats_stats_pages(): array
{
  return [
    'top-scorers' => [
      'name'       => 'Top Scorers',
      'short'      => 'Top scorers',
      'icon'       => 'fa-futbol',
      'main'       => 'goals',
      'columns'    => ['expected_goals_xg', 'total_shots'],
      'boards'     => ['expected_goals_xg', 'shots_on_target'],
      'teamBoards' => ['goals'],
      'chart'      => 'scoring charts',
      'descTail'   => 'Full top scorers list with xG, shots, minutes and goals per 90.',
    ],
    'assists' => [
      'name'       => 'Assists',
      'short'      => 'Assists',
      'icon'       => 'fa-hands-helping',
      'main'       => 'assists',
      'columns'    => ['expected_assists_xa', 'key_passes'],
      'boards'     => ['expected_assists_xa', 'big_chances_created', 'key_passes'],
      'teamBoards' => ['big_chances'],
      'chart'      => 'assists chart',
      'descTail'   => 'Full assists list with xA, key passes, big chances created and assists per 90.',
    ],
    'goalkeepers' => [
      'name'       => 'Goalkeeper Stats',
      'short'      => 'Goalkeepers',
      'icon'       => 'fa-hand-paper',
      'main'       => 'goalkeeper_saves',
      'columns'    => ['goals_prevented'],
      'boards'     => ['goals_prevented', 'punches'],
      'teamBoards' => ['clean_sheets', 'goals_conceded'],
      'chart'      => 'saves chart',
      'descTail'   => 'Saves, goals prevented and punches by goalkeeper, plus team clean sheets and goals conceded.',
    ],
    'shooting' => [
      'name'       => 'Shooting Stats',
      'short'      => 'Shooting',
      'icon'       => 'fa-bullseye',
      'main'       => 'total_shots',
      'columns'    => ['shots_on_target', 'expected_goals_xg'],
      'boards'     => ['shots_on_target', 'expected_goals_xg', 'xg_on_target_xgot', 'shots_inside_the_box'],
      'teamBoards' => ['total_shots', 'expected_goals_xg'],
      'chart'      => 'shots chart',
      'descTail'   => 'Shots, shots on target, xG and xGOT by player and team.',
    ],
    'passing' => [
      'name'       => 'Passing Stats',
      'short'      => 'Passing',
      'icon'       => 'fa-exchange-alt',
      'main'       => 'accurate_passes',
      'columns'    => ['key_passes'],
      'boards'     => ['key_passes', 'accurate_long_passes', 'accurate_crosses', 'accurate_passes_in_final_third'],
      'teamBoards' => [],
      'chart'      => 'passing chart',
      'descTail'   => 'Accurate passes with pass accuracy, key passes, long balls, crosses and final-third passes.',
    ],
    'defending' => [
      'name'       => 'Defending Stats',
      'short'      => 'Defending',
      'icon'       => 'fa-shield-alt',
      'main'       => 'tackles_won',
      'columns'    => ['interceptions', 'clearances'],
      'boards'     => ['interceptions', 'clearances', 'duels_won', 'aerial_duels_won'],
      'teamBoards' => ['goals_conceded', 'expected_goals_against', 'shots_against'],
      'chart'      => 'tackles chart',
      'descTail'   => 'Tackles won, interceptions, clearances and duels by player, plus the best defences.',
    ],
    'possession' => [
      'name'       => 'Possession Stats',
      'short'      => 'Possession',
      'icon'       => 'fa-running',
      'main'       => 'touches',
      'columns'    => ['touches_in_opposition_box'],
      'boards'     => ['touches_in_opposition_box', 'successful_dribbles'],
      'teamBoards' => ['possession'],
      'chart'      => 'touches chart',
      'descTail'   => 'Touches, touches in the opposition box and successful dribbles by player, plus team possession.',
    ],
  ];
}

/**
 * The stats page a metric belongs to (its main board first, then any board), or ''.
 */
function plstats_stats_page_for_metric(string $metricKey, string $entityType = 'Player'): string
{
  $pages = plstats_stats_pages();
  foreach ($pages as $slug => $page) {
    if ($entityType === 'Player' && $page['main'] === $metricKey) {
      return $slug;
    }
  }
  foreach ($pages as $slug => $page) {
    $keys = $entityType === 'Player' ? $page['boards'] : $page['teamBoards'];
    if (in_array($metricKey, $keys, true)) {
      return $slug;
    }
  }

  return '';
}

/**
 * /stats/{page}/ for the current season, /stats/{page}/{season}/ otherwise.
 */
function plstats_stats_url(string $page = '', string $season = '', string $currentSeason = ''): string
{
  if ($page === '') {
    return plstats_url('/stats/');
  }

  return plstats_url('/stats/' . $page . '/' . ($season !== '' && $season !== $currentSeason ? $season . '/' : ''));
}

/**
 * Every MetricDefinitions row, keyed by Key.
 */
function plstats_stats_definitions(PDO $pdo): array
{
  static $defs = null;
  if ($defs === null) {
    $defs = [];
    foreach ($pdo->query("SELECT `Key`, Label, ShortLabel, Description, Unit, HasTotal, HasPer90, HigherIsBetter FROM MetricDefinitions ORDER BY SortOrder") as $d) {
      $defs[$d['Key']] = $d;
    }
  }

  return $defs;
}

/**
 * Seasons with player boards, newest first.
 */
function plstats_stats_seasons(PDO $pdo): array
{
  return $pdo->query("
    SELECT DISTINCT Season
    FROM Leaderboards
    WHERE EntityType = 'Player'
      AND DeleteDate IS NULL
    ORDER BY Season DESC
  ")->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Highest round with a result in a season (1 August to 1 August).
 */
function plstats_stats_rounds_played(PDO $pdo, string $season): int
{
  $start = (int)substr($season, 0, 4);
  $stmt  = $pdo->prepare("
    SELECT MAX(Round)
    FROM Matches
    WHERE DeleteDate IS NULL
      AND HomeTeamScore IS NOT NULL
      AND AwayTeamScore IS NOT NULL
      AND Date >= :season_start
      AND Date < :season_end
  ");
  $stmt->execute(['season_start' => "$start-08-01 00:00:00", 'season_end' => ($start + 1) . '-08-01 00:00:00']);

  return (int)$stmt->fetchColumn();
}

/**
 * Per-90 minimum: a third of the minutes a club has had so far (rounds × 90 ÷ 3),
 * rounded down to 10 and never under 90. 150 after 5 rounds, 1,140 for a full season.
 */
function plstats_stats_min_minutes(int $roundsPlayed): int
{
  return max(90, (int)(floor($roundsPlayed * 90 / 3 / 10) * 10));
}

/**
 * A player board in rank order (ties: fewer minutes first).
 */
function plstats_stats_player_board(PDO $pdo, string $season, string $metricKey, int $limit): array
{
  $stmt = $pdo->prepare("
    SELECT l.EntityId AS PlayerId, l.Rank, l.Value, l.Total, l.Percentage, l.Per90, l.Minutes, l.Matches,
           COALESCE(p.Name, p.ShortName) AS DisplayName, p.Slug,
           t.Name AS TeamName, t.Slug AS TeamSlug, t.Logo AS TeamLogo
    FROM Leaderboards l
    JOIN Players p ON p.Id = l.EntityId AND p.DeleteDate IS NULL
    JOIN Teams t ON t.Id = l.TeamId
    WHERE l.Season = :season
      AND l.MetricKey = :metric_key
      AND l.EntityType = 'Player'
      AND l.DeleteDate IS NULL
    ORDER BY l.Rank, l.Minutes
    LIMIT " . (int)$limit
  );
  $stmt->execute(['season' => $season, 'metric_key' => $metricKey]);

  return $stmt->fetchAll();
}

/**
 * A per-90 board: players over the minimum minutes, best rate first, numbered here.
 */
function plstats_stats_per90_board(PDO $pdo, string $season, string $metricKey, int $minMinutes, int $limit): array
{
  $stmt = $pdo->prepare("
    SELECT l.EntityId AS PlayerId, l.Value, l.Per90, l.Minutes, l.Matches,
           COALESCE(p.Name, p.ShortName) AS DisplayName, p.Slug,
           t.Name AS TeamName, t.Slug AS TeamSlug, t.Logo AS TeamLogo
    FROM Leaderboards l
    JOIN Players p ON p.Id = l.EntityId AND p.DeleteDate IS NULL
    JOIN Teams t ON t.Id = l.TeamId
    WHERE l.Season = :season
      AND l.MetricKey = :metric_key
      AND l.EntityType = 'Player'
      AND l.DeleteDate IS NULL
      AND l.Per90 IS NOT NULL
      AND l.Minutes >= :min_minutes
    ORDER BY l.Per90 DESC, l.Value DESC, l.Minutes
    LIMIT " . (int)$limit
  );
  $stmt->execute(['season' => $season, 'metric_key' => $metricKey, 'min_minutes' => $minMinutes]);

  $rows = $stmt->fetchAll();
  $rank = 0;
  $prev = null;
  foreach ($rows as $i => &$r) {
    // Equal rates share a position (1, 2, 2, 4), as on the totals board
    if ($prev === null || (float)$r['Per90'] !== $prev) {
      $rank = $i + 1;
    }
    $r['Rank'] = $rank;
    $prev = (float)$r['Per90'];
  }
  unset($r);

  return $rows;
}

/**
 * A team board (20 clubs; ascending where fewer is better).
 */
function plstats_stats_team_board(PDO $pdo, string $season, string $metricKey, int $limit): array
{
  $stmt = $pdo->prepare("
    SELECT l.Rank, l.Value, l.PerMatch, l.Matches,
           t.Name AS TeamName, t.Slug AS TeamSlug, t.Logo AS TeamLogo
    FROM Leaderboards l
    JOIN Teams t ON t.Id = l.EntityId
    WHERE l.Season = :season
      AND l.MetricKey = :metric_key
      AND l.EntityType = 'Team'
      AND l.DeleteDate IS NULL
    ORDER BY l.Rank, t.Name
    LIMIT " . (int)$limit
  );
  $stmt->execute(['season' => $season, 'metric_key' => $metricKey]);

  return $stmt->fetchAll();
}

/**
 * Other boards' values for a set of players: [PlayerId][MetricKey] => Value.
 * A player missing from a board (value 0 there) is simply absent.
 */
function plstats_stats_player_values(PDO $pdo, string $season, array $metricKeys, array $playerIds): array
{
  if (!$metricKeys || !$playerIds) {
    return [];
  }

  $keyMarks    = implode(',', array_fill(0, count($metricKeys), '?'));
  $playerMarks = implode(',', array_fill(0, count($playerIds), '?'));
  $stmt = $pdo->prepare("
    SELECT EntityId, MetricKey, Value
    FROM Leaderboards
    WHERE Season = ?
      AND EntityType = 'Player'
      AND DeleteDate IS NULL
      AND MetricKey IN ($keyMarks)
      AND EntityId IN ($playerMarks)
  ");
  $stmt->execute(array_merge([$season], array_values($metricKeys), array_map('intval', $playerIds)));

  $values = [];
  foreach ($stmt->fetchAll() as $r) {
    $values[(int)$r['EntityId']][$r['MetricKey']] = $r['Value'];
  }

  return $values;
}

/**
 * "1 ahead of X" / "level with X" for a board's top two rows.
 */
function plstats_stats_gap(array $first, ?array $second, string $unit): string
{
  if (!$second) {
    return '';
  }
  $gap = (float)$first['Value'] - (float)$second['Value'];
  if ($gap <= 0) {
    return ', level with ' . $second['DisplayName'];
  }

  return ', ' . plstats_format_metric($gap, $unit) . ' ahead of ' . $second['DisplayName'];
}

/**
 * A leader list (top N of one board) in the shared .scorer_list markup.
 * Players get initials, clubs their crest.
 */
function plstats_leader_list(array $rows, string $unit, string $kind = 'player'): string
{
  $out = '<ol class="scorer_list">';
  foreach ($rows as $r) {
    $out .= '<li class="scorer_row">';
    $out .= '<span class="scorer_rank num">' . (int)$r['Rank'] . '</span>';

    if ($kind === 'team') {
      $out .= '<span class="scorer_crest">' . team_badge(['Name' => $r['TeamName'], 'Slug' => $r['TeamSlug'], 'Logo' => $r['TeamLogo']], 32) . '</span>';
      $out .= '<span class="scorer_info"><a class="scorer_name" href="' . htmlspecialchars(plstats_team_url($r['TeamSlug'])) . '">' . htmlspecialchars($r['TeamName']) . '</a>';
      $matches = (int)$r['Matches'];
      $out .= $matches > 0 ? '<span class="scorer_meta num">' . $matches . ($matches === 1 ? ' match' : ' matches') . '</span>' : '';
      $out .= '</span>';
    } else {
      $out .= '<span class="scorer_avatar" aria-hidden="true">' . htmlspecialchars(plstats_initials($r['DisplayName'])) . '</span>';
      $out .= '<span class="scorer_info">';
      $out .= $r['Slug']
        ? '<a class="scorer_name" href="' . htmlspecialchars(plstats_player_url($r['Slug'])) . '">' . htmlspecialchars($r['DisplayName']) . '</a>'
        : '<span class="scorer_name">' . htmlspecialchars($r['DisplayName']) . '</span>';
      $apps = (int)$r['Matches'];
      $out .= '<span class="scorer_meta">' . htmlspecialchars($r['TeamName']) . ($apps > 0 ? ' · <span class="num">' . $apps . ($apps === 1 ? ' app' : ' apps') . '</span>' : '') . '</span>';
      $out .= '</span>';
    }

    $out .= '<span class="scorer_goals"><span class="scorer_goals_value num">' . plstats_format_metric($r['Value'], $unit) . '</span></span>';
    $out .= '</li>';
  }

  return $out . '</ol>';
}

/**
 * How many players share first place on a board.
 */
function plstats_stats_leader_count(PDO $pdo, string $season, string $metricKey): int
{
  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM Leaderboards
    WHERE Season = :season
      AND MetricKey = :metric_key
      AND EntityType = 'Player'
      AND DeleteDate IS NULL
      AND Rank = 1
  ");
  $stmt->execute(['season' => $season, 'metric_key' => $metricKey]);

  return (int)$stmt->fetchColumn();
}
