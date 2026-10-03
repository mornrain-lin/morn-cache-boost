<?php
/**
 * 缓存读写协调层。
 *
 * @package MornRain\MornCacheBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 统一封装三种存储驱动的读写、统计与清理。
 */
class Morn_Cache_Boost_Cache {

	/**
	 * 当前驱动实例。
	 *
	 * @var object|null
	 */
	private static $driver = null;

	/**
	 * 缓存键前缀。
	 */
	const KEY_PREFIX = 'v1:';

	/**
	 * 获取当前存储驱动。
	 *
	 * @return object
	 */
	public static function get_driver() {
		if ( null !== self::$driver ) {
			return self::$driver;
		}

		$name     = (string) morn_cache_boost_get_setting( 'driver', 'file' );
		$settings = array(
			'file'      => 'Morn_Cache_Boost_Driver_File',
			'transient' => 'Morn_Cache_Boost_Driver_Transient',
			'apcu'      => 'Morn_Cache_Boost_Driver_Apcu',
		);

		$class = isset( $settings[ $name ] ) ? $settings[ $name ] : 'Morn_Cache_Boost_Driver_File';

		/**
		 * 过滤使用的存储驱动类名。
		 *
		 * @param string $class  类名。
		 * @param string $name   配置中的驱动名。
		 */
		$class = apply_filters( 'morn_cache_boost_driver_class', $class, $name );

		if ( ! class_exists( $class ) || ! is_callable( array( $class, 'get' ) ) ) {
			$class = 'Morn_Cache_Boost_Driver_File';
		}

		// APCu 不可用时回退到 File。
		if ( 'Morn_Cache_Boost_Driver_Apcu' === $class && ! Morn_Cache_Boost_Driver_Apcu::is_available() ) {
			$class = 'Morn_Cache_Boost_Driver_File';
		}

		self::$driver = new $class();

		return self::$driver;
	}

	/**
	 * 缓存键前缀中的版本号。
	 *
	 * 每次失效时递增，旧键自然失效。
	 *
	 * @return string
	 */
	public static function get_key_version() {
		return (string) (int) get_option( 'morn_cache_boost_version', 1 );
	}

	/**
	 * 计算当前请求的缓存键。
	 *
	 * 构成：版本 + 路径 + 设备类型 + 登录态 + 归一化查询参数
	 *
	 * @return string
	 */
	public static function build_key() {
		$uri  = morn_cache_boost_current_url();
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? $path : '/';

		$parts = array( self::KEY_PREFIX . self::get_key_version() . '|' . $path );

		// 移动端与桌面端分离。
		if ( morn_cache_boost_get_setting( 'separate_mobile', 1 ) ) {
			$parts[] = morn_cache_boost_is_mobile() ? 'm' : 'd';
		}

		// 登录态：已缓存登录用户页面时区分用户，避免串号。
		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			$parts[] = 'u' . ( $user_id > 0 ? $user_id : 0 );

			$parts[] = 'r' . ( current_user_can( 'edit_posts' ) ? '1' : '0' );
		}

		$query = array();

		if ( isset( $_GET ) && is_array( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 读取查询串用于生成缓存键，不做权限判断。
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query = wp_unslash( $_GET );
		}

		$normalized = morn_cache_boost_normalize_query( $query );

		if ( '' !== $normalized ) {
			$parts[] = $normalized;
		}

		$key = implode( '|', $parts );

		/**
		 * 过滤生成的缓存键。
		 *
		 * @param string $key 缓存键。
		 * @param string $uri  当前请求 URI。
		 */
		return apply_filters( 'morn_cache_boost_cache_key', $key, $uri );
	}

	/**
	 * 读取缓存。
	 *
	 * @param string $key 缓存键。
	 * @return string
	 */
	public static function get( $key ) {
		$value = self::get_driver()->get( $key );

		/**
		 * 过滤读取到的缓存内容。
		 *
		 * @param string $value 缓存内容。
		 * @param string $key   缓存键。
		 */
		return apply_filters( 'morn_cache_boost_get', $value, $key );
	}

	/**
	 * 写入缓存。
	 *
	 * @param string $key   缓存键。
	 * @param string $value 内容。
	 * @return bool
	 */
	public static function set( $key, $value ) {
		$ttl   = max( 60, (int) morn_cache_boost_get_setting( 'ttl', 3600 ) );
		$result = self::get_driver()->set( $key, $value, $ttl );

		/**
		 * 缓存写入结果。
		 *
		 * @param bool   $result 是否成功。
		 * @param string $key    缓存键。
		 * @param string $value  缓存内容。
		 */
		do_action( 'morn_cache_boost_set', $result, $key, $value );

		return (bool) $result;
	}

	/**
	 * 删除单个缓存。
	 *
	 * @param string $key 缓存键。
	 * @return bool
	 */
	public static function delete( $key ) {
		return (bool) self::get_driver()->delete( $key );
	}

	/**
	 * 清空全部缓存。
	 *
	 * @return int
	 */
	public static function flush() {
		$deleted = self::get_driver()->flush();

		/**
		 * 清空缓存完成后的动作。
		 *
		 * @param int $deleted 删除数量。
		 */
		do_action( 'morn_cache_boost_flushed', (int) $deleted );

		return (int) $deleted;
	}

	/**
	 * 清理过期缓存。
	 *
	 * @return int
	 */
	public static function gc() {
		$driver = self::get_driver();

		if ( ! is_callable( array( $driver, 'gc' ) ) ) {
			return 0;
		}

		return (int) $driver->gc();
	}

	/**
	 * 获取缓存统计。
	 *
	 * @return array
	 */
	public static function get_stats() {
		$driver = self::get_driver();

		if ( ! is_callable( array( $driver, 'stats' ) ) ) {
			return array(
				'count'   => 0,
				'size'    => 0,
				'expired' => 0,
			);
		}

		$stats = $driver->stats();

		return array(
			'count'   => isset( $stats['count'] ) ? (int) $stats['count'] : 0,
			'size'    => isset( $stats['size'] ) ? (int) $stats['size'] : 0,
			'expired' => isset( $stats['expired'] ) ? (int) $stats['expired'] : 0,
		);
	}

	/**
	 * 获取当前驱动名称。
	 *
	 * @return string
	 */
	public static function get_driver_name() {
		$driver = self::get_driver();

		if ( $driver instanceof Morn_Cache_Boost_Driver_File ) {
			return 'file';
		}

		if ( $driver instanceof Morn_Cache_Boost_Driver_Transient ) {
			return 'transient';
		}

		if ( $driver instanceof Morn_Cache_Boost_Driver_Apcu ) {
			return 'apcu';
		}

		return 'unknown';
	}
}
