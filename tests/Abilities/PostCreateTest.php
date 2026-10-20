<?php
/**
 * Tests: the experimental `wp/post-create` write ability (HANDOFF §8.2, §10.3; D11).
 *
 * Written test-first (WP4, TDD): every permission-negative gate path asserts
 * against `WP_Ability::check_permissions()` directly, before the schema gets a
 * chance to reject input, so fail-closed behavior is verified at the permission
 * layer; the end-to-end gate paths additionally run through `WP_Ability::execute()`.
 *
 * Suite-state note: each test class runs inside one WP boot in discovery
 * order, and RegistrarTest's first test asserts that `wp_abilities_api_init`
 * has not fired yet. This file therefore MUST NOT fire the registry init;
 * its registration assertions are order-adaptive (the realized registry path
 * only asserts when the action has already fired, e.g. when suite discovery
 * places this file after RegistrarTest). This file runs BEFORE RegistrarTest
 * in the current discovery order, so its deferred branch is the one normally
 * exercised; the realized registration-with-provenance path for this same
 * manifest name is asserted by RegistrarTest::test_positive_registration_
 * and_provenance_meta(), which runs with the real class once the fixture
 * double drops (tests/bootstrap.php self-removal).
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use WordPress\Abilities\Content\Post_Create;
use WordPress\Abilities\Manifest;
use WordPress\Abilities\Registrar;
use WP_Abilities_Registry;
use WP_Error;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Contract tests for the wp/post-create ability.
 */
final class PostCreateTest extends TestCase {

	/**
	 * The ability under test.
	 *
	 * @var Post_Create
	 */
	private $ability;

	/**
	 * Test users created once for the whole class, keyed by purpose.
	 *
	 * @var array<string, int>
	 */
	private static $users = array();

	/**
	 * Creates the shared fixture users and the post-type-specific fixture.
	 */
	public static function set_up_before_class(): void {
		self::$users['author']      = self::create_user( 'author' );
		self::$users['contributor'] = self::create_user( 'contributor' );

		register_post_type(
			'wp4book',
			array(
				'public'             => true,
				'publicly_queryable' => false,
				'map_meta_cap'       => true,
				'capability_type'    => 'book',
				/*
				 * Keep the fixture out of content-query surfaces: explicit false
				 * is safe on both envs (on WP < 7.2 the arg is simply unknown).
				 */
				'show_in_abilities'  => false,
			)
		);
	}

	/**
	 * Resets the current user so no test leaks identity into later classes.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
	}

	/**
	 * Inserts a test user and records the ID.
	 *
	 * A plain Yoast TestCase has no WP_UnitTestCase factory; wp_insert_user()
	 * itself is the mechanism. Fresh logins per class avoid cross-class state.
	 *
	 * @param string $role The user role (WP default roles).
	 * @return int The user ID.
	 */
	private static function create_user( string $role ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'postcreate_' . $role,
				'user_pass'  => 'postcreate-password',
				'user_email' => 'postcreate_' . $role . '@example.org',
				'role'       => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			self::fail( 'User fixture could not be created: ' . $user_id->get_error_message() );
		}

		return (int) $user_id;
	}

	/**
	 * Sets the current user from a shared fixture (0 ⇔ nobody).
	 *
	 * @param string|null $who The fixture key, or null for the anonymous state.
	 */
	private function act_as( ?string $who ): void {
		wp_set_current_user( $who ? self::$users[ $who ] : 0 );
	}

	/**
	 * §8.2 schema: input object with post_type + title required, content,
	 * excerpt, status and slug optional, no meta input, no other keys.
	 */
	public function test_input_schema_shape(): void {
		$this->boot_ability();
		$schema = $this->ability->get_input_schema();

		self::assertSame( 'object', $schema['type'] );
		self::assertSame( array( 'post_type', 'title' ), $schema['required'], 'post_type and title are required.' );
		self::assertTrue( false === $schema['additionalProperties'], 'No meta or other unlisted input is accepted.' );

		foreach ( array( 'post_type', 'title', 'content', 'excerpt', 'status', 'slug' ) as $key ) {
			self::assertArrayHasKey( $key, $schema['properties'], "Property {$key} must be declared." );
			self::assertSame( 'string', $schema['properties'][ $key ]['type'] );
		}

		self::assertSame( array( 'draft', 'publish', 'pending' ), $schema['properties']['status']['enum'], 'Status allowlist.' );
		self::assertSame( 'draft', $schema['properties']['status']['default'] );
		self::assertArrayNotHasKey( 'meta', $schema['properties'], 'Meta input is deferred (risky surface).' );
		self::assertSame( array( 'post_type', 'title', 'content', 'excerpt', 'status', 'slug' ), array_keys( $schema['properties'] ) );
	}

	/**
	 * §8.2 schema: output is an object of id (integer), link (uri), status
	 * (draft/publish/pending), all required, nothing extra.
	 */
	public function test_output_schema_shape(): void {
		$this->boot_ability();
		$schema = $this->ability->get_output_schema();

		self::assertSame( 'object', $schema['type'] );
		self::assertSame( array( 'id', 'link', 'status' ), $schema['required'] );
		self::assertSame( 'integer', $schema['properties']['id']['type'] );
		self::assertSame( 'string', $schema['properties']['link']['type'] );
		self::assertSame( 'uri', $schema['properties']['link']['format'] );
		self::assertSame( array( 'draft', 'publish', 'pending' ), $schema['properties']['status']['enum'] );
		self::assertSame( array( 'id', 'link', 'status' ), array_keys( $schema['properties'] ) );
		self::assertTrue( false === $schema['additionalProperties'] );
	}

	/**
	 * Annotations and static wiring per §8.2: create is non-destructive,
	 * additive, non-idempotent; the request-level floor is edit_posts.
	 */
	public function test_annotations_and_static_wiring(): void {
		$this->boot_ability();

		self::assertSame( 'wp/post-create', $this->ability->get_name() );
		self::assertSame( 'content', $this->ability->get_category(), 'Manifest category slug.' );
		self::assertSame( array( 'edit_posts' ), $this->ability->get_required_capabilities(), 'The request-level capability floor.' );

		$meta = $this->ability->get_meta();
		self::assertFalse( $meta['annotations']['readonly'], 'Create modifies its environment.' );
		self::assertFalse( $meta['annotations']['destructive'], 'Create is additive only.' );
		self::assertFalse( $meta['annotations']['idempotent'], 'Two calls create two posts.' );
		self::assertFalse( $meta['public'], 'Experimental write ability is not exposed by default (opt-in via public_only).' );

		self::assertSame( 'wordpress/abilities', $meta['source'] ?? null, 'Provenance: source.' );
		self::assertSame( Manifest::VERSION, $meta['package_version'] ?? null, 'Provenance: package_version.' );
	}

	/**
	 * Registration contract: via the Registrar under the wp/ prefix with
	 * provenance meta, stability stamped from the manifest's experimental
	 * tier. Order-adaptive; see the class docblock for the no-init rule.
	 */
	public function test_registers_via_registrar_with_experimental_provenance(): void {
		// The manifest entry is what the Registrar turns into `stability` meta.
		$entry = Manifest::get( 'wp/post-create' );
		self::assertNotNull( $entry, 'The manifest must know wp/post-create.' );
		self::assertSame( Post_Create::class, $entry['class'] );
		self::assertSame( Manifest::TIER_EXPERIMENTAL, $entry['tier'] );

		if ( ! did_action( 'wp_abilities_api_init' ) ) {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				self::markTestSkipped( 'Abilities API absent in this run (WP_ABILITIES_TESTS_API=off).' );
			}

			// Deferred path: queue and record; do NOT fire the init action,
			// RegistrarTest owns that (its first test asserts did_action === 0).
			self::assertFalse( Registrar::register( 'wp/post-create' ), 'Pre-init registration defers.' );
			self::assertTrue( has_action( 'wp_abilities_api_init' ), 'A deferred registration hook is queued.' );

			return;
		}

		// The init action has already fired: assert the realized path.
		self::assertTrue( Registrar::register( 'wp/post-create' ), 'The ability registers.' );
		self::assertTrue( wp_has_ability( 'wp/post-create' ) );

		$registered = WP_Abilities_Registry::get_instance()->get_registered( 'wp/post-create' );
		self::assertNotNull( $registered );
		self::assertInstanceOf( Post_Create::class, $registered );
		$meta = $registered->get_meta();
		self::assertSame( Manifest::TIER_EXPERIMENTAL, $meta['stability'] ?? null, 'Stability = experimental tier.' );
		self::assertSame( 'wordpress/abilities', $meta['source'] ?? null );
		self::assertSame( Manifest::VERSION, $meta['package_version'] ?? null );
	}

	/**
	 * Gate (a): lacking edit_posts denies at the required_capabilities floor.
	 */
	public function test_denies_without_edit_posts(): void {
		$this->boot_ability();
		$this->act_as( null ); // No user at all.

		$input = array(
			'post_type' => 'post',
			'title'     => 'Denied draft',
		);
		self::assertFalse( $this->ability->check_permissions( $input ), 'Anonymous: the edit_posts floor denies.' );

		$result = $this->ability->execute( $input );
		self::assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// Same for a non-author user without edit_posts: a subscriber.
		$subscriber = self::create_user( 'subscriber' );
		wp_set_current_user( $subscriber );
		self::assertFalse( $this->ability->check_permissions( $input ), 'A subscriber lacks the edit_posts floor.' );
	}

	/**
	 * Gate (b): status publish without publish_posts denies, while a draft
	 * from the same user stays allowed.
	 */
	public function test_denies_publish_without_publish_posts(): void {
		$this->boot_ability();
		$this->act_as( 'contributor' );

		$input = array(
			'post_type' => 'post',
			'title'     => 'Denied publish',
			'status'    => 'publish',
		);
		self::assertFalse( $this->ability->check_permissions( $input ), 'Contributor lacks publish_posts.' );
		self::assertSame( 'ability_invalid_permissions', $this->ability->execute( $input )->get_error_code() );

		// The draft path stays open for a contributor; covered in the
		// positive test below.
	}

	/**
	 * Gate (c): a post-type-specific create capability denied denies, even
	 * though the global edit_posts floor and publish_posts are both present.
	 */
	public function test_denies_without_post_type_create_capability(): void {
		$this->boot_ability();
		$this->act_as( 'author' );

		$book = get_post_type_object( 'wp4book' );
		self::assertSame( 'edit_books', $book->cap->create_posts, 'The mapping core uses: create_posts of the type.' );

		$input = array(
			'post_type' => 'wp4book',
			'title'     => 'Denied book',
		);
		self::assertFalse( $this->ability->check_permissions( $input ), 'The author lacks edit_books.' );
		self::assertSame( 'ability_invalid_permissions', $this->ability->execute( $input )->get_error_code() );
	}

	/**
	 * Gate (c): the publish check maps into the type as well — publish_posts
	 * alone does not unlock wp4book, publish_books is required.
	 */
	public function test_denies_publish_without_post_type_publish_capability(): void {
		$this->boot_ability();
		$this->act_as( 'author' );

		$book = get_post_type_object( 'wp4book' );
		self::assertSame( 'publish_books', $book->cap->publish_posts, 'The mapped publish capability of the type.' );

		wp_get_current_user()->add_cap( 'edit_books' ); // Create passes now...
		$input = array(
			'post_type' => 'wp4book',
			'title'     => 'Denied book publish',
			'status'    => 'publish',
		);
		self::assertTrue(
			$this->ability->check_permissions(
				array(
					'post_type' => 'wp4book',
					'title'     => 'Book draft ok',
				)
			),
			'Create allowed with edit_books.'
		);
		self::assertFalse( $this->ability->check_permissions( $input ), 'But publish_books is missing.' );
	}

	/**
	 * Gate (c): a user holding the type-specific caps may create and publish.
	 */
	public function test_grants_create_and_publish_with_post_type_capabilities(): void {
		$this->boot_ability();
		$this->act_as( 'author' );

		wp_get_current_user()->add_cap( 'edit_books' );
		$user = wp_get_current_user();
		$user->add_cap( 'publish_books' );

		$created = $this->ability->execute(
			array(
				'post_type' => 'wp4book',
				'title'     => 'Allowed book',
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $created );
		$published = $this->ability->execute(
			array(
				'post_type' => 'wp4book',
				'title'     => 'Allowed book publish',
				'status'    => 'publish',
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $published );

		self::assertSame( 'publish', get_post_status( $published['id'] ), 'Published book created.' );
		self::assertSame( 'draft', get_post_status( $created['id'] ), 'Draft book created.' );
	}

	/**
	 * Gate (d): an unknown post type denies, fail-closed.
	 */
	public function test_denies_unknown_post_type(): void {
		$this->boot_ability();
		$this->act_as( 'author' );

		$input = array(
			'post_type' => 'wp4nope_type',
			'title'     => 'Nowhere',
		);
		self::assertFalse( $this->ability->check_permissions( $input ), 'Unregistered post type denied.' );
		self::assertFalse(
			$this->ability->check_permissions(
				array(
					'post_type' => array( 'post' ),
					'title'     => 'Not a string',
				)
			),
			'Non-string post type denied.'
		);
	}

	/**
	 * Gate (e): an unknown status denies, fail-closed — unknown names and
	 * registered-but-not-createable statuses alike.
	 */
	public function test_denies_unknown_status(): void {
		$this->boot_ability();
		$this->act_as( 'author' );

		self::assertFalse(
			$this->ability->check_permissions(
				array(
					'post_type' => 'post',
					'title'     => 'Mystery',
					'status'    => 'mystery',
				)
			),
			'Unknown status denied.'
		);
		self::assertFalse(
			$this->ability->check_permissions(
				array(
					'post_type' => 'post',
					'title'     => 'Inherit',
					'status'    => 'inherit',
				)
			),
			'Registered-but-internal status denied.'
		);
		self::assertFalse(
			$this->ability->check_permissions(
				array(
					'post_type' => 'post',
					'title'     => 'Trash',
					'status'    => 'trash',
				)
			),
			'Trash status denied.'
		);
		self::assertFalse(
			$this->ability->check_permissions(
				array(
					'post_type' => 'post',
					'title'     => 'Array',
					'status'    => array( 'draft' ),
				)
			),
			'Non-string status denied.'
		);
	}

	/**
	 * Gate (f): malformed input denies at the permission layer, fail-closed.
	 */
	public function test_denies_malformed_input(): void {
		$this->boot_ability();
		$this->act_as( 'author' );

		self::assertFalse( $this->ability->check_permissions( null ), 'Null input denied.' );
		self::assertFalse( $this->ability->check_permissions( 'garbage' ), 'Non-array input denied.' );
		self::assertFalse( $this->ability->check_permissions( array() ), 'Input without post_type denied.' );
		self::assertFalse( $this->ability->check_permissions( array( 'title' => 'No type' ) ), 'Post_type only is denied.' );
		self::assertFalse(
			$this->ability->check_permissions(
				array(
					'post_type' => 42,
					'title'     => 'No int',
				)
			),
			'Integer post_type denied.'
		);
		self::assertFalse(
			$this->ability->check_permissions(
				array(
					'post_type' => 'post',
					'title'     => array( 'Not string' ),
				)
			),
			'Array title denied.'
		);

		// The execute() path rejects the same shapes at the schema layer.
		self::assertSame( 'ability_invalid_input', $this->ability->execute( null )->get_error_code() );
		self::assertSame(
			'ability_invalid_input',
			$this->ability->execute(
				array(
					'post_type'  => 'post',
					'title'      => 'x',
					'unexpected' => 1,
				)
			)->get_error_code()
		);
	}

	/**
	 * Happy path: a draft for a user holding the edit_posts floor, with
	 * content, excerpt and authoring recorded.
	 */
	public function test_creates_draft_with_edit_posts(): void {
		$this->boot_ability();
		$this->act_as( 'author' );
		$user_id = self::$users['author'];

		$result = $this->ability->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Hello Abilities',
				'content'   => 'The first word.',
				'excerpt'   => 'The short version.',
			)
		);

		self::assertNotInstanceOf( WP_Error::class, $result, 'The create succeeded.' );
		$id = (int) $result['id'];
		self::assertTrue( $id > 0, 'A real post id was returned.' );

		$post = get_post( $id );
		self::assertSame( $user_id, (int) $post->post_author, 'The post is authored by the requesting user.' );
		self::assertSame( 'draft', $result['status'], 'Default status is draft.' );
		self::assertSame( 'Hello Abilities', $post->post_title );
		self::assertSame( 'The first word.', $post->post_content );
		self::assertSame( 'The short version.', $post->post_excerpt );

		self::assertSame( get_permalink( $id ), $result['link'], 'The link is the post permalink.' );
	}

	/**
	 * Happy path: a contributor (edit_posts only) may create a draft.
	 */
	public function test_contributor_creates_draft(): void {
		$this->boot_ability();
		$this->act_as( 'contributor' );

		$result = $this->ability->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Contributor draft',
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $result );

		self::assertSame( 'draft', $result['status'] );
		$id = (int) $result['id'];
		self::assertGreaterThan( 0, $id );
	}

	/**
	 * Happy path: publish allowed when the user holds publish_posts.
	 */
	public function test_publishes_with_publish_posts(): void {
		$this->boot_ability();
		$this->act_as( 'author' );

		$result = $this->ability->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Published via ability',
				'status'    => 'publish',
			)
		);

		self::assertNotInstanceOf( WP_Error::class, $result );
		$post = get_post( (int) $result['id'] );
		self::assertSame( 'publish', $post->post_status );
		self::assertSame( 'publish', $result['status'] );
		self::assertSame( get_permalink( $post->ID ), $result['link'] );
	}

	/**
	 * Happy path: the slug input lands on post_name.
	 */
	public function test_slug_is_applied(): void {
		$this->boot_ability();
		$this->act_as( 'author' );

		$result = $this->ability->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Slugged',
				'status'    => 'publish',
				'slug'      => 'from-the-ability',
			)
		);

		self::assertNotInstanceOf( WP_Error::class, $result );
		self::assertSame( 'from-the-ability', get_post_field( 'post_name', (int) $result['id'] ) );
	}

	/**
	 * Builds a capability instance for the real class (or skips when the API
	 * is absent, as in the API-off CI run where WP_Ability does not exist).
	 */
	private function boot_ability(): void {
		if ( null !== $this->ability ) {
			return;
		}
		if ( ! class_exists( 'WP_Ability' ) ) {
			self::markTestSkipped( 'The Abilities API (WP_Ability) is missing in this run.' );
		}
		$this->ability = new Post_Create();
	}
}
