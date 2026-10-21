<?php
/**
 * Canonical WordPress abilities: shared authoring surface.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities;

use WP_Ability;


/**
 * Base class for canonical WordPress abilities shipped in this package.
 *
 * Subclasses are written core-landing-ready: self-contained, closures for
 * callbacks, no plugin helpers, no AI plumbing, text domain `default`. The
 * class provides only a dieted authoring surface (one small abstract method
 * per concern) plus fail-closed input normalization helpers mirroring the
 * style core uses in WP_Abilities_Content.
 *
 * All AI plumbing (prompt files, guideline injection, model preferences,
 * provider-specific filters) stays in the consuming plugin, never here.
 */
abstract class Abstract_Core_Ability extends WP_Ability {

	/**
	 * The full ability name, including its tier prefix.
	 *
	 * @return string E.g. 'core/content-query'.
	 */
	abstract protected function ability_name(): string;

	/**
	 * The human-readable ability label.
	 *
	 * @return string
	 */
	abstract protected function label(): string;

	/**
	 * A detailed description of what the ability does and when to use it.
	 *
	 * @return string
	 */
	abstract protected function description(): string;

	/**
	 * The ability category slug, e.g. 'content'.
	 *
	 * Must be a category the Abilities API registers; core registers
	 * 'site', 'user', 'content', and 'uncategorized'.
	 *
	 * @return string
	 */
	abstract protected function category_slug(): string;

	/**
	 * The JSON Schema definition for the ability's input.
	 *
	 * @return array<string, mixed> Empty array for an input-less ability.
	 */
	abstract protected function input_schema(): array;

	/**
	 * The JSON Schema definition for the ability's output.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function output_schema(): array;

	/**
	 * The execute callback. Called on every ability run.
	 *
	 * @return callable(mixed): (mixed|\WP_Error) Receives validated input.
	 */
	abstract protected function make_execute_callback(): callable;

	/**
	 * The permission callback. Called after the required capabilities check.
	 *
	 * Handles row/status-level checks and must fail closed on malformed
	 * input. Return false or a WP_Error to deny.
	 *
	 * @return callable(mixed): (bool|\WP_Error)
	 */
	abstract protected function make_permission_callback(): callable;

	/**
	 * The ability meta authored by this ability.
	 *
	 * Should hold only behavioral annotations and exposure flags:
	 * `readonly`, `destructive`, `idempotent`, `public`, `show_in_rest`.
	 * Provenance (source, package_version, stability, renamed_from) is
	 * merged in by {@see package_meta()} and the Registrar from the manifest
	 * entry, never restated here, so the manifest stays the single source of
	 * truth for lineage and tier.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function ability_meta(): array;

	/**
	 * The capabilities the current user must have to run the ability.
	 *
	 * Static, request-level floor, checked before the permission callback.
	 * Return an empty list when this callback fully owns authorization.
	 *
	 * @return list<string>
	 */
	abstract protected function required_capabilities(): array;

	/**
	 * Provenance meta merged into every ability of this package.
	 *
	 * Do not override: the Registrar merges provenance and relies on these
	 * keys marking the package origin in the registry.
	 *
	 * @return array<string, mixed>
	 */
	final protected function package_meta(): array {
		return array(
			'source'          => 'wordpress/abilities',
			'package_version' => Manifest::VERSION,
		);
	}

	/**
	 * Constructor.
	 *
	 * Wires the abstract methods into the exact arguments the runtime
	 * WP_Ability constructor accepts. The signature mirrors the
	 * `ability_class` registration mechanism of
	 * WP_Abilities_Registry::register(), which validates the class, unsets
	 * the `ability_class` argument, and instantiates it as
	 * `new $ability_class( $name, $args )`. Both parameters are optional so
	 * tests and tooling can instantiate an ability standalone; when the
	 * registry provides a name it must match the canonical ability name.
	 *
	 * @param string|null $name The expected ability name, when registered
	 *                          through the registry. Optional.
	 * @param array       $args Registration args; only `meta` is merged in
	 *                          (provenance from the Registrar wins over the
	 *                          authored and package defaults). Optional.
	 */
	final public function __construct( ?string $name = null, array $args = array() ) {
		$ability_name = $this->ability_name();

		if ( null !== $name && '' !== $name && $ability_name !== $name ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %1$s: given ability name, %2$s: canonical ability name. */
					__( 'The registration name "%1$s" does not match the canonical ability name "%2$s".' ),
					$name,
					$ability_name
				),
				'6.9.0'
			);
		}

		parent::__construct(
			$ability_name,
			array(
				'label'                 => $this->label(),
				'description'           => $this->description(),
				'category'              => $this->category_slug(),
				'input_schema'          => $this->input_schema(),
				'output_schema'         => $this->output_schema(),
				'execute_callback'      => $this->make_execute_callback(),
				'permission_callback'   => $this->make_permission_callback(),
				'required_capabilities' => $this->required_capabilities(),
				'meta'                  => $this->merge_meta( $args ),
			)
		);
	}

	/**
	 * Merges the ability meta with package provenance and registration meta.
	 *
	 * Later sources win: package provenance overwrites authored values, and
	 * registration meta (stability tier, renamed_from from the manifest
	 * entry) overwrites both. Malformed registration meta is ignored, never
	 * widened into the ability.
	 *
	 * @param array<string, mixed> $args The constructor arguments.
	 * @return array<string, mixed> The merged meta.
	 */
	private function merge_meta( array $args ): array {
		$base     = array_merge( $this->ability_meta(), $this->package_meta() );
		$incoming = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();

		return array_merge( $base, $incoming );
	}

	/**
	 * Parses a whole-number input to a bounded integer, else null.
	 *
	 * Mirrors core's fail-closed style in WP_Abilities_Content: integers
	 * within bounds pass through; digit strings are read as integers; any
	 * other input is rejected by returning null, never widened into a
	 * default value. Large floats are rejected because casting a float
	 * beyond the integer range wraps it to an arbitrary value.
	 *
	 * @param mixed $value The raw input value.
	 * @param int   $min   The minimum acceptable value, inclusive.
	 * @return int|null The parsed integer, or null when malformed or out of bounds.
	 */
	protected static function parse_filter_int( $value, int $min ): ?int {
		if ( is_int( $value ) ) {
			return $value >= $min ? $value : null;
		}

		if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
			$int = (int) $value;

			return $int >= $min ? $int : null;
		}

		if ( ! rest_is_integer( $value ) ) {
			return null;
		}

		$number = (float) $value;

		return $number >= $min && $number <= 2 ** 53 ? (int) $number : null;
	}

	/**
	 * Parses a raw list input into a list of distinct string values.
	 *
	 * Mirrors core's fail-closed style in WP_Abilities_Content: like schema
	 * validation, it accepts a scalar or a comma-separated string, and
	 * parses it with wp_parse_list(). A number, such as a slug sent as
	 * 2024, is read as its string form. Non-array, non-scalar input is
	 * rejected as empty, never widened.
	 *
	 * @param array<mixed> $input The ability input, or one of its objects, such as `where`.
	 * @param string       $key   The input key holding the list.
	 * @return list<string> The parsed string values; empty when absent or unparseable.
	 */
	protected static function parse_list_input( array $input, string $key ): array {
		$value = $input[ $key ] ?? null;
		if ( is_scalar( $value ) ) {
			$value = (string) $value;
		} elseif ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( wp_parse_list( $value ), 'is_string' ) );
	}

	/**
	 * Reads an object-valued input key, normalized to an array.
	 *
	 * Mirrors core's fail-closed style in WP_Abilities_Content: a missing
	 * key returns an empty object input; a present value that is not an
	 * object per the REST schema rules is rejected by returning null,
	 * never widened into an empty object.
	 *
	 * @param array<mixed> $input The ability input.
	 * @param string       $key   The input key holding an object.
	 * @return array<mixed>|null The normalized object input.
	 */
	protected static function get_input_object( array $input, string $key ): ?array {
		if ( ! array_key_exists( $key, $input ) ) {
			return array();
		}

		return rest_is_object( $input[ $key ] ) ? rest_sanitize_object( $input[ $key ] ) : null;
	}
}
