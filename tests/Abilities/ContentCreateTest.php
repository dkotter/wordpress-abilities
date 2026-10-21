<?php
/**
 * Tests: `core/content-create` (port of WordPress/ai PR #1025).
 *
 * Runs under both wp-env matrix environments. Unlike `core/content-query`,
 * which core itself provides on trunk, the write trio is a filed proposal
 * (not landed core), so the package copy registers in both environments and
 * its provenance is asserted unconditionally.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use ReflectionClass;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_Abilities_Registry;
use WordPress\Abilities\Manifest;
use WordPress\Abilities\Registrar;
use WordPress\Abilities\Support\Features;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Per-ability contract tests for `core/content-create`.
 */
final class ContentCreateTest extends TestCase {

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
	 * Cached IDs of the created posts, keyed by fixture name.
	 *
	 * @var array<string, int>
	 */
	private array $post_ids = array();

	/**
	 * Post types registered for one test, unregistered again in tear_down().
	 *
	 * @var list<string>
	 */
	private array $registered_post_types = array();

	/**
	 * Fires the API init action once, then runs the Registrar post-init, so
	 * tests read a settled registry, and loads the show_in_abilities polyfill
	 * when the host core predates the feature.
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
	 * Removes the mocked posts, test post types, and users, so each test
	 * builds a fresh data set.
	 */
	public function tear_down(): void {
		foreach ( $this->post_ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( $this->registered_post_types as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				unregister_post_type( $post_type );
			}
		}
		if ( ! empty( $this->user_ids ) && ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->user_ids as $id ) {
			if ( (int) $id > 1 ) {
				wp_delete_user( (int) $id );
			}
		}
		$this->user_ids              = array();
		$this->post_ids              = array();
		$this->registered_post_types = array();
		$GLOBALS['wp_post_statuses'] = array_diff_key( $GLOBALS['wp_post_statuses'] ?? array(), array( 'ccw_public' => true ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Removing a status this test registered.

		update_option( 'timezone_string', '' );

		parent::tear_down();
	}

	/**
	 * The registered `core/content-create` instance, or null.
	 */
	private function get_registered(): ?object {
		return WP_Abilities_Registry::get_instance()->get_registered( 'core/content-create' );
	}

	/**
	 * Invokes a private instance method on the registered ability copy.
	 */
	private function invoke_private( object $ability, string $method, ...$args ) {
		$reflected = new ReflectionClass( $ability );
		$method    = $reflected->getMethod( $method );
		$method->setAccessible( true );

		return $method->invoke( $ability, ...$args );
	}

	/**
	 * Executes the underlying create callback directly (the unvalidated path).
	 */
	private function execute_raw( object $ability, $input ) {
		return $this->invoke_private( $ability, 'execute_content_create', $input );
	}

	/**
	 * Asserts a WP_Error with the exact code, and optional message/data.
	 */
	private function assert_wp_error( string $code, $actual, ?string $message = null, $data = null ): void {
		self::assertInstanceOf( WP_Error::class, $actual, $message ?? "Expected WP_Error {$code}." );
		self::assertSame( $code, $actual->get_error_code(), $message ?? "Expected error code {$code}." );
		if ( null !== $data ) {
			self::assertSame( $data, $actual->get_error_data( $code ) );
		}
	}

	/**
	 * Creates the mocked users once.
	 */
	private function create_users(): void {
		if ( ! empty( $this->user_ids ) ) {
			return;
		}

		$this->user_ids['administrator'] = (int) wp_insert_user(
			array(
				'user_login' => 'ccwadministrator',
				'role'       => 'administrator',
			)
		);
		$this->user_ids['editor']        = (int) wp_insert_user(
			array(
				'user_login' => 'ccweditor',
				'role'       => 'editor',
			)
		);
		$this->user_ids['author']        = (int) wp_insert_user(
			array(
				'user_login' => 'ccwauthor',
				'role'       => 'author',
			)
		);
		$this->user_ids['contributor']   = (int) wp_insert_user(
			array(
				'user_login' => 'ccwcontributor',
				'role'       => 'contributor',
			)
		);
		$this->user_ids['subscriber']    = (int) wp_insert_user(
			array(
				'user_login' => 'ccwsubscriber',
				'role'       => 'subscriber',
			)
		);
	}

	/**
	 * Creates a post fixture and records its ID for teardown.
	 */
	private function make_post( array $args ): int {
		$id                             = (int) wp_insert_post( $args );
		$this->post_ids[ "post-{$id}" ] = $id;

		return $id;
	}

	/**
	 * Builds a standard create input, with overrides.
	 *
	 * @param array<string, mixed> $overrides Input values to override or add.
	 * @return array<string, mixed> The ability input.
	 */
	private function post_data( array $overrides = array() ): array {
		return array_merge(
			array(
				'type'        => 'post',
				'title.raw'   => "Rob O'Rourke's Diary",
				'content.raw' => 'Post content',
				'excerpt.raw' => 'Post excerpt',
				'status'      => 'publish',
				'fields'      => array(
					'id',
					'type',
					'status',
					'date',
					'date.local',
					'date.gmt',
					'slug',
					'link',
					'title.raw',
					'title.rendered',
					'content.raw',
					'content.rendered',
					'excerpt.raw',
					'author.slug',
				),
			),
			$overrides
		);
	}

	/**
	 * Asserts that no post, in any status, has the given title.
	 */
	private function assert_no_post_titled( string $title, string $message ): void {
		$query = new WP_Query(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
				'title'       => $title,
				'fields'      => 'ids',
			)
		);

		self::assertSame( array(), $query->posts, $message );
	}

	/**
	 * Registered with the PR's meta and schemas: a closed-world, non-destructive,
	 * non-idempotent write whose input names the writable fields by the dotted
	 * paths `core/content-query` returns.
	 */
	public function test_registered_meta_and_schema(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$meta = $ability->get_meta();

		self::assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
				'open_world'  => false,
			),
			$meta['annotations'] ?? null
		);
		self::assertTrue( (bool) ( $meta['public'] ?? false ) );
		self::assertTrue( (bool) ( $meta['show_in_rest'] ?? false ) );
		self::assertSame( 'wordpress/abilities', $meta['source'] ?? null, 'The package copy provides the trio in both environments.' );
		self::assertSame( Manifest::TIER_STABLE, $meta['stability'] ?? null );

		if ( method_exists( $ability, 'get_category' ) ) {
			self::assertSame( 'content', $ability->get_category() );
		}

		if ( method_exists( $ability, 'get_required_capabilities' ) ) {
			self::assertSame( array(), $ability->get_required_capabilities() );
		} else {
			self::assertSame( array(), ( new ReflectionClass( $ability ) )->getMethod( 'required_capabilities' )->invoke( $ability ) );
		}

		$schema = $ability->get_input_schema();
		self::assertSame( 'object', $schema['type'] );
		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( array( 'type' ), $schema['required'] );
		self::assertSame(
			array( 'type', 'title.raw', 'content.raw', 'excerpt.raw', 'status', 'slug', 'date.local', 'date.gmt', 'author.slug', 'parent', 'fields' ),
			array_keys( $schema['properties'] )
		);
		self::assertSame( array( 'post', 'page' ), $schema['properties']['type']['enum'], 'Only exposed post types are accepted.' );
		self::assertSame( array_values( get_post_stati( array( 'internal' => false ) ) ), $schema['properties']['status']['enum'] );
		self::assertSame( 1, $schema['properties']['author.slug']['minLength'] );
		self::assertSame( 0, $schema['properties']['parent']['minimum'] );
		self::assertSame(
			array(
				'id',
				'type',
				'status',
				'date',
				'date.local',
				'date.gmt',
				'modified',
				'modified.local',
				'modified.gmt',
				'slug',
				'link',
				'title',
				'title.raw',
				'title.rendered',
				'excerpt',
				'excerpt.raw',
				'excerpt.rendered',
				'excerpt.protected',
				'content',
				'content.raw',
				'content.rendered',
				'content.protected',
				'author',
				'author.id',
				'author.name',
				'author.slug',
				'parent',
			),
			$schema['properties']['fields']['items']['enum'],
			'The fields enum matches core/content-query, in get_field_paths() order.'
		);

		$output = $ability->get_output_schema();
		self::assertSame( 'object', $output['type'] );
		self::assertSame( array( 'id' ), $output['required'] );
		self::assertSame(
			array( 'id', 'type', 'status', 'date', 'modified', 'slug', 'link', 'title', 'excerpt', 'content', 'author', 'parent' ),
			array_keys( $output['properties'] )
		);
	}

	/**
	 * An editor can create a published post: the raw inputs land in the post,
	 * and the dotted projection returns the nested output shape.
	 */
	public function test_create_item(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$data   = $this->post_data();
		$result = $ability->execute( $data );

		self::assertNotInstanceOf( WP_Error::class, $result );
		self::assertSame( 'post', $result['type'] );
		self::assertSame( 'publish', $result['status'] );
		self::assertSame( "Rob O'Rourke's Diary", $result['title']['raw'], 'The raw title should match the input.' );
		// get_the_title() runs wptexturize + convert_chars, so the rendered title carries HTML entities for the curly quotes.
		self::assertSame( 'Rob O&#8217;Rourke&#8217;s Diary', $result['title']['rendered'], 'The rendered title is display-filtered.' );
		self::assertSame( "<p>Post content</p>\n", $result['content']['rendered'], 'The rendered content should be wpautop output.' );
		self::assertSame( 'Post excerpt', $result['excerpt']['raw'] );
		self::assertSame( 'rob-orourkes-diary', $result['slug'], 'The slug should be generated from the title.' );
		self::assertSame( (string) get_permalink( $result['id'] ), $result['link'] );
		self::assertStringEndsWith( '+00:00', (string) $result['date']['local'] );
		self::assertStringEndsWith( '+00:00', (string) $result['date']['gmt'] );

		$post = get_post( $result['id'] );
		self::assertInstanceOf( WP_Post::class, $post );
		self::assertSame( "Rob O'Rourke's Diary", $post->post_title, 'Quotes survive the slashing round trip.' );
		self::assertSame( 'Post content', $post->post_content );
		self::assertSame( 'Post excerpt', $post->post_excerpt );
		self::assertSame( 'publish', $post->post_status );
	}

	/**
	 * Without a field selection, the created post is returned with the lean
	 * default fields of `core/content-query`, always including the ID.
	 */
	public function test_create_returns_lean_default_fields(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$data = $this->post_data();
		unset( $data['fields'] );
		$result = $ability->execute( $data );

		self::assertNotInstanceOf( WP_Error::class, $result );
		self::assertSame( array( 'id', 'type', 'status', 'date', 'slug', 'title' ), array_keys( $result ), 'The default field set matches core/content-query.' );
		self::assertSame( 'publish', $result['status'] );
		self::assertArrayHasKey( 'rendered', $result['title'] );
		self::assertArrayNotHasKey( 'raw', $result['title'], 'The default fields carry no edit-context raw sub-fields.' );
		self::assertArrayHasKey( 'local', $result['date'] );
		self::assertArrayHasKey( 'gmt', $result['date'] );
	}

	/**
	 * Logged-out users and roles without the create capability are denied.
	 */
	public function test_create_permission_denials(): void {
		$this->create_users();
		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		wp_set_current_user( 0 );
		$this->assert_wp_error( 'ability_invalid_permissions', $ability->execute( $this->post_data( array( 'status' => 'draft' ) ) ), 'A logged-out user should not create posts.' );

		wp_set_current_user( $this->user_ids['subscriber'] );
		$this->assert_wp_error( 'ability_invalid_permissions', $ability->execute( $this->post_data( array( 'status' => 'draft' ) ) ), 'A subscriber should not create posts.' );
	}

	/**
	 * A status outside the registered non-internal statuses fails validation.
	 */
	public function test_create_invalid_status_rejected(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$this->assert_wp_error( 'ability_invalid_input', $ability->execute( $this->post_data( array( 'status' => 'no-such-status' ) ) ), 'An unknown status should fail validation.' );
	}

	/**
	 * An author slug that names no user is rejected with a params map naming
	 * the field; malformed slugs fail validation. A user who may only name
	 * themselves is refused without learning whether the slug exists.
	 */
	public function test_create_author_slug_contract(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$empty = $ability->execute( $this->post_data( array( 'author.slug' => '' ) ) );
		$this->assert_wp_error( 'ability_invalid_input', $empty, 'An empty author slug should fail validation.' );

		$user_id = $ability->execute( $this->post_data( array( 'author.slug' => $this->user_ids['author'] ) ) );
		$this->assert_wp_error( 'ability_invalid_input', $user_id, 'A user ID should fail validation.' );

		$missing = $ability->execute( $this->post_data( array( 'author.slug' => 'no-such-user' ) ) );
		$this->assert_wp_error( 'content_invalid_field', $missing, 'A slug that names no user should be rejected.' );
		self::assertSame(
			array( 'author.slug' => $missing->get_error_message() ),
			$missing->get_error_data()['params'],
			'The error data should map the author.slug field to the message.'
		);

		wp_set_current_user( $this->user_ids['author'] );
		$refused = $ability->execute(
			$this->post_data(
				array(
					'title.raw'   => 'Refused for another author',
					'author.slug' => 'no-such-user',
				)
			)
		);
		$this->assert_wp_error( 'content_cannot_edit_others', $refused, 'An unknown slug should be refused like any other user\'s.' );
		self::assertSame( array( 'status' => 403 ), $refused->get_error_data() );
		$this->assert_no_post_titled( 'Refused for another author', 'A refused create should write nothing.' );
	}

	/**
	 * An editor can create a post as another user.
	 */
	public function test_create_as_other_author_with_permission(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$author_slug = get_userdata( $this->user_ids['author'] )->user_nicename;
		$result      = $ability->execute(
			$this->post_data(
				array(
					'author.slug' => $author_slug,
					'fields'      => array( 'id', 'author.slug' ),
				)
			)
		);

		self::assertNotInstanceOf( WP_Error::class, $result );
		self::assertSame( $author_slug, $result['author']['slug'] );
		self::assertSame( $this->user_ids['author'], (int) get_post( $result['id'] )->post_author, 'The post should belong to the given author.' );
	}

	/**
	 * Publishing and making posts private require the publish capability, as
	 * does giving a post any other public or publicly viewable status.
	 */
	public function test_create_publish_capability_gate(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['author'] );
		wp_get_current_user()->add_cap( 'publish_posts', false );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$publish = $ability->execute(
			$this->post_data(
				array(
					'status'    => 'publish',
					'title.raw' => 'Refused publish',
				)
			)
		);
		$this->assert_wp_error( 'content_cannot_publish', $publish, 'Publishing without the capability should fail.' );
		self::assertSame( array( 'status' => 403 ), $publish->get_error_data() );

		$private = $ability->execute(
			$this->post_data(
				array(
					'status'    => 'private',
					'title.raw' => 'Refused private',
				)
			)
		);
		$this->assert_wp_error( 'content_cannot_publish', $private, 'Making a post private without the capability should fail.' );
		self::assertSame( array( 'status' => 403 ), $private->get_error_data() );

		// A public custom status is gated the same way (direct path: the status
		// postdates the frozen input schema, so engine validation cannot reach it).
		register_post_status(
			'ccw_public',
			array(
				'label'  => 'CCW Public',
				'public' => true,
			)
		);
		$custom = $this->execute_raw(
			$ability,
			$this->post_data(
				array(
					'status'    => 'ccw_public',
					'title.raw' => 'Refused custom',
				)
			)
		);
		$this->assert_wp_error( 'content_cannot_publish', $custom, 'A public custom status should need the publish capability.' );
		self::assertSame( array( 'status' => 403 ), $custom->get_error_data() );

		$this->assert_no_post_titled( 'Refused publish', 'A refused create should write nothing.' );
		$this->assert_no_post_titled( 'Refused private', 'A refused create should write nothing.' );
		$this->assert_no_post_titled( 'Refused custom', 'A refused create should write nothing.' );
	}

	/**
	 * Fields the post type does not support are rejected instead of being
	 * ignored, and a rejected create writes nothing.
	 *
	 * The cases run through the direct execute callback: the test post types
	 * postdate the frozen input schema, so the type enum would reject them
	 * before the field check. Each case is labeled for readable failures.
	 */
	public function test_create_rejects_unsupported_fields(): void {
		$cases = array(
			'parent on a post'                => array( 'post', 'parent', 0 ),
			'excerpt on a page'               => array( 'page', 'excerpt.raw', 'Excerpt' ),
			'title without title support'     => array( 'ccw_editor_only', 'title.raw', 'Title' ),
			'content without editor support'  => array( 'ccw_title_only', 'content.raw', 'Content' ),
			'excerpt without excerpt support' => array( 'ccw_title_only', 'excerpt.raw', 'Excerpt' ),
			'author without author support'   => array( 'ccw_title_only', 'author.slug', 'ccweditor' ),
		);
		// phpcs:disable WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral -- Fixed test slugs.
		register_post_type(
			'ccw_title_only',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title' ),
			)
		);
		register_post_type(
			'ccw_editor_only',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'editor' ),
			)
		);
		// phpcs:enable

		$this->registered_post_types = array( 'ccw_title_only', 'ccw_editor_only' );

		$this->create_users();
		wp_set_current_user( $this->user_ids['administrator'] );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		foreach ( $cases as $label => $case ) {
			list( $post_type, $field, $value ) = $case;
			$result                            = $this->execute_raw(
				$ability,
				array(
					'type' => $post_type,
					$field => $value,
				)
			);

			$this->assert_wp_error( 'content_invalid_field', $result, "[{$label}] The {$field} field should be rejected for the {$post_type} post type." );
			self::assertStringContainsString( $field, $result->get_error_message(), "[{$label}] The error should name the field." );
			self::assertSame(
				array( $field => $result->get_error_message() ),
				$result->get_error_data()['params'],
				"[{$label}] The error data should map the unsupported field to the message."
			);

			$written = new WP_Query(
				array(
					'post_type'   => $post_type,
					'post_status' => 'any',
					'fields'      => 'ids',
				)
			);
			self::assertSame( array(), $written->posts, "[{$label}] A rejected create should write nothing." );
		}
	}

	/**
	 * An unsupported field is also rejected through the fully validated
	 * transport, for a standard post type the schema already lists.
	 */
	public function test_create_unsupported_field_via_validated_execute(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$result = $ability->execute(
			$this->post_data(
				array(
					'parent'    => 0,
					'title.raw' => 'Not applied',
				)
			)
		);
		$this->assert_wp_error( 'content_invalid_field', $result, 'The parent field should be rejected for a post.' );
		self::assertSame(
			array( 'parent' => $result->get_error_message() ),
			$result->get_error_data()['params'],
			'The error data should map the parent field to the message.'
		);
		$this->assert_no_post_titled( 'Not applied', 'A rejected create should write nothing.' );
	}

	/**
	 * A page can be created under a parent page or at the top level with
	 * parent 0, and an invalid parent is rejected with a params map.
	 */
	public function test_create_page_with_parent(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$parent_id = $this->make_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Parent page',
				'post_status' => 'publish',
			)
		);

		$child = $ability->execute(
			array(
				'type'      => 'page',
				'title.raw' => 'Child page',
				'parent'    => $parent_id,
				'fields'    => array( 'id', 'parent' ),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $child );
		self::assertSame( $parent_id, $child['parent'] );
		self::assertSame( $parent_id, (int) get_post( $child['id'] )->post_parent );

		$top = $ability->execute(
			array(
				'type'      => 'page',
				'title.raw' => 'Top-level page',
				'parent'    => 0,
				'fields'    => array( 'id', 'parent' ),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $top );
		self::assertSame( 0, $top['parent'], 'A zero parent creates a top-level page.' );

		$missing = $ability->execute(
			array(
				'type'      => 'page',
				'title.raw' => 'Page with an invalid parent',
				'parent'    => self::IMPOSSIBLE_ID,
			)
		);
		$this->assert_wp_error( 'content_invalid_field', $missing, 'A missing parent should be rejected.' );
		self::assertSame( array( 'parent' => $missing->get_error_message() ), $missing->get_error_data()['params'] );

		$other_type = $this->make_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Not a page',
				'post_status' => 'publish',
			)
		);
		$mismatched = $ability->execute(
			array(
				'type'      => 'page',
				'title.raw' => 'Page under a post',
				'parent'    => $other_type,
			)
		);
		$this->assert_wp_error( 'content_invalid_field', $mismatched, 'A parent of another post type should be rejected.' );
		self::assertSame( array( 'parent' => $mismatched->get_error_message() ), $mismatched->get_error_data()['params'] );

		$this->assert_no_post_titled( 'Page with an invalid parent', 'A rejected create should write nothing.' );
		$this->assert_no_post_titled( 'Page under a post', 'A rejected create should write nothing.' );
	}

	/**
	 * Dates are stored in the site timezone with their GMT counterpart,
	 * whether given as local or as GMT.
	 */
	public function test_create_dates(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );
		update_option( 'timezone_string', 'America/New_York' );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$local = $ability->execute(
			$this->post_data(
				array(
					'date.local' => '2016-12-12T14:00:00',
					'fields'     => array( 'id', 'date.local', 'date.gmt' ),
				)
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $local );
		$post = get_post( $local['id'] );
		self::assertSame( '2016-12-12 14:00:00', $post->post_date, 'The stored local date should match.' );
		self::assertSame( '2016-12-12 19:00:00', $post->post_date_gmt, 'The stored GMT date should match.' );
		self::assertSame( '2016-12-12T14:00:00-05:00', $local['date']['local'], 'The returned local date should carry the site offset.' );
		self::assertSame( '2016-12-12T19:00:00+00:00', $local['date']['gmt'], 'The returned GMT date should be the UTC instant.' );

		$gmt = $ability->execute(
			$this->post_data(
				array(
					'date.gmt' => '2016-12-12T19:00:00',
					'fields'   => array( 'id', 'date.local', 'date.gmt' ),
				)
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $gmt );
		$post = get_post( $gmt['id'] );
		self::assertSame( '2016-12-12 14:00:00', $post->post_date );
		self::assertSame( '2016-12-12 19:00:00', $post->post_date_gmt );
	}

	/**
	 * A draft is created without a stored GMT date, whose output value is
	 * still derived, and its colliding slug is made unique.
	 */
	public function test_create_draft_slug_and_floating_gmt(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$taken = $this->make_post(
			array(
				'post_title'  => 'Sample',
				'post_status' => 'publish',
				'post_name'   => 'sample-slug',
			)
		);
		self::assertGreaterThan( 0, $taken );

		$draft = $ability->execute(
			$this->post_data(
				array(
					'status' => 'draft',
					'slug'   => 'sample-slug',
				)
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $draft );
		self::assertSame( 'draft', $draft['status'] );
		self::assertSame( 'sample-slug-2', $draft['slug'], 'The draft slug should be made unique.' );

		$post = get_post( $draft['id'] );
		self::assertSame( '0000-00-00 00:00:00', $post->post_date_gmt, 'A draft should have a floating GMT date.' );
		self::assertNotSame( '', $draft['date']['gmt'], 'The returned GMT date should be derived from the local date.' );
		self::assertStringEndsWith( '+00:00', (string) $draft['date']['gmt'], 'The derived GMT date should be reported as UTC.' );
	}
}
