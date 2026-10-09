<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side AI writing-assist integration.
 */
class Ai_Assist {

	/**
	 * Settings keys this class owns on both the site-wide options table and a game's own settings bag.
	 */
	const KEY_FIELDS = [ 'ai_openai_key', 'ai_claude_key' ];

	/**
	 * Field -> provider name, for deriving the has_own_{provider}_key flag redact_settings_read() exposes.
	 */
	const KEY_FIELD_PROVIDERS = [ 'ai_openai_key' => 'openai', 'ai_claude_key' => 'claude' ];

	const SITE_OPENAI_KEY_OPTION = 'be_ai_openai_key';
	const SITE_CLAUDE_KEY_OPTION = 'be_ai_claude_key';
	const SITE_PROVIDER_OPTION   = 'be_ai_provider';

	/**
	 * Optional overrides for a self-hosted.
	 */
	const SITE_OPENAI_BASE_URL_OPTION = 'be_ai_openai_base_url';
	const SITE_OPENAI_MODEL_OPTION    = 'be_ai_openai_model';
	const SITE_CLAUDE_BASE_URL_OPTION = 'be_ai_claude_base_url';
	const SITE_CLAUDE_MODEL_OPTION    = 'be_ai_claude_model';

	const DEFAULT_PROVIDER = 'openai';
	const DEFAULT_OPENAI_URL = 'https://api.openai.com/v1/chat/completions';
	const DEFAULT_CLAUDE_URL = 'https://api.anthropic.com/v1/messages';

	/**
	 * The default model for each provider.
	 */
	const OPENAI_MODEL = 'gpt-4o-mini';
	const CLAUDE_MODEL = 'claude-haiku-4-5-20251001';

	/**
	 * The ceiling on how much of a field's own current text is sent upstream.
	 */
	const MAX_INPUT_CHARS = 8000;

	/**
	 * The same ceiling for the short direction typed when a field is empty.
	 */
	const MAX_INSTRUCTION_CHARS = 1000;

	/**
	 * Suggestions one user may request in any minute.
	 */
	const RATE_LIMIT_PER_MINUTE = 20;

	/**
	 * Short, human-readable framing for each field_context, folded into the system prompt.
	 */
	const FIELD_CONTEXTS = [
		'character_biography'      => "a Mind's Eye Theatre character's biography",
		'character_notes'          => "freeform notes about a Mind's Eye Theatre character",
		'npc_roleplaying_notes'    => 'Storyteller-only roleplaying guidance for a non-player character (voice, mannerisms, or plot hooks)',
		'npc_public_description'   => 'a public-facing description of a non-player character, visible to players',
		'plot_description'         => 'a Storyteller plot description for a Mind\'s Eye Theatre LARP chronicle',
		'plot_cliffhanger'         => 'a short cliffhanger teaser for an ongoing plot',
		'plot_st_notes'            => 'Storyteller-only planning notes on a plot, never seen by a player',
		'plot_entry'               => 'a Storyteller note on a plot timeline entry',
		'rumor_description'        => "an in-character rumor circulating in a Mind's Eye Theatre chronicle",
		'world_object_description' => "a description of an item or location in a Mind's Eye Theatre chronicle",
		'world_object_limitations' => 'the limitations or restrictions on an item or location',
		'world_object_property'    => 'a short free-text property of a catalog item',
		'schema_item_reference'    => 'a page or document citation for a game mechanic',
		'schema_item_description'  => 'a house-rule or general note on a game mechanic',
		'schema_item_source'       => 'a sourcebook citation for a game mechanic',
		'approval_reason'          => 'a short citation naming the real-world approval authority for a game-mechanic exception',
		'chronicle_description'    => 'a short public description of a LARP chronicle',
		'credits_text'             => 'a short credits statement for a LARP character-management plugin',
	];

	/**
	 * Short framing and the exact JSON keys expected back, for each structured draft context. `required_keys` is
	 * validated on the decoded reply before anything is written or returned to a caller.
	 */
	const DRAFT_CONTEXTS = [
		'npc_roleplaying_draft' => [
			'description'    => "a non-player character's Storyteller-only roleplaying notes",
			'required_keys'  => [ 'Wants', 'Knows', 'Will Do If Unopposed', 'Voice & Tone', 'Emotional Range', 'Posture & Movement', 'Public Behavior', 'Private Behavior', 'Combat Style', 'Philosophy & Beliefs', 'Theme Statement', 'Motivations', 'Appearance' ],
			'schema_hint'     => 'a JSON object with exactly these string keys: "Wants", "Knows", "Will Do If Unopposed", "Voice & Tone", "Emotional Range", "Posture & Movement", "Public Behavior", "Private Behavior", "Combat Style", "Philosophy & Beliefs", "Theme Statement", "Motivations", "Appearance" - fill only the ones named as empty below, and leave every other key as an empty string',
		],
		'plot_draft'            => [
			'description'   => 'a new Storyteller plot drafted from a one-line premise',
			'required_keys' => [ 'title', 'description', 'st_notes', 'cliffhanger', 'notes', 'rumors' ],
			'schema_hint'   => 'a JSON object: "title" (string), "description" (string), "st_notes" (string, Storyteller-only planning detail), "cliffhanger" (string), "notes" (an array of 3 to 5 short strings, each one Storyteller-only plot beat), "rumors" (an array of 2 or 3 short strings, each one in-character rumor)',
		],
		'session_recap_draft'   => [
			'description'   => "a Storyteller's own recap of a game night",
			'required_keys' => [ 'key_events', 'player_decisions', 'npcs_involved', 'cliffhanger', 'prep' ],
			'schema_hint'   => 'a JSON object: "key_events" (string), "player_decisions" (string), "npcs_involved" (an array of objects, each `{"name": string, "status": one of "alive", "injured", "dead", "unknown"}`), "cliffhanger" (string), "prep" (string, prep notes for next time)',
		],
	];

	/**
	 * Generates a structured draft for one of DRAFT_CONTEXTS, from real chronicle data rather than a single
	 * field's own current text. The reply is parsed as JSON and checked for every `required_keys` entry before
	 * anything is returned; a provider error or an invalid reply never reaches the caller as data.
	 *
	 * @param string               $draft_context One of the DRAFT_CONTEXTS keys.
	 * @param array<string,mixed>  $context_data  The real data the draft is grounded in - never another
	 *                                            entity's own Storyteller-only text.
	 * @param string               $instruction   An optional short extra direction.
	 * @param string|null          $game_slug
	 * @return array{ok:bool,data?:array<string,mixed>,code?:string,message?:string}
	 */
	public static function generate_structured( string $draft_context, array $context_data, string $instruction, ?string $game_slug ): array {
		if ( ! isset( self::DRAFT_CONTEXTS[ $draft_context ] ) ) {
			return [ 'ok' => false, 'code' => 'unknown_field_context', 'message' => __( 'Unknown field.', 'beyond-elysium' ) ];
		}

		$resolved = self::resolve_key( $game_slug );
		if ( $resolved === null ) {
			return [ 'ok' => false, 'code' => 'ai_not_configured', 'message' => __( 'AI assist is not configured yet - ask whoever manages this chronicle (or the site) to add an API key.', 'beyond-elysium' ) ];
		}

		$context = self::DRAFT_CONTEXTS[ $draft_context ];
		$system  = sprintf(
			'You help a tabletop LARP Storyteller draft %s. Match the setting\'s tone. Reply with ONLY a single JSON object, no prose, no markdown code fence, matching this exact shape: %s.',
			$context['description'],
			$context['schema_hint']
		);

		$payload = wp_json_encode( $context_data );
		$user_message = 'Draft this from the following real chronicle data (JSON): ' . self::truncate( (string) $payload );
		if ( trim( $instruction ) !== '' ) {
			$user_message .= "\n\nAdditional direction: " . self::truncate( trim( $instruction ), self::MAX_INSTRUCTION_CHARS );
		}

		$result = self::dispatch( $resolved, $system, $user_message, 2000 );
		if ( ! $result['ok'] ) {
			return $result;
		}

		$decoded = self::decode_json_reply( (string) ( $result['suggestion'] ?? '' ) );
		if ( $decoded === null ) {
			return [ 'ok' => false, 'code' => 'ai_invalid_reply', 'message' => __( 'The AI provider replied, but not with the expected structure. Try again.', 'beyond-elysium' ) ];
		}
		foreach ( $context['required_keys'] as $key ) {
			if ( ! array_key_exists( $key, $decoded ) ) {
				return [ 'ok' => false, 'code' => 'ai_invalid_reply', 'message' => __( 'The AI provider replied, but not with the expected structure. Try again.', 'beyond-elysium' ) ];
			}
		}

		return [ 'ok' => true, 'data' => $decoded ];
	}

	/**
	 * Decodes a provider's own reply text as a JSON object, tolerating a markdown code fence around it.
	 *
	 * @return array<string,mixed>|null null on anything that isn't a decodable JSON object.
	 */
	private static function decode_json_reply( string $text ): ?array {
		$text = trim( $text );
		if ( str_starts_with( $text, '```' ) ) {
			$text = preg_replace( '/^```[a-z]*\n?/i', '', $text );
			$text = preg_replace( '/```$/', '', trim( (string) $text ) );
			$text = trim( (string) $text );
		}
		$decoded = json_decode( $text, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Generates a suggestion for one field.
	 *
	 * @param string      $field_context One of the FIELD_CONTEXTS keys.
	 * @param string      $current_text  The field's own current value, '' if empty.
	 * @param string      $instruction   A short player-typed prompt, used only when $current_text is empty.
	 * @param string|null $game_slug     null for a site-wide field.
	 * @return array{ok:bool,suggestion?:string,code?:string,message?:string}
	 */
	public static function generate( string $field_context, string $current_text, string $instruction, ?string $game_slug ): array {
		if ( ! isset( self::FIELD_CONTEXTS[ $field_context ] ) ) {
			return [ 'ok' => false, 'code' => 'unknown_field_context', 'message' => __( 'Unknown field.', 'beyond-elysium' ) ];
		}

		$resolved = self::resolve_key( $game_slug );
		if ( $resolved === null ) {
			return [ 'ok' => false, 'code' => 'ai_not_configured', 'message' => __( 'AI assist is not configured yet - ask whoever manages this chronicle (or the site) to add an API key.', 'beyond-elysium' ) ];
		}

		$text = self::truncate( $current_text );
		$description = self::FIELD_CONTEXTS[ $field_context ];

		$system = sprintf(
			'You help a tabletop LARP Storyteller write %s. Match the setting\'s tone. Write only the requested text itself - no preamble, no explanation, no markdown formatting. Do not invent specific game-mechanical rules; this text is prose, not a ruling.',
			$description
		);

		if ( $text !== '' ) {
			$user_message = "Improve and polish the following text, keeping its meaning and any names/details it already contains:\n\n" . $text;
		} else {
			$prompt = trim( $instruction ) !== '' ? self::truncate( trim( $instruction ), self::MAX_INSTRUCTION_CHARS ) : $description;
			$user_message = 'Write ' . $description . ', based on this direction: ' . $prompt;
		}

		return self::dispatch( $resolved, $system, $user_message );
	}

	/**
	 * Sends a minimal, cheap request to confirm a provider/key/endpoint combination is actually reachable and
	 * authenticates.
	 *
	 * @param string $provider
	 * @param string $key
	 * @param string $base_url Empty string uses the built-in default.
	 * @param string $model    Empty string uses the built-in default.
	 * @param bool   $safe     Request only public addresses - true for a chronicle's own settings (see dispatch()).
	 * @return array{ok:bool,message?:string,code?:string}
	 */
	public static function test_connection( string $provider, string $key, string $base_url, string $model, bool $safe = false ): array {
		if ( trim( $key ) === '' ) {
			return [ 'ok' => false, 'code' => 'ai_not_configured', 'message' => __( 'No key to test - enter one first.', 'beyond-elysium' ) ];
		}

		$resolved = [ 'provider' => $provider, 'key' => $key, 'base_url' => $base_url, 'model' => $model, 'scope' => $safe ? 'chronicle' : 'site' ];
		$result   = self::dispatch( $resolved, 'Reply with only the single word OK.', 'Reply with only the single word OK.' );

		if ( ! $result['ok'] ) {
			return $result;
		}
		return [ 'ok' => true, 'message' => __( 'Connected successfully.', 'beyond-elysium' ) ];
	}

	/**
	 * Dispatches to the resolved provider's own call method.
	 *
	 * @param array{provider:string,key:string,base_url:string,model:string,scope?:string} $resolved
	 * @param string $system
	 * @param string $user_message
	 * @param int    $max_tokens
	 * @return array{ok:bool,suggestion?:string,code?:string,message?:string}
	 */
	private static function dispatch( array $resolved, string $system, string $user_message, int $max_tokens = 1000 ): array {
		$safe = ( $resolved['scope'] ?? 'site' ) === 'chronicle';
		if ( $resolved['provider'] === 'claude' ) {
			return self::call_claude( $resolved['key'], $system, $user_message, $resolved['base_url'], $resolved['model'], $safe, $max_tokens );
		}
		return self::call_openai( $resolved['key'], $system, $user_message, $resolved['base_url'], $resolved['model'], $safe, $max_tokens );
	}

	/**
	 * Counts one suggestion request for a user and reports whether it is within RATE_LIMIT_PER_MINUTE for the current
	 * minute.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function within_rate_limit( int $user_id ): bool {
		$key    = 'be_ai_rate_' . $user_id;
		$window = get_transient( $key );
		$now    = time();

		if ( ! is_array( $window ) || $now - (int) ( $window['start'] ?? 0 ) >= MINUTE_IN_SECONDS ) {
			$window = [ 'start' => $now, 'count' => 0 ];
		}
		$window['count'] = (int) $window['count'] + 1;
		set_transient( $key, $window, MINUTE_IN_SECONDS );

		return $window['count'] <= self::RATE_LIMIT_PER_MINUTE;
	}

	/**
	 * Resolves which provider, (decrypted) key, and optional endpoint/model override a request should use.
	 *
	 * @param string|null $game_slug
	 * @return array{provider:string,key:string,base_url:string,model:string}|null
	 */
	public static function resolve_key( ?string $game_slug ): ?array {
		$site_provider = (string) get_option( self::SITE_PROVIDER_OPTION, self::DEFAULT_PROVIDER );

		if ( $game_slug === null ) {
			$key = self::decrypt( (string) get_option( self::site_key_option( $site_provider ), '' ) );
			return $key !== null ? array_merge( [ 'provider' => $site_provider, 'key' => $key, 'scope' => 'site' ], self::site_overrides( $site_provider ) ) : null;
		}

		$game = Game::find_by_slug( $game_slug );
		if ( ! $game || empty( $game->settings->ai_assist_enabled ?? false ) ) {
			return null;
		}

		$provider = (string) ( $game->settings->ai_provider ?? $site_provider );

		$own_field     = 'ai_' . $provider . '_key';
		$own_encrypted = $game->settings->$own_field ?? null;
		if ( ! empty( $own_encrypted ) ) {
			$key = self::decrypt( (string) $own_encrypted );
			if ( $key !== null ) {
				$base_url_field = 'ai_' . $provider . '_base_url';
				$model_field    = 'ai_' . $provider . '_model';
				return [
					'provider' => $provider,
					'key'      => $key,
					'base_url' => (string) ( $game->settings->$base_url_field ?? '' ),
					'model'    => (string) ( $game->settings->$model_field ?? '' ),
					// A chronicle's own endpoint.
					'scope'    => 'chronicle',
				];
			}
		}

		$site_key = self::decrypt( (string) get_option( self::site_key_option( $provider ), '' ) );
		return $site_key !== null ? array_merge( [ 'provider' => $provider, 'key' => $site_key, 'scope' => 'site' ], self::site_overrides( $provider ) ) : null;
	}

	/** @return array{base_url:string,model:string} The site-wide endpoint/model override for one provider. */
	private static function site_overrides( string $provider ): array {
		if ( $provider === 'claude' ) {
			return [
				'base_url' => (string) get_option( self::SITE_CLAUDE_BASE_URL_OPTION, '' ),
				'model'    => (string) get_option( self::SITE_CLAUDE_MODEL_OPTION, '' ),
			];
		}
		return [
			'base_url' => (string) get_option( self::SITE_OPENAI_BASE_URL_OPTION, '' ),
			'model'    => (string) get_option( self::SITE_OPENAI_MODEL_OPTION, '' ),
		];
	}

	/**
	 * Merges a settings write's incoming AI key fields into an existing settings array: encrypts a real value before
	 * storage and clears the key on an explicit empty string or null.
	 *
	 * @param array<string,mixed> $incoming What the request body's settings object contains.
	 * @param array<string,mixed> $existing The game's settings before this write.
	 * @return array<string,mixed> The merged settings, ready to persist.
	 */
	public static function merge_settings_write( array $incoming, array $existing ): array {
		foreach ( self::KEY_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $incoming ) ) {
				continue;
			}
			$value = $incoming[ $field ];
			if ( $value === '' || $value === null ) {
				unset( $incoming[ $field ], $existing[ $field ] );
			} else {
				$incoming[ $field ] = self::encrypt( (string) $value );
			}
		}
		return array_merge( $existing, $incoming );
	}

	/**
	 * Redacts a decoded settings object in place for an API response: each stored key is replaced with a
	 * `has_own_{provider}_key` boolean.
	 *
	 * @param \stdClass $settings Mutated in place.
	 */
	public static function redact_settings_read( \stdClass $settings ): void {
		foreach ( self::KEY_FIELD_PROVIDERS as $field => $provider ) {
			$settings->{"has_own_{$provider}_key"} = ! empty( $settings->$field );
			unset( $settings->$field );
		}
	}

	/** @return string The WP option name holding one provider's site-wide encrypted key. */
	private static function site_key_option( string $provider ): string {
		return $provider === 'claude' ? self::SITE_CLAUDE_KEY_OPTION : self::SITE_OPENAI_KEY_OPTION;
	}

	/**
	 * Truncates input text to `$max` characters (MAX_INPUT_CHARS by default), on a whitespace boundary where possible.
	 */
	private static function truncate( string $text, int $max = self::MAX_INPUT_CHARS ): string {
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) <= $max : strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
		$space = strrpos( $cut, ' ' );
		return $space !== false ? substr( $cut, 0, $space ) : $cut;
	}

	/**
	 * Encrypts a plaintext API key for storage.
	 *
	 * @param string $plaintext
	 * @return string
	 */
	public static function encrypt( string $plaintext ): string {
		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( ! $iv_length ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( $iv_length );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( $ciphertext === false ) {
			return '';
		}
		return base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypts a value produced by encrypt().
	 *
	 * @param string $encoded
	 * @return string|null
	 */
	private static function decrypt( string $encoded ): ?string {
		if ( $encoded === '' ) {
			return null;
		}
		$raw       = base64_decode( $encoded, true );
		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( $raw === false || ! $iv_length || strlen( $raw ) <= $iv_length ) {
			return null;
		}
		$iv         = substr( $raw, 0, $iv_length );
		$ciphertext = substr( $raw, $iv_length );
		$key        = hash( 'sha256', wp_salt( 'auth' ), true );
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return $plaintext === false ? null : $plaintext;
	}

	/**
	 * Calls an OpenAI-compatible Chat Completions endpoint.
	 *
	 * @param string $key
	 * @param string $system
	 * @param string $user_message
	 * @param string $base_url Empty string uses DEFAULT_OPENAI_URL.
	 * @param string $model    Empty string uses OPENAI_MODEL.
	 * @param bool   $safe       Request only public addresses (a chronicle's own endpoint - see dispatch()).
	 * @param int    $max_tokens
	 * @return array{ok:bool,suggestion?:string,code?:string,message?:string}
	 */
	private static function call_openai( string $key, string $system, string $user_message, string $base_url = '', string $model = '', bool $safe = false, int $max_tokens = 1000 ): array {
		$post     = $safe ? 'wp_safe_remote_post' : 'wp_remote_post';
		$response = $post( $base_url !== '' ? $base_url : self::DEFAULT_OPENAI_URL, [
			'timeout' => 30,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $key,
			],
			'body' => (string) wp_json_encode( [
				'model'       => $model !== '' ? $model : self::OPENAI_MODEL,
				'messages'    => [
					[ 'role' => 'system', 'content' => $system ],
					[ 'role' => 'user', 'content' => $user_message ],
				],
				'max_tokens'  => $max_tokens,
				'temperature' => 0.8,
			] ),
		] );

		return self::parse_response( $response, static function ( array $body ) {
			return $body['choices'][0]['message']['content'] ?? null;
		} );
	}

	/**
	 * Calls a Claude-compatible Messages endpoint, requested the same way as call_openai().
	 *
	 * @param string $key
	 * @param string $system
	 * @param string $user_message
	 * @param string $base_url Empty string uses DEFAULT_CLAUDE_URL.
	 * @param string $model    Empty string uses CLAUDE_MODEL.
	 * @param bool   $safe       Request only public addresses (a chronicle's own endpoint - see dispatch()).
	 * @param int    $max_tokens
	 * @return array{ok:bool,suggestion?:string,code?:string,message?:string}
	 */
	private static function call_claude( string $key, string $system, string $user_message, string $base_url = '', string $model = '', bool $safe = false, int $max_tokens = 1000 ): array {
		$post     = $safe ? 'wp_safe_remote_post' : 'wp_remote_post';
		$response = $post( $base_url !== '' ? $base_url : self::DEFAULT_CLAUDE_URL, [
			'timeout' => 30,
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
			],
			'body' => (string) wp_json_encode( [
				'model'      => $model !== '' ? $model : self::CLAUDE_MODEL,
				'system'     => $system,
				'messages'   => [
					[ 'role' => 'user', 'content' => $user_message ],
				],
				'max_tokens' => $max_tokens,
			] ),
		] );

		return self::parse_response( $response, static function ( array $body ) {
			return $body['content'][0]['text'] ?? null;
		} );
	}

	/**
	 * Shared response handling for both providers: a WP_Error (network failure), a non-2xx HTTP status, or an unparseable
	 * body all become the same `ai_provider_error` code.
	 *
	 * @param \WP_Error|array<string,mixed> $response      wp_remote_post()'s own return shape.
	 * @param callable                      $extract_text  (array $decoded_body): ?string
	 * @return array{ok:bool,suggestion?:string,code?:string,message?:string}
	 */
	private static function parse_response( $response, callable $extract_text ): array {
		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'code' => 'ai_provider_error', 'message' => __( 'Could not reach the AI provider. Try again in a moment.', 'beyond-elysium' ) ];
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			return [ 'ok' => false, 'code' => 'ai_provider_error', 'message' => __( 'The AI provider returned an error. Check that the configured API key is still valid.', 'beyond-elysium' ) ];
		}

		$text = $extract_text( $body );
		if ( ! is_string( $text ) || trim( $text ) === '' ) {
			return [ 'ok' => false, 'code' => 'ai_provider_error', 'message' => __( 'The AI provider returned an empty response.', 'beyond-elysium' ) ];
		}

		return [ 'ok' => true, 'suggestion' => trim( $text ) ];
	}
}
