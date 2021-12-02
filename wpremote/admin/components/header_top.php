<?php
	$plugin_slug = "wpremote";
	$plugin_logo = plugins_url("/../../img/wprlogo.svg", __FILE__);
	$title = "Manage hundreds of WordPress websites with half the effort";
	$header_logo_link = $this->getWebPage() . "/?utm_source=mc_plugin_lp_logo&utm_medium=logo_link&utm_campaign=mc_plugin_lp_header&utm_term=header_logo&utm_content=image_link";
?>
<div class="header-top">
	<div class="top-links">
		<span><a href="https://wordpress.org/support/plugin/<?php echo $plugin_slug; ?>/reviews/#new-post" target="_blank" rel="noopener noreferrer">
			Leave a Review
		</a></span>
		&nbsp;
		<span><a href="https://wordpress.org/support/plugin/<?php echo $plugin_slug; ?>/" target="_blank" rel="noopener noreferrer">
			Need Help?
		</a></span>
	</div>
	<div class="logo-img">
		<a href="<?php echo $header_logo_link; ?>" target="_blank" rel="noopener noreferrer">
			<img height="65" src="<?php echo $plugin_logo; ?>" alt="Logo">
		</a>
	</div>
	<h2 class="text-center heading"><?php echo $title; ?></h2>
</div>