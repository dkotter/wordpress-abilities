<?php
/**
 * Tests: `core/content-query` (HANDOFF §8.1, §10.3, WP3).
 *
 * Runs under both wp-env matrix environments: trunk (7.2-dev, core registers
 * the ability itself) and stable (6.9/7.1 + fixture API, where the package
 * copy registers and executes). Tests branch on the registered copy's
 * provenance; the fail-closed paths reflect private methods on whichever
 * copy the registry holds (core's or the package's).
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use ReflectionClass;
use WordPress\Abilities\Manifest;
use WordPress\Abilities\Registrar;
use WordPress\Abilities\Support\Features;
use WP_Abilities_Registry;
use WP_Error;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Per-ability contract tests for `core/content-query`.
 */
final class ContentQueryTest extends TestCase {

	/**
	 * Cached IDs of the mocked users, keyed by fixture slug.
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
	 * Fires the API init action once, then runs the Registrar post-init, so
	 * tests read a settled registry. The Registry is instantiated BEFORE
	 * register_all() so the stable env's fixture core registration (which
	 * sees no exposed post types) skips first and the package copy registers.
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
	 * Removes the mocked posts and users so a fresh data set is built per test
	 * (instance properties restart empty): without cleanup, wp_insert_user()
	 * hits the existing user_login, `(int) WP_Error` yields 1, and both the
	 * posts and the totals of earlier sets pollute the later tests' queries.
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
	 * The registered `core/content-query` instance, or null.
	 */
	private function get_registered(): ?object {
		return WP_Abilities_Registry::get_instance()->get_registered( 'core/content-query' );
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
	 * Executes the underlying callback directly (the unvalidated transport path).
	 */
	private function execute_raw( object $ability, $input ) {
		return $this->invoke_private( $ability, 'execute_content_query', $input );
	}

	/**
	 * Asserts a WP_Error with the exact code, and optional message/data.
	 */
	private function assert_wp_error( string $code, $actual, ?string $message = null, $data = null ): void {
		self::assertInstanceOf( WP_Error::class, $actual, "Expected WP_Error {$code}." );
		self::assertSame( $code, $actual->get_error_code() );
		if ( null !== $message ) {
			self::assertSame( $message, $actual->get_error_message() );
		}
		if ( null !== $data ) {
			self::assertSame( $data, $actual->get_error_data( $code ) );
		}
	}

	/**
	 * Creates the mocked data set once: an administrator, a subscriber, and posts.
	 */
	private function create_mocked_data_set(): void {
		if ( ! empty( $this->user_ids ) ) {
			return;
		}

		$this->user_ids['admin']      = (int) wp_insert_user(
			array(
				'user_login' => 'cqadmin',
				'role'       => 'administrator',
			)
		);
		$this->user_ids['subscriber'] = (int) wp_insert_user(
			array(
				'user_login' => 'cqsubscriber',
				'role'       => 'subscriber',
			)
		);

		$this->post_ids['publish-1']   = (int) wp_insert_post(
			array(
				'post_title'   => 'Mocked publish post 1',
				'post_content' => 'Mocked content.',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);
		$this->post_ids['publish-2']   = (int) wp_insert_post(
			array(
				'post_title'   => 'Mocked publish post 2',
				'post_content' => 'Mocked content.',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			)
		);
		$this->post_ids['draft-admin'] = (int) wp_insert_post(
			array(
				'post_title'   => 'Mocked admin draft',
				'post_content' => 'Draft content.',
				'post_status'  => 'draft',
				'post_type'    => 'post',
				'post_author'  => $this->user_ids['admin'],
			)
		);
	}

	/**
	 * Registered exactly once, with core's annotations; provenance branches by env;
	 * repeat registration never double-registers.
	 */
	public function test_registered_once_with_core_annotations(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$meta = $ability->get_meta();

		self::assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			$meta['annotations'] ?? null
		);
		self::assertTrue( (bool) ( $meta['public'] ?? false ) );

		if ( empty( $meta['source'] ) ) {
			// Trunk env: core provides the name; the package copy must have skipped.
			self::assertArrayNotHasKey( 'source', $meta );
		} else {
			// Stable env: the package copy registered.
			self::assertSame( 'wordpress/abilities', $meta['source'] );
			self::assertSame( Manifest::VERSION, $meta['package_version'] ?? null );
			self::assertSame( Manifest::TIER_CORE, $meta['stability'] ?? null );
		}

		Registrar::register_all();
		Registrar::register_all();
		self::assertSame( $ability, $this->get_registered(), 'No double registration: the single instance holds.' );
		self::assertSame(
			array( 'core/content-query' ),
			array_values( array_intersect( array_keys( WP_Abilities_Registry::get_instance()->get_all_registered() ), array( 'core/content-query' ) ) ),
			'Only a single core/content-query entry is registered.'
		);
	}

	/**
	 * Trunk registers no required_capabilities: the permission callback owns authorization.
	 */
	public function test_required_capabilities_mirror_trunk(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		self::assertSame( array(), $ability->get_required_capabilities() );
	}

	/**
	 * The input schema mirrors trunk exactly at its dynamic seams.
	 */
	public function test_input_schema_matches_trunk(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$schema = $ability->get_input_schema();

		self::assertSame( 'object', $schema['type'] );
		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( array( 'where' ), $schema['required'] );

		$where = $schema['properties']['where'];
		self::assertSame( array( 'type', 'ids', 'slugs', 'status', 'author_slug', 'parent' ), array_keys( $where['properties'] ) );
		self::assertSame( array( 'type' ), $where['required'] );
		self::assertSame( array_values( get_post_types( array( 'show_in_abilities' => true ) ) ), $where['properties']['type']['enum'] );
		self::assertSame( 100, $where['properties']['ids']['maxItems'] );
		self::assertSame( 100, $where['properties']['slugs']['maxItems'] );
		self::assertSame( array_values( get_post_stati( array( 'internal' => false ) ) ), $where['properties']['status']['items']['enum'] );

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
			'The fields enum in trunk get_field_paths() order.'
		);

		$pagination = $schema['properties']['pagination'];
		self::assertSame( array( 'page', 'per_page' ), array_keys( $pagination['properties'] ) );
		self::assertSame( 1, $pagination['properties']['page']['minimum'] );
		self::assertSame( 1, $pagination['properties']['per_page']['minimum'] );
		self::assertSame( 100, $pagination['properties']['per_page']['maximum'] );
	}

	/**
	 * The output schema mirrors trunk exactly.
	 */
	public function test_output_schema_matches_trunk(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$schema = $ability->get_output_schema();

		self::assertSame( 'object', $schema['type'] );
		self::assertSame( array( 'items', 'pagination' ), $schema['required'] );

		$post = $schema['properties']['items']['items'];
		self::assertSame(
			array( 'id', 'type', 'status', 'date', 'modified', 'slug', 'link', 'title', 'excerpt', 'content', 'author', 'parent' ),
			array_keys( $post['properties'] )
		);
		self::assertSame( array( 'id' ), $post['required'] );
		self::assertSame( array( 'total', 'total_pages' ), array_keys( $schema['properties']['pagination']['properties'] ) );
		self::assertSame( array( 'local', 'gmt' ), array_keys( $post['properties']['date']['properties'] ) );
		self::assertSame( array( 'raw', 'rendered', 'protected' ), array_keys( $post['properties']['content']['properties'] ) );
		self::assertSame( array( 'id', 'name', 'slug' ), array_keys( $post['properties']['author']['properties'] ) );
	}

	/**
	 * Permission gate: logged-out requests are denied (negative).
	 */
	public function test_permission_negative_logged_out(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		wp_set_current_user( 0 );

		$this->assert_wp_error( 'ability_invalid_permissions', $ability->execute( array( 'where' => array( 'type' => 'post' ) ) ) );
	}

	/**
	 * Permission gate, status path: publish/queryable passes for any authenticated
	 * user (positive), draft is denied without edit access (negative) and allowed
	 * for an administrator (positive).
	 */
	public function test_permission_status_gate_negative_and_positive(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$this->create_mocked_data_set();

		wp_set_current_user( $this->user_ids['subscriber'] );

		$draft_input   = array(
			'where' => array(
				'type'   => 'post',
				'status' => array( 'draft' ),
			),
		);
		$publish_input = array(
			'where' => array(
				'type'   => 'post',
				'status' => array( 'publish' ),
			),
		);

		$this->assert_wp_error( 'ability_invalid_permissions', $ability->execute( $draft_input ) );

		$publish = $ability->execute( $publish_input );
		self::assertNotInstanceOf( WP_Error::class, $publish );
		self::assertSame( 2, $publish['pagination']['total'] );

		$private = $ability->execute(
			array(
				'where' => array(
					'type'   => 'post',
					'status' => array( 'private' ),
				),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $private, 'Trunk allows querying private as any user.' );

		$default = $ability->execute( array( 'where' => array( 'type' => 'post' ) ) );
		self::assertNotInstanceOf( WP_Error::class, $default );
		self::assertSame( 'publish', $default['items'][0]['status'] );

		wp_set_current_user( $this->user_ids['admin'] );
		$admin_draft = $ability->execute( $draft_input );
		self::assertNotInstanceOf( WP_Error::class, $admin_draft );
		self::assertSame( 1, $admin_draft['pagination']['total'] );
		self::assertSame( 'draft', $admin_draft['items'][0]['status'] );

		wp_set_current_user( $this->user_ids['subscriber'] );
	}

	/**
	 * Permission gate, post-type path, via the direct callback (fail closed both ways):
	 * a non-exposed type denies (false) and an exposed one passes (true); a malformed
	 * `where` value is let through for the execute callback to reject, per trunk, while
	 * a query with no `where` at all is denied for lack of an exposed post type.
	 */
	public function test_permission_post_type_gate_negative_and_positive(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$this->create_mocked_data_set();
		wp_set_current_user( $this->user_ids['subscriber'] );

		self::assertFalse( $this->invoke_private( $ability, 'check_permission', array( 'where' => array( 'type' => 'abilities-no-such-type' ) ) ), 'Non-exposed type: denied.' );
		self::assertTrue( $this->invoke_private( $ability, 'check_permission', array( 'where' => array( 'type' => 'post' ) ) ), 'Exposed type: allowed.' );
		self::assertTrue( $this->invoke_private( $ability, 'check_permission', array( 'where' => 'not-an-object' ) ), 'Malformed where is let through for fail-closed execution.' );
		self::assertFalse( $this->invoke_private( $ability, 'check_permission', array() ), 'No where at all: no post type to expose, so denied.' );

		wp_set_current_user( 0 );
		self::assertFalse( $this->invoke_private( $ability, 'check_permission', array( 'where' => array( 'type' => 'post' ) ) ), 'Logged out: denied regardless of filters.' );
	}

	/**
	 * Fail-closed malformed-filter rejections in the execute callback (ungated
	 * transports bypass validation, so these run through it directly), plus the
	 * schema-reachable parent/author_slug rejections through the real ability.
	 */
	public function test_execute_fail_closed_malformed_filters(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$this->create_mocked_data_set();
		wp_set_current_user( $this->user_ids['subscriber'] );

		$where_message = 'The where filter must be an object.';
		$this->assert_wp_error(
			'content_invalid_filter',
			$this->execute_raw( $ability, array( 'where' => 'nope' ) ),
			$where_message
		);

		// The parent filter only exists for hierarchical post types.
		$parent_flat = $this->execute_raw(
			$ability,
			array(
				'where' => array(
					'type'   => 'post',
					'parent' => '-1',
				),
			)
		);
		$this->assert_wp_error(
			'content_invalid_filter',
			$parent_flat,
			null,
			array(
				'status' => 400,
				'params' => array( 'where[parent]' => 'The parent filter is only supported for hierarchical post types.' ),
			)
		);

		// On a hierarchical type the parent value must be a non-negative integer.
		$parent_int = $this->execute_raw(
			$ability,
			array(
				'where' => array(
					'type'   => 'page',
					'parent' => '-1',
				),
			)
		);
		$this->assert_wp_error(
			'content_invalid_filter',
			$parent_int,
			null,
			array(
				'status' => 400,
				'params' => array( 'where[parent]' => 'The parent filter must be a non-negative integer.' ),
			)
		);

		// Empty-after-normalization ids and slugs lists must not widen the query.
		$this->assert_wp_error(
			'content_invalid_filter',
			$this->execute_raw(
				$ability,
				array(
					'where' => array(
						'type' => 'post',
						'ids'  => array( 0, -1 ),
					),
				)
			),
			'The ids filter must list one or more valid post IDs.'
		);
		$this->assert_wp_error(
			'content_invalid_filter',
			$this->execute_raw(
				$ability,
				array(
					'where' => array(
						'type'  => 'post',
						'slugs' => array( '!!!' ),
					),
				)
			),
			'The slugs filter must list one or more valid post slugs.'
		);

		// A given-but-useless status filter and an unregistered status fail closed.
		$this->assert_wp_error(
			'content_invalid_filter',
			$this->execute_raw(
				$ability,
				array(
					'where' => array(
						'type'   => 'post',
						'status' => array( 'no-such-status' ),
					),
				)
			),
			'The status filter must list valid post statuses.'
		);

		// Unknown author slug (schema-reachable; goes through validate via the input, so
		// schema validation passes and the filter rejection fires).
		$author = $this->execute_raw(
			$ability,
			array(
				'where' => array(
					'type'        => 'post',
					'author_slug' => 'no-such-user',
				),
			)
		);
		$this->assert_wp_error( 'content_invalid_filter', $author, 'The author_slug filter must be the slug of an existing user.' );
	}

	/**
	 * Execute with the mocked data set: default listing (publish only, as in the
	 * REST posts controller), pagination over an explicit draft-inclusive list,
	 * out-of-range page, and row-level withholding of another user's draft.
	 */
	public function test_execute_mocked_data_set_and_pagination(): void {
		$ability = $this->get_registered();
		self::assertNotNull( $ability );
		$this->create_mocked_data_set();
		wp_set_current_user( $this->user_ids['admin'] );

		// No status filter: the default list covers `publish` only, even for admin.
		$default = $ability->execute(
			array(
				'where' => array( 'type' => 'post' ),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $default );
		self::assertSame( 2, $default['pagination']['total'], 'Default statuses cover publish only.' );
		foreach ( $default['items'] as $item ) {
			self::assertSame( 'publish', $item['status'] );
		}

		$page_one = $ability->execute(
			array(
				'where'      => array(
					'type'   => 'post',
					'status' => array( 'publish', 'draft' ),
				),
				'pagination' => array(
					'per_page' => 2,
					'page'     => 1,
				),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $page_one );
		self::assertCount( 2, $page_one['items'], 'per_page 2 of 3 matched posts.' );
		self::assertSame( 3, $page_one['pagination']['total'] );
		self::assertSame( 2, $page_one['pagination']['total_pages'] );

		$page_two = $ability->execute(
			array(
				'where'      => array(
					'type'   => 'post',
					'status' => array( 'publish', 'draft' ),
				),
				'pagination' => array(
					'per_page' => 2,
					'page'     => 2,
				),
			)
		);
		self::assertCount( 1, $page_two['items'], 'The second page holds the remaining post.' );

		$beyond = $ability->execute(
			array(
				'where'      => array( 'type' => 'post' ),
				'pagination' => array( 'page' => 3 ),
			)
		);
		$this->assert_wp_error( 'content_invalid_page_number', $beyond, null, array( 'status' => 404 ) );

		wp_set_current_user( $this->user_ids['subscriber'] );

		$withheld = $ability->execute(
			array(
				'where' => array(
					'type' => 'post',
					'ids'  => array( $this->post_ids['draft-admin'] ),
				),
			)
		);
		self::assertNotInstanceOf( WP_Error::class, $withheld, 'Listing a draft by ID passes the ids gate but the rows are withheld.' );
		self::assertSame( array(), $withheld['items'], "Another user's draft is withheld from a subscriber." );
		self::assertLessThanOrEqual( 1, $withheld['pagination']['total'] );
	}
}
