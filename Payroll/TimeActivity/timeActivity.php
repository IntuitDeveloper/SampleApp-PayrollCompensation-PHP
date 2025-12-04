<?php
// Display errors except deprecated notices for clarity
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

$graphQlEndpoint = $configs['payroll_graphql_endpoint'] ?? null;
if (!$graphQlEndpoint) {
    $graphQlEndpoint = ($environment === 'production')
        ? 'https://qb.api.intuit.com/graphql'
        : 'https://qb-sandbox.api.intuit.com/graphql';
}

if (empty($_SESSION['access_token']) || empty($_SESSION['realm_id'])) {
    echo "<h2>Error: Not Authenticated</h2>";
    echo "<p>Please complete the OAuth flow first.</p>";
    echo "<a href='../../OAuth_2/index.php'>Go to OAuth Home</a>";
    exit();
}

$accessToken = $_SESSION['access_token'];
$realmId = $_SESSION['realm_id'];

$graphQlDir = dirname(__DIR__) . '/graphql';
$payrollQueryFile = $graphQlDir . '/payroll.graphql';

$errors = [];
$messages = [];
$employees = [];
$customers = [];
$compensations = [];
$timeActivityResult = null;

function readGraphQlFile(string $path): string
{
    if (!file_exists($path)) {
        throw new RuntimeException("GraphQL file not found: {$path}");
    }
    return trim(file_get_contents($path));
}

function executeRestQuery(string $accessToken, string $realmId, string $baseUrl, string $query, int $minorVersion = 75): array
{
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
        throw new RuntimeException("REST query failed: {$curlErr}");
    }

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        throw new RuntimeException("Unexpected REST response ({$httpCode}): {$response}");
    }

    return $decoded;
}

function graphQlRequest(string $endpoint, string $accessToken, string $realmId, string $query, array $variables = []): array
{
    $payload = json_encode([
        'query' => $query,
        'variables' => $variables
    ]);

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Accept: application/json',
        'intuit-realm-id' => $realmId
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new RuntimeException("GraphQL request failed: {$curlErr}");
    }

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        throw new RuntimeException("Invalid GraphQL response ({$httpCode}): {$response}");
    }

    if (!empty($decoded['errors'])) {
        $messages = array_map(fn($err) => $err['message'] ?? json_encode($err), $decoded['errors']);
        throw new RuntimeException('GraphQL error: ' . implode('; ', $messages));
    }

    return $decoded['data'] ?? [];
}

function fetchEmployees(string $accessToken, string $realmId, string $baseUrl, int $minorVersion = 75): array
{
    $decoded = executeRestQuery($accessToken, $realmId, $baseUrl, 'Select * from Employee startposition 1 maxresults 10', $minorVersion);
    if (empty($decoded['QueryResponse']['Employee'])) {
        throw new RuntimeException('No employee data returned.');
    }
    return $decoded['QueryResponse']['Employee'];
}

function fetchCustomers(string $accessToken, string $realmId, string $baseUrl, int $minorVersion = 75): array
{
    $decoded = executeRestQuery($accessToken, $realmId, $baseUrl, 'Select * from Customer startposition 1 maxresults 10', $minorVersion);
    if (empty($decoded['QueryResponse']['Customer'])) {
        throw new RuntimeException('No customer data returned.');
    }
    return $decoded['QueryResponse']['Customer'];
}

function fetchEmployeeCompensations(
    string $accessToken,
    string $realmId,
    string $endpoint,
    string $queryFile,
    string $employeeId,
    bool $active = true
): array {
    if (!$employeeId) {
        return [];
    }

    $query = readGraphQlFile($queryFile);
    $variables = [
        'filter' => [
            'employeeId' => $employeeId,
            'active' => $active
        ]
    ];

    $data = graphQlRequest($endpoint, $accessToken, $realmId, $query, $variables);
    $edges = $data['payrollEmployeeCompensations']['edges'] ?? [];

    return array_map(fn($edge) => $edge['node'], $edges);
}

function createTimeActivityRest(
    string $accessToken,
    string $realmId,
    string $baseUrl,
    array $input,
    int $minorVersion = 75
): array {
    if (empty($input['customerId'])) {
        throw new InvalidArgumentException('Customer selection is required.');
    }

    $timeActivity = [
        'TxnDate' => $input['date'] ?: date('Y-m-d'),
        'NameOf' => 'Employee',
        'EmployeeRef' => ['value' => $input['employeeId']],
        'Hours' => (int) $input['hours'],
        'Minutes' => (int) $input['minutes'],
        'Description' => $input['description'] ?? 'Time activity (PHP sample)',
        'BillableStatus' => !empty($input['billable']) ? 'Billable' : 'NotBillable',
    ];

    if (!empty($input['projectId'])) {
        $timeActivity['ProjectRef'] = ['value' => $input['projectId']];
    }
    if (!empty($input['customerId'])) {
        $timeActivity['CustomerRef'] = ['value' => $input['customerId']];
    }
    if (!empty($input['itemId'])) {
        $timeActivity['ItemRef'] = ['value' => $input['itemId']];
    }
    if (!empty($input['payrollItemId'])) {
        $timeActivity['PayrollItemRef'] = ['value' => $input['payrollItemId']];
    }
    if (isset($input['hourlyRate']) && $input['hourlyRate'] !== '') {
        $timeActivity['HourlyRate'] = (float) $input['hourlyRate'];
    }

    $url = "{$baseUrl}/v3/company/{$realmId}/timeactivity?minorversion={$minorVersion}";
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
        'Content-Type: application/json'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($timeActivity));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new RuntimeException("REST TimeActivity request failed: {$curlErr}");
    }

    $decoded = json_decode($response, true);
    if (!isset($decoded['TimeActivity'])) {
        throw new RuntimeException("Unexpected REST response ({$httpCode}): {$response}");
    }

    return [
        'http_status' => $httpCode,
        'body' => $decoded['TimeActivity'],
        'raw_response' => $response,
        '_transport' => 'rest'
    ];
}

// Handle create time activity submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_time_activity') {
    try {
        $input = [
            'employeeId' => trim($_POST['employeeId'] ?? ''),
            'projectId' => $_POST['projectId'] ?: null,
            'customerId' => $_POST['customerId'] ?: null,
            'itemId' => $_POST['itemId'] ?: null,
            'payrollItemId' => $_POST['payrollItemId'] ?: null,
            'date' => $_POST['txnDate'] ?: date('Y-m-d'),
            'hours' => (float) ($_POST['hours'] ?? 0),
            'minutes' => (int) ($_POST['minutes'] ?? 0),
            'hourlyRate' => isset($_POST['hourlyRate']) ? (float) $_POST['hourlyRate'] : null,
            'description' => $_POST['description'] ?? '',
            'billable' => isset($_POST['billable']) && $_POST['billable'] === '1'
        ];

        if (empty($input['employeeId'])) {
            throw new RuntimeException('Employee is required');
        }
        if ($input['hours'] <= 0 && $input['minutes'] <= 0) {
            throw new RuntimeException('Hours or minutes must be greater than zero');
        }

        $filteredInput = array_filter($input, fn($value) => $value !== null && $value !== '');
        $timeActivityResult = createTimeActivityRest(
            $accessToken,
            $realmId,
            $baseUrl,
            $filteredInput,
            $minorVersion
        );
        $messages[] = 'Time activity created successfully via REST v3 endpoint.';
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// Determine selected employee for compensation query
$selectedEmployeeId = $_POST['selectedEmployeeId'] ?? $_GET['employeeId'] ?? null;
$selectedCustomerId = $_POST['customerId'] ?? null;

try {
    $employees = fetchEmployees($accessToken, $realmId, $baseUrl, $minorVersion);
    if (!$selectedEmployeeId && !empty($employees)) {
        $selectedEmployeeId = $employees[0]['Id'];
    }
} catch (Throwable $e) {
    $errors[] = 'Employee fetch failed: ' . $e->getMessage();
}

try {
    $customers = fetchCustomers($accessToken, $realmId, $baseUrl, $minorVersion);
    if (!$selectedCustomerId && !empty($customers)) {
        $selectedCustomerId = $customers[0]['Id'];
    }
} catch (Throwable $e) {
    $errors[] = 'Customer fetch failed: ' . $e->getMessage();
}

try {
    if ($selectedEmployeeId) {
        $compensations = fetchEmployeeCompensations(
            $accessToken,
            $realmId,
            $graphQlEndpoint,
            $payrollQueryFile,
            $selectedEmployeeId,
            true
        );
    }
} catch (Throwable $e) {
    $errors[] = 'Compensation fetch failed: ' . $e->getMessage();
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title>Time Activity (PHP)</title>
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
        label { display: block; margin-top: 10px; font-weight: bold; }
        input[type=text], input[type=number], input[type=date], select, textarea {
            width: 100%; padding: 8px; box-sizing: border-box; margin-top: 4px;
        }
        textarea { min-height: 80px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; }
        .btn { display: inline-block; padding: 10px 16px; background: #0077C5; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        .btn-secondary { background: #6c757d; }
    </style>
</head>
<body>
    <h1>Time Activity Playground (PHP)</h1>
    <div class="info">
        <strong>Environment:</strong> <?= htmlspecialchars($environment) ?> |
        <strong>Realm ID:</strong> <?= htmlspecialchars($realmId) ?> |
        <strong>GraphQL Endpoint:</strong> <?= htmlspecialchars($graphQlEndpoint) ?>
    </div>

    <?php foreach ($messages as $msg): ?>
        <div class="success"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>

    <?php foreach ($errors as $err): ?>
        <div class="error"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>
    <p style="margin: 15px 0;">
        <a href="listTimeActivities.php" class="btn" style="background:#17a2b8;">View Time Activities (Last 30 Days)</a>
    </p>

    <h2>Employees (REST v3)</h2>
    <?php if ($employees): ?>
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Primary Email</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($employees as $employee): ?>
                <tr>
                    <td><?= htmlspecialchars($employee['Id']) ?></td>
                    <td><?= htmlspecialchars($employee['DisplayName'] ?? ($employee['GivenName'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($employee['PrimaryEmailAddr']['Address'] ?? '') ?></td>
                    <td><?= htmlspecialchars(($employee['Active'] ?? true) ? 'Active' : 'Inactive') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No employees returned.</p>
    <?php endif; ?>

    <h2>Employee Compensations (GraphQL)</h2>
    <form method="post" style="margin-bottom: 15px;">
        <label for="selectedEmployeeId">Select Employee</label>
        <select name="selectedEmployeeId" id="selectedEmployeeId" onchange="this.form.submit()">
            <?php foreach ($employees as $employee): ?>
                <?php $isSelected = ($employee['Id'] === $selectedEmployeeId) ? 'selected' : ''; ?>
                <option value="<?= htmlspecialchars($employee['Id']) ?>" <?= $isSelected ?>>
                    <?= htmlspecialchars($employee['DisplayName'] ?? $employee['Id']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript>
            <button type="submit" class="btn btn-secondary" name="action" value="change_employee">Load Compensations</button>
        </noscript>
    </form>

    <?php if ($compensations): ?>
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Type</th>
                <th>Active</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($compensations as $comp): ?>
                <tr>
                    <td><?= htmlspecialchars($comp['id']) ?></td>
                    <td><?= htmlspecialchars($comp['employerCompensation']['name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($comp['employerCompensation']['type']['description'] ?? '') ?></td>
                    <td><?= !empty($comp['active']) ? 'Yes' : 'No' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No compensation records returned for this employee.</p>
    <?php endif; ?>

    <h2>Create Time Activity (REST)</h2>
    <form method="post">
        <input type="hidden" name="action" value="create_time_activity" />
        <div class="form-grid">
            <div>
                <label for="employeeId">Employee ID</label>
                <input type="text" name="employeeId" id="employeeId" value="<?= htmlspecialchars($selectedEmployeeId ?? '') ?>" required />
            </div>
            <div>
                <label for="projectId">Project ID (optional)</label>
                <input type="text" name="projectId" id="projectId" placeholder="Project ID" />
            </div>
            <div>
                <label for="customerId">Customer (required)</label>
                <select name="customerId" id="customerId" required>
                    <?php foreach ($customers as $customer): ?>
                        <?php $isSelected = ($customer['Id'] === $selectedCustomerId) ? 'selected' : ''; ?>
                        <option value="<?= htmlspecialchars($customer['Id']) ?>" <?= $isSelected ?>>
                            <?= htmlspecialchars($customer['DisplayName'] ?? $customer['FullyQualifiedName'] ?? $customer['Id']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="itemId">Item ID (optional)</label>
                <input type="text" name="itemId" id="itemId" placeholder="ItemRef value" />
            </div>
            <div>
                <label for="payrollItemId">Payroll Item ID (optional)</label>
                <input type="text" name="payrollItemId" id="payrollItemId" placeholder="PayrollItemRef value" />
            </div>
            <div>
                <label for="txnDate">Date</label>
                <input type="date" name="txnDate" id="txnDate" value="<?= htmlspecialchars($_POST['txnDate'] ?? date('Y-m-d')) ?>" required />
            </div>
            <div>
                <label for="hours">Hours</label>
                <input type="number" name="hours" id="hours" min="0" step="0.25" value="<?= htmlspecialchars($_POST['hours'] ?? '1') ?>" required />
            </div>
            <div>
                <label for="minutes">Minutes</label>
                <input type="number" name="minutes" id="minutes" min="0" max="59" value="<?= htmlspecialchars($_POST['minutes'] ?? '0') ?>" />
            </div>
            <div>
                <label for="hourlyRate">Hourly Rate (optional)</label>
                <input type="number" name="hourlyRate" id="hourlyRate" min="0" step="0.01" value="<?= htmlspecialchars($_POST['hourlyRate'] ?? '') ?>" />
            </div>
            <div>
                <label for="description">Description</label>
                <textarea name="description" id="description" placeholder="Describe the work performed"><?= htmlspecialchars($_POST['description'] ?? 'Sample time activity') ?></textarea>
            </div>
            <div>
                <label for="billable">Billable</label>
                <select name="billable" id="billable">
                    <option value="1" <?= (($_POST['billable'] ?? '') === '1') ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= (($_POST['billable'] ?? '') === '0') ? 'selected' : '' ?>>No</option>
                </select>
            </div>
        </div>
        <p style="margin-top:15px;">
            <button type="submit" class="btn">Create Time Activity via REST</button>
        </p>
    </form>

    <?php if ($timeActivityResult): ?>
        <div class="info">
            <h3>REST API Response Payload</h3>
            <pre><?= htmlspecialchars(json_encode($timeActivityResult['body'] ?? $timeActivityResult, JSON_PRETTY_PRINT)) ?></pre>
            <?php if (!empty($timeActivityResult['http_status'])): ?>
                <p><strong>HTTP Status:</strong> <?= htmlspecialchars($timeActivityResult['http_status']) ?></p>
            <?php endif; ?>
            <?php if (!empty($timeActivityResult['raw_response'])): ?>
                <details>
                    <summary>Raw Response</summary>
                    <pre><?= htmlspecialchars($timeActivityResult['raw_response']) ?></pre>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p style="margin-top:30px;">
        <a href="../../OAuth_2/index.php">← Back to OAuth Home</a>
    </p>
</body>
</html>

