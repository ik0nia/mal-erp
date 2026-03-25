<?php
// GitHub Webhook — auto-pull on push to main
// Secret configurat în GitHub repo settings → Webhooks

$secret = 'c78e0ccfc829f7c845740fa2808684650c3468729bf27387889264d2a70e45d6';

// Verifică semnătura GitHub
$payload = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if ($secret && $sig !== 'sha256=' . hash_hmac('sha256', $payload, $secret)) {
    http_response_code(403);
    exit('Unauthorized');
}

$data = json_decode($payload, true);
// Doar push pe branch-ul main
if (($data['ref'] ?? '') !== 'refs/heads/main') {
    exit('Not main branch, skipped');
}

$repoPath = '/var/www/erp';
$logFile  = $repoPath . '/storage/logs/deploy.log';
$ts       = date('Y-m-d H:i:s');

// Verifică că working tree e curat
exec("git -C {$repoPath} status --porcelain 2>&1", $statusOut, $statusCode);
$dirty = implode("\n", $statusOut);
if (!empty($dirty)) {
    file_put_contents($logFile, "[{$ts}] SKIP: working tree dirty\n{$dirty}\n\n", FILE_APPEND);
    exit('Working tree dirty, skipped');
}

// Pull
exec("git -C {$repoPath} pull --ff-only origin main 2>&1", $pullOut, $pullCode);
$pullLog = implode("\n", $pullOut);
file_put_contents($logFile, "[{$ts}] pull exit={$pullCode}\n{$pullLog}\n", FILE_APPEND);

if ($pullCode !== 0) {
    http_response_code(500);
    exit("Pull failed: {$pullLog}");
}

// Dacă s-a schimbat ceva, regenerează autoload și curăță cache
if (strpos($pullLog, 'Already up to date') === false) {
    exec("cd {$repoPath} && composer dump-autoload -o 2>&1", $autoloadOut);
    file_put_contents($logFile, "[{$ts}] dump-autoload: " . implode(' ', $autoloadOut) . "\n", FILE_APPEND);

    exec("php /var/www/erp/artisan view:clear 2>&1", $viewOut);
    exec("php /var/www/erp/artisan cache:clear 2>&1", $cacheOut);
    file_put_contents($logFile, "[{$ts}] view:clear: " . implode(' ', $viewOut) . "\n", FILE_APPEND);
    file_put_contents($logFile, "[{$ts}] cache:clear: " . implode(' ', $cacheOut) . "\n\n", FILE_APPEND);
}

http_response_code(200);
echo 'OK';
