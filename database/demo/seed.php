<?php

/**
 * Seeds the demo SQLite database with 7 days of monitoring data shaped like
 * the recorders' output, so every dashboard tab has something to show.
 *
 * Usage: vendor/bin/testbench migrate && php database/demo/seed.php [path/to/database.sqlite]
 */

$database = $argv[1] ?? __DIR__.'/demo.sqlite';

if (! file_exists($database)) {
    fwrite(STDERR, "Database not found: {$database}\nRun `vendor/bin/testbench migrate` first.\n");
    exit(1);
}

$pdo = new PDO('sqlite:'.$database);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('DELETE FROM monitor_entries');

$insert = $pdo->prepare(
    'INSERT INTO monitor_entries (type, subtype, key, payload, duration, user_id, created_at)
     VALUES (:type, :subtype, :key, :payload, :duration, :user_id, :created_at)'
);

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$start = $now->sub(new DateInterval('P7D'));
$rows = 0;

$record = function (
    DateTimeImmutable $at,
    string $type,
    ?string $subtype = null,
    ?string $key = null,
    ?array $payload = null,
    ?int $duration = null,
    ?int $userId = null,
) use ($insert, &$rows) {
    $insert->execute([
        'type' => $type,
        'subtype' => $subtype,
        'key' => $key,
        'payload' => $payload !== null ? json_encode($payload) : null,
        'duration' => $duration,
        'user_id' => $userId,
        'created_at' => $at->format('Y-m-d H:i:s'),
    ]);
    $rows++;
};

// Deterministic output so repeated seeds produce the same dashboard.
mt_srand(20260711);

$randomTime = fn (DateTimeImmutable $hour) => $hour->add(new DateInterval('PT'.mt_rand(0, 3599).'S'));

// Traffic curve: quiet nights, busy afternoons.
$requestsPerHour = fn (int $hour) => (int) round(8 + 22 * max(0, sin(($hour - 6) * M_PI / 14)));

$routes = [
    // [key, method, path, weight, typical ms]
    ['GET /', 'GET', '/', 20, 45],
    ['GET /dashboard', 'GET', '/dashboard', 15, 120],
    ['GET /api/orders', 'GET', '/api/orders', 18, 90],
    ['POST /api/orders', 'POST', '/api/orders', 8, 220],
    ['GET /api/orders/{order}', 'GET', '/api/orders/42', 12, 70],
    ['GET /api/products', 'GET', '/api/products', 14, 110],
    ['POST /login', 'POST', '/login', 6, 180],
    ['POST /api/checkout', 'POST', '/api/checkout', 4, 450],
    ['GET /reports/monthly', 'GET', '/reports/monthly', 2, 1400],
];
$routeWeights = array_sum(array_column($routes, 3));

$pickRoute = function () use ($routes, $routeWeights) {
    $roll = mt_rand(1, $routeWeights);
    foreach ($routes as $route) {
        if (($roll -= $route[3]) <= 0) {
            return $route;
        }
    }

    return $routes[0];
};

$jobs = [
    'App\\Jobs\\SendWelcomeEmail' => 350,
    'App\\Jobs\\ProcessPodcast' => 4200,
    'App\\Jobs\\GenerateInvoice' => 900,
    'App\\Jobs\\SyncInventory' => 2600,
];

$exceptions = [
    ['App\\Exceptions\\PaymentFailedException', 'Charge declined by provider: insufficient_funds', 'app/Services/PaymentService.php', 87],
    ['Illuminate\\Database\\QueryException', "SQLSTATE[40001]: Serialization failure: deadlock detected", 'app/Repositories/OrderRepository.php', 142],
    ['ErrorException', 'Undefined array key "shipping_address"', 'app/Http/Controllers/CheckoutController.php', 63],
    ['Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException', 'No query results for model [App\\Models\\Order] 9931', 'vendor/laravel/framework/src/Illuminate/Routing/Middleware/SubstituteBindings.php', 44],
];

$notifications = [
    ['App\\Notifications\\OrderShipped', 'mail'],
    ['App\\Notifications\\OrderShipped', 'database'],
    ['App\\Notifications\\InvoicePaid', 'mail'],
    ['App\\Notifications\\DeploymentFinished', 'slack'],
    ['App\\Notifications\\LowStockAlert', 'database'],
];

$mails = [
    ['Welcome to Acme', 'new-user@example.com'],
    ['Your order has shipped', 'customer@example.com'],
    ['Invoice #INV-2026 is ready', 'billing@example.com'],
    ['Password reset request', 'user@example.com'],
];

$outgoing = [
    ['POST https://api.stripe.com/v1/charges', 'POST', 'https://api.stripe.com/v1/charges', 320],
    ['GET https://api.exchangerate.host/latest', 'GET', 'https://api.exchangerate.host/latest', 180],
    ['POST https://hooks.slack.com/services/T00/B00', 'POST', 'https://hooks.slack.com/services/T00/B00', 240],
    ['GET https://api.github.com/repos/acme/app/releases', 'GET', 'https://api.github.com/repos/acme/app/releases', 410],
];

$slowQueries = [
    ['select * from "orders" where "status" = ? order by "created_at" desc', 'app/Repositories/OrderRepository.php:58'],
    ['select "products".*, sum("order_items"."qty") as "sold" from "products" left join "order_items" on ... group by "products"."id"', 'app/Services/ReportService.php:112'],
    ['update "inventory" set "stock" = "stock" - ? where "sku" in (...)', 'app/Jobs/SyncInventory.php:74'],
];

$schedule = [
    ['backup:run', 'Back up the database', '0 2 * * *'],
    ['queue:prune-batches', 'Prune stale batches', '0 3 * * *'],
    ['reports:generate-daily', 'Generate daily sales report', '0 6 * * *'],
    ['inventory:sync', 'Sync inventory with warehouse', '0 * * * *'],
];

$logs = [
    ['Payment webhook retried', 'warning'],
    ['Failed to resolve shipping rate, falling back to flat rate', 'warning'],
    ['Order export took longer than expected', 'warning'],
    ['Cache store unreachable, using array fallback', 'error'],
];

$cacheKeys = ['products.featured', 'settings.global', 'user.7.permissions', 'reports.daily.summary', 'exchange-rates.latest'];

$users = [
    1 => 'ana@example.com', 2 => 'bao@example.com', 3 => 'chi@example.com',
    4 => 'dan@example.com', 5 => 'emi@example.com', 6 => 'felix@example.com',
    7 => 'giang@example.com', 8 => 'huy@example.com',
];

for ($hour = clone $start; $hour < $now; $hour = $hour->add(new DateInterval('PT1H'))) {
    $h = (int) $hour->format('G');
    $count = $requestsPerHour($h);

    for ($i = 0; $i < $count; $i++) {
        $at = $randomTime($hour);
        [$key, $method, $path, , $typicalMs] = $pickRoute();

        $roll = mt_rand(1, 100);
        $status = match (true) {
            $roll <= 90 => 200,
            $roll <= 96 => [401, 404, 404, 422][mt_rand(0, 3)],
            default => 500,
        };
        $subtype = $status >= 500 ? '5xx' : ($status >= 400 ? '4xx' : '2xx');
        $duration = max(5, (int) round($typicalMs * (0.5 + mt_rand(0, 150) / 100)));
        $userId = mt_rand(1, 3) === 1 ? array_rand($users) : null;

        $record($at, 'request', $subtype, $key, [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'ip' => '192.168.1.'.mt_rand(2, 254),
        ], $duration, $userId);

        // 5xx responses come with an exception entry, like the real recorders.
        if ($subtype === '5xx') {
            [$class, $message, $file, $line] = $exceptions[array_rand($exceptions)];
            $record($at, 'exception', null, $class, [
                'class' => $class,
                'message' => $message,
                'file' => $file,
                'line' => $line,
                'trace' => [
                    $file.':'.$line.' '.$class.'->handle',
                    'app/Http/Kernel.php:38 App\\Http\\Kernel->handle',
                    'public/index.php:52 require_once',
                ],
            ]);
        }

        // Cache chatter alongside requests.
        if (mt_rand(1, 3) === 1) {
            $record($at, 'cache', ['hit', 'hit', 'hit', 'miss', 'write'][mt_rand(0, 4)], $cacheKeys[array_rand($cacheKeys)]);
        }
    }

    // Jobs: a few per hour, occasionally failing.
    for ($i = 0, $n = mt_rand(2, 6); $i < $n; $i++) {
        $at = $randomTime($hour);
        $job = array_rand($jobs);
        $typicalMs = $jobs[$job];

        $record($at, 'job', 'queued', $job, ['connection' => 'redis', 'queue' => 'default']);

        $done = $at->add(new DateInterval('PT'.mt_rand(1, 45).'S'));
        if (mt_rand(1, 20) === 1) {
            $record($done, 'job', 'failed', $job, [
                'connection' => 'redis',
                'queue' => 'default',
                'exception' => 'RuntimeException',
                'message' => 'Job exceeded maximum attempts',
            ], (int) round($typicalMs * 1.6));
        } else {
            $record($done, 'job', 'processed', $job, [
                'connection' => 'redis',
                'queue' => 'default',
            ], max(20, (int) round($typicalMs * (0.6 + mt_rand(0, 120) / 100))));
        }
    }

    // Outgoing HTTP calls.
    for ($i = 0, $n = mt_rand(1, 5); $i < $n; $i++) {
        $at = $randomTime($hour);
        [$key, $method, $url, $typicalMs] = $outgoing[array_rand($outgoing)];
        $roll = mt_rand(1, 100);

        if ($roll > 97) {
            $record($at, 'outgoing_request', 'failed', $key, ['method' => $method, 'url' => $url, 'status' => null]);
        } else {
            $status = $roll > 92 ? [429, 500, 502][mt_rand(0, 2)] : 200;
            $record($at, 'outgoing_request', $status >= 400 ? 'error' : 'success', $key, [
                'method' => $method,
                'url' => $url,
                'status' => $status,
            ], max(30, (int) round($typicalMs * (0.5 + mt_rand(0, 200) / 100))));
        }
    }

    // Slow queries surface a couple of times per hour.
    if (mt_rand(1, 2) === 1) {
        [$sql, $location] = $slowQueries[array_rand($slowQueries)];
        $record($randomTime($hour), 'slow_query', null, $sql, [
            'sql' => $sql,
            'connection' => 'mysql',
            'location' => $location,
        ], mt_rand(110, 950));
    }

    // Hourly inventory sync plus daily tasks at their scheduled hour.
    foreach ($schedule as [$command, $description, $expression]) {
        $runsThisHour = $command === 'inventory:sync'
            || ($command === 'backup:run' && $h === 2)
            || ($command === 'queue:prune-batches' && $h === 3)
            || ($command === 'reports:generate-daily' && $h === 6);

        if (! $runsThisHour) {
            continue;
        }

        $failed = mt_rand(1, 30) === 1;
        $record($hour->add(new DateInterval('PT'.mt_rand(0, 59).'S')), 'scheduled_task', $failed ? 'failed' : 'finished', $command, array_filter([
            'command' => $command,
            'description' => $description,
            'expression' => $expression,
            'error' => $failed ? 'Process exited with code 1' : null,
        ]), $failed ? null : mt_rand(400, 20000));
    }

    // Notifications and mail, mostly during the day.
    if ($h >= 7 && mt_rand(1, 2) === 1) {
        $at = $randomTime($hour);
        [$class, $channel] = $notifications[array_rand($notifications)];
        $userId = array_rand($users);
        $record($at, 'notification', $channel, $class, [
            'notification' => $class,
            'channel' => $channel,
            'notifiable' => 'App\\Models\\User#'.$userId,
        ]);
    }

    if ($h >= 7 && mt_rand(1, 3) === 1) {
        [$subject, $to] = $mails[array_rand($mails)];
        $record($randomTime($hour), 'mail', null, $subject, ['subject' => $subject, 'to' => $to]);
    }

    // Auth activity.
    if (mt_rand(1, 2) === 1) {
        $at = $randomTime($hour);
        $userId = array_rand($users);
        $roll = mt_rand(1, 10);
        if ($roll <= 6) {
            $record($at, 'auth', 'login', $users[$userId], ['guard' => 'web'], null, $userId);
        } elseif ($roll <= 9) {
            $record($at, 'auth', 'logout', $users[$userId], ['guard' => 'web'], null, $userId);
        } else {
            $record($at, 'auth', 'failed', $users[$userId], ['guard' => 'web']);
        }
    }

    // Warning/error log entries.
    if (mt_rand(1, 4) === 1) {
        [$message, $level] = $logs[array_rand($logs)];
        $record($randomTime($hour), 'log', $level, $message, [
            'message' => $message,
            'level' => $level,
            'context' => '{}',
        ]);
    }
}

echo "Seeded {$rows} monitor entries into {$database}\n";
