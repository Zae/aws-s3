<?php
/**
 * @link      http://buildwithcraft.com/
 * @copyright Copyright (c) 2015 Pixel & Tonic, Inc.
 * @license   http://buildwithcraft.com/license
 */
namespace craft\awss3;

use Aws\CloudFront\CloudFrontClient;
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\S3\Exception\S3Exception;
use Craft;
use craft\base\Volume as BaseVolume;
use craft\cache\adapters\GuzzleCacheAdapter;
use craft\dates\DateTime;
use craft\errors\VolumeException;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use \League\Flysystem\AwsS3v3\AwsS3Adapter;
use \Aws\S3\S3Client as S3Client;


/**
 * Class Volume
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Volume extends BaseVolume
{
    // Constants
    // =========================================================================

    const STORAGE_STANDARD = "STANDARD";
    const STORAGE_REDUCED_REDUNDANCY = "REDUCED_REDUNDANCY";
    const STORAGE_STANDARD_IA = "STANDARD_IA";

    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName()
    {
        return Craft::t('app', 'Amazon S3');
    }

    // Properties
    // =========================================================================

    /**
     * Whether this is a local source or not. Defaults to false.
     *
     * @var bool
     */
    protected $isVolumeLocal = false;

    /**
     * Subfolder to use
     *
     * @var string
     */
    public $subfolder = "";

    /**
     * AWS key ID
     *
     * @var string
     */
    public $keyId = "";

    /**
     * AWS key secret
     *
     * @var string
     */
    public $secret = "";

    /**
     * Bucket to use
     *
     * @var string
     */
    public $bucket = "";

    /**
     * Region to use
     *
     * @var string
     */
    public $region = "";

    /**
     * Cache expiration period.
     *
     * @var string
     */
    public $expires = "";

    /**
     * S3 storage class to use.
     *
     * @var string
     */
    public $storageClass = "";

    /**
     * CloudFront Distribution ID
     */
    public $cfDistributionId;

    /**
     * Cache adapter
     *
     * @var GuzzleCacheAdapter
     */
    private static $_cacheAdapter = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function rules()
    {
        $rules = parent::rules();
        $rules[] = [['bucket', 'region'], 'required'];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('awss3/volumeSettings',
            [
                'volume' => $this,
                'periods' => array_merge(['' => ''], Assets::periodList()),
                'storageClasses' => static::storageClasses(),
            ]);
    }

    /**
     * Get the bucket list using the specified credentials.
     *
     * @param $keyId
     * @param $secret
     *
     * @throws \InvalidArgumentException
     * @return array
     */
    public static function loadBucketList($keyId, $secret)
    {
        // Any region will do.
        $config = static::_buildConfigArray($keyId, $secret, 'us-east-1');

        $client = static::client($config);

        $objects = $client->listBuckets();

        if (empty($objects['Buckets'])) {
            return [];
        }

        $buckets = $objects['Buckets'];
        $bucketList = [];

        foreach ($buckets as $bucket) {
            try {
                $location = $client->getBucketLocation(['Bucket' => $bucket['Name']]);
            } catch (S3Exception $exception) {
                continue;
            }

            $bucketList[] = [
                'bucket' => $bucket['Name'],
                'urlPrefix' => 'http://'.$bucket['Name'].'.s3.amazonaws.com/',
                'region' => isset($location['Location']) ? $location['Location'] : ''
            ];
        }

        return $bucketList;
    }

    /**
     * @inheritdoc
     */
    public function getRootPath()
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function getRootUrl()
    {
        return rtrim(rtrim($this->url, '/').'/'.$this->subfolder, '/').'/';
    }

    /**
     * Return a list of available storage classes.
     *
     * @return array
     */
    public static function storageClasses()
    {
        return [
            static::STORAGE_STANDARD => 'Standard',
            static::STORAGE_REDUCED_REDUNDANCY => 'Reduced Redundancy Storage',
            static::STORAGE_STANDARD_IA => 'Infrequent Access Storage'
        ];
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return AwsS3Adapter
     */
    protected function createAdapter()
    {
        $config = $this->_getConfigArray();

        $client = static::client($config);

        return new AwsS3Adapter($client, $this->bucket, $this->subfolder);
    }

    /**
     * Get the AWS S3 client.
     *
     * @param $config
     *
     * @return S3Client
     */
    protected static function client($config = [])
    {
        return S3Client::factory($config);
    }

    /**
     * @inheritdoc
     */
    protected function addFileMetadataToConfig($config)
    {
        if (!empty($this->expires) && DateTimeHelper::isValidIntervalString($this->expires)) {
            $expires = new DateTime();
            $now = new DateTime();
            $expires->modify('+'.$this->expires);
            $diff = $expires->format('U') - $now->format('U');
            $config['CacheControl'] = 'max-age='.$diff.', must-revalidate';
        }

        if (!empty($this->storageClass)) {
            $config['StorageClass'] = $this->storageClass;
        }

        return parent::addFileMetadataToConfig($config);
    }

    /**
     * @inheritdoc
     */
    protected function invalidateCdnPath($path)
    {
        if (!empty($this->cfDistributionId)) {
            // If there's a CloudFront distribution ID set, invalidate the path.
            $cfClient = $this->_getCloudFrontClient();

            try {
                $cfClient->createInvalidation(
                    [
                        'DistributionId' => $this->cfDistributionId,
                        'InvalidationBatch' => [
                            'Paths' =>
                                [
                                    'Quantity' => 1,
                                    'Items' => ['/'.ltrim($path, '/')]
                                ],
                            'CallerReference' => 'Craft-'.StringHelper::randomString(24)
                        ]
                    ]
                );
            } catch (CloudFrontException $exception) {
                Craft::warning($exception->getMessage());
                throw new VolumeException('Failed to invalidate the CDN path for '.$path);
            }
        }

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Get a CloudFront client.
     *
     * @return CloudFrontClient
     */
    private function _getCloudFrontClient()
    {
        $config = $this->_getConfigArray();

        return CloudFrontClient::factory($config);
    }

    /**
     * Get the config array for AWS Clients.
     *
     * @return array
     */
    private function _getConfigArray()
    {
        $keyId = $this->keyId;
        $secret = $this->secret;
        $region = $this->region;

        return static::_buildConfigArray($keyId, $secret, $region);
    }

    /**
     * Build the config array based on a keyID and secret
     *
     * @param $keyId
     * @param $secret
     *
     * @return array
     */
    private static function _buildConfigArray($keyId = null, $secret = null, $region = null)
    {
        if (empty($keyId) || empty($secret)) {
            $config = [];
        } else {
            // TODO Add support for different credential supply methods
            // And look into v4 signature token caching.
            $config = [
                'credentials' => [
                    'key' => $keyId,
                    'secret' => $secret
                ]
            ];
        }

        $config['region'] = $region;
        $config['version'] = 'latest';

        return $config;
    }
}