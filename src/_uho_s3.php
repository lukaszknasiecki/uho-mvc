<?php

namespace Huncwot\UhoFramework;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\DynamoDb\DynamoDbClient;
use Aws\CloudFront\CloudFrontClient;
use Huncwot\UhoFramework\_uho_orm;

/**
 * This class provides S3 bucket interface
 * along with file/sessions caching of S3 buckets
 */

class _uho_s3
{
    /**
     * S3 access array
     */
    private $cfg = null;
    /**
     * S3Client object instance
     */
    private $s3Client = null;
    /**
     * cache uid
     */
    private $cache = [];
    /**
     * is mysql cache
     */
    private $cache_sql = false;
    private $orm;
    /**
     * currenat cache filename
     */
    private $cache_file = null;
    /**
     * compress
     */
    private $compress = null;
    /**
     * root data folder
     */
    private $folder = '';
    private $acl = true;

    /*
        non S3 path prefix to remove from S3 path
    */

    private $path_skip = '/public/upload/';
    private $cloudFrontClient = null;

    /**
     * Constructor
     * @param array $config
     * @return null
     */

    function __construct($config, $recache = false, $params = null)
    {
        
        $validate = [
            'region',
            'bucket',
            'key',
            'secret'
        ];

        foreach ($validate as $v)
            if (empty($config[$v])) return;


        $cfg = [
            'region' =>     $config['region'],
            'version' =>    'latest',
            'credentials' => [
                'key' =>    $config['key'],
                'secret' => $config['secret']
            ]
        ];

        if (!empty($config['compress'])) $this->setCompress($config['compress']);
        if (!empty($params['compress'])) $this->setCompress($params['compress']);

        if (!empty($config['cache_sql']))
        {
            $this->setCacheSql($config['cache_sql']);
            $this->setCompress(true);
        }

        if (!empty($config['folder']) && $config['folder'] != 'folder') $this->setFolder($config['folder']);
        if (!empty($params['orm'])) $this->orm = $params['orm'];
        if (!empty($config['acl']) && $config['acl'] == 'no') $this->acl = false;
        if (isset($config['cache'])) $this->cache_file = $config['cache'];
        if (isset($config['path_skip'])) $this->path_skip = rtrim($config['path_skip'], '/') . '/';

        $this->s3Client = new S3Client($cfg);
        $this->cfg = $config;

        if ($this->cache_sql)
        {

        } else
        {
            if (isset($_SESSION['_uho_s3_cache']) && !$recache) $this->cache = $_SESSION['_uho_s3_cache'];
            else {
                if (!$recache && $this->cache_file && $this->loadCache()) {
                } else {
                    $this->buildCache();
                }
                if (empty($_SESSION['_uho_s3_cache'])) $_SESSION['_uho_s3_cache'] = [];
                $this->cache = $_SESSION['_uho_s3_cache'];            
            }
        }
        
    }

    public function ready(): bool
    {
        return isset($this->s3Client);
    }

    /**
     * Loads Bucket contents and saves it to cache
     *
     * @return int[]
     *
     * @psalm-return array{count: int<0, max>, skipped: int<0, max>}
     */
    public function buildCache($params = []): array
    {
        
        $this->cacheClearAll();
        $c = 0;

        try {
            $results = $this->s3Client->getPaginator('ListObjects', [
                'Bucket' => $this->cfg['bucket'],
                'Prefix' => $this->folder
            ]);
        } catch (AwsException $e) {
            exit('_uho_s3:: Access Denied :: ' . $e->getMessage());
        }

        $skip = 0;

        foreach ($results as $objects)
            if (!empty($objects['Contents']))
                foreach ($objects['Contents']  as $object) {
                    if (@$params['skip_original'] && strpos($object['Key'], '/original/')) $object['Key'] = '';
                    if ($object['Key']) {
                        $c++;
                        $time = str_replace('+00:00', '', $object['LastModified']->__toString());
                        $this->cacheSet(
                            $object['Key'],
                            ['time' => $time],
                            false
                        );
                    } else $skip++;
                }

        $this->saveCache();
        return ['count' => $c, 'skipped' => $skip];
    }

    /**
     * Gets full bucket from cache
     * @param boolean $refresh
     * @return array
     */

    public function getCache($refresh = false, $offset = 0, $limit = 0)
    {
        if ($refresh) $this->buildCache();
        if ($offset || $limit) return array_slice($this->cache, $offset, $limit);
        return $this->cache;
    }

    /**
     * Determines bucket's region
     * @return string
     */
    private function getRegion()
    {
        return $this->s3Client->determineBucketRegion($this->cfg['bucket']);
    }

    /**
     * Returns current S3 Host
     * @return string
     */
    public function getHost(bool $slash = false)
    {
        $result = $this->cfg['host'];
        if ($this->folder) $result .= '/' . $this->folder;
        if ($slash) $result .= '/';
        return $result;
    }

    /**
     * Classsic file_exists functin but using S3 cache
     *
     * @param array $filename
     *
     * @return null|true
     */
    public function file_exists($filename)
    {
        $d = $this->getFileMetadata($filename);
        if (isset($d)) return true; else return false;
    }

    /**
     * Clears filename from root path
     *
     * @param array $filename
     *
     * @return string
     *
     * @psalm-return array<string>
     */
    private function clear_filename($filename): string
    {
        $root = $_SERVER['DOCUMENT_ROOT'] . $this->path_skip;
        $root = str_replace('//', '/', $root);
        $filename = str_replace($root, '', $filename);
        $filename = str_replace($this->cfg['host'] . '/', '', $filename);
        if ($this->path_skip) $filename = str_replace($this->path_skip, '', $filename);

        return $filename;
    }

    /**
     * Sets cache for one bucket item
     *
     * @param string $filename
     * @param $value
     * @param boolean $save
     *
     */
    private function cacheSet($filename, array|false $value, $save = true, bool $compress = true): void
    {
        if ($this->folder) $filename = str_replace($this->folder . '/', '', $filename);
        if ($filename) {
            if ($compress) $this->compressObject($filename, $value);
            $this->cache[$filename] = $_SESSION['_uho_s3_cache'][$filename] = $value;
            if ($save) $this->saveCache();
        }
    }

    private function cacheGet(string $filename)
    {

        $result = null;

        if ($this->path_skip) $filename = str_replace($this->path_skip, '', $filename);

        switch ($this->compress) {
            case "md5":
                $filename = md5($filename);
                break;
        }

        if ($this->cache_sql && $this->orm)
        {
            $c=$this->orm->get('uho_image_cache', ['id'=>$filename],true);
            if ($c)
            {
                return $c;
            }
            return null;
        } 

        elseif (isset($this->cache[$filename])) {

            switch ($this->compress) {
                case "md5":
                    $result = $this->cache[$filename];
                    if (empty($result['time']))
                        $result = ['time' => $result];
                    break;
                default:
                    $result = $this->cache[$filename];
                    break;
            }
        }
        return $result;
    }

    private function compressObject(string &$key, &$value): void
    {
        switch ($this->compress) {
            case "md5":
                $key = md5($key);
                if (!empty($value['time'])) {
                    $value = $value['time'];
                    $value = str_replace('-', '', $value);
                    $value = str_replace('T', '', $value);
                    $value = str_replace(':', '', $value);
                }
                break;
        }
    }

    /**
     * Clears cache for one bucket item
     *
     * @param array $filename
     */
    private function cacheClear($filename): void
    {
        unset($this->cache[$filename]);
        unset($_SESSION['_uho_s3_cache'][$filename]);
        $this->saveCache();
    }

    /**
     * Clears full cache
     */
    private function cacheClearAll(): void
    {
        unset($_SESSION['_uho_s3_cache']);
        unset($this->cache);
    }

    /**
     * Returns bucket object metadata
     * @param string $filename
     * @param boolean $force
     * @return array
     */

    public function getFileMetadata($filename, $force = false, $save = false)
    {

        $filename = $this->clear_filename($filename);        
        $result_simple = null;

        $cached = $this->cacheGet($filename);

        if ($cached && !$force) {
            $result = $cached;
            if (!$result) $result = null;
            return $result;
        } elseif (!$force) return null;

        try {
            $result = $this->s3Client->getObject([
                'Bucket' => $this->cfg['bucket'],
                'Key' => $filename
            ]);
            $result = $result->toArray();
            $result_simple = ['time' => $result['LastModified']->__toString()];
        } catch (AwsException $e) {

            $result = null;
        }

        if ($result_simple === null) $result_cache = false;
        else $result_cache = $result_simple;
        $this->cacheSet($filename, $result_cache, $save);

        return $result;
    }

    /**
     * S3 bucket version of file_time function
     * @param array $filename
     * @return string
     */

    public function file_time($filename)
    {
        $result = $this->getFileMetadata($filename);
        $time = '';

        if (isset($result) && isset($result['time'])) {
            $time = md5(@$result['time']);
        }
        return $time;
    }

    /**
     * Returns filename with host string
     * @param string $f
     * @return string
     */

    public function getFilenameWithHost($f)
    {
        $s = $this->getHost(true) . $this->clear_filename($f);
        return $s;
    }

    /**
     * Copies file using S3 bucket
     *
     * @param string $source
     * @param string $destination
     */
    public function copy($source, $destination, $download = false, $length = 0): void
    {
        if (!$source || !$destination) return;
        $destination = $this->clear_filename($destination);
        
        $this->cacheClear($destination);

        $destination = $this->createS3Key($destination);

        try {
            $object = [
                'Bucket' => $this->cfg['bucket'],
                'Key' => $destination,
                'SourceFile' => $source,
                'ContentDisposition' => "attachment"
            ];
            if ($length) $object['ContentLength'] = $length;
            if ($this->acl) $object['ACL'] = 'public-read';

            $ext=explode('.',$destination); $ext=array_pop($ext);

            switch ($ext)
            {
                case "jpg":
                case "jpeg":
                    $object['ContentType']= 'image/jpeg';
                    $object['ContentDisposition'] = "inline";
                    $object['CacheControl'] = "max-age=31536000, public";
                    break;
                case "webp":
                    $object['ContentType']= 'image/webp';
                    $object['ContentDisposition'] = "inline";
                    $object['CacheControl'] = "max-age=31536000, public";
                    break;
                case "png":
                    $object['ContentType']= 'image/png';
                    $object['ContentDisposition'] = "inline";
                    $object['CacheControl'] = "max-age=31536000, public";
                    break;
            }

            $result = $this->s3Client->putObject($object);            
            $result = $result->toArray();

            $result = $result['@metadata'];
            if ($result && $result['statusCode'] == 200)
            {
                $this->cacheSet($destination, ['time' => md5($result['headers']['date'])], true);
            }
        } catch (AwsException $e) {

            exit('[AWS COPY ERROR][' . $e->getAwsErrorCode() . ']');
        }
    }

    /**
     * Create file using S3 bucket
     *
     * @param string $soruce
     * @param string $destination
     */
    public function create($data, $destination): void
    {
        if (!$data || !$destination) return;
        $destination = $this->clear_filename($destination);
        $this->cacheClear($destination);

        $destination = $this->createS3Key($destination);

        try {
            $object = [
                'Bucket' => $this->cfg['bucket'],
                'Key' => $destination,
                'Body' => $data,
                'ContentDisposition' => "attachment"
            ];
            $object['ContentLength'] = strlen($data);
            if ($this->acl) $object['ACL'] = 'public-read';

            $result = $this->s3Client->putObject($object);
            $result = $result->toArray();

            $result = $result['@metadata'];
            if ($result && $result['statusCode'] == 200)
            {
                $this->cacheSet($destination, ['time' => md5($result['headers']['date'])], true);
            }
        } catch (AwsException $e) {

            exit('[AWS COPY ERROR][' . $e->getAwsErrorCode() . ']');
        }
    }

    /**
     * Unlink function for S3
     *
     * @param string $filename
     */
    public function unlink($filename): bool
    {
        $filename = $this->clear_filename($filename);
        $key = $this->createS3Key($filename);

        $result = false;
        if ($filename)
            try {
                $result = $this->s3Client->deleteObject([
                    'Bucket' => $this->cfg['bucket'],
                    'Key' => $key
                ]);

                $this->cacheClear($filename);
                $result = true;
            } catch (AwsException $e) {
                //exit('error');
                // $e->getAwsErrorCode()
            }
        return $result;
    }

    /**
     * Return permissions for current bucket, debug use only
     */
    private function permissionsGet(): void
    {
        try {
            $resp = $this->s3Client->getBucketAcl([
                'Bucket' => $this->cfg['bucket']
            ]);
            echo "Succeed in retrieving bucket ACL as follows: \n";
            var_dump($resp);
        } catch (AwsException $e) {
            // output error message if fails
            echo $e->getMessage();
            echo "\n";
        }
    }

    /**
     * List all bucket files, debug only
     * @return boolean
     */

    public function listFiles($prefix = null)
    {
        $list = ['Bucket' => $this->cfg['bucket']];
        if ($prefix) $list['Prefix'] = $prefix;
        $objects = $this->s3Client->listObjects($list); //, 'MaxKeys' => 1000, 'Prefix' => 'files/'.$value));
        return $objects;
    }

    public function listFilesPaginator($prefix = ''): array
    {
        $objects = $this->s3Client->getPaginator('ListObjects', ['Bucket' => $this->cfg['bucket']]);
        $items = [];
        foreach ($objects as $listResponse)
            $items = array_merge($items, $listResponse->search("Contents[?starts_with(Key,'" . $prefix . "')]"));
        return $items;
    }


    /**
     * Set compress
     * @return boolean
     */

    public function setCompress($type)
    {
        if (in_array($type, ['', 'md5'])) {
            $this->compress = $type;
            return true;
        } else return false;
    }

    /**
     * Get compress
     * @return boolean
     */

    public function getCompress()
    {
        return $this->compress;
    }

    public function getPathSkip()
    {
        return $this->path_skip;
    }


    /**
     * Check if cache file exists
     * @return boolean
     */

    public function checkCacheFile()
    {
        if ($this->cache_file) return @file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . $this->cache_file);
        else return false;
    }
    /**
     * Saves current cache to file
     * @return boolean
     */

    public function saveCache()
    {
        $result = false;
        
         $_SESSION['_uho_s3_cache'] = $this->cache;
        if ($this->cache_file) {
            $f = fopen($_SERVER['DOCUMENT_ROOT'] . '/' . $this->cache_file, 'w');
            if ($f) {
                if (empty($this->cache)) $this->cache = [];
                fputs($f, json_encode($this->cache));
                fclose($f);
                $result = true;
            }
        }

        if ($this->cache_sql && $this->orm) {
            /*
            $c = [];
                
            foreach ($this->cache as $k => $v)
                if (isset($v['time'])) {
                    $c[] = ['id' => md5($k), 'time' => str_replace('T',' ',$v['time'])];
                }

            if ($this->orm->truncateModel('uho_image_cache'))
                $this->orm->postJsonModel('uho_image_cache', $c, true);
            */
        }


        return $result;
    }

    /**
     * Loads cache from file
     *
     * @return bool|null
     */
    public function loadCache()
    {
        if ($this->cache_file) {

            $f = @fopen($_SERVER['DOCUMENT_ROOT'] . '/' . $this->cache_file, 'r');
            if ($f) {
                $s = fgets($f);
                if ($s) $s = json_decode($s, true);
                fclose($f);
                if ($s) {
                    $this->cacheClearAll();
                    foreach ($s as $k => $v)
                    {
                        //if ($this->compress=='md5') $v=['time'=>$v];
                        if (is_string($v)) $v=['time'=>$v];
                        //    else $v=['time' => $v['time']];
                        $this->cacheSet($k, $v, false, false);
                    }

                    return true;
                }
            } else return false;
        } else return false;
    }

    /**
     * @psalm-return int<0, max>
     */
    public function getCachedItemsCount(): int
    {
        if (isset($this->cache)) return count($this->cache);
        else return 0;
    }

    /*
    public function invalidateObject($destination)
    {
        if (!isset($this->cfg['cloudfront_id'])) return false;

        if (!$this->cloudFrontClient)
        {
            $this->cloudFrontClient = new Aws\CloudFront\CloudFrontClient([
                'version' => '2018-06-18',
                'region' => $this->cfg['region'],
                'credentials' => [
                    'key' =>    $this->cfg['key'],
                    'secret' => $this->cfg['secret']
                    ]    
            ]);

        }

        if (!is_array($destination))
        {
            $destination=[$destination];
        }


//        foreach ($destination as $k=>$v)
  //          $destination[$k]=$this->clear_filename($v);

            print_r($destination);

        try {
            $result = $this->cloudFrontClient->createInvalidation([
                'DistributionId' => $this->cfg['cloudfront_id'],
                'InvalidationBatch' => [
                    'CallerReference' => uniqid().uniqid(),
                    'Paths' => [
                        'Items' => $destination,
                        'Quantity' => count($destination)
                    ],
                ]
            ]);

            //print_r($result);
            //exit('ok!');
            return true;
    
            /*
            $message = '';
    
            if (isset($result['Location']))
            {
                $message = 'The invalidation location is: ' . 
                    $result['Location'];
            }
    
            $message .= ' and the effective URI is ' . 
                $result['@metadata']['effectiveUri'] . '.';

            return true;
    
            
        } catch (AwsException $e) {
            //exit('<b>Error:</b> ' . $e->getAwsErrorMessage());
            return false;
        }        

        
    }*/

    private function createCloudFrontClient(): void
    {
        $cfg = [
            'region' =>     $this->cfg['region'],
            'version' =>    '2018-06-18',
            'credentials' => [
                'key' =>    $this->cfg['key'],
                'secret' => $this->cfg['secret']
            ]
        ];

        $this->cloudFrontClient = new \Aws\CloudFront\CloudFrontClient($cfg);
    }

    /**
     * @return (bool|string)[]
     *
     * @psalm-return array{result: bool, message?: string}
     */
    public function cloudfrontInvalidate($distributionId, $urls): array
    {
        if (!$this->cloudFrontClient) $this->createCloudFrontClient();
        $uid = 'hbo' . date('YmdHis');
        try {
            $data = [
                'DistributionId' => $distributionId,
                'InvalidationBatch' => [
                    'CallerReference' => $uid,
                    'Paths' => [
                        'Items' => $urls,
                        'Quantity' => count($urls)
                    ],
                ]
            ];
            $this->cloudFrontClient->createInvalidation($data);
            return ['result' => true];
        } catch (\Exception $exception) {
            return ['result' => false, 'message' => $exception->getMessage()];
        }
    }

    public function setCacheSql($value): void
    {
        $this->cache_sql = $value;
    }

    public function setFolder($value): void
    {
        $this->folder = $value;
    }

    private function createS3Key(string $destination)
    {
        if ($this->folder) $destination = $this->folder . '/' . $destination;
        return $destination;
    }
}
