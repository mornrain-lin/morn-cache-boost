<?php
/**
 * 后台设置页。
 *
 * @package MornRain\MornCacheBoost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 设置页与统计面板。
 */
class Morn_Cache_Boost_Admin {

	/**
	 * 设置分组名。
	 */
	const GROUP = 'morn_cache_boost_group';

	/**
	 * 菜单 slug。
	 */
	const PAGE = 'morn-cache-boost';

	/**
	 * 注册钩子。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_morn_cache_boost_flush', array( __CLASS__, 'handle_flush' ) );
		add_action( 'admin_post_morn_cache_boost_gc', array( __CLASS__, 'handle_gc' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	/**
	 * 注册菜单。
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_menu_page(
			__( '页面缓存加速', 'morn-cache-boost' ),
			__( '页面缓存', 'morn-cache-boost' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			'dashicons-performance',
			60
		);
	}

	/**
	 * 注册设置项。
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			MORN_CACHE_BOOST_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section( 'sec_basic', __( '基本设置', 'morn-cache-boost' ), array( __CLASS__, 'render_sec_basic' ), self::PAGE );
		self::field( 'enabled', __( '启用页面缓存', 'morn-cache-boost' ), 'checkbox', 'sec_basic' );
		self::field( 'driver', __( '存储后端', 'morn-cache-boost' ), 'select_driver', 'sec_basic' );
		self::field( 'ttl', __( '缓存有效期（秒）', 'morn-cache-boost' ), 'number', 'sec_basic' );
		self::field( 'separate_mobile', __( '移动端与桌面端分离缓存', 'morn-cache-boost' ), 'checkbox', 'sec_basic' );
		self::field( 'cache_comment', __( '在页面尾部输出缓存命中注释', 'morn-cache-boost' ), 'checkbox', 'sec_basic' );

		add_settings_section( 'sec_exclude', __( '排除规则', 'morn-cache-boost' ), array( __CLASS__, 'render_sec_exclude' ), self::PAGE );
		self::field( 'exclude_logged', __( '排除已登录用户', 'morn-cache-boost' ), 'checkbox', 'sec_exclude' );
		self::field( 'exclude_admin', __( '排除管理员', 'morn-cache-boost' ), 'checkbox', 'sec_exclude' );
		self::field( 'exclude_cookies', __( '排除带登录 Cookie 的请求', 'morn-cache-boost' ), 'checkbox', 'sec_exclude' );
		self::field( 'exclude_post', __( '排除文章更新后的即时请求（POST）', 'morn-cache-boost' ), 'checkbox', 'sec_exclude' );
		self::field( 'exclude_query', __( '排除的查询参数（英文逗号分隔）', 'morn-cache-boost' ), 'text', 'sec_exclude' );
		self::field( 'exclude_urls', __( '排除的 URL 片段（每行一条）', 'morn-cache-boost' ), 'textarea', 'sec_exclude' );
		self::field( 'exclude_ua', __( '排除的 User-Agent 片段（每行一条）', 'morn-cache-boost' ), 'textarea', 'sec_exclude' );
		self::field( 'delete_on_logout', __( '用户退出时清空缓存', 'morn-cache-boost' ), 'checkbox', 'sec_exclude' );

		add_settings_section( 'sec_output', __( '输出优化', 'morn-cache-boost' ), array( __CLASS__, 'render_sec_output' ), self::PAGE );
		self::field( 'gzip', __( '启用 GZIP 输出', 'morn-cache-boost' ), 'checkbox', 'sec_output' );
		self::field( 'inline_css', __( '内联关键 CSS', 'morn-cache-boost' ), 'checkbox', 'sec_output' );
		self::field( 'inline_css_url', __( '要内联的 CSS 文件 URL', 'morn-cache-boost' ), 'url', 'sec_output' );
	}

	/**
	 * 注册单个字段。
	 *
	 * @param string $key     设置键名。
	 * @param string $label   标签。
	 * @param string $type    控件类型。
	 * @param string $section 分组。
	 * @return void
	 */
	private static function field( $key, $label, $type, $section ) {
		add_settings_field(
			'morn_cb_' . $key,
			$label,
			array( __CLASS__, 'render_field' ),
			self::PAGE,
			$section,
			array(
				'key'  => $key,
				'type' => $type,
			)
		);
	}

	/**
	 * 校验设置。
	 *
	 * @param mixed $input 原始输入。
	 * @return array
	 */
	public static function sanitize( $input ) {
		$old   = morn_cache_boost_get_settings();
		$input = is_array( $input ) ? $input : array();
		$clean = $old;

		$checkboxes = array(
			'enabled',
			'separate_mobile',
			'cache_comment',
			'exclude_logged',
			'exclude_admin',
			'exclude_cookies',
			'exclude_post',
			'delete_on_logout',
			'gzip',
			'inline_css',
		);

		foreach ( $checkboxes as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		// 存储后端白名单。
		$drivers = array( 'file', 'transient', 'apcu' );
		$driver  = isset( $input['driver'] ) ? (string) $input['driver'] : 'file';

		if ( ! in_array( $driver, $drivers, true ) ) {
			$driver = 'file';
		}

		// APCu 不可用时回退提示。
		if ( 'apcu' === $driver && ! Morn_Cache_Boost_Driver_Apcu::is_available() ) {
			$driver = 'file';
		}

		$clean['driver'] = $driver;

		$clean['ttl'] = isset( $input['ttl'] ) ? max( 60, min( 604800, absint( $input['ttl'] ) ) ) : 3600;

		if ( isset( $input['exclude_query'] ) ) {
			$raw  = is_scalar( $input['exclude_query'] ) ? (string) $input['exclude_query'] : '';
			$list = array();

			foreach ( explode( ',', $raw ) as $name ) {
				$name = sanitize_key( trim( $name ) );

				if ( '' !== $name ) {
					$list[] = $name;
				}
			}

			$clean['exclude_query'] = implode( ',', array_slice( $list, 0, 50 ) );
		}

		if ( isset( $input['exclude_urls'] ) ) {
			$raw   = is_scalar( $input['exclude_urls'] ) ? (string) $input['exclude_urls'] : '';
			$lines = preg_split( '/\r\n|\r|\n/', $raw );
			$out   = array();

			foreach ( (array) $lines as $line ) {
				$line = trim( (string) $line );

				if ( '' === $line ) {
					continue;
				}

				// 只保留路径/URL 片段形式。
				$line = ltrim( $line, '/' );
				$out[] = substr( $line, 0, 200 );

				if ( count( $out ) >= 100 ) {
					break;
				}
			}

			$clean['exclude_urls'] = implode( "\n", $out );
		}

		if ( isset( $input['exclude_ua'] ) ) {
			$raw   = is_scalar( $input['exclude_ua'] ) ? (string) $input['exclude_ua'] : '';
			$lines = preg_split( '/\r\n|\r|\n/', $raw );
			$out   = array();

			foreach ( (array) $lines as $line ) {
				$line = strtolower( trim( (string) $line ) );

				if ( '' === $line ) {
					continue;
				}

				$out[] = substr( $line, 0, 100 );

				if ( count( $out ) >= 50 ) {
					break;
				}
			}

			$clean['exclude_ua'] = implode( "\n", $out );
		}

		if ( isset( $input['inline_css_url'] ) ) {
			$clean['inline_css_url'] = esc_url_raw( trim( (string) $input['inline_css_url'] ) );
		}

		return $clean;
	}

	/**
	 * 渲染设置页。
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '您没有权限访问此页面。', 'morn-cache-boost' ) );
		}
		?>
		<div class="wrap morn-cb-wrap">
			<h1><?php echo esc_html__( '页面缓存加速', 'morn-cache-boost' ); ?></h1>

			<?php echo wp_kses_post( self::render_panel() ); ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * 渲染统计面板。
	 *
	 * @return string
	 */
	public static function render_panel() {
		$stats  = Morn_Cache_Boost_Cache::get_stats();
		$driver = Morn_Cache_Boost_Cache::get_driver_name();
		$stats_option = get_option( MORN_CACHE_BOOST_STATS, array() );

		$hits   = isset( $stats_option['hits'] ) ? (int) $stats_option['hits'] : 0;
		$misses = isset( $stats_option['misses'] ) ? (int) $stats_option['misses'] : 0;
		$total  = $hits + $misses;
		$rate   = $total > 0 ? round( $hits / $total * 100, 1 ) : 0.0;

		$driver_labels = array(
			'file'      => __( '文件（uploads 目录）', 'morn-cache-boost' ),
			'transient' => __( 'Transient（数据库）', 'morn-cache-boost' ),
			'apcu'      => __( 'APCu（内存）', 'morn-cache-boost' ),
		);

		$html  = '<div class="morn-cb-panel">';
		$html .= '<h2>' . esc_html__( '缓存状态', 'morn-cache-boost' ) . '</h2>';
		$html .= '<div class="morn-cb-cards">';

		$cards = array(
			array( __( '缓存条数', 'morn-cache-boost' ), number_format_i18n( $stats['count'] ) ),
			array( __( '占用空间', 'morn-cache-boost' ), morn_cache_boost_format_bytes( $stats['size'] ) ),
			array( __( '已过期', 'morn-cache-boost' ), number_format_i18n( $stats['expired'] ) ),
			array( __( '命中率', 'morn-cache-boost' ), $rate . '%' ),
			array( __( '当前驱动', 'morn-cache-boost' ), isset( $driver_labels[ $driver ] ) ? $driver_labels[ $driver ] : $driver ),
		);

		foreach ( $cards as $card ) {
			$html .= '<div class="morn-cb-card"><span class="morn-cb-card-label">' . esc_html( $card[0] );
			$html .= '</span><span class="morn-cb-card-value">' . esc_html( $card[1] ) . '</span></div>';
		}

		$html .= '</div>';

		$html .= '<p class="morn-cb-actions">';
		$html .= '<a class="button button-primary" href="' . esc_url( wp_nonce_url( add_query_arg( 'action', 'morn_cache_boost_flush', admin_url( 'admin-post.php' ) ), 'morn_cache_boost_flush' ) ) . '">';
		$html .= esc_html__( '清空全部缓存', 'morn-cache-boost' ) . '</a> ';
		$html .= '<a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( 'action', 'morn_cache_boost_gc', admin_url( 'admin-post.php' ) ), 'morn_cache_boost_gc' ) ) . '">';
		$html .= esc_html__( '仅清理过期项', 'morn-cache-boost' ) . '</a>';
		$html .= '</p>';

		$html .= '<p class="description">' . esc_html__( '命中率基于页面底部注释（启用「缓存命中注释」后）或响应头 X-Morn-Cache 统计，仅统计当前会话。', 'morn-cache-boost' ) . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * 基本设置分组说明。
	 *
	 * @return void
	 */
	public static function render_sec_basic() {
		echo '<p class="description">' . esc_html__( 'File 驱动速度最快且不受数据库负载影响；Transient 适合共享主机；APCu 仅在扩展可用时生效，缓存不持久（进程重启即失效）。', 'morn-cache-boost' ) . '</p>';
	}

	/**
	 * 排除规则分组说明。
	 *
	 * @return void
	 */
	public static function render_sec_exclude() {
		echo '<p class="description">' . esc_html__( '「排除已登录用户」默认开启，这是最重要的安全项：关闭后会把管理员工具栏等内容缓存给访客。', 'morn-cache-boost' ) . '</p>';
	}

	/**
	 * 输出优化分组说明。
	 *
	 * @return void
	 */
	public static function render_sec_output() {
		echo '<p class="description">' . esc_html__( 'GZIP 需要服务器支持 zlib；内联 CSS 仅适合小文件（建议 &lt; 20KB），过大会拖慢首屏。', 'morn-cache-boost' ) . '</p>';
	}

	/**
	 * 渲染字段控件。
	 *
	 * @param array $args 字段参数。
	 * @return void
	 */
	public static function render_field( $args ) {
		$settings = morn_cache_boost_get_settings();
		$key      = $args['key'];
		$type     = $args['type'];
		$name     = MORN_CACHE_BOOST_OPTION . '[' . $key . ']';
		$id       = 'morn-cb-' . $key;
		$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';

		switch ( $type ) {
			case 'checkbox':
				?>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( 1, (int) $value ); ?> />
					<?php echo esc_html__( '启用', 'morn-cache-boost' ); ?>
				</label>
				<?php
				break;

			case 'select_driver':
				?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<option value="file" <?php selected( 'file', (string) $value ); ?>><?php echo esc_html__( '文件（uploads 目录）', 'morn-cache-boost' ); ?></option>
					<option value="transient" <?php selected( 'transient', (string) $value ); ?>><?php echo esc_html__( 'Transient（数据库）', 'morn-cache-boost' ); ?></option>
					<option value="apcu" <?php selected( 'apcu', (string) $value ); ?> <?php disabled( ! Morn_Cache_Boost_Driver_Apcu::is_available() ); ?>>
						<?php
						echo esc_html__( 'APCu（内存）', 'morn-cache-boost' );
						if ( ! Morn_Cache_Boost_Driver_Apcu::is_available() ) {
							echo ' — ' . esc_html__( '当前不可用', 'morn-cache-boost' );
						}
						?>
					</option>
				</select>
				<?php
				break;

			case 'textarea':
				?>
				<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="5" class="large-text code"><?php echo esc_textarea( (string) $value ); ?></textarea>
				<?php
				break;

			case 'number':
				?>
				<input type="number" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="small-text" min="60" max="604800" />
				<?php
				break;

			case 'url':
				?>
				<input type="url" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="regular-text" placeholder="https://" />
				<p class="description"><?php echo esc_html__( '留空则自动探测当前主题的 style.css。', 'morn-cache-boost' ); ?></p>
				<?php
				break;

			default:
				?>
				<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="regular-text" />
				<?php
				break;
		}
	}

	/**
	 * 处理清空请求。
	 *
	 * @return void
	 */
	public static function handle_flush() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '您没有权限执行此操作。', 'morn-cache-boost' ) );
		}

		check_admin_referer( 'morn_cache_boost_flush' );

		$deleted = Morn_Cache_Boost_Invalidator::flush_all();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE,
					'morn_flushed'   => (int) $deleted,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * 处理垃圾回收请求。
	 *
	 * @return void
	 */
	public static function handle_gc() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '您没有权限执行此操作。', 'morn-cache-boost' ) );
		}

		check_admin_referer( 'morn_cache_boost_gc' );

		$deleted = Morn_Cache_Boost_Cache::gc();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE,
					'morn_gc'     => (int) $deleted,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * 加载后台资源。
	 *
	 * @param string $hook 当前后台页。
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'morn-cache-boost-admin',
			MORN_CACHE_BOOST_URL . 'assets/css/admin.css',
			array(),
			MORN_CACHE_BOOST_VERSION
		);
	}

	/**
	 * 输出操作结果提示。
	 *
	 * @return void
	 */
	public static function render_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'toplevel_page_' . self::PAGE !== $screen->id ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 仅用于显示提示。
		if ( isset( $_GET['morn_flushed'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: 已清理数量。 */
						__( '已清空缓存，共清理 %d 项。', 'morn-cache-boost' ),
						absint( $_GET['morn_flushed'] )
					)
				)
			);
		}

		if ( isset( $_GET['morn_gc'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: 已清理数量。 */
						__( '已清理 %d 个过期缓存文件。', 'morn-cache-boost' ),
						absint( $_GET['morn_gc'] )
					)
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$settings = morn_cache_boost_get_settings();

		if ( empty( $settings['exclude_logged'] ) && is_user_logged_in() ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( '您已关闭「排除已登录用户」。管理员工具栏等个性化内容可能被缓存并发送给访客，强烈建议重新开启。', 'morn-cache-boost' );
			echo '</p></div>';
		}
	}
}
