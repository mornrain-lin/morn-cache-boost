<?php
/**
 * 卸载清理脚本。
 *
 * 仅删除本插件自身创建的选项、定时任务与缓存文件。
 * 不删除任何文章、媒体附件、用户数据或主题文件。
 *
 * @package MornRain\MornCacheBoost
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 插件设置与统计。
delete_option( 'morn_cache_boost_settings' );
delete_option( 'morn_cache_boost_stats' );
delete_option( 'morn_cache_boost_version' );
delete_option( 'morn_cache_boost_activated_at' );

// 垃圾回收定时任务。
$morn_cb_gc = wp_next_scheduled( 'morn_cache_boost_gc' );

if ( $morn_cb_gc ) {
	wp_unschedule_event( $morn_cb_gc, 'morn_cache_boost_gc' );
}

wp_clear_scheduled_hook( 'morn_cache_boost_gc' );

// File 驱动缓存目录。
$morn_cb_uploads = wp_get_upload_dir();

if ( empty( $morn_cb_uploads['error'] ) && ! empty( $morn_cb_uploads['basedir'] ) ) {
	$morn_cb_dir = trailingslashit( $morn_cb_uploads['basedir'] ) . 'morn-cache-boost';

	if ( is_dir( $morn_cb_dir ) ) {
		$morn_cb_entries = glob( trailingslashit( $morn_cb_dir ) . '*', GLOB_ONLYDIR );

		if ( is_array( $morn_cb_entries ) ) {
			foreach ( $morn_cb_entries as $morn_cb_sub ) {
				$morn_cb_files = glob( trailingslashit( $morn_cb_sub ) . '*' );

				if ( ! is_array( $morn_cb_files ) ) {
					continue;
				}

				foreach ( $morn_cb_files as $morn_cb_file ) {
					if ( is_file( $morn_cb_file ) ) {
						wp_delete_file( $morn_cb_file );
					}
				}

				// 子目录为空时才移除。
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir -- 删除后需重新检查目录。
				$morn_cb_remain = @scandir( $morn_cb_sub );

				if ( is_array( $morn_cb_remain ) && 2 === count( $morn_cb_remain ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
					@rmdir( $morn_cb_sub );
				}
			}
		}

		// 顶层保护文件。
		foreach ( array( 'index.html', '.htaccess' ) as $morn_cb_guard ) {
			$morn_cb_path = trailingslashit( $morn_cb_dir ) . $morn_cb_guard;

			if ( file_exists( $morn_cb_path ) ) {
				wp_delete_file( $morn_cb_path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir -- 删除后需重新检查目录。
		$morn_cb_root_remain = @scandir( $morn_cb_dir );

		if ( is_array( $morn_cb_root_remain ) && 2 === count( $morn_cb_root_remain ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $morn_cb_dir );
		}
	}
}

// Transient 驱动缓存。
global $wpdb;

$morn_cb_like    = $wpdb->esc_like( '_transient_morn_cb_' ) . '%';
$morn_cb_timeout = $wpdb->esc_like( '_transient_timeout_morn_cb_' ) . '%';

$morn_cb_rows = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$morn_cb_like,
		$morn_cb_timeout
	)
);

if ( is_array( $morn_cb_rows ) ) {
	foreach ( $morn_cb_rows as $morn_cb_option ) {
		delete_option( $morn_cb_option );
	}
}
