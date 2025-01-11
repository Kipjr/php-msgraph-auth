<?php

namespace App\Helpers\MSGraphAPI;

use GuzzleHttp;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

class Application {

    protected $accessToken;
    public $employeeNumberProperty;
    public $descriptionProperty;
    public $mailProperty;
    private $allUserProperties;

    public function __construct() {
        $extension_app_id = Str::remove('-', config('services.azure.extension_app_id'));
        $this->employeeNumberProperty = 'extension_' . $extension_app_id . '_employeeNumber';
        $this->descriptionProperty = 'extension_' . $extension_app_id . '_description';
        $this->mailProperty = 'extension_' . $extension_app_id . '_mail';
        $this->allUserProperties = '$select=id,displayName,surname,givenName,userPrincipalName,mail,businessPhones,onPremisesSamAccountName,employeeId,' . $this->descriptionProperty . ',' . $this->employeeNumberProperty  . ',' . $this->mailProperty;
    }

    public function getToken() {
        return $this->_token();
    }

    private function _token() {
        # https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token
        $guzzle = new \GuzzleHttp\Client();
        $url = 'https://login.microsoftonline.com/' . config('services.azure.tenant') . '/oauth2/v2.0/token';
        $token = json_decode($guzzle->post($url, [
            'form_params' => [
                'client_id' => config('services.azure.client_id'),
                'client_secret' => config('services.azure.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ],
        ])->getBody()->getContents());
        return $token->access_token;
    }

    public function getUsers($topN = null, $searchProperty = null, $searchValue = null, $checkAdmin = False) {
        $token = $this->getToken();
        if ($token) {
            $graph = new \Microsoft\Graph\Graph;
            $graph
            ->setApiVersion("v1.0")
            ->setAccessToken($token);

            // if request for max items then append to query
            $topN = $topN ? '&$top=' . $topN : null;
            // if request for extensionProperty then translate the propertyName
            switch ($searchProperty) {
                case 'employeeNumber':
                    $searchProperty = $this->employeeNumberProperty;
                    $search = $searchProperty && $searchValue ? '&$filter='. $searchProperty . ' eq \'' . $searchValue . '\'' : null;
                    break;
                case 'description':
                    $searchProperty = $this->descriptionProperty;
                    $search = $searchProperty && $searchValue ? '&$filter='. $searchProperty . ' eq \'' . $searchValue . '\'' : null;
                    break;
                case 'email':
                    $searchProperty = $this->mailProperty;
                    $search = $searchProperty && $searchValue ? '&$filter='. $searchProperty . ' eq \'' . $searchValue . '\'' : null;
                    break;
                default:
                    $search = $searchProperty && $searchValue ? '&$search="'. $searchProperty . ':' . $searchValue . '"' : null;
                    break;
            }
            // if search then append to query
            //$search = $searchProperty && $searchValue ? '&$search="'. $searchProperty . ':' . $searchValue . '"' : null;
            //$search= $searchProperty && $searchValue ? '&$filter='. $searchProperty . ' eq \'' . $searchValue . '\'' : null;
            $endpoint = '/users';
            $url = $endpoint . '?$count=true' . $topN . $search ;
            # https://graph.microsoft.com/v1.0/users?$search="displayName:MyName"&$count=true&$orderBy=displayName&$select=id,displayName,surname,givenName,userPrincipalName,mail,businessPhones,onPremisesSamAccountName,employeeId,extension_e1fd504a055643d5b9d370b8b24b45ee_description,extension_e1fd504a055643d5b9d370b8b24b45ee_employeeNumber,extension_e1fd504a055643d5b9d370b8b24b45ee_mail
            $users = $graph
                ->createCollectionRequest("GET", $url . '&' . $this->allUserProperties)
                ->addHeaders(["ConsistencyLevel" => "eventual"])
                ->execute();
                
            $userCollection = collect([]);
                
            $body = $users->getBody();
            foreach ($body['value'] as $userData) {
                $userCollection->push(new \Microsoft\Graph\Model\User($userData));
            }
            if ($body['@odata.count'] == 0) {
                return null;
            }
            // if nextLink then loop
            while ($url = $users->getNextLink()) {
                $users = $graph
                    ->createCollectionRequest("GET", $url)
                    ->addHeaders(["ConsistencyLevel" => "eventual"])
                    ->execute();
                    $body = $users->getBody();
                foreach ($body['value'] as $userData) {
                    $userCollection->push(new \Microsoft\Graph\Model\User($userData));
                }
            }
           
            $cleanupUsers = $userCollection->map(function ($user) use($checkAdmin) {
                $user =  $this->cleanUserObject($user);
                if ($checkAdmin) {
                    $roles = $this->getAssignedRoles($user->id);
                    $user->mensa_admin = $roles['isAdmin'];
                }
                return $user;
            }, $userCollection);
            
            $filteredUsers = $cleanupUsers->whereIn('id', $this->getAllAppUsers() );
            
            return $filteredUsers;
        }
    }


    private function getAzureInfoBy($field,
        $value) {
        return $this->getUsers(null,
            $field,
            $value,
            False)->first();
    }

    public function searchAzureUsers($name) {
        if (!preg_match('/^[a-zA-Z0-9 _]+$/', $name)) {
            return '';
        }
        $users = $this->getUsers(50, 'displayName', $name, False);
        if (!empty($users)) {
            return $users->map(function ($user) {
                return [
                    'name' => $user->name,
                    'lidnummer' => $user->lidnummer,
                    //'id' => $user->id
                ];
            }, $users);
        }
    }

    public function saveAzureUser($user) {
        $dbUser = null;
        try {
            // We first check if we already have this user in our database
            $dbUser = \App\Models\User::findOrFail($user->description);
        } catch(\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // If not, we create a new user
            $dbUser = new \App\Models\User;
            //$dbUser->id =  $user->id;
            $dbUser->lidnummer = $user->lidnummer;
        }

        // We update all the information of the user
        $dbUser->name = $user->name;
        $dbUser->email = $user->email;
        //$dbUser->phonenumber = $user->businessPhones; //temp offline

        // Check if the user is a mensa admin
        $checkUser = $this->getUsers(10, 'description', $user->description, $checkAdmin = true)->first();
        $dbUser->mensa_admin = $checkUser->mensa_admin;
        // Save it back to the database
        $dbUser->save();

        // And return it so we can use it
        return $dbUser;
    }


    public function getAzureUserBy($field, $value) {
        $AzureInfo = $this->getAzureInfoBy($field, $value);
        if ($AzureInfo == null)
            return null;

        return $this->saveAzureUser($AzureInfo);
    }


    public function getAllAppUsers() {
        $token = $this->getToken();
        if ($token) {
            $graph = new \Microsoft\Graph\Graph;
            $graph
            ->setApiVersion("beta")
            ->setAccessToken($token);
            $servicePrincipalId = config('services.azure.serviceprincipal_id');
            $appRoles = $this->getAppRoles($graph, $servicePrincipalId);
            /*
                appRoles from servicePrincipals/$servicePrincipalId: [{"value": "mensa.user",  "id": "GUID"},  {"value": "mensa.user",  "id": "GUID"}]
            */
            $roleAssignments = $this->getAppRoleAssignedTo($graph, $servicePrincipalId);


            // Step 3: Iterate over the role assignments and collect users and groups
            foreach ($roleAssignments as $assignment) {
                $principalType = $assignment->getPrincipalType();
                $principalId = $assignment->getPrincipalId();
                if ($principalType === 'User') {
                    // Add the user to the list
                    $endpoint = '/users/' . $principalId .'?$count=true&' . $this->allUserProperties;
                    $user = $graph->createRequest("GET", $endpoint)
                    ->addHeaders(["ConsistencyLevel" => "eventual"])
                    ->setReturnType(\Microsoft\Graph\Model\User::class)
                    ->execute();
                    $userList[] = $this->cleanUserObject($user,'id');
                } elseif ($principalType === 'Group') {
                    // Add group ID to the group list for later fetching members
                    $groupList[] = $principalId;
                }

            }

            // Step 4: Get all group members and add them to the user list
            foreach ($groupList as $groupId) {
                $groupMembers = $this->getGroupTransitiveMembers($graph, $groupId);
                //$endpoint = '/groups/' . $groupId . '/members?$count=true&' . $this->allUserProperties;
                //$groupMembers = $graph->createRequest("GET", $endpoint)
                //->addHeaders(["ConsistencyLevel" => "eventual"])
                //->setReturnType(\Microsoft\Graph\Model\User::class)
                //->execute();
                foreach ($groupMembers as $member) {
                    $userList[] = $this->cleanUserObject($member,'id');
                }
            }
            return $userList;
        }
    }

    public function getAssignedRoles($userId) {
        /*
        Array
        (
            [isAdmin] =>
            [isUser] => 1
        )
        */
        $token = $this->getToken();
        if ($token) {
            $graph = new \Microsoft\Graph\Graph;
            $graph
            ->setApiVersion("beta")
            ->setAccessToken($token);
            $servicePrincipalId = config('services.azure.serviceprincipal_id');
            # AppRole should be cached.
            $appRoles = $this->getAppRoles($graph, $servicePrincipalId);
            /*
                appRoles from servicePrincipals/$servicePrincipalId: [{"value": "mensa.user",  "id": "GUID"},  {"value": "mensa.user",  "id": "GUID"}]
            */
            $roleAssignments = $this->getAppRoleAssignedTo($graph, $servicePrincipalId); // "principalType": "Group",  "principalId":  "GUID", "appRoleId": "GUID"
            $roleMappings = [];
            foreach ($roleAssignments as $assignment) {
                $principalId = $assignment->getProperties()['principalId'];
                $roleId = $assignment->getProperties()['appRoleId'];
                $principalType = $assignment->getProperties()['principalType'];
                if ($principalType === 'Group') {
                    $groupMembers = $this->getGroupTransitiveMembers($graph, $principalId, $userId);
                    foreach ($groupMembers as $member) {
                        $roleMappings[] = [
                            'id' => $member->getProperties()['id'],
                            'source' => $principalType,
                            'role' => $this->findRoleById($appRoles, $roleId),
                            'memberObject' => $member
                        ];
                    }
                } elseif ($principalType === 'User') {
                    if ($principalId === $userId) {
                        $roleMappings[] = [
                            'id' => $principalId,
                            'source' => $principalType,
                            'role' => $this->findRoleById($appRoles, $roleId),
                            'memberObject' => null
                        ];
                    }
                } else {
                    return;
                }
            }
            //default value, not an user and not an admin
            $userRoleAssignment = array(
                "isAdmin" => false,
                "isUser" => false
            );
            //check for each user assigned role if it matches the configured app role
            foreach ($roleMappings as $assignedRole) {
                switch ($assignedRole['role']) {
                    case config('services.azure.role.admin'):
                        $userRoleAssignment['isAdmin'] = true;
                        $userRoleAssignment['isAdmin'] = true; //if someone is admin, then it is definitely an user..
                        case config('services.azure.role.user'):
                            $userRoleAssignment['isUser'] = true;
                    }
                }
                return $userRoleAssignment;
            }
        }

        private function getAppRoles($graph, $servicePrincipalId) {
            # https://graph.microsoft.com/beta/servicePrincipals/${servicePrincipalId}?$select=appRoles
            /*
        [allowedMemberTypes] => [
            "User
        ],
        [description] =>
        [displayName] User,
        [id] => SOME_GUID
        [isEnabled] => true,
        [origin] => Application,
        [value] => SOME_VALUE,
        [isPreAuthorizationRequired] => false,
        [isPrivate] => false
        */
            $response = $graph->createRequest('GET', "/servicePrincipals/$servicePrincipalId" . '?$select=appRoles')
            ->addHeaders(["Content-Type" => "application/json"])
            ->setReturnType(\Microsoft\Graph\Model\ServicePrincipal::class)
            ->execute();
            $appRoles = $response->getProperties()['appRoles'];
            $enabledAppRoles = array_filter($appRoles, function ($role) {
                return $role['isEnabled'] === true;
            });
            return $enabledAppRoles;
        }

        private function getAppRoleAssignedTo($graph, $servicePrincipalId) {
            # https://graph.microsoft.com/beta/servicePrincipals/${servicePrincipalId}/appRoleAssignedTo
            /*
        [id] => SOMEID,
        [creationTimestamp] => 2021-03-20T18:06:09.6874997Z,
        [appRoleId] => GUID_ROLE,
        [principalDisplayName] => SOME_USER,
        [principalId] => GUID_USER,
        [principalType] => User,
        [resourceDisplayName] => Mensa,
        [resourceId] => GUID_APP,
        */
            $response = $graph->createRequest('GET', "/servicePrincipals/$servicePrincipalId/appRoleAssignedTo")
            ->addHeaders(["Content-Type" => "application/json"])
            ->setReturnType(\Microsoft\Graph\Model\AppRoleAssignment::class)
            ->execute();
            return $response;
        }

        private function getGroupTransitiveMembers($graph, $groupId, $UserId = null) {
            # https://graph.microsoft.com/v1.0/groups/${groupId}/transitiveMembers
            /*
        [id] => "SOME_GUID",
        [businessPhones] => ,
        [displayName] => "",
        [givenName] => "FirstName",
        [jobTitle] => null,
        [mail] => "USER_MAIL",
        [mobilePhone] => null,
        [officeLocation] => null,
        [preferredLanguage] => null,
        [surname] => "SOME_LASTNAME",
        [userPrincipalName] => "USER_UPN"
        */
            $endpoint = "/groups/$groupId/transitiveMembers" . '?$count=true&' . $this->allUserProperties . ($UserId ? "&filter=id eq '" . $UserId . "'" : "");
            $response = $graph->createRequest('GET', $endpoint)
            ->addHeaders(["Content-Type" => "application/json"])
            ->setReturnType(\Microsoft\Graph\Model\User::class)
            ->execute();
            return $response;
        }


        private function findRoleById($appRoles, $roleId) {
            foreach ($appRoles as $role) {
                if ($role['id'] === $roleId) {
                    return $role['value'];
                }
            }
            return null;
        }


    function cleanUserObject($userObj, $key = null) {
        $cleanObj = new \stdClass();
        $cleanObj->id = $userObj->getId();
        $cleanObj->name = $userObj->getDisplayName();
        $cleanObj->surname = $userObj->getSurname();
        $cleanObj->givenName = $userObj->getGivenName();
        $cleanObj->userPrincipalName = $userObj->getUserPrincipalName();
        $cleanObj->email = $userObj->getMail();
        $cleanObj->phonenumber = $userObj->getBusinessPhones()[0] ?? null;
        $cleanObj->onPremisesSamAccountName = $userObj->getOnPremisesSamAccountName();
        $cleanObj->employeeId = $userObj->getEmployeeId();
        $cleanObj->email = $userObj->getProperties()[$this->mailProperty];
        $cleanObj->description = $userObj->getProperties()[$this->descriptionProperty][0];
        $cleanObj->lidnummer  = $userObj->getProperties()[$this->employeeNumberProperty] ?? null;
        if ($key !== null) {
            return $cleanObj->$key;
        }
        else {
            return $cleanObj;
             }
    }
}
