<?php
/**
 * Plugin Name:       Agent Native WP
 * Plugin URI:        https://agent-native.site
 * Description:       Serves a clean, structured "agent-native" version of your content to AI agents (ChatGPT, Claude, Perplexity, …) while humans keep the normal theme. Per-page Markdown, llms.txt / llms-full.txt, JSON-LD, agent rate-limiting and traffic logging.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            otterbit.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agent-native
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGENT_NATIVE_VERSION', '1.0.0' );
define( 'AGENT_NATIVE_OPTION', 'agent_native_options' );
define( 'AGENT_NATIVE_DB_VERSION', '1' );
define( 'AGENT_NATIVE_DB_OPTION', 'agent_native_db_version' );

final class Agent_Native {

	/** @var Agent_Native|null */
	private static $instance = null;

	public static function instance(): Agent_Native {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_action( 'init', array( $this, 'maybe_upgrade' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_llms' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_serve_agent_view' ), 1 );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 10, 2 );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_agent_native_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_agent_native_export_log', array( $this, 'handle_export_log' ) );

		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
	}

	// Options

	public static function default_options(): array {
		return array(
			'enabled'        => 1,
			'agent_uas'      => implode( "\n", array(
				'GPTBot', 'ChatGPT-User', 'OAI-SearchBot',
				'ClaudeBot', 'Claude-Web', 'Claude-User', 'anthropic-ai',
				'PerplexityBot', 'Perplexity-User',
				'Google-Extended', 'GoogleOther',
				'CCBot', 'Bytespider', 'Amazonbot', 'Applebot-Extended',
				'meta-externalagent', 'cohere-ai', 'YouBot', 'DuckAssistBot',
			) ),
			'format'         => 'html',   // default when no ?format override
			'add_jsonld'     => 1,
			'add_llms_txt'   => 1,
			'add_llms_full'  => 1,
			'add_to_robots'  => 1,
			'llms_post_type' => 'post',
			'llms_limit'     => 100,

			// Rate limiting (real agents only).
			'rl_enabled'     => 0,
			'rl_limit'       => 60,       // requests
			'rl_window'      => 60,       // per seconds

			// Logging.
			'log_enabled'    => 1,
			'log_retention'  => 30,       // days
			'ip_mode'        => 'mask',   // full | mask | hash | none
		);
	}

	public static function get_options(): array {
		$saved = get_option( AGENT_NATIVE_OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_options() );
	}

	// Activation / upgrade / schema

	public static function activate(): void {
		self::instance()->add_rewrite();
		self::create_table();
		update_option( AGENT_NATIVE_DB_OPTION, AGENT_NATIVE_DB_VERSION );
		flush_rewrite_rules();
	}

	public function maybe_upgrade(): void {
		if ( get_option( AGENT_NATIVE_DB_OPTION ) !== AGENT_NATIVE_DB_VERSION ) {
			self::create_table();
			update_option( AGENT_NATIVE_DB_OPTION, AGENT_NATIVE_DB_VERSION );
		}
	}

	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'agent_native_log';
	}

	private static function create_table(): void {
		global $wpdb;
		$table           = self::log_table();
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ts DATETIME NOT NULL,
			agent VARCHAR(120) NOT NULL DEFAULT '',
			ua VARCHAR(255) NOT NULL DEFAULT '',
			ip VARCHAR(64) NOT NULL DEFAULT '',
			url VARCHAR(255) NOT NULL DEFAULT '',
			status SMALLINT NOT NULL DEFAULT 200,
			format VARCHAR(16) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY ts (ts),
			KEY agent (agent)
		) {$charset_collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// Detection

	public function get_agent_list(): array {
		$opts = self::get_options();
		$raw  = isset( $opts['agent_uas'] ) ? (string) $opts['agent_uas'] : '';
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) ) );
	}

	/** Return the matched UA needle for a real agent, or null. */
	public function matched_agent(): ?string {
		$opts = self::get_options();
		if ( empty( $opts['enabled'] ) ) {
			return null;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ( '' === $ua ) {
			return null;
		}
		foreach ( $this->get_agent_list() as $needle ) {
			if ( '' !== $needle && false !== stripos( $ua, $needle ) ) {
				return $needle;
			}
		}
		return null;
	}

	/** Explicit override for testing / integrations. */
	public function is_forced(): bool {
		if ( isset( $_GET['agent_view'] ) && '1' === $_GET['agent_view'] ) {
			return true;
		}
		if ( isset( $_GET['format'] ) && in_array( strtolower( (string) $_GET['format'] ), array( 'md', 'markdown', 'html' ), true ) ) {
			return true;
		}
		return false;
	}

	/** Which format to render for this request. */
	public function request_format(): string {
		if ( isset( $_GET['format'] ) ) {
			$f = strtolower( (string) $_GET['format'] );
			if ( 'md' === $f || 'markdown' === $f ) {
				return 'markdown';
			}
			if ( 'html' === $f ) {
				return 'html';
			}
		}
		$opts = self::get_options();
		return 'markdown' === $opts['format'] ? 'markdown' : 'html';
	}

	// Client IP + privacy

	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** Apply the configured privacy mode before storing an IP. */
	private function store_ip( string $ip ): string {
		$mode = self::get_options()['ip_mode'];
		if ( '' === $ip || 'none' === $mode ) {
			return '';
		}
		if ( 'full' === $mode ) {
			return $ip;
		}
		if ( 'hash' === $mode ) {
			return substr( hash( 'sha256', $ip . wp_salt( 'auth' ) ), 0, 32 );
		}
		// mask
		if ( false !== strpos( $ip, ':' ) ) {           // IPv6
			$parts = explode( ':', $ip );
			$parts = array_slice( $parts, 0, 3 );
			return implode( ':', $parts ) . '::';
		}
		$parts = explode( '.', $ip );                    // IPv4
		if ( 4 === count( $parts ) ) {
			$parts[3] = '0';
			return implode( '.', $parts );
		}
		return '';
	}

	/* Rate limiting (fixed window per IP, real agents only)
	   Returns 0 if allowed, or Retry-After seconds if limited. */

	private function rate_limited(): int {
		$opts = self::get_options();
		if ( empty( $opts['rl_enabled'] ) ) {
			return 0;
		}
		$limit  = max( 1, (int) $opts['rl_limit'] );
		$window = max( 1, (int) $opts['rl_window'] );
		$ip     = $this->client_ip();
		if ( '' === $ip ) {
			return 0;
		}

		$key  = 'an_rl_' . md5( $ip );
		$now  = time();
		$data = get_transient( $key );

		if ( false === $data || ! is_array( $data ) ) {
			set_transient( $key, array( 'start' => $now, 'count' => 1 ), $window );
			return 0;
		}

		$remaining = max( 1, $window - ( $now - (int) $data['start'] ) );

		if ( (int) $data['count'] >= $limit ) {
			return $remaining;
		}

		$data['count'] = (int) $data['count'] + 1;
		set_transient( $key, $data, $remaining );
		return 0;
	}

	// Logging

	private function log_hit( string $agent, int $status, string $format ): void {
		$opts = self::get_options();
		if ( empty( $opts['log_enabled'] ) ) {
			return;
		}
		global $wpdb;

		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$url = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

		$wpdb->insert(
			self::log_table(),
			array(
				'ts'     => current_time( 'mysql' ),
				'agent'  => substr( $agent, 0, 120 ),
				'ua'     => substr( $ua, 0, 255 ),
				'ip'     => substr( $this->store_ip( $this->client_ip() ), 0, 64 ),
				'url'    => substr( $url, 0, 255 ),
				'status' => $status,
				'format' => substr( $format, 0, 16 ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		// Occasional pruning to keep the table small.
		if ( 1 === wp_rand( 1, 25 ) ) {
			$days = max( 1, (int) $opts['log_retention'] );
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM " . self::log_table() . " WHERE ts < ( NOW() - INTERVAL %d DAY )",
				$days
			) );
		}
	}

	// Rewrites: /llms.txt and /llms-full.txt

	public function add_rewrite(): void {
		add_rewrite_rule( '^llms\.txt$', 'index.php?agent_native_llms=index', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?agent_native_llms=full', 'top' );
		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'agent_native_llms';
			return $vars;
		} );
	}

	public function robots_txt( $output, $public ) {
		$opts = self::get_options();
		if ( empty( $opts['add_to_robots'] ) || empty( $opts['add_llms_txt'] ) || ! $public ) {
			return $output;
		}
		$output .= "\n# Agent Native — machine-readable index for AI agents\n";
		$output .= '# LLMs: ' . home_url( '/llms.txt' ) . "\n";
		if ( ! empty( $opts['add_llms_full'] ) ) {
			$output .= '# LLMs-Full: ' . home_url( '/llms-full.txt' ) . "\n";
		}
		return $output;
	}

	public function maybe_serve_llms(): void {
		$mode = (string) get_query_var( 'agent_native_llms' );
		if ( '' === $mode ) {
			return;
		}
		$opts = self::get_options();
		if ( empty( $opts['add_llms_txt'] ) ) {
			return;
		}
		if ( 'full' === $mode && empty( $opts['add_llms_full'] ) ) {
			return;
		}

		header( 'Content-Type: text/' . ( 'full' === $mode ? 'markdown' : 'plain' ) . '; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		$site_name = get_bloginfo( 'name' );
		$tagline   = get_bloginfo( 'description' );
		$full      = ( 'full' === $mode );

		echo '# ' . $this->one_line( $site_name ) . "\n\n";
		if ( $tagline ) {
			echo '> ' . $this->one_line( $tagline ) . "\n\n";
		}
		echo 'Machine-readable ' . ( $full ? 'full-text export' : 'index' ) . ' of '
			. $this->one_line( $site_name ) . ". Add ?format=md to any page for Markdown.\n\n";

		$query = new WP_Query( array(
			'post_type'      => $opts['llms_post_type'],
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, (int) $opts['llms_limit'] ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		if ( ! $full ) {
			echo "## Pages\n\n";
		}

		while ( $query->have_posts() ) {
			$query->the_post();
			$post = get_post();
			if ( $full ) {
				echo "\n---\n\n";
				echo '# ' . $this->one_line( get_the_title() ) . "\n\n";
				echo 'URL: ' . esc_url_raw( get_permalink() ) . "\n\n";
				echo $this->html_to_markdown( $this->clean_content( $post ) ) . "\n";
			} else {
				$excerpt = $this->one_line( wp_strip_all_tags( get_the_excerpt() ) );
				$excerpt = $excerpt ? ': ' . wp_trim_words( $excerpt, 25, '…' ) : '';
				echo '- [' . $this->one_line( get_the_title() ) . '](' . esc_url_raw( get_permalink() ) . ')' . $excerpt . "\n";
			}
		}
		wp_reset_postdata();
		exit;
	}

	private function one_line( string $s ): string {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $s ) ) );
	}

	// Agent view for singular content

	public function maybe_serve_agent_view(): void {
		if ( is_admin() || is_feed() || is_robots() || is_trackback() ) {
			return;
		}

		$real   = $this->matched_agent();       // null or needle
		$forced = $this->is_forced();

		if ( null === $real && ! $forced ) {
			return; // humans → normal theme
		}
		if ( ! is_singular() ) {
			return; // only take over singular content
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$format = $this->request_format();

		// Rate limit real agents only (never block manual test hits).
		if ( null !== $real ) {
			$retry = $this->rate_limited();
			if ( $retry > 0 ) {
				status_header( 429 );
				nocache_headers();
				header( 'Retry-After: ' . $retry );
				header( 'Content-Type: text/plain; charset=utf-8' );
				$this->log_hit( $real, 429, $format );
				echo "429 Too Many Requests\nRetry-After: " . (int) $retry . "\n";
				exit;
			}
		}

		nocache_headers();
		header( 'X-Agent-Native: 1' );
		header( 'Vary: User-Agent' );

		if ( 'markdown' === $format ) {
			$this->render_markdown( $post );
		} else {
			$this->render_html( $post, ! empty( self::get_options()['add_jsonld'] ) );
		}

		$this->log_hit( $real ?? 'manual', 200, $format );
		exit;
	}

	private function clean_content( WP_Post $post ): string {
		$html = apply_filters( 'the_content', $post->post_content );
		$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
		$html = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $html );
		$html = preg_replace( '#<iframe\b[^>]*>.*?</iframe>#is', '', $html );
		$html = preg_replace( '#\son\w+="[^"]*"#i', '', $html );
		return trim( (string) $html );
	}

	private function render_html( WP_Post $post, bool $jsonld ): void {
		header( 'Content-Type: text/html; charset=utf-8' );

		$title     = get_the_title( $post );
		$permalink = get_permalink( $post );
		$author    = get_the_author_meta( 'display_name', $post->post_author );
		$published = get_the_date( 'c', $post );
		$modified  = get_the_modified_date( 'c', $post );
		$excerpt   = wp_strip_all_tags( get_the_excerpt( $post ) );
		$content   = $this->clean_content( $post );
		$site_name = get_bloginfo( 'name' );

		echo "<!DOCTYPE html>\n<html lang=\"" . esc_attr( get_bloginfo( 'language' ) ) . "\">\n<head>\n";
		echo '<meta charset="utf-8">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		echo '<title>' . esc_html( $title ) . ' – ' . esc_html( $site_name ) . "</title>\n";
		if ( $excerpt ) {
			echo '<meta name="description" content="' . esc_attr( wp_trim_words( $excerpt, 30, '…' ) ) . "\">\n";
		}
		echo '<link rel="canonical" href="' . esc_url( $permalink ) . "\">\n";
		echo '<link rel="alternate" type="text/markdown" href="' . esc_url( add_query_arg( 'format', 'md', $permalink ) ) . "\">\n";

		if ( $jsonld ) {
			$schema = array(
				'@context'      => 'https://schema.org',
				'@type'         => ( 'page' === $post->post_type ) ? 'WebPage' : 'Article',
				'headline'      => $title,
				'url'           => $permalink,
				'datePublished' => $published,
				'dateModified'  => $modified,
				'author'        => array( '@type' => 'Person', 'name' => $author ),
				'publisher'     => array( '@type' => 'Organization', 'name' => $site_name ),
				'description'   => $excerpt,
			);
			echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
		}

		echo "</head>\n<body>\n<main>\n<article>\n";
		echo '<h1>' . esc_html( $title ) . "</h1>\n";
		echo '<p><small>By ' . esc_html( $author )
			. ' · Published ' . esc_html( get_the_date( 'Y-m-d', $post ) )
			. ' · Updated ' . esc_html( get_the_modified_date( 'Y-m-d', $post ) )
			. ' · <a href="' . esc_url( $permalink ) . '">Canonical</a>'
			. "</small></p>\n";
		echo $content . "\n";
		echo "</article>\n</main>\n</body>\n</html>";
	}

	private function render_markdown( WP_Post $post ): void {
		header( 'Content-Type: text/markdown; charset=utf-8' );
		$permalink = get_permalink( $post );
		echo '# ' . $this->one_line( get_the_title( $post ) ) . "\n\n";
		echo '_By ' . $this->one_line( get_the_author_meta( 'display_name', $post->post_author ) )
			. ' · ' . get_the_date( 'Y-m-d', $post )
			. ' · [Canonical](' . esc_url_raw( $permalink ) . ")_\n\n";
		echo $this->html_to_markdown( $this->clean_content( $post ) ) . "\n";
	}

	private function html_to_markdown( string $html ): string {
		$md = $html;
		$md = preg_replace( '#<h1[^>]*>(.*?)</h1>#is', "\n# $1\n", $md );
		$md = preg_replace( '#<h2[^>]*>(.*?)</h2>#is', "\n## $1\n", $md );
		$md = preg_replace( '#<h3[^>]*>(.*?)</h3>#is', "\n### $1\n", $md );
		$md = preg_replace( '#<h4[^>]*>(.*?)</h4>#is', "\n#### $1\n", $md );
		$md = preg_replace( '#<(strong|b)[^>]*>(.*?)</\1>#is', '**$2**', $md );
		$md = preg_replace( '#<(em|i)[^>]*>(.*?)</\1>#is', '_$2_', $md );
		$md = preg_replace( '#<code[^>]*>(.*?)</code>#is', '`$1`', $md );
		$md = preg_replace( '#<blockquote[^>]*>(.*?)</blockquote>#is', "\n> $1\n", $md );
		$md = preg_replace_callback( '#<a[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', function ( $m ) {
			return '[' . trim( wp_strip_all_tags( $m[2] ) ) . '](' . $m[1] . ')';
		}, $md );
		$md = preg_replace_callback( '#<img[^>]*>#is', function ( $m ) {
			$alt = preg_match( '#alt="([^"]*)"#i', $m[0], $a ) ? $a[1] : '';
			$src = preg_match( '#src="([^"]*)"#i', $m[0], $s ) ? $s[1] : '';
			return $src ? "![$alt]($src)" : '';
		}, $md );
		$md = preg_replace( '#<li[^>]*>(.*?)</li>#is', "- $1\n", $md );
		$md = preg_replace( '#</?(ul|ol)[^>]*>#i', "\n", $md );
		$md = preg_replace( '#<p[^>]*>(.*?)</p>#is', "\n$1\n", $md );
		$md = preg_replace( '#<br\s*/?>#i', "\n", $md );
		$md = wp_strip_all_tags( $md );
		$md = html_entity_decode( $md, ENT_QUOTES, 'UTF-8' );
		$md = preg_replace( "/\n{3,}/", "\n\n", $md );
		return trim( $md );
	}

	// Admin

	public function admin_menu(): void {
		add_options_page( 'Agent Native', 'Agent Native', 'manage_options', 'agent-native', array( $this, 'settings_page' ) );
		add_management_page( 'Agent Native – Monitoring', 'Agent Native Log', 'manage_options', 'agent-native-log', array( $this, 'monitoring_page' ) );
	}

	public function register_settings(): void {
		register_setting( 'agent_native', AGENT_NATIVE_OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize' ),
			'default'           => self::default_options(),
		) );
	}

	public function sanitize( $input ): array {
		$d   = self::default_options();
		$out = $d;

		$out['enabled']       = empty( $input['enabled'] ) ? 0 : 1;
		$out['add_jsonld']    = empty( $input['add_jsonld'] ) ? 0 : 1;
		$out['add_llms_txt']  = empty( $input['add_llms_txt'] ) ? 0 : 1;
		$out['add_llms_full'] = empty( $input['add_llms_full'] ) ? 0 : 1;
		$out['add_to_robots'] = empty( $input['add_to_robots'] ) ? 0 : 1;
		$out['rl_enabled']    = empty( $input['rl_enabled'] ) ? 0 : 1;
		$out['log_enabled']   = empty( $input['log_enabled'] ) ? 0 : 1;

		$out['format']        = ( isset( $input['format'] ) && 'markdown' === $input['format'] ) ? 'markdown' : 'html';
		$out['agent_uas']     = isset( $input['agent_uas'] ) ? sanitize_textarea_field( $input['agent_uas'] ) : $d['agent_uas'];
		$out['llms_post_type']= isset( $input['llms_post_type'] ) ? sanitize_key( $input['llms_post_type'] ) : 'post';
		$out['llms_limit']    = isset( $input['llms_limit'] ) ? max( 1, (int) $input['llms_limit'] ) : 100;

		$out['rl_limit']      = isset( $input['rl_limit'] ) ? max( 1, (int) $input['rl_limit'] ) : 60;
		$out['rl_window']     = isset( $input['rl_window'] ) ? max( 1, (int) $input['rl_window'] ) : 60;
		$out['log_retention'] = isset( $input['log_retention'] ) ? max( 1, (int) $input['log_retention'] ) : 30;

		$ip_modes             = array( 'full', 'mask', 'hash', 'none' );
		$out['ip_mode']       = ( isset( $input['ip_mode'] ) && in_array( $input['ip_mode'], $ip_modes, true ) ) ? $input['ip_mode'] : 'mask';

		flush_rewrite_rules();
		return $out;
	}

	public function settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o          = self::get_options();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$n          = esc_attr( AGENT_NATIVE_OPTION );
		?>
		<div class="wrap">
			<h1>Agent Native WP</h1>
			<p>Delivers a clean, structured version of your contents to AI agents. Humans still see your normal theme.</p>
			<p>
				<strong>Test:</strong> Add <code>?agent_view=1</code> or <code>?format=md</code> to a post url ·
				Index: <a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank"><code>/llms.txt</code></a>
				<?php if ( ! empty( $o['add_llms_full'] ) ) : ?> · Full-text: <a href="<?php echo esc_url( home_url( '/llms-full.txt' ) ); ?>" target="_blank"><code>/llms-full.txt</code></a><?php endif; ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'agent_native' ); ?>

				<h2 class="title">Basics</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Plugin active</th>
						<td><label><input type="checkbox" name="<?php echo $n; ?>[enabled]" value="1" <?php checked( $o['enabled'], 1 ); ?>> Enable Agent-detection</label></td></tr>
					<tr><th scope="row">Default output format</th>
						<td><select name="<?php echo $n; ?>[format]">
							<option value="html" <?php selected( $o['format'], 'html' ); ?>>Clean HTML + JSON-LD</option>
							<option value="markdown" <?php selected( $o['format'], 'markdown' ); ?>>Markdown</option>
						</select> <span class="description">Can be overwritten per request with <code>?format=md</code> / <code>?format=html</code></span></td></tr>
					<tr><th scope="row">JSON-LD</th>
						<td><label><input type="checkbox" name="<?php echo $n; ?>[add_jsonld]" value="1" <?php checked( $o['add_jsonld'], 1 ); ?>> Embed Schema.org-metadata in HTML-Mode</label></td></tr>
				</table>

				<h2 class="title">Discovery / Sitemap</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">/llms.txt</th>
						<td><label><input type="checkbox" name="<?php echo $n; ?>[add_llms_txt]" value="1" <?php checked( $o['add_llms_txt'], 1 ); ?>> Provide machine readable index</label></td></tr>
					<tr><th scope="row">/llms-full.txt</th>
						<td><label><input type="checkbox" name="<?php echo $n; ?>[add_llms_full]" value="1" <?php checked( $o['add_llms_full'], 1 ); ?>> Provide a full-text export (Markdown of all posts)</label></td></tr>
					<tr><th scope="row">robots.txt</th>
						<td><label><input type="checkbox" name="<?php echo $n; ?>[add_to_robots]" value="1" <?php checked( $o['add_to_robots'], 1 ); ?>> Include a reference to llms.txt in robots.txt</label></td></tr>
					<tr><th scope="row">Inhaltstyp / Limit</th>
						<td>
							<select name="<?php echo $n; ?>[llms_post_type]">
								<?php foreach ( $post_types as $pt ) : ?>
									<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $o['llms_post_type'], $pt->name ); ?>><?php echo esc_html( $pt->labels->name ); ?></option>
								<?php endforeach; ?>
							</select>
							Limit: <input type="number" min="1" name="<?php echo $n; ?>[llms_limit]" value="<?php echo esc_attr( $o['llms_limit'] ); ?>" style="width:80px">
						</td></tr>
				</table>

				<h2 class="title">Rate-Limiting <span class="description">(real agents only)</span></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Active</th>
						<td><label><input type="checkbox" name="<?php echo $n; ?>[rl_enabled]" value="1" <?php checked( $o['rl_enabled'], 1 ); ?>> If the limit is exceeded, send <code>429 Too Many Requests</code></label></td></tr>
					<tr><th scope="row">Limit / Window</th>
						<td>
							<input type="number" min="1" name="<?php echo $n; ?>[rl_limit]" value="<?php echo esc_attr( $o['rl_limit'] ); ?>" style="width:80px"> Requests per
							<input type="number" min="1" name="<?php echo $n; ?>[rl_window]" value="<?php echo esc_attr( $o['rl_window'] ); ?>" style="width:80px"> seconds (per IP)
						</td></tr>
				</table>

				<h2 class="title">Logging</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Active</th>
						<td><label><input type="checkbox" name="<?php echo $n; ?>[log_enabled]" value="1" <?php checked( $o['log_enabled'], 1 ); ?>> Log agent accesses</label></td></tr>
					<tr><th scope="row">IP Storage</th>
						<td><select name="<?php echo $n; ?>[ip_mode]">
							<option value="mask" <?php selected( $o['ip_mode'], 'mask' ); ?>>Masked (GDPR-compliant, recommended)</option>
							<option value="hash" <?php selected( $o['ip_mode'], 'hash' ); ?>>Hashed (pseudonym)</option>
							<option value="full" <?php selected( $o['ip_mode'], 'full' ); ?>>Complete</option>
							<option value="none" <?php selected( $o['ip_mode'], 'none' ); ?>>Don't save at all</option>
						</select></td></tr>
					<tr><th scope="row">Storing</th>
						<td><input type="number" min="1" name="<?php echo $n; ?>[log_retention]" value="<?php echo esc_attr( $o['log_retention'] ); ?>" style="width:80px"> Days (older entries are automatically deleted)</td></tr>
				</table>

				<h2 class="title">Known Agent-User-Agents</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Liste</th>
						<td>
							<textarea name="<?php echo $n; ?>[agent_uas]" rows="10" cols="50" class="large-text code"><?php echo esc_textarea( $o['agent_uas'] ); ?></textarea>
							<p class="description">One entry per line. Case-insensitive. Match = substring in the User-Agent.</p>
						</td></tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------- Monitoring page ------------------------- */

	public function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'agent_native_clear_log' );
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::log_table() );
		wp_safe_redirect( add_query_arg( array( 'page' => 'agent-native-log', 'cleared' => '1' ), admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Neutralise CSV formula injection: a cell starting with = + - @ (or a
	 * leading control char) is prefixed with an apostrophe so spreadsheet
	 * apps treat it as text, never as a formula.
	 */
	private function csv_cell( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	// Stream the full log as a CSV download, batched to stay memory-safe.
	public function handle_export_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'agent_native_export_log' );

		global $wpdb;
		$table = self::log_table();

		nocache_headers();
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore
		}

		$filename = 'agent-native-log-' . gmdate( 'Ymd-His' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// Discard any buffered output so the CSV is clean.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel.
		fputcsv( $out, array( 'id', 'timestamp', 'agent', 'user_agent', 'ip', 'url', 'status', 'format' ) );

		$batch  = 5000;
		$offset = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, ts, agent, ua, ip, url, status, format FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
				$batch,
				$offset
			), ARRAY_A );

			foreach ( (array) $rows as $r ) {
				fputcsv( $out, array(
					$this->csv_cell( $r['id'] ),
					$this->csv_cell( $r['ts'] ),
					$this->csv_cell( $r['agent'] ),
					$this->csv_cell( $r['ua'] ),
					$this->csv_cell( $r['ip'] ),
					$this->csv_cell( $r['url'] ),
					$this->csv_cell( $r['status'] ),
					$this->csv_cell( $r['format'] ),
				) );
			}
			$offset += $batch;
		} while ( ! empty( $rows ) && count( $rows ) === $batch );

		fclose( $out );
		exit;
	}

	public function monitoring_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = self::log_table();

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$d1    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE ts >= (NOW() - INTERVAL 1 DAY)" );
		$d7    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE ts >= (NOW() - INTERVAL 7 DAY)" );
		$top   = $wpdb->get_results( "SELECT agent, COUNT(*) c FROM {$table} GROUP BY agent ORDER BY c DESC LIMIT 10" );
		$rows  = $wpdb->get_results( "SELECT ts, agent, ip, url, status, format FROM {$table} ORDER BY id DESC LIMIT 100" );
		?>
		<div class="wrap">
			<h1>Agent Native WP – Monitoring</h1>
			<?php if ( isset( $_GET['cleared'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Log deleted.</p></div><?php endif; ?>

			<p>
				<strong>Total:</strong> <?php echo esc_html( $total ); ?> ·
				<strong>last 24 h:</strong> <?php echo esc_html( $d1 ); ?> ·
				<strong>last 7 days:</strong> <?php echo esc_html( $d7 ); ?>
			</p>

			<?php if ( $top ) : ?>
				<h2>Top-Agents</h2>
				<table class="widefat striped" style="max-width:480px">
					<thead><tr><th>Agent</th><th>Hits</th></tr></thead>
					<tbody>
					<?php foreach ( $top as $r ) : ?>
						<tr><td><?php echo esc_html( $r->agent ); ?></td><td><?php echo esc_html( $r->c ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>Latest hits</h2>
			<table class="widefat striped">
				<thead><tr><th>Time</th><th>Agent</th><th>IP</th><th>URL</th><th>Status</th><th>Format</th></tr></thead>
				<tbody>
				<?php if ( $rows ) : foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r->ts ); ?></td>
						<td><?php echo esc_html( $r->agent ); ?></td>
						<td><?php echo esc_html( $r->ip ); ?></td>
						<td><code><?php echo esc_html( $r->url ); ?></code></td>
						<td><?php echo esc_html( $r->status ); ?></td>
						<td><?php echo esc_html( $r->format ); ?></td>
					</tr>
				<?php endforeach; else : ?>
					<tr><td colspan="6">No entries yet.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>

			<p style="margin-top:1em">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5em">
					<input type="hidden" name="action" value="agent_native_export_log">
					<?php wp_nonce_field( 'agent_native_export_log' ); ?>
					<?php submit_button( 'Export CSV', 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block"
					onsubmit="return confirm('Delete all entries?');">
					<input type="hidden" name="action" value="agent_native_clear_log">
					<?php wp_nonce_field( 'agent_native_clear_log' ); ?>
					<?php submit_button( 'Emtpy log', 'delete', 'submit', false ); ?>
				</form>
			</p>
		</div>
		<?php
	}
}

Agent_Native::instance();
