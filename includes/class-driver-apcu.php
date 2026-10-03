<?php
/**
 * APCu 存储驱动。
 *
 * @package MornRain\MornCacheBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 使用 APCu 用户态缓存（若扩展可用）。
 */
class Morn_Cache_Boost_Driver_Apcu {

	/**
	 * 键前缀。
	 */
	const PREFIX = 'morn_cb_';

	/**
	 * 内存中记录的所有键，用于支持清空操作。
	 *
	 * @var array
	 */
	private static $registry = array();

	/**
	 * APCu 扩展是否可用。
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'apcu_fetch' ) && function_exists( 'apcu_store' ) && function_exists( 'apcu_delete' );
	}

	/**
	 * 读取缓存。
	 *
	 * @param string $key 缓存键。
	 * @return string
	 */
	public static function get( $key ) {
		if ( ! self::is_available() ) {
			return '';
		}

		$full = self::PREFIX . md5( $key );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$success = false;
		$value   = apcu_fetch( $full, $success );

		if ( ! $success || ! is_string( $value ) || '' === $value ) {
			return '';
		}

		return $value;
	}

	/**
	 * 写入缓存。
	 *
	 * @param string $key   缓存键。
	 * @param string $value 内容。
	 * @param int    $ttl   有效期（秒）。
	 * @return bool
	 */
	public static function set( $key, $value, $ttl ) {
		if ( ! self::is_available() ) {
			return false;
		}

		$ttl = max( 1, (int) $ttl );
		$full = self::PREFIX . md5( $key );

		$result = apcu_store( $full, $value, $ttl );

		if ( $result ) {
			self::$registry[ $full ] = true;
		}

		return (bool) $result;
	}

	/**
	 * 删除单个缓存。
	 *
	 * @param string $key 缓存键。
	 * @return bool
	 */
	public static function delete( $key ) {
		if ( ! self::is_available() ) {
			return false;
		}

		$full = self::PREFIX . md5( $key );

		unset( self::$registry[ $full ] );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return (bool) apcu_delete( $full );
	}

	/**
	 * 清空本插件在 APCu 中的所有键。
	 *
	 * APCu 没有前缀删除能力，只能遍历已知的键。
	 *
	 * @return int 删除数量。
	 */
	public static function flush() {
		if ( ! self::is_available() ) {
			return 0;
		}

		$deleted = 0;

		foreach ( array_keys( self::$registry ) as $full ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( apcu_delete( $full ) ) {
				$deleted++;
			}
		}

		self::$registry = array();

		/**
		 * APCu 无法按前缀批量删除，此处允许其它集成补充清理逻辑。
		 *
		 * @param int $deleted 已删除数量。
		 */
		return (int) apply_filters( 'morn_cache_boost_apcu_flushed', $deleted );
	}

	/**
	 * 统计缓存状态。
	 *
	 * APCu 无内置遍历 API，仅能统计本进程写入的键。
	 *
	 * @return array
	 */
	public static function stats() {
		$size = 0;

		if ( self::is_available() ) {
			foreach ( array_keys( self::$registry ) as $full ) {
				$success = false;
				$value   = apcu_fetch( $full, $success );

				if ( $success && is_string( $value ) ) {
					$size += strlen( $value );
				}
			}
		}

		return array(
			'count'   => count( self::$registry ),
			'size'    => $size,
			'expired' => 0,
		);
	}

	/**
	 * APCu 自动清理过期项，此处无需额外处理。
	 *
	 * @return int
	 */
	public static function gc() {
		return 0;
	}
}
