<?php

/**
 * Data for the match page (matches/match.php).
 *
 * Expects from the page: $pdo, $match, $round, $season, $statsText, $statsIsRaw,
 * $lineupData, $review, $match_h1_in_template. Builds everything the template
 * prints; each module is empty when its data is missing, so it isn't printed.
 */

if (!isset($pdo, $match)) {
  http_response_code(404);
  exit;
}

$homeId   = (int)$match['HomeTeamId'];
$awayId   = (int)$match['AwayTeamId'];
$matchId  = (int)$match['Id'];
$isPlayed = ($match['HomeTeamScore'] !== null && $match['AwayTeamScore'] !== null);
$homeGoals = $isPlayed ? (int)$match['HomeTeamScore'] : null;
$awayGoals = $isPlayed ? (int)$match['AwayTeamScore'] : null;

$kickOff  = new DateTime($match['Date'], new DateTimeZone('Europe/London'));
$metaLine = 'Premier League · Round ' . (int)$round . ' · ' . $kickOff->format('D j M Y, H:i');

$teamNames = [$homeId => $match['HomeTeamName'], $awayId => $match['AwayTeamName']];

/* ----------------------------------------
   Events (goals, cards, subs, VAR)
---------------------------------------- */
function match_minute(array $e): string
{
  return (int)$e['Minute'] . ($e['AddedTime'] ? '+' . (int)$e['AddedTime'] : '') . "'";
}

$events = [];
try {
  $eventStmt = $pdo->prepare("
    SELECT e.TeamId, e.SortOrder, e.Minute, e.AddedTime, e.Type, e.PlayerId, e.PlayerName,
           e.RelatedPlayerId, e.RelatedPlayerName, e.Detail,
           p.Slug AS PlayerSlug, COALESCE(p.Name, p.ShortName) AS PlayerDisplay,
           rp.Slug AS RelatedSlug, COALESCE(rp.Name, rp.ShortName) AS RelatedDisplay
    FROM MatchEvents e
    LEFT JOIN Players p ON p.Id = e.PlayerId AND p.DeleteDate IS NULL
    LEFT JOIN Players rp ON rp.Id = e.RelatedPlayerId AND rp.DeleteDate IS NULL
    WHERE e.MatchId = :match_id
      AND e.DeleteDate IS NULL
    ORDER BY e.SortOrder
  ");
  $eventStmt->execute(['match_id' => $matchId]);
  $events = $eventStmt->fetchAll();
} catch (PDOException $e) {
  error_log('Match events query failed.');
}

$goalTypes = ['Goal', 'PenaltyGoal', 'OwnGoal'];

// Key events (Summary): goals, red cards, VAR decisions, with the running score
$keyEvents  = [];
$scorers    = [$homeId => [], $awayId => []];
$running    = [$homeId => 0, $awayId => 0];
$htScore    = [$homeId => 0, $awayId => 0];
foreach ($events as $e) {
  $teamId  = (int)$e['TeamId'];
  $isGoal  = in_array($e['Type'], $goalTypes, true);
  $isFirst = (int)$e['Minute'] <= 45;

  if ($isGoal && isset($running[$teamId])) {
    $running[$teamId]++;
    if ($isFirst) {
      $htScore[$teamId]++;
    }
  }

  $name = $e['PlayerDisplay'] ?: $e['PlayerName'];

  if ($isGoal) {
    // Header scorers: one line per player, minutes combined
    $key = $e['PlayerId'] ?: $name;
    if (!isset($scorers[$teamId][$key])) {
      $scorers[$teamId][$key] = ['name' => $name, 'slug' => $e['PlayerSlug'], 'minutes' => []];
    }
    $scorers[$teamId][$key]['minutes'][] = match_minute($e) . ($e['Type'] === 'PenaltyGoal' ? ' (pen)' : ($e['Type'] === 'OwnGoal' ? ' (og)' : ''));
  }

  if (!$isGoal && !in_array($e['Type'], ['RedCard', 'SecondYellow', 'VarDecision'], true)) {
    continue;
  }

  $detail = [];
  if ($e['Type'] === 'PenaltyGoal') {
    $detail[] = 'Penalty';
  } elseif ($e['Type'] === 'OwnGoal') {
    $detail[] = 'Own goal';
  } elseif ($e['Type'] === 'SecondYellow') {
    $detail[] = 'Second yellow card';
  } elseif ($e['Type'] === 'RedCard') {
    $detail[] = 'Red card';
  } elseif ($e['Type'] === 'VarDecision') {
    $detail[] = 'VAR' . ($e['Detail'] ? ': ' . $e['Detail'] : '');
  }
  if ($e['Type'] === 'Goal' && ($e['RelatedDisplay'] || $e['RelatedPlayerName'])) {
    $detail[] = 'assist ' . ($e['RelatedDisplay'] ?: $e['RelatedPlayerName']);
  }
  if (in_array($e['Type'], ['RedCard', 'SecondYellow'], true) && $e['Detail']) {
    $detail[] = $e['Detail'];
  }

  $keyEvents[] = [
    'minute'   => match_minute($e),
    'type'     => $isGoal ? 'goal' : ($e['Type'] === 'VarDecision' ? 'var' : 'red'),
    'team'     => $teamNames[$teamId] ?? '',
    'name'     => $name,
    'slug'     => $e['PlayerSlug'],
    'detail'   => implode(' · ', $detail),
    'score'    => $isGoal ? ($running[$homeId] . '–' . $running[$awayId]) : '',
    'first'    => $isFirst,
  ];
}

/* ----------------------------------------
   Lineups: starters in stored order, bench, subs
---------------------------------------- */
$lineupRows = [];
try {
  $lineupStmt = $pdo->prepare("
    SELECT l.TeamId, l.PlayerId, l.PlayerName, l.ShirtNumber, l.IsStarter, l.IsCaptain, l.IsGoalkeeper,
           l.Position, l.MinutesPlayed, l.Rating,
           p.Slug, p.Name AS FullName, COALESCE(p.Name, p.ShortName) AS DisplayName
    FROM MatchLineups l
    LEFT JOIN Players p ON p.Id = l.PlayerId AND p.DeleteDate IS NULL
    WHERE l.MatchId = :match_id
      AND l.DeleteDate IS NULL
    ORDER BY l.TeamId, l.IsStarter DESC, l.Id
  ");
  $lineupStmt->execute(['match_id' => $matchId]);
  $lineupRows = $lineupStmt->fetchAll();
} catch (PDOException $e) {
  error_log('Match lineups query failed.');
}

/**
 * Short label under a pitch circle: surname from the full name, or the first
 * token of the source's "Anthony J." form.
 */
function match_pitch_label(array $p): string
{
  if (!empty($p['FullName'])) {
    $parts = preg_split('/\s+/u', trim($p['FullName']));
    return end($parts);
  }

  return preg_split('/\s+/u', trim($p['PlayerName']))[0];
}

/**
 * A bench entry such as a lone "X" is a scraping artefact, not a player.
 */
function match_is_junk_name(?string $name): bool
{
  return $name === null || mb_strlen(trim($name)) <= 1;
}

$lineupSides = [];
foreach ([[$homeId, 'home', $lineupData['homeFormation'] ?? ''], [$awayId, 'away', $lineupData['awayFormation'] ?? '']] as [$teamId, $side, $formation]) {
  $starters = [];
  $bench    = [];
  foreach ($lineupRows as $p) {
    if ((int)$p['TeamId'] !== $teamId || match_is_junk_name($p['DisplayName'] ?: $p['PlayerName'])) {
      continue;
    }
    if ($p['IsStarter']) {
      $starters[] = $p;
    } else {
      $bench[] = $p;
    }
  }

  // Fallback to the text lineup when no structured rows exist (names only)
  if (!$starters && !empty($lineupData[$side . 'XI'])) {
    foreach ($lineupData[$side . 'XI'] as $i => $name) {
      if (!match_is_junk_name($name)) {
        $starters[] = ['PlayerName' => $name, 'DisplayName' => $name, 'FullName' => null, 'Slug' => null,
          'ShirtNumber' => null, 'IsGoalkeeper' => $i === 0 ? 1 : 0, 'IsCaptain' => 0, 'Rating' => null, 'MinutesPlayed' => null];
      }
    }
    foreach ($lineupData[$side . 'Substitutes'] ?? [] as $name) {
      if (!match_is_junk_name($name)) {
        $bench[] = ['PlayerName' => $name, 'DisplayName' => $name, 'Slug' => null, 'MinutesPlayed' => null];
      }
    }
  }

  if (!$starters) {
    continue;
  }

  // Pitch rows from the formation: GK + e.g. 4-2-3-1. Needs exactly 10 outfielders.
  $rows = null;
  if (preg_match('/^\d(-\d)+$/', trim($formation))) {
    $counts = array_map('intval', explode('-', trim($formation)));
    if (array_sum($counts) === 10 && count($starters) === 11 && !empty($starters[0]['IsGoalkeeper'])) {
      $rows = [[$starters[0]]];
      $offset = 1;
      foreach ($counts as $n) {
        $rows[] = array_slice($starters, $offset, $n);
        $offset += $n;
      }
    }
  }

  // Substitutes used (from the substitution events) and unused bench
  $subsUsed = [];
  $cameOn   = [];
  foreach ($events as $e) {
    if ($e['Type'] === 'Substitution' && (int)$e['TeamId'] === $teamId) {
      $subsUsed[] = [
        'name'    => $e['PlayerDisplay'] ?: $e['PlayerName'],
        'slug'    => $e['PlayerSlug'],
        'minute'  => match_minute($e),
        'replaced' => $e['RelatedDisplay'] ?: $e['RelatedPlayerName'],
      ];
      if ($e['PlayerId']) {
        $cameOn[(int)$e['PlayerId']] = true;
      }
    }
  }
  $unused = [];
  foreach ($bench as $p) {
    $wasSubbedOn = !empty($p['PlayerId']) && isset($cameOn[(int)$p['PlayerId']]);
    if (!$wasSubbedOn && empty($p['MinutesPlayed'])) {
      $unused[] = $p['DisplayName'] ?: $p['PlayerName'];
    }
  }
  // Text fallback has no sub events: list the bench as "Substitutes"
  $benchOnly = !$subsUsed && $bench && empty($bench[0]['PlayerId'] ?? null);

  $lineupSides[$side] = [
    'teamId'    => $teamId,
    'team'      => $teamNames[$teamId],
    'formation' => $rows ? trim($formation) : '',
    'rows'      => $rows,
    'starters'  => $starters,
    'subsUsed'  => $subsUsed,
    'unused'    => $unused,
    'benchOnly' => $benchOnly,
  ];
}

/* ----------------------------------------
   Player of the match: highest rating
---------------------------------------- */
$potm = null;
foreach ($lineupRows as $p) {
  if ($p['Rating'] !== null && ($potm === null || (float)$p['Rating'] > (float)$potm['Rating'])) {
    $potm = $p;
  }
}
if ($potm) {
  $potmGoals = 0;
  $potmAssists = 0;
  foreach ($events as $e) {
    if (in_array($e['Type'], ['Goal', 'PenaltyGoal'], true) && $potm['PlayerId'] && (int)$e['PlayerId'] === (int)$potm['PlayerId']) {
      $potmGoals++;
    }
    if (in_array($e['Type'], ['Goal', 'PenaltyGoal'], true) && $potm['PlayerId'] && (int)$e['RelatedPlayerId'] === (int)$potm['PlayerId']) {
      $potmAssists++;
    }
  }
  $contribution = [];
  if ($potmGoals) {
    $contribution[] = $potmGoals . ($potmGoals === 1 ? ' goal' : ' goals');
  }
  if ($potmAssists) {
    $contribution[] = $potmAssists . ($potmAssists === 1 ? ' assist' : ' assists');
  }
  if (!$contribution && $potm['Position']) {
    $contribution[] = $potm['Position'];
  }
  $potm['Club']         = $teamNames[(int)$potm['TeamId']] ?? '';
  $potm['Contribution'] = implode(', ', $contribution);
}

/* ----------------------------------------
   Commentary: "90+4'" lines start an entry,
   "3 - 0" lines carry the score after a goal
---------------------------------------- */
$commentaryItems = [];
$commentaryText  = trim((string)($match['Commentary'] ?? ''));
if ($commentaryText !== '') {
  $current = null;
  foreach (preg_split('/\r?\n/', $commentaryText) as $line) {
    $line = trim($line);
    // Provider boilerplate: "Show lineups" and the pre-match "welcome to our live ..." lines
    if ($line === '' || $line === 'Show lineups' || preg_match('/^(hello,\s*)?welcome to our live\b/i', $line)) {
      continue;
    }
    if (preg_match("/^(\d+(?:\+\d+)?)'$/", $line, $mm)) {
      if ($current) {
        $commentaryItems[] = $current;
      }
      $current = ['minute' => $mm[1] . "'", 'score' => '', 'text' => ''];
      continue;
    }
    if ($current && $current['text'] === '' && preg_match('/^(\d+)\s*-\s*(\d+)$/', $line, $sm)) {
      $current['score'] = $sm[1] . '–' . $sm[2];
      continue;
    }
    if ($current && $current['text'] === '') {
      $current['text'] = $line;
      continue;
    }
    // A line without its own minute (pre-match notes): its own entry
    if ($current) {
      $commentaryItems[] = $current;
    }
    $current = ['minute' => '', 'score' => '', 'text' => $line];
  }
  if ($current) {
    $commentaryItems[] = $current;
  }

  foreach ($commentaryItems as &$item) {
    $text = $item['text'];
    if (stripos($text, 'Goal!') === 0 || $item['score'] !== '') {
      $item['tag'] = 'goal';
    } elseif (preg_match('/\bred card\b|sent off|second yellow/i', $text)) {
      $item['tag'] = 'red';
    } elseif (preg_match('/\byellow card\b|\bbooked\b|into the book/i', $text)) {
      $item['tag'] = 'yellow';
    } elseif (preg_match('/game is over|full[- ]time whistle|final whistle/i', $text)) {
      $item['tag'] = 'fulltime';
    } else {
      $item['tag'] = '';
    }
  }
  unset($item);

  $commentaryItems = array_values(array_filter($commentaryItems, fn($i) => $i['text'] !== ''));
}

/* ----------------------------------------
   Team stats, grouped
---------------------------------------- */
$statGroupMap = [
  'Top stats' => ['Ball possession', 'Expected goals (xG)', 'Total shots', 'Shots on target', 'Big chances', 'Corner kicks'],
  'Shooting'  => ['xG on target (xGOT)', 'Big chances scored', 'Big chances missed', 'Shots inside the box', 'Shots outside the box', 'Shots off target', 'Blocked shots', 'Hit the woodwork', 'Headed goals'],
  'Passing'   => ['Passes', 'Long passes', 'Final third passes', 'Crosses', 'Key passes', 'Expected assists (xA)', 'Touches in opposition box', 'Throw-ins'],
  'Defending' => ['Tackles', 'Interceptions', 'Clearances', 'Duels won', 'Goalkeeper saves', 'Goals prevented', 'xGOT faced', 'Goals conceded', 'Errors leading to shot', 'Errors leading to goal'],
  'Discipline' => ['Fouls', 'Yellow cards', 'Red cards', 'Offsides', 'Free kicks'],
];
$statModelLabels = ['Expected goals (xG)', 'xG on target (xGOT)', 'Expected assists (xA)', 'xGOT faced', 'Goals prevented'];

$statRowsByName = [];
if ($statsText !== '' && !$statsIsRaw) {
  $body = preg_replace('/^MATCH\s+STATISTICS\s*\([^)]+\)\s*/i', '', $statsText);
  foreach (preg_split('/\r?\n/', $body) as $line) {
    if (!preg_match('/^(.+?):\s*([\d.]+%?)\s*\|\s*([\d.]+%?)\s*$/', trim($line), $mm)) {
      continue;
    }
    $name = trim($mm[1]);
    // Scraping artefacts such as "X" or an all-caps "SHOTS" header row
    if (mb_strlen($name) <= 1 || $name === mb_strtoupper($name)) {
      continue;
    }
    $statRowsByName[$name] = [
      'name'  => $name,
      'home'  => $mm[2],
      'away'  => $mm[3],
      'h'     => (float)rtrim($mm[2], '%'),
      'a'     => (float)rtrim($mm[3], '%'),
    ];
  }
}

$statGroups = [];
$placed     = [];
foreach ($statGroupMap as $group => $names) {
  foreach ($names as $name) {
    if (isset($statRowsByName[$name])) {
      $statGroups[$group][] = $statRowsByName[$name];
      $placed[$name] = true;
    }
  }
}
foreach ($statRowsByName as $name => $row) {
  if (!isset($placed[$name])) {
    $statGroups['Other'][] = $row;
  }
}
$statsHaveModel = (bool)array_intersect($statModelLabels, array_keys($statRowsByName));

// Summary "Top stats": possession split + five key rows
$possession = $statRowsByName['Ball possession'] ?? null;
$topStatRows = [];
foreach (['Expected goals (xG)', 'Total shots', 'Shots on target', 'Big chances', 'Corner kicks'] as $name) {
  if (isset($statRowsByName[$name])) {
    $topStatRows[] = $statRowsByName[$name];
  }
}

/**
 * Two-sided stat bar: lengths relative to the larger value, leader in teal.
 */
function match_stat_row(array $s, string $label = ''): string
{
  $max   = max($s['h'], $s['a']);
  $hw    = $max > 0 ? $s['h'] / $max * 100 : 0;
  $aw    = $max > 0 ? $s['a'] / $max * 100 : 0;
  $lead  = $s['h'] > $s['a'] ? 'home' : ($s['a'] > $s['h'] ? 'away' : '');

  return '<div class="mstat_row">'
    . '<div class="mstat_line">'
    . '<span class="mstat_val num' . ($lead === 'home' ? ' is_lead' : '') . '">' . htmlspecialchars($s['home']) . '</span>'
    . '<span class="mstat_label">' . htmlspecialchars($label ?: $s['name']) . '</span>'
    . '<span class="mstat_val mstat_val--away num' . ($lead === 'away' ? ' is_lead' : '') . '">' . htmlspecialchars($s['away']) . '</span>'
    . '</div>'
    . '<div class="mstat_bars" aria-hidden="true">'
    . '<span class="mstat_track mstat_track--home"><span class="mstat_fill' . ($lead === 'home' ? ' is_lead' : '') . '" style="width:' . number_format($hw, 1, '.', '') . '%"></span></span>'
    . '<span class="mstat_track"><span class="mstat_fill' . ($lead === 'away' ? ' is_lead' : '') . '" style="width:' . number_format($aw, 1, '.', '') . '%"></span></span>'
    . '</div>'
    . '</div>';
}

/* ----------------------------------------
   Review HTML: clean-up for display
   - footer metadata (Score / Competition / Date / Venue) removed
   - "{Home} vs {Away} Lineups" section removed (the Lineups tab shows it)
   - "Key Moments:" prefixes removed; the list gets a "Key moments" label
   - with the template H1: h1 -> h2, h2 -> h3
---------------------------------------- */
$reviewHtml          = '';
$reviewRemovedFooter = '';
if ($review && trim((string)$review['ReviewHtml']) !== '') {
  $doc = new DOMDocument();
  libxml_use_internal_errors(true);
  $loaded = $doc->loadHTML('<?xml encoding="utf-8"?><div id="review_root">' . $review['ReviewHtml'] . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
  libxml_clear_errors();

  if ($loaded) {
    $xp   = new DOMXPath($doc);
    $root = $doc->getElementById('review_root') ?: $xp->query('//div[@id="review_root"]')->item(0);

    // Footer metadata that duplicates the score header
    foreach (iterator_to_array($xp->query('.//footer', $root)) as $footer) {
      if (preg_match('/\b(Venue|Competition|Date|Score)\s*:/i', $footer->textContent)) {
        $reviewRemovedFooter = trim(preg_replace('/\s+/', ' ', $footer->textContent));
        $footer->parentNode->removeChild($footer);
      }
    }

    // Lineups prose section, matched by its heading
    foreach (iterator_to_array($xp->query('.//section[h2]', $root)) as $section) {
      $h2 = $xp->query('./h2', $section)->item(0);
      if ($h2 && preg_match('/\bLineups\s*$/i', trim($h2->textContent))) {
        $section->parentNode->removeChild($section);
      }
    }

    // Key moments lists
    foreach (iterator_to_array($xp->query('.//ul[li]', $root)) as $ul) {
      $isKeyMoments = false;
      foreach ($xp->query('./li', $ul) as $li) {
        if (preg_match('/^\s*Key Moments\s*:/i', $li->textContent)) {
          $isKeyMoments = true;
          // Drop a leading <strong>Key Moments:</strong> or the text prefix
          $first = $li->firstChild;
          while ($first && $first->nodeType === XML_TEXT_NODE && trim($first->textContent) === '') {
            $first = $first->nextSibling;
          }
          if ($first && $first->nodeType === XML_ELEMENT_NODE && preg_match('/^\s*Key Moments\s*:?\s*$/i', $first->textContent)) {
            $li->removeChild($first);
          } elseif ($first && $first->nodeType === XML_TEXT_NODE) {
            $first->nodeValue = preg_replace('/^\s*Key Moments\s*:\s*/i', '', $first->nodeValue);
          }
          // Trim the space left at the start
          $lead = $li->firstChild;
          if ($lead && $lead->nodeType === XML_TEXT_NODE) {
            $lead->nodeValue = ltrim($lead->nodeValue);
          }
        }
      }
      if ($isKeyMoments) {
        $box = $doc->createElement('div');
        $box->setAttribute('class', 'key_moments');
        $label = $doc->createElement('p', 'Key moments');
        $label->setAttribute('class', 'key_moments_label');
        $ul->parentNode->replaceChild($box, $ul);
        $box->appendChild($label);
        $box->appendChild($ul);
      }
    }

    // Heading levels under the template's H1
    if (!empty($match_h1_in_template)) {
      foreach (['h2' => 'h3', 'h1' => 'h2'] as $from => $to) {
        foreach (iterator_to_array($xp->query('.//' . $from, $root)) as $h) {
          $new = $doc->createElement($to);
          while ($h->firstChild) {
            $new->appendChild($h->firstChild);
          }
          foreach ($h->attributes as $attr) {
            $new->setAttribute($attr->name, $attr->value);
          }
          $h->parentNode->replaceChild($new, $h);
        }
      }
    }

    foreach ($root->childNodes as $child) {
      $reviewHtml .= $doc->saveHTML($child);
    }
  } else {
    $reviewHtml = $review['ReviewHtml'];
  }
}

/* ----------------------------------------
   More from this round (same season)
---------------------------------------- */
$roundMatches = [];
try {
  $seasonStartYear = (int)substr($season, 0, 4);
  $roundStmt = $pdo->prepare("
    SELECT m.Date, m.Round, m.HomeTeamScore, m.AwayTeamScore,
           ht.Name AS HomeName, ht.Slug AS HomeSlug, at.Name AS AwayName, at.Slug AS AwaySlug
    FROM Matches m
    JOIN Teams ht ON ht.Id = m.HomeTeamId
    JOIN Teams at ON at.Id = m.AwayTeamId
    WHERE m.Round = :round
      AND m.Id <> :match_id
      AND m.DeleteDate IS NULL
      AND m.Date >= :season_start
      AND m.Date < :season_end
    ORDER BY m.Date, m.Id
  ");
  $roundStmt->execute([
    'round'        => (int)$round,
    'match_id'     => $matchId,
    'season_start' => "$seasonStartYear-08-01 00:00:00",
    'season_end'   => ($seasonStartYear + 1) . '-08-01 00:00:00',
  ]);
  $roundMatches = $roundStmt->fetchAll();
} catch (PDOException $e) {
  error_log('Match round query failed.');
}

$hasSummary = $keyEvents || $possession || $topStatRows || $potm || $reviewHtml !== '';
