<?php
/**
 * Plugin Name: Morn Cache Boost
 * Plugin URI: https://github.com/mornrain-lin/morn-cache-boost
 * Description: 页面缓存加速插件。整页 HTML 输出缓存，支持 File / Transient / APCu 三种存储后端，移动端与桌面端分离缓存，智能排除规则，文章更新/主题切换自动失效，可选 GZIP 输出与关键 CSS 内联，后台一键清空缓存。零外部资源。
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.6
 * Author: MornRain
 * Author URI: https://github.com/mornrain-lin
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: morn-cache-boost
 * Domain Path: /languages
 *
 * @package MornRain\MornCacheBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 插件版本。
 */
define( 'MORN_CACHE_BOOST_VERSION', '1.0.0' );

/**
 * 主文件路径。
 */
define( 'MORN_CACHE_BOOST_FILE', __FILE__ );

/**
 * 插件目录路径。
 */
define( 'MORN_CACHE_BOOST_DIR', plugin_dir_path( __FILE__ ) );

/**
 * 插件目录 URL。
 */
define( 'MORN_CACHE_BOOST_URL', plugin_dir_url( __FILE__ ) );

/**
 * 设置选项名。
 */
define( 'MORN_CACHE_BOOST_OPTION', 'morn_cache_boost_settings' );

/**
 * 缓存全局计数器选项名。
 */
define( 'MORN_CACHE_BOOST_STATS', 'morn_cache_boost_stats' );

require_once MORN_CACHE_BOOST_DIR . 'includes/functions.php';
require_once MORN_CACHE_BOOST_DIR . 'includes/class-driver-file.php';
require_once MORN_CACHE_BOOST_DIR . 'includes/class-driver-transient.php';
require_once MORN_CACHE_BOOST_DIR . 'includes/class-driver-apcu.php';
require_once MORN_CACHE_BOOST_DIR . 'includes/class-cache.php';
require_once MORN_CACHE_BOOST_DIR . 'includes/class-rules.php';
require_once MORN_CACHE_BOOST_DIR . 'includes/class-invalidator.php';
require_once MORN_CACHE_BOOST_DIR . 'includes/class-bar.php';
require_once MORN_CACHE_BOOST_DIR . 'includes/class-admin.php';
require_once MORN_CACHE_BOOST_DIR . 'includes/class-plugin.php';

/**
 * 启动插件。
 *
 * @return void
 */
function morn_cache_boost_boot() {
	load_plugin_textdomain( 'morn-cache-boost', false, dirname( plugin_basename( MORN_CACHE_BOOST_FILE ) ) . '/languages' );

	Morn_Cache_Boost_Plugin::init();
}
add_action( 'plugins_loaded', 'morn_cache_boost_boot' );

/**
 * 激活钩子。
 *
 * @return void
 */
function morn_cache_boost_activate() {
	// 激活时清空一次，避免旧格式缓存被误用。
	Morn_Cache_Boost_Plugin::flush_all();

	update_option( 'morn_cache_boost_activated_at', time(), false );
}
register_activation_hook( __FILE__, 'morn_cache_boost_activate' );

/**
 * 停用钩子：清空全部缓存。
 *
 * @return void
 */
function morn_cache_boost_deactivate() {
	Morn_Cache_Boost_Plugin::flush_all();
	wp_clear_scheduled_hook( 'morn_cache_boost_gc' );
}
register_deactivation_hook( __FILE__, 'morn_cache_boost_deactivate' );
