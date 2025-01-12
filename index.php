<?php

use Microsoft\Graph\Graph;


if (isset($_GET['code'])) {
   

} else {
        $auth_base   = str_replace('{TENANT_ID}', $ld_config->getValue('ld_azure_tenant_id'), $ld_config->getValue('ld_azure_auth_url'));
        $form_params = [
            'response_type' => 'code',
            'client_id'     => $ld_config->getValue('ld_azure_client_id'),
            'redirect_uri'  => $ld_config->getValue('ld_azure_redirect_uri'),
            'scope'         => $ld_config->getValue('ld_azure_scopes'),
            'prompt'        => 'select_account',
            'state'         => $state,
        ];
        $oAuthURL = $auth_base . '?' . http_build_query($form_params);  
        echo "<a href=\"$oAuthURL\">Login</a>";
}

  

?>
