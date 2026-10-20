<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Not in your package - MY MOTIVE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#F6F7FB}.card{border:0;border-radius:18px;box-shadow:0 12px 40px rgba(30,35,51,.08)}</style>
</head>
<body>
<div class="container py-5" style="max-width:560px">
    <div class="card p-4 p-md-5 text-center">
        <div class="fs-1 mb-2">🔒</div>
        <h4 class="mb-2"><?= htmlspecialchars($featureLabel, ENT_QUOTES, 'UTF-8'); ?> is not included in your package</h4>
        <?php if ($packageName !== '') { ?>
        <p class="text-muted">Your business is on the <b><?= htmlspecialchars($packageName, ENT_QUOTES, 'UTF-8'); ?></b> package.</p>
        <?php } else { ?>
        <p class="text-muted">Your business does not have an active package at the moment.</p>
        <?php } ?>
        <p class="text-muted">To add this, please ask your administrator to upgrade the package.</p>
        <a class="btn btn-primary" href="<?= htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') . '/modules/dashboard/index.php', ENT_QUOTES, 'UTF-8'); ?>">Back to the dashboard</a>
    </div>
</div>
</body>
</html>
