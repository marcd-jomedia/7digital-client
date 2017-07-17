<?php
/**
 *
 */

namespace SevenDigital\Api;

use JoMedia\Music\MusicProviderException;
use SevenDigital\Oauth\Interfaces\OauthInterface;
use SevenDigital\Oauth\OauthFactory;
use Curl\Curl;
use \DateTime;
use \DateInterval;
use \DateTimeZone;
use \Exception;

/**
 * ApiClient 
 */
class ApiClient
{
    /**
     * @var array
     */
    private $config = array();

    /**
     * @var OauthInterface
     */
    private $oauth;

    /**
     * __construct 
     * 
     * @param array $config config 
     * 
     * @return void
     */
    public function __construct(array $config)
    {
        // TODO Validate config
        $this->config = $config;
        $oauthFactory = new OauthFactory();
        $this->oauth = $oauthFactory->create(1.0, $config);
    }

    /**
     * @param array $params
     * @return bool
     */
    public function createUser(array $params)
    {
        $requestUrl = $this->oauth->signRequest('GET', 'user/create', $params);

        $curl = new Curl();
        $response = $curl->get($requestUrl);

        if ($response->error) {
            return false;
        } else {
            return $response->user;
        }
    }

    /**
     * getStreamUrl 
     * 
     * @param array $params params 
     * 
     * @return void
     */
    public function getStreamUrl(array $params)
    {
        $oauthFactory = new OauthFactory();
        $oauth = $oauthFactory->create(1.0, $this->config);
        $requestUrl = $oauth->signRequest('GET', 'stream/subscription', $params);
        return $requestUrl;
    }

    /**
     * getStreamOfflineUrl 
     * 
     * @param array $params params 
     * 
     * @return void
     */
    public function getDownloadUrl(array $params)
    {
        $oauthFactory = new OauthFactory();
        $oauth = $oauthFactory->create(1.0, $this->config);
        $requestUrl = $oauth->signRequest('GET', 'offline/subscription', $params);
        return $requestUrl;
    }

    /**
     * getPreviewUrl 
     * 
     * @param array $params params 
     * 
     * @return void
     */
    public function getPreviewUrl(array $params)
    {
        $trackId = $params['trackId'];
        unset($params['trackId']);
        $oauthFactory = new OauthFactory();
        $oauth = $oauthFactory->create(1.0, $this->config);
        $requestUrl = $oauth->signRequest('GET', $trackId, $params);
        return $requestUrl;
    }

    /**
     * Authorize / unauthorize the device for the offline streaming. 
     * 
     * @param int $userId The id of our member.
     * @param string $clientId The unique identifier of the member/device.
     * @param string $countryCode The country code to authorized
     * @param bool $authorized Enable the device (true) or disable the device (false)
     * 
     * @throws Exception
     * @return boolean ture on success.
     */
    public function subscribeOfflineDevice($userId, $clientId, $countryCode, $authorized)
    {
        $params = $this->oauth->signRequest('POST', 'user/unlimitedStreaming/offline', 
            array(
                'userId' => $userId,
                'clientId' => $clientId,
                'offlineEnabled' => (true === $authorized ? 'true' : 'false'),
                'country' => $countryCode
            )
        );

        $curl = new Curl();
        $response = $curl->post($this->config['baseUrl'].'user/unlimitedStreaming/offline', $params);

        if (true == $response->offlineStatus->offlineEnabled) {
            return true;
        } else {
            throw new Exception('Can\'t subscribe the user for offline', MusicProviderException::INT_CREATE_ACCOUNT_ERROR);
        }
    }

    /**
     * @param array $params
     * @return mixed
     * @throws MusicProviderException
     */
    public function subscribeUser(array $params)
    {
        $date = new DateTime('now', new DateTimeZone('UTC'));
        $stringDate = $date->format('Y-m-d').'T'.$date->format('H:m:s').'Z';

        if (isset($params['status']) && 'expired' === $params['status']) {
            $expireDate = $date->sub(new DateInterval('P1D'));
        } else {
            $expireDate = $date->add(new DateInterval('P1M'));
        }

        $stringExpireDate = $expireDate->format('Y-m-d').'T'.$expireDate->format('H:m:s').'Z';
        $params = $this->oauth->signRequest('POST', 'user/unlimitedStreaming', 
            array_merge(
                array(
                    'currency' => 'USD',
                    'recurringFee' => 0,
                    'activatedAt' => $stringDate,
                    'currentPeriodStartDate' => $stringDate,
                    'expiryDate' => $stringExpireDate
                ),
                $params
            )
        );

        $curl = new Curl();
        $response = $curl->post($this->config['baseUrl'].'user/unlimitedStreaming', $params);

        if ($response->streaming) {
            return $response;
        } else {
            throw new MusicProviderException('Can\'t subscribe the user', MusicProviderException::INT_CREATE_ACCOUNT_ERROR);
        }
    }

    /**
     * getSubscription 
     * 
     * @param array $params params 
     * 
     * @return void
     */
    public function getSubscription(array $params)
    {
        $url = $this->oauth->signRequest('GET', 'user/unlimitedStreaming', $params);
        $curl = new Curl();
        $response = $curl->get($url);

        if (!is_string($response) && $response->streaming) {
            return $response;
        } else {
            return false;
        }
    }

    /**
     * getFeed 
     * 
     * @param mixed $type type 
     * @param mixed $filename filename 
     * @param mixed $params params 
     * @param mixed $full full 
     * 
     * @return void
     */
    public function getFeed($feedDir, $type, $filename, $params, $full = false)
    {
        $requestUrl = $this->getFeedUrl($type, $params, $full);

        if (!isset($feedDir)) {
            throw new Exception('config entry feedDir is not set.');
        } elseif (!is_writable($feedDir)) {
            throw new Exception('The provided feedDir is not writable');
        }

        $file = fopen($feedDir.'/'.$filename.'.gz', 'w+');

        $curl = new Curl();
        $curl->setOpt(CURLOPT_TIMEOUT, 600000);
        $curl->setOpt(CURLOPT_FILE, $file);
        $curl->setOpt(CURLOPT_FOLLOWLOCATION, true);
        $response = $curl->get($requestUrl);

        fclose($file);

        if ($curl->error) {
            throw new Exception($curl->errorMessage, $curl->errorCode);
        }

        return true;
    }

    /**
     * getFeedSize 
     * 
     * @param mixed $type type 
     * @param mixed $params params 
     * @param mixed $full full 
     * 
     * @return void
     */
    public function getFeedSize($type, $params, $full = false)
    {
        $requestUrl = $this->getFeedUrl($type, $params, $full = false);

        $curl = new Curl();
        $curl->setOpt(CURLOPT_NOBODY, true);
        $curl->setOpt(CURLOPT_HEADER, true);
        $curl->setOpt(CURLOPT_RETURNTRANSFER, true );
        $curl->setOpt(CURLOPT_FOLLOWLOCATION, true );
        $curl->setOpt(CURLOPT_NOBODY, true );
        $response = $curl->head($requestUrl);

        return $response;
    }

    /**
     * getFeedUrl 
     * 
     * @param mixed $type type 
     * @param mixed $params params 
     * @param mixed $full full 
     * 
     * @return void
     */
    private function getFeedUrl($type, $params, $full)
    {
        $this->config['baseUrl'] = 'http://feeds.api.7digital.com/1.2/feed/';
        $path = '';
        switch($type){
            case 'artist':
                $path = ($full === true) ? 'artist/full' : 'artist/updates';
                break;
            case "release":
                $path = ($full === true) ? 'release/full' : 'release/updates';
                break;
            case "track":
                $path = ($full === true) ? 'track/full' : 'track/updates';
                break;
        }

        $oauthFactory = new OauthFactory();
        $oauth = $oauthFactory->create(1.0, $this->config);
        $params['usagetypes']='subscriptionStreaming';
        $requestUrl = $oauth->signRequest('GET', $path, $params);
        return $requestUrl;
    }

    /**
     * setBaseUrl 
     * 
     * @param mixed $url url 
     * 
     * @return void
     */
    public function setBaseUrl($url)
    {
        $this->config['baseUrl'] = $url;
    }
}
