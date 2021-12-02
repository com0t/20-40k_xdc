<?php
/*
Plugin Name: XO Featured Image Tools
Plugin URI: https://xakuro.com/wordpress/
Description: Automatically generates the featured image from the image of the post.
Author: Xakuro
Author URI: https://xakuro.com/
License: GPLv2
Version: 1.6.1
Text Domain: xo-featured-image-tools
Domain Path: /languages/
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'XO_FEATURED_IMAGE_TOOLS_VERSION', '1.6.1' );

load_plugin_textdomain( 'xo-featured-image-tools' );

class XO_Featured_Image_Tools {
	public $admin;

	/**
	 * Construction.
	 */
	public function __construct() {
		require_once( plugin_dir_path( __FILE__ ) . 'admin.php' );
		$admin = new XO_Featured_Image_Tools_Admin( $this );
	}

	/**
	 * Gets the default value of the option.
	 *
	 * @since 0.3.0
	 */
	public static function get_default_options() {
		return array(
			'list_posts' => array( 'post', 'page' ),
			'auto_save_posts' => array( 'post', 'page' ),
		);
	}

	/**
	 * Plugin activation.
	 *
	 * @since 0.3.0
	 */
	public static function activation() {
		$options = get_option( 'xo_featured_image_tools_options' );
		if ( $options === false ) {
			add_option( 'xo_featured_image_tools_options', XO_Featured_Image_Tools::get_default_options() );
		}
	}

	/**
	 * Plugin deactivation.
	 *
	 * @since 0.3.0
	 */
	public static function uninstall() {
		delete_option( 'xo_featured_image_tools_options' );
	}
}

global $xo_featured_image_tools;
$xo_featured_image_tools = new XO_Featured_Image_Tools();

register_activation_hook( __FILE__, 'XO_Featured_Image_Tools::activation' );
register_uninstall_hook( __FILE__, 'XO_Featured_Image_Tools::uninstall' );
