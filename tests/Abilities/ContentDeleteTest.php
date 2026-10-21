<?php
/**
 * Tests: `core/content-delete` (port of WordPress/ai PR #1025).
 *
 * Runs under both wp-env matrix environments; the package copy registers in
 * both, so its provenance is asserted unconditionally.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use ReflectionClass;
use WP_Error;
use WP_Abilities_Registry;
use WordPress\Abilities\Manifest;
use WordPress\Abilities\Registrar;
use WordPress\Abilities\Support\Features;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Per-ability contract tests for `core/content-delete`.
 */
final class ContentDeleteTest extends TestCase {

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
	 * Removes the mocked posts and users, and restores the attachment
	 * exposure flag, so each test builds a fresh data set.
	 */
	public function tear_down(): void {
		$attachment_type = get_post_type_object( 'attachment' );
		if ( $attachment_type && ! empty( $attachment_type->show_in_abilities ) ) {
			$attachment_type->show_in_abilities = false;
		}
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
		remove_filter( 'pre_trash_post', '__return_false' );
		remove_filter( 'pre_delete_post', '__return_false' );
		$this->user_ids = array();
		$this->post_ids = array();

		parent::tear_down();
	}

	/**
	 * The registered `core/content-delete` instance, or null.
	 */
	private function get_registered(): ?object {
		return WP_Abilities_Registry::get_instance()->get_registered( 'core/content-delete' );
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
	 * Executes the underlying delete callback directly (the unvalidated path).
	 */
	private function execute_raw( object $ability, $input ) {
		return $this->invoke_private( $ability, 'execute_content_delete', $input );
	}

	/**
	 * Asserts a WP_Error with the exact code, and optional data.
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
				'user_login' => 'dcwadministrator',
				'role'       => 'administrator',
			)
		);
		$this->user_ids['editor']        = (int) wp_insert_user(
			array(
				'user_login' => 'dcweditor',
				'role'       => 'editor',
			)
		);
		$this->user_ids['author']        = (int) wp_insert_user(
			array(
				'user_login' => 'dcwauthor',
				'role'       => 'author',
			)
		);
		$this->user_ids['subscriber']    = (int) wp_insert_user(
			array(
				'user_login' => 'dcwsubscriber',
				'role'       => 'subscriber',
			)
		);
	}

	/**
	 * Creates a published post fixture owned by the editor and records its ID.
	 */
	private function make_post( array $overrides = array() ): int {
		$post = wp_insert_post(
			array_merge(
				array(
					'post_type'    => 'post',
					'post_title'   => 'To be deleted',
					'post_content' => 'Post content',
					'post_excerpt' => 'Post excerpt',
					'post_status'  => 'publish',
					'post_author'  => $this->user_ids['editor'],
				),
				$overrides
			),
			true
		);
		if ( is_wp_error( $post ) ) {
			throw new \RuntimeException( $post->get_error_message() );
		}
		$this->post_ids[ "post-{$post}" ] = (int) $post;

		return (int) $post;
	}

	/**
	 * Registered with the PR's meta and schemas: a closed-world, destructive,
	 * idempotent write whose input only needs an ID, an optional type guard,
	 * the force flag, and the fields projection.
	 */
	public function test_registered_meta_and_schema(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$meta = $ability->get_meta();

		self::assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
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
		self::assertSame( array( 'id' ), $schema['required'] );
		self::assertSame( array( 'id', 'type', 'force', 'fields' ), array_keys( $schema['properties'] ) );
		self::assertSame( 1, $schema['properties']['id']['minimum'] );
		self::assertSame( array( 'post', 'page' ), $schema['properties']['type']['enum'], 'Only exposed post types may guard the lookup.' );
		self::assertSame( 'boolean', $schema['properties']['force']['type'] );
	}

	/**
	 * Deleting without force moves the post to the trash, and the response
	 * reports the resulting status.
	 */
	public function test_delete_trashes_by_default(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );
		$post_id = $this->make_post();

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'status' ),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $result );
		self::assertSame( $post_id, $result['id'], 'The ID is always returned.' );
		self::assertSame( 'trash', $result['status'], 'A trash delete reports the trash status.' );
		self::assertSame( 'trash', get_post( $post_id )->post_status, 'The post should be trashed, not destroyed.' );
	}

	/**
	 * A forced delete destroys the post outright and returns its
	 * pre-deletion values.
	 */
	public function test_delete_force_deletes(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );
		$post_id = $this->make_post();

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'id'     => $post_id,
				'force'  => true,
				'fields' => array( 'id', 'status', 'title.raw' ),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $result );
		// Odd but faithful: the post is formatted before the forced deletion,
		// so the response carries its pre-deletion values.
		self::assertSame( 'publish', $result['status'] );
		self::assertSame( 'To be deleted', $result['title']['raw'] );
		self::assertNull( get_post( $post_id ), 'A forced delete removes the post entirely.' );
	}

	/**
	 * Requested fields that do not apply to the deleted post are skipped, so
	 * only the always-included ID comes back.
	 */
	public function test_delete_force_returns_id_only_when_fields_do_not_apply(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );
		$post_id = $this->make_post();

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'id'     => $post_id,
				'force'  => true,
				'fields' => array( 'parent' ),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $result );
		self::assertSame( array( 'id' => $post_id ), $result, 'A non-hierarchical post has no parent to report.' );
		self::assertNull( get_post( $post_id ) );
	}

	/**
	 * An already-trashed post cannot be trashed again, but a forced delete
	 * still works.
	 */
	public function test_delete_already_trashed(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );
		$post_id = $this->make_post();

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$trashed = $ability->execute( array( 'id' => $post_id ) );
		self::assertNotInstanceOf( WP_Error::class, $trashed );

		$again = $ability->execute( array( 'id' => $post_id ) );
		$this->assert_wp_error( 'content_already_trashed', $again );
		self::assertSame( 410, $again->get_error_data( 'content_already_trashed' )['status'] );

		$forced = $ability->execute(
			array(
				'id'    => $post_id,
				'force' => true,
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $forced );
		self::assertSame( 'trash', $forced['status'], 'The forced delete reports the pre-deletion trash status.' );
		self::assertNull( get_post( $post_id ) );
	}

	/**
	 * Post types without trash support (MEDIA_TRASH is off by default, so an
	 * attachment qualifies) cannot be soft-deleted; they only report
	 * `content_trash_not_supported`. The site-wide EMPTY_TRASH_DAYS = 0
	 * variant is not testable here, as wp-env defines that constant.
	 */
	public function test_delete_attachment_without_trash_support(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );

		$attachment_type                           = get_post_type_object( 'attachment' );
		$attachment_type->show_in_abilities        = true; // @phpstan-ignore property.notFound (matches the polyfill's write; the property is declared on trunk hosts)
		$attachment_id                             = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
				'post_author'    => $this->user_ids['editor'],
			)
		);
		$this->post_ids[ "post-{$attachment_id}" ] = $attachment_id;

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$result = $ability->execute( array( 'id' => $attachment_id ) );
		$this->assert_wp_error( 'content_trash_not_supported', $result );
		self::assertSame( 501, $result->get_error_data( 'content_trash_not_supported' )['status'] );

		// A string force value also takes the unvalidated string-input path.
		$forced = $this->execute_raw(
			$ability,
			array(
				'id'    => (string) $attachment_id,
				'force' => 'false',
			)
		);
		$this->assert_wp_error( 'content_trash_not_supported', $forced );

		self::assertSame( 'inherit', get_post( $attachment_id )->post_status, 'The attachment should survive an unsupported trash request.' );

		$deleted = $this->execute_raw(
			$ability,
			array(
				'id'    => (string) $attachment_id,
				'force' => 'true',
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $deleted );
		self::assertNull( get_post( $attachment_id ), 'A forced delete still works without trash support.' );
	}

	/**
	 * Users who cannot delete the post never reach the execute callback.
	 */
	public function test_delete_permission_denials(): void {
		$this->create_users();
		$post_id = $this->make_post();

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$input = array( 'id' => $post_id );

		wp_set_current_user( 0 );
		$this->assert_wp_error( 'ability_invalid_permissions', $ability->execute( $input ), 'Logged-out users cannot delete.' );

		wp_set_current_user( $this->user_ids['subscriber'] );
		$this->assert_wp_error( 'ability_invalid_permissions', $ability->execute( $input ), 'Subscribers cannot delete.' );

		wp_set_current_user( $this->user_ids['author'] );
		$this->assert_wp_error( 'ability_invalid_permissions', $ability->execute( $input ), 'Authors cannot delete an editor\'s post.' );

		// Authors can trash their own drafts.
		$draft_id = $this->make_post(
			array(
				'post_status' => 'draft',
				'post_author' => $this->user_ids['author'],
			)
		);
		$result   = $ability->execute( array( 'id' => $draft_id ) );
		self::assertNotInstanceOf( WP_Error::class, $result );
		self::assertSame( 'trash', $result['status'] );
	}

	/**
	 * A missing post fails the permission callback closed; the execute
	 * callback then reports the not-found error.
	 */
	public function test_delete_missing_post_fails_closed(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$this->assert_wp_error( 'ability_invalid_permissions', $ability->execute( array( 'id' => self::IMPOSSIBLE_ID ) ) );

		$direct = $this->execute_raw( $ability, array( 'id' => self::IMPOSSIBLE_ID ) );
		$this->assert_wp_error( 'content_not_found', $direct );
		self::assertSame( array( 'status' => 404 ), $direct->get_error_data( 'content_not_found' ) );
	}

	/**
	 * A type that guards the lookup denies mismatches and allows matches.
	 */
	public function test_delete_type_guard(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );
		$page_id = $this->make_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'A page',
			)
		);

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$this->assert_wp_error(
			'ability_invalid_permissions',
			$ability->execute(
				array(
					'id'   => $page_id,
					'type' => 'post',
				)
			),
			'A mismatched type guard fails the permission check.'
		);
		$this->assert_wp_error(
			'content_not_found',
			$this->execute_raw(
				$ability,
				array(
					'id'   => $page_id,
					'type' => 'post',
				)
			),
			'A mismatched type guard reports not-found on the direct path.'
		);

		$deleted = $ability->execute(
			array(
				'id'   => $page_id,
				'type' => 'page',
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $deleted );
		self::assertSame( 'trash', $deleted['status'] );
	}

	/**
	 * String inputs are honored on the unvalidated path: string IDs, string
	 * force flags, and CSV field lists.
	 */
	public function test_delete_string_inputs_are_honored(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );
		$post_id = $this->make_post();

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$trashed = $this->execute_raw(
			$ability,
			array(
				'id'     => (string) $post_id,
				'force'  => 'false',
				'fields' => 'id,status',
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $trashed );
		self::assertSame( array( 'id', 'status' ), array_keys( $trashed ), 'A CSV fields string should be split.' );
		self::assertSame( 'trash', $trashed['status'] );
		self::assertSame( 'trash', get_post( $post_id )->post_status );

		$deleted = $this->execute_raw(
			$ability,
			array(
				'id'    => (string) $post_id,
				'force' => 'true',
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $deleted );
		self::assertNull( get_post( $post_id ), "The string 'true' should force the deletion." );
	}

	/**
	 * A deletion the site refuses through its filters reports
	 * `content_cannot_delete` and leaves the post untouched.
	 */
	public function test_delete_reports_a_refused_deletion(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );
		$post_id = $this->make_post();

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		add_filter( 'pre_trash_post', '__return_false' );
		$refused = $ability->execute( array( 'id' => $post_id ) );
		remove_filter( 'pre_trash_post', '__return_false' );

		$this->assert_wp_error( 'content_cannot_delete', $refused );
		self::assertSame( 500, $refused->get_error_data( 'content_cannot_delete' )['status'] );
		self::assertSame( 'publish', get_post( $post_id )->post_status, 'A refused trash should leave the post untouched.' );

		add_filter( 'pre_delete_post', '__return_false' );
		$refused_force = $ability->execute(
			array(
				'id'    => $post_id,
				'force' => true,
			)
		);
		remove_filter( 'pre_delete_post', '__return_false' );

		$this->assert_wp_error( 'content_cannot_delete', $refused_force );
		self::assertSame( 'publish', get_post( $post_id )->post_status, 'A refused forced delete should leave the post untouched.' );
	}

	/**
	 * The execute callback rechecks the delete capability, so it fails
	 * closed even when called without the permission callback.
	 */
	public function test_execute_callback_rechecks_the_delete_capability(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['subscriber'] );
		$post_id = $this->make_post();

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$direct = $this->execute_raw( $ability, array( 'id' => $post_id ) );
		$this->assert_wp_error( 'content_cannot_delete', $direct );
		self::assertSame( 403, $direct->get_error_data( 'content_cannot_delete' )['status'] );

		$forced = $this->execute_raw(
			$ability,
			array(
				'id'    => $post_id,
				'force' => true,
			)
		);
		$this->assert_wp_error( 'content_cannot_delete', $forced );
		self::assertSame( 'publish', get_post( $post_id )->post_status, 'A subscriber cannot delete an editor\'s post.' );
	}

	/**
	 * Explicit raw fields in the projection require edit access to the
	 * post; rendered fields do not.
	 */
	public function test_delete_with_raw_fields_requires_edit_access(): void {
		$this->create_users();
		wp_set_current_user( $this->user_ids['editor'] );
		wp_get_current_user()->add_cap( 'edit_published_posts', false );
		$post_id = $this->make_post();

		$ability = $this->get_registered();
		self::assertNotNull( $ability );

		$this->assert_wp_error(
			'ability_invalid_permissions',
			$ability->execute(
				array(
					'id'     => $post_id,
					'fields' => array( 'id', 'title.raw' ),
				)
			),
			'Raw fields require edit access.'
		);
		self::assertSame( 'publish', get_post( $post_id )->post_status );

		$rendered = $ability->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'title.rendered' ),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $rendered );
		self::assertSame( 'To be deleted', $rendered['title']['rendered'] );
		self::assertSame( 'trash', get_post( $post_id )->post_status, 'The trash delete should still happen.' );
	}
}
