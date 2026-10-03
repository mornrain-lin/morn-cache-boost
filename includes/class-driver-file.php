<?php
/**
 * File 存储驱动。
 *
 * @package MornRain\MornCacheBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 把整页 HTML 存入 uploads 下的独立目录。
 */
class Morn_Cache_Boost_Driver_File {

	/**
	 * 目录名。
	 */
	const DIR_NAME = 'morn-cache-boost';

	/**
	 * 获取缓存根目录。
	 *
	 * @return string 绝对路径，失败返回空字符串。
	 */
	public static function get_base_dir() {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// 阻止目录列表。
		$index = trailingslashit( $dir ) . 'index.html';
		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $index, '' );
		}

		if ( ! file_exists( $htaccess ) ) {
			$rules = "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess, $rules );
		}

		return trailingslashit( $dir );
	}

	/**
	 * 把缓存键映射为安全的文件名与相对路径。
	 *
	 * 采用两级目录结构，避免单目录文件过多导致的性能问题。
	 *
	 * @param string $key 缓存键。
	 * @return array
	 */
	public static function get_file_path( $key ) {
		$dir = self::get_base_dir();

		if ( '' === $dir ) {
			return array(
				'path' => '',
				'full' => '',
			);
		}

		$hash = md5( $key );
		$sub  = substr( $hash, 0, 2 );

		return array(
			'path' => $sub . '/' . $hash . '.html',
			'full' => $dir . $sub . '/' . $hash . '.html',
		);
	}

	/**
	 * 读取缓存。
	 *
	 * @param string $key 缓存键。
	 * @return string 空字符串表示未命中。
	 */
	public static function get( $key ) {
		$file = self::get_file_path( $key );

		if ( '' === $file['full'] || ! file_exists( $file['full'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$content = @file_get_contents( $file['full'] );

		if ( false === $content || '' === $content ) {
			return '';
		}

		// 文件头前 32 字节为过期时间戳。
		$raw = substr( $content, 0, 32 );
		$ts  = (int) trim( $raw );

		if ( $ts <= 0 ) {
			return '';
		}

		if ( $ts < time() ) {
			self::delete( $key );

			return '';
		}

		return substr( $content, 32 );
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
		$file = self::get_file_path( $key );

		if ( '' === $file['full'] ) {
			return false;
		}

		$sub_dir = dirname( $file['full'] );

		if ( ! wp_mkdir_p( $sub_dir ) ) {
			return false;
		}

		$payload = sprintf( '%-32d', time() + (int) $ttl ) . $value;

		// 原子写入：先写临时文件再重命名，避免并发读到半截内容。
		$temp = $file['full'] . '.' . wp_generate_password( 6, false, false ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = @file_put_contents( $temp, $payload, LOCK_EX );

		if ( false === $written || 0 === $written ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@unlink( $temp );

			return false;
		}

		if ( ! @rename( $temp, $file['full'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@unlink( $temp );

			return false;
		}

		return true;
	}

	/**
	 * 删除单个缓存。
	 *
	 * @param string $key 缓存键。
	 * @return bool
	 */
	public static function delete( $key ) {
		$file = self::get_file_path( $key );

		if ( '' === $file['full'] || ! file_exists( $file['full'] ) ) {
			return false;
		}

		return (bool) wp_delete_file( $file['full'] );
	}

	/**
	 * 清空全部缓存。
	 *
	 * @return int 删除的文件数。
	 */
	public static function flush() {
		$dir = self::get_base_dir();

		if ( '' === $dir ) {
			return 0;
		}

		$deleted = 0;
		$entries = glob( $dir . '*', GLOB_ONLYDIR );

		if ( is_array( $entries ) ) {
			foreach ( $entries as $sub_dir ) {
				$files = glob( trailingslashit( $sub_dir ) . '*.html' );

				if ( ! is_array( $files ) ) {
					continue;
				}

				foreach ( $files as $file ) {
					if ( wp_delete_file( $file ) ) {
						$deleted++;
					}
				}
			}
		}

		// 清理可能残留的临时文件。
		$temps = glob( $dir . '*/*.tmp' );

		if ( is_array( $temps ) ) {
			foreach ( $temps as $temp ) {
				wp_delete_file( $temp );
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
		$dir = self::get_base_dir();

		if ( '' === $dir ) {
			return array(
				'count' => 0,
				'size'  => 0,
			);
		}

		$count     = 0;
		$size      = 0;
		$now       = time();
		$expired   = 0;
		$entries   = glob( $dir . '*', GLOB_ONLYDIR );

		foreach ( (array) $entries as $sub_dir ) {
			$files = glob( trailingslashit( $sub_dir ) . '*.html' );

			if ( ! is_array( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				$count++;
				$size += (int) filesize( $file );

				$handle = @fopen( $file, 'rb' );

				if ( $handle ) {
					$head = (string) fread( $handle, 32 );
					fclose( $handle );

					if ( (int) trim( $head ) < $now ) {
						$expired++;
					}
				}
			}
		}

		return array(
			'count'   => $count,
			'size'    => $size,
			'expired' => $expired,
		);
	}

	/**
	 * 清理已过期的缓存文件。
	 *
	 * @return int 删除数。
	 */
	public static function gc() {
		$dir = self::get_base_dir();

		if ( '' === $dir ) {
			return 0;
		}

		$deleted = 0;
		$now     = time();
		$entries = glob( $dir . '*', GLOB_ONLYDIR );

		foreach ( (array) $entries as $sub_dir ) {
			$files = glob( trailingslashit( $sub_dir ) . '*.html' );

			if ( ! is_array( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				$handle = @fopen( $file, 'rb' );

				if ( ! $handle ) {
					continue;
				}

				$head = (int) trim( (string) fread( $handle, 32 ) );
				fclose( $handle );

				if ( $head > 0 && $head < $now && wp_delete_file( $file ) ) {
					$deleted++;
				}
			}
		}

		return $deleted;
	}
}
