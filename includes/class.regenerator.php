<?php

/**
 * Regenerates the thumbnails for a given attachment.
 *
 * @since 3.0.0
 */
class RegenerateThumbnails_Regenerator {

	/**
	 * The WP_Post object for the attachment that is being operated on.
	 *
	 * @since 3.0.0
	 *
	 * @var WP_Post
	 */
	public $attachment;

	/**
	 * Stores the full path to the original image so that it can be passed between methods.
	 *
	 * @since 3.0.0
	 *
	 * @var string
	 */
	public $fullsizepath;

	/**
	 * Generates an instance of this class after doing some setup.
	 *
	 * MIME type is purposefully not validated in order to be more future proof and
	 * to avoid duplicating a ton of logic that already exists in WordPress core.
	 *
	 * @since 3.0.0
	 *
	 * @param int $attachment_id Attachment ID to process.
	 *
	 * @return RegenerateThumbnails_Regenerator|WP_Error A new instance of RegenerateThumbnails_Regenerator on success, or WP_Error on error.
	 */
	public static function get_instance( $attachment_id ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment ) {
			return new WP_Error(
				'regenerate_thumbnails_regenerator_attachment_doesnt_exist',
				__( 'No attachment exists with that ID.', 'regenerate-thumbnails' ),
				array(
					'status' => 404,
				)
			);
		}

		// We can only regenerate thumbnails for attachments
		if ( 'attachment' !== get_post_type( $attachment ) ) {
			return new WP_Error(
				'regenerate_thumbnails_regenerator_not_attachment',
				__( 'This item is not an attachment.', 'regenerate-thumbnails' ),
				array(
					'status' => 400,
				)
			);
		}

		// Don't touch any attachments that are being used as a site icon. Their thumbnails are usually custom cropped.
		if ( 'site-icon' === get_post_meta( $attachment->ID, '_wp_attachment_context', true ) ) {
			return new WP_Error(
				'regenerate_thumbnails_regenerator_is_site_icon',
				__( "This attachment is being used as a site icon and therefore the thumbnails shouldn't be touched.", 'regenerate-thumbnails' ),
				array(
					'status'     => 415,
					'attachment' => $attachment,
				)
			);
		}

		return new RegenerateThumbnails_Regenerator( $attachment );
	}

	/**
	 * The constructor for this class. Don't call this directly, see get_instance() instead.
	 * This is done so that WP_Error objects can be returned during class initiation.
	 *
	 * @since 3.0.0
	 *
	 * @param WP_Post $attachment The WP_Post object for the attachment that is being operated on.
	 */
	private function __construct( WP_Post $attachment ) {
		$this->attachment = $attachment;
	}

	/**
	 * Regenerate the thumbnails for this instance's attachment.
	 *
	 * @todo  Additional parameters such as deleting old thumbnails or only regenerating certain sizes.
	 *
	 * @since 3.0.0
	 *
	 * @param array|string $args {
	 *     Optional. Array or string of arguments for thumbnail regeneration.
	 *
	 *     @type bool $only_regenerate_missing_thumbnails  Skip regenerating existing thumbnail files. Default true.
	 *     @type bool $delete_unregistered_thumbnail_files Delete any thumbnail sizes that are no longer registered. Default false.
	 * }
	 *
	 * @return mixed|WP_Error Metadata for attachment (see wp_generate_attachment_metadata()), or WP_Error on error.
	 */
	public function regenerate( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'only_regenerate_missing_thumbnails'  => true,
			'delete_unregistered_thumbnail_files' => false,
		) );

		$this->fullsizepath = get_attached_file( $this->attachment->ID );

		if ( false === $this->fullsizepath || ! file_exists( $this->fullsizepath ) ) {
			return new WP_Error(
				'regenerate_thumbnails_regenerator_file_not_found',
				__( "Unable to locate the original file for this attachment.", 'regenerate-thumbnails' ),
				array(
					'status'       => 404,
					'fullsizepath' => _wp_relative_upload_path( $this->fullsizepath ),
					'attachment'   => $this->attachment,
				)
			);
		}

		$old_metadata = wp_get_attachment_metadata( $this->attachment->ID );

		if ( $args['only_regenerate_missing_thumbnails'] ) {
			add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_image_sizes_to_only_missing_thumbnails' ), 10, 2 );
		}

		require_once( ABSPATH . 'wp-admin/includes/admin.php' );
		$new_metadata = wp_generate_attachment_metadata( $this->attachment->ID, $this->fullsizepath );

		if ( $args['only_regenerate_missing_thumbnails'] ) {
			// Certain sizes may have been temporarily removed by the file so that they
			// weren't regenerated, so we need to add them back into the metadata.
			$new_metadata['sizes'] = array_merge( $old_metadata['sizes'], $new_metadata['sizes'] );

			remove_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_image_sizes_to_only_missing_thumbnails' ), 10 );
		}

		// If the user wants to keep their storage usage down, we can iterate over the list of
		// thumbnails in the old metadata and delete any that are no longer registered as valid sizes.
		if ( $args['delete_unregistered_thumbnail_files'] ) {
			$upload_dir = wp_get_upload_dir();
			$upload_dir = trailingslashit( $upload_dir['path'] );

			$intermediate_image_sizes = get_intermediate_image_sizes();
			foreach ( $old_metadata['sizes'] as $size => $thumbnail ) {
				if ( in_array( $size, $intermediate_image_sizes ) ) {
					continue;
				}

				// @todo: Prefix with @ for release. Could wrap in file_exists() but that just adds overhead.
				unlink( $upload_dir . $thumbnail['file'] );

				unset( $new_metadata['sizes'][ $size ] );
			}
		}

		wp_update_attachment_metadata( $this->attachment->ID, $new_metadata );

		return $new_metadata;
	}

	/**
	 * Filters the list of thumbnail sizes to only include those which have missing files.
	 *
	 * @param array $sizes    An associative array of image sizes.
	 * @param array $metadata An associative array of image metadata: width, height, file.
	 *
	 * @return array An associative array of image sizes.
	 */
	public function filter_image_sizes_to_only_missing_thumbnails( $sizes, $metadata ) {
		if ( ! $sizes ) {
			return $sizes;
		}

		$editor = wp_get_image_editor( $this->fullsizepath );

		if ( is_wp_error( $editor ) ) {
			return $sizes;
		}

		// This is based on WP_Image_Editor_GD::multi_resize() and others
		foreach ( $sizes as $size => $size_data ) {
			if ( ! isset( $size_data['width'] ) && ! isset( $size_data['height'] ) ) {
				continue;
			}

			if ( ! isset( $size_data['width'] ) ) {
				$size_data['width'] = null;
			}
			if ( ! isset( $size_data['height'] ) ) {
				$size_data['height'] = null;
			}

			if ( ! isset( $size_data['crop'] ) ) {
				$size_data['crop'] = false;
			}

			$dims = image_resize_dimensions( $metadata['width'], $metadata['height'], $size_data['width'], $size_data['height'], $size_data['crop'] );

			if ( ! $dims ) {
				unset( $sizes[ $size ] );
				continue;
			}

			list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;

			$suffix   = "{$dst_w}x{$dst_h}";
			$file_ext = strtolower( pathinfo( $this->fullsizepath, PATHINFO_EXTENSION ) );

			$filename = $editor->generate_filename( $suffix, null, $file_ext );

			if ( file_exists( $filename ) ) {
				unset( $sizes[ $size ] );
			}
		}

		return $sizes;
	}

	/**
	 * Update the post content of any public post types (posts and pages by default)
	 * that make use of this attachment.
	 *
	 * @param array|string $args {
	 *     Optional. Array or string of arguments for controlling the updating.
	 *
	 *     @type array $post_type      The post types to update. Defaults to public post types (posts and pages by default).
	 *     @type array $post_ids       Specific post IDs to update as opposed to any that uses the attachment.
	 *     @type int   $posts_per_loop How many posts to query at a time to keep memory usage down. You shouldn't need to modify this.
	 * }
	 *
	 * @return array List of post IDs that were modified. The key is the post ID and the value is either the post ID again or a WP_Error object if wp_update_post() failed.
	 */
	public function update_usages_in_posts( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'post_type'     => array(),
			'post_ids'       => array(),
			'posts_per_loop' => 10,
		) );

		if ( empty( $args['post_type'] ) ) {
			$args['post_type'] = array_values( get_post_types( array( 'public' => true ) ) );
			unset( $args['post_type']['attachment'] );
		}

		$offset        = 0;
		$posts_updated = array();

		while ( true ) {
			$posts = get_posts( array(
				'numberposts'            => $args['posts_per_loop'],
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'include'                => $args['post_ids'],
				'post_type'              => $args['post_type'],
				's'                      => 'wp-image-' . $this->attachment->ID,

				// For faster queries
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );

			if ( ! $posts ) {
				break;
			}

			$offset += $args['posts_per_loop'];

			foreach ( $posts as $post ) {
				$content = $post->post_content;
				$search  = $replace = array();

				// Example: <img src="URL" alt="" width="100" height="75" class="size-thumbnail wp-image-1712" />
				preg_match_all(
					'#<img src="[^"]+"([^>]+)? width="[^"]+" height="[^"]+"([^>]+)size-([^"]+) wp-image-' . $this->attachment->ID . '"([^>]+)?/>#i',
					$content,
					$matches,
					PREG_SET_ORDER
				);
				if ( $matches ) {
					foreach ( $matches as $match ) {
						$thumbnail = image_downsize( $this->attachment->ID, $match[3] );

						if ( ! $thumbnail ) {
							continue;
						}

						$search[]  = $match[0];
						$replace[] = '<img src="' . $thumbnail[0] . '"' . $match[1] . ' width="' . $thumbnail[1] . '" height="' . $thumbnail[2] . '"' . $match[2] . 'size-' . $match[3] . ' wp-image-' . $this->attachment->ID . '"' . $match[4] . '/>';
					}
				}

				// Example: <img class="cssclass wp-image-123 size-large" title="img title" src="URL" alt="alt" width="500" height="375" />
				// I believe this comes from TinyMCE reformatting the HTML
				preg_match_all(
					'#<img ([^>]+)wp-image-' . $this->attachment->ID . ' size-([^"]+)"([^>]+)? src="[^"]+"([^>]+)? width="[^"]+" height="[^"]+" />#i',
					$content,
					$matches,
					PREG_SET_ORDER
				);
				if ( $matches ) {
					foreach ( $matches as $match ) {
						$thumbnail = image_downsize( $this->attachment->ID, $match[2] );

						if ( ! $thumbnail ) {
							continue;
						}

						$search[]  = $match[0];
						$replace[] = '<img ' . $match[1] . 'wp-image-' . $this->attachment->ID . ' size-' . $match[2] . '"' . $match[3] . ' src="' . $thumbnail[0] . '"' . $match[4] . ' width="' . $thumbnail[1] . '" height="' . $thumbnail[2] . '" />';
					}
				}

				// Process the <img> tags now
				$content = str_replace( $search, $replace, $content );
				$search  = $replace = array();

				// Update the width in any [caption] shortcodes
				preg_match_all(
					'#\[caption id="attachment_' . $this->attachment->ID . '"([^\]]+)? width="[^"]+"\]([^\[]+)size-([^" ]+)([^\[]+)\[\/caption\]#i',
					$content,
					$matches,
					PREG_SET_ORDER
				);
				if ( $matches ) {
					foreach ( $matches as $match ) {
						$thumbnail = image_downsize( $this->attachment->ID, $match[3] );

						if ( ! $thumbnail ) {
							continue;
						}

						$search[]  = $match[0];
						$replace[] = '[caption id="attachment_' . $this->attachment->ID . '"' . $match[1] . ' width="' . $thumbnail[1] . '"]' . $match[2] . 'size-' . $match[3] . $match[4] . '[/caption]';
					}
				}

				$content = str_replace( $search, $replace, $content );

				$updated_post_object = (object) array(
					'ID'           => $post->ID,
					'post_content' => $content,
				);

				$posts_updated[ $post->ID ] = wp_update_post( $updated_post_object, true );
			}
		}

		return $posts_updated;
	}
}