<?php
namespace App\Helpers\MSGraphAPI;


use Microsoft\Graph\Graph;
use GuzzleHttp;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

class User {
    public $id;
    public $displayName;
    public $surname;
    public $givenName;
    public $description;
    public $userPrincipalName;
    public $email;
    public $onPremisesSamAccountName;
    public $employeeId;
    public $employeeNumber;
    public $isAdmin;
    public $isUser;

    public function __construct()
    {

    }

    public function getUserInfo($token) {
        if ($token) {
            $extension_app_id = Str::remove('-', config('services.azure.extension_app_id'));
            $employeeNumberProperty = 'extension_' . $extension_app_id . '_employeeNumber';
            $descriptionProperty = 'extension_' . $extension_app_id . '_description';
            $mailProperty = 'extension_' . $extension_app_id . '_mail';            
            
            $graph = new Graph();
            $graph->setApiVersion("beta");
            $graph->setAccessToken($token);

            $endpoint = '/me';
            $url = $endpoint . '?$count=true&$orderBy=displayName&$select=id,displayName,surname,givenName,userPrincipalName,mail,onPremisesSamAccountName,employeeId,' . $descriptionProperty . ',' . $employeeNumberProperty  . ',' . $mailProperty ;

            $request = $graph->createRequest("GET", $url)
                ->addHeaders(["ConsistencyLevel"=> "eventual"])
                ->execute();
            $content = $request->getBody();

            $this->id = $content['id'];
            $this->displayName = $content['displayName'];
            $this->surname = $content['surname'];
            $this->givenName = $content['givenName'];
            $this->userPrincipalName = $content['userPrincipalName'];
            $this->description = Arr::has($content,$descriptionProperty) ? Arr::join( $content[$descriptionProperty] , ',' ) : null;
            $this->email = $content[$mailProperty];
            $this->onPremisesSamAccountName = $content['onPremisesSamAccountName'];
            $this->employeeId = $content['employeeId'];
            $this->employeeNumber = $content[$employeeNumberProperty];
        }
    }
}
