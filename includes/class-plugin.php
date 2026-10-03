<?php
/**
 * 插件启动与整页缓存流程。
 *
 * @package MornRain\MornCacheBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 协调缓存读写、输出缓冲与响应头。
 */
class Morn_Cache_Boost_Plugin {

	/**
	 * 缓存键。
	 *
	 * @var string
	 */
	private static $key = '';

	/**
	 * 注册钩子。
	 *
	 * @return void
	 */
	public static function init() {
		Morn_Cache_Boost_Admin::init();
		Morn_Cache_Boost_Invalidator::init();
		Morn_Cache_Boost_Bar::init();

		if ( ! self::should_activate() ) {
			return;
		}

		// 尽早开始缓冲，以便捕获整页输出。
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 0 );
	}

	/**
	 * 判断当前请求是否应启动缓存流程。
	 *
	 * @return bool
	 */
	private static function should_activate() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		/**
		 * 过滤是否启用缓存流程。
		 *
		 * @param bool $active 是否启用。
		 */
		return (bool) apply_filters( 'morn_cache_boost_active', true );
	}

	/**
	 * 启动输出缓冲。
	 *
	 * @return void
	 */
	public static function start_buffer() {
		if ( headers_sent() ) {
			return;
		}

		// 排除的请求完全不缓冲。
		if ( Morn_Cache_Boost_Rules::is_excluded() ) {
			return;
		}

		// 只缓冲 HTML 响应。
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) ) : '';

		if ( '' !== $accept && false === strpos( $accept, 'text/html' ) && false === strpos( $accept, '*/*' ) && false === strpos( $accept, 'application/xhtml' ) ) {
			return;
		}

		self::$key = Morn_Cache_Boost_Cache::build_key();

		$cached = Morn_Cache_Boost_Cache::get( self::$key );

		if ( '' !== $cached ) {
			self::serve_cached( $cached );

			return;
		}

		self::record_miss();

		ob_start( array( __CLASS__, 'filter_output' ) );
	}

	/**
	 * 输出缓存内容并终止请求。
	 *
	 * @param string $content 缓存内容。
	 * @return void
	 */
	private static function serve_cached( $content ) {
		$age = self::estimate_age();

		self::bump_stat( 'hits' );

		if ( ! headers_sent() ) {
			Morn_Cache_Boost_Rules::send_headers( $age );

			if ( self::should_gzip() ) {
				header( 'Content-Encoding: gzip' );
				$content = self::gzip_compress( $content );
			}

			if ( '' !== $content ) {
				header( 'Content-Length: ' . strlen( $content ) );
			}
		}

		// 结束前关闭本插件及之前注册的输出缓冲，确保内容被真正送出。
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 缓存的是本站已渲染的完整 HTML。
		exit;
	}

	/**
	 * 过滤输出内容，注入统计注释并决定是否写入缓存。
	 *
	 * @param string $content 缓冲区内容。
	 * @return string
	 */
	public static function filter_output( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		// 只缓存完整的 HTML 页面。
		if ( false === stripos( $content, '</html>' ) ) {
			return $content;
		}

		// 若在输出过程中出现了 nocache 头（WordPress 可能后置发送），放弃缓存。
		if ( Morn_Cache_Boost_Rules::has_nocache_headers() ) {
			return $content;
		}

		if ( ! headers_sent() ) {
			Morn_Cache_Boost_Rules::send_headers( 0 );
		}

		$output = $content;

		// 注入内联 CSS。
		$output = self::maybe_inline_css( $output );

		// 先写入缓存（存储压缩前、不含命中注释的原始内容），
		// 否则「未命中」注释会被固化进缓存并在后续命中时误报。
		Morn_Cache_Boost_Cache::set( self::$key, $output );

		// 输出命中注释（仅本次响应，不入缓存）。
		if ( morn_cache_boost_get_setting( 'cache_comment', 1 ) ) {
			$output .= "\n<!-- " . esc_html__( 'Morn Cache Boost: 本次为缓存未命中，页面已生成新内容。', 'morn-cache-boost' ) . " -->\n";
		}

		// GZIP 压缩输出。
		if ( self::should_gzip() && ! headers_sent() ) {
			header( 'Content-Encoding: gzip' );
			$compressed = self::gzip_compress( $output );

			if ( '' !== $compressed ) {
				return $compressed;
			}
		}

		return $output;
	}

	/**
	 * 内联关键 CSS。
	 *
	 * @param string $html 页面 HTML。
	 * @return string
	 */
	private static function maybe_inline_css( $html ) {
		if ( ! morn_cache_boost_get_setting( 'inline_css', 0 ) ) {
			return $html;
		}

		$url = trim( (string) morn_cache_boost_get_setting( 'inline_css_url', '' ) );

		if ( '' === $url ) {
			$url = self::guess_theme_style_url();
		}

		if ( '' === $url ) {
			return $html;
		}

		// 只允许内联本站的 CSS，避免对外部域发起请求。
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( empty( $host ) || $host !== $home_host ) {
			return $html;
		}

		// 只允许 uploads 与主题目录下的文件。
		$uploads = wp_get_upload_dir();
		$base_dir = empty( $uploads['basedir'] ) ? '' : wp_normalize_path( $uploads['basedir'] );
		$base_url = empty( $uploads['baseurl'] ) ? '' : $uploads['baseurl'];

		$path = '';

		if ( '' !== $base_url && 0 === strpos( $url, $base_url ) ) {
			$path = $base_dir . substr( $url, strlen( $base_url ) );
		} else {
			// 尝试解析为相对于 home_url 的路径。
			$relative = str_replace( array( home_url(), content_url() ), '', $url );
			$relative = ltrim( $relative, '/' );

			if ( '' !== $relative ) {
				$path = ABSPATH . $relative;
			}
		}

		$path = wp_normalize_path( (string) $path );

		// 防止路径穿越。
		$root = wp_normalize_path( ABSPATH );

		if ( '' === $path || 0 !== strpos( $path, $root ) || ! file_exists( $path ) || filesize( $path ) > 20480 ) {
			return $html;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 读取本站静态资源。
		$css = @file_get_contents( $path );

		if ( false === $css || '' === trim( $css ) ) {
			return $html;
		}

		$inline = '<style id="morn-cb-inline-css">' . wp_strip_all_tags( $css ) . "</style>\n";

		// 插到 </head> 之前。
		$pos = strripos( $html, '</head>' );

		if ( false === $pos ) {
			return $html;
		}

		return substr( $html, 0, $pos ) . $inline . substr( $html, $pos );
	}

	/**
	 * 猜测主题 style.css 地址。
	 *
	 * @return string
	 */
	private static function guess_theme_style_url() {
		$theme = wp_get_theme();

		if ( ! $theme || ! $theme->exists() ) {
			return '';
		}

		return $theme->get_stylesheet_directory_uri() . '/style.css';
	}

	/**
	 * 是否应启用 GZIP。
	 *
	 * @return bool
	 */
	private static function should_gzip() {
		if ( ! morn_cache_boost_get_setting( 'gzip', 0 ) ) {
			return false;
		}

		if ( ! function_exists( 'ob_gzhandler' ) ) {
			return false;
		}

		$accept = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) )
			: '';

		// 避免对已压缩内容重复压缩。
		if ( isset( $_SERVER['HTTP_CONTENT_ENCODING'] ) ) {
			return false;
		}

		return false !== strpos( $accept, 'gzip' );
	}

	/**
	 * 压缩内容。
	 *
	 * @param string $content 内容。
	 * @return string 压缩失败时返回空字符串。
	 */
	private static function gzip_compress( $content ) {
		// 极短内容压缩得不偿失。
		if ( strlen( $content ) < 512 ) {
			return '';
		}

		if ( function_exists( 'gzencode' ) ) {
			$compressed = gzencode( $content, 6 );

			if ( false !== $compressed ) {
				return $compressed;
			}
		}

		if ( function_exists( 'gzcompress' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$compressed = gzcompress( $content, 6 );

			if ( false !== $compressed && function_exists( 'gzinflate' ) ) {
				return $compressed;
			}
		}

		return '';
	}

	/**
	 * 估算缓存年龄（秒）。
	 *
	 * 通过 File 驱动的文件修改时间推算；其它驱动返回 0。
	 *
	 * @return int
	 */
	private static function estimate_age() {
		$file = Morn_Cache_Boost_Driver_File::get_file_path( self::$key );

		if ( ! empty( $file['full'] ) && file_exists( $file['full'] ) ) {
			$mtime = filemtime( $file['full'] );

			if ( $mtime ) {
				return max( 0, time() - (int) $mtime );
			}
		}

		return 0;
	}

	/**
	 * 记录一次缓存未命中。
	 *
	 * @return void
	 */
	private static function record_miss() {
		self::bump_stat( 'misses' );
	}

	/**
	 * 更新统计计数。
	 *
	 * @param string $field 字段名：hits 或 misses。
	 * @return void
	 */
	private static function bump_stat( $field ) {
		$stats = get_option( MORN_CACHE_BOOST_STATS, array() );

		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		$stats[ $field ] = isset( $stats[ $field ] ) ? (int) $stats[ $field ] + 1 : 1;

		update_option( MORN_CACHE_BOOST_STATS, $stats, false );
	}

	/**
	 * 清空全部缓存（供激活/停用钩子调用）。
	 *
	 * @return int
	 */
	public static function flush_all() {
		return Morn_Cache_Boost_Invalidator::flush_all();
	}
}
