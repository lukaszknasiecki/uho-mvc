<?php

namespace Huncwot\UhoFramework;

/**
 * This class provides AUTH0 integration
 * for _uho_client class
 */

class _uho_client_auth0
{
    /**
     * oauth0 login params
     */

    private $params = [];
    /**
     * _uho_client instance
     */
    private $client;

    /**
     * Class constructor
     * @param object $client _uho_client instance
     * @param array $params oauth0 login params
     * @return null
     */

    function __construct($client, $params)
    {
        $this->client = $client;
        if (@$params['debug']) $this->debug = true;
        $this->params = $params;

        $this->auth0 = new \Auth0\SDK\Auth0([
            'domain'        =>  $params['domain'],
            'clientId'      =>  $params['clientId'],
            'clientSecret'  =>  $params['clientSecret'],
            'cookieSecret'  =>  $params['cookieSecret'],
            'redirectUri'   =>  $params['callback']
        ]);
    }

    /**
     * Get's user data
     *
     * @return array|null returns user's data
     */
    public function getData(): array|null
    {
        $session = $this->auth0->getCredentials();

        if ($session === null) return null;
        else {
            $user = [
                'auth0_id' => $session->user['sub'],
                'name' => @$session->user['given_name'],
                'surname' => @$session->user['family_name'],
                'email' => $session->user['email']
            ];
            $user = $this->exchangeAuth0ToUho($user);

            return $user;
        }
    }

    /**
     * Excanhech Oauth0 token to _uho_client token
     * @param array $user users's object
     * @return array
     */

    private function exchangeAuth0ToUho($user)
    {
        $exists = $this->client->getClient(['auth0_id' => $user['auth0_id']], true);
        if ($exists) {
            return [
                'id' => $exists['id'],
                'auth0_id' => $user['auth0_id'],
                'name' => $exists['name'],
                'surname' => $exists['surname'],
                'email' => $user['email']
            ];
        } else {
            return $this->client->create($user, true);
        }
    }

    /**
     * Actions to be performed before login
     *
     * @return true
     */
    public function beforeLogin(): bool
    {
        if ($this->getData()) {
            return true;
        } else {
            header("Location: " . $this->auth0->login());
            //header("Location: " . $this->auth0->login(null,['max_age'=>0]));
            die();
        }
    }

    /**
     * Actions to be performed before logout
     */
    public function beforeLogout(): void
    {
        if ($this->getData()) {
            $this->auth0->logout();
        }
    }

    /**
     * Actions to be performed before lggin callback
     *
     * @return null|true
     */
    public function beforeLoginCallback($data)
    {
        $this->auth0->exchange($this->params['callback'], $data['code'], $data['state']);
        $client = $this->getData();
        if ($client) return true;
    }

    public function beforePasswordReset(): void
    {
        /*
        $this->auth0->configuration()->setManagementToken($env['AUTH0_MANAGEMENT_API_TOKEN']);
        $this->management = $this->auth0->management();
        $response = $this->management->users()->getAll(['q' => 'josh']);
        */
    }
}
