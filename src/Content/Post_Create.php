<?php
/**
 * WP/post-create: the experimental content write ability wrapper.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Content;

use WordPress\Abilities\Abstract_Core_Ability;
use WP_Error;
use WP_Post_Type;

/**
 * Ability "wp/post-create": creates a single post through wp_insert_post().
 *
 * The permission surface is fail-closed and mirrors the checks core's own
 * post-creation flow applies: the request-level floor is `edit_posts`, a
 * registered post type's `create_posts` capability gates every call, and
 * publishing additionally requires the type's `publish_posts` capability.
 * Unknown post types, unknown statuses, and malformed input are denied —
 * never widened.
 *
 * This is an experimental-tier ability (`wp/` prefix): the shape of the
 * schema, the annotation surface, and the meta input surface are free to
 * change until it stabilizes. Meta input is deliberately deferred.
 */
final class Post_Create extends Abstract_Core_Ability {

	/**
	 * The statuses this ability accepts, in schema-declared order.
	 *
	 * Statuses outside this list — including virtual statuses like
	 * `inherit` or `trash` — are denied; a create wrapper must never
	 * smuggle a post into a state its owner did not request.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_STATUSES = array( 'draft', 'publish', 'pending' );

	/**
	 * The full ability name, including its tier prefix.
	 *
	 * @return string
	 */
	protected function ability_name(): string {
		return 'wp/post-create';
	}

	/**
	 * The human-readable ability label.
	 *
	 * @return string
	 */
	protected function label(): string {
		return __( 'Create post' );
	}

	/**
	 * A detailed description of what the ability does and when to use it.
	 *
	 * @return string
	 */
	protected function description(): string {
		return __(
			'Creates a single post in a supported publication status. Requires the post type\'s create capability; publishing additionally requires the publish capability. Post meta is not accepted.'
		);
	}

	/**
	 * The ability category slug.
	 *
	 * @return string
	 */
	protected function category_slug(): string {
		return 'content';
	}

	/**
	 * The authored ability meta: annotations and exposure only.
	 *
	 * Provenance (source and package version) is added by the base class;
	 * the stability tier is stamped by the Registrar at registration time
	 * and must never be restated here.
	 *
	 * @return array<string, mixed>
	 */
	protected function ability_meta(): array {
		return array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			'public'      => false,
		);
	}

	/**
	 * The request-level capability floor for wp/post-create.
	 *
	 * @return list<string>
	 */
	protected function required_capabilities(): array {
		return array( 'edit_posts' );
	}

	/**
	 * Builds the strict input schema (§8.2 experimental write contract).
	 *
	 * `post_type` and `title` are required; content, excerpt, status, and
	 * slug are optional. Post meta is deliberately not accepted: a
	 * free-form meta surface is the riskiest part of a generic write, and
	 * it stays deferred until the ability stabilizes.
	 *
	 * @return array<string, mixed>
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_type', 'title' ),
			'additionalProperties' => false,
			'properties'           => array(
				'post_type' => array(
					'type'        => 'string',
					'description' => __( 'Post type slug to create, e.g. post or page.' ),
				),
				'title'     => array(
					'type'        => 'string',
					'description' => __( 'Post title, as the author typed it.' ),
				),
				'content'   => array(
					'type'        => 'string',
					'description' => __( 'Post body content.' ),
				),
				'excerpt'   => array(
					'type'        => 'string',
					'description' => __( 'Short summary of the post.' ),
				),
				'status'    => array(
					'type'        => 'string',
					'enum'        => self::ALLOWED_STATUSES,
					'default'     => 'draft',
					'description' => __( 'Publication status the post is created in.' ),
				),
				'slug'      => array(
					'type'        => 'string',
					'description' => __( 'Slug of the post. Derived from the title when omitted.' ),
				),
			),
		);
	}

	/**
	 * Builds the strict output schema.
	 *
	 * Exactly the three results a caller needs to continue: the id, the
	 * permalink, and the resulting status.
	 *
	 * @return array<string, mixed>
	 */
	protected function output_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'link', 'status' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'readonly'    => true,
					'description' => __( 'ID of the created post.' ),
				),
				'link'   => array(
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
					'description' => __( 'Permalink of the created post.' ),
				),
				'status' => array(
					'type'        => 'string',
					'enum'        => self::ALLOWED_STATUSES,
					'readonly'    => true,
					'description' => __( 'Status of the created post.' ),
				),
			),
		);
	}

	/**
	 * Makes the permission callback for wp/post-create.
	 *
	 * Fail-closed ordering, mirroring core's post-creation flow:
	 *
	 * 1. The input must be shaped well enough to name an existing post
	 *    type, a title, and an allowed status — anything else is denied,
	 *    never guessed.
	 * 2. The post type's own `create_posts` capability gates every call.
	 *    `create_posts` defaults to `edit_posts` and remaps per type
	 *    (e.g. `edit_books`), exactly as core's edit flow uses map_meta_cap
	 *    through the post type object's capability map.
	 * 3. Status `publish` additionally requires the type's
	 *    `publish_posts` capability.
	 *
	 * @return callable(mixed): (bool|\WP_Error)
	 */
	protected function make_permission_callback(): callable {
		return static function ( $input ): bool {
			$post_type_object = self::resolve_post_type_object( $input );
			if ( null === $post_type_object ) {
				return false;
			}

			$status = self::resolve_status( $input );
			if ( null === $status ) {
				return false;
			}

			if ( null === self::resolve_title( $input ) ) {
				return false;
			}

			$create_posts = $post_type_object->cap->create_posts;
			if ( ! is_string( $create_posts ) || ! current_user_can( $create_posts ) ) {
				return false;
			}

			if ( 'publish' === $status ) {
				$publish_posts = $post_type_object->cap->publish_posts;

				return is_string( $publish_posts ) && current_user_can( $publish_posts );
			}

			return true;
		};
	}

	/**
	 * Makes the execute callback for wp/post-create.
	 *
	 * Wraps wp_insert_post() with the validated, normalized schema input:
	 * post_author is the current user, unlisted fields stay unlisted, and
	 * the only surfaced result is the schema-shaped id/link/status array.
	 * Failures, including post-type errors from wp_insert_post(), are
	 * passed through as WP_Error.
	 *
	 * @return callable(mixed): (mixed|\WP_Error) Receives validated input.
	 */
	protected function make_execute_callback(): callable {
		return static function ( $input ) {
			if ( ! is_array( $input ) ) {
				return new WP_Error(
					'wp_post_create_invalid_input',
					__( 'The input is not a valid input object.' ),
					array( 'status' => 400 )
				);
			}

			$post_type_object = self::resolve_post_type_object( $input );
			if ( null === $post_type_object ) {
				return new WP_Error(
					'wp_post_create_invalid_input',
					__( 'The input does not name a registered post type.' ),
					array( 'status' => 400 )
				);
			}

			$title = self::resolve_title( $input );
			if ( null === $title ) {
				return new WP_Error(
					'wp_post_create_invalid_input',
					__( 'The input does not include a post title.' ),
					array( 'status' => 400 )
				);
			}

			$status = self::resolve_status( $input );

			$postarr = array(
				'post_type'   => $post_type_object->name,
				'post_title'  => $title,
				'post_status' => $status,
				'post_author' => get_current_user_id(),
			);

			$content = $input['content'] ?? null;
			if ( is_string( $content ) && '' !== trim( $content ) ) {
				$postarr['post_content'] = $content;
			}

			$excerpt = $input['excerpt'] ?? null;
			if ( is_string( $excerpt ) && '' !== trim( $excerpt ) ) {
				$postarr['post_excerpt'] = $excerpt;
			}

			$slug = $input['slug'] ?? null;
			if ( is_string( $slug ) && '' !== trim( $slug ) ) {
				$postarr['post_name'] = $slug;
			}

			// With $wp_error = true, wp_insert_post() returns either the new
			// post id or a WP_Error; there is no falsy failure signal left.
			$post_id = wp_insert_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$post = get_post( $post_id );
			if ( null === $post ) {
				return new WP_Error(
					'wp_post_create_unknown_failure',
					__( 'The created post could not be read back.' ),
					array( 'status' => 500 )
				);
			}

			return array(
				'id'     => $post->ID,
				'link'   => (string) get_permalink( $post_id ),
				'status' => $post->post_status,
			);
		};
	}

	/**
	 * Resolves the ability's post type object, or null when absent.
	 *
	 * @param mixed $input The raw input.
	 * @return WP_Post_Type|null The post type object, or null when the
	 *   input is malformed or names an unregistered post type.
	 */
	private static function resolve_post_type_object( $input ): ?WP_Post_Type {
		if ( ! is_array( $input ) ) {
			return null;
		}

		$post_type = $input['post_type'] ?? null;
		if ( ! is_string( $post_type ) || '' === $post_type ) {
			return null;
		}

		$post_type_object = get_post_type_object( $post_type );

		return $post_type_object instanceof WP_Post_Type ? $post_type_object : null;
	}

	/**
	 * Resolves the ability's status, or null when malformed or unknown.
	 *
	 * A missing status reads as the schema's declared default of `draft`;
	 * any present-but-invalid status is denied, never coerced. The status
	 * is also confined to statuses the ability itself accepts — another
	 * registered status is unknown as far as wp/post-create is concerned.
	 *
	 * @param mixed $input The raw input.
	 * @return string|null The status, or null when malformed or unknown.
	 */
	private static function resolve_status( $input ): ?string {
		if ( ! is_array( $input ) ) {
			return null;
		}

		$status = $input['status'] ?? 'draft';
		if ( ! is_string( $status ) ) {
			return null;
		}

		return in_array( $status, self::ALLOWED_STATUSES, true ) ? $status : null;
	}

	/**
	 * Resolves the ability's title, or null when malformed or empty.
	 *
	 * @param mixed $input The raw input.
	 * @return string|null The title, or null when not a non-empty string.
	 */
	private static function resolve_title( $input ): ?string {
		if ( ! is_array( $input ) ) {
			return null;
		}

		$title = $input['title'] ?? null;

		if ( ! is_string( $title ) || '' === trim( $title ) ) {
			return null;
		}

		return $title;
	}
}
