<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The stub provider
|--------------------------------------------------------------------------
|
| Served by PHP's built-in server for the length of a soak run, this is
| the external service the ConnectorWorkflow calls. It refuses a request
| without the soak's bearer token, answers /items/{n} with whatever
| status its weights pick — including a 429 with Retry-After, which the
| durable retry must honour, and 5xx statuses the transport retries
| inside the attempt — and pages /pages until page four is empty.
|
| Its own log is not scanned for sentinels: it legitimately sees the
| token on every request. Only Laraflow's processes are held to that.
|
*/

$token = getenv('SOAK_TOKEN');
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

header('Content-Type: application/json');

if ($path === '/health') {
    echo json_encode(['ok' => true]);

    return;
}

$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if ($token === false || $token === '' || $authorization !== "Bearer {$token}") {
    http_response_code(401);
    echo json_encode(['message' => 'Bad credentials']);

    return;
}

if (preg_match('#^/items/(\d+)$#', $path, $matches) === 1) {
    $roll = random_int(1, 100);

    if ($roll <= 70) {
        echo json_encode(['item' => ['id' => (int) $matches[1], 'name' => 'Item '.$matches[1]]]);
    } elseif ($roll <= 80) {
        http_response_code(429);
        header('Retry-After: '.random_int(1, 2));
        echo json_encode(['message' => 'Slow down']);
    } elseif ($roll <= 88) {
        http_response_code(500);
        echo json_encode(['message' => 'Internal error']);
    } elseif ($roll <= 95) {
        http_response_code(503);
        echo json_encode(['message' => 'Try later']);
    } else {
        http_response_code(404);
        echo json_encode(['message' => 'Not found']);
    }

    return;
}

if ($path === '/pages') {
    $page = max(1, (int) ($_GET['page'] ?? 1));

    echo json_encode(['items' => $page > 3 ? [] : array_map(fn (int $i): array => ['id' => ($page - 1) * 5 + $i], range(1, 5))]);

    return;
}

http_response_code(404);
echo json_encode(['message' => 'No such route']);
