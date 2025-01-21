<?php
require 'vendor/autoload.php';

use Microsoft\Graph\Core\Authentication\GraphPhpLeagueAccessTokenProvider;
use Microsoft\Graph\Core\Authentication\GraphPhpLeagueAuthenticationProvider;
use Microsoft\Graph\Core\GraphConstants;

use Microsoft\Graph\GraphServiceClient;
use Microsoft\Graph\Generated\ServicePrincipals\ServicePrincipalsRequestBuilderGetRequestConfiguration;

use Microsoft\Kiota\Abstractions\ApiException;
use Microsoft\Kiota\Authentication\Cache\InMemoryAccessTokenCache;
use Microsoft\Kiota\Authentication\Oauth\AuthorizationCodeContext;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;

use League\OAuth2\Client\Token\AccessToken;


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
    echo("<br><hr><pre>");
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
    if(!$object){
        echo "&#11208;" . $summary . ": empty";
    } else {
        echo '<details><summary>' . $summary . ':</summary>';
        echo '<pre>' . print_r($object, true) . '</pre></details>';
    }
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

    echo '<a href="' . htmlspecialchars($loginUrl) . '"><button>Login with Microsoft Entra</button></a>';
    echo "<br><br><hr><pre>";
    $url = parse_url(urldecode($loginUrl));
    parse_str(($url['query']), $queryParams);
    print_r($url);

    print_r($queryParams);
    echo "</pre>";
    exit;
}


// // Step 2: Handle callback and retrieve the access token
if (isset($_GET['code']) && empty($_SESSION['auth_provider'])) {
    try {

        
        /* 
            Token
        */        
        $tokenRequestContext = new AuthorizationCodeContext(
            TENANT_ID,
            CLIENT_ID,
            CLIENT_SECRET,
            $_GET['code'],
            REDIRECT_URI
        );

        /*
         $inMemoryCache = new InMemoryAccessTokenCache();

        $graphServiceClient = GraphServiceClient::createWithAuthenticationProvider(
            GraphPhpLeagueAuthenticationProvider::createWithAccessTokenProvider(
                GraphPhpLeagueAccessTokenProvider::createWithCache(
                    $inMemoryCache,
                    $tokenRequestContext,
                    explode(' ',SCOPES)
                )
            )
        );
        
        $accessToken = $inMemoryCache->getTokenWithContext($tokenRequestContext);
        */
        
        $graphServiceClient = new GraphServiceClient($tokenRequestContext, explode(' ',SCOPES));
        if(isset($accessToken)){
            printCollapsible("Token", $accessToken);
        } else {
            printCollapsible("Token",null);
        }

        /* 
            USER
        */
        $user = $graphServiceClient->me()->get();
        if (!$user) {
            throw new Exception('Failed to fetch user profile.');
        }
        printCollapsible("User Info", $user);

        /* 
            servicePrincipals
        */
        $requestConfiguration = new ServicePrincipalsRequestBuilderGetRequestConfiguration();
        $headers = [
                'ConsistencyLevel' => 'eventual',
            ];
        $requestConfiguration->headers = $headers;
        $queryParameters = ServicePrincipalsRequestBuilderGetRequestConfiguration::createQueryParameters();
        $queryParameters->search = "\"appId:" . CLIENT_ID . "\"";
        $requestConfiguration->queryParameters = $queryParameters;
        $servicePrincipals = $graphServiceClient->servicePrincipals()->get($requestConfiguration)->wait();
        if (empty($servicePrincipals) || empty($servicePrincipals->getValue())) {
            throw new Exception('No service principal found for the given appId.');
        }

        $servicePrincipalId = $servicePrincipals->getValue()[0]->getId();
        printCollapsible("Service Principal Info", $servicePrincipals->getValue());

        /* 
            appRoleAssignedTo
        */        
        $appRoleAssignedTo = $graphServiceClient->servicePrincipals()
        ->byServicePrincipalId($servicePrincipalId)
        ->appRoleAssignedTo()
        ->get()
        ->wait();
        printCollapsible("Service Principal/appRoleAssignedTo", $appRoleAssignedTo->getValue());

    } catch (Exception $e) {
        getErrorInfo($e);
        die('Error during Microsoft Graph API operations.');
    }
}