<?php
/**
 * @author Steve Rhoades <sedonami@gmail.com>
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace OpenIDConnectClient;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Validation\Constraint;
use League\OAuth2\Client\Provider\GenericProvider;
use InvalidArgumentException;
use OpenIDConnectClient\Exception\InvalidTokenException;
use OpenIDConnectClient\Validator\EqualsTo;
use OpenIDConnectClient\Validator\EqualsToOrContains;
use OpenIDConnectClient\Validator\GreaterOrEqualsTo;
use OpenIDConnectClient\Validator\LesserOrEqualsTo;
use OpenIDConnectClient\Validator\NotEmpty;
use OpenIDConnectClient\Validator\ValidatorChain;
use League\OAuth2\Client\Grant\AbstractGrant;

class OpenIDConnectProvider extends GenericProvider
{
    /**
     * @var string
     */
    protected $publicKey;

    /**
     * @var Signer
     */
    protected $signer;

    /**
     * @var ValidatorChain
     */
    protected $validatorChain;

    /**
     * @var string
     */
    protected $idTokenIssuer;

    /**
     * @param array $options
     * @param array $collaborators
     */
    public function __construct(array $options = [], array $collaborators = [])
    {
        if (empty($collaborators['signer']) || false === $collaborators['signer'] instanceof Signer) {
            throw new InvalidArgumentException('Must pass a valid signer to OpenIdConnectProvider');
        }

        $this->signer = $collaborators['signer'];

        $this->validatorChain = new ValidatorChain();
        $this->validatorChain->setValidators([
            new NotEmpty('iat', true),
            new GreaterOrEqualsTo('exp', true),
            new EqualsTo('iss', true),
            new EqualsToOrContains('aud', true),
            new NotEmpty('sub', true),
            new LesserOrEqualsTo('nbf'),
            new EqualsTo('jti'),
            new EqualsTo('azp'),
            new EqualsTo('nonce'),
        ]);

        if (empty($options['scopes'])) {
            $options['scopes'] = [];
        } else if (!is_array($options['scopes'])) {
            $options['scopes'] = [$options['scopes']];
        }

        if(!in_array('openid', $options['scopes'])) {
            $options['scopes'][] = 'openid';
        }

        parent::__construct($options, $collaborators);
    }

    /**
     * Returns all options that are required.
     *
     * @return array
     */
    protected function getRequiredOptions()
    {
        $options = parent::getRequiredOptions();
        $options[] = 'publicKey';
        $options[] = 'idTokenIssuer';

        return $options;
    }

    public function getPublicKey()
    {
        return Key\InMemory::file($this->publicKey);
    }

    /**
     * Requests an access token using a specified grant and option set.
     *
     * @param  mixed $grant
     * @param  array $options
     *
     * @return \League\OAuth2\Client\Token\AccessToken|\League\OAuth2\Client\Token\AccessTokenInterface|AccessToken
     */
    public function getAccessToken($grant, array $options = [])
    {
        /** @var Token\Plain $token */
        $accessToken = parent::getAccessToken($grant, $options);
        $token       = $accessToken->getIdToken();

        // id_token is empty.
        if (null === $token) {
            throw new InvalidTokenException('Expected an id_token but did not receive one from the authorization server.');
        }

        // If the ID Token is received via direct communication between the Client and the Token Endpoint
        // (which it is in this flow), the TLS server validation MAY be used to validate the issuer in place of checking
        // the token signature. The Client MUST validate the signature of all other ID Tokens according to JWS [JWS]
        // using the algorithm specified in the JWT alg Header Parameter. The Client MUST use the keys provided by
        // the Issuer.
        //
        // The alg value SHOULD be the default of RS256 or the algorithm sent by the Client in the
        // id_token_signed_response_alg parameter during Registration.

        $config = Configuration::forSymmetricSigner($this->signer, $this->getPublicKey());
        $config->setValidationConstraints(new Constraint\SignedWith($this->signer, $this->getPublicKey()));

        if (false === $config->validator()->validate($token, ...$config->validationConstraints())) {
            throw new InvalidTokenException('Received an invalid id_token from authorization server.');
        }

        // validations
        // @see http://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation
        // validate the iss (issuer)
        // - The Issuer Identifier for the OpenID Provider (which is typically obtained during Discovery)
        // MUST exactly match the value of the iss (issuer) Claim.
        // validate the aud
        // - The Client MUST validate that the aud (audience) Claim contains its client_id value registered at the Issuer
        // identified by the iss (issuer) Claim as an audience. The aud (audience) Claim MAY contain an array with more
        // than one element. The ID Token MUST be rejected if the ID Token does not list the Client as a valid audience,
        // or if it contains additional audiences not trusted by the Client.
        // - If a nonce value was sent in the Authentication Request, a nonce Claim MUST be present and its value checked
        // to verify that it is the same value as the one that was sent in the Authentication Request. The Client SHOULD
        // check the nonce value for replay attacks. The precise method for detecting replay attacks is Client specific.
        // - If the auth_time Claim was requested, either through a specific request for this Claim or by using
        // the max_age parameter, the Client SHOULD check the auth_time Claim value and request re-authentication if it
        // determines too much time has elapsed since the last End-User authentication.
        // - The nbf time should be in the future. An option of nbfToleranceSeconds can be sent and it will be added to
        // the currentTime in order to accept some difference in clocks
        // TODO
        // If the acr Claim was requested, the Client SHOULD check that the asserted Claim Value is appropriate.
        // The meaning and processing of acr Claim Values is out of scope for this specification.
        $currentTime = time();
        $nbfToleranceSeconds = isset($options['nbfToleranceSeconds'])? (int)$options['nbfToleranceSeconds'] : 0;
        $data = [
            'iss'       => $this->getIdTokenIssuer(),
            'exp'       => DateTimeImmutable::createFromFormat('U.u', $currentTime),
            'auth_time' => $currentTime,
            'iat'       => DateTimeImmutable::createFromFormat('U.u', $currentTime),
            'nbf'       => DateTimeImmutable::createFromFormat('U.u', $currentTime + $nbfToleranceSeconds),
            'aud'       => $this->clientId
        ];

        // If the ID Token contains multiple audiences, the Client SHOULD verify that an azp Claim is present.
        // If an azp (authorized party) Claim is present, the Client SHOULD verify that its client_id is the Claim Value.
        if ($token->claims()->has('azp')) {
            $data['azp'] = $this->clientId;
        }

        if (false === $this->validatorChain->validate($data, $token)) {
            throw new InvalidTokenException('The id_token did not pass validation.');
        }

        return $accessToken;
    }

    /**
     * Overload parent as OpenID Connect specification states scopes shall be separated by spaces
     *
     * @return string
     */
    protected function getScopeSeparator()
    {
        return ' ';
    }

    /**
     * @return ValidatorChain|void
     */
    public function getValidatorChain()
    {
        return $this->validatorChain;
    }

    /**
     * Get the issuer of the OpenID Connect id_token
     *
     * @return string
     */
    protected function getIdTokenIssuer()
    {
        return $this->idTokenIssuer;
    }


    /**
     * Creates an access token from a response.
     *
     * The grant that was used to fetch the response can be used to provide
     * additional context.
     *
     * @param  array $response
     * @param  AbstractGrant $grant
     * @return AccessToken
     */
    protected function createAccessToken(array $response, AbstractGrant $grant)
    {
        return new AccessToken($response);
    }
}
