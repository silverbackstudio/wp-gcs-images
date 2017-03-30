<?php

use google\appengine\api\cloud_storage\CloudStorageTools;

$bucket = getenv('GCS_BUCKET') ;

$path = ltrim($_SERVER['REQUEST_URI'], '/') ;

if(empty($path)) {
    exit;
}

$filepath = CloudStorageTools::getFilename($bucket, $path);
$cache_key = 'imgsrv_'.md5($filepath);

$memcache = new Memcached;

header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] == 'GET') {

    $serving_url = $memcache->get($cache_key);

    if(!$serving_url && file_exists($filepath)) {

        $serving_url = CloudStorageTools::getImageServingUrl($filepath, ['secure_url'=>true]);

        if($serving_url) {
            $memcache->set($cache_key, $serving_url, 0);
        }
    }

    echo json_encode( array( 'serving_url' => $serving_url ) );

} elseif($_SERVER['REQUEST_METHOD'] == 'DELETE') {

    $memcache->delete($cache_key);
    CloudStorageTools::deleteImageServingUrl($filepath);

    echo json_encode(array('success'=>true));
}
