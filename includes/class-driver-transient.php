<?php
/**
 * Transient 存储驱动。
 *
 * @package MornRain\MornCacheBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 使用 WordPress transient 存储缓存。
 */
class Morn_Cache_Boost_Driver_Transient {

	/**
	 * Transient 前缀。
	 */
	const PREFIX = 'morn_cb_';

	/**
	 * 读取缓存。
	 *
	 * @param string $key 缓存键。
	 * @return string
	 */
	public static function get( $key ) {
		$value = get_transient( self::PREFIX . md5( $key ) );

		if ( ! is_string( $value ) || '' === $value ) {
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
		return (bool) set_transient( self::PREFIX . md5( $key ), $value, max( 1, (int) $ttl ) );
	}

	/**
	 * 删除单个缓存。
	 *
	 * @param string $key 缓存键。
	 * @return bool
	 */
	public static function delete( $key ) {
		return delete_transient( self::PREFIX . md5( $key ) );
	}

	/**
	 * 清空全部缓存。
	 *
	 * @return int 删除数量。
	 */
	public static function flush() {
		global $wpdb;

		$like    = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
		$timeout = $wpdb->esc_like( '_transient_timeout_' . self::PREFIX ) . '%';

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like,
				$timeout
			)
		);

		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$deleted = 0;

		foreach ( $rows as $option_name ) {
			if ( delete_option( $option_name ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * 统计缓存状态。
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;

		$like    = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
		$timeout = $wpdb->esc_like( '_transient_timeout_' . self::PREFIX ) . '%';

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		// 通过 timeout 判断过期数量。
		$now     = time();
		$expired = 0;
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$timeout
			)
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( (int) $row->option_value < $now ) {
					$expired++;
				}
			}
		}

		// 单条内容无法直接求和，按数量估算平均体积。
		$size = 0;

		if ( $count > 0 ) {
			$avg = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT AVG(LENGTH(option_value)) FROM {$wpdb->options} WHERE option_name LIKE %s",
					$like
				)
			);
			$size = (int) round( $count * (float) $avg );
		}

		return array(
			'count'   => $count,
			'size'    => $size,
			'expired' => $expired,
		);
	}

	/**
	 * Transient 由数据库自动清理过期项，此处无需额外处理。
	 *
	 * @return int
	 */
	public static function gc() {
		return 0;
	}
}
