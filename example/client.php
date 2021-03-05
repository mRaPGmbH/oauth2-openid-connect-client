<?php
require '../vendor/autoload.php';

$key = sprintf('file://%s/public.key', realpath(__DIR__));

$signer   = new \Lcobucci\JWT\Signer\Rsa\Sha256();
$provider = new \OpenIDConnectClient\OpenIDConnectProvider([
    'clientId'                => 'demoapp',
    'clientSecret'            => 'demopass',
    'idTokenIssuer'           => 'localhost:8080',
    // Your server
    'redirectUri'             => 'http://localhost:8081/',
    'urlAuthorize'            => 'http://localhost:8080/lockdin/authorize',
    'urlAccessToken'          => 'http://localhost:8080/lockdin/token',
    'urlResourceOwnerDetails' => 'http://localhost:8080/lockdin/resource',
    // Find the public key here: https://github.com/bshaffer/oauth2-demo-php/blob/master/data/pubkey.pem
    // to test against brentertainment.com
    'publicKey'                 => $key,
],
    [
        'signer' => $signer
    ]
);

// send the authorization request
if (empty($_GET['code'])) {
    $redirectUrl = $provider->getAuthorizationUrl();
    header(sprintf('Location: %s', $redirectUrl), true, 302);
    return;
}

// receive authorization response
try {
    $token = $provider->getAccessToken('authorization_code', [
        'code' => $_GET['code']
    ]);
} catch (\OpenIDConnectClient\Exception\InvalidTokenException $e) {
    $errors = $provider->getValidatorChain()->getMessages();
    echo $e->getMessage();
    var_dump($errors);
    return;
} catch (\Exception $e) {
    echo $e->getMessage();
    $errors = $provider->getValidatorChain()->getMessages();
    var_dump($errors);
    return;
}

$response = [
    "Token: " . $token->getToken(),
    "Refresh Token: ". $token->getRefreshToken(),
    "Expires: ". $token->getExpires(),
    "Has Expired: ". $token->hasExpired(),
    "All Claims: ". print_r($token->getIdToken()->claims(), true)
];

echo join("<br />", $response);
