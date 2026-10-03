<?php
/**
 * 缓存排除与命中规则。
 *
 * @package MornRain\MornCacheBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 决定当前请求是否可以读写缓存。
 */
class Morn_Cache_Boost_Rules {

	/**
	 * 判断当前请求是否应被排除（不缓存）。
	 *
	 * @return bool true 表示排除。
	 */
	public static function is_excluded() {
		$settings = morn_cache_boost_get_settings();

		// 插件总开关。
		if ( empty( $settings['enabled'] ) ) {
			return true;
		}

		// 非 GET/HEAD 请求永远不缓存。
		if ( ! self::is_readable_method() ) {
			return true;
		}

		// 后台、AJAX、REST、CRON。
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return true;
		}

		// 预览页面。
		if ( isset( $_GET['preview'] ) || isset( $_GET['preview_id'] ) || isset( $_GET['preview_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		// 登录用户。
		if ( is_user_logged_in() ) {
			if ( ! empty( $settings['exclude_logged'] ) ) {
				return true;
			}

			// 允许缓存登录用户页面时，仍需排除管理员（避免缓存后台工具栏内容）。
			if ( current_user_can( 'edit_posts' ) && ! empty( $settings['exclude_admin'] ) ) {
				return true;
			}
		}

		// 指定 Cookie 存在时不缓存。
		if ( ! empty( $settings['exclude_cookies'] ) && self::has_excluded_cookie() ) {
			return true;
		}

		// 排除的查询参数。
		if ( self::has_excluded_query() ) {
			return true;
		}

		// 排除的 URL 片段。
		if ( self::is_excluded_url() ) {
			return true;
		}

		// 排除的 User-Agent 片段。
		if ( self::is_excluded_ua() ) {
			return true;
		}

		// 页面自身声明不可缓存。
		if ( ! self::is_cacheable_page() ) {
			return true;
		}

		/**
		 * 过滤排除判定结果。
		 *
		 * @param bool   $excluded 是否排除。
		 * @param string $uri      当前请求 URI。
		 */
		return (bool) apply_filters( 'morn_cache_boost_excluded', false, morn_cache_boost_current_url() );
	}

	/**
	 * 判断请求方法是否可缓存。
	 *
	 * @return bool
	 */
	public static function is_readable_method() {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	/**
	 * 判断是否存在被排除的 Cookie。
	 *
	 * @return bool
	 */
	private static function has_excluded_cookie() {
		$blocked = array(
			'wordpress_logged_in',
			'wp-postpass',
			'comment_author_',
			'comment_author_email_',
			'comment_author_url_',
			'wp_lang',
			'wp-settings-',
		);

		foreach ( $blocked as $needle ) {
			foreach ( array_keys( $_COOKIE ) as $name ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( 0 === strpos( (string) $name, $needle ) ) {
					return true;
				}
			}
		}

		// PHP 会把部分 Cookie 合并进单个变量。
		$merged = isset( $_COOKIE['wordpress'] ) ? (string) $_COOKIE['wordpress'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' !== $merged && false !== strpos( $merged, 'wordpress_logged_in' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * 判断是否包含被排除的查询参数。
	 *
	 * @return bool
	 */
	private static function has_excluded_query() {
		$raw = (string) morn_cache_boost_get_setting( 'exclude_query', 'replytocom,wp-login' );

		if ( '' === trim( $raw ) ) {
			return false;
		}

		$names = array_filter( array_map( 'trim', explode( ',', $raw ) ) );

		foreach ( $names as $name ) {
			if ( isset( $_GET[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}

		return false;
	}

	/**
	 * 判断 URL 是否命中排除列表。
	 *
	 * @return bool
	 */
	private static function is_excluded_url() {
		$raw = (string) morn_cache_boost_get_setting( 'exclude_urls', '' );

		if ( '' === trim( $raw ) ) {
			return false;
		}

		$uri = morn_cache_boost_current_url();
		$lines = preg_split( '/\r\n|\r|\n/', $raw );

		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			if ( false !== strpos( $uri, $line ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 判断 User-Agent 是否命中排除列表。
	 *
	 * @return bool
	 */
	private static function is_excluded_ua() {
		$raw = (string) morn_cache_boost_get_setting( 'exclude_ua', '' );

		if ( '' === trim( $raw ) ) {
			return false;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';

		if ( '' === $ua ) {
			return false;
		}

		$lines = preg_split( '/\r\n|\r|\n/', $raw );

		foreach ( (array) $lines as $line ) {
			$line = strtolower( trim( (string) $line ) );

			if ( '' === $line ) {
				continue;
			}

			if ( false !== strpos( $ua, $line ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 页面自身是否允许缓存。
	 *
	 * @return bool
	 */
	private static function is_cacheable_page() {
		if ( is_404() || is_search() ) {
			return false;
		}

		if ( is_front_page() && is_home() && is_paged() ) {
			return false;
		}

		if ( is_feed() ) {
			return false;
		}

		if ( is_preview() ) {
			return false;
		}

		// 未发布内容与密码保护内容不缓存。
		if ( is_singular() ) {
			$post = get_post();

			if ( $post && ( 'publish' !== $post->post_status || post_password_required( $post ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 判断响应是否带有 nocache 语义。
	 *
	 * 若已发送 nocache 头，则必须放弃缓存，避免与 WordPress 语义冲突。
	 *
	 * @return bool
	 */
	public static function has_nocache_headers() {
		if ( ! function_exists( 'headers_list' ) ) {
			return false;
		}

		$headers = headers_list();

		if ( ! is_array( $headers ) ) {
			return false;
		}

		foreach ( $headers as $header ) {
			$lower = strtolower( (string) $header );

			if ( 0 === strpos( $lower, 'cache-control:' ) ) {
				if ( false !== strpos( $lower, 'no-cache' ) || false !== strpos( $lower, 'no-store' ) || false !== strpos( $lower, 'private' ) || false !== strpos( $lower, 'must-revalidate' ) ) {
					return true;
				}
			}

			if ( 0 === strpos( $lower, 'pragma: no-cache' ) ) {
				return true;
			}

			if ( 0 === strpos( $lower, 'expires:' ) ) {
				// Expires: Thu, 01 Jan 1970 视为已过期。
				if ( false !== stripos( $lower, '1970' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * 发送缓存命中相关的响应头。
	 *
	 * @param int $age 缓存年龄（秒）。
	 * @return void
	 */
	public static function send_headers( $age ) {
		if ( headers_sent() ) {
			return;
		}

		$ttl = max( 60, (int) morn_cache_boost_get_setting( 'ttl', 3600 ) );
		$age = max( 0, (int) $age );

		header( 'X-Morn-Cache: HIT' );
		header( 'X-Morn-Cache-Age: ' . $age );
		header( 'Cache-Control: public, max-age=' . $ttl . ', stale-while-revalidate=60' );
	}
}
