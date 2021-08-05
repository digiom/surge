<?php
namespace Koddrio\Cache;

/**
 * Caching configuration settings.
 *
 * @param string $key Configuration key
 *
 * @return mixed The config value for the supplied key.
 */
function config( $key ) {
	return [
		'ttl' => 600,
		'ignore_cookies' => [ 'wordpress_test_cookie' ],
		'ignore_query_vars' => [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ],
	][ $key ];
}

/**
 * Generate a cache key array.
 *
 * @return array
 */
function key() {
	$cookies = [];
	$headers = [];

	// Clean up and normalize cookies.
	foreach ( $_COOKIE as $key => $value ) {
		if ( ! in_array( $key, config( 'ignore_cookies' ) ) ) {
			$cookies[ $key ] = $value;
		}
	}

	// Clean the URL/query vars
	$parsed = parse_url( 'http://example.org' . $_SERVER['REQUEST_URI'] );
	$path = $parsed['path'];

	parse_str( $parsed['query'], $query_vars );
	foreach ( $query_vars as $key => $value ) {
		if ( in_array( $key, config( 'ignore_query_vars' ) ) ) {
			unset( $query_vars[ $key ] );
		}
	}

	return [
		'https' => $_SERVER['HTTPS'],
		'method' => $_SERVER['REQUEST_METHOD'],
		'host' => strtolower( $_SERVER['HTTP_HOST'] ),

		'path' => $path,
		'query_vars' => $query_vars,
		'cookies' => $cookies,
		'headers' => $headers,
	];
}

/**
 * Get a cached item by key.
 *
 * @param array $key The array.
 *
 * @return bool|array The metadata array of a cached object or false if not found.
 */
function get( $key ) {
	$cache_key = md5( json_encode( $key ) );
	$level = substr( $cache_key, -2 );
	$cache_dir = WP_CONTENT_DIR . "/cache/koddrio/{$level}/";
	$meta_filename = $cache_dir . $cache_key . '.meta';

	if ( ! file_exists( $meta_filename ) ) {
		return false;
	}

	$meta = json_decode( file_get_contents( $meta_filename ), true );

	if ( ! $meta ) {
		return false;
	}

	$cache = $meta;
	$cache['filename'] = $cache_dir . $cache_key . '.html'; // TODO: not .html.
	return $cache;
}

/**
 * Store a cache item.
 *
 * @param array $key The request key.
 * @param mixed $value The cache item to store.
 *
 * @return bool True on success.
 */
function set( $key, $value ) {
	$contents = $value['contents'];
	unset( $value['contents'] );
	$meta = json_encode( $value );

	$cache_key = md5( json_encode( $key ) );
	$level = substr( $cache_key, -2 );
	$cache_dir = WP_CONTENT_DIR . "/cache/koddrio/{$level}/";

	if ( ! wp_mkdir_p( $cache_dir ) ) {
		return false;
	}

	// Open the meta file and acquire a lock.
	$f = fopen( $cache_dir . $cache_key . '.meta', 'w' );
	if ( ! flock( $f, LOCK_EX ) ) {
		fclose( $f );
		return false;
	}

	file_put_contents( $cache_dir . $cache_key . '.html', $contents, LOCK_EX );

	// Write the metadata and release the lock.
	fwrite( $f, $meta );
	fclose( $f );
	// flock( $f, LOCK_UN );
	return true;
}

function delete( $key ) {

}

/**
 * The main output buffer callback.
 *
 * @param string $contents The buffer contents.
 *
 * @return string Contents.
 */
function ob_callback( $contents ) {
	$key = key();

	// TODO: Check if request is cacheable.

	foreach ( headers_list() as $header ) {
		list( $name, $value ) = array_map( 'trim', explode( ':', $header, 2 ) );
		$headers[ $name ] = $value;
	}

	$cache = [
		'code' => http_response_code(),
		'headers' => $headers,

		'contents' => $contents,
		'created' => time(),
		'expires' => time() + config( 'ttl' ),

		// TODO: Add custom headers probably.
		// TODO: REMOVE!
		'key' => $key,
	];

	set( $key, $cache );

	header( 'X-Cache: miss' );
	return $contents;
}

/**
 * Serve a cached version of a request, if available.
 *
 * @return null
 */
function serve() {
	$key = key();
	$cache = get( $key );

	if ( ! $cache ) {
		return;
	}

	if ( $cache['expires'] < time() ) {
		header( 'X-Cache: expired' );
		return;
	}

	// Set the HTTP response code and send headers.
	http_response_code( $cache['code'] );

	foreach ( $cache['headers'] as $name => $value ) {
		header( "{$name}: {$value}" );
	}

	header( 'X-Cache: hit' );
	readfile( $cache['filename'] );
	die();
}

serve();
ob_start('Koddrio\Cache\ob_callback');
