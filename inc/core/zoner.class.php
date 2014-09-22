<?php (__FILE__ == $_SERVER['SCRIPT_FILENAME']) ? die(header('Location: /')) : null;

if (!class_exists('qsot_zoner')):

// handles reservations, creation, update, and deletion
class qsot_zoner {
	protected static $o = null;

	public static function pre_init() {
		$settings_class_name = apply_filters('qsot-settings-class-name', '');
		if (empty($settings_class_name) || !class_exists($settings_class_name)) return;
		self::$o = call_user_func_array(array($settings_class_name, 'instance'), array());
		
		self::$o->z = apply_filters('qsot-zoner-settings', array(
			'states' => array(
				'o' => 'occupied', // confirmed seat that has been actually occupied, via checkin feature. lasts indefinitely
				'c' => 'confirmed', // user confirmed seat, and paid for it (if applicable). lasts indefinitely
				'r' => 'reserved', // user has selected a price for a seat, but has not yet completed the purchase. lasts 1 hour
			),
			'state_timeouts' => array( // in seconds
				'o' => 0, // never
				'c' => 0, // never
				'r' => 3600, // 1 hour
			),
		));

		// setup the db tables for the zone reserver
		global $wpdb;
		$wpdb->qsot_event_zone_to_order = $wpdb->prefix.'qsot_event_zone_to_order';
		add_filter('qsot-upgrader-table-descriptions', array(__CLASS__, 'setup_tables'), 10);

		// state timeouts
		add_filter('qsot-temporary-zone-states', array(__CLASS__, 'temporary_zone_states'), 10, 1);
		add_filter('qsot-permanent-zone-states', array(__CLASS__, 'permanent_zone_states'), 10, 1);

		// reservation functions
		add_filter('qsot-zoner-item-data-keys', array(__CLASS__, 'item_data_keys_to_maintain'), 10, 2);
		add_action('qsot-zoner-clear-locks', array(__CLASS__, 'clear_locks'), 10, 2);
		add_filter('qsot-zoner-reserve-current-user', array(__CLASS__, 'reserve_current_user'), 10, 4);
		add_filter('qsot-zoner-reserve', array(__CLASS__, 'reserve'), 10, 6);
		add_filter('qsot-zoner-owns-current-user', array(__CLASS__, 'owns_current_user'), 10, 4);
		add_filter('qsot-zoner-owns', array(__CLASS__, 'owns'), 10, 7);
		add_filter('qsot-zoner-ownerships-current-user', array(__CLASS__, 'ownerships_current_user'), 10, 4);
		add_filter('qsot-zoner-ownerships', array(__CLASS__, 'ownerships'), 10, 7);
		add_filter('qsot-zoner-update-reservation', array(__CLASS__, 'update_reservation'), 10, 3);
		add_filter('qsot-zoner-current-user', array(__CLASS__, 'current_user'), 10, 3);
		add_filter('qsot-zoner-order-event-qty-state', array(__CLASS__, 'get_state_from_order_event_quantity'), 10, 4);

		// determine if the item could be a ticket
		add_filter('qsot-item-is-ticket', array(__CLASS__, 'item_is_ticket'), 100, 2);

		// checkin code
		add_filter('qsot-is-already-occupied', array(__CLASS__, 'is_occupied'), 1000, 4);
		add_filter('qsot-occupy-sold', array(__CLASS__, 'occupy_sold'), 1000, 5);

		// stats
		add_filter('qsot-count-tickets', array(__CLASS__, 'count_tickets'), 1000, 2);
	}

	public static function get_state_from_order_event_quantity($state, $order_id, $event_id, $qty) {
		global $wpdb;

		$q = $wpdb->prepare('select `state` from '.$wpdb->qsot_event_zone_to_order.' where order_id = %d and event_id = %d and quantity = %d limit 1', $order_id, $event_id, $qty);
		$res = $wpdb->get_var($q);

		return $res ? $res : $state;
	}

	public static function count_tickets($current, $args='') {
		$args = wp_parse_args($args, array(
			'state' => '*',
			'event_id' => '',
		));

		global $wpdb;

		$q = 'select state, sum(quantity) tot from '.$wpdb->qsot_event_zone_to_order.' where 1=1';
		if ( !empty( $args['event_id'] ) ) {
			if ( is_array( $args['event_id'] ) ) {
				$ids = array_filter( array_map( 'absint', $args['event_id'] ) );
				if ( ! empty( $ids ) )
					$q .= ' and event_id in (' . implode(',', $ids) . ')';
			} else if ( (int)$args['event_id'] > 0 ) {
				$q .= $wpdb->prepare( ' and event_id = %d', $args['event_id'] );
			}
		}
		$q .= ' group by state';

		$rows = $wpdb->get_results($q);
		$out = array();

		if (empty($rows)) return (!empty($args['state']) && $args['state'] != '*') ? 0 : $out;

		foreach ($rows as $row) $out[$row->state] = $row->tot;

		if (!empty($args['state']) && $args['state'] != '*') return isset($out[$args['state']]) ? $out[$args['state']] : 0;

		return $out;
	}

	// list of 'states' (db table field) that are considered temporary, and expire
	public static function temporary_zone_states($list) {
		static $ours = false;
		if ($ours === false) {
			$ours = array();
			foreach (self::$o->{'z.state_timeouts'} as $k => $time)
				if ($time > 0)
					$ours[] = self::$o->{'z.states.'.$k};
		}
		return is_array($list) ? array_unique(array_merge($list, $ours)) : $ours;
	}

	// list of 'states' (db table field) that are considered permanent, and never expire
	public static function permanent_zone_states($list) {
		static $ours = false;
		if ($ours === false) {
			$ours = array();
			foreach (self::$o->{'z.state_timeouts'} as $k => $time)
				if ($time <= 0)
					$ours[] = self::$o->{'z.states.'.$k};
		}
		return is_array($list) ? array_unique(array_merge($list, $ours)) : $ours;
	}

	// determine if all of a given ticket have been marked as occupied or not
	public static function is_occupied($current, $order_id, $event_id, $oiid) {
		$order = new WC_Order($order_id);
		$event = apply_filters('qsot-get-event', false, $event_id);
		if (!is_object($order) || !is_object($event) || !isset($order->id)) return false;

		$order_items = $order->get_items();
		$oi = isset($order_items[$oiid]) ? $order_items[$oiid] : false;
		if (!is_array($oi) || !isset($oi['event_id'])) return false;

		$confirms = apply_filters('qsot-zoner-owns', array(), $event, $oi['product_id'], self::$o->{'z.states.c'}, false, $order_id, $oiid);
		
		return !!$confirms;
	}

	// if there are 'confirmed' seats that are not checked in yet (occupied) that match the given criteria, then check them in
	public static function occupy_sold($current, $order_id, $event_id, $oiid, $qty) {
		$order = new WC_Order($order_id);
		$event = apply_filters('qsot-get-event', false, $event_id);
		if (!is_object($order) || !is_object($event) || !isset($order->id)) return false;

		$order_items = $order->get_items();
		$oi = isset($order_items[$oiid]) ? $order_items[$oiid] : false;
		if (!is_array($oi) || !isset($oi['event_id'])) return false;

		// get a list of all states that have entries for this ticket purchase
		$all = apply_filters('qsot-zoner-owns', array(), $event, $oi['product_id'], '*', false, $order_id, $oiid);

		// if there are none in the 'confirm' category, then either we have a non-ticket (unlikely) or they are all checked in already. either way, fail.
		if (!isset($all[self::$o->{'z.states.c'}]) || (int)$all[self::$o->{'z.states.c'}] < $qty) return false;
		$confirms = apply_filters('qsot-zoner-ownerships', array(), $event, $oi['product_id'], self::$o->{'z.states.c'}, false, $order_id, $oiid);
		if (empty($confirms)) return false;

		// if there a none already checked in, then insert a row to be updated
		if (!isset($all[self::$o->{'z.states.o'}])) {
			global $wpdb;
			$wpdb->insert(
				$wpdb->qsot_event_zone_to_order,
				array(
					'event_id' => $event_id,
					'ticket_type_id' => $oi['product_id'],
					'quantity' => 0,
					'state' => self::$o->{'z.states.o'},
					'session_customer_id' => $confirms[0]->session_customer_id,
					'order_id' => $order_id,
					'order_item_id' => $oiid,
				)
			);
			$all[self::$o->{'z.states.o'}] = 0;
		}

		$res_dec = apply_filters(
			'qsot-zoner-update-reservation',
			false,
			array('event_id' => $event_id, 'qty' => (int)$all[self::$o->{'z.states.c'}], 'state' => self::$o->{'z.states.c'}, 'order_id' => $order_id, 'order_item_id' => $oiid),
			array('qty' => '::DEC::')
		);

		$res_inc = apply_filters(
			'qsot-zoner-update-reservation',
			false,
			array('event_id' => $event_id, 'qty' => (int)$all[self::$o->{'z.states.o'}], 'state' => self::$o->{'z.states.o'}, 'order_id' => $order_id, 'order_item_id' => $oiid),
			array('qty' => '::INC::')
		);
	}

	// is the order item marked as a ticket would be marked?
	public static function item_is_ticket($is, $item) {
		if (!isset($item['event_id']) || empty($item['event_id'])) return false;
		return $is;
	}

	// clear out any temporary locks that have expired
	public static function clear_locks($event_id=0, $customer_id=false) {
		global $wpdb;
		// require either required basic information type
		if (empty($event_id) && empty($customer_id)) return;

		// get a list of expireable states, and format it for quick, reliable use later
		$temp_states = array_flip(apply_filters('qsot-temporary-zone-states', array()));

		// cycle through each state type
		foreach (self::$o->{'z.states'} as $k => $name) {
			// if it is not a temporary state, pass
			if (!isset($temp_states[$name])) continue;

			// get the timeout of the state
			$timeout = (int)self::$o->{'z.state_timeouts.'.$k};

			// if there is a defined, positive timeout, then we need to remove any temporary locks for this state, that have surpassed that timeout
			if ($timeout > 0) {
				// build a query that will find all locks that have expired, based on the supplied criteria. we fetch the list so that we can
				// notify other sources that these locks are going away (such as other plugins, or upgrades to this plugin)
				$q = $wpdb->prepare('select * from '.$wpdb->qsot_event_zone_to_order.' where state = %s and since < NOW() - INTERVAL %d SECOND', self::$o->{'z.states.'.$k}, $timeout);
				// if the event was supplied, reduce the list to only ones for this event
				if (!empty($event_id)) $q .= $wpdb->prepare(' and event_id = %d', $event_id);
				// if the customer id was supplied then, add that to the sql
				if (!empty($customer_id)) {
					if (is_array($customer_id)) $q .= ' and session_customer_id in(\''.implode('\',\'', array_map('esc_sql', $customer_id)).'\')';
					else $q .= $wpdb->prepare(' and session_customer_id = %s', $customer_id);
				}
				// fetch a list of existing locks.
				$locks = $wpdb->get_results($q);

				// tell everyone that the locks are going away
				do_action('qsot-removing-zone-locks', $locks, self::$o->{'z.states.'.$k}, $event_id, $customer_id);

				// delete the locks we said we would delete in the above action.
				// this is done in this manner, because we need to only delete the ones we told others about.
				// technically, if the above action call takes too long, other locks could have expired by the time we get to delete them.
				// thus we need to explicitly delete ONLY the ones we told everyone we were deleting, so that none are removed without the others being notified.
				$q = 'delete from '.$wpdb->qsot_event_zone_to_order.' where '; // base query
				$wheres = array(); // holder for queries defining each specific row to delete
				// cycle through all the locks we said we would delete
				foreach ($locks as $lock) {
					// aggregate a partial where statement, that specifically identifies this row, using all fields for positive id
					$fields = array();
					foreach ($lock as $k => $v) $fields[] = $wpdb->prepare($k.' = %s', $v);
					if (!empty($fields)) $wheres[] = implode(' and ', $fields);
				}
				// if we have where statements for at least one row to remove
				if (!empty($wheres)) {
					// glue the query together, and run it to delete the rows
					$q .= '('.implode(') or (', $wheres).')';
					$wpdb->query($q);
				}
			}
		}
	}

	// tell woocommerce what item meta to keep when a users moves from page to page, so that we dont lose vital ticket data
	public static function item_data_keys_to_maintain($current) {
		$current = is_array($current) ? $current : array();
		$current[] = 'event_id';
		return $current;
	}

	// current_user is the id we use to lookup tickets in relation to a product in a cart. once we have an order number this pretty much becomes obsolete
	public static function current_user($current, $order_id=false, $data='') {
		global $woocommerce;
		$res = false;
		$data = wp_parse_args($data, array('customer_user' => false));
		if ($data['customer_user']) $res = $data['customer_user'];
		if (empty($res) && (int)$order_id > 0) $res = get_post_meta($order_id, '_customer_user', true);
		if (empty($res) && is_object($woocommerce->session)) $res = $woocommerce->session->get_customer_id();
		if (empty($res)) $res = $current;

		return $res;
	}

	// add a reservation for the current user
	public static function reserve_current_user($success, $event, $ticket_type_id, $count) {
		// idetify the current user
		$customer_id = apply_filters('qsot-zoner-current-user', md5(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : time()));
		$success = false;
		// add the reservation
		return apply_filters('qsot-zoner-reserve', $success, $event, $ticket_type_id, $count, $customer_id);
	}
	
	// add the reservation
	public static function reserve($success, $event, $ticket_type_id, $count, $customer_id, $order_id=0) {
		global $wpdb;

		// event is required infromation
		$event = is_numeric($event) && $event > 0 ? get_post($event) : $event;
		if (!is_object($event)) return false;

		// if for some reason the count is negative (due to external plugin or hacker funny business) then do nothing, becasue it is impossible to have -1 seats
		if ($count < 0) return false;
		// if setting the reservation count to 0, then delete reservations if they already exist
		else if ($count == 0) {
			$res = $wpdb->delete(
				$wpdb->qsot_event_zone_to_order,
				array('event_id' => $event->ID, 'ticket_type_id' => $ticket_type_id, 'state' => self::$o->{'z.states.r'}, 'session_customer_id' => $customer_id, 'order_id' => $order_id)
			);
			$success = true;
		// if count is > 0 then
		} else {
			// get the available occupancy of the event
			$available = apply_filters('qsot-get-event-available-tickets', 0, $event, $ticket_type_id);
			// determine how many this person already has reserved
			$owns = apply_filters('qsot-zoner-owns', 0, $event, $ticket_type_id, self::$o->{'z.states.r'}, $customer_id);

			// if this user already owns some seats for this event, then 
			if ($owns) {
				// if they are requesting more than is available, then just fail
				if ($count > ($available + $owns)) return false;
				// otherwise update the reservation count for this user for this event
				$res = $wpdb->update(
					$wpdb->qsot_event_zone_to_order,
					array('quantity' => $count),
					array('event_id' => $event->ID, 'ticket_type_id' => $ticket_type_id, 'state' => self::$o->{'z.states.r'}, 'session_customer_id' => $customer_id, 'order_id' => $order_id)
				);
				$success = $res !== false;
			// if the user does not already have reservations for this event, then
			} else {
				// if the user is requesting more than what is currently available, then just fail
				if ($count > $available) return false;
				// oterhwise, insert the reservations for these seats now
				$res = $wpdb->insert(
					$wpdb->qsot_event_zone_to_order,
					array(
						'event_id' => $event->ID,
						'ticket_type_id' => $ticket_type_id,
						'quantity' => $count,
						'state' => self::$o->{'z.states.r'},
						'session_customer_id' => $customer_id,
						'order_id' => $order_id,
					)
				);
				$success = (bool)$res;
			}
		}

		return $success;
	}

	// get the total reservations for each zone that the current user owns (based on event, ticket type, and state)
	public static function owns_current_user($current, $event, $ticket_type_id, $state) {
		$customer_id = apply_filters('qsot-zoner-current-user', md5(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : time()));
		$success = false;
		return apply_filters('qsot-zoner-owns', $success, $event, $ticket_type_id, $state, $customer_id);
	}

	// get the total reservations for each zone that a given user owns (based on event, ticket type, state, customer_id or order)
	public static function owns($current, $event, $ticket_type_id, $state=false, $customer_id=false, $order_id=false, $order_item_id=false) {
		global $wpdb;

		// event is required information here
		$event = is_numeric($event) && $event > 0 ? apply_filters('qsot-get-event', false, $event) : $event;
		if (!is_object($event)) return 0;
		
		// generate an sql statement that will pull out the reservation list based on the supplied information
		$q = $wpdb->prepare('select sum(quantity) as cnt, state from '.$wpdb->qsot_event_zone_to_order.' where event_id = %d and ticket_type_id = %d', $event->ID, $ticket_type_id);
		// if the state was supplied, or it is 'all' states (*) then, add that to the query
		if (!empty($state) && $state != '*') {
			if (is_array($state)) $q .= ' and state in (\''.implode('\',\'', array_map('esc_sql', $state)).'\')';
			else $q .= $wpdb->prepare(' and state = %s', $state);
		}

		$subs = array();
		// if customer_id is supplied, add it to the query
		if (!empty($customer_id)) {
			if (is_array($customer_id)) $subs[] = 'session_customer_id in(\''.implode('\',\'', array_map('esc_sql', $customer_id)).'\')';
			else $subs[] = $wpdb->prepare('session_customer_id = %s', $customer_id);
		}
		// if order_id is supplied, add it to the query
		if (!empty($order_id)) {
			if (is_array($order_id)) $subs[] = 'order_id in(\''.implode('\',\'', array_map('esc_sql', $order_id)).'\')';
			else $subs[] = $wpdb->prepare('order_id = %s', $order_id);
		}
		// if order_item_id is supplied, add it to the query
		if (!empty($order_item_id)) {
			if (is_array($order_item_id)) $subs[] = 'order_item_id in(\''.implode('\',\'', array_map('esc_sql', $order_item_id)).'\')';
			else $subs[] = $wpdb->prepare('order_item_id = %s', $order_item_id);
		}
		
		if (!empty($subs)) $q .= ' and ('.implode(' or ', $subs).') ';

		$q .= ' group by state';

		// allow other plugins to add their logic
		$q = apply_filters('qsot-zoner-owns-query', $q, $event, $ticket_type_id, $state, $customer_id);

		// get the tallied results, grouped by state
		$counts = $wpdb->get_results($q);
		$indexed = array();
		// format the results in a useable form
		foreach ($counts as $count) $indexed[$count->state] = $count->cnt;

		// if all results were requested, or if there are no results, then just return the whole list
		if (empty($state) || $state == '*' || is_array($state)) return $indexed;

		// otherwise, we need to only return a specific resultset, based on requested state
		return isset($indexed[$state]) ? $indexed[$state] : 0;
	}

	// get the list of actual zones that the current user has reservations for (based on event, ticket type, and state)
	public static function ownerships_current_user($current, $event=0, $ticket_type_id=0, $state=false) {
		// determine current user
		$customer_id = apply_filters('qsot-zoner-current-user', md5(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : time()));
		// get the zone list
		return apply_filters('qsot-zoner-ownerships', $current, $event, $ticket_type_id, $state, $customer_id);
	}

	// get the list of actual zones that the _given_ user (and/or order_id, and/or order_item_id) has reservations for (based on event, ticket type, and state)
	public static function ownerships($current, $event=0, $ticket_type_id=0, $state=false, $customer_id=false, $order_id=false, $order_item_id=false) {
		global $wpdb;

		$event = is_numeric($event) && $event > 0 ? get_post($event) : $event;

		// build a query to pull the whole list zones this user owns
		$q = 'select * from '.$wpdb->qsot_event_zone_to_order.' where 1=1';
		// if the event was supplied, add it to the query to narrow the results
		if (is_object($event)) {
			$q = $wpdb->prepare(' and event_id = %d', $event->ID);
		}
		// if the ticket type (product_id) was supplied, add to the query to narrow results
		if ($ticket_type_id > 0) {
			$q = $wpdb->prepare(' and ticket_type_id = %d', $ticket_type_id);
		}
		// if the state was supplied, add it to the query to narrow....
		if (!empty($state) && $state != '*') {
			if (is_array($state)) $q .= ' and state in (\''.implode('\',\'', array_map('esc_sql', $state)).'\')';
			else $q .= $wpdb->prepare(' and state = %s', $state);
		}
		// if for specific user, add it to query....
		if (!empty($customer_id)) {
			if (is_array($customer_id)) $q .= ' and session_customer_id in(\''.implode('\',\'', array_map('esc_sql', $customer_id)).'\')';
			else $q .= $wpdb->prepare(' and session_customer_id = %s', $customer_id);
		}
		// if for specific order_id, add it to query....
		if (!empty($order_id)) {
			if (is_array($order_id)) $q .= ' and order_id in(\''.implode('\',\'', array_map('esc_sql', $order_id)).'\')';
			else $q .= $wpdb->prepare(' and order_id = %s', $order_id);
		}
		// if for specific order_item_id, add it to query....
		if (!empty($order_item_id)) {
			if (is_array($order_item_id)) $q .= ' and order_item_id in(\''.implode('\',\'', array_map('esc_sql', $order_item_id)).'\')';
			else $q .= $wpdb->prepare(' and order_item_id = %s', $order_item_id);
		}

		// allow external plugins to add their logic here
		$q = apply_filters('qsot-zoner-ownerships-query', $q, $event, $ticket_type_id, $state, $customer_id, $order_id, $order_item_id);

		// fetch the list
		$raw = $wpdb->get_results($q);
		$indexed = array();
		// organize the list
		foreach ($raw as $row) {
			$indexed[$row->state] = isset($indexed[$row->state]) && is_array($indexed[$row->state]) ? $indexed[$row->state] : array();
			$indexed[$row->state][] = $row;
		}

		// we have no results, we requested all results, or we requested multiple state results, then just return the whole resultset
		if (empty($indexed) || $state == '*' || is_array($state)) return $indexed;

		// otherwise only return the specified results
		return isset($indexed[$state]) ? $indexed[$state] : array();
	}

	// update/delete existing reservations
	public static function update_reservation($success, $where, $set) {
		global $wpdb;
		$is_delete = false;

		// generate the 'where statement' pieces for the sql query to perform the actual update
		$wheres = array();
		// if the order id was given, add it
		if (isset($where['order_item_id'])) {
			if (is_array($where['order_item_id'])) $wheres[] = ' and order_item_id in('.implode(',', $where['order_item_id']).')';
			else $wheres['order_item_id'] = $wpdb->prepare(' and order_item_id = %d', $where['order_item_id']);
		}
		// if the order id was given, add it
		if (isset($where['order_id'])) {
			if (is_array($where['order_id'])) $wheres[] = ' and order_id in('.implode(',', $where['order_id']).')';
			else $wheres['order_id'] = $wpdb->prepare(' and order_id = %d', $where['order_id']);
		}
		// if the customer was specified, add it
		if (isset($where['customer_id'])) {
			if (is_array($where['customer_id'])) $wheres[] = ' and session_customer_id in(\''.implode('\',\'', $where['customer_id']).'\')';
			else $wheres['customer_id'] = $wpdb->prepare(' and session_customer_id = %s', $where['customer_id']);
		}
		// if the state was given, add it
		if (isset($where['state']) && $where['state'] != '*') {
			if (is_array($where['state'])) $wheres[] = ' and state in (\''.implode('\',\'', $where['state']).'\')';
			else $wheres['state'] = $wpdb->prepare(' and state = %s', $where['state']);
		}
		// if the event id was given, add it
		if (isset($where['event_id'])) {
			if (is_array($where['event_id'])) $wheres[] = ' and event_id in ('.implode(',', $where['event_id']).')';
			else $wheres['event_id'] = $wpdb->prepare(' and event_id = %d', $where['event_id']);
		}
		// if the product_id of the ticket type was given, add it
		if (isset($where['ticket_type_id'])) {
			if (is_array($where['ticket_type_id'])) $wheres[] = ' and ticket_type_id in ('.implode(',', $where['ticket_type_id']).')';
			else $wheres['ticket_type_id'] = $wpdb->prepare(' and ticket_type_id = %d', $where['ticket_type_id']);
		}
		// if a quantity was specified, add it
		if (isset($where['qty'])) {
			if (is_array($where['qty'])) $wheres[] = ' and qty in ('.implode(',', $where['qty']).')';
			else $wheres['qty'] = $wpdb->prepare(' and quantity = %d', $where['qty']);
		}
		// allow external plugins to modify this with their logic
		$wheres = apply_filters('qsot-zoner-update-reservation-wheres', $wheres, $set, $where);

		// generate the 'where statement' pieces for the sql query to perform the removal of existing records, prior to the actual update
		$set_wheres = array();
		// if the order id was given, add it
		if (isset($set['order_item_id'])) {
			if (is_array($set['order_item_id'])) $set_wheres[] = ' and order_item_id in('.implode(',', $set['order_item_id']).')';
			else $set_wheres['order_item_id'] = $wpdb->prepare(' and order_item_id = %d', $set['order_item_id']);
		}
		// if the order id was given, add it
		if (isset($set['order_id'])) {
			if (is_array($set['order_id'])) $set_wheres[] = ' and order_id in('.implode(',', $set['order_id']).')';
			else $set_wheres['order_id'] = $wpdb->prepare(' and order_id = %d', $set['order_id']);
		}
		// if the customer was specified, add it
		if (isset($set['customer_id'])) {
			if (is_array($set['customer_id'])) $set_wheres[] = ' and session_customer_id in(\''.implode('\',\'', $set['customer_id']).'\')';
			else $set_wheres['customer_id'] = $wpdb->prepare(' and session_customer_id = %s', $set['customer_id']);
		}
		// if a specific state (or group of states) was requested, add it
		if (isset($set['state']) && $set['state'] != '*') {
			if (is_array($set['state'])) $set_wheres[] = ' and state in (\''.implode('\',\'', $set['state']).'\')';
			else $set_wheres['state'] = $wpdb->prepare(' and state = %s', $set['state']);
		}
		// if the event id was given, add it
		if (isset($set['event_id'])) {
			if (is_array($set['event_id'])) $set_wheres[] = ' and event_id in ('.implode(',', $set['event_id']).')';
			else $set_wheres['event_id'] = $wpdb->prepare(' and event_id = %d', $set['event_id']);
		}
		// if the product_id of the ticket type was specified, add it
		if (isset($set['ticket_type_id'])) {
			if (is_array($set['ticket_type_id'])) $set_wheres[] = ' and ticket_type_id in ('.implode(',', $set['ticket_type_id']).')';
			else $set_wheres['ticket_type_id'] = $wpdb->prepare(' and ticket_type_id = %d', $set['ticket_type_id']);
		}

		// normalize the where statements for the update and deletion prior to update
		if (!empty($set_wheres)) {
			$set_wheres = wp_parse_args($set_wheres, $wheres);
			// we will be updating the quantity, so we c
			unset($set_wheres['qty']);
		}

		$limit = '';
		// if we are trying to delete the reservations, then start the update query as a delete statement
		if (isset($set['_delete']) || (isset($set['qty']) && $set['qty'] <= 0)) {
			$q = 'delete from '.$wpdb->qsot_event_zone_to_order.' where 1=1';
			// also update passed information for the action below
			$set['_qty'] = $set['qty'];
			$set['qty'] = 0;
			$is_delete = true;
		// otherwise this is an actual, genuine update to the reservations, so make the query an update query
		} else {
			$q = 'update '.$wpdb->qsot_event_zone_to_order.' set ';
			// create the update sql
			$pairs = array();
			if (isset($set['order_item_id'])) $pairs[] = $wpdb->prepare(' order_item_id = %d', $set['order_item_id']);
			if (isset($set['order_id'])) $pairs[] = $wpdb->prepare(' order_id = %d', $set['order_id']);
			if (isset($set['customer_id'])) $pairs[] = $wpdb->prepare(' session_customer_id = %s', $set['customer_id']);
			if (isset($set['state'])) $pairs[] = $wpdb->prepare(' state = %s', $set['state']);
			if (isset($set['event_id'])) $pairs[] = $wpdb->prepare(' event_id = %d', $set['event_id']);
			if (isset($set['ticket_type_id'])) $pairs[] = $wpdb->prepare(' ticket_type_id = %d', $set['ticket_type_id']);
			if (isset($set['qty'])) {
				if ($set['qty'] == '::DEC::') $pairs[] = ' quantity = quantity - 1 ';
				else if ($set['qty'] == '::INC::') $pairs[] = ' quantity = quantity + 1 ';
				else $pairs[] = $wpdb->prepare(' quantity = %d', $set['qty']);
			}
			// allow other plugins to add their own update stuff
			$pairs = apply_filters('qsot-zoner-update-reservation-sets', $pairs, $set, $where);
			// glue it all together
			$q .= implode(',', $pairs).' where 1=1';
			$limit = ' limit 1';
		}

		// if we actually have updates to make, because we actually have data to filter our updated set by (in other words, do not delete all records accidentally)
		if (is_array($wheres) && count($wheres)) { // safegaurd against deleting all records
			$set['qty'] = isset($set['qty']) ? $set['qty'] : 0;
			// pull out the existing reservations, and pass them on to external plugins, notifying them that they will be updated
			$wheres = array_values($wheres);
			$cur = $wpdb->get_results('select * from '.$wpdb->qsot_event_zone_to_order.' where 1=1'.implode('', $wheres));
			$total = 0;
			foreach ($cur as $item) $total += $item->quantity;
			$change = -$total + $set['qty'];

			// tell other plugins that we are updating these rows
			do_action('qsot-zoner-before-update-reservation', $change, $set['qty'], $cur, $where, $set);

			// if for some reason we have some duplicate entries with close to the same data represented as the data we are coming _from_, then delete all but one of them
			if (count($cur) > 1) {
				// remove any weird duplicate entries.
				$wpdb->query($wpdb->prepare('delete from '.$wpdb->qsot_event_zone_to_order.' where 1=1'.implode('', $wheres).' limit %d order by since desc', count($cur) - 1));
			}

			// if this is an update, remove any data that looks like the data we are going _to_
			/*@@@@LOUSHOU - removed because it causes problems. possible rethink needed
			if (!empty($set_wheres) && !$is_delete) {
				$set_wheres = array_values($set_wheres);
				//$wpdb->query('delete from '.$wpdb->qsot_event_zone_to_order.' where 1=1'.implode('', $set_wheres));
			}
			*/

			// glue the query
			$q .= implode('', $wheres).$limit;
			// actually run the update, be it delete or update
			$res = $wpdb->query($q);
			$success = $res !== false;
	
			// notify other plugins of our success
			if ($success)
				do_action('qsot-zoner-after-update-reservation', $change, $set['qty'], $where, $set);
		}

		return $success;
	}

	public static function setup_tables($tables) {
    global $wpdb;
    $tables[$wpdb->qsot_event_zone_to_order] = array(
      'version' => '0.1.5',
      'fields' => array(
				'event_id' => array('type' => 'bigint(20) unsigned'), // post of type qsot-event
				'order_id' => array('type' => 'bigint(20) unsigned'), // post of type shop_order (woocommerce)
				'quantity' => array('type' => 'smallint(5) unsigned'), // some zones can have more than 1 capacity, so we need a quantity to designate how many were purchased ina given zone
				'state' => array('type' => 'varchar(20)'), // word descriptor for the current state. core states are interest, reserve, confirm, occupied
				'since' => array('type' => 'timestamp', 'default' => 'CONST:|CURRENT_TIMESTAMP|'), // when the last action took place. used for lockout clearing
				'session_customer_id' => array('type' => 'varchar(150)'), // woo session id for linking a ticket to a user, before the order is actually created (like interest and reserve statuses)
				'ticket_type_id' => array('type' => 'bigint(20) unsigned', 'default' => '0'), // product_id of the woo product that represents the ticket that was purchased/reserved
				'order_item_id' => array('type' => 'bigint(20) unsigned', 'default' => '0'), // order_item_id of the order item that represents this ticket. present after order creation
      ),   
      'keys' => array(
        'KEY evt_id (event_id)',
        'KEY ord_id (order_id)',
        'KEY oiid (order_item_id)',
				'KEY stt (state)',
      )    
    );   

    return $tables;
	}
}

if (defined('ABSPATH') && function_exists('add_action')) {
	qsot_zoner::pre_init();
}

endif;
