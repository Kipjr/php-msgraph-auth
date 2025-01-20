<?php
require 'vendor/autoload.php';

use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\OAuth\ClientCredentialContext;
use Microsoft\Kiota\Authentication\OAuth\AuthorizationCodeContext;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

session_start();

// Load environment variables
define('FQDN_REDIRECT_URI', $_ENV['FQDN_REDIRECT_URI']);
define('PORT', $_ENV['PORT']);
define('CLIENT_ID', $_ENV['CLIENT_ID']);
define('CLIENT_SECRET', $_ENV['CLIENT_SECRET']);
define('TENANT_ID', $_ENV['TENANT_ID']);
define('SCOPES', $_ENV['SCOPES']);
define('ENVIRONMENT', $_ENV['ENVIRONMENT'] ?? "prod");

define('REDIRECT_URI', "https://" . FQDN_REDIRECT_URI . ":" . PORT);
define('AUTH_URL', "https://login.microsoftonline.com/" . TENANT_ID . "/oauth2/v2.0/authorize");
define('TOKEN_URL', "https://login.microsoftonline.com/" . TENANT_ID . "/oauth2/v2.0/token");

// Generate CSRF token based on environment
if (ENVIRONMENT == 'dev') {
    define('CSRF', hash('sha256', $_SERVER['SERVER_ADDR'] . $_SERVER['REMOTE_ADDR']));
} else {
    define('CSRF', hash('sha256', uniqid(mt_rand(), true)));
}

/**
 * Helper function to print error information.
 */
function getErrorInfo($e) {
    echo("<pre>");
    print_r("<b>File:</b>\n\t" . $e->getFile() . "\n\n");
    print_r("<b>Line:</b>\n\t" . $e->getLine() . "\n\n");
    print_r("<b>Message:</b>\n\t" . $e->getMessage() . "\n\n");
    print_r("<b>Trace:</b>\n\t" . $e->getTraceAsString() . "\n\n");
    echo("\n</pre>");
}

/**
 * Function to print collapsible HTML elements for debugging.
 */
function printCollapsible($summary, $object) {
    echo '<details><summary>' . $summary . ':</summary>';
    echo '<pre>' . print_r($object, true) . '</pre></details>';
}

// Step 1: Generate the Microsoft login link
if (!isset($_GET['code'])) {
    $loginUrl = AUTH_URL . '?' . http_build_query([
        'client_id'     => CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri'  => REDIRECT_URI,
        'response_mode' => 'query',
        'scope'         => SCOPES,
        'state'         => CSRF
    ]);

    echo '<a href="' . htmlspecialchars($loginUrl) . '">Login with Microsoft Entra</a>';
    exit;
}

// Step 2: Handle callback and retrieve the access token
if (isset($_GET['code']) && !isset($_SESSION['access_token'])) {
    $authContext = new AuthorizationCodeContext(CLIENT_ID, CLIENT_SECRET, TENANT_ID, $_GET['code'], REDIRECT_URI);
    $tokenRequest = $authContext->getTokenRequestContext();

    $accessToken = $tokenRequest->getAccessToken();
    $_SESSION['access_token'] = $accessToken->getToken();
    $_SESSION['token_info'] = $accessToken;

    printCollapsible("Token Info", $_SESSION['token_info']);
}

// Step 3: Fetch user info and service principal
if (isset($_SESSION['access_token'])) {
    try {
        // Initialize Graph client with the access token
        $graphClient = new GraphServiceClient(new ClientCredentialContext(CLIENT_ID, CLIENT_SECRET, TENANT_ID));
        $graphClient->setAccessToken($_SESSION['access_token']);

        // Get user profile
        $user = $graphClient->me()->get();
        printCollapsible("User Info", $user);

        // Get ServicePrincipal for the app
        $appId = CLIENT_ID;
        $servicePrincipals = $graphClient->servicePrincipals()
            ->getByQuery(["\$filter" => "appId eq '$appId'"]);

        $servicePrincipalId = $servicePrincipals->getValue()[0]->getId();
        printCollapsible("Service Principal Info", $servicePrincipals);

        // Fetch assigned app roles
        $appRoleAssignedTo = $graphClient->servicePrincipalsById($servicePrincipalId)
            ->appRoleAssignedTo()
            ->get();

        printCollapsible("Service Principal/appRoleAssignedTo", $appRoleAssignedTo);

    } catch (Exception $e) {
        getErrorInfo($e);
        die('Execution stopped...');
    }
}
