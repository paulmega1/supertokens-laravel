<?php
/* Copyright (c) 2020, VRAI Labs and/or its affiliates. All rights reserved.
 *
 * This software is licensed under the Apache License, Version 2.0 (the
 * "License") as published by the Apache Software Foundation.
 *
 * You may not use this file except in compliance with the License. You may
 * obtain a copy of the License at http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */
namespace SuperTokens;

use ArrayObject;
use DateTime;
use Exception;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use SuperTokens\Exceptions\SuperTokensException;
use SuperTokens\Exceptions\SuperTokensGeneralException;
use SuperTokens\Exceptions\SuperTokensTokenTheftException;
use SuperTokens\Exceptions\SuperTokensTryRefreshTokenException;
use SuperTokens\Exceptions\SuperTokensUnauthorisedException;
use SuperTokens\Helpers\Constants;
use SuperTokens\Helpers\HandshakeInfo;
use SuperTokens\Helpers\Querier;
use SuperTokens\Helpers\Utils;
use SuperTokens\Db\RefreshTokenDb;
use SuperTokens\Helpers\AccessTokenSigningKey;
use SuperTokens\Helpers\RefreshTokenSigningKey;
use SuperTokens\Helpers\AccessToken;
use SuperTokens\Helpers\RefreshToken;

/**
 * Class SessionHandlingFunctions
 * @package SuperTokensHandlingFunctions
 */
class SessionHandlingFunctions
{

    /**
     * @var bool
     */
    public static $TEST_SERVICE_CALLED = false; // for testing purpose

    /**
     * @var string | null
     */
    public static $TEST_FUNCTION_VERSION = null; // for testing purpose

    /**
     * @throws SuperTokensGeneralException
     */
    public static function reset()
    {
        if (App::environment("testing")) {
            self::$TEST_SERVICE_CALLED = false;
            self::$TEST_FUNCTION_VERSION = null;
        } else {
            throw SuperTokensException::generateGeneralException("calling testing function in non testing env");
        }
    }

    /**
     * SessionHandlingFunctions constructor.
     */
    public function __construct()
    {
    }

    /**
     * @param string $userId
     * @param array $jwtPayload
     * @param array $sessionData
     * @return array
     * @throws SuperTokensGeneralException
     */
    public static function createNewSession(string $userId, array $jwtPayload, array $sessionData)
    {
        if (count($jwtPayload) === 0) {
            $jwtPayload = new ArrayObject();
        }
        if (count($sessionData) === 0) {
            $sessionData = new ArrayObject();
        }
        $response = Querier::getInstance()->sendPostRequest(Constants::SESSION, [
            'userId' => $userId,
            'userDataInJWT' => $jwtPayload,
            'userDataInDatabase' => $sessionData
        ]);

        $instance = HandshakeInfo::getInstance();
        $instance->updateJwtSigningPublicKeyInfo($response['jwtSigningPublicKey'], $response['jwtSigningPublicKeyExpiryTime']);
        unset($response['status']);
        unset($response['jwtSigningPublicKey']);
        unset($response['jwtSigningPublicKeyExpiryTime']);
        if (!isset($response['accessToken']['domain'])) {
            $response['accessToken']['domain'] = null;
        }
        if (!isset($response['refreshToken']['domain'])) {
            $response['refreshToken']['domain'] = null;
        }
        if (!isset($response['idRefreshToken']['domain'])) {
            $response['idRefreshToken']['domain'] = null;
        }
        return $response;
    }

    /**
     * @param string $accessToken
     * @param string | null $antiCsrfToken
     * @param boolean $doAntiCsrfCheck
     * @param string | null $idRefreshToken
     * @return array
     * @throws SuperTokensGeneralException
     * @throws SuperTokensUnauthorisedException
     * @throws SuperTokensTryRefreshTokenException
     */
    public static function getSession($accessToken, $antiCsrfToken, $doAntiCsrfCheck)
    {
        $handshakeInfo = HandshakeInfo::getInstance();

        try {
            if ($handshakeInfo->jwtSigningPublicKeyExpiryTime > Utils::getCurrentTimestampMS()) {
                $accessTokenInfo = AccessToken::getInfoFromAccessToken($accessToken, $handshakeInfo->jwtSigningPublicKey, $handshakeInfo->enableAntiCsrf && $doAntiCsrfCheck);

                if (
                    $handshakeInfo->enableAntiCsrf &&
                    $doAntiCsrfCheck &&
                    (!isset($antiCsrfToken) || $antiCsrfToken !== $accessTokenInfo['antiCsrfToken'])
                ) {
                    if (!isset($antiCsrfToken)) {
                        throw SuperTokensException::generateTryRefreshTokenException("provided antiCsrfToken is undefined. If you do not want anti-csrf check for this API, please set doAntiCsrfCheck to false");
                    }
                    throw SuperTokensException::generateTryRefreshTokenException("anti-csrf check failed");
                }
                if (
                    !$handshakeInfo->accessTokenBlacklistingEnabled &&
                    !isset($accessTokenInfo['parentRefreshTokenHash1'])
                ) {
                    self::$TEST_SERVICE_CALLED = false; // for testing purpose
                    return [
                        'session' => [
                            'handle' => $accessTokenInfo['sessionHandle'],
                            'userId' => $accessTokenInfo['userId'],
                            'userDataInJWT' => $accessTokenInfo['userData']
                        ]
                    ];
                }
            }
        } catch (SuperTokensTryRefreshTokenException $e) {
            // we continue to call the service
        } catch (Exception $e) {
            throw $e;
        }

        self::$TEST_SERVICE_CALLED = true; // for testing purpose

        $requestBody = [
            'accessToken' => $accessToken,
            'doAntiCsrfCheck' => $doAntiCsrfCheck
        ];
        if (isset($antiCsrfToken)) {
            $requestBody['antiCsrfToken'] = $antiCsrfToken;
        }
        $response = Querier::getInstance()->sendPostRequest(Constants::SESSION_VERIFY, $requestBody);

        if ($response['status'] === "OK") {
            $instance = HandshakeInfo::getInstance();
            $instance->updateJwtSigningPublicKeyInfo($response['jwtSigningPublicKey'], $response['jwtSigningPublicKeyExpiryTime']);
            unset($response['status']);
            unset($response['jwtSigningPublicKey']);
            unset($response['jwtSigningPublicKeyExpiryTime']);
            if (isset($response['accessToken'])) {
                if (!isset($response['accessToken']['domain'])) {
                    $response['accessToken']['domain'] = null;
                }
            }
            return $response;
        } elseif ($response['status'] === "UNAUTHORISED") {
            throw SuperTokensException::generateUnauthorisedException($response['message']);
        } else {
            throw SuperTokensException::generateTryRefreshTokenException($response['message']);
        }
    }

    /**
     * @param $refreshToken
     * @param string | null $antiCsrfToken
     * @return array
     * @throws SuperTokensGeneralException
     * @throws SuperTokensUnauthorisedException
     * @throws SuperTokensTokenTheftException
      */
    public static function refreshSession($refreshToken, $antiCsrfToken)
    {
        $requestBody = [
            'refreshToken' => $refreshToken
        ];
        if (isset($antiCsrfToken)) {
            $requestBody['antiCsrfToken'] = $antiCsrfToken;
        }
        $response = Querier::getInstance()->sendPostRequest(Constants::SESSION_REFRESH, $requestBody);
        if ($response['status'] === "OK") {
            unset($response['status']);
            if (!isset($response['accessToken']['domain'])) {
                $response['accessToken']['domain'] = null;
            }
            if (!isset($response['refreshToken']['domain'])) {
                $response['refreshToken']['domain'] = null;
            }
            if (!isset($response['idRefreshToken']['domain'])) {
                $response['idRefreshToken']['domain'] = null;
            }
            return $response;
        } elseif ($response['status'] === "UNAUTHORISED") {
            throw SuperTokensException::generateUnauthorisedException($response['message']);
        } else {
            throw SuperTokensException::generateTokenTheftException($response['session']['userId'], $response['session']['handle']);
        }
    }

    /**
     * @param string $userId
     * @return array
     * @throws SuperTokensGeneralException
     */
    public static function revokeAllSessionsForUser($userId)
    {
        $response = Querier::getInstance()->sendPostRequest(Constants::SESSION_REMOVE, [
            'userId' => $userId
        ]);
        self::$TEST_FUNCTION_VERSION = "2.0";
        return $response['sessionHandlesRevoked'];
    }

    /**
     * @param string $userId
     * @return array
     * @throws SuperTokensGeneralException
     */
    public static function getAllSessionHandlesForUser($userId)
    {
        $response = Querier::getInstance()->sendGetRequest(Constants::SESSION_USER, [
            'userId' => $userId
        ]);
        return $response['sessionHandles'];
    }

    /**
     * @param $sessionHandle
     * @return bool
     * @throws SuperTokensGeneralException
     */
    public static function revokeSession($sessionHandle)
    {
        $response = Querier::getInstance()->sendPostRequest(Constants::SESSION_REMOVE, [
            'sessionHandles' => [$sessionHandle]
        ]);
        self::$TEST_FUNCTION_VERSION = "2.0";
        return count($response['sessionHandlesRevoked']) === 1;
    }

    /**
     * @param array $sessionHandles
     * @return array
     * @throws SuperTokensGeneralException
     */
    public static function revokeMultipleSessions($sessionHandles)
    {
        $response = Querier::getInstance()->sendPostRequest(Constants::SESSION_REMOVE, [
            'sessionHandles' => $sessionHandles
        ]);
        return $response['sessionHandlesRevoked'];
    }

    /**
     * @param string $sessionHandle
     * @return array
     * @throws Exception
     * @throws SuperTokensUnauthorisedException
     */
    public static function getSessionData($sessionHandle)
    {
        $response = Querier::getInstance()->sendGetRequest(Constants::SESSION_DATA, [
            'sessionHandle' => $sessionHandle
        ]);
        if ($response['status'] === "OK") {
            return $response['userDataInDatabase'];
        }
        throw SuperTokensException::generateUnauthorisedException($response['message']);
    }

    /**
     * @param string $sessionHandle
     * @param array $newSessionData
     * @throws SuperTokensUnauthorisedException | SuperTokensGeneralException
     */
    public static function updateSessionData($sessionHandle, $newSessionData)
    {
        if (!isset($newSessionData) || is_null($newSessionData)) {
            throw SuperTokensException::generateGeneralException("session data passed to the function can't be null. Please pass empty array instead.");
        }
        if (count($newSessionData) === 0) {
            $newSessionData = new ArrayObject();
        }

        $response = Querier::getInstance()->sendPutRequest(Constants::SESSION_DATA, [
            'sessionHandle' => $sessionHandle,
            'userDataInDatabase' => $newSessionData
        ]);
        if ($response['status'] === Constants::EXCEPTION_UNAUTHORISED) {
            throw new SuperTokensUnauthorisedException($response['message']);
        }
    }

    /**
     * @param string $sessionHandle
     * @return array
     * @throws SuperTokensGeneralException
     * @throws SuperTokensUnauthorisedException
     */
    public static function getJWTPayload($sessionHandle)
    {
        $response = Querier::getInstance()->sendGetRequest(Constants::JWT_DATA, [
            'sessionHandle' => $sessionHandle
        ]);
        if ($response['status'] === "OK") {
            return $response['userDataInJWT'];
        }
        throw SuperTokensException::generateUnauthorisedException($response['message']);
    }

    /**
     * @param string $sessionHandle
     * @param array $newJWTPayload
     * @throws SuperTokensGeneralException
     * @throws SuperTokensUnauthorisedException
     */
    public static function updateJWTPayload($sessionHandle, $newJWTPayload)
    {
        if (!isset($newJWTPayload) || is_null($newJWTPayload)) {
            throw SuperTokensException::generateGeneralException("jwt data passed to the function can't be null. Please pass empty array instead.");
        }
        if (count($newJWTPayload) === 0) {
            $newJWTPayload = new ArrayObject();
        }

        $response = Querier::getInstance()->sendPutRequest(Constants::JWT_DATA, [
            'sessionHandle' => $sessionHandle,
            'userDataInJWT' => $newJWTPayload
        ]);
        if ($response['status'] === Constants::EXCEPTION_UNAUTHORISED) {
            throw new SuperTokensUnauthorisedException($response['message']);
        }
    }
}
