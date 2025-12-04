<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

session_start();

$rootDir = dirname(__DIR__, 2);
$configPath = $rootDir . '/OAuth_2/config.php';

if (!file_exists($configPath)) {
    die('Missing configuration file. Please configure OAuth_2/config.php');
}

$configs = include $configPath;
$environment = $configs['environment'] ?? 'sandbox';
$baseUrl = rtrim($configs['base_url'] ?? '', '/');
$minorVersion = $configs['minor_version'] ?? 75;

if (empty($_SESSION['access_token']) || empty($_SESSION['realm_id'])) {
    echo "<h2>Error: Not Authenticated</h2>";
    echo "<p>Please complete the OAuth flow first.</p>";
    echo "<a href='../../OAuth_2/index.php'>Go to OAuth Home</a>";
    exit();
}

$accessToken = $_SESSION['access_token'];
$realmId = $_SESSION['realm_id'];

$errors = [];
$messages = [];
$timeActivities = [];

function fetchTimeActivities(string $accessToken, string $realmId, string $baseUrl, int $minorVersion, string $startDate): array
{
    $query = sprintf(
        "Select * from TimeActivity where TxnDate >= '%s' startposition 1 maxresults 50",
        $startDate
    );
    $url = "{$baseUrl}/v3/company/{$realmId}/query?minorversion={$minorVersion}";

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
        'Content-Type: application/text'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $query);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new RuntimeException("TimeActivity query failed: {$curlErr}");
    }

    $decoded = json_decode($response, true);
    if ($decoded === null || !isset($decoded['QueryResponse']['TimeActivity'])) {
        if (($decoded['QueryResponse'] ?? null) === null || empty($decoded['QueryResponse'])) {
            return [];
        }
        throw new RuntimeException("Unexpected TimeActivity response ({$httpCode}): {$response}");
    }

    return $decoded['QueryResponse']['TimeActivity'];
}

$startDate = date('Y-m-d', strtotime('-30 days'));

try {
    $timeActivities = fetchTimeActivities($accessToken, $realmId, $baseUrl, $minorVersion, $startDate);
} catch (Throwable $e) {
    $errors[] = 'TimeActivity query failed: ' . $e->getMessage();
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title>Time Activities (Last 30 Days)</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1, h2 { margin-top: 30px; }
        table { border-collapse: collapse; width: 100%; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f8f8f8; }
        .info, .error, .success { padding: 12px; border-radius: 4px; margin: 10px 0; }
        .info { background: #f0f8ff; border: 1px solid #cce; }
        .error { background: #ffecec; border: 1px solid #f5a2a2; color: #a00; }
        .success { background: #f0fff0; border: 1px solid #9c9; color: #155724; }
    </style>
</head>
<body>
    <h1>Time Activities (Last 30 Days)</h1>
    <div class="info">
        <strong>Environment:</strong> <?= htmlspecialchars($environment) ?> |
        <strong>Realm ID:</strong> <?= htmlspecialchars($realmId) ?> |
        <strong>Query start date:</strong> <?= htmlspecialchars($startDate) ?>
    </div>

    <?php foreach ($messages as $msg): ?>
        <div class="success"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>

    <?php foreach ($errors as $err): ?>
        <div class="error"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <?php if ($timeActivities): ?>
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Date</th>
                <th>Employee</th>
                <th>Customer</th>
                <th>Hours</th>
                <th>Minutes</th>
                <th>Description</th>
                <th>Billable Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($timeActivities as $activity): ?>
                <tr>
                    <td><?= htmlspecialchars($activity['Id'] ?? '') ?></td>
                    <td><?= htmlspecialchars($activity['TxnDate'] ?? '') ?></td>
                    <td><?= htmlspecialchars($activity['EmployeeRef']['value'] ?? '') ?></td>
                    <td><?= htmlspecialchars($activity['CustomerRef']['value'] ?? '') ?></td>
                    <td><?= htmlspecialchars($activity['Hours'] ?? '') ?></td>
                    <td><?= htmlspecialchars($activity['Minutes'] ?? '') ?></td>
                    <td><?= htmlspecialchars($activity['Description'] ?? '') ?></td>
                    <td><?= htmlspecialchars($activity['BillableStatus'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No TimeActivity records found in the last 30 days.</p>
    <?php endif; ?>

    <div style="margin-top: 24px;">
        <a href="timeActivity.php">← Back to Time Activity Builder</a> |
        <a href="../../OAuth_2/index.php">OAuth Home</a>
    </div>
</body>
</html>

