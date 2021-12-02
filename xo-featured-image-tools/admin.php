<?php

class XO_Featured_Image_Tools_Admin {
	private $parent;
	private $tools_page_id;
	private $settings_page_id;
	private $options;

	/**
	 * Construction.
	 */
	public function __construct( $parent ) {
		$this->parent = $parent;
		$this->options = get_option( 'xo_featured_image_tools_options' );
		if ( $this->options === false ) {
			$this->options = $this->parent->get_default_options();
		}
		add_action( 'plugins_loaded', array( $this, 'setup' ) );
	}

	/**
	 * Set up processing in the administration panel.
	 */
	function setup() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'wp_ajax_xo_featured_image_tools', array( $this, 'ajax_featured_image' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Post List
		if ( isset( $this->options['list_posts'] ) ) {
			foreach ( $this->options['list_posts'] as $post_type ) {
				add_filter( "manage_edit-{$post_type}_columns", array( $this, 'add_columns' ), 1000 );
				add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'custom_columns' ), 10, 2 );
			}
		}
		add_action( 'restrict_manage_posts', array( $this, 'restrict_manage_posts' ) );
		add_filter( 'parse_query', array( $this, 'parse_query' ) );
	}

	/**
	 * Add a menu to the administration panel.
	 */
	function admin_menu() {
		// Tools menu
		$this->tools_page_id = add_management_page(
			__( 'Featured Image Tools', 'xo-featured-image-tools' ),
			__( 'Featured Image Tools', 'xo-featured-image-tools' ),
			'manage_options',
			'xo-featured-image-tools',
			array( $this, 'tools_page' )
		);
		// Options menu
		$this->settings_page_id = add_options_page( 'XO Featured Image', 'XO Featured Image', 'manage_options', 'xo_featured_image', array( $this, 'settings_page' ) );
	}

	/**
	 * Enqueue styles and scripts in the administration panel.
	 */
	function admin_enqueue_scripts( $hook_suffix ) {
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		if ( $hook_suffix == $this->tools_page_id ) {
			wp_enqueue_style( 'xo-featured-image-tools', plugins_url( '/admin-tools.css', __FILE__ ), false, XO_FEATURED_IMAGE_TOOLS_VERSION );
			wp_enqueue_script( 'jquery-ui-progressbar' );
			wp_enqueue_script( 'xo-featured-image-tools', plugins_url( "/admin-tools{$min}.js", __FILE__ ), array( 'jquery-ui-progressbar' ), XO_FEATURED_IMAGE_TOOLS_VERSION, false );
		} elseif ( $hook_suffix == $this->settings_page_id ) {
			wp_enqueue_media();
			wp_enqueue_style( 'xo-featured-image-options', plugins_url( '/admin-options.css', __FILE__ ), false, XO_FEATURED_IMAGE_TOOLS_VERSION );
			wp_enqueue_script( 'xo-featured-image-options', plugins_url( "/admin-options{$min}.js", __FILE__ ), array( 'jquery' ), XO_FEATURED_IMAGE_TOOLS_VERSION, false );
		} elseif ( $hook_suffix == 'edit.php' ) {
			wp_enqueue_style( 'xo-featured-image-edit-list', plugins_url( '/admin-edit-list.css', __FILE__ ), false, XO_FEATURED_IMAGE_TOOLS_VERSION );
		}
	}

	/**
	 * Get attachment file from GUID.
	 *
	 * @since 1.1.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @param string $guid GUID.
	 * @return int Attachment post ID, 0 on failure.
	 */
	function get_attachment_id_by_guid( $guid ) {
		global $wpdb;

		$attachment_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM $wpdb->posts WHERE post_type='attachment' AND guid=%s LIMIT 1;",
			$guid
		) );
		return (int)$attachment_id;
	}

	/**
	 * Download the image of the specified URL and register it in the media library.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url
	 * @param int $post_id Post ID.
	 * @param int $timeout The timeout for the request to download the file default 10 seconds
	 * @return int|WP_Error ID of the attachment or a WP_Error object on failure.
	 */
	function insert_attachment_from_url( $url, $post_id = 0, $timeout = 10 ) {
		if ( !function_exists( 'media_handle_upload' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/image.php' );
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
			require_once( ABSPATH . 'wp-admin/includes/media.php' );
		}

		$tmp_file = download_url( $url, $timeout );
		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		//if ( !exif_imagetype( $temp_file ) ) {
		//	return new WP_Error( 'invalid_image', __( 'Not an image', 'xo-featured-image-tools' ) );
		//}

		$file = array(
			'name' => basename( $url ),
			'tmp_name' => $tmp_file,
		);
		$attachment_id = media_handle_sideload( $file, $post_id, null, array( 'guid' => $url ) );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_file );
		}

		return $attachment_id;
	}

	/**
	 * Set the featured image in AJAX.
	 *
	 * @since 0.1.0
	 */
	public function ajax_featured_image() {
		if ( ! current_user_can( 'manage_options' ) ) {
			die( '-1' );
		}

		set_time_limit( 600 );
		error_reporting( 0 );
		header( 'Content-type: application/json' );

		$post_id = (int)$_REQUEST['id'];
		$external_image = ( $_REQUEST['external_image'] == 'true' );
		$exclude_small_image = ( $_REQUEST['exclude_small_image'] == 'true' );
		$exclude_small_image_size = isset( $this->options['exclude_small_image_size'] ) ? (int) $this->options['exclude_small_image_size'] : 0;
		$default_image = (int)$_REQUEST['default_image'];

		try {
			$attachment_id = get_post_thumbnail_id( $post_id );
			if ( $attachment_id === false ) {
				die( json_encode( array( 'error' => 'Failed post.' ) ) );
			} elseif ( !empty( $attachment_id ) ) {
				die( json_encode( array( 'success' => 'Already set.' ) ) );
			} else {
				$post = get_post( $post_id );
				if ( get_post_meta( $post_id, 'disable_featured_image', true ) ) {
					die( json_encode( array( 'success' => 'Skipped.' ) ) );
				} else {
					$title = isset( $post->post_title ) ? esc_html( $post->post_title ) : '';
					$content = $post->post_content;

					$matches = array();
					preg_match_all( '/<\s*img .*?src\s*=\s*[\"|\'](.*?)[\"|\'].*?>/i', $content, $matches );
					foreach ( $matches[0] as $key => $img ) {
						$url = $matches[1][$key];

						// Exclusion by extension
						//$ext = substr( $matches[1][$key], strrpos( $matches[1][$key], '.' ) + 1 );
						//if ( in_array( $ext, array( 'gif' ), true ) ) {
						//	continue;
						//}

						// Get the ID from the wp-image-{$id} class.
						$class_matches = array();
						if ( preg_match( '/class\s*=\s*[\"|\'].*?wp-image-([0-9]*).*?[\"|\']/i', $img, $class_matches ) ) {
							$attachment_id = $class_matches[1];
						}

						// Get ID from URL.
						if ( ! $attachment_id ) {
							$attachment_id = $this->get_attachment_id_by_url( $url );
							if ( ! $attachment_id ) {
								$attachment_id = $this->get_attachment_id_by_url( $url, false );
							}
						}

						// Get ID from GUID URL.
						if ( ! $attachment_id ) {
							$attachment_id = $this->get_attachment_id_by_guid( $url );
						}

						if ( $attachment_id ) {
							// Size check.
							if ( $exclude_small_image ) {
								$meta_data = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
								if ( ! is_array( $meta_data ) || ! isset( $meta_data['height'] ) || ! isset( $meta_data['width'] ) ||
									$meta_data['height'] <= $exclude_small_image_size ||
									$meta_data['width'] <= $exclude_small_image_size
								) {
									continue;
								}
							}
						} else {
							// Get external image.
							if ( $external_image ) {
								// Size check.
								if ( $exclude_small_image ) {
									$size = @getimagesize( $url );
									if ( ! $size || $size[0] <= $exclude_small_image_size || $size[1] <= $exclude_small_image_size ) {
										continue;
									}
								}

								$attachment_id = $this->insert_attachment_from_url( $url, $post_id );
								if ( is_wp_error( $attachment_id ) ) {
									die( json_encode( array( 'error' => sprintf( __( '"%s" (ID %d) failed.', 'xo-featured-image-tools' ), $title, $post->ID ) ) ) );
									exit;
								}
							}
						}

						if ( $attachment_id ) {
							if ( update_post_meta( $post_id, '_thumbnail_id', $attachment_id ) ) {
								die( json_encode( array( 'success' => 'Set image.' ) ) );
							} else {
								die( json_encode( array( 'error' => sprintf( __( '"%s" (ID %d) failed.', 'xo-featured-image-tools' ), $title, $post->ID ) ) ) );
							}
							break;
						}
					}

					// Gallery
					if ( ! $attachment_id ) {
						$matches = array();
						if ( preg_match( '/\[\s*gallery\s.*ids\s*=\s*[\"|\']([0-9\s]+).*?[\"|\'].*?\]/i', $content, $matches ) ) {
							$attachment_id = $matches[1];
							if ( update_post_meta( $post_id, '_thumbnail_id', $attachment_id ) ) {
								die( json_encode( array( 'success' => 'Set image.' ) ) );
							} else {
								die( json_encode( array( 'error' => sprintf( __( '"%s" (ID %d) failed.', 'xo-featured-image-tools' ), $title, $post->ID ) ) ) );
							}
						}
					}

					if ( ! $attachment_id ) {
						if ( $default_image ) {
							if ( update_post_meta( $post_id, '_thumbnail_id', $default_image ) ) {
								die( json_encode( array( 'success' => 'Set image.' ) ) );
							} else {
								die( json_encode( array( 'error' => sprintf( __( '"%s" (ID %d) failed.', 'xo-featured-image-tools' ), $title, $post->ID ) ) ) );
							}
						} else {
							die( json_encode( array( 'success' => 'No image.' ) ) );
						}
					}
				}
			}
		} catch ( Exception $e ) {
			die( json_encode( array( 'error' => $e->getMessage() ) ) );
		}
		exit;
	}

	/**
	 * Output Featured Image Tools page.
	 *
	 * @since 0.1.0
	 */
	function tools_page() {
		global $wpdb;

		$default_image = isset( $this->options['default_image'] ) ? (int)$this->options['default_image'] : 0;

		echo '<div id="message" class="updated fade" style="display:none;"></div>';
		echo '<div class="wrap xo-featured-image-tools">';
		echo '<h1>' . __( 'Featured Image Tools', 'xo-featured-image-tools' ) . '</h1>';
		if ( !empty( $_POST['xo-featured-image-tools'] ) || !empty( $_REQUEST['featured-image-post-type'] ) ) {
			check_admin_referer( 'xo-featured-image-tools' );

			$post_type = sanitize_text_field( $_REQUEST['featured-image-post-type'] );
			$post_type_object = get_post_type_object( $post_type );

			$sql = 
				"SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status='publish' " .
				"AND not exists (SELECT post_id FROM {$wpdb->postmeta} WHERE {$wpdb->postmeta}.meta_key = '_thumbnail_id' AND {$wpdb->postmeta}.post_id = {$wpdb->posts}.id ) " .
				"ORDER BY ID DESC";

			$posts = $wpdb->get_results( $wpdb->prepare( $sql, $post_type ) );
			if ( !$posts || count( $posts ) === 0 ) {
				echo '<p>' . sprintf( __( '%s was not found.', 'xo-featured-image-tools' ), $post_type_object->label ) . '</p>';
				echo '</div>';
				return;
			}
			$post_ids = array();
			foreach ( $posts as $post ) {
				$post_ids[] = $post->ID;
			}

			$external_image = ( ! empty( $_REQUEST['featured-image-external-image'] ) );
			$exclude_small_image = ( ! empty( $_REQUEST['featured-image-exclude-small-image'] ) );
			$default_image = ( ! empty( $_REQUEST['featured-image-default-image'] )  ) ? $default_image : 0;
			$post_count = count( $post_ids );
			$xo_featured_image_tools_values = array(
				'post_ids' => $post_ids,
				'post_count' => $post_count,
				'external_image' => $external_image,
				'exclude_small_image' => $exclude_small_image,
				'default_image' => $default_image,
				'stop_button_message' => __( 'Abort...', 'xo-featured-image-tools' ),
				'success_message' => __( 'Completed. There is no failure.', 'xo-featured-image-tools' ),
				'failure_message' =>  __( 'Completed. %s failed.', 'xo-featured-image-tools' )
			);

			echo '<p><span id="xo-featured-image-message">' . __( 'It may take some time. Please do not move from this page until it is completed.', 'xo-featured-image-tools' ) . '</span></p>';
			echo '<div id="xo-featured-image-bar" style="position:relative; height:25px;">';
			echo '<div id="xo-featured-image-bar-percent" style="position:absolute; left:50%;top:50%; width:300px; margin-left:-150px; height:25px; margin-top:-9px; font-weight:bold; text-align:center;"></div>';
			echo '</div>';
			echo '<p><input type="button" class="button hide-if-no-js" name="xo-featured-image-stop-bottun" id="xo-featured-image-stop-bottun" value="' . __( 'Stop', 'xo-featured-image-tools' ) . '" /></p>';
			echo '<h3 class="title">' . __( 'Status', 'xo-featured-image-tools' ) . '</h3>';
			echo '<p>' . sprintf( __( 'Post Type: %s', 'xo-featured-image-tools' ), esc_html( $post_type_object->label ) ) . '</p>';
			echo '<p>' . sprintf( __( 'Total: %s', 'xo-featured-image-tools' ), $post_count ) . '</p>';
			echo '<p>' . sprintf( __( 'Success: %s', 'xo-featured-image-tools' ), '<span id="xo-featured-image-success-count">0</span>' ) . '</p>';
			echo '<p>' . sprintf( __( 'Failure: %s', 'xo-featured-image-tools' ), '<span id="xo-featured-image-error-count">0</span>' ) . '</p>';
			echo '<ol id="xo-featured-image-msg"></ol>';

			echo '<script type="text/javascript">';
			echo 'new XOFieaturedImageTool(' . json_encode( $xo_featured_image_tools_values, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ');';
			echo '</script>' . "\n";
		} else {
			echo '<form method="post" action="">';
			wp_nonce_field( 'xo-featured-image-tools' );

			echo '<p>' . __( 'Generate the featured image collectively.', 'xo-featured-image-tools' );
			echo '<p>' . __( 'Post type: ', 'xo-featured-image-tools' );
			$post_types = get_post_types( array(), 'objects' );
			echo '<select id="featured-image-post-type" name="featured-image-post-type">';
			foreach ( $post_types as $post_type ) {
				if ( post_type_supports( $post_type->name, 'thumbnail' ) ) {
					echo '<option value="' . $post_type->name . '">' . $post_type->label . '</option >';
				}
			}
			echo '</select>';
			echo '</p>';

			$external_image = isset( $this->options['external_image'] ) ? $this->options['external_image'] : false;
			echo '<p><label><input id="featured-image-external-image" name="featured-image-external-image" type="checkbox" value="1" ' . checked( 1, $external_image, false ) . '> ' . __( 'Also applies to external images (images other than attachment files)', 'xo-featured-image-tools' ) . '</label></p>';

			$exclude_small_image = isset( $this->options['exclude_small_image'] ) ? $this->options['exclude_small_image'] : false;
			echo '<p><label><input id="featured-image-exclude-small-image" name="featured-image-exclude-small-image" type="checkbox" value="1" ' . checked( 1, $exclude_small_image, false ) . '> ' . __( 'Exclude small image', 'xo-featured-image-tools' ) . '</label></p>';

			if ( $default_image ) {
				echo '<p><label><input id="featured-image-default-image" name="featured-image-default-image" type="checkbox" value="1" checked="checked"> ' . __( 'Use the default image', 'xo-featured-image-tools' ) . '</label></p>';
			} else {
				_e( 'To set the default image, specify the default image from the setting page.', 'xo-featured-image-tools' );
			}

			echo '<p><input type="submit" class="button button-primary hide-if-no-js" name="xo-featured-image-tools" id="xo-featured-image-tools" value="' . __( 'Generate featured image', 'xo-featured-image-tools' ) . '" /></p>';

			echo '</form>';
		}
		echo '</div>' . "\n";
	}

	/**
	 * Acquire the ID from the URL of the attachment file.
	 *
	 * @since 0.2.0
	 *
	 * @global wpdb $wpdb
	 * @param string $url Attachment file URL.
	 * @param bool $is_full_size option. A value indicating whether it is full size. True for full size, false for different size. The default is true.
	 * @return int It returns ID (1 or more) if it succeeds, 0 if it does not exist.
	 */
	public function get_attachment_id_by_url( $url, $is_full_size = true ) {
		global $wpdb;

		$attachment_id = 0;

		// If it is a relative URL, convert it to an absolute URL.
		$parse_url = parse_url( $url );
		if ( ! isset( $parse_url['host'] ) ) {
			if ( isset( $_SERVER['SERVER_NAME'] ) ) {
				$host = set_url_scheme( '//' . strtolower( $_SERVER['SERVER_NAME'] ) );
				$url = rtrim( $host, '/' ) . '/' . ltrim( $parse_url['path'], '/' );
			}
		}

		$full_size_url = $url;

		if ( ! $is_full_size ) {
			// Remove the size notation (-999x999) from the URL to get the full-size URL.
			$full_size_url = preg_replace( '/(-[0-9]+x[0-9]+)(\.[^.]+){0,1}$/i', '${2}', $url );
			if ( $url === $full_size_url ) {
				// Abort because it is not a different size.
				return $attachment_id;
			}
		}

		$uploads = wp_upload_dir();
		$base_url = $uploads['baseurl'];
		if ( strpos( $full_size_url, $base_url ) === 0 ) {
			// $attached_file = ltrim( $full_size_url, $base_url . '/' );
			$attached_file = str_replace( $base_url . '/', '', $full_size_url );
			$attachment_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_wp_attached_file' AND meta_value=%s LIMIT 1;",
				$attached_file
			) );
		}

		return (int)$attachment_id;
	}

	/**
	 * When saving a post, save the first image in the content as an eye catch image.
	 *
	 * @since 0.2.0
	 *
	 * @param int $post_id
	 * @param post $post
	 * @filter save_post
	 */
	public function save_post( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return;

		if ( wp_is_post_revision( $post_id ) )
			return;

		if ( ! isset( $this->options['auto_save_posts'] ) || ! in_array( $post->post_type, $this->options['auto_save_posts'] ) )
			return;

		// If the Disable_featured_image custom field is set, skip it.
		if ( get_post_meta( $post_id, 'disable_featured_image', true ) )
			return;

		$attachment_id = get_post_meta( $post_id, '_thumbnail_id', true );
		$content = $post->post_content;

		if ( ! $attachment_id ) {
			$attachment_id = 0;
			$external_image = isset( $this->options['external_image'] ) ? $this->options['external_image'] : false;
			$exclude_small_image = isset( $this->options['exclude_small_image'] ) ? $this->options['exclude_small_image'] : false;
			$exclude_small_image_size = isset( $this->options['exclude_small_image_size'] ) ? (int) $this->options['exclude_small_image_size'] : 0;

			$matches = array();
			preg_match_all( '/<\s*img .*?src\s*=\s*[\"|\'](.*?)[\"|\'].*?>/i', $content, $matches );
			foreach ( $matches[0] as $key => $img ) {
				$url = $matches[1][$key];

				// Exclusion by extension
				//$ext = substr( $matches[1][$key], strrpos( $matches[1][$key], '.' ) + 1 );
				//if ( in_array( $ext, array( 'gif' ), true ) ) {
				//	continue;
				//}

				// Get the ID from the Wp-image-{$id} class.
				$class_matches = array();
				if ( preg_match( '/class\s*=\s*[\"|\'].*?wp-image-([0-9]*).*?[\"|\']/i', $img, $class_matches ) ) {
					$attachment_id = $class_matches[1];
				}

				// Get ID from URL.
				if ( ! $attachment_id ) {
					$attachment_id = $this->get_attachment_id_by_url( $url );
					if ( ! $attachment_id ) {
						$attachment_id = $this->get_attachment_id_by_url( $url, false );
					}
				}

				// Get ID from GUID URL.
				if ( ! $attachment_id ) {
					$attachment_id = $this->get_attachment_id_by_guid( $url );
				}

				if ( $attachment_id ) {
					// Size check.
					if ( $exclude_small_image ) {
						$meta_data = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
						if ( ! is_array( $meta_data ) || ! isset( $meta_data['height'] ) || ! isset( $meta_data['width'] ) ||
							$meta_data['height'] <= $exclude_small_image_size ||
							$meta_data['width'] <= $exclude_small_image_size
						) {
							continue;
						}
					}
				} else {
					// Get external image.
					if ( $external_image ) {
						// Size check.
						if ( $exclude_small_image ) {
							$size = @getimagesize( $url );
							if ( ! $size || $size[0] <= $exclude_small_image_size || $size[1] <= $exclude_small_image_size ) {
								continue;
							}
						}

						$attachment_id = $this->insert_attachment_from_url( $url, $post_id );
						if ( is_wp_error( $attachment_id ) ) {
							return;
						}
					}
				}

				if ( $attachment_id ) {
					update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
					break;
				}
			}

			// Gallery
			if ( ! $attachment_id ) {
				$matches = array();
				if ( preg_match( '/\[\s*gallery\s.*ids\s*=\s*[\"|\']([0-9\s]+).*?[\"|\'].*?\]/i', $content, $matches ) ) {
					$attachment_id = $matches[1];
					update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
				}
			}

			if ( $attachment_id == 0 ) {
				$default_image = isset( $this->options['default_image'] ) ? (int)$this->options['default_image'] : 0;
				if ( $default_image ) {
					update_post_meta( $post_id, '_thumbnail_id', $default_image );
				}
			}
		}
	}

	/**
	 * Add the featured Image column.
	 *
	 * @since 0.3.0
	 */
	function add_columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			$columns = array();
		}
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			if ( $key == 'title' ) {
				$new_columns['featured-image'] = esc_html__( 'Image', 'xo-featured-image-tools' );
            }
			$new_columns[$key] = $value;
        }
		return $new_columns;
	}

	/**
	 * Output the featured Image column.
	 *
	 * @since 0.3.0
	 */
	function custom_columns( $column, $post_id ) {
		if ( $column == 'featured-image' ) {
			if ( has_post_thumbnail( $post_id ) ) {
				echo get_the_post_thumbnail( $post_id, 'thumbnail' );
			} else {
				echo '<div class="featured-image-none"></div>';
			}
		}
	}

	/**
	 * Output the featured image filter to the list of posts.
	 *
	 * @since 0.3.0
	 */
	function restrict_manage_posts( $post_type ) {
		if ( isset( $this->options['list_posts'] ) && in_array( $post_type, $this->options['list_posts'] ) ) {
			$filter = isset( $_GET['featured_image_filter'] ) ? $_GET['featured_image_filter'] : '';
			echo '<select name="featured_image_filter" id="featured_image_filter">';
			echo '<option value="all" ' . selected( 'all', $filter ) . '>' . __( 'Featured Image', 'xo-featured-image-tools' ) . '</option>';
			echo '<option value="notset" ' . selected( 'notset', $filter ) . '>' . __( 'Not set', 'xo-featured-image-tools' ) . '</option>';
			echo '</select>' . "\n";
		}
	}

	/**
	 * Add the featured image filter to the list of posts.
	 *
	 * @since 0.3.0
	 */
	function parse_query( $query ) {
		global $pagenow, $post_type;
		if ( is_admin() && $pagenow == 'edit.php' && isset( $_GET['featured_image_filter'] ) && $_GET['featured_image_filter'] != 'all' ) {
			if ( isset( $this->options['list_posts'] ) && in_array( $post_type, $this->options['list_posts'] ) ) {
				$query->query_vars['meta_key'] = '_thumbnail_id';
				$query->query_vars['meta_compare'] = 'NOT EXISTS';
				$query->query_vars['meta_value'] = '';
			}
		}
	}

	/**
	 * Output the settings page.
	 *
	 * @since 0.3.0
	 */
	function settings_page() {
		echo '<div class="wrap">';
		echo '<h1>' . __( 'XO Featured Image Tools Settings', 'xo-featured-image-tools' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'xo_featured_image_tools_group' );
		do_settings_sections( 'xo_featured_image_tools_group' );
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Register the settings.
	 *
	 * @since 0.3.0
	 */
	function register_settings() {
		register_setting( 'xo_featured_image_tools_group', 'xo_featured_image_tools_options', array( $this, 'sanitize' ) );
		add_settings_section( 'xo_featured_image_tools_posts_list_section', __( 'Post List', 'xo-featured-image-tools' ), '__return_empty_string', 'xo_featured_image_tools_group' );
		add_settings_field( 'enable_edit_list', __( 'Featured Image Item', 'xo-featured-image-tools' ), array( $this, 'field_list_posts' ), 'xo_featured_image_tools_group', 'xo_featured_image_tools_posts_list_section' );
		add_settings_section( 'xo_featured_image_tools_edit_post_section', __( 'Edit Post', 'xo-featured-image-tools' ), '__return_empty_string', 'xo_featured_image_tools_group' );
		add_settings_field( 'enable_edit_list', __( 'Automatically generated', 'xo-featured-image-tools' ), array( $this, 'field_auto_save_posts' ), 'xo_featured_image_tools_group', 'xo_featured_image_tools_edit_post_section' );
		add_settings_field( 'external_image', __( 'External image', 'xo-featured-image-tools' ), array( $this, 'field_external_image' ), 'xo_featured_image_tools_group', 'xo_featured_image_tools_edit_post_section' );
		add_settings_field( 'exclude_small_image', __( 'Exclude small image', 'xo-featured-image-tools' ), array( $this, 'field_exclude_small_image' ), 'xo_featured_image_tools_group', 'xo_featured_image_tools_edit_post_section' );
		add_settings_field( 'default_image', __( 'Default image', 'xo-featured-image-tools' ), array( $this, 'field_default_image' ), 'xo_featured_image_tools_group', 'xo_featured_image_tools_edit_post_section' );
	}

	/**
	 * Register the list posts field.
	 *
	 * @since 0.3.0
	 */
	function field_list_posts() {
		$checks = isset( $this->options['list_posts'] ) ? $this->options['list_posts'] : array();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$fields = array();
		foreach ( $post_types as $post_type ) {
			if ( post_type_supports( $post_type->name, 'thumbnail' ) ) {
				$check = in_array( $post_type->name, $checks );
				$field = "<label for=\"list_posts_{$post_type->name}\">";
				$field .= sprintf( '<input id="list_posts_%1$s" type="checkbox" class="checkbox" name="xo_featured_image_tools_options[list_posts][]" value="%1$s" %2$s> %3$s (%1$s)', $post_type->name, checked( $check, true, false ), $post_type->label );
				$field .= '</label>';
				$fields[] = $field;
			}
		}
		echo '<fieldset>';
		echo implode( '<br />', $fields );
		echo '<p class="description">' . __( 'Please select the post type to display featured image item.', 'xo-featured-image-tools' ) . '</p>';
		echo "</fieldset>\n";
	}

	/**
	 * Register the auto save posts field.
	 *
	 * @since 0.3.0
	 */
	function field_auto_save_posts() {
		$checks = isset( $this->options['auto_save_posts'] ) ? $this->options['auto_save_posts'] : array();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$fields = array();
		foreach ( $post_types as $post_type ) {
			if ( post_type_supports( $post_type->name, 'thumbnail' ) ) {
				$check = in_array( $post_type->name, $checks );
				$field = "<label for=\"auto_save_posts_{$post_type->name}\">";
				$field .= sprintf( '<input id="auto_save_posts_%1$s" type="checkbox" class="checkbox" name="xo_featured_image_tools_options[auto_save_posts][]" value="%1$s" %2$s> %3$s (%1$s)', $post_type->name, checked( $check, true, false ), $post_type->label );
				$field .= '</label>';
				$fields[] = $field;
			}
		}
		echo '<fieldset>';
		echo implode( '<br />', $fields );
		echo '<p class="description">' . __( 'Please select the post type that automatically generates featured images.', 'xo-featured-image-tools' ) . '</p>';
		echo "</fieldset>\n";
	}

	/**
	 * Register the external image field.
	 *
	 * @since 1.1.0
	 */
	function field_external_image() {
		$check = isset( $this->options['external_image'] ) ? $this->options['external_image'] : false;
		echo '<label for="external_image"><input id="external_image" name="xo_featured_image_tools_options[external_image]" type="checkbox" value="1" class="code" ' . checked( 1, $check, false ) . ' /> ' . __( 'Also applies to external images (images other than attachment files)', 'xo-featured-image-tools' ) . '</label>';
	}

	/**
	 * Register exclude small image field.
	 *
	 * @since 1.5.0
	 */
	function field_exclude_small_image() {
		$check = isset( $this->options['exclude_small_image'] ) ? $this->options['exclude_small_image'] : false;
		$size = isset( $this->options['exclude_small_image_size'] ) ? $this->options['exclude_small_image_size'] : 48;
		echo '<label for="exclude_small_image"><input id="exclude_small_image" name="xo_featured_image_tools_options[exclude_small_image]" type="checkbox" value="1" class="code" ' . checked( 1, $check, false ) . ' /></label> ';
		echo '<label for="exclude_small_image_size"><input id="exclude_small_image_size" name="xo_featured_image_tools_options[exclude_small_image_size]" type="number" value="' . $size . '" class="small-text" min="0" max="9999" step="1" /> ' . __( 'px or less', 'xo-featured-image-tools' ) . '</label>';
	}

	/**
	 * Register the default image field.
	 *
	 * @since 1.3.0
	 */
	function field_default_image() {
		$image_id = isset( $this->options['default_image'] ) ? $this->options['default_image'] : 0;
		$image_src = ( $image_id ) ? wp_get_attachment_image_src( $image_id, array( 150, 150 ) ) : false;
		$img = ( $image_src ) ? '<img src="' . $image_src[0] . '">' : '';
		echo '<input id="default_image" name="xo_featured_image_tools_options[default_image]" type="hidden" value="' . $image_id . '" />';
		echo '<input class="button hide-if-no-js" name="default_image_setting" id="default_image_setting" value="' . __( 'Select Image', 'xo-featured-image-tools' ) . '" type="button" data-title="' . __( 'Default Image', 'xo-featured-image-tools' ) . '">&nbsp;';
		echo '<input class="button hide-if-no-js" name="default_image_clear" id="default_image_clear" value="' . __( 'Clear Image', 'xo-featured-image-tools' ) . '" type="button"' . disabled( $img, '', false ) . '>';
		echo "<p><div id=\"default-image-area\">{$img}</div></p>";
	}

	/**
	 * Sanitize our setting.
	 *
	 * @since 0.3.0
	 */
	function sanitize( $input ) {
		$input['external_image'] = ( isset( $input['external_image'] ) );
		$input['exclude_small_image_size'] = isset( $input['exclude_small_image_size'] ) ? intval( $input['exclude_small_image_size'] ) : 48;
		$input['default_image'] = ( isset( $input['default_image'] ) ) ? (int)$input['default_image'] : 0;
		return $input;
	}
}
