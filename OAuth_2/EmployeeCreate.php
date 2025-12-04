<?php
// Suppress PHP warnings from QuickBooks SDK for PHP 8.4+ compatibility
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

session_start();
require_once "../vendor/autoload.php";

use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Core\Http\Serialization\XmlObjectSerializer;
use QuickBooksOnline\API\Facades\Employee;

// Load configuration
$configs = include('./config.php');
$environment = $configs['environment'] ?? 'sandbox';
$baseUrl = $configs['base_url'] ?? '';

// Ensure user is authenticated
if (!isset($_SESSION['access_token']) || empty($_SESSION['access_token'])) {
    echo "<h2>Error: Not Authenticated</h2>";
    echo "<p>You need to complete the OAuth2 flow first.</p>";
    echo "<a href='index.php'>Go back to main page</a>";
    exit();
}

$accessToken = $_SESSION['access_token'];
$refreshToken = $_SESSION['refresh_token'] ?? null;
$realmId = $_SESSION['realm_id'] ?? null;

/**
 * Initialize DataService with shared settings.
 */
function initDataService(array $configs, string $accessToken, ?string $refreshToken, ?string $realmId): DataService
{
    $service = DataService::Configure([
        'auth_mode'       => 'oauth2',
        'ClientID'        => $configs['client_id'],
        'ClientSecret'    => $configs['client_secret'],
        'accessTokenKey'  => $accessToken,
        'refreshTokenKey' => $refreshToken,
        'QBORealmID'      => $realmId,
        'baseUrl'         => $configs['base_url']
    ]);
    $service->setMinorVersion('75');
    $service->throwExceptionOnError(true);

    return $service;
}

/**
 * Clean up raw POST input.
 */
function sanitizeEmployeeInput(array $input): array
{
    $clean = [
        'DisplayName' => trim($input['displayName'] ?? ''),
        'GivenName'   => trim($input['givenName'] ?? ''),
        'MiddleName'  => trim($input['middleName'] ?? ''),
        'FamilyName'  => trim($input['familyName'] ?? ''),
        'SSN'         => preg_replace('/[^0-9-]/', '', $input['ssn'] ?? ''),
        'PrimaryAddr' => [
            'Line1'                 => trim($input['line1'] ?? ''),
            'City'                  => trim($input['city'] ?? ''),
            'CountrySubDivisionCode'=> strtoupper(trim($input['stateCode'] ?? '')),
            'PostalCode'            => trim($input['postalCode'] ?? '')
        ],
        'PrimaryPhone'=> [
            'FreeFormNumber'        => trim($input['phone'] ?? '')
        ],
        'PrimaryEmailAddr' => !empty($input['email']) ? ['Address' => trim($input['email'])] : null,
        'Notes'            => trim($input['notes'] ?? '')
    ];

    $clean['PrimaryAddr'] = array_filter($clean['PrimaryAddr'], fn($v) => $v !== '');
    if (!$clean['PrimaryAddr']) {
        unset($clean['PrimaryAddr']);
    }

    if (empty($clean['PrimaryPhone']['FreeFormNumber'])) {
        unset($clean['PrimaryPhone']);
    }

    if (empty($clean['PrimaryEmailAddr'])) {
        unset($clean['PrimaryEmailAddr']);
    }

    if (!$clean['Notes']) {
        unset($clean['Notes']);
    }

    return array_filter($clean, fn($v) => $v !== '');
}

/**
 * Validate required employee attributes before hitting QBO.
 */
function validateEmployeePayload(array $payload): void
{
    $required = ['DisplayName', 'GivenName', 'FamilyName', 'SSN'];
    foreach ($required as $field) {
        if (empty($payload[$field])) {
            throw new InvalidArgumentException("{$field} is required.");
        }
    }

    if (!preg_match('/^\d{3}-\d{2}-\d{4}$/', $payload['SSN'])) {
        throw new InvalidArgumentException('SSN must be in the format 000-00-0000.');
    }
}

$dataService = initDataService($configs, $accessToken, $refreshToken, $realmId);
$resultingObj = null;
$errorMsg = null;
$employeeInfo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_employee') {
    try {
        $employeeInfo = sanitizeEmployeeInput($_POST);
        validateEmployeePayload($employeeInfo);

        $employeeResource = Employee::create($employeeInfo);
        $resultingObj = $dataService->Add($employeeResource);
        $error = $dataService->getLastError();

        if ($error) {
            throw new Exception($error->getResponseBody() ?: 'Unknown QuickBooks error', $error->getHttpStatusCode());
        }
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}
?>

<html>
<head>
    <title>Create Employee</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; background: #f0fff0; padding: 10px; border: 1px solid green; }
        .error { color: red; background: #fff0f0; padding: 10px; border: 1px solid red; }
        .info { background: #f0f8ff; padding: 10px; border: 1px solid #ccc; margin: 10px 0; }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        input[type=text], input[type=email], textarea { width: 100%; padding: 8px; box-sizing: border-box; }
        textarea { min-height: 60px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; }
        .btn { display: inline-block; padding: 10px 16px; background: #0077C5; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>Create Employee</h1>
    <div class="info">
        <h3>Authentication Info:</h3>
        <p><strong>Environment:</strong> <?= htmlspecialchars($environment) ?> (<?= htmlspecialchars($baseUrl) ?>)</p>
        <p><strong>Realm ID:</strong> <?= htmlspecialchars($realm_id ?? '') ?></p>
    </div>

    <?php if ($resultingObj): ?>
        <div class="success">
            <h2>✅ Employee Created Successfully!</h2>
            <p><strong>Employee ID:</strong> <?= htmlspecialchars($resultingObj->Id) ?></p>
            <p><strong>Display Name:</strong> <?= htmlspecialchars($resultingObj->DisplayName) ?></p>
        </div>
        <div class="info">
            <h3>Request Payload:</h3>
            <pre><?= htmlspecialchars(json_encode($employeeInfo, JSON_PRETTY_PRINT)) ?></pre>
        </div>
        <div class="info">
            <h3>Full Employee Object:</h3>
            <pre><?= htmlspecialchars(json_encode($resultingObj, JSON_PRETTY_PRINT)) ?></pre>
        </div>
    <?php elseif ($errorMsg): ?>
        <div class="error">
            <h2>❌ Error Creating Employee</h2>
            <p><?= htmlspecialchars($errorMsg) ?></p>
        </div>
    <?php endif; ?>

    <h2>Employee Details</h2>
    <form method="post">
        <input type="hidden" name="action" value="create_employee">
        <div class="form-grid">
            <div>
                <label for="displayName">Display Name *</label>
                <input type="text" id="displayName" name="displayName" value="<?= htmlspecialchars($_POST['displayName'] ?? 'EMP-' . time()) ?>" required>
            </div>
            <div>
                <label for="givenName">Given Name *</label>
                <input type="text" id="givenName" name="givenName" value="<?= htmlspecialchars($_POST['givenName'] ?? 'John') ?>" required>
            </div>
            <div>
                <label for="middleName">Middle Name</label>
                <input type="text" id="middleName" name="middleName" value="<?= htmlspecialchars($_POST['middleName'] ?? '') ?>">
            </div>
            <div>
                <label for="familyName">Family Name *</label>
                <input type="text" id="familyName" name="familyName" value="<?= htmlspecialchars($_POST['familyName'] ?? 'Doe') ?>" required>
            </div>
            <div>
                <label for="ssn">SSN *</label>
                <input type="text" id="ssn" name="ssn" value="<?= htmlspecialchars($_POST['ssn'] ?? '444-55-6677') ?>" required>
            </div>
            <div>
                <label for="phone">Primary Phone *</label>
                <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '408-555-1212') ?>" required>
            </div>
            <div>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div>
                <label for="line1">Address Line 1 *</label>
                <input type="text" id="line1" name="line1" value="<?= htmlspecialchars($_POST['line1'] ?? '123 Main Street') ?>" required>
            </div>
            <div>
                <label for="city">City *</label>
                <input type="text" id="city" name="city" value="<?= htmlspecialchars($_POST['city'] ?? 'San Jose') ?>" required>
            </div>
            <div>
                <label for="stateCode">State Code *</label>
                <input type="text" id="stateCode" name="stateCode" value="<?= htmlspecialchars($_POST['stateCode'] ?? 'CA') ?>" maxlength="2" required>
            </div>
            <div>
                <label for="postalCode">Postal Code *</label>
                <input type="text" id="postalCode" name="postalCode" value="<?= htmlspecialchars($_POST['postalCode'] ?? '95112') ?>" required>
            </div>
            <div>
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>
        </div>
        <p style="margin-top: 20px;">
            <button type="submit" class="btn">Create Employee</button>
        </p>
    </form>

    <div style="margin-top: 30px;">
        <a href="index.php">← Back to Main Page</a> |
        <a href="connected.php">View Connection Info</a>
    </div>
</body>
</html>


