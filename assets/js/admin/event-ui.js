var QS = QS || {};
QS.EventUI = (function($, EventUI_Callbacks, undefined) {
	var qt = QS.Tools;
	function NewEventDateTimeForm() {
		var t = this;

		if (!t.calendar) return;

		t.form = {};
		t.callback('add_repeat_functions');
		t.elements = t.elements || {};
		t.elements.form = t.elements.form || {};
		t.elements.form.main_form = $('.option-sub[rel=add]', t.elements.main || 'body');
		t.elements.form.add_btn = $('[rel=add-btn]', t.elements.form.main_form);
		t.elements.form.messages = $('[rel=messages]', t.elements.form.main_from);
		t.elements.form.start_date = $('input[name="start-date"]', t.elements.form.main_form);
		t.elements.form.end_date = $('input[name="end-date"]', t.elements.form.main_form);
		t.elements.form.starts_on = $('input[name="repeat-starts"]', t.elements.form.main_form);
		t.elements.form.ends_on = $('input[name="repeat-ends-on"]', t.elements.form.main_form);

		var current = undefined;
		$(window).on( 'scroll', function(e) {
			var last = (new Date()).getTime() + ' ' + (Math.random() * 10000);
			current = last;
			setTimeout( function() {
				if ( last != current ) return;
				var wintop = $( window ).scrollTop(), opt = $( '.option-sub[rel="settings"]', t.elements.main || 'body' ), opttop = opt.offset().top, opthei = opt.outerHeight(),
				    bulk = opt.find( '.bulk-edit-settings' ), bulkhei = bulk.outerHeight(), bump = 100;
				if ( wintop > opttop - bump && wintop < opttop + opthei - bulkhei - bump )
					bulk.finish().animate( { top:wintop - opttop + bump }, { duration:500 } );
				else if ( wintop < opttop - bump )
					bulk.finish().animate( { top:0 }, { duration:500 } );
				else if ( wintop > opttop + opthei - bulkhei - bump )
					bulk.finish().animate( { top:opthei - bulkhei }, { duration:500 } );
			}, 100 );
		} );

		t.elements.form.start_date.bind('change', function() {
			var val = $(this).val();
			var cur = new XDate(val);
			var end = new XDate(t.elements.form.end_date.val());
			var starton = new XDate(t.elements.form.starts_on.val());
			var endon = new XDate(t.elements.form.ends_on.val());

			if (cur.diffSeconds(end) < 0) t.elements.form.end_date.val(val);
			if (cur.diffSeconds(starton) < 0) t.elements.form.starts_on.val(val);
			if (cur.diffSeconds(endon) < 0) t.elements.form.ends_on.val(val);
		});

		if (typeof t.callback != 'function') {
			t.callback = function(name, params) {
				var params = params || [];
				var cbs = EventUI.callbacks.get(name);
				if (cbs instanceof Array) {
					for (var i=0; i<cbs.length; i++)
						cbs[i].apply(t, params);
				}
			};
		}

		t.elements.form.add_btn.click(function(e) {
			e.preventDefault();
			var data = t.elements.form.main_form.louSerialize();
			data['title'] = $('[name=post_title]').val();
			t.callback('form_add_btn_data', [data, t.elements.form.main_form, t.elements.form.add_btn]);
			t.form.processAddDateTimes(data);
		});

		t.form.processAddDateTimes = function(data) {
			if (typeof t.addEvents != 'function') return;

			var current_dt = new XDate();
			var data = $.extend({
				'start-time': '00:00:00',
				'start-date': current_dt.toString('yyyy-MM-dd'),
				'end-time': '23:59:59',
				'end-time': current_dt.toString('yyyy-MM-dd'),
			}, data);

			var base = {
				start: new XDate(data['start-date']+' '+data['start-time']).toDate(),
				end: new XDate(data['end-date']+' '+data['end-time']).toDate(),
				title: data['title'],
				allDay: false,
				editable: true,
				post_id: -1,
			};
			var events = [];

			//console.log('data', data, base);

			if (typeof data.repeat != 'undefined' && data.repeat) {
				var funcName = 'repeat'+QS.ucFirst(data.repeats);
				if (typeof t.form[funcName] == 'function') t.form[funcName](events, base, data);
				else t.callback(funcName, [events, base, data]);
			} else {
				events.push($.extend(true, {}, base, {
					single: 'yes',
					start: (new XDate(data['start-date']+' '+data['start-time'])).toDate(),
					end: (new XDate(data['end-date']+' '+data['end-time'])).toDate()
				}));
			}

			t.callback('process_add_date_time', [events, base, data]);
			var cnt = events.length;
			t.addEvents(events);

			var msg = $('<li><strong>Added</strong> [<strong>'+cnt+'</strong>] events to the calendar below.</li>').appendTo(t.elements.form.messages);
			t.callback('process_add_date_time_msgs', [msg]);
			msg.show().fadeOut({duration:3000, complete:function() { msg.remove(); } });
		};

		function evenDays(from, to) {
			var f = new XDate(from.getFullYear(), from.getMonth(), from.getDate());
			var o = new XDate(to.getFullYear(), to.getMonth(), to.getDate());
			return f.diffDays(o);
		};

		t.form.repeatWeekly = function(events, base, data) {
			var d = XDate(data['repeat-starts']);
			var st = new XDate(base['start']);
			var en = new XDate(base['end']);
			var cnt = 0;
			var inRange = function() { return false; };

			switch (data['repeat-ends-type']) {
				case 'on': inRange = (function() { var e = XDate(data['repeat-ends-on']); return function() { console.log('on', d.getTime(), e.getTime()); return d.getTime() <= e.getTime(); }; })(); break;
				case 'after': inRange = (function() { var e = data['repeat-ends-after']; return function() { console.log('after', cnt, e); return cnt < e; }; })(); break;
				default:
					// pass params as an object, so that the function inRange can be modified by callbacks, and then returned by reference. that way we can actually accept the changed function
					var pkg = {
						inRange:inRange,
						data:data
					};
					t.callback('repeat_ends_type', [pkg]);
					inRange = pkg.inRange;
				break;
			}

			function incWeeks() { d.addDays(-d.getDay()).addWeeks(data['repeat-every']); }
			function nextDay(day) {
				var c = d.getDay();
				if (day < c) return -1;
				d.addDays(day-c);
				return 1;
			}

			while (inRange()) {
				for (i in data['repeat-on']) {
					if (nextDay(data['repeat-on'][i]) < 0) continue; // initial run, in case first day is in middle of list. list m,tu,th,sa and first day is th
					if (!inRange()) break;
					var args = $.extend(true, {}, base);
					args['start'] = st.addDays(evenDays(st,d)).toDate();
					args['end'] = en.addDays(evenDays(en,d)).toDate();
					events.push(args);
					cnt++;
				}
				incWeeks();
			}
			console.log(events);

			return events;
		};

		t.form.repeatDaily = function(events, base, data) {
		};
	}

	function EventList() {
		var t = this;

		if (!t.calendar) return;
		if (!t.elements.event_list || t.elements.event_list.length == 0) return;
		console.log('adding event list handler');

		t.elements.bulk_edit = {
			settings_form: $('.bulk-edit-settings', t.elements.main)
		};
		t.event_list = {};
		t.last_clicked = undefined;
		t.selection = $();

		if (typeof t.callback != 'function') {
			t.callback = function(name, params) {
				var params = params || [];
				var cbs = EventUI.callbacks.get(name);
				if (cbs instanceof Array) {
					for (var i=0; i<cbs.length; i++)
						cbs[i].apply(t, params);
				}
			};
		}

		t.event_list.updateSettingsForm = function() {
			t.elements.bulk_edit.settings_form.unbind('updated.update-settings').bind('updated.update-settings', function(e, data) {
				var selected = t.event_list.getSelection();

				selected.each(function() {
					var ev = $(this).data('event');
					for (i in data) {
						if (data[i] !== '') {
							ev[i] = data[i];
						}
					}
				});

				t.calendar.fullCalendar('refetchEvents');
			}).trigger('clear');

			var selected = t.event_list.getSelection();

			function Multiple() {
				this.toString = function() { return ''; };
				this.toLabel = function() { return '(Multiple)'; };
			};

			if (selected.length) {
				var settings = {};

				selected.each(function() {
					var ev = $(this).data('event');
					if (typeof ev == 'object') {
						for (i in ev) {
							if (typeof settings[i] != 'undefined') {
								if (settings[i] != ev[i]) settings[i] = new Multiple();
							} else {
								settings[i] = typeof ev[i] != 'undefined' ? ev[i] : '';
							}
						}
					}
				});

				var i = undefined;
				for (i in settings) {
					var field = t.elements.bulk_edit.settings_form.find('[name="settings['+i+']"]');
					if (field.length > 0) {
						var setting_main = field.closest(field.attr('scope') || 'body');
						if (setting_main.length > 0) {
							var updateArgs = {};
							updateArgs[i] = settings[i];
							setting_main.qsEditSetting('update', settings, false);
							setting_main.qsEditSetting('update', updateArgs, true);
						}
					}
				}

				t.elements.bulk_edit.settings_form.show();
				t.callback('update_settings_form_show');
			} else {
				t.callback('update_settings_form_hide');
				$('[rel=value]', t.elements.bulk_edit.settings_form).val('');
				$('[rel=display]', t.elements.bulk_edit.settings_form).html('');
				t.elements.bulk_edit.settings_form.hide();
				$('[rel=form]', t.elements.bulk_edit.settings_form).hide();
				$('[rel=edit]', t.elements.bulk_edit.settings_form).show();
			}
		}

		t.event_list.getSelection = function() {
			return t.selection; //$('.event-date.selected', t.elements.event_list);
		};

		function highlight(e) {
			var self = $(this);

			if (e.shiftKey && t.last_clicked && t.last_clicked.length > 0 && !t.last_clicked.equals(self)) {
				var p = self.prevAll('.event-date');
				var pl = t.last_clicked.prevAll('.event-date');
				var list = $(self).add(t.last_clicked);
				if (p.length < pl.length) list = self.parent().find('.event-date').slice(p.length, pl.length+1);
				else list = self.parent().find('.event-date').slice(pl.length, p.length+1);
				t.selection = list;
			} else if (e.metaKey || e.ctrlKey) {
				if (t.selection.filter(function() { return $(this).equals(self); }).length > 0) {
					t.selection = t.selection.filter(function() { return !$(this).equals(self); });
				} else {
					t.selection = t.selection.add(self);
				}
			} else {
				if (t.last_clicked && t.last_clicked.length > 0 && t.last_clicked.equals(self) && t.selection.length == 1) {
					if (self.hasClass('selected')) { 
						t.selection = t.selection.filter(function() { return !$(this).equals(self); });
					} else {
						t.selection.add(self);
					}
				} else {
					t.selection = self;
					t.last_clicked = self;
				}
			}

			self.siblings('.event-date').andSelf().removeClass('selected');
			t.selection.addClass('selected');

			t.event_list.updateSettingsForm();
		};

		function remove(e) {
			e.preventDefault();
			var self = $(this);
			var scope = self.closest('[rel=item]');
			var ev = scope.data('event');
			scope.remove();
			t.removeEvents(ev);
		};

		t.event_list.add_item = function(ev) {
			var d = new XDate(ev.start);
			var extra = [];
			if (typeof ev.edit_link == 'string' && ev.edit_link.length) 
				extra.push('<div class="edit action"><a href="'+ev.edit_link+'" target="_blank" rel="edit" title="Edit Event">E</a></div>');
			if (typeof ev.view_link == 'string' && ev.view_link.length) 
				extra.push('<div class="view action"><a href="'+ev.view_link+'" target="_blank" rel="edit" title="View Event">V</a></div>');
			var ele = $('<div class="event-date" rel="item">'
					+'<div class="event-title">'
						+'<span>'+d.toString('hh:mmtt')+' on '+d.toString('ddd yyyy-MM-dd')+' ('+ev.title+')</span>'
						+'<div class="actions">'
							+extra.join('')
							+'<div class="remove action" rel="remove">X</div>'
						+'</div>'
					+'</div>'
				+'</div>').data('event', ev).click(highlight);
			ele.find('[rel=remove]').click(remove);
			t.callback('event_list_item', [ele]);
			if (ele.length)
				ele.appendTo(t.elements.event_list);
		};

	}

	function startEventUI(e, o) {
		var e = $(e);
		var exists = e.data('qsot-event-ui');
		var ret = undefined;

		if (exists instanceof EventUI && typeof exists.initialized == 'boolean' && exists.initialized) {
			exists.setOptions(o);
			ret = exists;
		} else {
			ret = new EventUI(e, o);
			e.data('qsot-event-ui', ret);
		}

		return ret;
	}

	function EventUI(e, o) {
		this.first = new XDate();
		this.setOptions(o);
		this.loadSettings();
		this.elements = {
			main:e,
			calendar:e.find('[rel=calendar]'),
			event_list:e.find('[rel=event-list]'),
			buttons:{}
		};
		this.init();
	};

	EventUI.prototype = {
		defs: {
			evBgColor:'#000000',
			evFgColor:'#ffffff'
		},
		fctm:'fc',
		calendar:undefined,
		events:[],
		first:false,
		initialized:false,

		init: function() {
			var self = this;
			//console.log(this, this.options);

			this.calendar = this.elements.calendar.fullCalendar({
				header: {
					left: 'title agendaWeek,month',
					center: '',
					right: 'today prev,next'
				},
				eventAfterRender: function(ev, element, view) { return self.calendarAfterRender(ev, element, view) },
				eventRender: function(ev, element, view) { var args = Array.prototype.slice.call(arguments); args.push(this); return self.eventRender.apply(self, args); },
				eventDrop: function(ev, day, min, allDay, revertFunc, jsEv, ui, view) { var args = Array.prototype.slice.call(arguments); args.push(this); return self.eventDrop.apply(self, args); },
				viewDisplay: function(view) { return self.addButtons(view); }
			});
			this.calendar.fullCalendar('gotoDate', this.first.toDate());

			// import
			NewEventDateTimeForm.call(this);
			EventList.call(this);

			this.calendar.closest('form').submit(function(e) {
				//e.preventDefault();
				return self.beforeFormSubmit($(this));
			});

			this.calendar.fullCalendar('addEventSource', {
				events:this.events,
				color:this.options.evBgColor,
				textColor:this.options.evFgColor
			});
			this.updateEventList();

			this.callback('init');
			this.initialized = true;
		},

		eventRender: function(ev, element, view, that) {
			var self = this;
			function _toNum(data) { var d = parseInt(data); return isNaN(d) ? 0 : d; };

			var tmpl = this.template(['render_event_'+view.name, 'render_event']);
			//console.log('rendering event on view', view.name, view, tmpl);

			if (tmpl) {
				if (typeof tmpl == 'function') tmpl = tmpl();
				else tmpl = $(tmpl);
				tmpl.find('.'+self.fctm+'-event-time').html(element.find('.'+self.fctm+'-event-time').html());
				tmpl.find('.'+self.fctm+'-event-title').html(element.find('.'+self.fctm+'-event-title').html());
				tmpl.find('.'+self.fctm+'-capacity').html('('+_toNum(ev.capacity)+')');
				tmpl.find('.'+self.fctm+'-visibility').html('['+QS.ucFirst(ev.visibility)+']');
				tmpl.find('[rel=remove]').click(function() { self.removeEvents([ev]); });
				element.empty();
				tmpl.appendTo(element);
			} else {
				$('<span class="'+self.fctm+'-capacity"> ('+_toNum(ev.capacity)+') </span>')
					.insertBefore(element.find('.'+self.fctm+'-event-title'));
			}

			this.callback('render_event', [ev, element, view, that]);
		},

		eventDrop: function(ev, day, min, allDay, revertFunc, jsEv, ui, view, that) {
			this.updateEventList();
			this.callback('drop_event', [ev, day, min, allDay, revertFunc, jsEv, ui, view, that]);
		},

		addEvents: function(events) {
			if (events instanceof Array) {
				for (var i=0; i<events.length; i++) {
					this.addEvent(events[i]);
				}
				this.updateEventList();
			} else if (typeof events == 'object' && typeof events._id == 'string') {
				this.addEvent(events);
				this.updateEventList();
			}
			this.callback('add_events', [events]);
		},

		addEvent: function(title, start, extra) {
			var args = {};
			var obj = {};

			if (typeof title == 'object') {
				obj = $.extend({}, title);
				args = $.extend({
					visibility:'pending',
					capacity:0,
					post_id:-1
				}, obj);
			} else {
				var extra = extra || {allDay:false};
				if (!(start instanceof Date || start instanceof XDate)) return;
				if (typeof title != 'string') return;
				obj = $.extend({}, {
					title:title,
					start:start instanceof XDate ? start.toDate() : start
				}, extra);
				args = $.extend({
					visibility:'pending',
					capacity:0,
					post_id:-1
				}, obj);
			}
			this.callback('add_event', [args, obj]);

			this.events.push(args);
		},

		removeEvents: function(events) {
			this.callback('delete_events', [events]); // be smart... call before we delete the events from the list
			if (events instanceof Array) {
				for (var i=0; i<events.length; i++) this.removeEvent(events[i]);
				this.updateEventList();
			} else if (typeof events == 'object' && typeof events._id == 'string') {
				this.removeEvent(events);
				this.updateEventList();
			}
		},

		removeEvent: function(ev) {
			var to_remove = ev.length;

			// this may seem dumb, and convoluted, but trust me, it is not. we have to use the EXACT same array here, otherwise the list used by the calendar does not update.
			// this means that you cannot use methods that create a NEW array, such as Array.filter or Array.slice/splice or some function that creates a new array outright.
			// the alternative to this is to manually update the array used by the 'event source' that the calendar uses, and that is far less future proof.

			// track the number of items that have been removed
			var removed = 0;

			// cycle through all array items in the events array
			for (var i=0; i<this.events.length; i++) {
				// if the current array item (event) matches the event we are trying to remove
				if (this.events[i]._id == ev._id) {
					// remove this item
					delete this.events[i];
					// add to the count of removed items
					removed++;
				// if we are not removing the current item, we need to shift this item up the array by the number of items we have removed thus far
				} else {
					// move the item up
					this.events[i-removed] = this.events[i];
				}
			}

			// now we know how many items we have removed total. now we need to trim the end of the array by that many items. this will remove any duplicates that this
			// shifting process has created as well as any undefined values
			for (var i=0; i<removed; i++)
				this.events.pop();

			var exists = $('[rel="items-removed"]', this.elements.main);
			if (exists.length == 0) $('<input type="hidden" rel="items-removed" name="events-removed" value="1" />').appendTo(this.elements.main);
		},

		callback: function(name, params) {
			var params = params || [];
			var cbs = EventUI.callbacks.get(name);
			if (cbs instanceof Array) {
				for (var i=0; i<cbs.length; i++)
					cbs[i].apply(this, params);
			}
		},

		template: function(names) {
			var template = '';

			if (typeof names == 'string') names = [names];
			if (!(names instanceof Array)) return template;

			for (var i=0; i<names.length; i++) {
				if ($.inArray(typeof this.templates[names[i]], ['string', 'object', 'function']) != -1) {
					template = this.templates[names[i]];
					break;
				}
			}

			return template;
		},

		setOptions: function(o) {
			this.options = $.extend({}, this.defs, o, {author:'loushou', version:'0.1-beta'});
			this.callback('set_options', [o]);
		},

		loadSettings: function() {
			if (qt.isO(_qsot_settings)) {
				if (qt.isO(_qsot_settings.events)) {
					this.events = _qsot_settings.events;
				}
				if (qt.isO(_qsot_settings.templates)) {
					this.templates = _qsot_settings.templates;
				}
				this.first = typeof _qsot_settings.first == 'string' && _qsot_settings.first != '' ? new XDate(_qsot_settings.first) : new XDate();
			}
			this.callback('load_settings');
		},

		updateEventList: function() {
			this.calendar.fullCalendar('refetchEvents');
			var events = this.calendar.fullCalendar('clientEvents');

			//console.log('update', events, this.events);
			if (this.elements.event_list.length) {
				this.elements.event_list.empty();
				events.sort(function(a, b) {
					var at = a.start.getTime();
					var bt = b.start.getTime();
					return at < bt ? -1 : ( at == bt ? 0 : 1 );
				});
				for (i in events) {
					if (typeof this.event_list == 'object' && typeof this.event_list.add_item == 'function') this.event_list.add_item(events[i]);
				}
				this.last_clicked = undefined;
				if (typeof this.event_list == 'object' && typeof this.event_list.updateSettingsForm == 'function') this.event_list.updateSettingsForm();
				this.callback('update_event_list');
			}
		},

		calendarAfterRender: function(ev, element, view) {
			element = $(element);
			//console.log('after render', ev, element, view);
		},

		addButtons: function(view) {
			var tm = this.fctm;
			this.elements.header_center = view.element.closest('.'+tm).find('.'+tm+'-header-center');
			this.addButton('new_event_btn', 'New Event Date', ['togvis'], {tar:'.option-sub[rel=add]', scope:'.events-ui'});
			this.callback('add_buttons');
		},

		addButton: function(name, label, classes, attr) {
			if (typeof this.elements.buttons[name] == 'undefined' || this.elements.buttons[name] == null) {
				var tm = this.fctm;
				var attr = attr || {};
				var classes = classes || '';
				classes = typeof classes == 'object' ? classes.join(' ') : '';
				this.elements.buttons[name] = $('<span class="'+tm+'-button '+tm+'-button-'+name+' '+tm+'-state-default '+tm+'-corner-left '+tm+'-corner-right '+classes+'">'
						+'<span class="'+tm+'-button-inner">'
							+'<span class="'+tm+'-button-content">'+label+'</span>'
							+'<span class="'+tm+'-button-effect"><span></span></span>'
						+'</span>'
					+'</span>').attr(attr).appendTo(this.elements.header_center).hover(
						function() { $(this).not('.' + tm + '-state-active').not('.' + tm + '-state-disabled').addClass(tm + '-state-hover'); },
						function() { $(this).removeClass(tm + '-state-hover').removeClass(tm + '-state-down'); }
					).click(function() {
						var self = $(this);
						if (self.hasClass(tm + '-state-active')) self.removeClass(tm + '-state-active');
						else self.addClass(tm + '-state-active');
					});
			}
			return this.elements.buttons[name];
		},

		beforeFormSubmit: function(form) {
			var events = this.calendar.fullCalendar('clientEvents');

			for (i in events) {
				var ev = $.extend({
					post_id:-1,
					status:'pending',
					visibility:'public',
					password:'',
					pub_date:'',
					capacity:0
				}, {
					_id: events[i]._id,
					start: (new XDate(events[i].start)).toString('yyyy-MM-dd HH:mm:ss'),
					end: events[i].end instanceof Date || events[i].end instanceof XDate ? (new XDate(events[i].end)).toString('yyyy-MM-dd HH:mm:ss') : events[i].end,
					title: events[i].title,
					post_id: events[i].post_id,
					status: events[i].status,
					visibility: events[i].visibility,
					password: events[i].password,
					pub_date: events[i].pub_date,
					capacity: events[i].capacity
				});
				this.callback('before_submit_event_item', [ev, events[i]]);
				var txt = JSON.stringify(ev);
				$('<input type="hidden" name="_event_settings['+i+']" value=""/>').val(txt).appendTo(form);
			}

			//return false;
		}
	};

	$.fn.qsEventUI = function(o) { return this.each(function() { return startEventUI($(this), o); }); };

	EventUI.callbacks = new EventUI_Callbacks();

	return EventUI;
})(jQuery, QS.EventUI_Callbacks);
