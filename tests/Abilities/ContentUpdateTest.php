<?php
/**
 * Tests: `core/content-update` (port of WordPress/ai PR #1025).
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use ReflectionClass;
use WP_Error;
use WP_Post;
use WP_Abilities_Registry;
use WordPress\Abilities\Manifest;
use WordPress\Abilities\Registrar;
use WordPress\Abilities\Support\Features;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Per-ability contract tests for `core/content-update`.
 */
final class ContentUpdateTest extends TestCase {

	/**
	 * An ID no fixture can ever carry: core defines the equivalent
	 * REST_TESTS_IMPOSSIBLY_HIGH_NUMBER inside its test bootstrap, which
	 * static analysis does not load.
	 */
	private const IMPOSSIBLE_ID = 99999999;

	/**
	 * Cached IDs of the mocked users, keyed by fixture name.
	 *
	 * @var array<string, int>
	 */
	private array $user_ids = array();

	/**
	 * Cached IDs of the mocked posts, keyed by fixture name.
	 *
	 * @var array<string, int>
	 */
	private array $post_ids = array();

	/**
	 * Set up before class.
	 */
	public static function set_up_before_class(): void {
		if ( 0 === did_action( 'wp_abilities_api_init' ) ) {
			WP_Abilities_Registry::get_instance();
		}
		Registrar::register_all();

		if ( ! Features::is_available( 'show_in_abilities' ) ) {
			Features::load_polyfill( 'show_in_abilities' );
		}
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		foreach ( $this->post_ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		if ( ! empty( $this->user_ids ) && ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->user_ids as $id ) {
			if ( (int) $id > 1 ) {
				wp_delete_user( (int) $id );
			}
		}
		$this->user_ids = array();
		$this->post_ids = array();

		parent::tear_down();
	}

	/**
	 * The registered instance, or null.
	 */
	private function get_registered(): ?object {
		return WP_Abilities_Registry::get_instance()->get_registered( 'core/content-update' );
	}

	/**
	 * Invokes a private instance method.
	 */
	private function invoke_private( object $ability, string $method, ...$args ) {
		$reflected = new ReflectionClass( $ability );
		$method    = $reflected->getMethod( $method );
		$method->setAccessible( true );

		return $method->invoke( $ability, ...$args );
	}

	/**
	 * Asserts a WP_Error with the exact code.
	 */
	private function assert_wp_error( string $code, $actual, ?string $message = null ): void {
		self::assertInstanceOf( WP_Error::class, $actual, $message ?? "Expected WP_Error {$code}." );
		self::assertSame( $code, $actual->get_error_code(), $message ?? "Expected error code {$code}." );
	}

	/**
	 * Creates the mocked users once.
	 */
	private function create_users(): void {
		if ( ! empty( $this->user_ids ) ) {
			return;
		}
		$this->user_ids['editor']      = (int) wp_insert_user(
			array(
				'user_login' => 'ucweditor',
				'role'       => 'editor',
			)
		);
		$this->user_ids['author']      = (int) wp_insert_user(
			array(
				'user_login' => 'ucwauthor',
				'role'       => 'author',
			)
		);
		$this->user_ids['author2']     = (int) wp_insert_user(
			array(
				'user_login' => 'ucwauthor2',
				'role'       => 'author',
			)
		);
		$this->user_ids['contributor'] = (int) wp_insert_user(
			array(
				'user_login' => 'ucwcontributor',
				'role'       => 'contributor',
			)
		);
		$this->user_ids['subscriber']  = (int) wp_insert_user(
			array(
				'user_login' => 'ucwsubscriber',
				'role'       => 'subscriber',
			)
		);
	}

	/**
	 * Creates the editable post fixture once per test.
	 */
	private function make_post(): int {
		$this->create_users();
		$id                             = (int) wp_insert_post(
			array(
				'post_title'   => 'Original title',
				'post_content' => 'Original content',
				'post_excerpt' => 'Original excerpt',
				'post_status'  => 'publish',
				'post_author'  => $this->user_ids['editor'],
				'post_type'    => 'post',
			)
		);
		$this->post_ids[ "post-{$id}" ] = $id;

		return $id;
	}

	/**
	 * Builds a standard update input, with overrides.
	 *
	 * @param array<string, mixed> $overrides Input values to override or add.
	 * @return array<string, mixed> The ability input.
	 */
	private function post_data( array $overrides = array() ): array {
		return array_merge(
			array(
				'title.raw'   => 'Post Title',
				'content.raw' => 'Post content',
				'excerpt.raw' => 'Post excerpt',
				'status'      => 'publish',
				'fields'      => array( 'id', 'type', 'status', 'slug', 'title.raw', 'content.raw', 'excerpt.raw' ),
			),
			$overrides
		);
	}

	/**
	 * Registered with the PR's meta: a destructive, non-idempotent write whose
	 * input requires an ID, turns `type` into an optional guard, and offers the
	 * writable fields on the dotted paths.
	 */
	public function test_registered_meta_and_schema(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$meta = $ability->get_meta();

		self::assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
				'open_world'  => false,
			),
			$meta['annotations'] ?? null
		);
		self::assertTrue( (bool) ( $meta['public'] ?? false ) );
		self::assertSame( 'wordpress/abilities', $meta['source'] ?? null );
		self::assertSame( Manifest::TIER_STABLE, $meta['stability'] ?? null );

		if ( method_exists( $ability, 'get_category' ) ) {
			self::assertSame( 'content', $ability->get_category() );
		}

		$schema = $ability->get_input_schema();
		self::assertSame( 'object', $schema['type'] );
		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( array( 'id' ), $schema['required'], 'Only the ID should be required.' );
		self::assertSame( 1, $schema['properties']['id']['minimum'] );
		self::assertNotContains( 'type', $schema['required'], 'The type is an optional guard.' );
		self::assertSame( array( 'post', 'page' ), $schema['properties']['type']['enum'] );
		self::assertSame(
			array( 'id', 'type', 'title.raw', 'content.raw', 'excerpt.raw', 'status', 'slug', 'date.local', 'date.gmt', 'author.slug', 'parent', 'fields' ),
			array_keys( $schema['properties'] )
		);

		$output = $ability->get_output_schema();
		self::assertSame( array( 'id' ), $output['required'] );
		self::assertArrayHasKey( 'title', $output['properties'] );
	}

	/**
	 * An editor updates every writable field.
	 */
	public function test_update_item(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );
		$post_id = $this->make_post();

		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$result = $ability->execute( $this->post_data( array( 'id' => $post_id ) ) );

		self::assertNotInstanceOf( WP_Error::class, $result );
		self::assertSame( $post_id, $result['id'] );
		self::assertSame( 'Post Title', $result['title']['raw'] );
		self::assertSame( 'Post content', $result['content']['raw'] );
		self::assertSame( 'Post excerpt', $result['excerpt']['raw'] );

		$post = get_post( $post_id );
		self::assertInstanceOf( WP_Post::class, $post );
		self::assertSame( 'Post Title', $post->post_title );
		self::assertSame( 'Post content', $post->post_content );
		self::assertSame( 'Post excerpt', $post->post_excerpt );
	}

	/**
	 * Omitted fields keep their current values.
	 */
	public function test_update_keeps_omitted_fields(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );
		$post_id = $this->make_post();

		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$result = $ability->execute(
			array(
				'id'        => $post_id,
				'title.raw' => 'Only the title',
				'fields'    => array( 'id', 'title.raw', 'content.raw', 'excerpt.raw', 'status' ),
			)
		);

		self::assertNotInstanceOf( WP_Error::class, $result );
		self::assertSame( 'Only the title', $result['title']['raw'] );
		self::assertSame( 'Original content', $result['content']['raw'], 'The content should be kept.' );
		self::assertSame( 'Original excerpt', $result['excerpt']['raw'], 'The excerpt should be kept.' );
		self::assertSame( 'publish', $result['status'], 'The status should be kept.' );
	}

	/**
	 * Sending the post's current author slug back is the same as leaving the
	 * field out, so a no-op update succeeds, even repeated.
	 */
	public function test_update_current_author_back_is_a_no_change(): void {
		$this->create_users();
		$author_id = $this->user_ids['author'];
		wp_set_current_user( $author_id );

		$post_id                             = (int) wp_insert_post(
			array(
				'post_title'  => 'Shared draft',
				'post_status' => 'draft',
				'post_author' => $author_id,
			)
		);
		$this->post_ids[ "post-{$post_id}" ] = $post_id;

		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$slug = get_userdata( $author_id )->user_nicename;

		for ( $i = 0; $i < 2; $i++ ) {
			$result = $ability->execute(
				array(
					'id'          => $post_id,
					'author.slug' => $slug,
				)
			);
			self::assertNotInstanceOf( WP_Error::class, $result );
			self::assertSame( $post_id, $result['id'] );
		}
	}

	/**
	 * Logged-out users, subscribers, authors without edit access to the post,
	 * and editors stripped of the edit capability are denied.
	 */
	public function test_update_permission_denials(): void {
		$this->create_users();
		$post_id = $this->make_post();
		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		wp_set_current_user( 0 );
		$this->assert_wp_error(
			'ability_invalid_permissions',
			$ability->execute(
				array(
					'id'        => $post_id,
					'title.raw' => 'Nope',
				)
			),
			'A logged-out user should not update posts.'
		);

		wp_set_current_user( $this->user_ids['subscriber'] );
		$this->assert_wp_error(
			'ability_invalid_permissions',
			$ability->execute(
				array(
					'id'        => $post_id,
					'title.raw' => 'Nope',
				)
			),
			'A subscriber should not update posts.'
		);

		wp_set_current_user( $this->user_ids['author'] );
		$this->assert_wp_error(
			'ability_invalid_permissions',
			$ability->execute(
				array(
					'id'        => $post_id,
					'title.raw' => 'Nope',
				)
			),
			"An author should not update another user's post."
		);

		wp_set_current_user( $this->user_ids['editor'] );
		wp_get_current_user()->add_cap( 'edit_published_posts', false );
		$this->assert_wp_error( 'ability_invalid_permissions', $ability->execute( $this->post_data( array( 'id' => $post_id ) ) ), 'An editor without edit_published_posts should not update a published post.' );
	}

	/**
	 * A missing post is denied before execution; a direct call fails closed
	 * on it, as does a mismatched type guard.
	 */
	public function test_update_missing_post_fails_closed(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );
		$post_id = $this->make_post();
		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$this->assert_wp_error( 'ability_invalid_permissions', $ability->execute( $this->post_data( array( 'id' => self::IMPOSSIBLE_ID ) ) ), 'A missing post should be denied.' );

		$direct = $this->invoke_private( $ability, 'execute_content_update', array( 'id' => self::IMPOSSIBLE_ID ) );
		$this->assert_wp_error( 'content_not_found', $direct );
		self::assertSame( array( 'status' => 404 ), $direct->get_error_data() );

		$mismatched = $ability->execute(
			$this->post_data(
				array(
					'id'   => $post_id,
					'type' => 'page',
				)
			)
		);
		$this->assert_wp_error( 'ability_invalid_permissions', $mismatched, 'A mismatched type guard should deny the update.' );

		$direct_mismatched = $this->invoke_private(
			$ability,
			'execute_content_update',
			array(
				'id'   => $post_id,
				'type' => 'page',
			)
		);
		$this->assert_wp_error( 'content_not_found', $direct_mismatched );

		$matching = $ability->execute(
			$this->post_data(
				array(
					'id'        => $post_id,
					'type'      => 'post',
					'title.raw' => 'Guarded update',
				)
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $matching );
		self::assertSame( 'Guarded update', get_post( $post_id )->post_title );
	}

	/**
	 * A user who may edit a post but not others' posts cannot change its
	 * author, but may send the current author back, in any case variant the
	 * collation matches; a refused update writes nothing.
	 */
	public function test_update_author_change_requires_edit_others(): void {
		$this->create_users();
		$author_id                           = $this->user_ids['author'];
		$current                             = get_userdata( $this->user_ids['author2'] )->user_nicename;
		$post_id                             = (int) wp_insert_post(
			array(
				'post_title'  => 'Shared draft',
				'post_status' => 'draft',
				'post_author' => $this->user_ids['author2'],
			)
		);
		$this->post_ids[ "post-{$post_id}" ] = $post_id;

		// A co-author may edit this one post only, as a co-authors plugin would grant.
		$grant_edit = static function ( array $caps, string $cap, int $user_id, array $args ) use ( $author_id, $post_id ): array {
			return 'edit_post' === $cap && $author_id === $user_id && (int) ( $args[0] ?? 0 ) === $post_id ? array( 'edit_posts' ) : $caps;
		};
		add_filter( 'map_meta_cap', $grant_edit, 10, 4 );
		wp_set_current_user( $author_id );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$back = $ability->execute(
			array(
				'id'          => $post_id,
				'title.raw'   => 'Edited by a co-author',
				'author.slug' => strtoupper( $current ),
				'fields'      => array( 'id', 'title.raw' ),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $back, 'Sending the current author back, in capitals, should keep the author.' );
		self::assertSame( 'Edited by a co-author', get_post( $post_id )->post_title, 'The rest of the update should be written.' );
		self::assertSame( (string) $this->user_ids['author2'], get_post( $post_id )->post_author );

		$change = $ability->execute(
			array(
				'id'          => $post_id,
				'title.raw'   => 'Renamed',
				'author.slug' => get_userdata( $this->user_ids['editor'] )->user_nicename,
			)
		);
		$this->assert_wp_error( 'content_cannot_edit_others', $change );
		self::assertSame( array( 'status' => 403 ), $change->get_error_data() );
		self::assertSame( 'Edited by a co-author', get_post( $post_id )->post_title, 'A refused update should write nothing.' );
		self::assertSame( (string) $this->user_ids['author2'], get_post( $post_id )->post_author, 'A refused update should keep the author.' );
	}

	/**
	 * Publishing, or making a post private, requires the publish capability
	 * on top of edit access; sending the post's current status back does not.
	 */
	public function test_update_status_capability_gates(): void {
		$this->create_users();

		// A contributor cannot publish their own draft.
		$contributor_id = $this->user_ids['contributor'];
		wp_set_current_user( $contributor_id );
		$draft_id                             = (int) wp_insert_post(
			array(
				'post_title'  => 'Contributor draft',
				'post_status' => 'draft',
				'post_author' => $contributor_id,
			)
		);
		$this->post_ids[ "post-{$draft_id}" ] = $draft_id;

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$publish = $ability->execute(
			array(
				'id'     => $draft_id,
				'status' => 'publish',
			)
		);
		$this->assert_wp_error( 'content_cannot_publish', $publish );
		self::assertSame( array( 'status' => 403 ), $publish->get_error_data() );

		$same_draft = $ability->execute(
			array(
				'id'     => $draft_id,
				'status' => 'draft',
				'fields' => array( 'id', 'status' ),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $same_draft, 'Sending the current status back should need no publish capability.' );
		self::assertSame( 'draft', $same_draft['status'] );

		// An editor stripped of publish_posts may still keep a private post private.
		wp_set_current_user( $this->user_ids['editor'] );
		wp_get_current_user()->add_cap( 'publish_posts', false );
		$private_id                             = (int) wp_insert_post(
			array(
				'post_title'  => 'Editor private',
				'post_status' => 'private',
				'post_author' => $this->user_ids['editor'],
			)
		);
		$this->post_ids[ "post-{$private_id}" ] = $private_id;

		$kept = $ability->execute(
			array(
				'id'     => $private_id,
				'status' => 'private',
				'fields' => array( 'id', 'status' ),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $kept, 'Sending the current status back should need no publish capability.' );
		self::assertSame( 'private', $kept['status'] );

		$made_private = $ability->execute(
			array(
				'id'     => $this->make_post(),
				'status' => 'private',
			)
		);
		$this->assert_wp_error( 'content_cannot_publish', $made_private, 'Making a post private should require the publish capability.' );
	}

	/**
	 * Fields the post type does not support are rejected, and a rejected
	 * update writes nothing.
	 */
	public function test_update_rejects_unsupported_fields(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );
		$post_id = $this->make_post();

		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$result = $ability->execute(
			array(
				'id'        => $post_id,
				'parent'    => 0,
				'title.raw' => 'Not applied',
			)
		);

		$this->assert_wp_error( 'content_invalid_field', $result, 'A parent should be rejected for a post.' );
		self::assertSame( array( 'parent' => $result->get_error_message() ), $result->get_error_data()['params'] );
		self::assertSame( 'Original title', get_post( $post_id )->post_title, 'A rejected update should write nothing.' );
	}

	/**
	 * A page parent of 0 moves a page to the top level; a parent that makes
	 * a loop, or one of another type, is rejected.
	 */
	public function test_update_page_parent_contract(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$parent_id      = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Parent',
				'post_status' => 'publish',
			)
		);
		$page_id        = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Unchanged',
				'post_status' => 'publish',
				'post_parent' => $parent_id,
			)
		);
		$child_id       = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Child',
				'post_status' => 'publish',
				'post_parent' => $page_id,
			)
		);
		$grand_id       = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Grandchild',
				'post_status' => 'publish',
				'post_parent' => $child_id,
			)
		);
		$this->post_ids = array(
			'parent' => $parent_id,
			'page'   => $page_id,
			'child'  => $child_id,
			'grand'  => $grand_id,
		);

		$top = $ability->execute(
			array(
				'id'     => $page_id,
				'parent' => 0,
				'fields' => array( 'id', 'parent' ),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $top );
		self::assertSame( 0, $top['parent'], 'A parent of 0 should move the page to the top level.' );

		// Restore the hierarchy for the loop cases.
		wp_update_post(
			array(
				'ID'          => $page_id,
				'post_parent' => $parent_id,
			)
		);

		foreach ( array( $page_id, $child_id, $grand_id ) as $loop_parent ) {
			$result = $ability->execute(
				array(
					'id'        => $page_id,
					'title.raw' => 'Changed',
					'parent'    => $loop_parent,
				)
			);
			$this->assert_wp_error( 'content_invalid_field', $result, 'A parent that makes a loop should be rejected.' );
			self::assertSame( array( 'parent' => $result->get_error_message() ), $result->get_error_data()['params'] );
			self::assertSame( 'Unchanged', get_post( $page_id )->post_title, 'A rejected update should leave the whole page unchanged.' );
		}

		$post_id = $this->make_post();
		$invalid = $ability->execute(
			array(
				'id'     => $page_id,
				'parent' => $post_id,
			)
		);
		$this->assert_wp_error( 'content_invalid_field', $invalid, 'A parent of another post type should be rejected.' );
	}
}
