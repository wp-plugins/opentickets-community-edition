<?php get_header(); ?>
<style>
	.message-wrapper { margin-bottom:20px; }
</style>
<div id="primary" class="content-area">
	<div id="content" class="site-content" role="main">
		<div class="message-wrapper">
			<h3>An error has occurred while attempting to view the ticket</h3>
			<p><?php echo $msg ?></p>
		</div>
	</div>
</div>
<?php get_footer();
