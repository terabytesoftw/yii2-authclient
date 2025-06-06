<?php
/**
 * @link https://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\authclient;

use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\JWSLoader;
use Jose\Component\Signature\JWSTokenSupport;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Yii;
use yii\authclient\signature\HmacSha;
use yii\base\InvalidConfigException;
use yii\caching\Cache;
use yii\di\Instance;
use yii\helpers\Json;
use yii\helpers\StringHelper;
use yii\web\HttpException;

/**
 * OpenIdConnect serves as a client for the OpenIdConnect flow.
 *
 * Application configuration example:
 *
 * ```php
 * 'components' => [
 *     'authClientCollection' => [
 *         'class' => 'yii\authclient\Collection',
 *         'clients' => [
 *             'google' => [
 *                 'class' => 'yii\authclient\OpenIdConnect',
 *                 'issuerUrl' => 'https://accounts.google.com',
 *                 'clientId' => 'google_client_id',
 *                 'clientSecret' => 'google_client_secret',
 *                 'name' => 'google',
 *                 'title' => 'Google OpenID Connect',
 *             ],
 *         ],
 *     ]
 *     // ...
 * ]
 * ```
 *
 * This class requires `web-token/jwt-checker`,`web-token/jwt-key-mgmt`, `web-token/jwt-signature`, `web-token/jwt-signature-algorithm-hmac`,
 * `web-token/jwt-signature-algorithm-ecdsa` and `web-token/jwt-signature-algorithm-rsa` libraries to be installed for
 * JWS verification. This can be done via composer:
 *
 * ```
 * composer require --prefer-dist "web-token/jwt-checker:>=1.0 <3.0" "web-token/jwt-signature:>=1.0 <3.0"
 * "web-token/jwt-signature:>=1.0 <3.0" "web-token/jwt-signature-algorithm-hmac:>=1.0 <3.0"
 * "web-token/jwt-signature-algorithm-ecdsa:>=1.0 <3.0" "web-token/jwt-signature-algorithm-rsa:>=1.0 <3.0"
 * ```
 *
 * Note: if you are using well-trusted OpenIdConnect provider, you may disable [[validateJws]], making installation of
 * `web-token` library redundant, however it is not recommended as it violates the protocol specification.
 *
 * @see https://openid.net/connect/
 * @see OAuth2
 *
 * @property Cache|null $cache The cache object, `null` - if not enabled. Note that the type of this property
 * differs in getter and setter. See [[getCache()]] and [[setCache()]] for details.
 * @property array $configParams OpenID provider configuration parameters.
 * @property bool $validateAuthNonce Whether to use and validate auth 'nonce' parameter in authentication
 * flow.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 2.1.3
 */
class OpenIdConnect extends OAuth2
{
    /**
     * {@inheritdoc}
     */
    public $accessTokenLocation = OAuth2::ACCESS_TOKEN_LOCATION_HEADER;
    /**
     * @var array Predefined OpenID Connect Claims
     * @see https://openid.net/specs/openid-connect-core-1_0.html#rfc.section.2
     * @since 2.2.12
     */
    public $defaultIdTokenClaims = [
        'iss', // Issuer Identifier for the Issuer of the response.
        'sub', // Subject Identifier.
        'aud', // Audience(s) that this ID Token is intended for.
        'exp', // Expiration time on or after which the ID Token MUST NOT be accepted for processing.
        'iat', // Time at which the JWT was issued.
        'auth_time', // Time when the End-User authentication occurred.
        'nonce', // String value used to associate a Client session with an ID Token, and to mitigate replay attacks.
        'acr', // Authentication Context Class Reference.
        'amr', // Authentication Methods References.
        'azp', // Authorized party - the party to which the ID Token was issued.
    ];
    /**
     * {@inheritdoc}
     */
    public $scope = 'openid';
    /**
     * @var string OpenID Issuer (provider) base URL, e.g. `https://example.com`.
     */
    public $issuerUrl;
    /**
     * @var bool whether to validate/decrypt JWS received with Auth token.
     * Note: this functionality requires `web-token/jwt-checker`, `web-token/jwt-key-mgmt`, `web-token/jwt-signature`
     * composer package to be installed. You can disable this option in case of usage of trusted OpenIDConnect provider,
     * however this violates the protocol rules, so you are doing it on your own risk.
     */
    public $validateJws = true;
    /**
     * @var array JWS algorithms, which are allowed to be used.
     * These are used by `web-token` library for JWS validation/decryption.
     * Make sure to install `web-token/jwt-signature-algorithm-hmac`, `web-token/jwt-signature-algorithm-ecdsa`
     * and `web-token/jwt-signature-algorithm-rsa` packages that support the particular algorithm before adding it here.
     */
    public $allowedJwsAlgorithms = [
        'HS256', 'HS384', 'HS512',
        'ES256', 'ES384', 'ES512',
        'RS256', 'RS384', 'RS512',
        'PS256', 'PS384', 'PS512'
    ];
    /**
     * @var string the prefix for the key used to store [[configParams]] data in cache.
     * Actual cache key will be formed addition [[id]] value to it.
     * @see cache
     */
    public $configParamsCacheKeyPrefix = 'config-params-';

    /**
     * @var bool|null whether to use and validate auth 'nonce' parameter in authentication flow.
     * The option is used for preventing replay attacks.
     */
    private $_validateAuthNonce;
    /**
     * @var array OpenID provider configuration parameters.
     */
    private $_configParams;
    /**
     * @var Cache|string the cache object or the ID of the cache application component that
     * is used for caching. This can be one of the following:
     *
     * - an application component ID (e.g. `cache`)
     * - a configuration array
     * - a [[\yii\caching\Cache]] object
     *
     * When this is not set, it means caching is not enabled.
     */
    private $_cache = 'cache';
    /**
     * @var JWSLoader JSON Web Signature
     */
    private $_jwsLoader;
    /**
     * @var JWKSet Key Set
     */
    private $_jwkSet;
    /**
     * @var int cache duration in seconds, default: 1 week
     */
    private $cacheDuration = 604800;


    /**
     * @return bool whether to use and validate auth 'nonce' parameter in authentication flow.
     */
    public function getValidateAuthNonce()
    {
        if ($this->_validateAuthNonce === null) {
            $this->_validateAuthNonce = $this->validateJws && in_array('nonce', $this->getConfigParam('claims_supported'));
        }
        return $this->_validateAuthNonce;
    }

    /**
     * @param bool $validateAuthNonce whether to use and validate auth 'nonce' parameter in authentication flow.
     */
    public function setValidateAuthNonce($validateAuthNonce)
    {
        $this->_validateAuthNonce = $validateAuthNonce;
    }

    /**
     * @return Cache|null the cache object, `null` - if not enabled.
     */
    public function getCache()
    {
        if ($this->_cache !== null && !is_object($this->_cache)) {
            $this->_cache = Instance::ensure($this->_cache, Cache::className());
        }
        return $this->_cache;
    }

    /**
     * Sets up a component to be used for caching.
     * This can be one of the following:
     *
     * - an application component ID (e.g. `cache`)
     * - a configuration array
     * - a [[\yii\caching\Cache]] object
     *
     * When `null` is passed, it means caching is not enabled.
     * @param Cache|array|string|null $cache the cache object or the ID of the cache application component.
     */
    public function setCache($cache)
    {
        $this->_cache = $cache;
    }

    /**
     * @return array OpenID provider configuration parameters.
     */
    public function getConfigParams()
    {
        if ($this->_configParams === null) {
            $cache = $this->getCache();
            $cacheKey = $this->configParamsCacheKeyPrefix . $this->getId();
            if ($cache === null || ($configParams = $cache->get($cacheKey)) === false) {
                $configParams = $this->discoverConfig();

                if ($cache !== null) {
                    $cache->set($cacheKey, $configParams, $this->cacheDuration);
                }
            }

            $this->_configParams = $configParams;
        }
        return $this->_configParams;
    }

    /**
     * Returns particular configuration parameter value.
     * @param string $name configuration parameter name.
     * @param mixed $default value to be returned if the configuration parameter isn't set.
     * @return mixed configuration parameter value.
     */
    public function getConfigParam($name, $default = null)
    {
        $params = $this->getConfigParams();
        return array_key_exists($name, $params) ? $params[$name] : $default;
    }

    /**
     * Discovers OpenID Provider configuration parameters.
     * @return array OpenID Provider configuration parameters.
     * @throws InvalidResponseException on failure.
     */
    protected function discoverConfig()
    {
        $request = $this->createRequest();
        $configUrl = rtrim($this->issuerUrl, '/') . '/.well-known/openid-configuration';
        $request->setMethod('GET')
            ->setUrl($configUrl);
        $response = $this->sendRequest($request);
        return $response;
    }

    /**
     * Set the OpenID provider configuration manually, this will bypass the automatic discovery via
     * the /.well-known/openid-configuration endpoint.
     * @param array $configParams OpenID provider configuration parameters.
     * @since 2.2.12
     */
    public function setConfigParams($configParams)
    {
        $this->_configParams = $configParams;
    }

    /**
     * {@inheritdoc}
     */
    public function buildAuthUrl(array $params = [])
    {
        if ($this->authUrl === null) {
            $this->authUrl = $this->getConfigParam('authorization_endpoint');
        }

        if (!isset($params['nonce']) && $this->getValidateAuthNonce()) {
            $nonce = $this->generateAuthNonce();
            $this->setState('authNonce', $nonce);
            $params['nonce'] = $nonce;
        }

        return parent::buildAuthUrl($params);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAccessToken($authCode, array $params = [])
    {
        if ($this->tokenUrl === null) {
            $this->tokenUrl = $this->getConfigParam('token_endpoint');
        }

        if (!isset($params['nonce']) && $this->getValidateAuthNonce()) {
            $params['nonce'] = $this->getState('authNonce');
        }

        return parent::fetchAccessToken($authCode, $params);
    }

    /**
     * {@inheritdoc}
     */
    public function refreshAccessToken(OAuthToken $token)
    {
        if ($this->tokenUrl === null) {
            $this->tokenUrl = $this->getConfigParam('token_endpoint');
        }

        if ($this->getValidateAuthNonce()) {
            $nonce = $this->generateAuthNonce();
            $this->setState('authNonce', $nonce);
            $token->setParam('nonce', $nonce);
        }

        return parent::refreshAccessToken($token);
    }

    /**
     * {@inheritdoc}
     */
    protected function initUserAttributes()
    {
        // Use 'userinfo_endpoint' config if available,
        // try to extract user claims from access token's 'id_token' claim otherwise.

        $userinfoEndpoint = $this->getConfigParam('userinfo_endpoint');
        if (!empty($userinfoEndpoint)) {
            $userInfo = $this->api($userinfoEndpoint, 'GET');
            // The userinfo endpoint can return a JSON object (which will be converted to an array) or a JWT.
            if (is_array($userInfo)) {
                return $userInfo;
            } else {
                // Use the userInfo endpoint as id_token and parse it as JWT below
                $idToken = $userInfo;
            }
        } else {
            $accessToken = $this->accessToken;
            if ($accessToken !== null) {
                $idToken = $accessToken->getParam('id_token');
            }
        }

        $idTokenData = [];
        if (!empty($idToken)) {
            if ($this->validateJws) {
                $idTokenClaims = $this->loadJws($idToken);
            } else {
                $idTokenClaims = Json::decode(StringHelper::base64UrlDecode(explode('.', $idToken)[1]));
            }
            $metaDataFields = array_flip($this->defaultIdTokenClaims);
            unset($metaDataFields['sub']); // "Subject Identifier" is not meta data
            $idTokenData = array_diff_key($idTokenClaims, $metaDataFields);
        }

        return $idTokenData;
    }

    /**
     * {@inheritdoc}
     */
    protected function applyClientCredentialsToRequest($request)
    {
        $supportedAuthMethods = $this->getConfigParam('token_endpoint_auth_methods_supported', ['client_secret_basic']);

        if (in_array('client_secret_basic', $supportedAuthMethods)) {
            $request->addHeaders([
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret)
            ]);
        } elseif (in_array('client_secret_post', $supportedAuthMethods)) {
            $request->addData([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);
        } elseif (in_array('client_secret_jwt', $supportedAuthMethods)) {
            $header = [
                'typ' => 'JWT',
                'alg' => 'HS256',
            ];
            $payload = [
                'iss' => $this->clientId,
                'sub' => $this->clientId,
                'aud' => $this->tokenUrl,
                'jti' => $this->generateAuthNonce(),
                'iat' => time(),
                'exp' => time() + 3600,
            ];

            $signatureBaseString = base64_encode(Json::encode($header)) . '.' . base64_encode(Json::encode($payload));
            $signatureMethod = new HmacSha(['algorithm' => 'sha256']);
            $signature = $signatureMethod->generateSignature($signatureBaseString, $this->clientSecret);

            $assertion = $signatureBaseString . '.' . $signature;

            $request->addData([
                'assertion' => $assertion,
            ]);
        } else {
            throw new InvalidConfigException('Unable to authenticate request: none of following auth methods is supported: ' . implode(', ', $supportedAuthMethods));
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function createToken(array $tokenConfig = [])
    {
        if ($this->validateJws) {
            $jwsData = $this->loadJws($tokenConfig['params']['id_token']);
            $this->validateClaims($jwsData);
            $tokenConfig['params'] = array_merge($tokenConfig['params'], $jwsData);

            if ($this->getValidateAuthNonce()) {
                $authNonce = $this->getState('authNonce');
                if (
                    !isset($jwsData['nonce'])
                    || empty($authNonce)
                    || !Yii::$app->getSecurity()->compareString($jwsData['nonce'], $authNonce)
                ) {
                    throw new HttpException(400, 'Invalid auth nonce');
                } else {
                    $this->removeState('authNonce');
                }
            }
        }

        return parent::createToken($tokenConfig);
    }

    /**
     * Return JwkSet, returning related data.
     * @return JWKSet object represents a key set.
     * @throws InvalidResponseException on failure.
     */
    protected function getJwkSet()
    {
        if ($this->_jwkSet === null) {
            $cache = $this->getCache();
            $cacheKey = $this->configParamsCacheKeyPrefix . '_jwkSet';
            if ($cache === null || ($jwkSet = $cache->get($cacheKey)) === false) {
                $request = $this->createRequest()
                    ->setMethod('GET')
                    ->setUrl($this->getConfigParam('jwks_uri'));
                $response = $this->sendRequest($request);
                $jwkSet = JWKFactory::createFromValues($response);

                if ($cache !== null) {
                    $cache->set($cacheKey, $jwkSet, $this->cacheDuration);
                }
            }

            $this->_jwkSet = $jwkSet;
        }
        return $this->_jwkSet;
    }

    /**
     * Return JWSLoader that validate the JWS token.
     * @return JWSLoader to do token validation.
     * @throws InvalidConfigException on invalid algorithm provide in configuration.
     */
    protected function getJwsLoader()
    {
        if ($this->_jwsLoader === null) {
            $algorithms = [];
            foreach ($this->allowedJwsAlgorithms as $algorithm)
            {
                $class = '\Jose\Component\Signature\Algorithm\\' . $algorithm;
                if (!class_exists($class))
                {
                    throw new InvalidConfigException("Alogrithm class $class doesn't exist");
                }
                $algorithms[] = new $class();
            }
            $this->_jwsLoader = new JWSLoader(
                new JWSSerializerManager([ new CompactSerializer() ]),
                new JWSVerifier(new AlgorithmManager($algorithms)),
                new HeaderCheckerManager(
                    [ new AlgorithmChecker($this->allowedJwsAlgorithms) ],
                    [ new JWSTokenSupport() ]
                )
            );
        }
        return $this->_jwsLoader;
    }

    /**
     * Decrypts/validates JWS, returning related data.
     * @param string $jws raw JWS input.
     * @return array JWS underlying data.
     * @throws HttpException on invalid JWS signature.
     */
    protected function loadJws($jws)
    {
        try {
            $jwsLoader = $this->getJwsLoader();
            $signature = null;
            $jwsVerified = $jwsLoader->loadAndVerifyWithKeySet($jws, $this->getJwkSet(), $signature);
            return Json::decode($jwsVerified->getPayload());
        } catch (\Exception $e) {
            $message = YII_DEBUG ? 'Unable to verify JWS: ' . $e->getMessage() : 'Invalid JWS';
            throw new HttpException(400, $message, $e->getCode(), $e);
        }
    }

    /**
     * Validates the claims data received from OpenID provider.
     * @param array $claims claims data.
     * @throws HttpException on invalid claims.
     * @since 2.2.3
     */
    protected function validateClaims(array $claims)
    {
        $expectedIssuer = $this->getConfigParam('issuer', $this->issuerUrl);
        if (!isset($claims['iss']) || (strcmp(rtrim($claims['iss'], '/'), rtrim($expectedIssuer, '/')) !== 0)) {
            throw new HttpException(400, 'Invalid "iss"');
        }
        if (!isset($claims['aud'])
            || (!is_string($claims['aud']) && !is_array($claims['aud']))
            || (is_string($claims['aud']) && strcmp($claims['aud'], $this->clientId) !== 0)
            || (is_array($claims['aud']) && !in_array($this->clientId, $claims['aud']))
        ) {
            throw new HttpException(400, 'Invalid "aud"');
        }
    }

    /**
     * Generates the auth nonce value.
     * @return string auth nonce value.
     */
    protected function generateAuthNonce()
    {
        return Yii::$app->security->generateRandomString();
    }
}
