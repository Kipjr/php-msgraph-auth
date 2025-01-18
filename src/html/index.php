<?php
require 'vendor/autoload.php';

use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

session_start();

define('FQDN_REDIRECT_URI', $_ENV['FQDN_REDIRECT_URI']);
define('PORT', $_ENV['PORT']);
define('CLIENT_ID', $_ENV['CLIENT_ID']);
define('CLIENT_SECRET', $_ENV['CLIENT_SECRET']);
define('TENANT_ID', $_ENV['TENANT_ID']);
define('AUTH_URL', 'https://login.microsoftonline.com/' . TENANT_ID . '/oauth2/v2.0/authorize');
define('TOKEN_URL', 'https://login.microsoftonline.com/' . TENANT_ID . '/oauth2/v2.0/token');
define('GRAPH_URL', 'https://graph.microsoft.com/v1.0');
define('SCOPES', $_ENV['SCOPES']);
define('ENVIRONMENT', $_ENV['ENVIRONMENT'] ?? "prod");

define('REDIRECT_URI', "https://" . FQDN_REDIRECT_URI . ":" . PORT);

if(ENVIRONMENT == 'dev'){
    define('CSRF',hash('sha256', $_SERVER['SERVER_ADDR'] . $_SERVER['REMOTE_ADDR']));
} else {
    //prod or anything else
    define('CSRF',hash('sha256', uniqid(mt_rand(), true)));
}

function getErrorInfo($e){
    echo("<pre>");
    
    print_r("<b>File:</b>\n\t" . $e->getFile() . "\n\n");
    print_r("<b>Line:</b>\n\t" . $e->getLine() . "\n\n");
    print_r("<b>Message:</b>\n\t" . $e->getMessage() . "\n\n");
    print_r("<b>Trace:</b>\n\t" . $e->getTraceAsString() . "\n\n");
    debug_print_backtrace();
    echo("\n</pre>");
}
function printCollapsible($summary,$object){
    echo '<details><summary>' . $summary . ':</summary>';
    echo '<pre>' . print_r($object, true) . '</pre></details>';
}


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

###
### Main
###

// Step 1: Generate the login link
if (!isset($_GET['code'])) {
    $loginUrl = AUTH_URL . '?' . http_build_query([
        'client_id' => CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri' => REDIRECT_URI,
        'response_mode' => 'query',
        'prompt'        => 'select_account',
        'scope' => SCOPES,
        'state' => CSRF
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
    printCollapsible("Token Info",$_SESSION['token_info']);
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
        printCollapsible("User Info",$user);
        
        // Get ServicePrincipal for the app
        $appId = CLIENT_ID;
        $servicePrincipals = $graph->createRequest('GET', "/servicePrincipals?\$filter=appId eq '$appId'")->execute();
        $servicePrincipalId = $servicePrincipals->getBody()['value'][0]['id'];
        printCollapsible("Service Principal Info",$servicePrincipals->getBody());
                
        $appRoleAssignedTo = $graph->createRequest('GET', "/servicePrincipals/$servicePrincipalId/appRoleAssignedTo")->execute();
        printCollapsible("Service Principal/appRoleAssignedTo",$appRoleAssignedTo->getBody());

    } catch (Exception $e) {
        getErrorInfo($e);
        die('Execution stopped...');
    }
}