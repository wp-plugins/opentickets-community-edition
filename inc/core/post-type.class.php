<?php (__FILE__ == $_SERVER['SCRIPT_FILENAME']) ? die(header('Location: /')) : null;

/* Handles the creation of the qsot (events) post type. Also handles the builtin metaboxes, event save actions, and general admin interface setup for events. */
class qsot_post_type {
	// holder for event plugin options
	protected static $o = null;
	protected static $options = null;

	public static function pre_init() {
		$settings_class_name = apply_filters('qsot-settings-class-name', '');
		if (!empty($settings_class_name)) {
			self::$o = call_user_func_array(array($settings_class_name, "instance"), array());

			$mk = self::$o->meta_key;
			self::$o->meta_key = array_merge(is_array($mk) ? $mk : array(), array(
				'start' => '_start',
				'end' => '_end',
				'capacity' => '_capacity',
			));

			// load all the options, and share them with all other parts of the plugin
			$options_class_name = apply_filters('qsot-options-class-name', '');
			if (!empty($options_class_name)) {
				self::$options = call_user_func_array(array($options_class_name, "instance"), array());
				self::_setup_admin_options();
			}

			// setup the post type at the appropriate time
			//add_action('init', array(__CLASS__, 'register_post_type'), 1);
			add_filter('qsot-events-core-post-types', array(__CLASS__, 'register_post_type'), 1, 1);
			// register js and css assets at the appropriate time
			add_action('init', array(__CLASS__, 'register_assets'), 2);
			// hook to load assets on the frontend for events
			add_action('wp', array(__CLASS__, 'load_frontend_assets'), 1000);
			// hook into the edit page loading, and add our needed js and css for the admin interface
			add_action('load-post.php', array(__CLASS__, 'load_edit_page_assets'), 999);
			add_action('load-post-new.php', array(__CLASS__, 'load_edit_page_assets'), 999);
			// handle default post list for events
			add_action('load-edit.php', array(__CLASS__, 'intercept_event_list_page'), 10);
			add_filter('manage_'.self::$o->core_post_type.'_posts_columns', array(__CLASS__, 'post_columns'), 10, 1);
			add_filter('views_edit-'.self::$o->core_post_type, array(__CLASS__, 'adjust_post_list_views'), 10, 1);
			add_filter('_admin_menu', array(__CLASS__, 'patch_menu'), 10);
			add_action('admin_head', array(__CLASS__, 'patch_menu_second_hack'), 10);
			// handle saving of the parent event post
			add_action('save_post', array(__CLASS__, 'save_event'), 999, 2);

			// obtain start and end date range based on criteria
			add_filter('qsot-event-date-range', array(__CLASS__, 'get_date_range'), 100, 2);

			// filter to add the metadata to an event post object
			add_filter('qsot-get-event', array(__CLASS__, 'get_event'), 10, 2);
			add_filter('qsot-event-add-meta', array(__CLASS__, 'add_meta'), 10, 1);
			add_filter('the_posts', array(__CLASS__, 'the_posts_add_meta'), 10, 2);

			// add the 'hidden' post status, which allows all logged in users to see the permalink if they know the url, but block all searching on th frontend
			add_action('init', array(__CLASS__, 'register_post_statuses'), 1);
			// blcok all searching
			add_filter('posts_where', array(__CLASS__, 'hide_hidden_posts_where'), 10000, 2);

			// automatically use the parent event thumbnail if one is not defined for the child event
			add_filter('post_thumbnail_html', array(__CLASS__, 'cascade_thumbnail'), 10, 5);
			add_filter('get_post_metadata', array(__CLASS__, 'cascade_thumbnail_id'), 10, 4);

			// intercept the template for the event, and allow our base template to be used as the fallback instead of the single.php page
			add_filter('template_include', array(__CLASS__, 'template_include'), 10, 1);

			// special event query stuff
			add_filter('posts_where_request', array(__CLASS__, 'events_query_where'), 10, 2);
			add_filter('posts_join_request', array(__CLASS__, 'events_query_join'), 10, 2);
			add_filter('posts_orderby_request', array(__CLASS__, 'events_query_orderby'), 10, 2);
			add_filter('posts_fields_request', array(__CLASS__, 'events_query_fields'), 10, 2);

			add_filter('the_content', array(__CLASS__, 'the_content'), 10, 1);

			add_filter('qsot-can-sell-tickets-to-event', array(__CLASS__, 'check_event_sale_time'), 10, 2);
			//add_filter('posts_request', function($req, $q) { die(var_dump($req, $q)); }, 10, 2);

			add_action('add_meta_boxes', array(__CLASS__, 'core_setup_meta_boxes'), 10, 1);

			add_filter('qsot-order-id-from-order-item-id', array(__CLASS__, 'order_item_id_to_order_id'), 10, 2);

			// 'social' plugin hack
			add_filter('social_broadcasting_enabled_post_types', array(__CLASS__, 'enable_social_sharing'), 10, 1);

			do_action('qsot-restrict-usage', self::$o->core_post_type);

			// add event name to item lists
			add_action('qsot-order-item-list-ticket-info', array(__CLASS__, 'add_event_name_to_emails'), 10, 3);
			add_action('woocommerce_get_item_data', array(__CLASS__, 'add_event_name_to_cart'), 10, 2);

			// order by meta_value cast to date
			add_filter('posts_orderby', array(__CLASS__, 'wp_query_orderby_meta_value_date'), 10, 2);

			// work around for core hierarchical permalink bug - loushou
			// https://core.trac.wordpress.org/ticket/29615
			add_filter('post_type_link', array(__CLASS__, 'qsot_event_link'), 1000, 4);
		}
	}

	// work around for non-page hierarchical post type 'default permalink' bug i found - loushou
	// https://core.trac.wordpress.org/ticket/29615
	public static function qsot_event_link($permalink, $post, $leavename, $sample) {
		$post_type = get_post_type_object($post->post_type);

		if (!$post_type->hierarchical) return $permalink;

		// copied and slightly modified to actually work with WP_Query() from wp-includes/link-template.php @ get_post_permalink()
		global $wp_rewrite;

		$post_link = $wp_rewrite->get_extra_permastruct($post->post_type);
		$draft_or_pending = isset($post->post_status) && in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ) );
		$slug = get_page_uri($post->ID);

		if ( !empty($post_link) && ( !$draft_or_pending || $sample ) ) {
			if ( ! $leavename )
				$post_link = str_replace("%$post->post_type%", $slug, $post_link);
			$post_link = home_url( user_trailingslashit($post_link) );
		} else {
			if ( $post_type->query_var && ( isset($post->post_status) && !$draft_or_pending ) )
				$post_link = add_query_arg($post_type->query_var, $slug, '');
			else
				$post_link = add_query_arg(array('post_type' => $post->post_type, 'p' => $post->ID), '');
			$post_link = home_url($post_link);
		}

		return $post_link;
	}

	public static function add_event_name_to_cart($list, $item) {
		if (isset($item['event_id'])) {
			$event = apply_filters('qsot-get-event', false, $item['event_id']);
			if (is_object($event)) {
				$list[] = array(
					'name' => __('Event'),
					'display' => apply_filters('the_title', $event->post_title),
				);
			}
		}

		return $list;
	}

	public static function add_event_name_to_emails($item_id, $item, $order) {
		if (!isset($item['event_id']) || empty($item['event_id'])) return;
		$event = apply_filters('qsot-get-event', false, $item['event_id']);
		if (!is_object($event)) return;

		echo sprintf(
			'<br/><small> - <a class="event-link" href="%s" target="_blank" title="%s">%s</a></small>',
			get_permalink($event->ID),
			'View this event',
			apply_filters('the_title', $event->post_title)
		);
	}

	public static function patch_menu() {
		global $menu, $submenu;

		foreach ($menu as $ind => $mitem) {
			if (isset($mitem[5]) && $mitem[5] == 'menu-posts-'.self::$o->core_post_type) {
				$key = $menu[$ind][2];
				$new_key = $menu[$ind][2] = add_query_arg(array('post_parent' => 0), $key);
				if (isset($submenu[$key])) {
					$submenu[$new_key] = $submenu[$key];
					unset($submenu[$key]);
					foreach ($submenu[$new_key] as $sind => $sitem) {
						if ($sitem[2] == $key) {
							$submenu[$new_key][$sind][2] = $new_key;
							break;
						}
					}
				}
				break;
			}
		}
	}

	public static function patch_menu_second_hack() {
		global $parent_file;

		if ($parent_file == 'edit.php?post_type='.self::$o->core_post_type) $parent_file = add_query_arg(array('post_parent' => 0), $parent_file);
	}

	// based on $args, determine the start and ending date of a set of events
	public static function get_date_range($current, $args='') {
		$args = wp_parse_args( apply_filters('qsot-event-date-range-args', $args), array(
			'event_id' => 0,
			'with__self' => false,
			'year__only' => false,
			'include__only' => array(),
		));

		extract($args);

		$event_id = absint($event_id);
		$with__self = !!$with__self;
		$year__only = !!$year__only;
		$include__only = wp_parse_id_list($include__only);

		global $wpdb;

		$fields = array();
		$join = array();
		$where = array();
		$fmt = 'Y-m-d H:i:s';

		if ($year__only) {
			$fields[] = 'min(year(cast(pm.meta_value as datetime))) as min_val';
			$fields[] = 'max(year(cast(pm.meta_value as datetime))) as max_val';
			$fmt = 'Y';
		} else {
			$fields[] = 'min(cast(pm.meta_value as datetime)) as min_val';
			$fields[] = 'max(cast(pm.meta_value as datetime)) as max_val';
		}

		$join[] = $wpdb->prepare($wpdb->postmeta.' pm on pm.post_id = p.id and (pm.meta_key = %s or pm.meta_key = %s)', self::$o->{'meta_key.start'}, self::$o->{'meta_key.end'});

		if ($event_id) {
			if ($with__self) $where[] = $wpdb->prepare('and ( p.id = %d or p.post_parent = %d )', $event_id, $event_id);
			else $where[] = $where[] = $wpdb->prepare('and p.post_parent = %d', $event_id);
		} else if (!empty($include__only)) {
			$where[] = 'p.id in ('.implode(',', $include__only).')';
		}

		$pieces = array( 'where', 'fields', 'join' );

		foreach ($pieces as $piece)
			$$piece = apply_filters('qsot-event-date-range-'.$piece, $$piece);

		$clauses = (array) apply_filters('qsot-event-date-range-clauses', compact( $pieces ), $args);
		foreach ($pieces as $piece)
			$$piece = isset($clauses[$piece]) ? $clauses[$piece] : '';

		$fields  = !empty($fields) ? ( is_array($fields) ? implode(', ', $fields) : $fields ) : '*';
		$where  = !empty($where) ? $wpdb->prepare(' where p.post_type = %s ', self::$o->core_post_type).( is_array($where) ? implode(' ', $where) : $where ) : '';
		$join  = !empty($join) ? ' join '.( is_array($join) ? implode(' ', $join) : $join ) : '';

		$query = apply_filters(
			'qsot-event-date-range-request',
			'select '.$fields.' from '.$wpdb->posts.' p '.$join.' '.$where,
			compact($pieces),
			$args
		);

		$results = $wpdb->get_row($query, ARRAY_N);
		$today = date_i18n($fmt);

		return is_array($results) && count($results) == 2 ? $results : array($today, $today);
	}

	public static function check_event_sale_time($current, $event_id) {
		$formula = get_post_meta($event_id, '_stop_sales_before_show', true);
		if (empty($formula)) {
			$post = get_post($event_id);
			$parent_id = $post->post_parent;
			if ($parent_id) $formula = get_post_meta($parent_id, '_stop_sales_before_show', true);
			if (empty($formula)) $formula = get_option('qsot-stop-sales-before-show', '');
		}
		$start = get_post_meta($event_id, '_start', true);

		$formula = str_replace('+', '-', $formula);
		$formula = preg_replace('#(-)\s*(\d)#', '\1\2', $formula);
		$formula = preg_replace('#(^|\s+)(?<!-)(\d+)#', '\1-\2', $formula);
		
		$stime = strtotime($start);
		$stop_time = strtotime($formula, $stime);
		if ($stop_time === false) $stop_time = $stime;
		$time = current_time('timestamp');

		return $time < $stop_time;
	}

	public static function intercept_event_list_page() {
		if (isset($_GET['post_type']) && $_GET['post_type'] == self::$o->core_post_type) {
			add_action('pre_get_posts', array(__CLASS__, 'add_post_parent_query_var'), 10, 1);
		}
	}

	public static function add_post_parent_query_var(&$q) {
		if (isset($_GET['post_parent'])) {
			$q->query_vars['post_parent'] = $_GET['post_parent'];
		}
	}

	public static function post_columns($columns) {
		if (isset($_GET['post_parent']) && $_GET['post_parent'] == 0) {
			add_action('manage_'.self::$o->core_post_type.'_posts_custom_column', array(__CLASS__, 'post_columns_contents'), 10, 2);
			$final = array();
			foreach ($columns as $col => $val) {
				$final[$col] = $val;
				if ($col == 'title') $final['child-event-count'] = 'Events';
			}
			$columns = $final;
		}

		return $columns;
	}

	public static function post_columns_contents($column, $post_id) {
		global $wpdb;

		switch ($column) {
			case 'child-event-count':
				$total = (int)$wpdb->get_var($wpdb->prepare('select count(id) from '.$wpdb->posts.' where post_parent = %d and post_type = %s', $post_id, self::$o->core_post_type));
				echo $total;
			break;
		}
	}

	public static function adjust_post_list_views($views) {
		$post_counts = self::_count_posts();
		$post_counts["0"] = isset($post_counts["0"]) && is_numeric($post_counts["0"]) ? $post_counts["0"] : 0;
		$current = isset($_GET['post_parent']) && $_GET['post_parent'] == 0 ? ' class="current"' : '';

		$new_views = array(
			'only-parents' => sprintf(
				'<a href="%s"'.$current.'>%s (%d)</a>',
				'edit.php?post_type='.self::$o->core_post_type.'&post_parent=0',
				'Top Level Events',
				$post_counts["0"]
			),
		);

		foreach ($views as $slug => $view) {
			$new_views[$slug] = $current ? preg_replace('#(class="[^"]*)current([^"]*")#', '\1\2', $view) : $view;
		}

		return $new_views;
	}

	protected static function _count_posts() {
		global $wpdb;

		$return = array();
		$res = $wpdb->get_results($wpdb->prepare('select post_parent, count(post_type) as c from '.$wpdb->posts.' where post_type = %s group by post_parent', self::$o->core_post_type));
		foreach ($res as $row) $return["{$row->post_parent}"] = $row->c;

		return $return;
	}

	public static function enable_social_sharing($list) {
		$list[] = self::$o->core_post_type;
		return array_filter(array_unique($list));
	}

	public static function the_content($content) {
		$post = get_post();
		if ( post_password_required( $post ) ) return $content;

		if (($event = get_post()) && is_object($event) && $event->post_type == self::$o->core_post_type && $event->post_parent != 0) {
			if (self::$options->{'qsot-single-synopsis'} && self::$options->{'qsot-single-synopsis'} != 'no') {
				$p = clone $GLOBALS['post'];
				$GLOBALS['post'] = get_post($event->post_parent);
				setup_postdata($GLOBALS['post']);
				$content = get_the_content();
				$GLOBALS['post'] = $p;
				setup_postdata($p);
			}

			$content = apply_filters('qsot-event-the-content', $content, $event);
		}

		return $content;
	}

	public static function wp_query_orderby_meta_value_date($orderby, $query) {
		if (
				isset($query->query_vars['orderby'], $query->query_vars['meta_key'])
				&& $query->query_vars['orderby'] == 'meta_value_date'
				&& !empty($query->query_vars['meta_key'])
		) {
			$order = strtolower(isset($query->query_vars['order']) ? $query->query_vars['order'] : 'asc');
			$order = in_array($order, array('asc', 'desc')) ? $order : 'asc';
			$orderby = 'cast(mt1.meta_value as datetime) '.$order;
		}
		return $orderby;
	}

	public static function events_query_where($where, $q) {
		global $wpdb;

		if (isset($q->query_vars['start_date_after']) && strtotime($q->query_vars['start_date_after']) > 0) {
			$where .= $wpdb->prepare(' AND (cast(qssda.meta_value as datetime) >= %s) ', $q->query_vars['start_date_after']);
		}

		if (isset($q->query_vars['start_date_before']) && strtotime($q->query_vars['start_date_before']) > 0) {
			$where .= $wpdb->prepare(' AND (cast(qssda.meta_value as datetime) <= %s) ', $q->query_vars['start_date_before']);
		}

		if (isset($q->query_vars['post_parent__not_in']) && !empty($q->query_vars['post_parent__not_in'])) {
			$ppni = $q->query_vars['post_parent__not_in'];
			if (is_string($ppni)) $ppni = preg_split('#\s*,\s*', $ppni);
			if (is_array($ppni)) {
				$where .= $wpdb->prepare(' AND ('.$wpdb->posts.'.post_parent not in ('.implode(',', array_map('absint', $ppni)).')', true);
			}
		}

		if (isset($q->query_vars['post_parent__in']) && !empty($q->query_vars['post_parent__in'])) {
			$ppi = $q->query_vars['post_parent__in'];
			if (is_string($ppi)) $ppi = preg_split('#\s*,\s*', $ppi);
			if (is_array($ppi)) {
				$where .= $wpdb->prepare(' AND ('.$wpdb->posts.'.post_parent in ('.implode(',', array_map('absint', $ppi)).')', true);
			}
		}

		if (isset($q->query_vars['post_parent__not']) && $q->query_vars['post_parent__not'] !== '') {
			$ppn = $q->query_vars['post_parent__not'];
			if (is_scalar($ppn)) {
				$where .= $wpdb->prepare(' AND ('.$wpdb->posts.'.post_parent != %s) ', $ppn);
			}
		}

		return $where;
	}

	public static function events_query_join($join, $q) {
		global $wpdb;

		if (
			(isset($q->query_vars['start_date_after']) && strtotime($q->query_vars['start_date_after']) > 0) ||
			(isset($q->query_vars['start_date_before']) && strtotime($q->query_vars['start_date_before']) > 0)
		){
			$join .= $wpdb->prepare(' join '.$wpdb->postmeta.' as qssda on qssda.post_id = '.$wpdb->posts.'.ID and qssda.meta_key = %s ', self::$o->{'meta_key.start'});
		}

		return $join;
	}

	public static function events_query_fields($fields, $q) {
		return $fields;
	}

	public static function events_query_orderby($orderby, $q) {
		global $wpdb;

		if (isset($q->query_vars['special_order']) && strlen($q->query_vars['special_order'])) {
			//$orderby = preg_split('#\s*,\s*#', $orderby);
			$orderby = $q->query_vars['special_order'];
			//$orderby = implode(', ', $orderby);
		}

		return $orderby;
	}

	public static function the_posts_add_meta($posts, $q) {
		foreach ($posts as $i => $post) {
			if ($post->post_type == self::$o->core_post_type) {
				$posts[$i] = apply_filters('qsot-event-add-meta', $post);
			}
		}

		return $posts;
	}

	public static function get_event($current, $event_id) {
		$event = get_post($event_id);

		if (is_object($event) && isset($event->post_type) && $event->post_type == self::$o->core_post_type) {
			$event = apply_filters('qsot-event-add-meta', $event);
		} else {
			$event = $current;
		}

		return $event;
	}

	public static function add_meta($event) {
		if (is_object($event) && isset($event->ID, $event->post_type) && $event->post_type == self::$o->core_post_type) {
			//die(var_dump($event));
			$km = self::$o->meta_key;
			$m = array();
			$meta = get_post_meta($event->ID);
			foreach ($meta as $k => $v) {
				if (($pos = array_search($k, $km)) !== false) $k = $pos;
				$m[$k] = maybe_unserialize(array_shift($v));
			}
			$m = wp_parse_args($m, array('purchases' => 0, 'capacity' => 0));
			$m['available'] = $m['capacity'] - $m['purchases'];
			switch (true) {
				case $m['available'] >= ($m['capacity'] - self::$o->always_reserve) * 0.65: $m['availability'] = 'high'; break;
				case $m['available'] >= ($m['capacity'] - self::$o->always_reserve) * 0.30: $m['availability'] = 'medium'; break;
				case $m['available'] <= self::$o->always_reserve: $m['availability'] = 'sold-out'; break;
				default: $m['availability'] = 'low'; break;
			}
			$m = apply_filters('qsot-event-meta', $m, $event, $meta);
			if (isset($m['_event_area_obj'], $m['_event_area_obj']->ticket, $m['_event_area_obj']->ticket->id))
				$m['reserved'] = apply_filters('qsot-zoner-owns', 0, $event, $m['_event_area_obj']->ticket->id, self::$o->{'z.states.r'});
			else
				$m['reserved'] = 0;
			$event->meta = (object)$m;

			$image_id = get_post_thumbnail_id($event->ID);
			$image_id = empty($image_id) ? get_post_thumbnail_id($event->post_parent) : $image_id;
			$event->image_id = $image_id;
		}

		return $event;
	}

	public static function order_item_id_to_order_id($order_id, $order_item_id) {
		static $cache = array();

		if (!isset($cache["{$order_id}"])) {
			global $wpdb;
			$q = $wpdb->prepare('select order_id from '.$wpdb->prefix.'woocommerce_order_items where order_item_id = %d', $order_item_id);
			$cache["{$order_id}"] = (int)$wpdb->get_var($q);
		}

		return $cache["{$order_id}"];
	}

	public static function cascade_thumbnail($html, $post_id, $post_thumbnail_id, $size, $attr) {
		if (empty($html) || empty($post_thumbnail_id)) {
			$post = get_post($post_id);
			if (is_object($post) && isset($post->post_type) && $post->post_type == self::$o->core_post_type && !empty($post->post_parent)) {
				$html = get_the_post_thumbnail($post->post_parent, $size, $attr);
			}
		}

		return $html;
	}

	public static function cascade_thumbnail_id($current, $object_id, $key, $single) {
		static $map = array();

		if (!isset($map[$object_id.''])) {
			$obj = get_post($object_id);
			if ($obj->ID == $object_id) $map[$object_id.''] = $obj->post_type;
			else $map[$object_id.''] = '_unknown_post_type';
		}

		if ($map[$object_id.''] == self::$o->core_post_type && $key == '_thumbnail_id' && $parent_id = wp_get_post_parent_id($object_id)) {
			remove_filter('get_post_metadata', array(__CLASS__, 'cascade_thumbnail_id'), 10);
			$this_value = get_post_meta($object_id, $key, $single);
			add_filter('get_post_metadata', array(__CLASS__, 'cascade_thumbnail_id'), 10, 4);

			if (empty($this_value)) $current = get_post_meta($parent_id, $key, $single);
			else $current = $this_value;
		}

		return $current;
	}

	public static function template_include($template) {
		if (is_singular(self::$o->core_post_type)) {
			$post = get_post();
			$files = array(
				'single-'.self::$o->core_post_type.'.php',
			);
			if ($post->post_parent != 0) array_unshift($files, 'single-'.self::$o->core_post_type.'-child.php');

			$tmpl = apply_filters('qsot-locate-template', '', $files);
			if (!empty($tmpl)) $template = $tmpl;
		}

		return $template;
	}

	// always register our scripts and styles before using them. it is good practice for future proofing, but more importantly, it allows other plugins to use our js if needed.
	// for instance, if an external plugin wants to load something after our js, like a takeover js, they will have access to see our js before we actually use it, and will 
	// actually be able to use it as a dependency to their js. if the js is not yet declared, you cannot use it as a dependency.
	public static function register_assets() {
		$suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';

		// XDate 0.7. used for date calculations when using the FullCalendar plugin. http://arshaw.com/xdate/
		wp_register_script('xdate', self::$o->core_url.'assets/js/utils/third-party/xdate/xdate.dev.js', array('jquery'), '0.7');
		// FullCalendar 1.5.4 jQuery plugin. used for all calendar related interfaces. http://arshaw.com/fullcalendar/
		wp_register_script('fullcalendar', self::$o->core_url.'assets/js/libs/fullcalendar/fullcalendar'.$suffix.'.js', array('jquery','xdate'), '1.5.4');
		wp_register_style('fullcalendar', self::$o->core_url.'assets/css/libs/fullcalendar/fullcalendar.css', array(), '1.5.4');
		// json2 library to add JSON window object in case it does not exist
		wp_register_script('json2', self::$o->core_url.'assets/js/utils/json2.js', array(), 'commit-17');
		// colorpicker
		wp_register_script('jqcolorpicker', self::$o->core_url.'assets/js/libs/cp/colorpicker.js', array('jquery'), '23.05.2009');
		wp_register_style('jqcolorpicker', self::$o->core_url.'assets/css/libs/cp/colorpicker.css', array(), '23.05.2009');
		// generic set of tools for our js work. almost all written by Loushou
		wp_register_script('qsot-tools', self::$o->core_url.'assets/js/utils/tools.js', array('jquery', 'json2', 'xdate'), '0.2-beta');
		// main event ui js. combines all the moving parts to make the date/time selection process more user friendly than other crappy event plugins
		wp_register_script('qsot-event-ui', self::$o->core_url.'assets/js/admin/event-ui.js', array('qsot-tools', 'fullcalendar'), self::$o->version);
		// initialization js. initializes all the moving parts. called at the top of the edit event page
		wp_register_script('qsot-events-admin-edit-page', self::$o->core_url.'assets/js/admin/edit-page.js', array('qsot-event-ui', 'jquery-ui-datepicker'), self::$o->version);
		// jQueryUI theme for the admin
		wp_register_style('qsot-jquery-ui', self::$o->core_url.'assets/css/libs/jquery/jquery-ui-1.10.1.custom.min.css', array(), '1.10.1');
		// general additional styles for the event ui interface
		wp_register_style('qsot-admin-styles', self::$o->core_url.'assets/css/admin/ui.css', array('qsot-jquery-ui'), self::$o->version);
		// ajax js
		wp_register_script('qsot-frontend-ajax', self::$o->core_url.'assets/js/utils/ajax.js', array('qsot-tools'), self::$o->version);
	}

	public static function load_frontend_assets(&$wp) {
		if (is_singular(self::$o->core_post_type) && ($post = get_post()) && $post->post_parent != 0) {
			do_action('qsot-frontend-event-assets', $post);
		}
	}

	// need three main statuses for events.
	// Published - obvious, and needs no explanation
	// Private - Admin Only equivalent. this is a status that only editors and above can see when searching or browsing to the permalink. we will extend this to affect the calendar and grids also.
	// Hidden (new) - only logged in users can see this, and only if they know the permalink. security by obscurity. 
	// CHANGE 6-27-13: Hidden should be completely public to anyone with the url, regardless of logged in status
	public static function register_post_statuses() {
		$slug = 'hidden'; // status name
		// status settings
		$args = array(
			'label' => _x('Hidden', 'post', 'qsot'), // nice label for the admin
			// @WHY-PUBLIC
			// though this is actually a 'private' type, which would otherwise be hidden, we must make it public. the reason is because core wordpress at 3.5.2 does not currently have a method to 
			// clearly define rules for post statuses. so in order to bypass the core filtering in the WP_Query::get_posts(), which determines the visibility of a post (query.php@3.5.1 on line 2699),
			// so that we can make our own fancy filtering code, we MUST make it public, and then post-filter it.
			'public' => true,
			'publicly_queryable' => false, // not 100% sure what this actually does, because you can goto the permalink, search, and see in admin with this at false
			// @WHY-NOT-EXCLUDE-FROM-SEARCH
			// again, this seems counter intuitive, but it is needed. currently serves a dual purpose. purpose #1: in our event ui interface, we have a settable field called 'visibility' (aka status)
			// which queries WP for all post statuses that meet a list of specific criteria. one of those is that it is searchable, because we want to exclude several post statuses from that list
			// which are not search able. making this true would defeat the purpose of adding it, for that reason alone. purpose #2: again because core WP does not have a good way to granularly 
			// control what a post status does, we must make our status by pass core WP filtering so that we can do our own filtering. we need this status to be searchable by users with the
			// read_private_pages capability, but not to anyone without read_private_pages permissions. to do this we need to allow WP add it to the SQL query for everyone, and then filter it out
			// manually for those who cant use it.
			'exclude_from_search' => false,
			'show_in_admin_all_list' => true, // do not hide from the admin events list
			'show_in_admin_status_list' => true, // allow the all events list to be filtered by this status
			'label_count' => _n_noop('Hidden <span class="count">(%s)</span>', 'Hidden <span class="count">(%s)</span>'), // show the count of hidden events in the admin events list page
		);
		// register the event
		register_post_status($slug, $args);
	}

	// as discussed above, in the post_status declaration (marked @WHY-NOT-EXCLUDE-FROM-SEARCH), we need to allow WP to add the hidden status to all relevant queries, and then filter it out
	// again for users that it does not apply to. since we want anyone with the read_private_pages to be able to see it, but anyone without to not see it, we need it there by default (so we don't
	// have to modify core WP) and then filter it out for anyone cannot see it. to do this we need to assert specific conditions are true, and if they do not pass, we need to filter out 
	// the status from the query.
	public static function hide_hidden_posts_where($where, &$query) {
		// first, before thinking about making changes to the query, make sure that we are actually querying for our event post type. there are two cases where our event post type could be
		// being queried for, but we are only concerned with one. i'll explain both. the one we are not concerned with: if the where clause does not specifically filter for post_type, then 
		// we could technically get an event post in the result. we are not concerned with this, because, except for some rare outlier situations and intentional circumventing of this rule,
		// the only time that a query should not specifically filter for post_type is when we are visiting a 'single' page, which we have a separate software driven filter for. THE ONE WE DO
		// CARE ABOUT: when the where clause implicitly states that we are querying by post_type. in this case, we are most likely doing a search or on some sort of list page. when that is
		// the case, we need to first make sure that we are querying our event post type, before we even think about changing the where clause. we do this with some specially crafted regex.
		$querying_events = false; // default is that we are not querying for our post type
		$parts = explode(' AND ', $where); // break the where clause into more logical sub blocks, so that we can test for our post_type filtering
		// foreach block, check if the block is testing the post type. if it is, additionally make sure that our post type is in the list of post types being tested for. if it is, they we
		// need to perform our additional checkes to determine if we need to filter this where statement to remove our special status.
		foreach ($parts as $part) if (preg_match('#post_type\s+(in|=)\s+.*'.self::$o->core_post_type.'#i', $part)) $querying_events = true;

		// our only other check is whether the current user can read_private_pages. if we are querying for our event post type, and the current user does not have our special capability
		if ($querying_events && is_user_logged_in() && !apply_filters( 'qsot-show-hidden-events', current_user_can( 'edit_posts' ) )) {
			// then craft a new where statement that is identical to the old one, minus our special status, based on how WP3.5.1 currently constructs the query
			$new_parts = array();
			// for each of the parts of the where statement, that we made above
			foreach ($parts as $part) {
				// test if it is the part that handles the post_status. if it is, then
				if (preg_match('#post_status.*(\'|")hidden\1#', $part)) {
					// remove our public 'hidden' post status from the query, since this user cannot see it, and add that piece of the where statement back to the where statement list
					$new_parts[] = preg_replace('#(\s+or)?\s+[^\s]+post_status\s+[^\s]+\s+(\'|")hidden\2#i', '', $part);
				} else {
					// if this is not the piece that handles the post_status filtering, then passthru this piece of the query, unmodified
					$new_parts[] = $part;
				}
			}
			// paste all the filtered pieces of the where statement together again
			$where = implode(' AND ', $new_parts);
		}

		// return the either unmodified or filtered where statement
		return $where;
	}

	// register our events post type
	public static function register_post_type( $list) {
		// needs to be it's own local variable, so that we can pass it as a 'used' variable to the anonymous function we make later
		$corept = self::$o->core_post_type;

		$list[self::$o->core_post_type] = array(
			'label_replacements' => array(
				'plural' => 'Events', // plural version of the proper name, used in the slightly modified labels in my _register_post_type method
				'singular' => 'Event', // singular version of the proper name, used in the slightly modified labels in my _register_post_type method
			),
			'args' => array( // almost all of these are passed through to the core regsiter_post_type function, and follow the same guidelines defined on wordpress.org
				'public' => true, 
				'menu_position' => 21.1,
				'supports' => array(
					'title',
					'editor',
					'thumbnail',
					'author',
					'excerpt',
					'custom-fields',
				),
				'hierarchical' => true,
				'rewrite' => array('slug' => self::$o->core_post_rewrite_slug),
				//'register_meta_box_cb' => array(__CLASS__, 'core_setup_meta_boxes'),
				//'capability_type' => 'event',
				'show_ui' => true,
				'taxonomies' => array('category', 'post_tag'),
				'permalink_epmask' => EP_PAGES,
			),
		);

		return $list;
	}

	// when on the edit single event page in the admin, we need to queue up certain aseets (previously registered) so that the page actually works properly
	public static function load_edit_page_assets() {
		// is this a new event or an existing one? we can check this by determining the post_id, if there is one (since WP does not tell us)
		$post_id = 0;
		// if there is a post_id in the admin url, and the post it represents is of our event post type, then this is an existing post we are just editing
		if (isset($_REQUEST['post']) && get_post_type($_REQUEST['post']) == self::$o->core_post_type) {
			$post_id = $_REQUEST['post'];
			$existing = true;
		// if there is not a post_id but this is the edit page of our event post type, then we still need to load the assets
		} else if (isset($_REQUEST['post_type']) && $_REQUEST['post_type'] == self::$o->core_post_type) {
			$existing = false;
		// if this is not an edit page of our post type, then we need none of these assets loaded
		} else return;

		// load the eit page js, which also loads all it's dependencies
		wp_enqueue_script('qsot-events-admin-edit-page');
		// load the fullcalendar styles and the misc interface styling
		wp_enqueue_style('fullcalendar');
		wp_enqueue_style('qsot-admin-styles');

		// use the loacalize script trick to send misc settings to the event ui script, based on the current post, and allow sub/external plugins to modify this
		@list($events, $first) = self::_child_event_settings($post_id);
		wp_localize_script('qsot-events-admin-edit-page', '_qsot_settings', apply_filters('qsot-event-admin-edit-page-settings', array(
			'first' => $first,
			'events' => $events, // all children events
			'templates' => self::_ui_templates($post_id), // all templates used by the ui js
		), $post_id));

		// allow sub/external plugins to load their own stuff right now
		do_action('qsot-events-edit-page-assets', $existing, $post_id);
	}

	// load a list of all the child events to teh given event. this will be sent to the js event ui interface as settings to aid in construction of the interface
	protected static function _child_event_settings($post_id) {
		$list = array();
		// if there is no post_id then return an empty list
		if (empty($post_id)) return $list;
		$post = get_post($post_id);
		// if the post_id passed does not exist, then return an empty list
		if (!is_object($post) || !isset($post->post_title)) return $list;

		// default settings for the passed lit of subevent objects. modifiable by sub/external plugins, so they can add their own settings
		$defs = apply_filters('qsot-load-child-event-settings-defaults', array(
			'title' => $post->post_title,
			'start' => '0000-00-00 00:00:00',
			'allDay' => false,
			'editable' => true,
			'status' => 'pending', // status
			'visibility' => 'public', // visibiltiy
			'password' => '', // protected password
			'pub_date' => '', // date to publish
			'capacity' => 0, // max occupants
			'post_id' => -1, // sub event post_id used for lookup during save process
			'edit_link' => '', // edit individual event link
			'view_link' => '', // view individual event link
		), $post_id);

		// args to load a list of child events using WP_Query
		$pargs = array(
			'post_type' => self::$o->core_post_type, // event post type (exclude images and other sub posts)
			'post_status' => 'any', // any status, including our special one
			'post_parent' => $post->ID, // children to this main event
			'posts_per_page' => -1, // all of them, not limited to 5 (like the default)
		);
		// get the list
		$events = get_posts($pargs);

		$earliest = PHP_INT_MAX;
		// foreach sub event we found, do some stuff
		foreach ($events as $event) {
			// load the meta, and reduce the list to only the first value for each piece of meta (since there is rarely any duplicates)
			$meta = get_post_meta($event->ID);
			foreach ($meta as $k => $v) $meta[$k] = array_shift($v);
			// determine the start date for the item. default to the _start meta value, and fallback on the post slug (super bad if this ever gets used. mainly for recovery purposes)
			$start = isset($meta[self::$o->{'meta_key.start'}])
					? $meta[self::$o->{'meta_key.start'}]
					: date('Y-m-d H:i:s', strtotime(preg_replace('#(\d{4}-\d{2}-\d{2})_(\d{1,2})-(\d{2})((a|p)m)#', '\1 \2:\3\4', $event->post_name)));
			$start = date('Y-m-d\TH:i:sP', strtotime($start));
			$earliest = min(strtotime($start), $earliest);
			$end = isset($meta[self::$o->{'meta_key.end'}])
					? $meta[self::$o->{'meta_key.end'}]
					: date('Y-m-d H:i:s', strtotime('+1 hour', $start));
			$end = date('Y-m-d\TH:i:sP', strtotime($end));
			// add an item to the list, by transposing the loaded settings for this sub event over the list of default settings, and then allowing sub/external plugins to modify them
			// to add their own settings for the interface.
			$list[] = apply_filters('qsot-load-child-event-settings', wp_parse_args(array(
				'start' => $start,
				'status' => in_array( $event->post_status, array( 'hidden', 'private' ) ) ? 'publish' : $event->post_status,
				'visibility' => in_array( $event->post_status, array( 'hidden', 'private' ) ) ? $event->post_status : ( $event->post_password ? 'protected' : 'public' ),
				'password' => $event->post_password,
				'pub_date' => $event->post_date,
				'capacity' => isset($meta[self::$o->{'meta_key.capacity'}]) ? $meta[self::$o->{'meta_key.capacity'}] : 0,
				'end' => $end,
				'post_id' => $event->ID,
				'edit_link' => get_edit_post_link($event->ID),
				'view_link' => get_permalink($event->ID),
			), $defs), $defs, $event);
		}

		// return the generated list
		return array($list, $earliest == PHP_INT_MAX ? '' : date('Y-m-d H:i:s', $earliest));
	}

	// generate the core templates used by the event ui js
	protected static function _ui_templates($post_id) {
		$list = array();

		// default individual event block on any view without a specifically defined template
		$list['render_event'] = '<div class="'.self::$o->fctm.'-event-item">'
				.'<div class="'.self::$o->fctm.'-event-item-header">'
					.'<span class="'.self::$o->fctm.'-event-time"></span>' // time of the event
					.'<span class="'.self::$o->fctm.'-separator"> </span>'
					.'<span class="'.self::$o->fctm.'-capacity"></span>' // event max occupants
					.'<span class="'.self::$o->fctm.'-separator"> </span>'
					.'<span class="'.self::$o->fctm.'-visibility"></span>' // status
					.'<div class="remove" rel="remove">X</div>' // remove button
				.'</div>'
				.'<div class="'.self::$o->fctm.'-event-item-content">'
					.'<span class="'.self::$o->fctm.'-event-title"></span>' // title of the event
				.'</div>'
				.'<div class="'.self::$o->fctm.'-event-item-footer">'
				.'</div>'
			.'</div>';

		// extended, slightly modified version of the above template, specifically for the agendaWeek view of the calendar
		$list['render_event_agendaWeek'] = '<div class="'.self::$o->fctm.'-event-item">'
				.'<div class="'.self::$o->fctm.'-event-item-header">'
					.'<span class="'.self::$o->fctm.'-event-time"></span>' // time range of the event, in extended form
					.'<div class="remove" rel="remove">X</div>' // remove button
				.'</div>'
				.'<div class="'.self::$o->fctm.'-event-item-content">'
					.'<div class="'.self::$o->fctm.'-section">'
						.'<span class="'.self::$o->fctm.'-event-title"></span>' // event title
					.'</div>'
					.'<div class="'.self::$o->fctm.'-section">'
						.'<span>Max: </span><span class="'.self::$o->fctm.'-capacity"></span>' // event max occupants
					.'</div>'
					.'<div class="'.self::$o->fctm.'-section">'
						.'<span>Status: </span><span class="'.self::$o->fctm.'-visibility"></span>' // status
					.'</div>'
					.apply_filters('qsot-render-event-agendaWeek-template-details', '', $post_id)
				.'</div>'
				.'<div class="'.self::$o->fctm.'-event-item-footer">'
				.'</div>'
			.'</div>';

		// allow sub/external plugins to modify this list if they wish
		return apply_filters('qsot-ui-templates', $list, $post_id);
	}

	// save function for the parent events
	public static function save_event($post_id, $post) {
		if ($post->post_type != self::$o->core_post_type) return; // only run for our event post type
		if ($post->post_parent != 0) return; // this is only for parent event posts

		// if there were settings for the sub events sent, then process those settings
		if (isset($_POST['_event_settings'])) {
			$need_lookup = $updates = $matched = array();
			// default post_arr to send to wp_insert_post
			$defs = array(
				'post_type' => self::$o->core_post_type,
				'post_status' => 'pending',
				'post_password' => '',
				'post_parent' => $post_id,
			);

			// cycle through all the subevent settings that were sent. some will be new, some will be modified, some will be modified but have lost their post id. determine what each is,
			// and properly group them for possible later processing
			foreach ($_POST['_event_settings'] as $item) {
				// expand the settings
				$tmp = @json_decode(stripslashes($item));
				// if the settings are a valid set of settings, then continue with this item
				if (is_object($tmp)) {
					// change the title to be the date, which makes for better permalinks
					$tmp->title = date('Y-m-d_gia', strtotime($tmp->start));
					// if the post_id was passed in with the settings, then we know what subevent post to modify with these settings already. therefore we do not need to match it up to an existing
					// subevent or create a new subevent. lets throw it directly into the update list for usage later
					if (isset($tmp->post_id) && is_numeric($tmp->post_id) && $tmp->post_id > 0) {
						// parse the date so that we can use it to make a proper post_title
						$d = strtotime($tmp->start);
						// add the settings to the list of posts to update
						$updates[] = array(
							'post_arr' => wp_parse_args(array(
								'ID' => $tmp->post_id, // be sure to set the id of the post to update, otherwise we get a completely new post
								'post_title' => sprintf('%s on %s @ %s', $post->post_title, date('Y-m-d', $d), date('g:ia', $d)), // create a pretty proper title
								'post_status' => in_array( $tmp->visibility, array( 'hidden', 'private' ) ) ? $tmp->visibility : $tmp->status, // set the post status of the event
								'post_password' => $tmp->password, // protected events have passwords
								'post_name' => $tmp->title, // use that normalized title we made earlier, as to create a pretty url
								'post_date' => $tmp->pub_date == '' || $tmp->pub_date == 'now' ? '' : date_i18n( 'Y-m-d H:i:s', strtotime( $tmp->pub_date ) ),
							), $defs),
							'meta' => array( // setup the meta to save
								self::$o->{'meta_key.capacity'} => $tmp->capacity, // max occupants
								self::$o->{'meta_key.end'} => $tmp->end, // end time, for lookup and display purposes later
								self::$o->{'meta_key.start'} => $tmp->start, // start time for lookup and display purposes later
							),
							'submitted' => $tmp,
						);
						$matched[] = $tmp->post_id; // track the post_ids we have matched up to settings. will use later to determine subevents to delete
					// if no post id was passed, then we need to attempt to match this item up to an existing subevent
					} else {
						// add this item to the needs lookup list
						$need_lookup[$tmp->title] = $tmp;
					}
				}
			}

			// if there are subevent settings that did not contain a post_id, we need to attempt to match them up to existing, unmatched subevents of this event
			if (count($need_lookup) > 0) {
				// args for looking up existing subevents to this event
				$args = array(
					'post_type' => self::$o->core_post_type, // of event post type
					'post_parent' => $post_id, // is a child of this event
					'post_status' => 'any', // any status, including our special hidden status
					'posts_per_page' => -1, // lookup all of them, not just some
				);
				if (!empty($matched)) $args['post__not_in'] = $matched; // if any hve yet been matched, exclude them from the lookup
				$existing = get_posts($args); // fetch the list of existing, unmatched subevents

				// if there are any existing, unmatched subevents, then
				if (is_array($existing) && count($existing)) {
					// cycle through the list of them
					foreach ($existing as $exist) {
						// if the name of this subevent match any of the normalized names of the passed subevent settings, then lets assume that they are a match, so that we dont needlessly create extra
						// subevents just because we are missing a post_id above
						if (isset($need_lookup[$exist->post_name])) {
							$tmp = $need_lookup[$exist->post_name];
							// get the date in timestamp form so that we can use it to make a pretty title
							$d = strtotime($tmp->start);
							// remove the settings from the list that needs a match up, since we just matched it
							unset($need_lookup[$exist->post_name]);
							// add the settings to the list of posts to update
							$updates[] = array(
								'post_arr' => wp_parse_args(array(
									'ID' => $exist->ID, // be sure to set the post_id so that we don't create a new post 
									'post_title' => sprintf('%s on %s @ %s', $post->post_title, date('Y-m-d', $d), date('g:ia', $d)), // make a pretty title to describe the event
									'post_name' => $tmp->title, // use the normalized event slug for pretty urls
									'post_status' => in_array( $tmp->visibility, array( 'hidden', 'private' ) ) ? $tmp->visibility : $tmp->status, // set the post status of the event
									'post_password' => $tmp->password, // protected events have passwords
									'post_date' => $tmp->pub_date == '' || $tmp->pub_date == 'now' ? '' : date_i18n( 'Y-m-d H:i:s', strtotime( $tmp->pub_date ) ),
								), $defs),
								'meta' => array( // set the meta
									self::$o->{'meta_key.capacity'} => $tmp->capacity, // occupant capacity
									self::$o->{'meta_key.end'} => $tmp->end, // event end date/time for later lookup and display
									self::$o->{'meta_key.start'} => $tmp->start, // event start data/time for later lookup and display
								),
								'submitted' => $tmp,
							);
							$matched[] = $exist->ID; // mark as matched
						}
					}
				}
				
				// if there are still un matched sub event settings (always will be on new events with sub events)
				if (count($need_lookup)) {
					// cycle through them
					foreach ($need_lookup as $k => $data) {
						// get the date in timestamp form so that we can use it to make a pretty title
						$d = strtotime($data->start);
						// add the settings to the list of posts to update/insert
						$updates[] = array(
							'post_arr' => wp_parse_args(array( // will INSERT because there is no post_id
								'post_title' => sprintf('%s on %s @ %s', $post->post_title, date('Y-m-d', $d), date('g:ia', $d)), // create a pretty title
								'post_name' => $data->title, // user pretty url slug
								'post_status' => in_array( $data->visibility, array( 'hidden', 'private' ) ) ? $data->visibility : $data->status, // set the post status of the event
								'post_password' => $data->password, // protected events have passwords
								'post_date' => $data->pub_date == '' || $data->pub_date == 'now' ? '' : date_i18n( 'Y-m-d H:i:s', strtotime( $data->pub_date ) ),
							), $defs),
							'meta' => array( // set meta
								self::$o->{'meta_key.capacity'} => $data->capacity, // occupant copacity
								self::$o->{'meta_key.end'} => $data->end, // end data for lookup and display
								self::$o->{'meta_key.start'} => $data->start, // start date for lookup and display
							),
							'submitted' => $data,
						);
					}
				}
			}

			// if the event ui has marked that there have been events removed, then remove any unmatched events, assuming that any we did not get settings for, should be removed.
			if (isset($_POST['events-removed']) && $_POST['events-removed'] == 1) {
				// remove non-matched/non-updated sub posts before updating existing posts and creating new ones
				// args to lookup posts to remove
				$args = array(
					'post_type' => self::$o->core_post_type, // must be an event
					'post_parent' => $post_id, // and a child of the current event
					'post_status' => 'any', // can be of any status, even our special ones
					'posts_per_page' => -1, // fetch a comprehensive list
				);
				if (!empty($matched)) $args['post__not_in'] = $matched; // only fetch the unmatched ones
				$delete = get_posts($args); // get the list
				if (is_array($delete)) foreach ($delete as $del) wp_delete_post($del->ID, true); // delete all posts in the list
			}

			// for every item in the update list, either update or create a subevent
			foreach ($updates as $update) {
				$update = apply_filters('qsot-events-save-sub-event-settings', $update, $post_id, $post);
				if (isset($update['post_arr']) && is_array($update['post_arr'])) {
					$event_id = wp_insert_post($update['post_arr']); // update/insert the subevent
					if (is_numeric($event_id))
						foreach ($update['meta'] as $k => $v) update_post_meta($event_id, $k, $v); // update/add the meta to the new subevent
					// keep track of the earliest start time of all sub events
					if (isset($update['meta'][self::$o->{'meta_key.start'}])) {
						$start_date = empty($start_date) ? strtotime($update['meta'][self::$o->{'meta_key.start'}]) : min($start_date, strtotime($update['meta'][self::$o->{'meta_key.start'}]));
					}
					// keep track of the latest end time of all sub events
					if (isset($update['meta'][self::$o->{'meta_key.end'}])) {
						$end_date = empty($end_date) ? strtotime($update['meta'][self::$o->{'meta_key.end'}]) : max($end_date, strtotime($update['meta'][self::$o->{'meta_key.end'}]));
					}
				}
			}

			// update the start and end time fo the parent event
			$current_start = get_post_meta($post_id, self::$o->{'meta_key.start'}, true);
			$current_end = get_post_meta($post_id, self::$o->{'meta_key.end'}, true);
			
			@list($actual_start, $actual_end) = apply_filters('qsot-event-date-range', array(), array('event_id' => $post_id));

			$submit_start_date = $submit_end_date = array();
			if (isset($_POST['_qsot_start_date']) && !empty($_POST['_qsot_start_date'])) $submit_start_date[] = $_POST['_qsot_start_date'];
			if (isset($_POST['_qsot_start_time']) && !empty($_POST['_qsot_start_time'])) $submit_start_date[] = $_POST['_qsot_start_time'];
			if (isset($_POST['_qsot_end_date']) && !empty($_POST['_qsot_end_date'])) $submit_end_date[] = $_POST['_qsot_end_date'];
			if (isset($_POST['_qsot_end_time']) && !empty($_POST['_qsot_end_time'])) $submit_end_date[] = $_POST['_qsot_end_time'];

			$submit_start_date = count($submit_start_date) == 2 ? implode(' ', $submit_start_date) : '';
			$submit_end_date = count($submit_end_date) == 2 ? implode(' ', $submit_end_date) : '';

			if ($submit_start_date == $current_start && $current_start != $actual_start) $submit_start_date = $actual_start;
			if ($submit_end_date == $current_end && $current_end != $actual_end) $submit_end_date = $actual_end;

			// if we have a min start time and max end time, then save them to the main parent event, for use in lookup of the featured event ordering
			if (!empty($submit_start_date))
				update_post_meta($post_id, self::$o->{'meta_key.start'}, $submit_start_date);
			else if (!empty($actual_start))
				update_post_meta($post_id, self::$o->{'meta_key.start'}, $actual_start);

			if (!empty($submit_end_date))
				update_post_meta($post_id, self::$o->{'meta_key.end'}, $submit_end_date);
			else if (!empty($actual_end))
				update_post_meta($post_id, self::$o->{'meta_key.end'}, $actual_end);

			// save the stop selling formula
			$formula = $_POST['_qsot_stop_sales_before_show'];
			update_post_meta($post_id, '_stop_sales_before_show', $formula);
		}
	}

	public static function core_setup_meta_boxes($post_type) {
		global $post;
		if ($post->post_parent == 0) {
			add_meta_box(
				'event-date-time',
				'Event Date Time Settings',
				array(__CLASS__, 'mb_event_date_time_settings'),
				self::$o->core_post_type,
				'normal',
				'high'
			);
		}

		add_meta_box(
			'stop-sales-before-show',
			'Stop Sales Before Show',
			array(__CLASS__, 'mb_stop_sales_before_show'),
			self::$o->core_post_type,
			'side',
			'core'
		);

		add_meta_box(
			'event-run-date-range',
			'Event Run Date Range',
			array(__CLASS__, 'mb_event_run_date_range'),
			self::$o->core_post_type,
			'side',
			'core'
		);
	}

	public static function mb_stop_sales_before_show($post, $mb) {
		$formula = get_post_meta($post->ID, '_stop_sales_before_show', true);
		?>
			<div class="field-wrap">
				<div class="label"><label>Formula:</label></div>
				<div class="field">
					<input type="text" class="widefat" name="_qsot_stop_sales_before_show" value="<?php echo esc_attr($formula) ?>" />
				</div>
			</div>

			<p>
				This is the formula to calculate when tickets should stop being sold on the frontend for this show.
				For example, if you wish to stop selling tickets 2 hours and 30 minutes before the show, use: <code>2 hours 30 minutes</code>.
				Valid units include: hour, hours, minute, minutes, second, seconds, day, days, week, weeks, month, months, year, years.
				Leave the formula empty to just use the Global Setting for this formula.
			</p>
		<?php
	}

	public static function mb_event_run_date_range($post, $mb) {
		@list($start, $start_time) = explode(' ', get_post_meta($post->ID, '_start', true));
		@list($end, $end_time) = explode(' ', get_post_meta($post->ID, '_end', true));
		
		?>
			<style>
				#event-run-date-range .field-wrap { margin-bottom:6px; }
				#event-run-date-range .field-wrap label { font-weight:bold; margin-bottom:2px; }
			</style>

			<div class="field-wrap">
				<label>Start Date/Time:</label>
				<div class="field">
					<table cellspacing="0">
						<tbody>
							<tr>
								<td width="60%">
									<input type="text" class="widefat use-datepicker" name="_qsot_start_date" value="<?php echo esc_attr($start) ?>" />
								</td>
								<td width="1%">@</td>
								<td width="39%">
									<input type="text" class="widefat use-timepicker" name="_qsot_start_time" value="<?php echo esc_attr($start_time) ?>" />
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
			<div class="field-wrap">
				<label>End Date/Time:</label>
				<div class="field">
					<table cellspacing="0">
						<tbody>
							<tr>
								<td width="60%">
									<input type="text" class="widefat use-datepicker" name="_qsot_end_date" value="<?php echo esc_attr($end) ?>" />
								</td>
								<td width="1%">@</td>
								<td width="39%">
									<input type="text" class="widefat use-timepicker" name="_qsot_end_time" value="<?php echo esc_attr($end_time) ?>" />
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		<?php
	}

	public static function mb_event_date_time_settings($post, $mb) {
		?>
			<div class="<?php echo self::$o->pre ?>event-date-time-wrapper events-ui">
				<input type="hidden" name="save-recurrence" value="1"/>
				<div class="option-scope">
					<div class="option-sub above hide-if-js" rel="add">
						<table class="event-date-time-settings settings-table">
							<tbody>
								<tr>
									<td width="50%">
										<h4>Basic Settings</h4>
										<div class="date-time-block subsub">
											<?php $now = strtotime(current_time('mysql')) ?>
											<input type="text" class="use-datepicker date-text" name="start-date" value="<?php echo date('Y-m-d', $now) ?>" title="Start Date" />
											<input type="text" class="time-text" name="start-time" value="<?php echo date('h:ia', $now) ?>" title="Start Time" />
											to
											<?php $end = strtotime('+1 hour', $now); ?>
											<input type="text" class="use-datepicker date-text" name="end-date" value="<?php echo date('Y-m-d', $end) ?>" title="End Date" />
											<input type="text" class="time-text" name="end-time" value="<?php echo date('h:ia', $end) ?>" title="End Time" />
										</div>
										
										<div class="event-settings-block subsub">
											<span class="cb-wrap">
												<input type="checkbox" name="repeat" value="1" class="togvis" tar=".repeat-options" scope=".option-sub" auto="auto" />
												<span class="cb-text">Repeat...</span>
											</span>
										</div>

										<?php do_action('qsot-events-basic-settings', $post, $mb) ?>
									</td>

									<td>
										<div class="repeat-options hide-if-js">
											<h4>Repeat</h4>
											<div class="repeat-settings subsub">
												<table class="repeat-settings-wrapper settings-list">
													<tbody>
														<tr>
															<th>Repeats:</th>
															<td>
																<select name="repeats" class="togvis" tar=".repeat-options-%VAL%" scope=".repeat-settings" auto="auto">
																	<?php /* <option value="daily">Daily</option> */ ?>
																	<option value="weekly" <?php selected(true, true) ?>>Weekly</option>
																</select>
															</td>
														</tr>

														<tr>
															<th>Repeats Every:</th>
															<td>
																<select name="repeat-every">
																	<?php for ($i=1; $i<=30; $i++): ?>
																		<option value="<?php echo $i; ?>"><?php echo $i; ?></option>
																	<?php endfor; ?>
																</select>
																<span class="every-descriptor repeat-options-daily hide-if-js">days</span>
																<span class="every-descriptor repeat-options-weekly hide-if-js">weeks</span>
															</td>
														</tr>

														<tr class="hide-if-js repeat-options-weekly">
															<th>Repeat on:</th>
															<td>
																<span class="cb-wrap">
																	<input type="checkbox" name="repeat-on[]" value="0" <?php selected(date('w', $now), 0) ?> />
																	<span class="cb-text">Su</span>
																</span>
																<span class="cb-wrap">
																	<input type="checkbox" name="repeat-on[]" value="1" <?php selected(date('w', $now), 1) ?> />
																	<span class="cb-text">M</span>
																</span>
																<span class="cb-wrap">
																	<input type="checkbox" name="repeat-on[]" value="2" <?php selected(date('w', $now), 2) ?> />
																	<span class="cb-text">Tu</span>
																</span>
																<span class="cb-wrap">
																	<input type="checkbox" name="repeat-on[]" value="3" <?php selected(date('w', $now), 3) ?> />
																	<span class="cb-text">W</span>
																</span>
																<span class="cb-wrap">
																	<input type="checkbox" name="repeat-on[]" value="4" <?php selected(date('w', $now), 4) ?> />
																	<span class="cb-text">Th</span>
																</span>
																<span class="cb-wrap">
																	<input type="checkbox" name="repeat-on[]" value="5" <?php selected(date('w', $now), 5) ?> />
																	<span class="cb-text">F</span>
																</span>
																<span class="cb-wrap">
																	<input type="checkbox" name="repeat-on[]" value="6" <?php selected(date('w', $now), 6) ?> />
																	<span class="cb-text">Sa</span>
																</span>
															</td>
														</tr>

														<tr>
															<th>Starts on:</th>
															<td>
																<input type="text" class="widefat date-text use-datepicker" name="repeat-starts" value="<?php echo date('Y-m-d', $now) ?>" />
															</td>
														</tr>

														<tr>
															<th>Ends:</th>
															<td>
																<ul>
																	<li>
																		<span class="cb-wrap">
																			<input type="radio" name="repeat-ends-type" value="on" checked="checked" />
																			<span class="cb-text">On:</span>
																		</span>
																		<input type="text" class="widefat date-text use-datepicker" name="repeat-ends-on" value="<?php echo date('Y-m-d', $now) ?>" />
																	</li>
																	<li>
																		<span class="cb-wrap">
																			<input type="radio" name="repeat-ends-type" value="after" />
																			<span class="cb-text">After:</span>
																		</span>
																		<input type="number" class="widefat date-text" name="repeat-ends-after" value="15" />
																		<span> occurences</span>
																	</li>
																	<?php do_action('qsot-events-repeat-ends-type', $post, $mb) ?>
																</ul>
															</td>
														</tr>

														<?php do_action('qsot-events-repeat-options', $post, $mb) ?>
													</tbody>
												</table>
											</div>
										</div>
									</td>
								</tr>

								<?php do_action('qsot-events-date-time-settings-rows', $post, $mb) ?>
							</tbody>
						</table>

						<div class="clear"></div>
						<div class="actions">
							<input type="button" value="Add to Calendar" class="action button button-primary" rel="add-btn" />
						</div>
						<ul class="messages" rel="messages">
						</ul>
					</div>
				</div>

				<div class="<?php echo self::$o->pre ?>event-calendar-wrap option-sub no-border">
					<div class="<?php echo self::$o->pre ?>event-calendar" rel="calendar"></div>
				</div>

				<div class="option-sub" rel="settings">
					<table class="event-settings settings-table">
						<tbody>
							<tr>
								<td width="1%" class="date-selection-column">
									<h4>Event Date/Times</h4>
									<div class="event-date-time-list-view" rel="event-list"></div>
								</td>

								<td>
									<div class="bulk-edit-settings hide-if-js" rel="settings-main-form">
										<h4>Settings</h4>
										<div class="settings-form">
											<div class="setting-group">
												<div class="setting" rel="setting-main" tag="status">
													<div class="setting-current">
														<span class="setting-name">Status:</span>
														<span class="setting-current-value" rel="setting-display"></span>
														<a class="edit-btn" href="#" rel="setting-edit" scope="[rel=setting]" tar="[rel=form]">Edit</a>
														<input type="hidden" name="settings[status]" value="" scope="[rel=setting-main]" rel="status" />
													</div>
													<div class="setting-edit-form" rel="setting-form">
														<select name="status">
															<option value="publish" data-only-if="status=,publish,pending,draft,hidden,private"><?php _e( 'Published' ) ?></option>
															<option value="private" data-only-if="status=private"><?php _e( 'Privately Published' ) ?></option>
															<option value="future" data-only-if="status=future"><?php _e( 'Scheduled' ) ?></option>
															<?php do_action( 'qsot-event-setting-custom-status', $post, $mb ) ?>
															<option value="pending"><?php _e( 'Pending Review' ) ?></option>
															<option value="draft"><?php _e( 'Draft' ) ?></option>
														</select>
														<div class="edit-setting-actions">
															<input type="button" class="button" rel="setting-save" value="OK" />
															<a href="#" rel="setting-cancel">Cancel</a>
														</div>
													</div>
												</div>

												<div class="setting" rel="setting-main" tag="visibility">
													<div class="setting-current">
														<span class="setting-name">Visibility:</span>
														<span class="setting-current-value" rel="setting-display"></span>
														<a class="edit-btn" href="#" rel="setting-edit" scope="[rel=setting]" tar="[rel=form]">Edit</a>
														<input type="hidden" name="settings[visibility]" value="" scope="[rel=setting-main]" rel="visibility" />
													</div>
													<div class="setting-edit-form" rel="setting-form">
														<div class="cb-wrap" title="<?php _e( 'Viewable to the public', 'qsot' ) ?>">
															<input type="radio" name="visibility" value="public" />
															<span class="cb-text"><?php _e( 'Public' ) ?></span>
														</div>
														<div class="cb-wrap" title="<?php _e( 'Visible on the calendar, but only those with the password can view to make reservations', 'qsot' ) ?>">
															<input type="radio" name="visibility" value="protected" />
															<span class="cb-text"><?php _e( 'Password Protected' ) ?></span>
															<div class="extra" data-only-if="visibility=protected">
																<label>Password:</label><br/>
																<input type="text" name="password" value="" rel="password" />
															</div>
														</div>
														<div class="cb-wrap" title="<?php _e( 'Hidden from the calendar, but open to anyone with the url', 'qsot' ) ?>">
															<input type="radio" name="visibility" value="hidden" />
															<span class="cb-text"><?php _e( 'Hidden' ) ?></span>
														</div>
														<div class="cb-wrap" title="<?php _e( 'Only logged in admin users or the event author can view it', 'qsot' ) ?>">
															<input type="radio" name="visibility" value="private" />
															<span class="cb-text"><?php _e( 'Private' ) ?></span>
														</div>
														<div class="edit-setting-actions">
															<input type="button" class="button" rel="setting-save" value="OK" />
															<a href="#" rel="setting-cancel">Cancel</a>
														</div>
													</div>
												</div>

												<div class="setting" rel="setting-main" tag="pub_date">
													<div class="setting-current">
														<span class="setting-name">Publish Date:</span>
														<span class="setting-current-value" rel="setting-display"></span>
														<a class="edit-btn" href="#" rel="setting-edit" scope="[rel=setting]" tar="[rel=form]">Edit</a>
														<input type="hidden" name="settings[pub_date]" value="" scope="[rel=setting-main]" rel="pub_date" />
													</div>
													<div class="setting-edit-form" rel="setting-form">
														<input type="hidden" name="pub_date"  value="" />
														<div class="date-edit" tar="[name='pub_date']" rel="[rel='setting-form']">
															<select rel="month">
																<option value="01">01 - <?php _e( 'Januaray', 'qsot' ) ?></option>
																<option value="02">02 - <?php _e( 'February', 'qsot' ) ?></option>
																<option value="03">03 - <?php _e( 'March', 'qsot' ) ?></option>
																<option value="04">04 - <?php _e( 'April', 'qsot' ) ?></option>
																<option value="05">05 - <?php _e( 'May', 'qsot' ) ?></option>
																<option value="06">06 - <?php _e( 'June', 'qsot' ) ?></option>
																<option value="07">07 - <?php _e( 'July', 'qsot' ) ?></option>
																<option value="08">08 - <?php _e( 'August', 'qsot' ) ?></option>
																<option value="09">09 - <?php _e( 'September', 'qsot' ) ?></option>
																<option value="10">10 - <?php _e( 'October', 'qsot' ) ?></option>
																<option value="11">11 - <?php _e( 'November', 'qsot' ) ?></option>
																<option value="12">12 - <?php _e( 'December', 'qsot' ) ?></option>
															</select>
															<input type="text" rel="day" value="" size="2" />, 
															<input type="text" rel="year" value="" size="4" class="year" /> @ 
															<input type="text" rel="hour" value="" size="2" /> : 
															<input type="text" rel="minute" value="" size="2" />
														</div>
														<div class="edit-setting-actions">
															<input type="button" class="button" rel="setting-save" value="OK" />
															<a href="#" rel="setting-cancel">Cancel</a>
														</div>
													</div>
												</div>
											</div>

											<?php do_action('qsot-events-bulk-edit-settings', $post, $mb); ?>
										</div>
									</div>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<?php do_action('qsot-events-more-settings') ?>
			</div>
		<?php
	}

	protected static function _setup_admin_options() {
		self::$options->def('qsot-single-synopsis', 'no');
		self::$options->def('qsot-stop-sales-before-show', '');

		self::$options->add(array(
			'order' => 100,
			'type' => 'title',
			'title' => __('General Settings', 'qsot'),
			'id' => 'heading-general-1',
		));

		self::$options->add(array(
			'order' => 105,
			'id' => 'qsot-stop-sales-before-show',
			'type' => 'text',
			'title' => __('Stop Sales Before Show', 'qsot'),
			'desc' => __('Amount of time to stop sales for a show, before show time. (ie: stop sales two hour before show time <code>2 hours</code>)', 'qsot'),
			'desc_tip' => __('valid units: hour, hours, minute, minutes, second, seconds, day, days, week, weeks, month, months, year, years', 'qsot'),
		));

		self::$options->add(array(
			'order' => 110,
			'id' => 'qsot-single-synopsis',
			'type' => 'checkbox',
			'title' => __('Single Event Synopsis', 'qsot'),
			'desc' => __('Show event synopsis on single event pages', 'qsot'),
			'desc_tip' => __('By default, just the event logo, and the event pricing options are shown. This feature will additionally show the description of the event to the user.', 'qsot'),
			'default' => 'no',
		));

		self::$options->add(array(
			'order' => 199,
			'type' => 'sectionend',
			'id' => 'heading-general-1',
		));
	}
}

if (defined('ABSPATH') && function_exists('add_action')) {
	qsot_post_type::pre_init();
}
