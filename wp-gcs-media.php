<?php
/**
 * @package WP GCS Media
 * @version 0.1
 */
/*
Plugin Name: WP GCS Media
Plugin URI: https://github.com/silverbackstudio/wp-gcs-media
Description: Use Google Cloud Storage for media uploads and Google Image Servers for resizing.
Author: Silverback Studio
Version: 0.1
Author URI: https://www.silverbackstudio.com/
*/


namespace Svbk\WP\Plugins\GCSMedia;

defined('ABSPATH') or die('No direct access!');

add_filter( 'image_downsize', __NAMESPACE__.'\\get_intermediate_url', 100, 3 );
add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
add_filter( 'delete_attachment', __NAMESPACE__.'\\delete_attachment_serving_image', 10, 1 );
add_action( 'admin_init', __NAMESPACE__.'\\settings_api_init' );

function settings_api_init() {

	 add_settings_section(
		'wp_gcs_media',
		'Google Cloud Storage',
		__NAMESPACE__.'\\setting_section_callback_function',
		'media'
	);

	 add_settings_field(
		'wp_gcs_media_service_url',
		'GCS Service URL',
		__NAMESPACE__.'\\setting_callback_function',
		'media',
		'wp_gcs_media'
	);

	 register_setting( 'media', 'wp_gcs_media_service_url' );
}


function setting_section_callback_function() {
	echo '<p>Integrates WP with Google Cloud Storage</p>';
}

function setting_callback_function() {
 	echo '<input name="wp_gcs_media_service_url" id="wp_gcs_media_service_url" type="text" value="'.esc_attr(get_option( 'wp_gcs_media_service_url' )).'" class="code" placeholder="image-dot-projectname.appspot.com" /> Eg. [service]-dot-[project].appspot.com';
}

function get_intermediate_url( $data, $id, $size ) {

	$baseurl = get_attachment_serving_url($id);

	if(!$baseurl) {
		remove_filter( 'image_downsize', __NAMESPACE__.'\\get_intermediate_url', 100 );
		$data = image_downsize( $id, $size );
		add_filter( 'image_downsize', __NAMESPACE__.'\\get_intermediate_url', 100, 3 );
		return $data;
	}

	$sizes = image_sizes();

	if ( is_array( $size ) ) {
		$sizeParams = ['width' => $size[0], 'height' => $size[1], 'crop' => false];
	}
	else {
		$sizeParams = $sizes[ $size ];
	}

	$metadata  = wp_get_attachment_metadata($id);
    list($width, $height) = wp_constrain_dimensions($metadata['width'], $metadata['height'], $sizeParams['width'], $sizeParams['height']);

    $intermediate = !(($width === $metadata['width']) && ($height === $metadata['height']));

	$url = resize_serving_url( $baseurl, $intermediate ? $sizeParams : [] );

	if($intermediate) {
		$width = $sizeParams['width'];
		$height = $sizeParams['height'];
	}

    return [$url, $width, $height, $intermediate];
}

function get_attachment_serving_url($id){

	$file = get_attached_file( $id );

	if ( !in_array(get_post_mime_type($id), ['image/jpeg', 'image/png', 'image/gif']) ) {
		return false;
	}

	$baseurl     = get_post_meta( $id, '_appengine_imageurl', true );
	//$cached_file = get_post_meta( $id, '_appengine_imageurl_file', true );

  $secure_urls =  true;

	if ( empty( $baseurl ) && get_option('wp_gcs_media_service_url') ) {

		$response_raw = wp_remote_request( get_image_service_url($file), array('method'=>'GET') );
		$response  = json_decode(wp_remote_retrieve_body($response_raw), true);

	    if(isset($response['serving_url'])){

			$baseurl = $response['serving_url'];
 	        update_post_meta( $id, '_appengine_imageurl', $baseurl );
	        update_post_meta( $id, '_appengine_imageurl_file', $file );

		} else {
			$baseurl = false;
		}

	}

	if ($secure_urls) {
		$baseurl = preg_replace("/^http:/i", "https:", $baseurl);
	}

	return $baseurl;
}

function delete_attachment_serving_image($attachment_id) {
	$file = get_attached_file( $attachment_id );

	wp_remote_request( get_image_service_url($file), array('method'=>'DELETE'));
}

function get_image_service_url($file){
    $upload_dir = wp_upload_dir();
    $filename = str_replace( trailingslashit($upload_dir['basedir']), '', $file );

    return trailingslashit(get_option('wp_gcs_media_service_url')).$filename;
}

function resize_serving_url($url, $p) {
	$defaults = array(
		'width'=>'',
		'height'=>'',
		'crop'=>'',
		'quality'=>'', //1-100
		'stretch'=>false
	);

	$p = array_merge($defaults, $p);

	$params = array();

	if($p['width']){
		$params[]= 'w'.$p['width'];
	} elseif($p['height']) {
		$params[]= 'h'.$p['height'];
	} else {
		$params[] = 's0';
	}

	if($p['crop']){
		$params[] = 'c';
	}

	return $url.'='.join('-', $params);
}

function image_sizes() {
	static $images_sizes = null;

	if (!empty($image_sizes) ) {
		return $image_sizes;
	}

	global $_wp_additional_image_sizes;

	// Populate an array matching the data structure of $_wp_additional_image_sizes so we have a consistent structure for image sizes
	$images = [
		'thumb' => [
			'width' => intval( get_option( 'thumbnail_size_w' ) ),
			'height' => intval( get_option( 'thumbnail_size_h' ) ),
			'crop' => (bool) get_option( 'thumbnail_crop' )
		],
		'medium' => [
			'width' => intval( get_option( 'medium_size_w' ) ),
			'height' => intval( get_option( 'medium_size_h' ) ),
			'crop' => false
		],
		'large' => [
			'width' => intval( get_option( 'large_size_w' ) ),
			'height' => intval( get_option( 'large_size_h' ) ),
			'crop' => false
		],
		'full' => [
			'width' => null,
			'height' => null,
			'crop' => false
		]
	];

	// Compatibility mapping as found in wp-includes/media.php
	$images['thumbnail'] = $images['thumb'];

	// Update class variable, merging in $_wp_additional_image_sizes if any are set
	if ( is_array( $_wp_additional_image_sizes ) && ! empty( $_wp_additional_image_sizes ) ) {
		$image_sizes = array_merge( $images, $_wp_additional_image_sizes );
	}
	else {
		$image_sizes = $images;
	}

	return is_array( $image_sizes ) ? $image_sizes : array();
}
