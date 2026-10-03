<?php
/**
 * 插件通用辅助函数。
 *
 * @package MornRain\MornCacheBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 读取设置并与默认值合并。
 *
 * @return array
 */
function morn_cache_boost_get_settings() {
	$defaults = array(
		'enabled'          => 1,
		'driver'           => 'file',
		'ttl'              => 3600,
		'separate_mobile'  => 1,
		'separate_logged'  => 1,
		'gzip'             => 0,
		'inline_css'       => 0,
		'inline_css_url'   => '',
		'cache_comment'    => 1,
		'exclude_logged'   => 1,
		'exclude_post'     => 1,
		'exclude_admin'    => 1,
		'exclude_cookies'  => 1,
		'exclude_urls'     => '',
		'exclude_ua'       => '',
		'exclude_query'    => 'replytocom,wp-login',
		'delete_on_logout' => 1,
	);

	$saved = get_option( MORN_CACHE_BOOST_OPTION, array() );

	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	/**
	 * 过滤缓存插件设置。
	 *
	 * @param array $settings 合并默认值后的设置。
	 */
	return apply_filters( 'morn_cache_boost_settings', array_merge( $defaults, $saved ) );
}

/**
 * 读取单个设置项。
 *
 * @param string $key     设置键名。
 * @param mixed  $default 默认值。
 * @return mixed
 */
function morn_cache_boost_get_setting( $key, $default = null ) {
	$settings = morn_cache_boost_get_settings();

	if ( ! array_key_exists( $key, $settings ) ) {
		return $default;
	}

	return $settings[ $key ];
}

/**
 * 判断当前请求是否来自移动设备。
 *
 * 不依赖任何外部库，使用常见 UA 关键字。
 *
 * @return bool
 */
function morn_cache_boost_is_mobile() {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';

	if ( '' === $ua ) {
		return false;
	}

	$needles = array(
		'android',
		'iphone',
		'ipad',
		'ipod',
		'windows phone',
		'blackberry',
		'opera mini',
		'opera mobi',
		'iemobile',
		'mobile safari',
		'webos',
		'kindle',
		'silk',
	);

	foreach ( $needles as $needle ) {
		if ( false !== strpos( $ua, $needle ) ) {
			/**
			 * 过滤移动设备判定结果。
			 *
			 * @param bool   $mobile 是否移动设备。
			 * @param string $ua     User-Agent 字符串。
			 */
			return (bool) apply_filters( 'morn_cache_boost_is_mobile', true, $ua );
		}
	}

	/**
	 * 过滤移动设备判定结果。
	 *
	 * @param bool   $mobile 是否移动设备。
	 * @param string $ua     User-Agent 字符串。
	 */
	return (bool) apply_filters( 'morn_cache_boost_is_mobile', false, $ua );
}

/**
 * 归一化查询参数。
 *
 * 参数排序后拼接，保证同一组参数产生相同缓存键。
 *
 * @param array $query 查询参数数组。
 * @return string
 */
function morn_cache_boost_normalize_query( $query ) {
	if ( ! is_array( $query ) || empty( $query ) ) {
		return '';
	}

	// 过滤掉值为空与控制字符的项。
	$clean = array();

	foreach ( $query as $key => $value ) {
		$key = strtolower( trim( (string) $key ) );

		if ( '' === $key ) {
			continue;
		}

		if ( is_array( $value ) ) {
			$value = implode( ',', array_map( 'strval', $value ) );
		}

		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value );

		if ( '' === $value ) {
			continue;
		}

		$clean[ $key ] = $value;
	}

	if ( empty( $clean ) ) {
		return '';
	}

	ksort( $clean );

	$pairs = array();

	foreach ( $clean as $key => $value ) {
		$pairs[] = $key . '=' . $value;
	}

	return implode( '&', $pairs );
}

/**
 * 获取当前请求 URL（不含协议与末尾斜杠归一化）。
 *
 * @return string
 */
function morn_cache_boost_current_url() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

	if ( '' === $uri ) {
		$uri = '/';
	}

	return $uri;
}

/**
 * 判断服务器是否支持 GZIP。
 *
 * @return bool
 */
function morn_cache_boost_gzip_supported() {
	if ( function_exists( 'zlib_version' ) === false ) {
		return false;
	}

	if ( ! function_exists( 'ob_get_level' ) || ! function_exists( 'ob_get_clean' ) ) {
		return false;
	}

	return true;
}

/**
 * 人类可读的字节数。
 *
 * @param int $bytes 字节数。
 * @return string
 */
function morn_cache_boost_format_bytes( $bytes ) {
	$bytes = (int) $bytes;

	if ( $bytes <= 0 ) {
		return '0 B';
	}

	$units = array( 'B', 'KB', 'MB', 'GB' );
	$index = min( (int) floor( log( $bytes, 1024 ) ), count( $units ) - 1 );

	return round( $bytes / pow( 1024, $index ), 2 ) . ' ' . $units[ $index ];
}
