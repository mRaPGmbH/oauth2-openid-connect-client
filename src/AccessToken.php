<?php
/**
 * @author Steve Rhoades <sedonami@gmail.com>
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace OpenIDConnectClient;

use Lcobucci\JWT\Configuration;

class AccessToken extends \League\OAuth2\Client\Token\AccessToken
{
    protected $idToken;

    public function __construct($options = [])
    {
        parent::__construct($options);

        if (!empty($this->values['id_token'])) {
            $this->idToken = Configuration::forUnsecuredSigner()->parser()->parse($this->values['id_token']);
            unset($this->values['id_token']);
        }
    }

    public function getIdToken()
    {
        return $this->idToken;
    }
    
    public function jsonSerialize()
    {
        $parameters = parent::jsonSerialize();
        if ($this->idToken) {
            $parameters['id_token'] = $this->idToken->toString();
        }

        return $parameters;
    }    
}
