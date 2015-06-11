<?php (__FILE__ == $_SERVER['SCRIPT_FILENAME']) ? die(header('Location: /')) : null;

if (!class_exists('qsot_templates')):

class qsot_templates {
	protected static $o = null; // holder for all options of the events plugin

	public static function pre_init() {
		// load the settings. theya re required for everything past this point
		$settings_class_name = apply_filters('qsot-settings-class-name', '');
		if (empty($settings_class_name)) return;
		self::$o = call_user_func_array(array($settings_class_name, "instance"), array());

		// qsot template locator. checks theme first, then our templates dir
		add_filter('qsot-locate-template', array(__CLASS__, 'locate_template'), 10, 4);

		// similar to above, only specifically for templates that we may have overriden from woo.... like admin templates
		add_filter('qsot-woo-template', array(__CLASS__, 'locate_woo_template'), 10, 2);
		add_filter('woocommerce_locate_template', array(__CLASS__, 'wc_locate_template'), 10, 3);

		add_filter('init', array(__CLASS__, 'rig_theme_page_template_cache'), 0);
		add_filter('theme_page_templates', array(__CLASS__, 'add_extra_templates'), 10, 1);
		add_filter('template_include', array(__CLASS__, 'intercept_page_template_request'), 100, 1);
	}

	public static function rig_theme_page_template_cache() {
		// get a list of page templates not in the theme
		$extras = apply_filters('qsot-templates-page-templates', array());
		// load the theme
		$theme = wp_get_theme();
		// build the cache hash, used for the cache keys
		$cache_hash = md5($theme->get_theme_root().'/'.$theme->get_stylesheet());
		// force the cache key to generate, in case it hasn't already
		$theme->get_page_templates();
		// fetch the current list of templates
		$list = wp_cache_get('page_templates-'.$cache_hash, 'themes');
		// add our list
		$list = array_merge($list, $extras);
		// save the list again
		wp_cache_set('page_templates-'.$cache_hash, $list, 'themes', 1800);
		// profit!
	}

	public static function add_extra_templates($list) {
		$extras = apply_filters('qsot-templates-page-templates', array());
		return array_merge($list, $extras);
	}

	public static function intercept_page_template_request($current) {
		if ( is_page() ) {
			$intercept = apply_filters('qsot-templates-page-templates', array());

			$id = get_queried_object_id();
			$template = get_page_template_slug();
			$pagename = get_query_var('pagename');

			if ( isset( $intercept[ $template ] ) ) {
				if ( ! $pagename && $id ) {
					// If a static page is set as the front page, $pagename will not be set. Retrieve it from the queried object
					$post = get_queried_object();
					if ( $post )
						$pagename = $post->post_name;
				}

				$templates = array();

				if ( $template && 0 === validate_file( $template ) )
					$templates[] = $template;
				/*
				if ( $pagename )
					$templates[] = "page-$pagename.php";
				if ( $id )
					$templates[] = "page-$id.php";
				$templates[] = 'page.php';
				*/

				$current = apply_filters('qsot-locate-template', $current, $templates);
			}
		}

		return $current;
	}

	// locate a given template. first check the theme for it, then our plugin dirs for fallbacks
	public static function locate_template( $current='', $files=array(), $load=false, $require_once=false ) {
		// normalize the list of potential files
		$files = ! empty( $files ) ? (array)$files : $files;

		// if we have a list of files
		if ( is_array( $files ) && count( $files ) ) {
			// first search the theme
			$templ = locate_template( $files, $load, $require_once );

			// if there was not a matching file in the theme, then search our backup dirs
			if ( empty( $templ ) ) {
				// aggregate a list of backup dirs to search
				$dirs = apply_filters( 'qsot-template-dirs', array( self::$o->core_dir . 'templates/' ) );
				$qsot_path = '';

				// add the legacy directory within the theme that holds the legacy OTCE templates
				array_unshift( $dirs, get_stylesheet_directory() . '/' . $qsot_path, get_template_directory() . '/' . $qsot_path );

				// for each file in the list, try to find it in each backup dir
				foreach ( $files as $file ) {
					// normalize the filename, and skip any empty ones
					$file = trim( $file );
					if ( '' === $file )
						continue;

					// check each backup dir for this file
					foreach ( $dirs as $dir ) {
						$dir = trailingslashit( $dir );
						// if the file exists, then use that one, and bail the remainder of the search
						if ( file_exists( $dir . $file ) && is_readable( $dir . $file ) ) {
							$templ = $dir . $file;
							break 2;
						}
					}
				}
			}

			// if there is a template found, and we are being asked to include it, the include it, by either 'require' or 'include' depending on the passed params
			if ( ! empty( $templ ) && $load ) {
				if ( $require_once )
					require_once $templ;
				else
					include $templ;
			}

			// if we found a template, make sure to update the return value with the full path to the file
			if ( ! empty( $templ ) )
				$current = $templ;
		}

		return $current;
	}

	public static function wc_locate_template($current, $template_name, $template_path) {
		$name = $template_name;
		//self::_lg( 'templater::wc_locate_template $current', $current );
		$found = apply_filters('qsot-woo-template', $name);
		//self::_lg( 'templater::wc_locate_template $found', $found );
		return $found ? $found : $current;
	}

	// created to track down a specific theme issue
	protected static function _lg( $msg ) {
		?>
			<script>
				( function() { var args = <?php echo @json_encode( func_get_args() ) ?>; if ( console && console.log && 'function' == typeof console.log ) console.log.apply( console.log, args ); } )();
			</script>
		<?php
	}

	public static function locate_woo_template($name, $type=false) {
		global $woocommerce;

		//self::_lg( '>>>> req template', $name );

		$found = locate_template(array($name), false, false);
		if (!$found) {
			$woodir = trailingslashit( $woocommerce->plugin_path() );
			switch ($type) {
				case 'admin': $qsot_path = 'templates/admin/'; $woo_path = 'includes/admin/'; break;
				default: $qsot_path = 'templates/'; $woo_path = 'templates/';
			}

			$dirs = apply_filters('qsot-template-dirs', array(
				get_stylesheet_directory().'/woocommerce/',
				get_template_directory().'/woocommerce/',
				get_stylesheet_directory().'/templates/',
				get_template_directory().'/templates/',
				self::$o->core_dir.$qsot_path,
				$woodir.$woo_path,
			), $qsot_path, $woo_path, 'woocommerce');
			array_unshift($dirs, get_stylesheet_directory().'/'.$qsot_path, get_template_directory().'/'.$qsot_path);

			foreach ($dirs as $dir) {
				//self::_lg( '==== checking', trailingslashit($dir).$name, file_exists(($file = trailingslashit($dir).$name)) );
				if (file_exists(($file = trailingslashit($dir).$name))) {
					$found = $file;
					break;
				}
			}
		}

		//self::_lg( '<<<< final template', $found );

		return $found;
	}
}

if (defined('ABSPATH') && function_exists('add_action')) {
	qsot_templates::pre_init();
}

endif;
