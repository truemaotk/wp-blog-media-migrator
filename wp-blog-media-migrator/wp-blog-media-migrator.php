<?php
/**
 * Plugin Name: 博客文章与图片迁移工具
 * Plugin URI: https://www.maotk.com/
 * Description: 在 WordPress 网站之间迁移博客文章、正文、分类标签、特色图、正文图片和阅读量。
 * Version: 1.3.2
 * Author: Mao TK
 * Author URI: https://www.maotk.com/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MaoTK_Blog_Media_Migrator {
	const VERSION           = '1.3.2';
	const PAGE              = 'maotk-blog-media-migrator';
	const BRAND_URL         = 'https://www.maotk.com/';
	const BRAND_LOGO        = 'https://www.maotk.com/wp-content/uploads/maotk-favicon.svg';
	const MAX_PACKAGE_MB    = 1000;
	const MAX_MANIFEST_MB   = 100;
	const MAX_ASSET_MB      = 50;
	const MAX_PACKAGE_FILES = 30000;
	const HASH_META         = '_maotk_blog_migrator_sha256';
	const MANAGED_META      = '_maotk_blog_migrator_managed';
	const SOURCE_META       = '_maotk_blog_migrator_source';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_maotk_bmm_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_maotk_bmm_import', array( __CLASS__, 'import' ) );
		add_action( 'wp_ajax_maotk_bmm_progress', array( __CLASS__, 'ajax_progress' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
	}

	public static function admin_menu() {
		add_management_page(
			'博客文章与图片迁移',
			'博客文章与图片迁移',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file ) {
			$links[] = '<a href="' . esc_url( self::BRAND_URL ) . '" target="_blank" rel="noopener noreferrer">访问 Mao TK</a>';
		}
		return $links;
	}

	private static function require_access() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '你没有执行此操作的权限。' );
		}
	}

	public static function ajax_progress() {
		self::require_access();
		check_ajax_referer( 'maotk_bmm_progress', 'nonce' );
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$data  = $token ? get_transient( self::progress_key( $token ) ) : false;
		wp_send_json_success( $data ? $data : array( 'status' => 'pending' ) );
	}

	private static function progress_key( $token ) {
		return 'maotk_bmm_progress_' . get_current_user_id() . '_' . $token;
	}

	private static function set_progress( $token, $completed, $total, $stage, $current = '', $status = 'running' ) {
		if ( ! $token ) {
			return;
		}
		$old = get_transient( self::progress_key( $token ) );
		set_transient(
			self::progress_key( $token ),
			array(
				'status'     => $status,
				'completed'  => max( 0, (int) $completed ),
				'total'      => max( 1, (int) $total ),
				'stage'      => sanitize_text_field( $stage ),
				'current'    => sanitize_text_field( $current ),
				'started_at' => is_array( $old ) && ! empty( $old['started_at'] ) ? (float) $old['started_at'] : microtime( true ),
				'updated_at' => microtime( true ),
			),
			HOUR_IN_SECONDS
		);
	}

	public static function render_page() {
		self::require_access();
		$export_token = wp_generate_password( 20, false, false );
		$import_token = wp_generate_password( 20, false, false );
		$progress_nonce = wp_create_nonce( 'maotk_bmm_progress' );
		$post_counts  = wp_count_posts( 'post', 'readable' );

		if ( ! class_exists( 'ZipArchive' ) ) {
			echo '<div class="notice notice-error"><p>服务器未启用 PHP ZipArchive 扩展，无法使用迁移功能。</p></div>';
		}

		$result = get_transient( 'maotk_bmm_result_' . get_current_user_id() );
		if ( $result ) {
			delete_transient( 'maotk_bmm_result_' . get_current_user_id() );
			$failed_posts  = isset( $result['failed_posts'] ) && is_array( $result['failed_posts'] ) ? $result['failed_posts'] : array();
			$failed_images = isset( $result['failed_images'] ) && is_array( $result['failed_images'] ) ? $result['failed_images'] : array();
			$warnings      = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array();
			$problem_count = count( $failed_posts ) + count( $failed_images ) + count( $warnings );
			$class         = 0 === $problem_count ? 'notice-success' : 'notice-warning';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>';
			echo esc_html(
				sprintf(
					'导入完成：新增文章 %1$d，覆盖文章 %2$d，跳过文章 %3$d，新增图片 %4$d，文章失败 %5$d，图片失败 %6$d，其他警告 %7$d。',
					(int) $result['created'],
					(int) $result['updated'],
					(int) $result['skipped'],
					(int) $result['images'],
					count( $failed_posts ),
					count( $failed_images ),
					count( $warnings )
				)
			);
			echo '</p>';
			if ( $failed_posts ) {
				echo '<details open><summary><strong>导入失败的文章（' . count( $failed_posts ) . '）</strong></summary><ul>';
				foreach ( $failed_posts as $failure ) {
					echo '<li><strong>' . esc_html( isset( $failure['title'] ) ? $failure['title'] : '未命名文章' ) . '</strong>：' . esc_html( isset( $failure['reason'] ) ? $failure['reason'] : '未知原因' ) . '</li>';
				}
				echo '</ul></details>';
			}
			if ( $failed_images ) {
				echo '<details open><summary><strong>导入失败的图片（' . count( $failed_images ) . '）</strong></summary><ul>';
				foreach ( $failed_images as $failure ) {
					$label = isset( $failure['name'] ) && $failure['name'] ? $failure['name'] : '未命名图片';
					echo '<li><strong>' . esc_html( $label ) . '</strong>：' . esc_html( isset( $failure['reason'] ) ? $failure['reason'] : '未知原因' );
					if ( ! empty( $failure['url'] ) ) {
						echo '<br><code style="word-break:break-all">' . esc_html( $failure['url'] ) . '</code>';
					}
					echo '</li>';
				}
				echo '</ul></details>';
			}
			if ( $warnings ) {
				echo '<details><summary><strong>其他警告（' . count( $warnings ) . '）</strong></summary><ul>';
				foreach ( $warnings as $warning ) {
					echo '<li>' . esc_html( $warning ) . '</li>';
				}
				echo '</ul></details>';
			}
			if ( 0 === $problem_count ) {
				echo '<p><strong>全部文章和已打包图片均导入成功。</strong></p>';
			}
			echo '</div>';
		}
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:10px;">
				<a href="<?php echo esc_url( self::BRAND_URL ); ?>" target="_blank" rel="noopener noreferrer" style="display:inline-flex;">
					<img src="<?php echo esc_url( self::BRAND_LOGO ); ?>" alt="Mao TK" width="38" height="38">
				</a>
				博客文章与图片迁移
			</h1>
			<p>只迁移博客文章，不迁移用户、插件设置、页面、商品或整个数据库。</p>

			<div class="card" style="max-width:800px;padding:8px 20px 20px;">
				<h2>第一步：在旧网站导出</h2>
				<p><strong>选择需要导出的文章状态：</strong></p>
				<form id="maotk-bmm-export-form" method="post" target="maotk-bmm-download-frame" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="maotk_bmm_export">
					<input type="hidden" name="export_token" value="<?php echo esc_attr( $export_token ); ?>">
					<?php wp_nonce_field( 'maotk_bmm_export' ); ?>
					<fieldset style="display:grid;gap:8px;margin:12px 0 16px;">
						<label><input type="checkbox" name="post_statuses[]" value="publish" checked> <strong>已发布（<?php echo isset( $post_counts->publish ) ? (int) $post_counts->publish : 0; ?>）</strong>：网站前台正常公开显示的文章</label>
						<label><input type="checkbox" name="post_statuses[]" value="private"> <strong>私密（<?php echo isset( $post_counts->private ) ? (int) $post_counts->private : 0; ?>）</strong>：仅登录且有权限的用户可以查看</label>
						<label><input type="checkbox" name="post_statuses[]" value="draft"> <strong>草稿（<?php echo isset( $post_counts->draft ) ? (int) $post_counts->draft : 0; ?>）</strong>：尚未发布的文章</label>
						<label><input type="checkbox" name="post_statuses[]" value="pending"> <strong>待审（<?php echo isset( $post_counts->pending ) ? (int) $post_counts->pending : 0; ?>）</strong>：等待审核发布的文章</label>
						<label><input type="checkbox" name="post_statuses[]" value="future"> <strong>定时发布（<?php echo isset( $post_counts->future ) ? (int) $post_counts->future : 0; ?>）</strong>：设定在未来自动发布的文章</label>
					</fieldset>
					<p class="description">
						默认只勾选“已发布”，因此不会导出私密文章。需要迁移私密文章时，再单独勾选“私密”。
					</p>
					<?php submit_button( '下载文章迁移包', 'primary', 'submit', false ); ?>
				</form>
				<hr>
				<h3>迁移包包含什么？</h3>
				<ul style="list-style:disc;padding-left:22px;">
					<li>文章标题、别名、完整正文、摘要、发布日期和原文章状态。</li>
					<li>文章分类、标签、特色图片。</li>
					<li>正文中的普通图片、响应式图片和常见懒加载图片。</li>
					<li>常见阅读量字段，包括字段名含有 view、read 或 hit 的文章数据。</li>
					<li>文章密码；如果私密文章本身没有密码，则仍按“私密”状态迁移。</li>
				</ul>
			</div>

			<div class="card" style="max-width:800px;padding:8px 20px 20px;margin-top:20px;">
				<h2>第二步：在新网站导入</h2>
				<p>上传本插件导出的 ZIP。建议先备份新站数据库和 uploads 目录。</p>
				<form id="maotk-bmm-import-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="maotk_bmm_import">
					<input type="hidden" name="progress_token" value="<?php echo esc_attr( $import_token ); ?>">
					<?php wp_nonce_field( 'maotk_bmm_import' ); ?>
					<p><input type="file" name="migration_package" accept=".zip,application/zip" required></p>
					<p>
						<label>
							<input type="checkbox" name="update_existing" value="1" checked>
							<strong>覆盖已导入或别名相同的文章</strong>（推荐）
						</label>
						<br><span class="description">再次导入时更新正文、分类、标签、特色图和阅读量，避免创建重复文章。</span>
					</p>
					<p>
						<label>
							<input type="checkbox" name="preserve_status" value="1" checked>
							<strong>保留原文章状态</strong>
						</label>
						<br><span class="description">勾选后，已发布仍为已发布、私密仍为私密、草稿仍为草稿。取消后，所有导入文章统一保存为草稿，适合先检查再发布。</span>
					</p>
					<?php submit_button( '开始导入', 'primary', 'submit', false ); ?>
				</form>
				<hr>
				<h3>导入后建议检查</h3>
				<ol>
					<li>打开几篇文章，确认正文图片和特色图能正常显示。</li>
					<li>检查一篇阅读量较高的文章，确认新旧网站数字一致。</li>
					<li>如果迁移了私密文章，请退出管理员账号测试，确认访客无法查看。</li>
				</ol>
			</div>
			<iframe name="maotk-bmm-download-frame" title="文章迁移包下载" style="display:none"></iframe>
			<div id="maotk-bmm-progress" style="display:none;position:fixed;z-index:100000;inset:0;background:rgba(0,0,0,.48);align-items:center;justify-content:center;">
				<div style="position:relative;width:min(560px,calc(100vw - 40px));background:#fff;border-radius:8px;padding:24px;box-shadow:0 12px 50px rgba(0,0,0,.28);">
					<button type="button" id="maotk-bmm-progress-close-x" aria-label="关闭" style="display:none;position:absolute;right:14px;top:12px;border:0;background:transparent;font-size:25px;line-height:1;cursor:pointer;color:#646970">&times;</button>
					<h2 id="maotk-bmm-progress-title" style="margin-top:0">正在处理</h2>
					<p id="maotk-bmm-progress-text">正在准备，请不要关闭页面。</p>
					<div style="height:18px;background:#e5e7eb;border-radius:999px;overflow:hidden;">
						<div id="maotk-bmm-progress-bar" style="height:100%;width:0;background:#2271b1;border-radius:999px;transition:width .35s ease;"></div>
					</div>
					<p style="display:flex;justify-content:space-between;margin:10px 0 0"><strong id="maotk-bmm-progress-percent">0%</strong><span id="maotk-bmm-progress-eta">预计剩余：计算中</span></p>
					<p id="maotk-bmm-progress-time" style="color:#646970;margin-bottom:0">已用时：0 秒</p>
					<p id="maotk-bmm-progress-actions" style="display:none;text-align:right;margin:18px 0 0"><button type="button" class="button button-primary" id="maotk-bmm-progress-close">关闭</button></p>
				</div>
			</div>
		</div>
		<script>
		(function () {
			const overlay = document.getElementById('maotk-bmm-progress');
			const title = document.getElementById('maotk-bmm-progress-title');
			const text = document.getElementById('maotk-bmm-progress-text');
			const bar = document.getElementById('maotk-bmm-progress-bar');
			const time = document.getElementById('maotk-bmm-progress-time');
			const percentText = document.getElementById('maotk-bmm-progress-percent');
			const etaText = document.getElementById('maotk-bmm-progress-eta');
			const closeX = document.getElementById('maotk-bmm-progress-close-x');
			const closeButton = document.getElementById('maotk-bmm-progress-close');
			const actions = document.getElementById('maotk-bmm-progress-actions');
			const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			const progressNonce = <?php echo wp_json_encode( $progress_nonce ); ?>;
			let timer = null;
			let progressPoll = null;
			let startedAt = 0;
			let activeMode = '';

			function closeProgress() {
				overlay.style.display = 'none';
				window.clearInterval(timer);
				window.clearInterval(progressPoll);
			}
			function enableClose() {
				closeX.style.display = 'block';
				actions.style.display = 'block';
			}
			closeX.addEventListener('click', closeProgress);
			closeButton.addEventListener('click', closeProgress);

			function cookieValue(name) {
				const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
				return match ? decodeURIComponent(match[1]) : '';
			}
			function clearCookie(name) {
				document.cookie = name + '=; Max-Age=0; path=<?php echo esc_js( COOKIEPATH ? COOKIEPATH : '/' ); ?>; SameSite=Lax';
			}
			function formatDuration(seconds) {
				seconds = Math.max(0, Math.round(seconds));
				if (seconds < 60) return seconds + ' 秒';
				const minutes = Math.floor(seconds / 60);
				const remain = seconds % 60;
				if (minutes < 60) return minutes + ' 分 ' + remain + ' 秒';
				return Math.floor(minutes / 60) + ' 小时 ' + (minutes % 60) + ' 分';
			}
			async function readProgress(token) {
				try {
					const body = new URLSearchParams({action:'maotk_bmm_progress', nonce:progressNonce, token:token});
					const response = await fetch(ajaxUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body:body.toString()});
					const json = await response.json();
					if (!json.success || !json.data || json.data.status === 'pending') return;
					const data = json.data;
					const total = Math.max(1, Number(data.total) || 1);
					const completed = Math.max(0, Number(data.completed) || 0);
					const percent = data.status === 'finished' ? 100 : Math.min(99, Math.floor(completed / total * 100));
					bar.style.width = percent + '%';
					percentText.textContent = percent + '%（' + completed + ' / ' + total + '）';
					text.textContent = (data.stage || '正在处理') + (data.current ? '：' + data.current : '');
					const elapsed = Math.max(1, Date.now() / 1000 - (Number(data.started_at) || startedAt / 1000));
					const rate = completed / elapsed;
					etaText.textContent = rate > 0 && completed < total ? '预计剩余：' + formatDuration((total - completed) / rate) : (completed >= total ? '预计剩余：0 秒' : '预计剩余：计算中');
					if (data.status === 'finished') {
						title.textContent = activeMode === 'export' ? '导出完成' : '导入完成';
						enableClose();
					}
				} catch (error) {
					etaText.textContent = '预计剩余：等待服务器进度';
				}
			}
			function startProgress(mode, token) {
				let seconds = 0;
				startedAt = Date.now();
				activeMode = mode;
				overlay.style.display = 'flex';
				closeX.style.display = 'none';
				actions.style.display = 'none';
				title.textContent = mode === 'export' ? '正在导出文章' : '正在导入文章';
				text.textContent = mode === 'export'
					? '正在收集文章、阅读量和图片，并生成 ZIP 迁移包。'
					: '正在上传并写入文章、分类、阅读量和图片，请不要刷新页面。';
				bar.style.width = '0%';
				percentText.textContent = '0%';
				etaText.textContent = '预计剩余：计算中';
				timer = window.setInterval(function () {
					seconds++;
					time.textContent = '已用时：' + formatDuration(seconds);
				}, 1000);
				progressPoll = window.setInterval(function () { readProgress(token); }, 800);
				readProgress(token);
			}
			function finishExport(count) {
				window.clearInterval(timer);
				window.clearInterval(progressPoll);
				bar.style.width = '100%';
				percentText.textContent = '100%（' + count + ' / ' + count + '）';
				etaText.textContent = '预计剩余：0 秒';
				enableClose();
				title.textContent = '导出完成';
				text.textContent = '已打包 ' + count + ' 篇文章，浏览器应已开始下载迁移包。请点击关闭按钮。';
			}

			const exportForm = document.getElementById('maotk-bmm-export-form');
			exportForm.addEventListener('submit', function (event) {
				const selected = exportForm.querySelectorAll('input[name="post_statuses[]"]:checked');
				if (!selected.length) {
					event.preventDefault();
					window.alert('请至少选择一种文章状态。');
					return;
				}
				const token = exportForm.querySelector('input[name="export_token"]').value;
				const cookieName = 'maotk_bmm_export_' + token;
				clearCookie(cookieName);
				startProgress('export', token);
				const poll = window.setInterval(function () {
					const result = cookieValue(cookieName);
					if (result.indexOf('done-') === 0) {
						window.clearInterval(poll);
						clearCookie(cookieName);
						finishExport(parseInt(result.substring(5), 10) || 0);
					}
				}, 700);
				window.setTimeout(function () {
					window.clearInterval(poll);
					if (overlay.style.display !== 'none') {
						text.textContent = '处理时间较长。如果浏览器没有下载文件，请检查服务器错误日志或减小一次导出的文章数量。';
					}
				}, 30 * 60 * 1000);
			});

			document.getElementById('maotk-bmm-import-form').addEventListener('submit', function () {
				startProgress('import', this.querySelector('[name="progress_token"]').value);
			});
		}());
		</script>
		<?php
	}

	public static function export() {
		self::require_access();
		check_admin_referer( 'maotk_bmm_export' );
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( '服务器未启用 PHP ZipArchive 扩展。' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		$allowed_statuses = array( 'publish', 'private', 'draft', 'pending', 'future' );
		$requested        = isset( $_POST['post_statuses'] ) ? (array) wp_unslash( $_POST['post_statuses'] ) : array();
		$statuses         = array_values( array_unique( array_intersect( $allowed_statuses, array_map( 'sanitize_key', $requested ) ) ) );
		if ( ! $statuses ) {
			wp_die( '请至少选择一种需要导出的文章状态。', '未选择文章状态', array( 'back_link' => true ) );
		}
		$post_ids = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => $statuses,
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$progress_token = isset( $_POST['export_token'] ) ? sanitize_key( wp_unslash( $_POST['export_token'] ) ) : '';
		$progress_total = max( 1, count( $post_ids ) );
		$progress_done  = 0;
		self::set_progress( $progress_token, 0, $progress_total, '正在准备文章数据' );

		$tmp_zip = wp_tempnam( 'blog-migration.zip' );
		if ( ! $tmp_zip ) {
			wp_die( '无法创建临时文件。' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp_zip );
			wp_die( '无法创建 ZIP 迁移包。' );
		}

		$manifest = array(
			'format'      => 'maotk-blog-media-migrator',
			'version'     => self::VERSION,
			'exported_at' => gmdate( 'c' ),
			'source_url'  => home_url( '/' ),
			'posts'       => array(),
			'assets'      => array(),
		);
		$packed   = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导出文章', '无效文章 ID：' . $post_id );
				continue;
			}
			self::set_progress( $progress_token, $progress_done, $progress_total, '正在打包文章和图片', $post->post_title );
			$asset_urls = self::extract_image_urls( $post->post_content );
			$thumb_id   = get_post_thumbnail_id( $post_id );
			$thumb_url  = $thumb_id ? wp_get_attachment_url( $thumb_id ) : '';
			if ( $thumb_url ) {
				$asset_urls[] = $thumb_url;
			}

			$asset_urls = array_values( array_unique( array_filter( $asset_urls ) ) );
			foreach ( $asset_urls as $asset_url ) {
				self::pack_asset( $zip, $asset_url, $manifest['assets'], $packed );
			}

			$categories = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'names' ) );
			$tags       = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );
			$manifest['posts'][] = array(
				'source_id'        => (int) $post_id,
				'title'            => $post->post_title,
				'slug'             => $post->post_name,
				'content'          => $post->post_content,
				'excerpt'          => $post->post_excerpt,
				'status'           => $post->post_status,
				'date'             => $post->post_date,
				'date_gmt'         => $post->post_date_gmt,
				'modified'         => $post->post_modified,
				'modified_gmt'     => $post->post_modified_gmt,
				'comment_status'   => $post->comment_status,
				'ping_status'      => $post->ping_status,
				'password'         => $post->post_password,
				'menu_order'       => (int) $post->menu_order,
				'categories'       => is_wp_error( $categories ) ? array() : $categories,
				'tags'             => is_wp_error( $tags ) ? array() : $tags,
				'featured_image'   => $thumb_url,
				'reading_meta'     => self::get_reading_meta( $post_id ),
			);
			++$progress_done;
			self::set_progress( $progress_token, $progress_done, $progress_total, '正在导出文章', $post->post_title );
		}

		$json = wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json || ! $zip->addFromString( 'manifest.json', $json ) ) {
			$zip->close();
			@unlink( $tmp_zip );
			wp_die( '无法写入迁移清单。' );
		}
		$zip->close();
		self::set_progress( $progress_token, $progress_total, $progress_total, '导出完成', '', 'finished' );

		$filename = 'wordpress-blog-' . gmdate( 'Y-m-d-His' ) . '.zip';
		nocache_headers();
		$export_token = $progress_token;
		if ( $export_token ) {
			setcookie(
				'maotk_bmm_export_' . $export_token,
				'done-' . count( $post_ids ),
				array(
					'expires'  => time() + 300,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		}
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp_zip ) );
		readfile( $tmp_zip );
		@unlink( $tmp_zip );
		exit;
	}

	private static function extract_image_urls( $content ) {
		$urls = array();
		if ( ! is_string( $content ) || '' === $content ) {
			return $urls;
		}
		$patterns = array(
			'/\b(?:src|data-src|data-original|data-lazy-src)\s*=\s*(["\'])(.*?)\1/is',
			'/\bsrcset\s*=\s*(["\'])(.*?)\1/is',
			'/\burl\(\s*(["\']?)(.*?)\1\s*\)/is',
		);
		if ( preg_match_all( $patterns[0], $content, $matches ) ) {
			foreach ( $matches[2] as $url ) {
				$url = self::normalize_asset_url( $url );
				if ( $url ) {
					$urls[] = $url;
				}
			}
		}
		if ( preg_match_all( $patterns[1], $content, $matches ) ) {
			foreach ( $matches[2] as $srcset ) {
				foreach ( explode( ',', $srcset ) as $candidate ) {
					$parts = preg_split( '/\s+/', trim( $candidate ) );
					$url   = self::normalize_asset_url( isset( $parts[0] ) ? $parts[0] : '' );
					if ( $url ) {
						$urls[] = $url;
					}
				}
			}
		}
		if ( preg_match_all( $patterns[2], $content, $matches ) ) {
			foreach ( $matches[2] as $url ) {
				$url = self::normalize_asset_url( $url );
				if ( $url ) {
					$urls[] = $url;
				}
			}
		}
		return array_values( array_unique( $urls ) );
	}

	private static function normalize_asset_url( $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) || 0 === strpos( $url, 'blob:' ) ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		} elseif ( ! wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	private static function pack_asset( ZipArchive $zip, $url, &$assets, &$packed ) {
		if ( isset( $assets[ $url ] ) ) {
			return;
		}
		$image = self::read_asset( $url );
		if ( is_wp_error( $image ) ) {
			$assets[ $url ] = array(
				'file'  => '',
				'mime'  => '',
				'hash'  => '',
				'error' => $image->get_error_message(),
			);
			return;
		}
		$type = self::detect_image_type( $image['body'], $image['mime'], $url );
		if ( is_wp_error( $type ) ) {
			$assets[ $url ] = array(
				'file'  => '',
				'mime'  => '',
				'hash'  => '',
				'error' => $type->get_error_message(),
			);
			return;
		}
		$packed_bytes = $type['bytes'];
		$hash = hash( 'sha256', $packed_bytes );
		$file = 'assets/' . $hash . '.' . $type['extension'];
		if ( empty( $packed[ $file ] ) ) {
			$zip->addFromString( $file, $packed_bytes );
			$packed[ $file ] = true;
		}
		$assets[ $url ] = array(
			'file' => $file,
			'mime' => $type['mime'],
			'hash' => $hash,
		);
	}

	private static function read_asset( $url ) {
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id ) {
			$path = get_attached_file( $attachment_id );
			if ( $path && is_readable( $path ) && filesize( $path ) <= self::MAX_ASSET_MB * MB_IN_BYTES ) {
				$body = file_get_contents( $path );
				if ( false !== $body && '' !== $body ) {
					return array(
						'body' => $body,
						'mime' => (string) get_post_mime_type( $attachment_id ),
					);
				}
			}
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 30,
				'redirection'         => 5,
				'limit_response_size' => self::MAX_ASSET_MB * MB_IN_BYTES,
				'user-agent'          => 'MaoTK Blog Media Migrator/' . self::VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'http_error', '图片服务器返回状态码 ' . wp_remote_retrieve_response_code( $response ) );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return new WP_Error( 'empty_asset', '图片内容为空' );
		}
		return array(
			'body' => $body,
			'mime' => strtolower( trim( strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' ) ) ),
		);
	}

	private static function detect_image_type( $bytes, $hint_mime, $hint_name ) {
		$info = @getimagesizefromstring( $bytes );
		$map  = array(
			'image/jpeg'               => 'jpg',
			'image/png'                => 'png',
			'image/gif'                => 'gif',
			'image/webp'               => 'webp',
			'image/avif'               => 'avif',
			'image/bmp'                => 'bmp',
			'image/x-ms-bmp'           => 'bmp',
			'image/vnd.microsoft.icon' => 'ico',
			'image/x-icon'             => 'ico',
		);
		if ( is_array( $info ) && ! empty( $info['mime'] ) && isset( $map[ $info['mime'] ] ) ) {
			return array(
				'mime'      => $info['mime'],
				'extension' => $map[ $info['mime'] ],
				'bytes'     => $bytes,
			);
		}
		$trimmed = ltrim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $bytes ) );
		if ( preg_match( '/<svg(?:\s|>)/i', substr( $trimmed, 0, 8192 ) ) ) {
			$clean_svg = self::sanitize_svg( $trimmed );
			if ( is_wp_error( $clean_svg ) ) {
				return $clean_svg;
			}
			return array(
				'mime'      => 'image/svg+xml',
				'extension' => 'svg',
				'bytes'     => $clean_svg,
			);
		}
		return new WP_Error(
			'unsupported_image',
			'无法识别图片格式：' . sanitize_text_field( $hint_mime . ' ' . wp_basename( $hint_name ) )
		);
	}

	private static function sanitize_svg( $svg ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'svg_dom_missing', '服务器缺少 DOM 扩展，无法安全处理 SVG' );
		}
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$loaded   = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $dom->documentElement || 'svg' !== strtolower( $dom->documentElement->localName ) ) {
			return new WP_Error( 'invalid_svg', 'SVG 文件结构无效' );
		}

		$xpath   = new DOMXPath( $dom );
		$blocked = array( 'script', 'style', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'animate', 'animatetransform', 'animatemotion', 'set' );
		foreach ( $blocked as $element ) {
			$nodes = $xpath->query( '//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . $element . '"]' );
			if ( $nodes ) {
				for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
					$node = $nodes->item( $i );
					if ( $node && $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
				}
			}
		}
		$nodes = $xpath->query( '//*' );
		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				for ( $i = $node->attributes->length - 1; $i >= 0; $i-- ) {
					$attribute = $node->attributes->item( $i );
					$name      = strtolower( $attribute->name );
					$value     = trim( html_entity_decode( $attribute->value, ENT_QUOTES, 'UTF-8' ) );
					$is_url    = in_array( $name, array( 'href', 'xlink:href', 'src' ), true );
					$is_safe   = 0 === strpos( $value, '#' ) || (bool) preg_match( '/^data:image\/(?:png|jpeg|gif|webp|avif|bmp);base64,/i', $value );
					if (
						0 === strpos( $name, 'on' ) ||
						( $is_url && ! $is_safe ) ||
						( 'style' === $name && preg_match( '/(?:expression\s*\(|javascript\s*:|url\s*\(\s*["\']?\s*(?:javascript|data:text\/html))/i', $value ) )
					) {
						$node->removeAttributeNode( $attribute );
					}
				}
			}
		}
		$clean = $dom->saveXML( $dom->documentElement );
		return $clean ? $clean : new WP_Error( 'svg_save_failed', '无法保存清理后的 SVG' );
	}

	private static function get_reading_meta( $post_id ) {
		$all      = get_post_meta( $post_id );
		$selected = array();
		$known    = array(
			'views',
			'_views',
			'post_views',
			'post_views_count',
			'_post_views',
			'view_count',
			'views_count',
			'zib_post_views',
			'zibll_views',
			'read_count',
			'reading_count',
			'hits',
		);
		foreach ( $all as $key => $values ) {
			$is_view_key = in_array( $key, $known, true ) || preg_match( '/(?:^|_)(?:view|views|read|reading|hit|hits)(?:_|$)/i', $key );
			if ( ! $is_view_key || ! is_array( $values ) ) {
				continue;
			}
			$clean_values = array();
			foreach ( $values as $value ) {
				if ( is_scalar( $value ) && strlen( (string) $value ) <= 1000 ) {
					$clean_values[] = (string) $value;
				}
			}
			if ( $clean_values ) {
				$selected[ $key ] = $clean_values;
			}
		}
		return $selected;
	}

	public static function import() {
		self::require_access();
		check_admin_referer( 'maotk_bmm_import' );
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( '服务器未启用 PHP ZipArchive 扩展。' );
		}
		if (
			empty( $_FILES['migration_package']['tmp_name'] ) ||
			UPLOAD_ERR_OK !== (int) $_FILES['migration_package']['error'] ||
			! is_uploaded_file( $_FILES['migration_package']['tmp_name'] )
		) {
			wp_die( '迁移包上传失败。' );
		}
		if ( (int) $_FILES['migration_package']['size'] > self::MAX_PACKAGE_MB * MB_IN_BYTES ) {
			wp_die( '迁移包不能超过 ' . self::MAX_PACKAGE_MB . 'MB。' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $_FILES['migration_package']['tmp_name'] ) ) {
			wp_die( '无法打开 ZIP 迁移包。' );
		}
		if ( $zip->numFiles > self::MAX_PACKAGE_FILES ) {
			$zip->close();
			wp_die( '迁移包内文件数量异常。' );
		}
		$stat = $zip->statName( 'manifest.json' );
		if ( ! $stat || (int) $stat['size'] > self::MAX_MANIFEST_MB * MB_IN_BYTES ) {
			$zip->close();
			wp_die( '迁移清单不存在或体积异常。' );
		}
		$manifest = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
		if (
			! is_array( $manifest ) ||
			'maotk-blog-media-migrator' !== ( isset( $manifest['format'] ) ? $manifest['format'] : '' ) ||
			! isset( $manifest['posts'], $manifest['assets'] ) ||
			! is_array( $manifest['posts'] ) ||
			! is_array( $manifest['assets'] )
		) {
			$zip->close();
			wp_die( '这不是有效的博客迁移包。' );
		}

		$result = array(
			'created'       => 0,
			'updated'       => 0,
			'skipped'       => 0,
			'images'        => 0,
			'failed_posts'  => array(),
			'failed_images' => array(),
			'errors'        => array(),
		);
		$update_existing = ! empty( $_POST['update_existing'] );
		$preserve_status = ! empty( $_POST['preserve_status'] );
		$progress_token  = isset( $_POST['progress_token'] ) ? sanitize_key( wp_unslash( $_POST['progress_token'] ) ) : '';
		$progress_total  = max( 1, count( $manifest['assets'] ) + count( $manifest['posts'] ) );
		$progress_done   = 0;
		self::set_progress( $progress_token, 0, $progress_total, '正在准备导入' );
		$asset_map       = self::import_assets( $zip, $manifest['assets'], $result, $progress_token, $progress_done, $progress_total );
		$source_url      = isset( $manifest['source_url'] ) ? esc_url_raw( $manifest['source_url'] ) : '';

		foreach ( $manifest['posts'] as $index => $item ) {
			$current_title = is_array( $item ) && ! empty( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '第 ' . ( $index + 1 ) . ' 条文章';
			self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入文章', $current_title );
			if ( ! is_array( $item ) ) {
				$result['failed_posts'][] = array(
					'title'  => '第 ' . ( $index + 1 ) . ' 条文章',
					'reason' => '迁移包中的文章数据格式无效。',
				);
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入文章', $current_title );
				continue;
			}
			$title     = sanitize_text_field( isset( $item['title'] ) ? $item['title'] : '' );
			$slug      = sanitize_title( isset( $item['slug'] ) ? $item['slug'] : '' );
			$source_id = (int) ( isset( $item['source_id'] ) ? $item['source_id'] : 0 );
			if ( '' === $title ) {
				$result['failed_posts'][] = array(
					'title'  => '第 ' . ( $index + 1 ) . ' 条文章',
					'reason' => '文章缺少标题。',
				);
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入文章', $current_title );
				continue;
			}

			$existing_id = self::find_existing_post( $source_url, $source_id, $slug );
			if ( $existing_id && ! $update_existing ) {
				++$result['skipped'];
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入文章', $title );
				continue;
			}

			$content = isset( $item['content'] ) ? (string) $item['content'] : '';
			foreach ( $asset_map as $old_url => $asset ) {
				if ( ! empty( $asset['url'] ) ) {
					$content = str_replace(
						array( $old_url, esc_attr( $old_url ), esc_url( $old_url ) ),
						$asset['url'],
						$content
					);
				}
			}

			$status = $preserve_status ? self::safe_post_status( isset( $item['status'] ) ? $item['status'] : 'draft' ) : 'draft';
			$data   = array(
				'post_type'      => 'post',
				'post_title'     => $title,
				'post_name'      => $slug,
				'post_content'   => $content,
				'post_excerpt'   => isset( $item['excerpt'] ) ? (string) $item['excerpt'] : '',
				'post_status'    => $status,
				'post_date'      => self::safe_mysql_date( isset( $item['date'] ) ? $item['date'] : '' ),
				'post_date_gmt'  => self::safe_mysql_date( isset( $item['date_gmt'] ) ? $item['date_gmt'] : '' ),
				'comment_status' => in_array( isset( $item['comment_status'] ) ? $item['comment_status'] : '', array( 'open', 'closed' ), true ) ? $item['comment_status'] : 'open',
				'ping_status'    => in_array( isset( $item['ping_status'] ) ? $item['ping_status'] : '', array( 'open', 'closed' ), true ) ? $item['ping_status'] : 'open',
				'post_password'  => sanitize_text_field( isset( $item['password'] ) ? $item['password'] : '' ),
				'menu_order'     => (int) ( isset( $item['menu_order'] ) ? $item['menu_order'] : 0 ),
			);
			if ( $existing_id ) {
				$data['ID'] = $existing_id;
				$post_id    = wp_update_post( wp_slash( $data ), true );
			} else {
				$post_id = wp_insert_post( wp_slash( $data ), true );
			}
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				$result['failed_posts'][] = array(
					'title'  => $title,
					'reason' => '文章写入失败：' . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : '数据库未返回文章 ID' ),
				);
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入文章', $title );
				continue;
			}

			update_post_meta( $post_id, self::SOURCE_META, self::source_key( $source_url, $source_id ) );
			self::set_terms( $post_id, 'category', isset( $item['categories'] ) ? $item['categories'] : array(), $title, $result );
			self::set_terms( $post_id, 'post_tag', isset( $item['tags'] ) ? $item['tags'] : array(), $title, $result );
			self::restore_reading_meta( $post_id, isset( $item['reading_meta'] ) ? $item['reading_meta'] : array() );

			$featured = isset( $item['featured_image'] ) ? $item['featured_image'] : '';
			if ( $featured && ! empty( $asset_map[ $featured ]['attachment_id'] ) ) {
				set_post_thumbnail( $post_id, (int) $asset_map[ $featured ]['attachment_id'] );
			} elseif ( $existing_id ) {
				delete_post_thumbnail( $post_id );
			}

			if ( $existing_id ) {
				++$result['updated'];
			} else {
				++$result['created'];
			}
			++$progress_done;
			self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入文章', $title );
		}

		$zip->close();
		self::set_progress( $progress_token, $progress_total, $progress_total, '导入完成', '', 'finished' );
		set_transient( 'maotk_bmm_result_' . get_current_user_id(), $result, 15 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE ) );
		exit;
	}

	private static function import_assets( ZipArchive $zip, $assets, &$result, $progress_token, &$progress_done, $progress_total ) {
		$map = array();
		foreach ( $assets as $old_url => $asset ) {
			self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入图片', wp_basename( (string) wp_parse_url( $old_url, PHP_URL_PATH ) ) );
			$old_url = esc_url_raw( $old_url, array( 'http', 'https' ) );
			if ( ! $old_url || ! is_array( $asset ) || empty( $asset['file'] ) ) {
				if ( $old_url && ! empty( $asset['error'] ) ) {
					$result['failed_images'][] = array(
						'name'   => wp_basename( (string) wp_parse_url( $old_url, PHP_URL_PATH ) ),
						'url'    => $old_url,
						'reason' => '旧站导出时未能打包：' . sanitize_text_field( $asset['error'] ),
					);
				}
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入图片', wp_basename( (string) wp_parse_url( $old_url, PHP_URL_PATH ) ) );
				continue;
			}
			$imported = self::import_one_asset( $zip, $asset, $old_url );
			if ( is_wp_error( $imported ) ) {
				$result['failed_images'][] = array(
					'name'   => wp_basename( (string) wp_parse_url( $old_url, PHP_URL_PATH ) ),
					'url'    => $old_url,
					'reason' => $imported->get_error_message(),
				);
				++$progress_done;
				self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入图片', wp_basename( (string) wp_parse_url( $old_url, PHP_URL_PATH ) ) );
				continue;
			}
			$map[ $old_url ] = $imported;
			if ( ! empty( $imported['created'] ) ) {
				++$result['images'];
			}
			++$progress_done;
			self::set_progress( $progress_token, $progress_done, $progress_total, '正在导入图片', wp_basename( (string) wp_parse_url( $old_url, PHP_URL_PATH ) ) );
		}
		return $map;
	}

	private static function import_one_asset( ZipArchive $zip, $asset, $old_url ) {
		$file = ltrim( str_replace( '\\', '/', (string) $asset['file'] ), '/' );
		if ( 0 !== strpos( $file, 'assets/' ) || false !== strpos( $file, '../' ) || false !== strpos( $file, "\0" ) ) {
			return new WP_Error( 'unsafe_path', '文件路径不安全' );
		}
		$stat = $zip->statName( $file );
		if ( ! $stat || (int) $stat['size'] <= 0 || (int) $stat['size'] > self::MAX_ASSET_MB * MB_IN_BYTES ) {
			return new WP_Error( 'invalid_size', '文件不存在、为空或过大' );
		}
		$bytes = $zip->getFromName( $file );
		if ( false === $bytes ) {
			return new WP_Error( 'read_failed', '无法读取文件' );
		}
		$type = self::detect_image_type( $bytes, isset( $asset['mime'] ) ? $asset['mime'] : '', $file );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$bytes = $type['bytes'];
		$hash = hash( 'sha256', $bytes );
		$ids  = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => self::HASH_META,
				'meta_value'     => $hash,
				'no_found_rows'  => true,
			)
		);
		if ( $ids ) {
			$url = wp_get_attachment_url( $ids[0] );
			if ( $url ) {
				return array(
					'url'           => $url,
					'attachment_id' => (int) $ids[0],
					'created'       => false,
				);
			}
		}

		$base     = sanitize_file_name( pathinfo( (string) wp_parse_url( $old_url, PHP_URL_PATH ), PATHINFO_FILENAME ) );
		$base     = $base ? $base : 'article-image';
		$filename = $base . '-' . substr( $hash, 0, 8 ) . '.' . $type['extension'];
		$upload   = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_failed', $upload['error'] );
		}
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $type['mime'],
				'post_title'     => sanitize_text_field( $base ),
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			@unlink( $upload['file'] );
			return is_wp_error( $attachment_id ) ? $attachment_id : new WP_Error( 'attachment_failed', '无法创建媒体库记录' );
		}
		update_post_meta( $attachment_id, self::HASH_META, $hash );
		update_post_meta( $attachment_id, self::MANAGED_META, '1' );
		if ( 'image/svg+xml' !== $type['mime'] ) {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			if ( ! is_wp_error( $metadata ) && $metadata ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}
		}
		return array(
			'url'           => $upload['url'],
			'attachment_id' => (int) $attachment_id,
			'created'       => true,
		);
	}

	private static function source_key( $source_url, $source_id ) {
		return hash( 'sha256', untrailingslashit( (string) $source_url ) . '|' . (int) $source_id );
	}

	private static function find_existing_post( $source_url, $source_id, $slug ) {
		if ( $source_url && $source_id ) {
			$ids = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'meta_key'       => self::SOURCE_META,
					'meta_value'     => self::source_key( $source_url, $source_id ),
					'no_found_rows'  => true,
				)
			);
			if ( $ids ) {
				return (int) $ids[0];
			}
		}
		if ( $slug ) {
			$post = get_page_by_path( $slug, OBJECT, 'post' );
			if ( $post ) {
				return (int) $post->ID;
			}
		}
		return 0;
	}

	private static function safe_post_status( $status ) {
		return in_array( $status, array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ? $status : 'draft';
	}

	private static function safe_mysql_date( $date ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $date ) ? $date : current_time( 'mysql' );
	}

	private static function set_terms( $post_id, $taxonomy, $names, $title, &$result ) {
		$term_ids = array();
		foreach ( (array) $names as $name ) {
			$name = sanitize_text_field( $name );
			if ( '' === $name ) {
				continue;
			}
			$term = term_exists( $name, $taxonomy );
			if ( ! $term ) {
				$term = wp_insert_term( $name, $taxonomy );
			}
			if ( is_wp_error( $term ) ) {
				$result['errors'][] = $title . '：无法创建分类或标签“' . $name . '”。';
				continue;
			}
			$term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}
		$set = wp_set_object_terms( $post_id, array_values( array_unique( $term_ids ) ), $taxonomy, false );
		if ( is_wp_error( $set ) ) {
			$result['errors'][] = $title . '：分类或标签更新失败。';
		}
	}

	private static function restore_reading_meta( $post_id, $meta ) {
		if ( ! is_array( $meta ) ) {
			return;
		}
		foreach ( $meta as $key => $values ) {
			$key = sanitize_key( $key );
			if ( ! $key || ! is_array( $values ) ) {
				continue;
			}
			delete_post_meta( $post_id, $key );
			foreach ( $values as $value ) {
				if ( is_scalar( $value ) && strlen( (string) $value ) <= 1000 ) {
					add_post_meta( $post_id, $key, (string) $value );
				}
			}
		}
	}
}

MaoTK_Blog_Media_Migrator::init();
