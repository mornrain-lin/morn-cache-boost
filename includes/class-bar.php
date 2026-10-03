<?php
/**
 * 顶栏清空缓存按钮。
 *
 * @package MornRain\MornCacheBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 在管理员顶栏显示「清空缓存」按钮。
 */
class Morn_Cache_Boost_Bar {

	/**
	 * 清空动作的 nonce 名称。
	 */
	const NONCE = 'morn_cache_boost_flush';

	/**
	 * 注册钩子。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_button' ), 999 );
	}

	/**
	 * 添加顶栏按钮。
	 *
	 * @param WP_Admin_Bar $bar 管理工具栏对象。
	 * @return void
	 */
	public static function add_button( $bar ) {
		if ( ! $bar instanceof WP_Admin_Bar ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$url = self::get_flush_url();

		$bar->add_node(
			array(
				'id'    => 'morn-cache-boost',
				'title' => esc_html__( '清空页面缓存', 'morn-cache-boost' ),
				'href'  => $url,
				'meta'  => array(
					'title' => esc_attr__( '清空全部页面缓存', 'morn-cache-boost' ),
				),
			)
		);

		$stats = Morn_Cache_Boost_Cache::get_stats();

		$bar->add_node(
			array(
				'id'     => 'morn-cache-boost-stats',
				'parent' => 'morn-cache-boost',
				'title'  => sprintf(
					/* translators: 1: 缓存条数, 2: 占用体积。 */
					esc_html__( '缓存 %1$s 条 / %2$s', 'morn-cache-boost' ),
					esc_html( number_format_i18n( $stats['count'] ) ),
					esc_html( morn_cache_boost_format_bytes( $stats['size'] ) )
				),
				'href'   => false,
				'meta'   => array( 'class' => 'morn-cache-boost-stats' ),
			)
		);
	}

	/**
	 * 生成带 nonce 的清空 URL。
	 *
	 * @return string
	 */
	public static function get_flush_url() {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'morn_cache_boost_flush',
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE
		);
	}
}
