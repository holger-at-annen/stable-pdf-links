<?php
/**
 * Plugin Name: Stable PDF Links
 * Description: Publish versioned PDFs behind stable, cache-resistant public links.
 * Version: 2.2.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Holger Hennrich
 * Author URI: https://github.com/holger-at-annen
 * License: GPL-2.0-or-later
 * Update URI: false
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SPLSC_Stable_PDF_Links {

	const VERSION = '2.2.1';

	const POST_TYPE = 'splsc_pdf_link';
	const PAGE      = 'splsc-pdf-links';

	const CAP_MANAGE  = 'manage_stable_pdf_links';
	const CAP_VERSIONS = 'manage_stable_pdf_versions';

	const OPTION_SETTINGS        = 'splsc_settings';
	const OPTION_REWRITE_PENDING = 'splsc_rewrite_refresh_pending';
	const OPTION_FALLBACK_PROBE  = 'splsc_missing_pdf_probe';
	const TRANSIENT_REWRITE_LOCK = 'splsc_rewrite_refresh_lock';
	const TRANSIENT_PROBE_PREFIX = 'splsc_probe_';
	const TRANSIENT_PROBE_HIT_PREFIX = 'splsc_probe_hit_';

	const META_SLUG       = '_splsc_public_slug';
	const META_FOLDER     = '_splsc_storage_folder';
	const META_TARGET     = '_splsc_retention_target';
	const META_LIMIT      = '_splsc_absolute_limit';
	const META_CURRENT    = '_splsc_current_attachment';
	const META_LINK_ID    = '_splsc_pdf_link_id';
	const META_PROTECTED  = '_splsc_protected';

	private static $upload_subdir = '';
	private static $internal_attachment_delete = false;

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_rewrite_rule' ), 20 );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_refresh_rewrite_rules' ), 999 );

		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'parse_request', array( __CLASS__, 'mark_route_uncacheable' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_public_link' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_missing_pdf_request' ), 9 );

		add_action( 'admin_menu', array( __CLASS__, 'register_admin_pages' ) );
		add_action( 'wp_ajax_splsc_check_values', array( __CLASS__, 'ajax_check_values' ) );
		add_action( 'wp_ajax_splsc_prepare_fallback_probe', array( __CLASS__, 'ajax_prepare_fallback_probe' ) );
		add_action( 'wp_ajax_splsc_finish_fallback_probe', array( __CLASS__, 'ajax_finish_fallback_probe' ) );

		add_action( 'admin_post_splsc_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_splsc_refresh_routes', array( __CLASS__, 'handle_refresh_routes' ) );
		add_action( 'admin_post_splsc_test_missing_pdf_fallback', array( __CLASS__, 'handle_test_missing_pdf_fallback' ) );
		add_action( 'admin_post_splsc_save_link', array( __CLASS__, 'handle_save_link' ) );
		add_action( 'admin_post_splsc_upload_pdf', array( __CLASS__, 'handle_upload_pdf' ) );
		add_action( 'admin_post_splsc_make_current', array( __CLASS__, 'handle_make_current' ) );
		add_action( 'admin_post_splsc_toggle_protection', array( __CLASS__, 'handle_toggle_protection' ) );
		add_action( 'admin_post_splsc_delete_pdf', array( __CLASS__, 'handle_delete_pdf' ) );
		add_action( 'admin_post_splsc_apply_retention', array( __CLASS__, 'handle_apply_retention' ) );
		add_action( 'admin_post_splsc_delete_link', array( __CLASS__, 'handle_delete_link' ) );
		add_action( 'admin_post_splsc_repair_maintenance', array( __CLASS__, 'handle_repair_maintenance' ) );
		add_action( 'admin_post_splsc_reset_plugin_data', array( __CLASS__, 'handle_reset_plugin_data' ) );

		add_filter( 'pre_delete_attachment', array( __CLASS__, 'guard_attachment_deletion' ), 10, 3 );
		add_action( 'delete_attachment', array( __CLASS__, 'prepare_for_external_attachment_deletion' ), 10, 2 );
	}

	public static function activate() {
		self::register_post_type();
		$settings = self::get_settings();

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->add_cap( self::CAP_MANAGE );
			$administrator->add_cap( self::CAP_VERSIONS );
		}

		$editor = get_role( 'editor' );
		if ( $editor && ! empty( $settings['allow_editors'] ) ) {
			$editor->add_cap( self::CAP_VERSIONS );
		}

		if ( ! empty( $settings['url_base'] ) ) {
			update_option( self::OPTION_REWRITE_PENDING, 1, false );
		}
	}

	public static function deactivate() {
		/*
		 * Remove only this plugin's exact cached rule. A full rewrite refresh
		 * would touch the route map for the whole site and is unnecessary here.
		 */
		self::remove_cached_plugin_routes( false );
		delete_option( self::OPTION_REWRITE_PENDING );
		delete_transient( self::TRANSIENT_REWRITE_LOCK );

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->remove_cap( self::CAP_MANAGE );
			$administrator->remove_cap( self::CAP_VERSIONS );
		}
		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->remove_cap( self::CAP_VERSIONS );
		}
	}

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'          => 'PDF Links',
					'singular_name' => 'PDF Link',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
			)
		);
	}

	private static function get_default_settings() {
		return array(
			'url_base'            => '',
			'upload_base_folder'  => 'stable-pdf-links',
			'missing_pdf_fallback' => 0,
			'max_upload_mb'       => 5,
			'default_target'      => 5,
			'default_limit'       => 10,
			'allow_editors'       => 0,
		);
	}

	private static function get_settings() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::get_default_settings() );
	}

	private static function get_url_base() {
		$settings = self::get_settings();
		return sanitize_title( $settings['url_base'] );
	}

	private static function get_upload_base_folder() {
		$settings = self::get_settings();
		$folder = sanitize_title( (string) $settings['upload_base_folder'] );
		return $folder ? $folder : 'stable-pdf-links';
	}

	private static function get_route_regex( $base ) {
		return '^' . preg_quote( $base, '/' ) . '/([^/]+)/?$';
	}

	private static function remove_cached_plugin_routes( $all = false ) {
		$rules = get_option( 'rewrite_rules', array() );
		if ( ! is_array( $rules ) ) {
			return 0;
		}

		$current = self::get_url_base();
		$current_regex = $current ? self::get_route_regex( $current ) : '';
		$removed = 0;
		foreach ( $rules as $regex => $destination ) {
			$is_plugin_rule = false !== strpos( (string) $destination, 'splsc_pdf_slug=' );
			if ( ! $is_plugin_rule ) {
				continue;
			}
			if ( $all || $regex === $current_regex ) {
				unset( $rules[ $regex ] );
				$removed++;
			}
		}

		if ( $removed ) {
			update_option( 'rewrite_rules', $rules, false );
		}
		return $removed;
	}

	public static function register_rewrite_rule() {
		$base = self::get_url_base();
		if ( '' === $base ) {
			return;
		}

		add_rewrite_rule(
			self::get_route_regex( $base ),
			'index.php?splsc_pdf_slug=$matches[1]',
			'top'
		);
	}

	public static function maybe_refresh_rewrite_rules() {
		if ( ! get_option( self::OPTION_REWRITE_PENDING ) ) {
			return;
		}

		if ( get_transient( self::TRANSIENT_REWRITE_LOCK ) ) {
			return;
		}

		set_transient( self::TRANSIENT_REWRITE_LOCK, 1, 30 );

		/* Soft refresh only: the false argument prevents .htaccess writes. */
		flush_rewrite_rules( false );

		delete_option( self::OPTION_REWRITE_PENDING );
		delete_transient( self::TRANSIENT_REWRITE_LOCK );
	}

	public static function register_query_vars( $vars ) {
		$vars[] = 'splsc_pdf_slug';
		return $vars;
	}

	public static function mark_route_uncacheable( $wp ) {
		if ( ! empty( $wp->query_vars['splsc_pdf_slug'] ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
		}
	}

	private static function send_no_cache_headers() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'Pragma: no-cache', true );
		header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );
		header( 'Surrogate-Control: no-store', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}

	public static function handle_public_link() {
		$slug = sanitize_title( (string) get_query_var( 'splsc_pdf_slug' ) );
		if ( '' === $slug ) {
			return;
		}

		$link = self::get_link_by_slug( $slug );
		if ( ! $link ) {
			self::public_error( 'This PDF link is not available.' );
		}

		$current = self::get_current_attachment( $link->ID );
		if ( ! $current ) {
			self::public_error( 'No PDF has been published for this link yet.' );
		}

		$url = self::get_versioned_attachment_url( $current->ID );
		if ( ! $url ) {
			self::public_error( 'The current PDF file could not be found.' );
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			self::public_error( 'The current PDF URL is invalid.' );
		}

		self::send_no_cache_headers();
		wp_redirect( esc_url_raw( $url ), 302, 'Stable PDF Links' );
		exit;
	}

	private static function public_error( $message ) {
		self::send_no_cache_headers();
		wp_die(
			esc_html( $message ),
			'PDF unavailable',
			array( 'response' => 404 )
		);
	}

	private static function get_request_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return '';
		}
		return '/' . ltrim( rawurldecode( $path ), '/' );
	}

	private static function get_upload_base_url_path() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) || empty( $upload['baseurl'] ) ) {
			return '';
		}
		$url = trailingslashit( $upload['baseurl'] ) . self::get_upload_base_folder();
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) ? '/' . trim( rawurldecode( $path ), '/' ) : '';
	}

	private static function get_fallback_probe_fingerprint() {
		$upload = wp_upload_dir();
		$baseurl = empty( $upload['baseurl'] ) ? '' : untrailingslashit( $upload['baseurl'] );
		return hash( 'sha256', $baseurl . '/' . self::get_upload_base_folder() );
	}

	private static function fallback_probe_is_supported() {
		$result = get_option( self::OPTION_FALLBACK_PROBE, array() );
		return is_array( $result )
			&& 'supported' === ( isset( $result['status'] ) ? $result['status'] : '' )
			&& hash_equals( self::get_fallback_probe_fingerprint(), isset( $result['fingerprint'] ) ? (string) $result['fingerprint'] : '' );
	}

	public static function handle_missing_pdf_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		$request_path = self::get_request_path();
		$probe_token = isset( $_GET['splsc_probe'] ) ? sanitize_key( wp_unslash( $_GET['splsc_probe'] ) ) : '';
		if ( $probe_token && preg_match( '/^[a-z0-9]{24}$/', $probe_token ) ) {
			$expected_path = get_transient( self::TRANSIENT_PROBE_PREFIX . $probe_token );
			if ( is_string( $expected_path ) && hash_equals( $expected_path, $request_path ) ) {
				delete_transient( self::TRANSIENT_PROBE_PREFIX . $probe_token );
				set_transient( self::TRANSIENT_PROBE_HIT_PREFIX . $probe_token, 1, 2 * MINUTE_IN_SECONDS );
				self::send_no_cache_headers();
				header( 'X-Stable-PDF-Links-Probe: supported', true );
				status_header( 204 );
				exit;
			}
		}

		$settings = self::get_settings();
		if ( empty( $settings['missing_pdf_fallback'] ) || ! is_404() ) {
			return;
		}

		$base_path = self::get_upload_base_url_path();
		$prefix = $base_path ? trailingslashit( $base_path ) : '';
		if ( ! $prefix || 0 !== strpos( $request_path, $prefix ) ) {
			return;
		}

		$relative = substr( $request_path, strlen( $prefix ) );
		$parts = explode( '/', $relative );
		if ( 2 !== count( $parts ) ) {
			return;
		}
		$folder = $parts[0];
		$filename = $parts[1];
		if ( ! $folder || sanitize_title( $folder ) !== $folder || ! $filename || strlen( $filename ) > 255 || false !== strpos( $filename, '\\' ) || preg_match( '/[\x00-\x1F\x7F]/', $filename ) || wp_basename( $filename ) !== $filename || 'pdf' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			return;
		}

		$physical_path = self::get_folder_path( $folder ) . '/' . $filename;
		if ( file_exists( $physical_path ) || is_link( $physical_path ) ) {
			return;
		}

		$link = self::get_link_by_folder( $folder );
		if ( ! $link || ! self::get_current_attachment( $link->ID ) ) {
			return;
		}
		$stable_url = self::get_public_url( $link->ID );
		if ( ! $stable_url ) {
			return;
		}

		self::send_no_cache_headers();
		wp_safe_redirect( $stable_url, 302, 'Stable PDF Links missing-file fallback' );
		exit;
	}

	private static function get_link( $link_id ) {
		$link = get_post( absint( $link_id ) );
		if ( ! $link || self::POST_TYPE !== $link->post_type || 'publish' !== $link->post_status ) {
			return false;
		}
		return $link;
	}

	private static function get_all_links() {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	private static function get_link_by_slug( $slug ) {
		$links = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => self::META_SLUG,
				'meta_value'     => sanitize_title( $slug ),
			)
		);
		return ! empty( $links ) ? $links[0] : false;
	}

	private static function get_link_by_folder( $folder ) {
		$links = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => self::META_FOLDER,
				'meta_value'     => sanitize_title( $folder ),
			)
		);
		return ! empty( $links ) ? $links[0] : false;
	}

	private static function get_link_slug( $link_id ) {
		return sanitize_title( (string) get_post_meta( $link_id, self::META_SLUG, true ) );
	}

	private static function get_link_folder( $link_id ) {
		return sanitize_title( (string) get_post_meta( $link_id, self::META_FOLDER, true ) );
	}

	private static function get_target( $link_id ) {
		$value = get_post_meta( $link_id, self::META_TARGET, true );
		if ( '' === $value ) {
			$settings = self::get_settings();
			return max( 1, absint( $settings['default_target'] ) );
		}
		return max( 1, absint( $value ) );
	}

	private static function get_limit( $link_id ) {
		$value = get_post_meta( $link_id, self::META_LIMIT, true );
		if ( '' === $value ) {
			$settings = self::get_settings();
			return max( self::get_target( $link_id ), absint( $settings['default_limit'] ) );
		}
		return max( self::get_target( $link_id ), absint( $value ) );
	}

	private static function get_public_url( $link_id ) {
		$base = self::get_url_base();
		$slug = self::get_link_slug( $link_id );
		if ( '' === $base || '' === $slug ) {
			return '';
		}
		return home_url( '/' . trailingslashit( $base . '/' . $slug ) );
	}

	private static function get_attachments( $link_id ) {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'application/pdf',
				'posts_per_page' => -1,
				'meta_key'       => self::META_LINK_ID,
				'meta_value'     => absint( $link_id ),
				'orderby'        => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'order'          => 'DESC',
			)
		);
	}

	private static function attachment_belongs_to_link( $attachment_id, $link_id ) {
		return absint( get_post_meta( $attachment_id, self::META_LINK_ID, true ) ) === absint( $link_id );
	}

	private static function is_protected( $attachment_id ) {
		return '1' === (string) get_post_meta( $attachment_id, self::META_PROTECTED, true );
	}

	private static function get_current_attachment( $link_id ) {
		$current_id = absint( get_post_meta( $link_id, self::META_CURRENT, true ) );
		if ( $current_id && self::attachment_belongs_to_link( $current_id, $link_id ) ) {
			$current = get_post( $current_id );
			if ( $current && 'attachment' === $current->post_type ) {
				return $current;
			}
		}

		$attachments = self::get_attachments( $link_id );
		return ! empty( $attachments ) ? $attachments[0] : false;
	}

	private static function get_versioned_attachment_url( $attachment_id ) {
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return false;
		}

		$file  = get_attached_file( $attachment_id );
		$stamp = get_post_modified_time( 'U', true, $attachment_id );
		$size  = 0;

		if ( $file && is_file( $file ) ) {
			$file_mtime = filemtime( $file );
			$file_size  = filesize( $file );
			if ( false !== $file_mtime ) {
				$stamp = $file_mtime;
			}
			if ( false !== $file_size ) {
				$size = $file_size;
			}
		}

		return add_query_arg(
			'v',
			absint( $stamp ? $stamp : time() ) . '-' . absint( $size ),
			$url
		);
	}

	private static function get_upload_base_path( $base_folder = '' ) {
		$upload = wp_upload_dir();
		$base_folder = $base_folder ? sanitize_title( $base_folder ) : self::get_upload_base_folder();
		return trailingslashit( $upload['basedir'] ) . $base_folder;
	}

	private static function get_folder_path( $folder ) {
		return trailingslashit( self::get_upload_base_path() ) . sanitize_title( $folder );
	}

	private static function directory_is_empty( $path ) {
		if ( ! file_exists( $path ) ) {
			return true;
		}
		if ( ! is_dir( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		$entries = scandir( $path );
		if ( false === $entries ) {
			return false;
		}
		return empty( array_diff( $entries, array( '.', '..' ) ) );
	}

	private static function folder_is_empty( $folder ) {
		$path = self::get_folder_path( $folder );
		return self::directory_is_empty( $path );
	}

	private static function get_all_managed_attachment_ids() {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => array( 'inherit', 'publish', 'private', 'draft', 'pending', 'future', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::META_LINK_ID,
						'compare' => 'EXISTS',
					),
				),
			)
		);
	}

	private static function upload_base_is_locked() {
		return ! empty( self::get_all_managed_attachment_ids() ) || ! self::directory_is_empty( self::get_upload_base_path() );
	}

	private static function folder_is_locked( $link_id, $folder ) {
		return ! empty( self::get_attachments( $link_id ) ) || ! self::folder_is_empty( $folder );
	}

	private static function value_used_by_other_link( $meta_key, $value, $exclude_id = 0 ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => $meta_key,
			'meta_value'     => $value,
		);
		if ( $exclude_id ) {
			$args['post__not_in'] = array( absint( $exclude_id ) );
		}
		return ! empty( get_posts( $args ) );
	}

	private static function name_used_by_other_link( $name, $exclude_id = 0 ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'title'          => $name,
		);
		if ( $exclude_id ) {
			$args['post__not_in'] = array( absint( $exclude_id ) );
		}
		foreach ( get_posts( $args ) as $link ) {
			if ( 0 === strcasecmp( trim( $link->post_title ), trim( $name ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function public_content_conflict( $base, $slug = '' ) {
		$path = $slug ? $base . '/' . $slug : $base;
		$types = get_post_types( array( 'public' => true ), 'names' );
		if ( empty( $types ) ) {
			return false;
		}
		return get_page_by_path( $path, OBJECT, array_values( $types ) ) instanceof WP_Post;
	}

	private static function base_has_known_conflict( $base ) {
		$reserved = array( 'wp-admin', 'wp-json', 'wp-login', 'feed', 'comments', 'author', 'category', 'tag', 'search' );
		if ( in_array( $base, $reserved, true ) ) {
			return true;
		}

		$category_base = sanitize_title( (string) get_option( 'category_base' ) );
		$tag_base      = sanitize_title( (string) get_option( 'tag_base' ) );
		if ( $base === $category_base || $base === $tag_base ) {
			return true;
		}

		return self::public_content_conflict( $base );
	}

	private static function link_slug_has_known_conflict( $slug ) {
		$base = self::get_url_base();
		return $base ? self::public_content_conflict( $base, $slug ) : false;
	}

	private static function effective_upload_limit_bytes() {
		$settings     = self::get_settings();
		$plugin_limit = max( 1, absint( $settings['max_upload_mb'] ) ) * MB_IN_BYTES;
		return min( $plugin_limit, wp_max_upload_size() );
	}

	private static function minimum_required_slots( $link_id ) {
		$required = 0;
		$current  = self::get_current_attachment( $link_id );
		$current_id = $current ? $current->ID : 0;

		foreach ( self::get_attachments( $link_id ) as $attachment ) {
			if ( self::is_protected( $attachment->ID ) ) {
				$required++;
			}
		}

		if ( $current_id && ! self::is_protected( $current_id ) ) {
			$required++;
		}

		return $required;
	}

	private static function require_capability( $capability ) {
		if ( ! current_user_can( $capability ) ) {
			wp_die( 'You do not have permission to perform this action.', 'Permission denied', array( 'response' => 403 ) );
		}
	}

	private static function redirect_admin( $message, $link_id = 0, $extra = array(), $page = self::PAGE ) {
		$args = array_merge(
			array(
				'page'          => $page,
				'splsc_message' => sanitize_key( $message ),
			),
			$extra
		);
		if ( $link_id ) {
			$args['link_id'] = absint( $link_id );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function register_admin_pages() {
		add_menu_page(
			'Stable PDF Links',
			'PDF Links',
			self::CAP_VERSIONS,
			self::PAGE,
			array( __CLASS__, 'render_link_list_page' ),
			'dashicons-pdf',
			11
		);

		add_submenu_page(
			self::PAGE,
			'All PDF Links',
			'All PDF Links',
			self::CAP_VERSIONS,
			self::PAGE,
			array( __CLASS__, 'render_link_list_page' )
		);

		if ( current_user_can( self::CAP_MANAGE ) ) {
			add_submenu_page(
				self::PAGE,
				'Add PDF Link',
				'Add PDF Link',
				self::CAP_MANAGE,
				'splsc-add-link',
				array( __CLASS__, 'render_add_link_page' )
			);

			add_submenu_page(
				self::PAGE,
				'General Settings',
				'General Settings',
				self::CAP_MANAGE,
				'splsc-settings',
				array( __CLASS__, 'render_settings_page' )
			);
		}
	}

	public static function ajax_check_values() {
		self::require_capability( self::CAP_MANAGE );
		check_ajax_referer( 'splsc_check_values', 'nonce' );

		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$slug    = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$folder  = isset( $_POST['folder'] ) ? sanitize_title( wp_unslash( $_POST['folder'] ) ) : '';

		$old_folder = $link_id ? self::get_link_folder( $link_id ) : '';
		$folder_locked = $link_id && self::folder_is_locked( $link_id, $old_folder );

		wp_send_json_success(
			array(
				'name' => array(
					'ok'      => '' !== $name && ! self::name_used_by_other_link( $name, $link_id ),
					'message' => self::name_used_by_other_link( $name, $link_id ) ? 'Already used by another PDF Link.' : 'Available.',
				),
				'slug' => array(
					'ok'      => '' !== $slug && ! self::value_used_by_other_link( self::META_SLUG, $slug, $link_id ) && ! self::link_slug_has_known_conflict( $slug ),
					'message' => self::value_used_by_other_link( self::META_SLUG, $slug, $link_id ) || self::link_slug_has_known_conflict( $slug ) ? 'Already used or conflicts with known content.' : 'No known conflict.',
				),
				'folder' => array(
					'ok'      => '' !== $folder && ! self::value_used_by_other_link( self::META_FOLDER, $folder, $link_id ) && ( $folder === $old_folder || self::folder_is_empty( $folder ) ) && ! ( $folder_locked && $folder !== $old_folder ),
					'message' => self::value_used_by_other_link( self::META_FOLDER, $folder, $link_id ) || ( $folder !== $old_folder && ! self::folder_is_empty( $folder ) ) ? 'Already used or the directory is not empty.' : 'Available.',
				),
			)
		);
	}

	public static function handle_save_settings() {
		self::require_capability( self::CAP_MANAGE );
		check_admin_referer( 'splsc_save_settings' );

		$old = self::get_settings();
		$base = isset( $_POST['url_base'] ) ? sanitize_title( wp_unslash( $_POST['url_base'] ) ) : '';
		$upload_base_folder = isset( $_POST['upload_base_folder'] ) ? sanitize_title( wp_unslash( $_POST['upload_base_folder'] ) ) : '';
		$max_upload_mb = isset( $_POST['max_upload_mb'] ) ? max( 1, absint( $_POST['max_upload_mb'] ) ) : 5;
		$target = isset( $_POST['default_target'] ) ? max( 1, absint( $_POST['default_target'] ) ) : 5;
		$limit = isset( $_POST['default_limit'] ) ? max( 1, absint( $_POST['default_limit'] ) ) : 10;
		$allow_editors = ! empty( $_POST['allow_editors'] ) ? 1 : 0;
		$missing_pdf_fallback = ! empty( $_POST['missing_pdf_fallback'] ) ? 1 : 0;

		if ( '' === $base ) {
			self::redirect_admin( 'settings_base_required', 0, array(), 'splsc-settings' );
		}
		if ( strlen( $base ) > 80 ) {
			self::redirect_admin( 'settings_base_long', 0, array(), 'splsc-settings' );
		}
		if ( '' === $upload_base_folder ) {
			self::redirect_admin( 'upload_base_required', 0, array(), 'splsc-settings' );
		}
		if ( strlen( $upload_base_folder ) > 80 ) {
			self::redirect_admin( 'upload_base_long', 0, array(), 'splsc-settings' );
		}
		if ( $target > $limit ) {
			self::redirect_admin( 'settings_limit_invalid', 0, array(), 'splsc-settings' );
		}
		if ( $base !== $old['url_base'] && self::base_has_known_conflict( $base ) ) {
			self::redirect_admin( 'settings_base_conflict', 0, array(), 'splsc-settings' );
		}

		$old_upload_base = sanitize_title( (string) $old['upload_base_folder'] );
		if ( $upload_base_folder !== $old_upload_base ) {
			if ( self::upload_base_is_locked() ) {
				self::redirect_admin( 'upload_base_locked', 0, array(), 'splsc-settings' );
			}
			if ( ! self::directory_is_empty( self::get_upload_base_path( $upload_base_folder ) ) ) {
				self::redirect_admin( 'upload_base_not_empty', 0, array(), 'splsc-settings' );
			}
		}
		if ( $missing_pdf_fallback && ( $upload_base_folder !== $old_upload_base || ! self::fallback_probe_is_supported() ) ) {
			self::redirect_admin( 'fallback_requires_test', 0, array(), 'splsc-settings' );
		}

		update_option(
			self::OPTION_SETTINGS,
			array(
				'url_base'           => $base,
				'upload_base_folder' => $upload_base_folder,
				'missing_pdf_fallback' => $missing_pdf_fallback,
				'max_upload_mb'      => min( 1024, $max_upload_mb ),
				'default_target'     => min( 1000, $target ),
				'default_limit'      => min( 1000, $limit ),
				'allow_editors'      => $allow_editors,
			),
			false
		);

		$editor = get_role( 'editor' );
		if ( $editor ) {
			if ( $allow_editors ) {
				$editor->add_cap( self::CAP_VERSIONS );
			} else {
				$editor->remove_cap( self::CAP_VERSIONS );
			}
		}

		if ( $base !== $old['url_base'] ) {
			update_option( self::OPTION_REWRITE_PENDING, 1, false );
		}
		if ( $upload_base_folder !== $old_upload_base ) {
			delete_option( self::OPTION_FALLBACK_PROBE );
			$old_path = self::get_upload_base_path( $old_upload_base );
			if ( is_dir( $old_path ) && self::directory_is_empty( $old_path ) ) {
				rmdir( $old_path );
			}
		}

		self::redirect_admin(
			$base !== $old['url_base'] ? 'settings_saved_base_changed' : ( $upload_base_folder !== $old_upload_base ? 'settings_saved_upload_folder' : 'settings_saved' ),
			0,
			array(),
			'splsc-settings'
		);
	}

	private static function prepare_fallback_probe() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) || empty( $upload['baseurl'] ) ) {
			return new WP_Error( 'uploads_unavailable' );
		}

		$token = strtolower( wp_generate_password( 24, false, false ) );
		$probe_url = trailingslashit( $upload['baseurl'] ) . self::get_upload_base_folder() . '/splsc-probe-' . $token . '.pdf';
		$probe_path = wp_parse_url( $probe_url, PHP_URL_PATH );
		$probe_path = is_string( $probe_path ) ? '/' . ltrim( rawurldecode( $probe_path ), '/' ) : '';
		if ( ! $probe_path ) {
			return new WP_Error( 'invalid_upload_url' );
		}

		set_transient( self::TRANSIENT_PROBE_PREFIX . $token, $probe_path, 2 * MINUTE_IN_SECONDS );
		delete_transient( self::TRANSIENT_PROBE_HIT_PREFIX . $token );
		return array(
			'token' => $token,
			'url'   => add_query_arg( 'splsc_probe', $token, $probe_url ),
		);
	}

	private static function save_fallback_probe_status( $status ) {
		$status = in_array( $status, array( 'supported', 'unsupported', 'inconclusive' ), true ) ? $status : 'inconclusive';
		update_option(
			self::OPTION_FALLBACK_PROBE,
			array(
				'status'      => $status,
				'fingerprint' => self::get_fallback_probe_fingerprint(),
				'checked_at'  => time(),
			),
			false
		);
	}

	public static function ajax_prepare_fallback_probe() {
		self::require_capability( self::CAP_MANAGE );
		check_ajax_referer( 'splsc_browser_fallback_probe', 'nonce' );
		$probe = self::prepare_fallback_probe();
		if ( is_wp_error( $probe ) ) {
			self::save_fallback_probe_status( 'inconclusive' );
			wp_send_json_error( array( 'message_key' => 'fallback_test_inconclusive' ) );
		}
		wp_send_json_success( $probe );
	}

	public static function ajax_finish_fallback_probe() {
		self::require_capability( self::CAP_MANAGE );
		check_ajax_referer( 'splsc_browser_fallback_probe', 'nonce' );
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$browser_error = ! empty( $_POST['browser_error'] );
		if ( ! preg_match( '/^[a-z0-9]{24}$/', $token ) ) {
			self::save_fallback_probe_status( 'inconclusive' );
			wp_send_json_error( array( 'message_key' => 'fallback_test_inconclusive' ) );
		}

		$hit = (bool) get_transient( self::TRANSIENT_PROBE_HIT_PREFIX . $token );
		delete_transient( self::TRANSIENT_PROBE_PREFIX . $token );
		delete_transient( self::TRANSIENT_PROBE_HIT_PREFIX . $token );
		$status = $hit ? 'supported' : ( $browser_error ? 'inconclusive' : 'unsupported' );
		self::save_fallback_probe_status( $status );
		wp_send_json_success(
			array(
				'message_key' => 'supported' === $status ? 'fallback_test_supported' : ( 'unsupported' === $status ? 'fallback_test_unsupported' : 'fallback_test_inconclusive' ),
			)
		);
	}

	public static function handle_test_missing_pdf_fallback() {
		self::require_capability( self::CAP_MANAGE );
		check_admin_referer( 'splsc_test_missing_pdf_fallback' );
		$probe = self::prepare_fallback_probe();
		if ( is_wp_error( $probe ) ) {
			self::save_fallback_probe_status( 'inconclusive' );
			self::redirect_admin( 'fallback_test_inconclusive', 0, array(), 'splsc-settings' );
		}

		$response = wp_remote_get(
			$probe['url'],
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Cache-Control' => 'no-cache, no-store, max-age=0',
					'Pragma'        => 'no-cache',
				),
			)
		);
		$hit = (bool) get_transient( self::TRANSIENT_PROBE_HIT_PREFIX . $probe['token'] );
		delete_transient( self::TRANSIENT_PROBE_PREFIX . $probe['token'] );
		delete_transient( self::TRANSIENT_PROBE_HIT_PREFIX . $probe['token'] );

		$status = $hit ? 'supported' : ( is_wp_error( $response ) ? 'inconclusive' : 'unsupported' );
		$message = 'supported' === $status ? 'fallback_test_supported' : ( 'unsupported' === $status ? 'fallback_test_unsupported' : 'fallback_test_inconclusive' );
		self::save_fallback_probe_status( $status );
		self::redirect_admin( $message, 0, array(), 'splsc-settings' );
	}

	public static function handle_refresh_routes() {
		self::require_capability( self::CAP_MANAGE );
		check_admin_referer( 'splsc_refresh_routes' );
		if ( '' === self::get_url_base() ) {
			self::redirect_admin( 'settings_base_required', 0, array(), 'splsc-settings' );
		}
		update_option( self::OPTION_REWRITE_PENDING, 1, false );
		self::redirect_admin( 'routes_refreshed', 0, array(), 'splsc-settings' );
	}

	public static function handle_save_link() {
		self::require_capability( self::CAP_MANAGE );
		check_admin_referer( 'splsc_save_link' );

		if ( '' === self::get_url_base() ) {
			self::redirect_admin( 'configure_settings' );
		}

		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
		$name = isset( $_POST['link_name'] ) ? sanitize_text_field( wp_unslash( $_POST['link_name'] ) ) : '';
		$slug = isset( $_POST['public_slug'] ) ? sanitize_title( wp_unslash( $_POST['public_slug'] ) ) : '';
		$folder = isset( $_POST['storage_folder'] ) ? sanitize_title( wp_unslash( $_POST['storage_folder'] ) ) : '';
		$target = isset( $_POST['retention_target'] ) ? max( 1, absint( $_POST['retention_target'] ) ) : 1;
		$limit = isset( $_POST['absolute_limit'] ) ? max( 1, absint( $_POST['absolute_limit'] ) ) : 1;

		if ( '' === $name || '' === $slug || '' === $folder ) {
			self::redirect_admin( 'missing_fields', $link_id );
		}
		if ( $target > $limit ) {
			self::redirect_admin( 'link_limit_invalid', $link_id );
		}
		if ( self::name_used_by_other_link( $name, $link_id ) ) {
			self::redirect_admin( 'name_in_use', $link_id );
		}
		if ( self::value_used_by_other_link( self::META_SLUG, $slug, $link_id ) ) {
			self::redirect_admin( 'slug_in_use', $link_id );
		}
		if ( self::link_slug_has_known_conflict( $slug ) ) {
			self::redirect_admin( 'slug_conflict', $link_id );
		}
		if ( self::value_used_by_other_link( self::META_FOLDER, $folder, $link_id ) ) {
			self::redirect_admin( 'folder_in_use', $link_id );
		}

		$old_folder = $link_id ? self::get_link_folder( $link_id ) : '';
		if ( $link_id && ! self::get_link( $link_id ) ) {
			self::redirect_admin( 'invalid_link' );
		}
		if ( $link_id && $limit < self::minimum_required_slots( $link_id ) ) {
			self::redirect_admin( 'link_limit_protected', $link_id );
		}
		if ( $link_id && $folder !== $old_folder && self::folder_is_locked( $link_id, $old_folder ) ) {
			self::redirect_admin( 'folder_locked', $link_id );
		}
		if ( $folder !== $old_folder && ! self::folder_is_empty( $folder ) ) {
			self::redirect_admin( 'target_folder_not_empty', $link_id );
		}

		$post_data = array(
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		);
		if ( $link_id ) {
			$post_data['ID'] = $link_id;
			$result = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			self::redirect_admin( 'save_failed', $link_id );
		}

		$link_id = absint( $result );
		update_post_meta( $link_id, self::META_SLUG, $slug );
		update_post_meta( $link_id, self::META_FOLDER, $folder );
		update_post_meta( $link_id, self::META_TARGET, $target );
		update_post_meta( $link_id, self::META_LIMIT, $limit );

		self::redirect_admin( 'link_saved', $link_id );
	}

	public static function filter_upload_directory( $dirs ) {
		if ( '' === self::$upload_subdir ) {
			return $dirs;
		}
		$dirs['subdir'] = self::$upload_subdir;
		$dirs['path']   = $dirs['basedir'] . self::$upload_subdir;
		$dirs['url']    = $dirs['baseurl'] . self::$upload_subdir;
		return $dirs;
	}

	private static function sanitize_public_filename( $requested, $original ) {
		$requested = trim( (string) $requested );
		$source    = '' !== $requested ? $requested : $original;
		$source    = sanitize_file_name( wp_basename( $source ) );
		$stem      = sanitize_title( pathinfo( $source, PATHINFO_FILENAME ) );
		return $stem ? $stem . '.pdf' : '';
	}

	private static function upload_can_fit_hard_limit( $link_id ) {
		$limit = self::get_limit( $link_id );
		$protected = 0;
		foreach ( self::get_attachments( $link_id ) as $attachment ) {
			if ( self::is_protected( $attachment->ID ) ) {
				$protected++;
			}
		}

		/* The new upload must also occupy one slot as the new current file. */
		return ( $protected + 1 ) <= $limit;
	}

	public static function handle_upload_pdf() {
		self::require_capability( self::CAP_VERSIONS );
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
		check_admin_referer( 'splsc_upload_pdf_' . $link_id );

		$link = self::get_link( $link_id );
		if ( ! $link ) {
			self::redirect_admin( 'invalid_link' );
		}
		if ( ! self::upload_can_fit_hard_limit( $link_id ) ) {
			self::redirect_admin( 'hard_limit_blocked', $link_id );
		}
		if ( ! isset( $_FILES['splsc_pdf'] ) || empty( $_FILES['splsc_pdf']['name'] ) ) {
			self::redirect_admin( 'select_pdf', $link_id );
		}

		$file = $_FILES['splsc_pdf'];
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			self::redirect_admin( 'upload_server_rejected', $link_id );
		}
		if ( isset( $file['size'] ) && (int) $file['size'] > self::effective_upload_limit_bytes() ) {
			self::redirect_admin( 'upload_too_large', $link_id );
		}

		$requested_name = isset( $_POST['public_filename'] ) ? wp_unslash( $_POST['public_filename'] ) : '';
		$public_filename = self::sanitize_public_filename( $requested_name, $file['name'] );
		if ( '' === $public_filename ) {
			self::redirect_admin( 'invalid_filename', $link_id );
		}
		$_FILES['splsc_pdf']['name'] = $public_filename;

		$folder = self::get_link_folder( $link_id );
		$folder_path = self::get_folder_path( $folder );
		if ( ! is_dir( $folder_path ) && ! wp_mkdir_p( $folder_path ) ) {
			self::redirect_admin( 'folder_create_failed', $link_id );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$old_current = absint( get_post_meta( $link_id, self::META_CURRENT, true ) );
		self::$upload_subdir = '/' . self::get_upload_base_folder() . '/' . $folder;
		add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_directory' ), 999 );

		try {
			$attachment_id = media_handle_upload(
				'splsc_pdf',
				$link_id,
				array(),
				array(
					'test_form' => false,
					'mimes'     => array( 'pdf' => 'application/pdf' ),
				)
			);
		} finally {
			remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_directory' ), 999 );
			self::$upload_subdir = '';
		}

		if ( is_wp_error( $attachment_id ) ) {
			self::redirect_admin( 'upload_failed', $link_id );
		}

		update_post_meta( $attachment_id, self::META_LINK_ID, $link_id );
		delete_post_meta( $attachment_id, self::META_PROTECTED );
		update_post_meta( $link_id, self::META_CURRENT, $attachment_id );

		/*
		 * Keep the previous current version out of the soft-cleanup phase until
		 * the hard limit has been satisfied. If a deletion fails and the upload
		 * must be rolled back, the former current version is still available.
		 */
		$cleanup = self::apply_retention( $link_id, $old_current );
		$current_count = count( self::get_attachments( $link_id ) );

		if ( $current_count > self::get_limit( $link_id ) ) {
			self::delete_attachment_permanently( $attachment_id );
			if ( $old_current && self::attachment_belongs_to_link( $old_current, $link_id ) ) {
				update_post_meta( $link_id, self::META_CURRENT, $old_current );
			} else {
				delete_post_meta( $link_id, self::META_CURRENT );
			}
			self::redirect_admin( 'upload_rolled_back', $link_id );
		}

		/* The hard limit is safe; now finish ordinary soft retention cleanup. */
		$final_cleanup = self::apply_retention( $link_id );
		$cleanup['deleted'] += $final_cleanup['deleted'];
		$cleanup['failed']  += $final_cleanup['failed'];

		self::redirect_admin(
			$cleanup['failed'] ? 'uploaded_cleanup_warning' : 'uploaded',
			$link_id,
			array(
				'removed' => $cleanup['deleted'],
				'failed'  => $cleanup['failed'],
			)
		);
	}

	private static function apply_retention( $link_id, $preserve_until_hard_limit = 0 ) {
		$result = array( 'deleted' => 0, 'failed' => 0, 'blocked' => 0 );
		$target = self::get_target( $link_id );
		$limit  = self::get_limit( $link_id );
		$current = self::get_current_attachment( $link_id );
		$current_id = $current ? $current->ID : 0;
		$attachments = self::get_attachments( $link_id );

		$keep = array();
		foreach ( array_slice( $attachments, 0, $target ) as $attachment ) {
			$keep[ $attachment->ID ] = true;
		}
		if ( $current_id ) {
			$keep[ $current_id ] = true;
		}
		foreach ( $attachments as $attachment ) {
			if ( self::is_protected( $attachment->ID ) ) {
				$keep[ $attachment->ID ] = true;
			}
		}

		/* First remove versions outside the soft retention set, oldest first. */
		foreach ( array_reverse( $attachments ) as $attachment ) {
			if ( $attachment->ID === absint( $preserve_until_hard_limit ) || isset( $keep[ $attachment->ID ] ) || $attachment->ID === $current_id || self::is_protected( $attachment->ID ) ) {
				continue;
			}
			if ( self::delete_attachment_permanently( $attachment->ID ) ) {
				$result['deleted']++;
			} else {
				$result['failed']++;
				break;
			}
		}

		/* The hard limit is stronger than the soft target. */
		$remaining = self::get_attachments( $link_id );
		$hard_candidates = array();
		$preserved_candidate = false;
		foreach ( array_reverse( $remaining ) as $attachment ) {
			if ( $attachment->ID === $current_id || self::is_protected( $attachment->ID ) ) {
				continue;
			}
			if ( $attachment->ID === absint( $preserve_until_hard_limit ) ) {
				$preserved_candidate = $attachment;
				continue;
			}
			$hard_candidates[] = $attachment;
		}
		if ( $preserved_candidate ) {
			$hard_candidates[] = $preserved_candidate;
		}

		foreach ( $hard_candidates as $attachment ) {
			if ( count( self::get_attachments( $link_id ) ) <= $limit ) {
				break;
			}
			if ( self::delete_attachment_permanently( $attachment->ID ) ) {
				$result['deleted']++;
			} else {
				$result['failed']++;
				break;
			}
		}

		if ( count( self::get_attachments( $link_id ) ) > $limit ) {
			$result['blocked'] = 1;
		}

		return $result;
	}

	private static function delete_attachment_permanently( $attachment_id ) {
		self::$internal_attachment_delete = true;
		try {
			return wp_delete_attachment( $attachment_id, true );
		} finally {
			self::$internal_attachment_delete = false;
		}
	}

	public static function guard_attachment_deletion( $delete, $post, $force_delete ) {
		if ( null !== $delete || self::$internal_attachment_delete || ! $post || 'attachment' !== $post->post_type ) {
			return $delete;
		}

		$link_id = absint( get_post_meta( $post->ID, self::META_LINK_ID, true ) );
		if ( ! $link_id ) {
			return $delete;
		}

		$current = self::get_current_attachment( $link_id );
		if ( ! $current || $current->ID !== $post->ID ) {
			return $delete;
		}

		return count( self::get_attachments( $link_id ) ) < 2 ? false : $delete;
	}

	public static function prepare_for_external_attachment_deletion( $attachment_id, $post = null ) {
		if ( self::$internal_attachment_delete ) {
			return;
		}
		$link_id = absint( get_post_meta( $attachment_id, self::META_LINK_ID, true ) );
		if ( ! $link_id ) {
			return;
		}
		$current = self::get_current_attachment( $link_id );
		if ( ! $current || $current->ID !== absint( $attachment_id ) ) {
			return;
		}
		foreach ( self::get_attachments( $link_id ) as $attachment ) {
			if ( $attachment->ID !== absint( $attachment_id ) ) {
				update_post_meta( $link_id, self::META_CURRENT, $attachment->ID );
				break;
			}
		}
	}

	public static function handle_make_current() {
		self::require_capability( self::CAP_VERSIONS );
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		check_admin_referer( 'splsc_make_current_' . $link_id . '_' . $attachment_id );
		if ( ! self::get_link( $link_id ) || ! self::attachment_belongs_to_link( $attachment_id, $link_id ) ) {
			self::redirect_admin( 'invalid_pdf', $link_id );
		}
		update_post_meta( $link_id, self::META_CURRENT, $attachment_id );
		self::redirect_admin( 'current_changed', $link_id );
	}

	public static function handle_toggle_protection() {
		self::require_capability( self::CAP_VERSIONS );
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		check_admin_referer( 'splsc_toggle_protection_' . $link_id . '_' . $attachment_id );
		if ( ! self::get_link( $link_id ) || ! self::attachment_belongs_to_link( $attachment_id, $link_id ) ) {
			self::redirect_admin( 'invalid_pdf', $link_id );
		}
		if ( self::is_protected( $attachment_id ) ) {
			delete_post_meta( $attachment_id, self::META_PROTECTED );
			$message = 'protection_removed';
		} else {
			update_post_meta( $attachment_id, self::META_PROTECTED, '1' );
			$message = 'version_protected';
		}
		self::redirect_admin( $message, $link_id );
	}

	public static function handle_delete_pdf() {
		self::require_capability( self::CAP_VERSIONS );
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		check_admin_referer( 'splsc_delete_pdf_' . $link_id . '_' . $attachment_id );

		if ( ! self::get_link( $link_id ) || ! self::attachment_belongs_to_link( $attachment_id, $link_id ) ) {
			self::redirect_admin( 'invalid_pdf', $link_id );
		}

		$attachments = self::get_attachments( $link_id );
		$current = self::get_current_attachment( $link_id );
		$is_current = $current && $current->ID === $attachment_id;

		if ( $is_current && count( $attachments ) < 2 ) {
			self::redirect_admin( 'last_current_protected', $link_id );
		}

		$old_current = $current ? $current->ID : 0;
		if ( $is_current ) {
			foreach ( $attachments as $attachment ) {
				if ( $attachment->ID !== $attachment_id ) {
					update_post_meta( $link_id, self::META_CURRENT, $attachment->ID );
					break;
				}
		}
		}

		if ( ! self::delete_attachment_permanently( $attachment_id ) ) {
			if ( $is_current && $old_current ) {
				update_post_meta( $link_id, self::META_CURRENT, $old_current );
			}
			self::redirect_admin( 'delete_failed', $link_id );
		}

		self::redirect_admin( $is_current ? 'current_deleted_promoted' : 'pdf_deleted', $link_id );
	}

	public static function handle_apply_retention() {
		self::require_capability( self::CAP_VERSIONS );
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
		check_admin_referer( 'splsc_apply_retention_' . $link_id );
		if ( ! self::get_link( $link_id ) ) {
			self::redirect_admin( 'invalid_link' );
		}
		$result = self::apply_retention( $link_id );
		self::redirect_admin(
			$result['blocked'] ? 'cleanup_blocked' : ( $result['failed'] ? 'cleanup_failed' : 'cleanup_complete' ),
			$link_id,
			array( 'removed' => $result['deleted'], 'failed' => $result['failed'] )
		);
	}

	public static function handle_delete_link() {
		self::require_capability( self::CAP_MANAGE );
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
		$mode = isset( $_POST['delete_mode'] ) ? sanitize_key( $_POST['delete_mode'] ) : '';
		check_admin_referer( 'splsc_delete_link_' . $link_id );

		if ( ! self::get_link( $link_id ) || ! in_array( $mode, array( 'retain', 'purge' ), true ) ) {
			self::redirect_admin( 'invalid_link' );
		}

		$folder = self::get_link_folder( $link_id );
		$attachments = self::get_attachments( $link_id );

		if ( 'purge' === $mode ) {
			$failures = 0;
			foreach ( $attachments as $attachment ) {
				if ( ! self::delete_attachment_permanently( $attachment->ID ) ) {
					$failures++;
				}
			}
			if ( $failures ) {
				self::redirect_admin( 'link_delete_files_failed', $link_id, array( 'failed' => $failures ) );
			}
		} else {
			foreach ( $attachments as $attachment ) {
				delete_post_meta( $attachment->ID, self::META_LINK_ID );
				delete_post_meta( $attachment->ID, self::META_PROTECTED );
				wp_update_post( array( 'ID' => $attachment->ID, 'post_parent' => 0 ) );
			}
		}

		if ( ! wp_delete_post( $link_id, true ) ) {
			self::redirect_admin( 'link_delete_failed', $link_id );
		}

		$folder_removed = false;
		$folder_path = self::get_folder_path( $folder );
		if ( 'purge' === $mode && is_dir( $folder_path ) && self::folder_is_empty( $folder ) ) {
			$folder_removed = rmdir( $folder_path );
		}

		if ( 'retain' === $mode ) {
			self::redirect_admin( 'link_deleted_files_retained' );
		}
		self::redirect_admin( $folder_removed ? 'link_deleted' : 'link_deleted_folder_retained' );
	}

	private static function get_managed_storage_bytes() {
		$total = 0;
		foreach ( self::get_all_links() as $link ) {
			foreach ( self::get_attachments( $link->ID ) as $attachment ) {
				$file = get_attached_file( $attachment->ID );
				if ( $file && is_file( $file ) ) {
					$size = filesize( $file );
					if ( false !== $size ) {
						$total += $size;
					}
				}
			}
		}
		return $total;
	}

	private static function get_all_link_ids_for_maintenance() {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
	}

	private static function inspect_storage_directory( $managed_paths ) {
		$result = array(
			'empty_directories' => array(),
			'unknown_count'     => 0,
			'unknown_samples'   => array(),
		);
		$base_path = self::get_upload_base_path();
		if ( ! is_dir( $base_path ) || ! is_readable( $base_path ) ) {
			return $result;
		}

		$base_entries = scandir( $base_path );
		if ( false === $base_entries ) {
			return $result;
		}
		$base_entries = array_values( array_diff( $base_entries, array( '.', '..' ) ) );
		if ( empty( $base_entries ) ) {
			$result['empty_directories'][] = $base_path;
			return $result;
		}

		foreach ( $base_entries as $entry ) {
			$path = $base_path . '/' . $entry;
			if ( is_link( $path ) || ! is_dir( $path ) ) {
				$normalized = wp_normalize_path( $path );
				if ( ! isset( $managed_paths[ $normalized ] ) ) {
					$result['unknown_count']++;
					if ( count( $result['unknown_samples'] ) < 10 ) {
						$result['unknown_samples'][] = $entry;
					}
				}
				continue;
			}

			$entries = is_readable( $path ) ? scandir( $path ) : false;
			if ( false === $entries ) {
				$result['unknown_count']++;
				if ( count( $result['unknown_samples'] ) < 10 ) {
					$result['unknown_samples'][] = $entry . '/ (unreadable)';
				}
				continue;
			}
			$entries = array_values( array_diff( $entries, array( '.', '..' ) ) );
			if ( empty( $entries ) ) {
				$result['empty_directories'][] = $path;
				continue;
			}
			foreach ( $entries as $child ) {
				$child_path = $path . '/' . $child;
				$normalized = wp_normalize_path( $child_path );
				if ( ! isset( $managed_paths[ $normalized ] ) ) {
					$result['unknown_count']++;
					if ( count( $result['unknown_samples'] ) < 10 ) {
						$result['unknown_samples'][] = $entry . '/' . $child;
					}
				}
			}
		}

		return $result;
	}

	private static function get_maintenance_audit() {
		$audit = array(
			'orphaned_attachment_ids' => array(),
			'missing_local_file_ids'  => array(),
			'invalid_current_link_ids' => array(),
			'stale_route_keys'        => array(),
			'empty_directories'       => array(),
			'unknown_count'           => 0,
			'unknown_samples'         => array(),
		);
		$managed_paths = array();

		foreach ( self::get_all_managed_attachment_ids() as $attachment_id ) {
			$link_id = absint( get_post_meta( $attachment_id, self::META_LINK_ID, true ) );
			if ( ! $link_id || ! self::get_link( $link_id ) ) {
				$audit['orphaned_attachment_ids'][] = absint( $attachment_id );
			}
			$file = get_attached_file( $attachment_id );
			if ( $file ) {
				$managed_paths[ wp_normalize_path( $file ) ] = true;
			}
			if ( ! $file || ! is_file( $file ) ) {
				$audit['missing_local_file_ids'][] = absint( $attachment_id );
			}
		}

		foreach ( self::get_all_links() as $link ) {
			$current_id = absint( get_post_meta( $link->ID, self::META_CURRENT, true ) );
			$attachments = self::get_attachments( $link->ID );
			$current = $current_id ? get_post( $current_id ) : false;
			$valid = $current && 'attachment' === $current->post_type && self::attachment_belongs_to_link( $current_id, $link->ID );
			if ( ( $current_id && ! $valid ) || ( ! $current_id && ! empty( $attachments ) ) ) {
				$audit['invalid_current_link_ids'][] = $link->ID;
			}
		}

		$rules = get_option( 'rewrite_rules', array() );
		$current_base = self::get_url_base();
		$current_regex = $current_base ? self::get_route_regex( $current_base ) : '';
		if ( is_array( $rules ) ) {
			foreach ( $rules as $regex => $destination ) {
				if ( false !== strpos( (string) $destination, 'splsc_pdf_slug=' ) && $regex !== $current_regex ) {
					$audit['stale_route_keys'][] = $regex;
				}
			}
		}

		$storage = self::inspect_storage_directory( $managed_paths );
		$audit['empty_directories'] = $storage['empty_directories'];
		$audit['unknown_count'] = $storage['unknown_count'];
		$audit['unknown_samples'] = $storage['unknown_samples'];
		return $audit;
	}

	private static function remove_stale_cached_plugin_routes() {
		$rules = get_option( 'rewrite_rules', array() );
		if ( ! is_array( $rules ) ) {
			return 0;
		}
		$current_base = self::get_url_base();
		$current_regex = $current_base ? self::get_route_regex( $current_base ) : '';
		$removed = 0;
		foreach ( $rules as $regex => $destination ) {
			if ( false !== strpos( (string) $destination, 'splsc_pdf_slug=' ) && $regex !== $current_regex ) {
				unset( $rules[ $regex ] );
				$removed++;
			}
		}
		if ( $removed ) {
			update_option( 'rewrite_rules', $rules, false );
		}
		return $removed;
	}

	public static function handle_repair_maintenance() {
		self::require_capability( self::CAP_MANAGE );
		check_admin_referer( 'splsc_repair_maintenance' );
		$audit = self::get_maintenance_audit();
		$repaired = 0;

		foreach ( $audit['invalid_current_link_ids'] as $link_id ) {
			$attachments = self::get_attachments( $link_id );
			if ( ! empty( $attachments ) ) {
				update_post_meta( $link_id, self::META_CURRENT, $attachments[0]->ID );
			} else {
				delete_post_meta( $link_id, self::META_CURRENT );
			}
			$repaired++;
		}

		foreach ( $audit['orphaned_attachment_ids'] as $attachment_id ) {
			delete_post_meta( $attachment_id, self::META_LINK_ID );
			delete_post_meta( $attachment_id, self::META_PROTECTED );
			wp_update_post( array( 'ID' => $attachment_id, 'post_parent' => 0 ) );
			$repaired++;
		}

		$repaired += self::remove_stale_cached_plugin_routes();
		usort( $audit['empty_directories'], static function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		foreach ( $audit['empty_directories'] as $path ) {
			if ( is_dir( $path ) && self::directory_is_empty( $path ) && rmdir( $path ) ) {
				$repaired++;
			}
		}

		self::redirect_admin( 'maintenance_repaired', 0, array( 'repaired' => $repaired ), 'splsc-settings' );
	}

	public static function handle_reset_plugin_data() {
		self::require_capability( self::CAP_MANAGE );
		check_admin_referer( 'splsc_reset_plugin_data' );
		$reset_upload_base = self::get_upload_base_folder();
		$confirmation = isset( $_POST['reset_confirmation'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['reset_confirmation'] ) ) ) : '';
		if ( 'DELETE ALL DATA' !== $confirmation ) {
			self::redirect_admin( 'reset_confirmation_invalid', 0, array(), 'splsc-settings' );
		}

		$failed = 0;
		foreach ( self::get_all_managed_attachment_ids() as $attachment_id ) {
			if ( ! self::delete_attachment_permanently( $attachment_id ) ) {
				$failed++;
			}
		}
		if ( $failed ) {
			self::redirect_admin( 'reset_files_failed', 0, array( 'failed' => $failed ), 'splsc-settings' );
		}

		foreach ( self::get_all_link_ids_for_maintenance() as $link_id ) {
			if ( ! wp_delete_post( $link_id, true ) ) {
				$failed++;
			}
		}
		if ( $failed ) {
			self::redirect_admin( 'reset_links_failed', 0, array( 'failed' => $failed ), 'splsc-settings' );
		}

		$audit = self::get_maintenance_audit();
		usort( $audit['empty_directories'], static function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		foreach ( $audit['empty_directories'] as $path ) {
			if ( is_dir( $path ) && self::directory_is_empty( $path ) ) {
				rmdir( $path );
			}
		}
		$base_path = self::get_upload_base_path();
		if ( is_dir( $base_path ) && self::directory_is_empty( $base_path ) ) {
			rmdir( $base_path );
		}
		$leftovers = file_exists( $base_path ) || is_link( $base_path );

		self::remove_cached_plugin_routes( true );
		delete_option( self::OPTION_SETTINGS );
		delete_option( self::OPTION_REWRITE_PENDING );
		delete_option( self::OPTION_FALLBACK_PROBE );
		delete_transient( self::TRANSIENT_REWRITE_LOCK );
		self::redirect_admin( $leftovers ? 'reset_complete_leftovers' : 'reset_complete', 0, $leftovers ? array( 'leftover_base' => $reset_upload_base ) : array(), 'splsc-settings' );
	}

	private static function render_notice() {
		if ( empty( $_GET['splsc_message'] ) ) {
			return;
		}
		$key = sanitize_key( wp_unslash( $_GET['splsc_message'] ) );
		$removed = isset( $_GET['removed'] ) ? absint( $_GET['removed'] ) : 0;
		$failed = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;
		$repaired = isset( $_GET['repaired'] ) ? absint( $_GET['repaired'] ) : 0;
		$leftover_base = isset( $_GET['leftover_base'] ) ? sanitize_title( wp_unslash( $_GET['leftover_base'] ) ) : '';
		$messages = array(
			'settings_saved'                => array( 'success', 'General settings saved.' ),
			'settings_saved_base_changed'   => array( 'success', 'Settings saved. The old URL base was removed and the new route was refreshed using a soft database-only refresh. Update buttons and cache exclusions.' ),
			'settings_saved_upload_folder'  => array( 'success', 'Settings saved. The upload base folder was changed; no rewrite refresh was needed.' ),
			'fallback_test_supported'        => array( 'success', 'Missing-PDF fallback is supported by the current uploads URL and server path. You may now enable it.' ),
			'fallback_test_unsupported'      => array( 'warning', 'Missing-PDF fallback is not supported or the probe was intercepted before reaching WordPress. The option remains unavailable.' ),
			'fallback_test_inconclusive'     => array( 'warning', 'The missing-PDF fallback test was inconclusive because the probe request could not be verified. Leave the option disabled.' ),
			'routes_refreshed'               => array( 'success', 'The PDF route was refreshed using a soft database-only refresh.' ),
			'link_saved'                    => array( 'success', 'PDF Link saved.' ),
			'uploaded'                       => array( 'success', 'The new PDF was uploaded and made current.' ),
			'uploaded_cleanup_warning'       => array( 'warning', 'The PDF was uploaded, but one or more old versions could not be removed.' ),
			'current_changed'                => array( 'success', 'The selected version is now current.' ),
			'version_protected'              => array( 'success', 'The version is protected from automatic cleanup.' ),
			'protection_removed'             => array( 'success', 'Automatic-cleanup protection was removed.' ),
			'pdf_deleted'                    => array( 'success', 'The PDF version was permanently deleted.' ),
			'current_deleted_promoted'       => array( 'success', 'The current PDF was deleted and the newest remaining version was made current.' ),
			'cleanup_complete'               => array( 'success', 'Retention cleanup completed.' ),
			'cleanup_failed'                 => array( 'warning', 'Retention cleanup completed with deletion errors.' ),
			'cleanup_blocked'                => array( 'warning', 'Cleanup could not reach the absolute limit because current or protected files occupy the required slots.' ),
			'link_deleted'                   => array( 'success', 'The PDF Link, managed PDFs, and empty storage folder were deleted.' ),
			'link_deleted_folder_retained'   => array( 'warning', 'The PDF Link and managed PDFs were deleted, but the folder was retained because it was not empty or could not be removed.' ),
			'link_deleted_files_retained'    => array( 'success', 'The PDF Link was removed. Its PDFs remain as ordinary Media Library attachments.' ),
			'maintenance_repaired'           => array( 'success', 'Safe maintenance repairs completed. No PDF files or unknown files were deleted.' ),
			'reset_complete'                 => array( 'success', 'All plugin-managed data was deleted. You can now deactivate and delete the plugin.' ),
			'reset_complete_leftovers'       => array( 'warning', 'Plugin-managed data was deleted, but unknown files or folders remain in the upload base and were not removed.' ),
			'configure_settings'             => array( 'error', 'Configure and save General Settings before creating PDF Links.' ),
			'settings_base_required'         => array( 'error', 'A public URL base is required.' ),
			'settings_base_long'             => array( 'error', 'The public URL base is too long.' ),
			'settings_limit_invalid'         => array( 'error', 'The default absolute limit must be at least the default retention target.' ),
			'settings_base_conflict'         => array( 'error', 'That URL base conflicts with known WordPress content or a reserved path.' ),
			'upload_base_required'           => array( 'error', 'An upload base folder is required.' ),
			'upload_base_long'               => array( 'error', 'The upload base folder name is too long.' ),
			'upload_base_locked'             => array( 'error', 'The upload base folder cannot be changed while managed PDFs or any files remain in the current base folder.' ),
			'upload_base_not_empty'          => array( 'error', 'The requested upload base folder already exists and is not empty.' ),
			'fallback_requires_test'         => array( 'error', 'Run a successful missing-PDF fallback feasibility test for the current upload base before enabling this option.' ),
			'missing_fields'                 => array( 'error', 'Name, public slug, and storage folder are required.' ),
			'link_limit_invalid'             => array( 'error', 'The absolute file limit must be at least the retention target.' ),
			'link_limit_protected'           => array( 'error', 'The absolute limit is lower than the number of current and protected files that must be retained. Unprotect or delete a version first.' ),
			'name_in_use'                    => array( 'error', 'That name is already used by another PDF Link.' ),
			'slug_in_use'                    => array( 'error', 'That public slug is already used by another PDF Link.' ),
			'slug_conflict'                  => array( 'error', 'That public path conflicts with known WordPress content.' ),
			'folder_in_use'                  => array( 'error', 'That storage folder is already used by another PDF Link.' ),
			'folder_locked'                  => array( 'error', 'The storage folder cannot be changed while managed or unknown files remain in it.' ),
			'target_folder_not_empty'        => array( 'error', 'The requested storage folder already exists and is not empty.' ),
			'save_failed'                    => array( 'error', 'WordPress could not save the PDF Link.' ),
			'invalid_link'                   => array( 'error', 'The selected PDF Link does not exist.' ),
			'invalid_pdf'                    => array( 'error', 'That attachment does not belong to this PDF Link.' ),
			'hard_limit_blocked'             => array( 'error', 'Upload blocked: protected files plus the new current file would exceed the absolute limit. Unprotect or delete a version first.' ),
			'select_pdf'                     => array( 'error', 'Select a PDF file first.' ),
			'upload_server_rejected'         => array( 'error', 'The web server rejected the upload. Check its size and the server PHP limits.' ),
			'upload_too_large'               => array( 'error', 'The PDF exceeds the effective upload-size limit.' ),
			'invalid_filename'               => array( 'error', 'Enter a valid public filename.' ),
			'folder_create_failed'           => array( 'error', 'WordPress could not create the storage folder. Check upload-directory permissions.' ),
			'upload_failed'                  => array( 'error', 'WordPress rejected or could not store the upload. Confirm that it is a valid PDF.' ),
			'upload_rolled_back'             => array( 'error', 'The upload was rolled back because cleanup could not satisfy the absolute file limit.' ),
			'last_current_protected'         => array( 'error', 'The only remaining current PDF cannot be deleted. Upload another version first.' ),
			'delete_failed'                  => array( 'error', 'WordPress could not delete the PDF.' ),
			'link_delete_files_failed'       => array( 'error', 'One or more PDFs could not be deleted, so the PDF Link configuration was retained.' ),
			'link_delete_failed'             => array( 'error', 'WordPress could not delete the PDF Link configuration.' ),
			'reset_confirmation_invalid'     => array( 'error', 'Full reset was cancelled because the confirmation text did not match.' ),
			'reset_files_failed'             => array( 'error', 'Full reset stopped because one or more managed PDF attachments could not be deleted. Link definitions and settings were retained.' ),
			'reset_links_failed'             => array( 'error', 'Managed PDFs were removed, but one or more PDF Link records could not be deleted. Settings were retained.' ),
		);
		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}
		$type = $messages[ $key ][0];
		$text = $messages[ $key ][1];
		if ( $removed ) {
			$text .= ' Removed: ' . $removed . '.';
		}
		if ( $failed ) {
			$text .= ' Failed: ' . $failed . '.';
		}
		if ( $repaired ) {
			$text .= ' Repaired: ' . $repaired . '.';
		}
		if ( $leftover_base ) {
			$text .= ' Review /wp-content/uploads/' . $leftover_base . '/ manually.';
		}
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $text ); ?></p></div>
		<?php
	}

	private static function render_styles() {
		?>
		<style>
			.splsc-wrap .splsc-card{background:#fff;border:1px solid #c3c4c7;box-shadow:0 1px 1px rgba(0,0,0,.04);margin:20px 0;max-width:1050px;padding:20px}
			.splsc-wrap .splsc-danger{border-left:4px solid #d63638}.splsc-wrap .splsc-current{color:#008a20;font-weight:600}.splsc-wrap .splsc-protected{color:#8a4b00;font-weight:600}
			.splsc-wrap .splsc-code{font-family:Consolas,Monaco,monospace;width:100%;max-width:760px}.splsc-wrap .splsc-actions{display:flex;flex-wrap:wrap;gap:7px;align-items:center}
			.splsc-wrap .splsc-actions form{margin:0}.splsc-check{display:inline-block;margin-left:8px;font-weight:600}.splsc-ok{color:#008a20}.splsc-bad{color:#b32d2e}
			.splsc-status{display:grid;grid-template-columns:minmax(180px,260px) 1fr;gap:8px 18px;max-width:850px}.splsc-status div{padding:5px 0;border-bottom:1px solid #eee}
		</style>
		<?php
	}

	private static function render_live_check_script( $link_id = 0 ) {
		$nonce = wp_create_nonce( 'splsc_check_values' );
		?>
		<script>
		(function(){
			const fields={name:document.getElementById('link_name'),slug:document.getElementById('public_slug'),folder:document.getElementById('storage_folder')};
			let timer;
			function run(){
				const data=new URLSearchParams({action:'splsc_check_values',nonce:<?php echo wp_json_encode( $nonce ); ?>,link_id:<?php echo absint( $link_id ); ?>,name:fields.name?fields.name.value:'',slug:fields.slug?fields.slug.value:'',folder:fields.folder?fields.folder.value:''});
				fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:data.toString()}).then(r=>r.json()).then(r=>{
					if(!r.success)return;
					Object.keys(fields).forEach(k=>{const out=document.getElementById('splsc-check-'+k);if(out&&r.data[k]){out.textContent=r.data[k].message;out.className='splsc-check '+(r.data[k].ok?'splsc-ok':'splsc-bad');}});
				});
			}
			Object.values(fields).forEach(f=>{if(f&&!f.disabled)f.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(run,350);});});
			run();
		})();
		</script>
		<?php
	}

	public static function render_link_list_page() {
		self::require_capability( self::CAP_VERSIONS );
		$link_id = isset( $_GET['link_id'] ) ? absint( $_GET['link_id'] ) : 0;
		if ( $link_id ) {
			self::render_manage_link_page( $link_id );
			return;
		}

		$links = self::get_all_links();
		$base = self::get_url_base();
		?>
		<div class="wrap splsc-wrap">
			<h1 class="wp-heading-inline">PDF Links</h1>
			<?php if ( current_user_can( self::CAP_MANAGE ) && $base ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=splsc-add-link' ) ); ?>" class="page-title-action">Add PDF Link</a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php self::render_notice(); self::render_styles(); ?>

			<?php if ( ! $base ) : ?>
				<div class="notice notice-warning"><p><strong>Initial setup is required.</strong> An administrator must save the public URL base in General Settings before PDF Links can be created.</p></div>
				<?php if ( current_user_can( self::CAP_MANAGE ) ) : ?><p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=splsc-settings' ) ); ?>">Open General Settings</a></p><?php endif; ?>
			<?php elseif ( empty( $links ) ) : ?>
				<div class="splsc-card"><p><strong>No PDF Links exist yet.</strong></p><?php if ( current_user_can( self::CAP_MANAGE ) ) : ?><p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=splsc-add-link' ) ); ?>">Create the first PDF Link</a></p><?php endif; ?></div>
			<?php else : ?>
				<table class="widefat striped"><thead><tr><th>Name</th><th>Permanent URL</th><th>Files</th><th>Target / limit</th><th>Action</th></tr></thead><tbody>
				<?php foreach ( $links as $link ) : $files = self::get_attachments( $link->ID ); ?>
					<tr><td><strong><?php echo esc_html( $link->post_title ); ?></strong></td><td><a href="<?php echo esc_url( self::get_public_url( $link->ID ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( self::get_public_url( $link->ID ) ); ?></a></td><td><?php echo esc_html( count( $files ) ); ?></td><td><?php echo esc_html( self::get_target( $link->ID ) . ' / ' . self::get_limit( $link->ID ) ); ?></td><td><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'link_id' => $link->ID ), admin_url( 'admin.php' ) ) ); ?>">Manage</a></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_add_link_page() {
		self::require_capability( self::CAP_MANAGE );
		if ( ! self::get_url_base() ) {
			self::redirect_admin( 'configure_settings' );
		}
		$settings = self::get_settings();
		?>
		<div class="wrap splsc-wrap"><h1>Add PDF Link</h1><?php self::render_notice(); self::render_styles(); ?>
		<div class="splsc-card"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="splsc_save_link"><?php wp_nonce_field( 'splsc_save_link' ); ?>
		<?php self::render_link_fields( 0, '', '', '', absint( $settings['default_target'] ), absint( $settings['default_limit'] ), false ); ?>
		<?php submit_button( 'Create PDF Link' ); ?></form></div><?php self::render_live_check_script(); ?></div>
		<?php
	}

	private static function render_link_fields( $link_id, $name, $slug, $folder, $target, $limit, $folder_locked ) {
		$base = self::get_url_base();
		?>
		<table class="form-table">
		<tr><th scope="row"><label for="link_name">Name</label></th><td><input type="text" name="link_name" id="link_name" class="regular-text" value="<?php echo esc_attr( $name ); ?>" required><span id="splsc-check-name" class="splsc-check"></span><p class="description">Administrative name, for example Weekly Price List.</p></td></tr>
		<tr><th scope="row"><label for="public_slug">Public URL slug</label></th><td><input type="text" name="public_slug" id="public_slug" class="regular-text" value="<?php echo esc_attr( $slug ); ?>" required><span id="splsc-check-slug" class="splsc-check"></span><p class="description">Public URL: <code><?php echo esc_html( home_url( '/' . trailingslashit( $base . '/your-slug' ) ) ); ?></code></p></td></tr>
		<tr><th scope="row"><label for="storage_folder">Storage folder</label></th><td>
		<?php if ( $folder_locked ) : ?><input type="text" id="storage_folder" class="regular-text" value="<?php echo esc_attr( $folder ); ?>" disabled><input type="hidden" name="storage_folder" value="<?php echo esc_attr( $folder ); ?>"><p class="description">Locked while managed or unknown files remain in this directory.</p>
		<?php else : ?><input type="text" name="storage_folder" id="storage_folder" class="regular-text" value="<?php echo esc_attr( $folder ); ?>" required><span id="splsc-check-folder" class="splsc-check"></span><p class="description">Stored below <code>/wp-content/uploads/<?php echo esc_html( self::get_upload_base_folder() ); ?>/</code>.</p><?php endif; ?></td></tr>
		<tr><th scope="row"><label for="retention_target">Recent versions to keep</label></th><td><input type="number" name="retention_target" id="retention_target" value="<?php echo esc_attr( $target ); ?>" min="1" max="1000" required><p class="description">Newest versions retained automatically. Current and protected versions are exceptions.</p></td></tr>
		<tr><th scope="row"><label for="absolute_limit">Absolute file limit</label></th><td><input type="number" name="absolute_limit" id="absolute_limit" value="<?php echo esc_attr( $limit ); ?>" min="1" max="1000" required><p class="description">Includes current and protected files. Must be at least the retention target.</p></td></tr>
		</table>
		<?php
	}

	private static function render_manage_link_page( $link_id ) {
		self::require_capability( self::CAP_VERSIONS );
		$link = self::get_link( $link_id );
		if ( ! $link ) {
			wp_die( 'The selected PDF Link does not exist.' );
		}

		$folder = self::get_link_folder( $link_id );
		$files = self::get_attachments( $link_id );
		$current = self::get_current_attachment( $link_id );
		$protected_count = 0;
		foreach ( $files as $file ) {
			if ( self::is_protected( $file->ID ) ) { $protected_count++; }
		}
		?>
		<div class="wrap splsc-wrap"><h1><?php echo esc_html( $link->post_title ); ?></h1><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">&larr; Back to all PDF Links</a></p>
		<?php self::render_notice(); self::render_styles(); ?>
		<div class="splsc-card"><h2>Permanent link</h2><input type="text" class="splsc-code" readonly onclick="this.select();" value="<?php echo esc_attr( self::get_public_url( $link_id ) ); ?>"><p><a class="button" href="<?php echo esc_url( self::get_public_url( $link_id ) ); ?>" target="_blank" rel="noopener noreferrer">Test permanent link</a></p></div>

		<div class="splsc-card"><h2>Upload new PDF</h2>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="splsc_upload_pdf"><input type="hidden" name="link_id" value="<?php echo esc_attr( $link_id ); ?>"><?php wp_nonce_field( 'splsc_upload_pdf_' . $link_id ); ?>
		<table class="form-table"><tr><th><label for="splsc_pdf">PDF file</label></th><td><input type="file" name="splsc_pdf" id="splsc_pdf" accept=".pdf,application/pdf" required></td></tr>
		<tr><th><label for="public_filename">Public filename</label></th><td><input type="text" name="public_filename" id="public_filename" class="regular-text" placeholder="leave empty to use the selected filename"><p class="description">The visitor sees this physical filename after redirecting. The plugin sanitizes it and forces <code>.pdf</code>.</p></td></tr></table>
		<?php submit_button( 'Upload and make current' ); ?><p class="description">Effective maximum: <?php echo esc_html( size_format( self::effective_upload_limit_bytes() ) ); ?>. Protected files: <?php echo esc_html( $protected_count ); ?>. Absolute limit: <?php echo esc_html( self::get_limit( $link_id ) ); ?>.</p></form></div>

		<div class="splsc-card"><h2>PDF versions</h2>
		<?php if ( empty( $files ) ) : ?><p>No PDFs have been uploaded.</p><?php else : ?>
		<table class="widefat striped"><thead><tr><th>Physical filename</th><th>Uploaded</th><th>Size</th><th>Status and actions</th></tr></thead><tbody>
		<?php foreach ( $files as $file ) : $path = get_attached_file( $file->ID ); $is_current = $current && $current->ID === $file->ID; $is_protected = self::is_protected( $file->ID ); $size = $path && is_file( $path ) ? filesize( $path ) : false; ?>
		<tr><td><a href="<?php echo esc_url( self::get_versioned_attachment_url( $file->ID ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $path ? basename( $path ) : get_the_title( $file ) ); ?></a></td>
		<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_post_time( 'U', true, $file ) ) ); ?></td><td><?php echo false !== $size ? esc_html( size_format( $size ) ) : '&mdash;'; ?></td>
		<td><div class="splsc-actions"><?php if ( $is_current ) : ?><span class="splsc-current">CURRENT</span><?php else : self::render_version_action_form( 'splsc_make_current', 'Make current', $link_id, $file->ID, 'splsc_make_current_' . $link_id . '_' . $file->ID, '' ); endif; ?>
		<?php if ( $is_protected ) : ?><span class="splsc-protected">PROTECTED</span><?php endif; self::render_version_action_form( 'splsc_toggle_protection', $is_protected ? 'Remove protection' : 'Protect', $link_id, $file->ID, 'splsc_toggle_protection_' . $link_id . '_' . $file->ID, '' ); ?>
		<?php if ( ! $is_current || count( $files ) > 1 ) : self::render_version_action_form( 'splsc_delete_pdf', 'Delete permanently', $link_id, $file->ID, 'splsc_delete_pdf_' . $link_id . '_' . $file->ID, $is_current ? 'Delete the current PDF and promote the newest remaining version?' : 'Permanently delete this PDF?' ); endif; ?></div></td></tr>
		<?php endforeach; ?></tbody></table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px" onsubmit="return confirm('Apply retention now and permanently delete eligible old versions?');"><input type="hidden" name="action" value="splsc_apply_retention"><input type="hidden" name="link_id" value="<?php echo esc_attr( $link_id ); ?>"><?php wp_nonce_field( 'splsc_apply_retention_' . $link_id ); ?><button class="button">Apply retention now</button></form>
		<?php endif; ?></div>

		<?php if ( current_user_can( self::CAP_MANAGE ) ) : ?>
		<div class="splsc-card"><h2>PDF Link settings</h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="splsc_save_link"><input type="hidden" name="link_id" value="<?php echo esc_attr( $link_id ); ?>"><?php wp_nonce_field( 'splsc_save_link' ); ?>
		<?php self::render_link_fields( $link_id, $link->post_title, self::get_link_slug( $link_id ), $folder, self::get_target( $link_id ), self::get_limit( $link_id ), self::folder_is_locked( $link_id, $folder ) ); ?><?php submit_button( 'Save PDF Link' ); ?></form></div><?php self::render_live_check_script( $link_id ); ?>

		<div class="splsc-card splsc-danger"><h2>Delete PDF Link</h2><p>Both actions remove the public URL and configuration.</p><div class="splsc-actions">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Remove this PDF Link but retain all PDFs as ordinary Media Library attachments?');"><input type="hidden" name="action" value="splsc_delete_link"><input type="hidden" name="link_id" value="<?php echo esc_attr( $link_id ); ?>"><input type="hidden" name="delete_mode" value="retain"><?php wp_nonce_field( 'splsc_delete_link_' . $link_id ); ?><button class="button">Remove link, retain PDFs</button></form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Permanently delete this PDF Link and all PDFs managed by it? Unknown files are never deleted.');"><input type="hidden" name="action" value="splsc_delete_link"><input type="hidden" name="link_id" value="<?php echo esc_attr( $link_id ); ?>"><input type="hidden" name="delete_mode" value="purge"><?php wp_nonce_field( 'splsc_delete_link_' . $link_id ); ?><button class="button button-link-delete">Delete link and managed PDFs</button></form>
		</div></div><?php endif; ?></div>
		<?php
	}

	private static function render_version_action_form( $action, $label, $link_id, $attachment_id, $nonce_action, $confirm ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : ''; ?>>
		<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>"><input type="hidden" name="link_id" value="<?php echo esc_attr( $link_id ); ?>"><input type="hidden" name="attachment_id" value="<?php echo esc_attr( $attachment_id ); ?>"><?php wp_nonce_field( $nonce_action ); ?><button class="button button-small<?php echo 'splsc_delete_pdf' === $action ? ' button-link-delete' : ''; ?>"><?php echo esc_html( $label ); ?></button></form>
		<?php
	}

	public static function render_settings_page() {
		self::require_capability( self::CAP_MANAGE );
		$settings = self::get_settings();
		$upload = wp_upload_dir();
		$base = sanitize_title( $settings['url_base'] );
		$upload_base_folder = self::get_upload_base_folder();
		$upload_base_locked = self::upload_base_is_locked();
		$rules = get_option( 'rewrite_rules', array() );
		$route_cached = $base && is_array( $rules ) && array_key_exists( self::get_route_regex( $base ), $rules );
		$links = self::get_all_links();
		$total_versions = 0;
		foreach ( $links as $link ) { $total_versions += count( self::get_attachments( $link->ID ) ); }
		$audit = self::get_maintenance_audit();
		$repairable = count( $audit['invalid_current_link_ids'] ) + count( $audit['orphaned_attachment_ids'] ) + count( $audit['stale_route_keys'] ) + count( $audit['empty_directories'] );
		$probe = get_option( self::OPTION_FALLBACK_PROBE, array() );
		$probe_is_current = is_array( $probe ) && hash_equals( self::get_fallback_probe_fingerprint(), isset( $probe['fingerprint'] ) ? (string) $probe['fingerprint'] : '' );
		$probe_status = $probe_is_current && isset( $probe['status'] ) ? sanitize_key( $probe['status'] ) : 'not_tested';
		$probe_status_html = '<span class="splsc-bad">Not tested</span>';
		if ( 'supported' === $probe_status ) {
			$probe_status_html = '<span class="splsc-ok">Supported</span>';
		} elseif ( 'unsupported' === $probe_status ) {
			$probe_status_html = '<span class="splsc-bad">Not supported or intercepted</span>';
		} elseif ( 'inconclusive' === $probe_status ) {
			$probe_status_html = '<span class="splsc-bad">Inconclusive</span>';
		}
		if ( $probe_is_current && ! empty( $probe['checked_at'] ) ) {
			$probe_status_html .= ' — checked ' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $probe['checked_at'] ) ) );
		}
		?>
		<div class="wrap splsc-wrap"><h1>PDF Links — General Settings</h1><?php self::render_notice(); self::render_styles(); ?>
		<div class="splsc-card"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="splsc_save_settings"><?php wp_nonce_field( 'splsc_save_settings' ); ?>
		<table class="form-table">
		<tr><th><label for="url_base">Public URL base</label></th><td><input type="text" name="url_base" id="url_base" class="regular-text" value="<?php echo esc_attr( $settings['url_base'] ); ?>" placeholder="documents" required><p class="description">One path segment. Example: <code>documents</code> produces <code>/documents/weekly/</code>. Changing it removes the old base, changes every public URL, and performs one soft database-only rewrite refresh.</p></td></tr>
		<tr><th><label for="upload_base_folder">Upload base folder</label></th><td><input type="text" name="upload_base_folder" id="upload_base_folder" class="regular-text" value="<?php echo esc_attr( $upload_base_folder ); ?>" placeholder="stable-pdf-links" maxlength="80" required <?php echo $upload_base_locked ? 'readonly' : ''; ?>><p class="description">One folder below <code>/wp-content/uploads/</code>. <?php echo $upload_base_locked ? 'Locked because managed PDFs or other files exist in the current folder. Use Full reset before changing it.' : 'Editable until the folder contains a managed or unknown file. Changing it does not refresh routes.'; ?></p></td></tr>
		<tr><th><label for="max_upload_mb">Maximum PDF upload size</label></th><td><input type="number" name="max_upload_mb" id="max_upload_mb" value="<?php echo esc_attr( $settings['max_upload_mb'] ); ?>" min="1" max="1024" required> MB<p class="description">Can lower but cannot raise the server limit of <?php echo esc_html( size_format( wp_max_upload_size() ) ); ?>.</p></td></tr>
		<tr><th><label for="default_target">Default recent versions</label></th><td><input type="number" name="default_target" id="default_target" value="<?php echo esc_attr( $settings['default_target'] ); ?>" min="1" max="1000" required><p class="description">Used for newly created PDF Links only.</p></td></tr>
		<tr><th><label for="default_limit">Default absolute limit</label></th><td><input type="number" name="default_limit" id="default_limit" value="<?php echo esc_attr( $settings['default_limit'] ); ?>" min="1" max="1000" required><p class="description">Includes current and protected files. Used for newly created PDF Links only.</p></td></tr>
		<tr><th>Editor access</th><td><label><input type="checkbox" name="allow_editors" value="1" <?php checked( ! empty( $settings['allow_editors'] ) ); ?>> Allow users with the built-in Editor role to upload, select, protect, and delete PDF versions</label><p class="description">Editors cannot change General Settings or create/delete PDF Links.</p></td></tr>
		<tr><th>Missing-PDF fallback</th><td><label><input type="checkbox" name="missing_pdf_fallback" value="1" <?php checked( ! empty( $settings['missing_pdf_fallback'] ) ); ?>> Redirect a missing PDF inside a registered storage folder to that PDF Link's stable URL</label><p class="description">Disabled by default. A successful feasibility test for the current upload base is required before this can be enabled. Existing files are never intercepted.</p></td></tr>
		</table><?php submit_button( $base ? 'Save General Settings' : 'Complete Initial Setup' ); ?></form></div>

		<div class="splsc-card"><h2>System check</h2><div class="splsc-status">
		<div>Pretty permalinks</div><div><?php echo get_option( 'permalink_structure' ) ? '<span class="splsc-ok">Enabled</span>' : '<span class="splsc-bad">Disabled — choose a non-Plain permalink structure.</span>'; ?></div>
		<div>Upload directory</div><div><?php echo empty( $upload['error'] ) && is_dir( $upload['basedir'] ) && is_writable( $upload['basedir'] ) ? '<span class="splsc-ok">Writable</span>' : '<span class="splsc-bad">Not writable or unavailable.</span>'; ?></div>
		<div>Upload base folder</div><div><code><?php echo esc_html( $upload_base_folder ); ?></code> — <?php echo $upload_base_locked ? 'locked while files remain' : '<span class="splsc-ok">editable</span>'; ?></div>
		<div>Server upload ceiling</div><div><?php echo esc_html( size_format( wp_max_upload_size() ) ); ?></div>
		<div>Effective plugin limit</div><div><?php echo esc_html( size_format( self::effective_upload_limit_bytes() ) ); ?></div>
		<div>URL base</div><div><?php echo $base ? ( self::base_has_known_conflict( $base ) ? '<span class="splsc-bad">Known conflict detected</span>' : '<span class="splsc-ok">No known content conflict</span>' ) : '<span class="splsc-bad">Not configured</span>'; ?></div>
		<div>Cached route</div><div><?php echo $route_cached ? '<span class="splsc-ok">Present</span>' : ( $base ? '<span class="splsc-bad">Not present. Use the soft refresh button below.</span>' : 'Waiting for setup' ); ?></div>
		<div>Missing-PDF fallback</div><div><?php echo $probe_status_html; ?> — <?php echo ! empty( $settings['missing_pdf_fallback'] ) ? '<strong>enabled</strong>' : 'disabled'; ?></div>
		<div>Managed PDF Links</div><div><?php echo esc_html( count( $links ) ); ?></div><div>Managed PDF versions</div><div><?php echo esc_html( $total_versions ); ?></div><div>Managed PDF storage</div><div><?php echo esc_html( size_format( self::get_managed_storage_bytes() ) ); ?></div>
		</div><p class="description">“No known conflict” cannot guarantee compatibility with every dynamic route created by every third-party plugin. Test each permanent link after deployment.</p>
		<div class="splsc-actions"><?php if ( $base ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="splsc_refresh_routes"><?php wp_nonce_field( 'splsc_refresh_routes' ); ?><button class="button">Soft-refresh PDF route</button></form><?php endif; ?>
		<button type="button" class="button" id="splsc-browser-probe">Test missing-PDF fallback</button><span id="splsc-browser-probe-status" class="description"></span>
		<noscript><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="splsc_test_missing_pdf_fallback"><?php wp_nonce_field( 'splsc_test_missing_pdf_fallback' ); ?><button class="button">Run server-side fallback test</button></form></noscript></div><p class="description">The primary test uses this browser to request a unique nonexistent PDF, then asks WordPress to verify that the request arrived. It creates no file and stores only the latest status. The server-side test shown without JavaScript may be inconclusive when hosting blocks loopback requests.</p>
		<script>
		(function(){
			const button=document.getElementById('splsc-browser-probe');
			const output=document.getElementById('splsc-browser-probe-status');
			if(!button)return;
			const endpoint=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			const nonce=<?php echo wp_json_encode( wp_create_nonce( 'splsc_browser_fallback_probe' ) ); ?>;
			async function post(data){
				const body=new URLSearchParams(Object.assign({nonce:nonce},data));
				const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});
				return response.json();
			}
			button.addEventListener('click',async function(){
				button.disabled=true;output.textContent=' Testing…';
				try{
					const prepared=await post({action:'splsc_prepare_fallback_probe'});
					if(!prepared.success||!prepared.data||!prepared.data.url||!prepared.data.token){throw new Error('prepare');}
					let browserError=0;
					try{await fetch(prepared.data.url,{method:'GET',mode:'no-cors',credentials:'same-origin',cache:'no-store',redirect:'follow'});}catch(e){browserError=1;}
					const finished=await post({action:'splsc_finish_fallback_probe',token:prepared.data.token,browser_error:browserError});
					if(!finished.success||!finished.data||!finished.data.message_key){throw new Error('finish');}
					const destination=new URL(window.location.href);destination.searchParams.set('splsc_message',finished.data.message_key);window.location.assign(destination.toString());
				}catch(e){output.textContent=' The browser test could not be completed. Leave the option disabled and retry after checking browser or security restrictions.';button.disabled=false;}
			});
		})();
		</script></div>

		<div class="splsc-card"><h2>Cache exclusions</h2><p>Add the following routes to the “Never cache,” “Exclude URLs,” or equivalent setting in your cache plugin, host cache, reverse proxy, and CDN. Without exclusions, compliant caches should honor this plugin's no-cache headers, but a cache configured to override them can serve an old redirect until it expires or is purged.</p>
		<?php if ( ! $base ) : ?><p>Complete initial setup first.</p><?php else : ?><p>Use this wildcard if supported:</p><input type="text" class="splsc-code" readonly onclick="this.select();" value="<?php echo esc_attr( '/' . $base . '/*' ); ?>">
		<?php if ( $links ) : ?><p>Or exclude these exact paths:</p><textarea class="large-text code" rows="<?php echo esc_attr( min( 12, max( 3, count( $links ) ) ) ); ?>" readonly onclick="this.select();"><?php foreach ( $links as $link ) { echo esc_textarea( '/' . $base . '/' . self::get_link_slug( $link->ID ) . "/\n" ); } ?></textarea><?php endif; ?>
		<p><strong>Do not exclude the entire uploads directory.</strong> The actual PDFs use unique filenames and may remain cached. After changing the base, update these exclusions and purge cached redirects.</p><?php endif; ?></div>

		<div class="splsc-card"><h2>Maintenance audit</h2><p>This scan checks plugin-owned WordPress records, the plugin upload base, and cached plugin rewrite entries. It never contacts or purges an external page cache, host cache, proxy, or CDN.</p>
		<div class="splsc-status">
		<div>Invalid current pointers</div><div><?php echo esc_html( count( $audit['invalid_current_link_ids'] ) ); ?></div>
		<div>Orphaned managed attachments</div><div><?php echo esc_html( count( $audit['orphaned_attachment_ids'] ) ); ?></div>
		<div>Missing local files</div><div><?php echo esc_html( count( $audit['missing_local_file_ids'] ) ); ?> <span class="description">(may be normal when media is offloaded)</span></div>
		<div>Stale plugin route entries</div><div><?php echo esc_html( count( $audit['stale_route_keys'] ) ); ?></div>
		<div>Empty plugin directories</div><div><?php echo esc_html( count( $audit['empty_directories'] ) ); ?></div>
		<div>Unknown files or entries</div><div><?php echo esc_html( $audit['unknown_count'] ); ?></div>
		</div>
		<?php if ( ! empty( $audit['unknown_samples'] ) ) : ?><p><strong>Unknown-item sample:</strong> <code><?php echo esc_html( implode( ', ', $audit['unknown_samples'] ) ); ?></code></p><?php endif; ?>
		<p class="description">Safe repair restores current pointers, detaches orphaned PDFs into the normal Media Library, removes stale plugin route entries, and removes empty directories. It does not delete PDFs, missing/offloaded attachment records, or unknown files.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="splsc_repair_maintenance"><?php wp_nonce_field( 'splsc_repair_maintenance' ); ?><button class="button" <?php disabled( 0 === $repairable ); ?>>Repair safe issues<?php echo $repairable ? ' (' . esc_html( $repairable ) . ')' : ''; ?></button></form></div>

		<div class="splsc-card splsc-danger"><h2>Full reset</h2><p>Permanently deletes every PDF attachment managed by this plugin, every PDF Link definition, plugin settings, and plugin route entries. Empty plugin directories are removed; unknown files are preserved. The plugin remains active so you can review the result, then deactivate and delete it.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Permanently delete all plugin-managed PDFs, links, and settings? This cannot be undone.');"><input type="hidden" name="action" value="splsc_reset_plugin_data"><?php wp_nonce_field( 'splsc_reset_plugin_data' ); ?><p><label for="reset_confirmation">Type <code>DELETE ALL DATA</code> to continue:</label></p><input type="text" name="reset_confirmation" id="reset_confirmation" class="regular-text" autocomplete="off" required><p><button class="button button-link-delete">Delete all plugin data</button></p></form></div>
		</div>
		<?php
	}
}

SPLSC_Stable_PDF_Links::boot();

register_activation_hook( __FILE__, array( 'SPLSC_Stable_PDF_Links', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SPLSC_Stable_PDF_Links', 'deactivate' ) );
