<?php if (isset($tickets) && is_array($tickets) && !empty($tickets)): ?>
	<h2><?php echo apply_filters('qsot_my_account_my_upcoming_tickets_title', __('Upcoming Tickets', 'qsot')); ?></h2>

	<?php if ($display_format == 'as_list'): ?>

		<ul class="ticket-list">
			<?php foreach ($tickets as $ticket): ?>
				<?php
					$name = sprintf(
						'%s @ %s',
						$ticket->product->post->post_title,
						money_format('%.2n', $ticket->_line_subtotal)
					);
				?>
				<li>
					<?php if ( isset( $ticket->permalink ) && $ticket->permalink ): ?>
						<a href="<?php echo esc_attr($ticket->permalink) ?>" title="<?php echo esc_attr( __( 'View your ticket', 'qsot' ) ) ?>"><?php echo $name ?></a>
					<?php else: ?>
						<?php echo $name . ' (' . __( 'pending payment', 'qsot' ) . ')' ?>
					<?php endif; ?>
					<?php echo sprintf(
						__( 'for <a href="%s" title="%s">%s</a>' ),
						esc_attr( get_permalink( $ticket->event->ID ) ),
						esc_attr( __( 'Visit the Event page', 'qsot' ) ),
						apply_filters( 'the_title', $ticket->event->post_title )
					) ?>
					<?php if (is_admin() && isset($ticket->__order_id) && !empty($ticket->__order_id)): ?>
						<?php echo sprintf(
							__( '(order <a href="%s" title="%s">#%s<a/>)' ),
							esc_attr( get_edit_post_link( $ticket->__order_id ) ),
							esc_attr( __( 'Edit order', 'qsot' ) ) . ' #' . $ticket->__order_id,
							$ticket->__order_id
						) ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

	<?php else: ?>

		<table class="event-item section shop_table my_account_upcoming_tickets">
			<?php foreach ($by_event as $event): ?>
				<thead>
					<tr>
            <?php $ticket = current(array_values($event->tickets)) ?>
            <th colspan="<?php echo is_admin() && isset($ticket->__order_id) && !empty($ticket->__order_id) ? '3' : '2' ?>"
                is_admin="<?php echo is_admin() ? 'yes' : 'no' ?>"
                order_id="<?php echo isset($ticket->__order_id) && !empty($ticket->__order_id) ? $ticket->__order_id : '0' ?>">
							<span class="nobr">
								<?php echo apply_filters('the_title', $event->post_title); ?>
								<?php echo sprintf(
									__( '<a href="%s" title="%s">%s</a>' ),
									esc_attr( get_permalink( $event->ID ) ),
									__( 'Visit the Event page', 'qsot' ),
									__( '(View Show Page)', 'qsot' )
								) ?>
							</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($event->tickets as $ticket): ?>
						<?php
							$name = sprintf(
								'%s @ %s',
								$ticket->product->get_title(),
								money_format('%.2n', $ticket->_line_subtotal)
							);
						?>
						<tr>
							<td>
								<?php if ( isset( $ticket->permalink ) && $ticket->permalink ): ?>
									<a href="<?php echo esc_attr($ticket->permalink) ?>" title="View your ticket"><?php echo $name ?></a>
								<?php else: ?>
									<?php echo $name . ' (' . __( 'pending payment', 'qsot' ) . ')' ?>
								<?php endif; ?>
							</td>
							<td> x <?php echo $ticket->_qty ?></td>
							<?php if (is_admin() && isset($ticket->__order_id) && !empty($ticket->__order_id)): ?>
								<td>
									<?php echo sprintf(
										__( '(order <a href="%s" title="%s">#%s<a/>)' ),
										esc_attr( get_edit_post_link( $ticket->__order_id ) ),
										esc_attr( __( 'Edit order', 'qsot' ) ) . ' #' . $ticket->__order_id,
										$ticket->__order_id
									) ?>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			<?php endforeach; ?>
		</table>

	<?php endif; ?>

<?php endif; ?>
