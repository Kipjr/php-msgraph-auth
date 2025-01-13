<?php
require 'vendor/autoload.php';

use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

session_start();

define('CLIENT_ID', $_ENV['CLIENT_ID']);
define('CLIENT_SECRET', $_ENV['CLIENT_SECRET']);
define('TENANT_ID', $_ENV['TENANT_ID']);
define('REDIRECT_URI', $_ENV['REDIRECT_URI']);
define('AUTH_URL', 'https://login.microsoftonline.com/' . TENANT_ID . '/oauth2/v2.0/authorize');
define('TOKEN_URL', 'https://login.microsoftonline.com/' . TENANT_ID . '/oauth2/v2.0/token');
define('GRAPH_URL', 'https://graph.microsoft.com/v1.0');

// Step 1: Generate the login link
if (!isset($_GET['code'])) {
    $loginUrl = AUTH_URL . '?' . http_build_query([
        'client_id' => CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri' => REDIRECT_URI,
        'response_mode' => 'query',
        'prompt'        => 'select_account',
        'scope' => 'https://graph.microsoft.com/.default openid profile email',
        'state' => '12345' // Replace with a CSRF token in production
    ]);

    echo '<a href="' . htmlspecialchars($loginUrl) . '">Login with Microsoft Entra</a>';
    exit;
}

// Step 2: Handle the callback and retrieve access token
if (isset($_GET['code'])){ 
    if(!isset($_SESSION['access_token'])) {
        $token = fetchAccessToken($_GET['code']);
        if (isset($token['access_token'])) {
            $_SESSION['access_token'] = $token['access_token'];
        } else {
            die('Error retrieving access token: ' . $token['error']);
        }
    }
    if(!isset($_SESSION['token_info'])) {
        if (isset($token['access_token'])) {
            $_SESSION['token_info'] = $token;
        }
    }
    echo '<h3>Token Info:</h3>';
    echo '<pre>' . print_r($_SESSION['token_info'], true) . '</pre>';
} 


// Step 3: Fetch user info and ServicePrincipal
if (isset($_SESSION['access_token'])) {
    $graph = new Graph();
    $graph->setAccessToken($_SESSION['access_token']);

    try {
        // Get user info
        $user = $graph->createRequest('GET', '/me')
            ->setReturnType(Model\User::class)
            ->execute();
        echo '<h3>User Info:</h3>';
        echo '<pre>' . print_r($user, true) . '</pre>';

        // Get ServicePrincipal for the app
        $appId = CLIENT_ID;
        $servicePrincipals = $graph->createRequest('GET', "/servicePrincipals?\$filter=appId eq '$appId'")
            ->execute();
        echo '<h3>Service Principal Info:</h3>';
        echo '<pre>' . print_r($servicePrincipals->getBody(), true) . '</pre>';
    } catch (Exception $e) {
        die('Graph API Error: ' . $e->getMessage());
    }
}

/**
 * Fetch access token using authorization code.
 */
function fetchAccessToken($code)
{
    $postData = [
        'client_id' => CLIENT_ID,
        'client_secret' => CLIENT_SECRET,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => REDIRECT_URI
    ];

    $ch = curl_init(TOKEN_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}
