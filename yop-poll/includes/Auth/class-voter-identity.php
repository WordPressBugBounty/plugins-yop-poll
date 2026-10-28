<?php
namespace YopPoll\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Trusted voter data returned by a vote authentication provider. */
final class Voter_Identity {
	private string $permission;
	private int $user_id;
	private string $user_email;
	private string $user_type;
	private string $voter_id;
	private string $first_name;
	private string $last_name;

	public function __construct(
		string $permission,
		int $user_id = 0,
		string $user_email = '',
		string $user_type = 'anonymous',
		string $voter_id = '',
		string $first_name = '',
		string $last_name = ''
	) {
		$this->permission = sanitize_key( $permission );
		$this->user_id    = max( 0, $user_id );
		$this->user_email = sanitize_email( $user_email );
		$this->user_type  = sanitize_key( $user_type );
		$this->voter_id   = sanitize_text_field( $voter_id );
		$this->first_name = sanitize_text_field( $first_name );
		$this->last_name  = sanitize_text_field( $last_name );
	}

	public function permission(): string {
		return $this->permission;
	}

	public function user_id(): int {
		return $this->user_id;
	}

	public function user_email(): string {
		return $this->user_email;
	}

	public function user_type(): string {
		return $this->user_type;
	}

	public function voter_id(): string {
		return $this->voter_id;
	}

	public function first_name(): string {
		return $this->first_name;
	}

	public function last_name(): string {
		return $this->last_name;
	}

	public function to_array(): array {
		return array(
			'permission' => $this->permission,
			'user_id'    => $this->user_id,
			'user_email' => $this->user_email,
			'user_type'  => $this->user_type,
			'voter_id'   => $this->voter_id,
			'first_name' => $this->first_name,
			'last_name'  => $this->last_name,
		);
	}
}
