# Changelog

本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

## [1.0.0] - 2026-10-02

### 新增

- 整页 HTML 输出缓存：在 `template_redirect` 优先级 0 启动输出缓冲，捕获完整页面后写入缓存。
- 三种可切换存储后端：
  - **File**：写入 `uploads/morn-cache-boost/`，两级目录结构（MD5 前两位）避免单目录文件过多，原子写入（临时文件 + rename）防止并发读到半截内容，文件头 32 字节存过期时间戳。
  - **Transient**：使用 WordPress transient，支持按前缀批量清理。
  - **APCu**：扩展不可用时自动回退到 File。
- 移动端与桌面端分离缓存，基于 12 个 UA 关键字判定，可通过过滤器覆盖。
- 缓存键构成：版本号 + 路径 + 设备类型 + 登录态（用户 ID + 是否可编辑）+ 归一化查询参数（键排序、过滤控制字符与空值）。
- 版本号参与缓存键，清空时递增版本使旧键自然失效。
- 多维排除规则：非 GET/HEAD、后台、AJAX、REST、XML-RPC、WP-CLI、CRON、预览页、已登录用户、管理员、登录 Cookie、排除的查询参数、排除的 URL 片段、排除的 User-Agent、404、搜索页、Feed、非发布状态、密码保护文章。
- **严格遵守 nocache 语义**：在写入缓存前检查 `headers_list()`，发现 `no-cache` / `no-store` / `private` / `must-revalidate` / `Expires: 1970` 即放弃缓存。
- 自动失效钩子：`save_post`、`deleted_post`、`trashed_post`、`untrashed_post`、`transition_post_status`、`wp_update_nav_menu`、`switch_theme`、`customize_save_after`、`created_term`、`edited_term`、`delete_term`、`wp_insert_comment`、`deleted_comment`、`transition_comment_status`，以及固定链接、站点地址、站点标题等选项更新。
- 用户退出登录时自动清空缓存，避免个性化内容被复用。
- 可选 GZIP 输出：检查 `Accept-Encoding`、zlib 可用性、内容长度（< 512 字节不压缩），避免重复压缩。
- 可选关键 CSS 内联：仅允许 uploads 与主题目录下的文件、大小 ≤ 20KB、同源校验、`wp_normalize_path` 防路径穿越，插入到 `</head>` 之前。
- 后台统计面板：缓存条数、占用空间、已过期数、命中率、当前驱动。
- 管理员顶栏「清空页面缓存」按钮（带 nonce）与实时统计子菜单。
- 缓存命中时发送 `X-Morn-Cache: HIT`、`X-Morn-Cache-Age` 与 `Cache-Control: public, max-age=..., stale-while-revalidate=60` 响应头。
- 页面尾部可选输出缓存命中注释（HTML 注释，不影响渲染）。
- 定时任务：每小时清理过期缓存文件。
- 设置页对 APCu 不可用时禁用该选项并标注「当前不可用」。

### 安全

- 「排除已登录用户」默认开启；关闭时设置页显示醒目警告，防止管理员工具栏内容被缓存给访客。
- 后台清空与 GC 操作均经 `check_admin_referer()` 校验 nonce 与 `manage_options` 权限。
- APCu 不可用时自动降级，不会导致页面报错。
- CSS 内联严格限制同源 + uploads/主题目录 + 20KB 上限，阻断任意文件读取。
- 缓存目录写入 `.htaccess`（Apache）与 `index.html`，阻止目录列表与直接访问。
- 统计计数仅在管理员登录时写库，避免高频请求拖慢数据库。

### 兼容

- PHP 7.4 ~ 8.3 语法兼容，未使用 enum、readonly、构造器属性提升、命名参数等 PHP 8 独有特性。
- WordPress 6.0 ~ 6.6。
- 零外部资源：不引用任何 CDN、远程 API 或第三方脚本，CSS 内联仅读取本站文件。
