<?php

/**
 * Data for the matches hub (/matches/ and /matches/{season}/).
 *
 * Expects from the page: $pdo, $rows (every match, with _ts, _played, _season),
 * $seasonList, $activeSeason, $selectedSeason. Optional GET filters:
 *   team   = a team slug from the selected season
 *   status = "results" | "fixtures"
 * A "select_season" parameter (sent by the no-JS filter form) redirects to
 * that season's own path, keeping the other filters. ("season" itself is
 * taken: the rewrite rule passes the URL's season to season.php with it.) The canonical tag of both
 * pages stays the clean URL, so filtered views consolidate to it.
 */

if (!isset($pdo, $rows, $selectedSeason)) {
  http_response_code(404);
  exit;
}

$hubPath = $selectedSeason === $activeSeason ? '/matches/' : "/matches/$selectedSeason/";

$seasonRows = array_values(array_filter($rows, fn($r) => $r['_season'] === $selectedSeason));

// Teams in this season (slug => name), alphabetical by name
$hubTeams = [];
foreach ($seasonRows as $r) {
  $hubTeams[$r['HomeTeamSlug']] = $r['HomeTeamName'];
  $hubTeams[$r['AwayTeamSlug']] = $r['AwayTeamName'];
}
asort($hubTeams);

$filterTeam   = isset($_GET['team']) && isset($hubTeams[$_GET['team']]) ? $_GET['team'] : '';
$filterStatus = isset($_GET['status']) && in_array($_GET['status'], ['results', 'fixtures'], true) ? $_GET['status'] : '';

// No-JS form submit: season choice (and empty parameters) become a clean URL
if (isset($_GET['select_season']) || (isset($_GET['team']) && $_GET['team'] === '') || (isset($_GET['status']) && $_GET['status'] === '')) {
  $targetSeason = (isset($_GET['select_season']) && in_array($_GET['select_season'], $seasonList, true)) ? $_GET['select_season'] : $selectedSeason;
  $targetPath   = $targetSeason === $activeSeason ? '/matches/' : "/matches/$targetSeason/";

  // A team that didn't play in the target season is dropped
  $keepTeam = $filterTeam;
  if ($keepTeam !== '' && $targetSeason !== $selectedSeason) {
    $inTarget = false;
    foreach ($rows as $r) {
      if ($r['_season'] === $targetSeason && ($r['HomeTeamSlug'] === $keepTeam || $r['AwayTeamSlug'] === $keepTeam)) {
        $inTarget = true;
        break;
      }
    }
    $keepTeam = $inTarget ? $keepTeam : '';
  }

  $query = http_build_query(array_filter(['team' => $keepTeam, 'status' => $filterStatus]));
  header('Location: ' . plstats_url($targetPath) . ($query !== '' ? '?' . $query : ''), true, 302);
  exit;
}

/* ----------------------------------------
   Group by round (all matches, unfiltered,
   so the round status reflects the round)
---------------------------------------- */
$roundsAll = [];
foreach ($seasonRows as $r) {
  $roundsAll[(int)$r['Round']][] = $r;
}

$hubRounds = [];
foreach ($roundsAll as $roundNo => $roundRows) {
  $playedCount = count(array_filter($roundRows, fn($r) => $r['_played']));
  $status = $playedCount === 0 ? 'upcoming' : ($playedCount === count($roundRows) ? 'complete' : 'in_progress');

  // Apply the filters to the rows shown
  $shown = array_values(array_filter($roundRows, function ($r) use ($filterTeam, $filterStatus) {
    if ($filterTeam !== '' && $r['HomeTeamSlug'] !== $filterTeam && $r['AwayTeamSlug'] !== $filterTeam) {
      return false;
    }
    if ($filterStatus === 'results' && !$r['_played']) {
      return false;
    }
    if ($filterStatus === 'fixtures' && $r['_played']) {
      return false;
    }
    return true;
  }));

  // A round with nothing to show for the active filters is not printed
  if (!$shown) {
    continue;
  }

  // Day groups: upcoming oldest first, complete newest first, in progress chronological
  usort($shown, fn($a, $b) => $a['_ts'] <=> $b['_ts']);
  $days = [];
  foreach ($shown as $r) {
    $days[date('Y-m-d', $r['_ts'])][] = $r;
  }
  if ($status === 'complete') {
    $days = array_reverse($days, true);
  }

  $timestamps = array_column($roundRows, '_ts');
  $hubRounds[$roundNo] = [
    'round'  => $roundNo,
    'status' => $status,
    'from'   => min($timestamps),
    'to'     => max($timestamps),
    'days'   => $days,
  ];
}

// Round order: fixtures soonest first; otherwise newest round first
if ($filterStatus === 'fixtures') {
  ksort($hubRounds);
} else {
  krsort($hubRounds);
}
$hubRounds = array_values($hubRounds);

/**
 * "10–12 Oct", "30 Sep–2 Oct" or "18 Sep" for a round's date span.
 */
function hub_date_range(int $from, int $to): string
{
  if (date('Y-m-d', $from) === date('Y-m-d', $to)) {
    return date('j M', $from);
  }
  if (date('Y-m', $from) === date('Y-m', $to)) {
    return date('j', $from) . '–' . date('j M', $to);
  }

  return date('j M', $from) . '–' . date('j M', $to);
}

/**
 * Hub row in the shared .match_row markup.
 */
function hub_match_row(array $r): string
{
  return plstats_match_row([
    'Date'          => $r['Date'],
    'Round'         => $r['Round'],
    'HomeName'      => $r['HomeTeamName'],
    'HomeSlug'      => $r['HomeTeamSlug'],
    'HomeLogo'      => $r['HomeTeamLogo'],
    'AwayName'      => $r['AwayTeamName'],
    'AwaySlug'      => $r['AwayTeamSlug'],
    'AwayLogo'      => $r['AwayTeamLogo'],
    'HomeTeamScore' => $r['HomeTeamScore'],
    'AwayTeamScore' => $r['AwayTeamScore'],
    'HasDetails'    => $r['_played'],
  ], $r['_played']);
}

// "Updated" (current season only): last data change for matches, as a UK date
$hubUpdated = '';
if ($selectedSeason === $activeSeason) {
  try {
    $updatedUtc = $pdo->query("SELECT UpdatedAtUtc FROM DataVersions WHERE Scope = 'matches'")->fetchColumn();
    if ($updatedUtc) {
      $hubUpdated = (new DateTime($updatedUtc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/London'))
        ->format('j M Y');
    }
  } catch (PDOException $e) {
    error_log('Matches hub: data version query failed.');
  }
}

// Rounds shown before "Load earlier rounds"
const HUB_ROUNDS_FIRST = 2;
const HUB_ROUNDS_STEP  = 2;
