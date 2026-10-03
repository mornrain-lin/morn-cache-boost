<?php
/**
 * 缓存失效处理。
 *
 * @package MornRain\MornCacheBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 在内容变更、主题切换、用户退出时清理缓存。
 */
class Morn_Cache_Boost_Invalidator {

	/**
	 * 注册钩子。
	 *
	 * @return void
	 */
	public static function init() {
		// 文章变更。
		add_action( 'save_post', array( __CLASS__, 'on_post_change' ), 10, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'on_post_deleted' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_post_change_simple' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_post_change_simple' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status_change' ), 10, 3 );

		// 导航与菜单。
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'flush_all' ) );
		add_action( 'update_option_permalink_structure', array( __CLASS__, 'flush_all' ) );
		add_action( 'update_option_home', array( __CLASS__, 'flush_all' ) );
		add_action( 'update_option_blogname', array( __CLASS__, 'flush_all' ) );
		add_action( 'update_option_blogdescription', array( __CLASS__, 'flush_all' ) );

		// 主题与插件。
		add_action( 'switch_theme', array( __CLASS__, 'flush_all' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'flush_all' ) );

		// 评论（评论数会显示在页面上）。
		add_action( 'wp_insert_comment', array( __CLASS__, 'flush_all' ) );
		add_action( 'deleted_comment', array( __CLASS__, 'flush_all' ) );
		add_action( 'transition_comment_status', array( __CLASS__, 'flush_all' ), 10, 3 );

		// 术语。
		add_action( 'created_term', array( __CLASS__, 'flush_all' ) );
		add_action( 'edited_term', array( __CLASS__, 'flush_all' ) );
		add_action( 'delete_term', array( __CLASS__, 'flush_all' ) );

		// 用户退出后清理该用户可能产生的缓存。
		add_action( 'wp_logout', array( __CLASS__, 'on_logout' ) );

		// 定期清理过期文件。
		add_action( 'morn_cache_boost_gc', array( __CLASS__, 'gc' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_gc' ) );
	}

	/**
	 * 文章保存后清理。
	 *
	 * @param int     $post_id 文章 ID。
	 * @param WP_Post $post    文章对象。
	 * @param bool    $update  是否为更新。
	 * @return void
	 */
	public static function on_post_change( $post_id, $post, $update ) {
		// 自动草稿与修订不触发清理。
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( $post instanceof WP_Post && 'auto-draft' === $post->post_status ) {
			return;
		}

		self::flush_all();
	}

	/**
	 * 文章删除后清理。
	 *
	 * @param int $post_id 文章 ID。
	 * @return void
	 */
	public static function on_post_deleted( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		self::flush_all();
	}

	/**
	 * 回收站状态变化时清理。
	 *
	 * @param int $post_id 文章 ID。
	 * @return void
	 */
	public static function on_post_change_simple( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		self::flush_all();
	}

	/**
	 * 文章状态变化时清理。
	 *
	 * @param string  $new_status 新状态。
	 * @param string  $old_status 旧状态。
	 * @param WP_Post $post       文章对象。
	 * @return void
	 */
	public static function on_status_change( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status ) {
			return;
		}

		if ( $post instanceof WP_Post && 'auto-draft' === $post->post_status ) {
			return;
		}

		self::flush_all();
	}

	/**
	 * 用户退出时清理。
	 *
	 * @return void
	 */
	public static function on_logout() {
		if ( ! morn_cache_boost_get_setting( 'delete_on_logout', 1 ) ) {
			return;
		}

		// 退出登录会改变响应状态，缓存的用户页面必须立即失效。
		self::flush_all();
	}

	/**
	 * 清空全部缓存。
	 *
	 * @return int
	 */
	public static function flush_all() {
		$deleted = Morn_Cache_Boost_Cache::flush();

		self::bump_version();

		return $deleted;
	}

	/**
	 * 递增缓存键版本号。
	 *
	 * 版本号参与缓存键计算，递增后所有旧键自然失效。
	 *
	 * @return void
	 */
	private static function bump_version() {
		$version = (int) get_option( 'morn_cache_boost_version', 1 );
		update_option( 'morn_cache_boost_version', $version + 1, false );
	}

	/**
	 * 注册定期清理任务。
	 *
	 * @return void
	 */
	public static function maybe_schedule_gc() {
		if ( ! wp_next_scheduled( 'morn_cache_boost_gc' ) ) {
			wp_schedule_event( time() + 30 * MINUTE_IN_SECONDS, 'hourly', 'morn_cache_boost_gc' );
		}
	}

	/**
	 * 清理过期缓存文件。
	 *
	 * @return void
	 */
	public static function gc() {
		Morn_Cache_Boost_Cache::gc();
	}
}
