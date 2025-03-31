<?php
/**
 * Post Sync Class
 * Handles the synchronization of posts from an external WordPress site.
 *
 * @package WordPress
 * @subpackage Copy Posts WP
 * @since 1.0.0
 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * Class MGCPWP_Post_Sync
	 * Handles the synchronization of posts from an external WordPress site.
	 */
	class MGCPWP_Post_Sync {

		/**
		 * @var array $log Stores the log messages.
		 */
		private static array $log = [];

		/**
		 * Initialize the class.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'wpjps_scheduled_sync', [ __CLASS__, 'sync_posts' ] );
			add_action( 'admin_post_wpjps_manual_sync', [ __CLASS__, 'sync_posts' ] ); // Manual sync action
		}

		/**
		 * Schedule the synchronization of posts.
		 *
		 * @return void
		 */
		public static function sync_posts(): void {
			$options           = get_option( 'wpjps_settings' );
			$external_site_url = rtrim( $options['external_site_url'], '/' );
			$post_type         = $options['post_type'];
			$category          = $options['category'] ?? '';
			$tag               = $options['tag'] ?? '';

			if ( ! $external_site_url || ! $post_type ) {
				self::$log[] = 'Error: Missing required settings.';
				return;
			}

			$api_url = "$external_site_url/wp-json/wp/v2/$post_type?per_page=100"; // Adjust per_page if needed
			if ( $category ) {
				$api_url .= "&categories=$category";
			}
			if ( $tag ) {
				$api_url .= "&tags=$tag";
			}
			$api_url .= '&_embed';

			$response = wp_remote_get( $api_url, [ 'timeout' => 15 ] );

			if ( is_wp_error( $response ) ) {
				self::$log[] = 'Error fetching posts: ' . $response->get_error_message();
				return;
			}

			$posts = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $posts ) ) {
				self::$log[] = 'Invalid response from API.';
				return;
			}

			foreach ( $posts as $post_data ) {
				self::import_post( $post_data, $post_type );
			}

			update_option( 'wpjps_sync_log', self::$log );
		}

		/**
		 * Import a single post from the external site.
		 *
		 * @param array $post_data The post data from the external site.
		 * @param string $post_type The post type to import.
		 *
		 * @return void
		 */
		private static function import_post( array $post_data, string $post_type ): void {
			$source_id     = $post_data['id'];
			$existing_post = get_posts( [
				'post_type'   => $post_type,
				'meta_key'    => '_wpjps_source_id',
				'meta_value'  => $source_id,
				'post_status' => 'any',
			] );

			if ( $existing_post ) {
				$post_id = $existing_post[0]->ID;
				self::update_post( $post_id, $post_data );
			} else {
				self::create_post( $post_data, $post_type );
			}
		}

		/**
		 * Create a new post from the external site data.
		 *
		 * @param array $post_data The post data from the external site.
		 * @param string $post_type The post type to import.
		 *
		 * @return void
		 */
		private static function create_post( array $post_data, string $post_type ): void {
			$author_id = self::get_or_create_author( $post_data['author'] );
			$post_id   = wp_insert_post( [
				'post_type'    => $post_type,
				'post_title'   => $post_data['title']['rendered'],
				'post_content' => $post_data['content']['rendered'],
				'post_status'  => 'publish',
				'post_date'    => $post_data['date'],
				'post_author'  => $author_id,
				'meta_input'   => [ '_wpjps_source_id' => $post_data['id'] ],
			] );

			self::set_taxonomies( $post_id, $post_data );
			self::import_images( $post_id, $post_data['content']['rendered'] );
			self::$log[] = "Imported post ID $post_id from source ID {$post_data['id']}.";
		}

		/**
		 * Update an existing post with new data from the external site.
		 *
		 * @param int $post_id The ID of the existing post.
		 * @param array $post_data The new post data from the external site.
		 *
		 * @return void
		 */
		private static function update_post( int $post_id, array $post_data ): void {
			$post             = get_post( $post_id );
			$existing_content = $post->post_content;
			$new_content      = $post_data['content']['rendered'];

			if ( $existing_content !== $new_content ) {
				$updated_post = [
					'ID'           => $post_id,
					'post_content' => $new_content,
					'post_title'   => $post_data['title']['rendered'],
					'post_status'  => 'publish',
					'post_date'    => $post_data['date'],
				];

				wp_update_post( $updated_post );
				self::$log[] = "Updated post ID $post_id.";
			} else {
				self::$log[] = "No changes detected for post ID $post_id.";
			}
		}

		/**
		 * Get or create an author based on the external site data.
		 *
		 * @param int $author_id The ID of the author from the external site.
		 *
		 * @return int The ID of the WordPress user.
		 */
		private static function get_or_create_author( int $author_id ): int {
			$response = wp_remote_get( get_option( 'wpjps_settings' )['external_site_url'] . "/wp-json/wp/v2/users/$author_id" );
			if ( is_wp_error( $response ) ) {
				return get_current_user_id();
			}

			$author_data  = json_decode( wp_remote_retrieve_body( $response ), true );
			$author_email = isset( $author_data['email'] ) ? sanitize_email( $author_data['email'] ) : "author$author_id@example.com";

			$user = get_user_by( 'email', $author_email );
			if ( $user ) {
				return $user->ID;
			}

			return wp_insert_user( [
				'user_login' => sanitize_user( $author_data['name'] ),
				'user_email' => $author_email,
				'user_pass'  => wp_generate_password(),
				'role'       => 'author',
			] );
		}

		/**
		 * Set the taxonomies for the imported post.
		 *
		 * @param int $post_id The ID of the post.
		 * @param array $post_data The post data from the external site.
		 *
		 * @return void
		 */
		private static function set_taxonomies( int $post_id, array $post_data ): void {
			if ( ! isset( $post_data['_embedded']['wp:term'] ) ) {
				return; // No taxonomy data available
			}

			foreach ( $post_data['_embedded']['wp:term'] as $taxonomy_terms ) {
				if ( ! is_array( $taxonomy_terms ) || empty( $taxonomy_terms ) ) {
					continue;
				}

				foreach ( $taxonomy_terms as $term_data ) {
					$term_name = $term_data['name'];
					$term_slug = $term_data['slug'];
					$taxonomy  = $term_data['taxonomy']; // Ensure this is correctly provided in the API response

					if ( ! taxonomy_exists( $taxonomy ) ) {
						continue; // Skip if the taxonomy doesn't exist
					}

					// Check if the term already exists by slug
					$existing_term = get_term_by( 'slug', $term_slug, $taxonomy );

					if ( ! $existing_term ) {
						// Create the term if it doesn’t exist
						$new_term = wp_insert_term( $term_name, $taxonomy, [ 'slug' => $term_slug ] );

						if ( is_wp_error( $new_term ) ) {
							continue; // Skip this term if there's an error
						}

						$term_id = $new_term['term_id'];
					} else {
						$term_id = $existing_term->term_id;
					}

					// Assign the term to the post
					wp_set_post_terms( $post_id, [ $term_id ], $taxonomy, true );
				}
			}
		}

		/**
		 * Import images from the post content.
		 *
		 * @param int $post_id The ID of the post.
		 * @param string $content The post content.
		 *
		 * @return void
		 */
		private static function import_images( int $post_id, string $content ): void {
			preg_match_all( '/<img[^>]+src=["\'](.*?)["\']/i', $content, $matches );

			foreach ( $matches[1] as $image_url ) {
				self::download_image( $post_id, $image_url );
			}
		}

		/**
		 * Download an image and set it as the post thumbnail.
		 *
		 * @param int $post_id The ID of the post.
		 * @param string $image_url The URL of the image to download.
		 *
		 * @return void
		 */
		private static function download_image( int $post_id, string $image_url ): void {
			$upload_dir = wp_upload_dir();
			$image_data = wp_remote_get( $image_url );

			if ( is_wp_error( $image_data ) ) {
				self::$log[] = "Error downloading image: " . $image_data->get_error_message();
				return;
			}

			$image_body   = wp_remote_retrieve_body( $image_data );
			$content_type = wp_remote_retrieve_header( $image_data, 'content-type' );

			// Get the file extension based on the MIME type
			$mime_extensions = [
				'image/jpeg'    => 'jpg',
				'image/png'     => 'png',
				'image/gif'     => 'gif',
				'image/webp'    => 'webp',
				'image/svg+xml' => 'svg',
			];

			$extension = isset( $mime_extensions[ $content_type ] ) ? $mime_extensions[ $content_type ] : 'jpg'; // Default to jpg
			$filename  = wp_unique_filename( $upload_dir['path'], uniqid( 'image_' ) . '.' . $extension );
			$filepath  = $upload_dir['path'] . '/' . $filename;

			// Save the file
			file_put_contents( $filepath, $image_body );

			// Ensure correct MIME type
			$wp_filetype = wp_check_filetype( $filename, null );

			// Create attachment post
			$attachment = [
				'post_mime_type' => $wp_filetype['type'],
				'post_title'     => sanitize_file_name( $filename ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			];

			$attachment_id = wp_insert_attachment( $attachment, $filepath, $post_id );

			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $filepath );
			wp_update_attachment_metadata( $attachment_id, $attachment_data );

			// Set featured image if none exists
			if ( ! get_post_thumbnail_id( $post_id ) ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}

			self::$log[] = "Downloaded and attached image ($filename) to post ID $post_id.";
		}

	}
