<?php
http_response_code(404);
require_once __DIR__ . '/includes/functions/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>404 Not Found – plstats.uk</title>
  <meta name="description" content="Page not found on plstats.uk. Return to our homepage to explore trusted Non-GamStop casino reviews and guides.">

  <!-- Canonical Tag -->
  <link rel="canonical" href="<?= htmlspecialchars(plstats_url('/404')) ?>" />

  <!-- Prevent Indexing -->
  <meta name="robots" content="noindex, nofollow">

  <?php include 'includes/blocks/head.php'; ?>
</head>

<body>
  <?php include 'includes/blocks/navbar.php'; ?>

  <section class="container section_wrapper" style="text-align:center; padding: 100px 20px;">
    <h1>404 – Page Not Found</h1>
    <p>
      Sorry, the page you're looking for doesn't exist or may have been moved.
    </p>
    <a href="<?= htmlspecialchars(plstats_url('/')) ?>" title="Return to Homepage">
      Back to Home
    </a>
  </section>

  <?php include 'includes/blocks/footer.php'; ?>
</body>

</html>
