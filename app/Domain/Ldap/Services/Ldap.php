<?php

namespace Leantime\Domain\Ldap\Services;

use Illuminate\Contracts\Container\BindingResolutionException;
use LDAP\Connection;
use Leantime\Core\Environment;
use Leantime\Domain\Setting\Repositories\Setting as SettingRepository;
use Leantime\Domain\Users\Repositories\Users as UserRepository;

/**
 *
 */
class Ldap
{
    private false|Connection $ldapConnection;
    private mixed $host;
    private mixed $port;
    private mixed $ldapDomain;
    private mixed $ldapUri;
    private mixed $ldapDn; //DN where users are located (including baseDn)
    private mixed $ldapKeys = array(
        "username" => "uid",
        "groups" => "memberof",
        "email" => "mail",
        "firstname" => "displayname",
        "lastname" => '',
        "phone" => 'telephonenumber',
        "jobTitle" => "title",
        "jobLevel" => "level",
        "department" => "department",
    );
    private mixed $ldapLtGroupAssignments = array();
    private mixed $settingsRepo;
    private mixed $defaultRoleKey;
    private mixed $directoryType = "OL";

    /**
     * @var environment $config
     */
    private Environment $config;

    /**
     * @var mixed
     */
    public mixed $useLdap;

    /**
     * @var mixed
     */
    public mixed $autoCreateUser;

    /**
     * @param bool|Environment $differentConfig
     * @throws BindingResolutionException
     */
    public function __construct(bool|Environment $differentConfig = false)
    {

        $this->settingsRepo = app()->make(SettingRepository::class);

        if (!$differentConfig) {
            $this->config = app()->make(Environment::class);
            //Map config vars
            $this->useLdap = $this->config->useLdap;

            //Don't do anything else if ldap is turned off
            if ($this->useLdap === false) {
                return false;
            }

            //Prepare and map in case we want to get the config from somewhere else in the future
            $this->host = $this->config->ldapHost;
            $this->ldapDn = $this->config->ldapDn;
            $this->defaultRoleKey = $this->config->ldapDefaultRoleKey;
            $this->port = $this->config->ldapPort;
            $this->ldapLtGroupAssignments = json_decode(trim(preg_replace('/\s+/', '', $this->config->ldapLtGroupAssignments)));
            $this->ldapKeys = $this->settingsRepo->getSetting('companysettings.ldap.ldapKeys') ? json_decode($this->settingsRepo->getSetting('companysettings.ldap.ldapKeys')) : json_decode(trim(preg_replace('/\s+/', '', $this->config->ldapKeys)));
            $this->directoryType = $this->config->ldapType;

            $this->ldapDomain = $this->config->ldapDomain;
            $this->ldapUri = $this->config->ldapUri;

            if (!is_object($this->ldapLtGroupAssignments)) {
                error_log("LDAP: Group Assignment array failed to parse. Please check for valid json");
                return false;
            }

            if (!is_object($this->ldapKeys)) {
                error_log("LDAP: Ldap Keys failed to parse. Please check for valid json");
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool|void
     */
    public function connect()
    {

        if (!$this->config->useLdap) {
            return false;
        }

        if (function_exists("ldap_connect")) {
            if ($this->ldapUri != '' && str_starts_with($this->ldapUri, "ldap")) {
                $this->ldapConnection = ldap_connect($this->ldapUri);
            } else {
                $this->ldapConnection = ldap_connect($this->host, $this->port);
            }

            ldap_set_option($this->ldapConnection, LDAP_OPT_PROTOCOL_VERSION, 3) or die('Unable to set LDAP protocol version');
            ldap_set_option($this->ldapConnection, LDAP_OPT_REFERRALS, 0); // We need this for doing an LDAP search.

            if ($this->config->debug) {
                ldap_set_option($this->ldapConnection, LDAP_OPT_DEBUG_LEVEL, 7);
            }

            return true;
        } else {
            error_log("ldap extension not installed", 0);
            return false;
        }
    }

    /**
     * @param string $username
     * @param string $password
     * @return bool
     */
    public function bind(string $username = '', string $password = ''): bool
    {

        if ($username != '' && $password != '') {
            $passwordBind = $password;

            //AD allows usenrame login
            if ($this->directoryType == 'AD') {
                $usernameDN = $username;

                $bind = ldap_bind($this->ldapConnection, $usernameDN, $passwordBind);
                if ($bind) {
                    return true;
                }

                $bind = ldap_bind($this->ldapConnection, $usernameDN . "@" . $this->ldapDomain, $passwordBind);
                //OL requires distinguished name login
            } else {
                $usernameDN = $this->ldapKeys->username . "=" . $username . "," . $this->ldapDn;

                $bind = ldap_bind($this->ldapConnection, $usernameDN, $passwordBind);
            }
            if ($bind) {
                return true;
            }

            if ($this->config->debug == 1) {
                error_log(ldap_error($this->ldapConnection));
                ldap_get_option($this->ldapConnection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $err);
                if ($err) {
                    error_log($err);
                }
            }
        }
        return false;
    }

    /**
     * @param $username
     * @return mixed|string|void
     */
    public function getEmail($username)
    {
        if (!$this->ldapConnection) {
            error_log("No connection, last error: " . ldap_error($this->ldapConnection));
        }
        $filter = "(" . $this->ldapKeys->username . "=" . $this->extractLdapFromUsername($username) . ")";

        $attr = array($this->ldapKeys->groups, $this->ldapKeys->firstname, $this->ldapKeys->lastname, $this->ldapKeys->email);

        $result = ldap_search($this->ldapConnection, $this->ldapDn, $filter, $attr) or exit("Unable to search LDAP server");
        $entries = ldap_get_entries($this->ldapConnection, $result);

        if ($entries === false) {
            error_log(ldap_error($this->ldapConnection));
            ldap_get_option($this->ldapConnection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $err);
            error_log($err);
        }
        $mail = isset($entries[0][$this->ldapKeys->email]) ? $entries[0][$this->ldapKeys->email][0] : '';
        return $mail;
    }

    /**
     * @param $username
     * @return array|false|void
     */
    public function getSingleUser($username)
    {

        if (!$this->ldapConnection) {
            error_log("No connection, last error: " . ldap_error($this->ldapConnection));
        }

        $filter = "(" . $this->ldapKeys->username . "=" . $this->extractLdapFromUsername($username) . ")";

        $attr = array($this->ldapKeys->groups, $this->ldapKeys->firstname, $this->ldapKeys->lastname, $this->ldapKeys->email, $this->ldapKeys->phone);

        $result = ldap_search($this->ldapConnection, $this->ldapDn, $filter, $attr) or exit("Unable to search LDAP server");
        $entries = ldap_get_entries($this->ldapConnection, $result);

        if ($entries === false || !isset($entries[0])) {
            error_log(ldap_error($this->ldapConnection));
            ldap_get_option($this->ldapConnection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $err);
            error_log($err);
            return false;
        }

        //Find Role
        $role = $this->defaultRoleKey;

        foreach ($entries[0][$this->ldapKeys->groups] as $grps) {
            foreach ($this->ldapLtGroupAssignments as $key => $row) {
                if ($row->ldapRole != "") {
                    if (strpos($grps, $row->ldapRole)) {
                        if ($key > $role) {
                            $role = $key;
                        }
                    }
                }
            }
        }
        //Find Firstname & Lastname
        $firstname = isset($entries[0][$this->ldapKeys->firstname]) ? $entries[0][$this->ldapKeys->firstname][0] : '';
        $lastname = isset($entries[0][$this->ldapKeys->lastname]) ? $entries[0][$this->ldapKeys->lastname][0] : '';
        $phone = isset($entries[0][$this->ldapKeys->phone]) ? $entries[0][$this->ldapKeys->phone][0] : '';
        $uname = isset($entries[0][$this->ldapKeys->email]) ? $entries[0][$this->ldapKeys->email][0] : '';
        $jobTitle = isset($entries[0][$this->ldapKeys->jobTitle]) ? $entries[0][$this->ldapKeys->jobTitle][0] : '';
        $jobLevel = isset($entries[0][$this->ldapKeys->jobLevel]) ? $entries[0][$this->ldapKeys->jobLevel][0] : '';
        $department = isset($entries[0][$this->ldapKeys->department]) ? $entries[0][$this->ldapKeys->department][0] : '';

        if ($this->config->debug) {
            error_log("LEANTIME: Testing the logging\n");

            error_log("LEANTIME: >>>Attributes Begin>>>>>>\n");
            error_log("LEANTIME: fn $firstname", 0);
            error_log("LEANTIME: sn $lastname", 0);
            error_log("LEANTIME: phone $phone", 0);
            error_log("LEANTIME: role $role", 0);
            error_log("LEANTIME: username $uname ", 0);
            error_log("LEANTIME: jobTitle $jobTitle ", 0);
            error_log("LEANTIME: jobLevel $jobLevel ", 0);
            error_log("LEANTIME: department $department ", 0);
            error_log("LEANTIME: >>>Attributes End>>>>>>\n", 0);
        }

        return array(
            "user" => $uname,
            "firstname" => $firstname,
            "lastname" => $lastname,
            "role" => $role,
            "phone" => $phone,
            "jobTitle" => $jobTitle,
            "jobLevel" => $jobLevel,
            "department" => $department,
        );
    }

    /**
     * @param $username
     * @return mixed|string
     */
    public function extractLdapFromUsername($username): mixed
    {

        $getLdap = explode("@", $username);

        if ($getLdap && is_array($getLdap)) {
            return $getLdap[0];
        } else {
            return "";
        }
    }

    /**
     * @return array|false|void
     */
    /**
     * @return array|false|void
     */
    public function getAllMembers()
    {

        if (function_exists("ldap_search")) {
            $attr = array($this->ldapKeys->groups, $this->ldapKeys->firstname, $this->ldapKeys->lastname);

            $filter = "(cn=*)";

            $result = ldap_search($this->ldapConnection, $this->ldapDn, $filter, $attr) or exit("Unable to search LDAP server");
            $entries = ldap_get_entries($this->ldapConnection, $result);

            $allUsers = array();

            foreach ($entries as $key => $row) {
                if (isset($row["dn"])) {
                    preg_match('/(?:^|.*,)uid=(.*?)(?:,.*$|$)/', $row["dn"], $usernameArray);

                    if (count($usernameArray) > 0) {
                        $allUsers[] = $this->getSingleUser($usernameArray[1]);
                    }
                }
            }

            return $allUsers;
        } else {
            error_log("ldap extension not installed", 0);
            return false;
        }
    }

    /**
     * @param $ldapUsers
     * @return bool
     * @throws BindingResolutionException
     */
    public function upsertUsers($ldapUsers): bool
    {

        $userRepo = app()->make(UserRepository::class);

        foreach ($ldapUsers as $user) {
            //Update
            $checkUser = $userRepo->getUserByEmail($user['user']);

            if (is_array($checkUser)) {
                $userRepo->patchUser($checkUser['id'], array("firstname" => $user["firstname"], "lastname" => $user["lastname"], "role" => $user["role"]));
            } else {
                //Insert
                $userArray = array(
                    'firstname' => $user['firstname'],
                    'lastname' => $user['lastname'],
                    'phone' => $user['phone'],
                    'user' => $user['user'],
                    'role' => $user['role'],
                    'password' => '',
                    'clientId' => '',
                    'jobTitle' => $user['jobTitle'],
                    'jobLevel' => $user['jobLevel'],
                    'department' => $user['department'],
                    'source' => 'ldap',
                );


                $userRepo->addUser($userArray);
            }
        }

        return true;
    }
}
