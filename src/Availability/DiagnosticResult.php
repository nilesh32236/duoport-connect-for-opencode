<?php
/**
 * Credential-blind diagnostic result value object.
 *
 * Replaces the 6-parameter positional result() bool bag: factories build a
 * DiagnosticResult from named arguments and project it to the legacy
 * array shape, so a future factory cannot misorder positional bools.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */

declare(strict_types=1);

namespace OpenCodeConnector\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable diagnostic result.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */
final class DiagnosticResult {
	/**
	 * Build a result from named fields.
	 *
	 * @since 0.1.8
	 *
	 * @param string $state      Safe state name.
	 * @param bool   $configured Whether authorization was accepted.
	 * @param bool   $verified   Whether the backend was reached and identified.
	 * @param bool   $usable     Whether the connection is currently usable.
	 * @param int    $status     HTTP status or zero.
	 * @param string $code       Safe result code.
	 */
	public function __construct(
		public readonly string $state,
		public readonly bool $configured,
		public readonly bool $verified,
		public readonly bool $usable,
		public readonly int $status,
		public readonly string $code
	) {}

	/**
	 * Project to the legacy array shape callers consume.
	 *
	 * @since 0.1.8
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'state'      => $this->state,
			'configured' => $this->configured,
			'verified'   => $this->verified,
			'usable'     => $this->usable,
			'status'     => $this->status,
			'code'       => $this->code,
		);
	}

	/**
	 * Build the legacy array shape from named fields in one call.
	 *
	 * @since 0.1.8
	 *
	 * @param string $state      Safe state name.
	 * @param bool   $configured Whether authorization was accepted.
	 * @param bool   $verified   Whether the backend was reached and identified.
	 * @param bool   $usable     Whether the connection is currently usable.
	 * @param int    $status     HTTP status or zero.
	 * @param string $code       Safe result code.
	 * @return array<string, mixed>
	 */
	public static function make( string $state, bool $configured, bool $verified, bool $usable, int $status, string $code ): array {
		return ( new self( $state, $configured, $verified, $usable, $status, $code ) )->toArray();
	}
}
