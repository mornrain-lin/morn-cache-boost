# Morn Cache Boost

页面缓存加速插件。整页 HTML 输出缓存，支持 File / Transient / APCu 三种存储后端，移动端与桌面端分离，多维排除规则，内容变更自动失效。

零外部资源，不引用任何 CDN、远程 API 或第三方脚本。

- 版本：1.0.0
- 需要 WordPress：6.0+
- 需要 PHP：7.4+
- 测试至：WordPress 6.6
- 许可证：MIT

## 特性

- **整页缓存**：在 `template_redirect` 优先级 0 启动输出缓冲，捕获完整页面 HTML。
- **三种存储后端**：File（最快，不占数据库）、Transient（适合共享主机）、APCu（内存，不持久）。
- **移动端分离**：避免移动端拿到桌面端布局缓存。
- **智能缓存键**：版本 + 路径 + 设备 + 登录态 + 归一化查询参数，参数排序后拼接。
- **严格遵守 nocache 语义**：检测到 `no-cache` / `private` 等响应头立即放弃缓存，不会破坏 WordPress 的缓存约定。
- **自动失效**：文章、评论、菜单、术语、主题、自定义器变更时自动清空。
- **退出即清**：用户退出登录时自动清空，避免个性化内容被复用。
- **可选 GZIP**：自动检查协商与内容长度，避免重复压缩。
- **可选 CSS 内联**：严格限制同源、目录与大小，阻断任意文件读取。
- **顶栏一键清空**：带 nonce 校验的管理员工具栏按钮。
- **原子写入**：临时文件 + rename，避免并发读到半截内容。

## 安装

1. 将 `morn-cache-boost` 目录上传到 `wp-content/plugins/`。
2. 在后台「插件」中启用「Morn Cache Boost」。
3. 进入「页面缓存 → 页面缓存加速」选择存储后端并保存。
4. 保存设置后点击「清空全部缓存」一次，让新配置生效。

> **重要**：如果站点已经使用了 Nginx FastCGI Cache、Varnish、WP Super Cache 或其它页面缓存插件，**请只保留一个**。多层整页缓存会导致频繁失效，反而更慢。

## 配置说明

后台路径：**页面缓存 → 页面缓存加速**（顶层菜单，`manage_options` 权限）。

设置页顶部是状态面板：缓存条数、占用空间、已过期数、命中率、当前驱动，并提供「清空全部缓存」与「仅清理过期项」两个按钮。

### 基本设置

| 设置项 | 默认值 | 说明 |
| --- | --- | --- |
| 启用页面缓存 | 启用 | 总开关。 |
| 存储后端 | 文件（uploads 目录） | 见下方对比表。 |
| 缓存有效期（秒） | `3600` | TTL。范围 60~604800（7 天）。到期后下次访问自动重建。 |
| 移动端与桌面端分离缓存 | 启用 | 关闭会显著提高命中率但可能给移动端错误的布局。**建议保持开启。** |
| 缓存命中注释 | 启用 | 在页面 `</body>` 后输出 HTML 注释，方便排查。查看页面源代码即可看到。 |

**存储后端对比**

| 后端 | 速度 | 持久性 | 适用场景 |
| --- | --- | --- | --- |
| 文件（uploads） | 最快 | 持久 | **推荐**。不占数据库，磁盘 IO 极快。 |
| Transient（数据库） | 中等 | 持久 | 共享主机无文件写权限时使用。数据库负载较高。 |
| APCu（内存） | 最快 | **不持久** | 进程重启即失效。只在高流量单机部署下有意义。 |

> APCu 扩展不可用时，该选项会显示「当前不可用」并自动回退到文件后端。

### 排除规则

| 设置项 | 默认值 | 说明 |
| --- | --- | --- |
| 排除已登录用户 | **启用** | **最重要的安全项。** 关闭会把管理员工具栏等个性化内容缓存给访客。设置页会在关闭时显示警告。 |
| 排除管理员 | 启用 | 即使关闭上一项，具备 `edit_posts` 权限的用户仍不缓存。 |
| 排除带登录 Cookie 的请求 | 启用 | 检测 `wordpress_logged_in`、`wp-postpass`、`comment_author_*` 等 Cookie。 |
| 排除文章更新后的即时请求（POST） | 启用 | 排除全部非 GET/HEAD 请求。 |
| 排除的查询参数 | `replytocom,wp-login` | 英文逗号分隔。命中任一参数即不缓存。 |
| 排除的 URL 片段 | 空 | 每行一条，URI 含其中任一片段即不缓存。最多 100 条。 |
| 排除的 User-Agent 片段 | 空 | 每行一条，UA 含其中任一片段即不缓存。自动转小写。最多 50 条。 |
| 用户退出时清空缓存 | 启用 | 退出登录会改变响应状态，必须立即失效。 |

**始终被排除的请求**（无需配置，代码强制）

非 GET/HEAD 请求、WordPress 后台、AJAX、REST API、XML-RPC、WP-CLI、WP-Cron、文章预览、404 页面、搜索结果页、Feed、非 `publish` 状态的文章、密码保护文章。

**排除 URL 片段示例**

```text
/cart
/checkout
/my-account
/wp-admin
?orderby=
```

### 输出优化

| 设置项 | 默认值 | 说明 |
| --- | --- | --- |
| 启用 GZIP 输出 | 关闭 | 需服务器支持 zlib。会自动检查 `Accept-Encoding` 协商。 |
| 内联关键 CSS | 关闭 | 见下方说明。 |
| 要内联的 CSS 文件 URL | 空 | 留空则自动探测当前主题的 `style.css`。 |

**内联 CSS 的安全限制**

为避免任意文件读取，只有同时满足以下条件才会内联：

1. URL 与站点同源
2. 文件位于 uploads 目录或 WordPress 安装目录内（`wp_normalize_path` 前缀校验，防路径穿越）
3. 文件大小 ≤ 20KB
4. 文件真实存在

**建议**：内联只适合小文件。超过 20KB 的 CSS 内联会拖慢首屏解析时间，反而有害。

## 工作原理

### 缓存流程

```
template_redirect (优先级 0)
  ↓
排除规则检查 ──── 命中 ────→ 放弃缓存，正常输出
  ↓ 未命中
Accept 头检查 ─── 非 HTML ───→ 放弃缓存
  ↓
计算缓存键（版本 + 路径 + 设备 + 登录态 + 查询参数）
  ↓
读取缓存 ──── 命中 ────→ 发送 X-Morn-Cache 头 + GZIP → 输出 → exit
  ↓ 未命中
ob_start() 开始缓冲
  ↓
WordPress 正常渲染并输出
  ↓
缓冲区回调 filter_output()
  ├─ 校验 </html> 是否存在
  ├─ 检查是否出现 nocache 头 → 命中则放弃缓存
  ├─ 内联 CSS（可选）
  ├─ 追加缓存注释（可选）
  ├─ 写入缓存
  └─ GZIP 压缩（可选）
```

### 缓存键构成

```text
v1:{版本号}|{请求路径}|{设备类型}|{登录态}|{归一化查询参数}
```

| 组成 | 说明 |
| --- | --- |
| 版本号 | 每次清空时递增，旧键自然失效。 |
| 路径 | `REQUEST_URI` 的 path 部分。 |
| 设备类型 | `m`（移动）或 `d`（桌面），仅在「分离缓存」开启时加入。 |
| 登录态 | 已登录时为 `u{用户ID}` + `r{是否可编辑}`，未登录时不加入。 |
| 归一化查询参数 | 参数名转小写、过滤空值与控制字符、按字母排序后拼接。 |

**归一化的作用**：`?a=1&b=2` 与 `?b=2&a=1` 会产生相同的缓存键，避免重复缓存同一页面。

### nocache 语义

本插件**绝不会**缓存带有以下响应头的响应：

- `Cache-Control: no-cache` / `no-store` / `private` / `must-revalidate`
- `Pragma: no-cache`
- `Expires: <1970 年时间戳>`

检查在写入缓存前通过 `headers_list()` 完成，覆盖 WordPress 在 `shutdown` 阶段后置发送 `nocache_headers()` 的情况。这保证了插件与 WordPress 及其它插件的缓存语义不冲突。

### File 驱动的存储格式

```text
uploads/morn-cache-boost/
├── index.html          # 阻止目录列表
├── .htaccess           # Apache 拒绝访问
├── 3f/
│   └── 3fa85f64....html
└── a1/
    └── a1b2c3d4....html
```

- **两级目录**：按 MD5 前两位分子目录，避免单目录下文件过多导致的文件系统性能下降。
- **文件头 32 字节**：左对齐补零的过期时间戳，其后为完整 HTML。
- **原子写入**：先写 `{文件名}.{随机}.tmp`，成功后 `rename` 到目标文件。`rename` 在同一文件系统内是原子操作，杜绝并发读取半截内容的可能。

## 自动失效时机

| 触发动作 | Hook |
| --- | --- |
| 文章保存 | `save_post` |
| 文章删除 | `deleted_post` |
| 回收站 / 恢复 | `trashed_post`、`untrashed_post` |
| 状态变更 | `transition_post_status` |
| 导航菜单更新 | `wp_update_nav_menu` |
| 主题切换 | `switch_theme` |
| 自定义器保存 | `customize_save_after` |
| 评论提交 / 删除 | `wp_insert_comment`、`deleted_comment` |
| 评论状态变更 | `transition_comment_status` |
| 分类 / 标签变更 | `created_term`、`edited_term`、`delete_term` |
| 固定链接变更 | `update_option_permalink_structure` |
| 站点地址 / 标题变更 | `update_option_home`、`update_option_blogname`、`update_option_blogdescription` |
| **用户退出登录** | `wp_logout` |
| 插件启用 / 停用 | 激活与停用钩子 |

修订版（revision）与自动保存（autosave）**不会**触发清空，避免编辑时的无谓失效。

## Hook 列表

### 动作（do_action）

| Hook | 回调 | 优先级 | 用途 |
| --- | --- | --- | --- |
| `plugins_loaded` | `morn_cache_boost_boot` | 10 | 加载文本域并启动插件。 |
| `template_redirect` | `Morn_Cache_Boost_Plugin::start_buffer` | 0 | 启动输出缓冲或输出缓存。 |
| `init` | `Morn_Cache_Boost_Invalidator::maybe_schedule_gc` | 10 | 注册每小时 GC 任务。 |
| `morn_cache_boost_gc` | `Morn_Cache_Boost_Invalidator::gc` | — | 清理过期缓存文件。 |
| `wp_logout` | `Morn_Cache_Boost_Invalidator::on_logout` | — | 退出登录后清空缓存。 |
| `switch_theme` | `Morn_Cache_Boost_Invalidator::flush_all` | — | 主题切换后清空。 |
| `save_post` | `Morn_Cache_Boost_Invalidator::on_post_change` | 10 | 文章保存后清空。 |
| `wp_update_nav_menu` | `Morn_Cache_Boost_Invalidator::flush_all` | — | 菜单更新后清空。 |
| `customize_save_after` | `Morn_Cache_Boost_Invalidator::flush_all` | — | 自定义器保存后清空。 |
| `admin_bar_menu` | `Morn_Cache_Boost_Bar::add_button` | 999 | 添加顶栏清空按钮。 |
| `morn_cache_boost_set` | — | — | 缓存写入后触发，`do_action( 'morn_cache_boost_set', bool $result, string $key, string $value )`。 |
| `morn_cache_boost_flushed` | — | — | 清空完成后触发，`do_action( 'morn_cache_boost_flushed', int $deleted )`。 |

### 过滤器（apply_filters）

| Hook | 签名 | 说明 |
| --- | --- | --- |
| `morn_cache_boost_settings` | `apply_filters( 'morn_cache_boost_settings', array $settings )` | 过滤合并默认值后的设置。 |
| `morn_cache_boost_active` | `apply_filters( 'morn_cache_boost_active', bool $active )` | 控制是否启用整个缓存流程。 |
| `morn_cache_boost_excluded` | `apply_filters( 'morn_cache_boost_excluded', bool $excluded, string $uri )` | **覆盖排除判定结果**（返回 true 排除）。 |
| `morn_cache_boost_is_mobile` | `apply_filters( 'morn_cache_boost_is_mobile', bool $mobile, string $ua )` | 覆盖移动设备判定。 |
| `morn_cache_boost_cache_key` | `apply_filters( 'morn_cache_boost_cache_key', string $key, string $uri )` | 覆盖缓存键计算。 |
| `morn_cache_boost_get` | `apply_filters( 'morn_cache_boost_get', string $value, string $key )` | 覆盖读取到的缓存内容。 |
| `morn_cache_boost_driver_class` | `apply_filters( 'morn_cache_boost_driver_class', string $class, string $name )` | 替换存储驱动类。 |
| `morn_cache_boost_apcu_flushed` | `apply_filters( 'morn_cache_boost_apcu_flushed', int $deleted )` | APCu 无前缀删除能力，此处补充清理逻辑。 |

### 使用示例

**排除特定页面**

```php
add_filter( 'morn_cache_boost_excluded', function ( $excluded, $uri ) {
    $patterns = array( '/cart', '/checkout', '/account' );

    foreach ( $patterns as $pattern ) {
        if ( false !== strpos( $uri, $pattern ) ) {
            return true;
        }
    }

    return $excluded;
}, 10, 2 );
```

**接入 CDN 的边缘缓存**

```php
add_filter( 'morn_cache_boost_cache_key', function ( $key ) {
    $cdn_zone = 'hk';
    $key .= '|cdn:' . $cdn_zone;

    return $key;
} );
```

**接入 Memcached 作为后端**

```php
add_filter( 'morn_cache_boost_driver_class', function ( $class, $name ) {
    if ( 'file' !== $name ) {
        return $class;
    }

    return 'My_Memcached_Cache_Driver';
}, 10, 2 );
```

> 自定义驱动需实现 `get( $key )`、`set( $key, $value, $ttl )`、`delete( $key )`、`flush()`、`stats()` 五个方法。

**用 Redis 做对象级补充缓存**（与页面缓存无关）

```php
add_action( 'morn_cache_boost_flushed', function () {
    // 页面缓存清空时，同时清理你自己的 Redis 缓存。
} );
```

## FAQ

**Q：为什么命中率很低？**
A：按顺序排查：1) 是否处于「排除已登录用户」状态——**你正在后台/已登录浏览，这些页面本来就不缓存**。请用无痕窗口或登出后访问前台测试；2) 打开页面源代码看末尾的注释，确认是「未命中」还是根本没注入；3) 检查是否命中了某条排除规则。

**Q：为什么更新文章后前台还是旧内容？**
A：正常情况下 `save_post` 会自动清空。若没生效，检查：1) 是否有其它缓存层在更外层（Nginx/Varnish）；2) 浏览器/CDN 缓存——清理它们；3) 浏览器自身缓存，按 Ctrl+Shift+R 强制刷新。

**Q：会不会把管理员登录状态缓存给访客？**
A：默认不会。「排除已登录用户」与「排除管理员」都默认开启，且 Cookie 检测会额外拦截。如果你手动关闭了「排除已登录用户」，设置页会显示警告——**请不要关闭**，否则会泄露管理员工具栏内容。

**Q：GZIP 打开后页面乱码？**
A：可能是服务器或 CDN 已经在做 GZIP，本插件又压缩了一次。已内置 `Content-Encoding` 检测，但仍建议确认服务器配置，**同一层只做一次压缩**。

**Q：内联 CSS 没生效？**
A：检查：1) 留空 URL 时会自动探测主题 `style.css`，请确认主题有该文件；2) 文件必须小于 20KB；3) 必须在 uploads 或 WordPress 目录内；4) URL 必须与站点同源（含 www 与非 www 需一致）；5) 缓存已命中时不会重新处理 HTML——先清空缓存。

**Q：能用 Transient 驱动替代 Redis 吗？**
A：Transient 存在数据库，**在高并发下会显著增加数据库负担**，它的定位是共享主机上的兜底方案。如果你的站点有 Redis/Memcached，建议用上面的过滤器接入，而不是用 Transient。

**Q：缓存文件在哪里？**
A：File 驱动下位于 `wp-content/uploads/morn-cache-boost/`。这是**缓存数据，不是内容**，删除后会自动重建。

**Q：能缓存 WooCommerce 购物车页吗？**
A：不建议。购物车、结账、账户页必须保持实时。请把这些 URL 加入「排除的 URL 片段」。

**Q：磁盘空间会持续增长吗？**
A：不会。过期文件由每小时 GC 任务自动清理，手动点击「仅清理过期项」也可立即执行。清空缓存会删除全部文件。

**Q：与服务器级缓存（Nginx FastCGI Cache）冲突吗？**
A：会。**只保留一层整页缓存。**服务器级缓存性能远高于 PHP 层，且不占 WordPress 进程。如果已经在用 Nginx 缓存，请勿启用本插件。

**Q：如何确认缓存在工作？**
A：查看响应头。命中时会有：

```text
X-Morn-Cache: HIT
X-Morn-Cache-Age: 120
Cache-Control: public, max-age=3600, stale-while-revalidate=60
```

## 目录说明

```
morn-cache-boost/
├── morn-cache-boost.php    # 主文件：插件头、启动、激活/停用钩子
├── uninstall.php            # 卸载清理（选项、定时任务、缓存文件）
├── README.md
├── LICENSE                  # MIT
├── CHANGELOG.md
├── .gitignore
├── .gitattributes
├── assets/
│   └── css/admin.css        # 后台状态面板与顶栏样式
└── includes/
    ├── functions.php            # 设置读取、UA 判定、查询归一化、格式化
    ├── class-plugin.php         # 启动与整页缓存流程（输出缓冲核心）
    ├── class-cache.php          # 驱动协调、缓存键计算、读写封装
    ├── class-driver-file.php    # File 驱动（原子写入、GC、统计）
    ├── class-driver-transient.php # Transient 驱动
    ├── class-driver-apcu.php    # APCu 驱动（不可用时降级）
    ├── class-rules.php          # 排除规则与 nocache 语义检测
    ├── class-invalidator.php    # 自动失效钩子与定时 GC
    ├── class-bar.php            # 顶栏清空按钮
    └── class-admin.php          # 设置页与统计面板
```

**存储的选项**

| 选项名 | 类型 | 说明 |
| --- | --- | --- |
| `morn_cache_boost_settings` | array | 插件全部设置。 |
| `morn_cache_boost_version` | integer | 缓存键版本号，自动维护。 |
| `morn_cache_boost_stats` | array | 命中/未命中计数。 |
| `morn_cache_boost_activated_at` | integer | 激活时间戳。 |

**缓存文件**：`uploads/morn-cache-boost/{前两位}/{md5}.html`

**Transient 前缀**：`_transient_morn_cb_`（Transient 驱动使用）

**定时任务**：`morn_cache_boost_gc`（每小时执行）

## 卸载说明

在后台「插件」中点击「删除」并确认卸载时，`uninstall.php` 会执行以下清理：

- 删除选项 `morn_cache_boost_settings`、`morn_cache_boost_stats`、`morn_cache_boost_version`、`morn_cache_boost_activated_at`
- 取消定时任务 `morn_cache_boost_gc`
- 删除 `uploads/morn-cache-boost/` 下全部缓存文件与保护文件，目录为空时一并移除
- 清理所有 `_transient_morn_cb_*` 缓存

停用插件时也会自动清空缓存，避免残留数据。

**不会删除**：任何文章、媒体附件、用户数据、主题文件或其它插件的数据。

## License

MIT License
Copyright (c) 2026 MornRain

详见 [LICENSE](LICENSE)。
