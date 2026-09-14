<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Game;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side AI writing-assist integration (ai-writing-assist-design.md).
 *
 * The plugin's first outbound call to a third-party API and its first stored
 * secret held anywhere other than a `wp-config.php` constant (the signed-PDF
 * key is a single site-wide value an admin sets once via SSH; this needs a
 * variable number of keys, settable from wp-admin by an HST with no SSH
 * access at all, so it lives encrypted in the database instead).
 *
 * A key is resolved in two layers, matching this codebase's own existing
 * global-default/chronicle-fork precedent (schema blocks, templates -
 * Decision 011): a site-wide key an administrator configures, and an
 * optional per-chronicle key an HST can set to override it for their own
 * chronicle's own content. Site-wide catalog fields (Schema Block
 * descriptions, Credits) always use the site-wide key, since no chronicle
 * owns that data to begin with.
 *
 * Never trusts a client-asserted field_context/capability pairing - see
 * Ai_Assist_Controller::FIELD_CAPABILITIES, the one authoritative map.
 */
class Ai_Assist {

	/** Settings keys this class owns on both the site-wide options table and a game's own settings bag. */
	const KEY_FIELDS = [ 'ai_openai_key', 'ai_claude_key' ];

	/**
	 * Field -> provider name, for deriving the has_own_{provider}_key flag
	 * redact_settings_read() exposes. A plain map, not a string-strip of
	 * KEY_FIELDS - `str_replace(['ai_', '_key'], '', 'ai_openai_key')` looks
	 * reasonable but is wrong: "openai_key" itself contains a second "ai_"
	 * substring (op-en-AI_-key), so a naive prefix/suffix strip yields
	 * "openkey", not "openai" - caught by
	 * AiAssistThreadTest::test_redact_settings_read_strips_the_key_and_adds_a_boolean.
	 */
	const KEY_FIELD_PROVIDERS = [ 'ai_openai_key' => 'openai', 'ai_claude_key' => 'claude' ];

	const SITE_OPENAI_KEY_OPTION = 'be_ai_openai_key';
	const SITE_CLAUDE_KEY_OPTION = 'be_ai_claude_key';
	const SITE_PROVIDER_OPTION   = 'be_ai_provider';

	/**
	 * Optional overrides for a self-hosted or otherwise-compatible endpoint
	 * (Ollama, LM Studio, vLLM, LocalAI, ...) speaking the same request/
	 * response shape as OpenAI's Chat Completions API or Claude's Messages
	 * API at a different URL, with a different model name than this
	 * class's own built-in default. Neither is a secret - stored and
	 * echoed back in plain text, unlike the KEY_FIELDS above. An Azure
	 * OpenAI deployment (a different URL *shape* - per-deployment routing -
	 * and a different `api-key` auth header, not `Authorization: Bearer`)
	 * is deliberately out of scope; this only supports a plain OpenAI-
	 * compatible base URL with the same Bearer-token auth every one of the
	 * self-hosted options above already uses by default.
	 */
	const SITE_OPENAI_BASE_URL_OPTION = 'be_ai_openai_base_url';
	const SITE_OPENAI_MODEL_OPTION    = 'be_ai_openai_model';
	const SITE_CLAUDE_BASE_URL_OPTION = 'be_ai_claude_base_url';
	const SITE_CLAUDE_MODEL_OPTION    = 'be_ai_claude_model';

	const DEFAULT_PROVIDER = 'openai';
	const DEFAULT_OPENAI_URL = 'https://api.openai.com/v1/chat/completions';
	const DEFAULT_CLAUDE_URL = 'https://api.anthropic.com/v1/messages';

	/** Revisit as provider model lineups change - HST pays their own usage, so cost-effective defaults over the most expensive tier. */
	const OPENAI_MODEL = 'gpt-4o-mini';
	const CLAUDE_MODEL = 'claude-haiku-4-5-20251001';

	/** A generous but bounded ceiling on how much of a field's own current text is ever sent upstream, protecting the HST's own API spend from one runaway field. */
	const MAX_INPUT_CHARS = 8000;

	/**
	 * Short, human-readable framing for each field_context, folded into the
	 * system prompt so the model writes in the right register without the
	 * client needing to know anything about prompt engineering. Deliberately
	 * generic and setting-flavored rather than naming specific OWBN
	 * chronicle rules the model has no reliable knowledge of.
	 */
	const FIELD_CONTEXTS = [
		'character_biography'      => "a Mind's Eye Theatre character's biography",
		'character_notes'          => "freeform notes about a Mind's Eye Theatre character",
		'npc_roleplaying_notes'    => 'Storyteller-only roleplaying guidance for a non-player character (voice, mannerisms, or plot hooks)',
		'plot_description'         => 'a Storyteller plot description for a Mind\'s Eye Theatre LARP chronicle',
		'plot_cliffhanger'         => 'a short cliffhanger teaser for an ongoing plot',
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
	 * Generates a suggestion for one field. Resolves the effective provider
	 * and key for the given chronicle (or the site-wide key when
	 * `$game_slug` is null, for a site-wide field), then dispatches to that
	 * provider. Every failure is a named code, never a raw exception or a
	 * partial suggestion - the caller (the REST controller) turns these
	 * directly into an HTTP response.
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
			$prompt = trim( $instruction ) !== '' ? trim( $instruction ) : $description;
			$user_message = 'Write ' . $description . ', based on this direction: ' . $prompt;
		}

		return self::dispatch( $resolved, $system, $user_message );
	}

	/**
	 * Sends a minimal, cheap request to confirm a provider/key/endpoint
	 * combination is actually reachable and authenticates - used by the
	 * settings UI's own "Test Connection" button, before or instead of
	 * relying on the first real ST's own use of a field to discover a
	 * typo'd URL or a revoked key. Deliberately takes its own explicit
	 * provider/key/base_url/model rather than resolving through
	 * resolve_key(): the whole point is to test a value the admin just
	 * typed, which may not be saved yet.
	 *
	 * @param string $provider
	 * @param string $key
	 * @param string $base_url Empty string uses the built-in default.
	 * @param string $model    Empty string uses the built-in default.
	 * @return array{ok:bool,message?:string,code?:string}
	 */
	public static function test_connection( string $provider, string $key, string $base_url, string $model ): array {
		if ( trim( $key ) === '' ) {
			return [ 'ok' => false, 'code' => 'ai_not_configured', 'message' => __( 'No key to test - enter one first.', 'beyond-elysium' ) ];
		}

		$resolved = [ 'provider' => $provider, 'key' => $key, 'base_url' => $base_url, 'model' => $model ];
		$result   = self::dispatch( $resolved, 'Reply with only the single word OK.', 'Reply with only the single word OK.' );

		if ( ! $result['ok'] ) {
			return $result;
		}
		return [ 'ok' => true, 'message' => __( 'Connected successfully.', 'beyond-elysium' ) ];
	}

	/**
	 * Dispatches to the resolved provider's own call method - the one place
	 * both generate() and test_connection() route through, so a future
	 * third provider is one new branch here, not two.
	 *
	 * @param array{provider:string,key:string,base_url:string,model:string} $resolved
	 * @param string $system
	 * @param string $user_message
	 * @return array{ok:bool,suggestion?:string,code?:string,message?:string}
	 */
	private static function dispatch( array $resolved, string $system, string $user_message ): array {
		if ( $resolved['provider'] === 'claude' ) {
			return self::call_claude( $resolved['key'], $system, $user_message, $resolved['base_url'], $resolved['model'] );
		}
		return self::call_openai( $resolved['key'], $system, $user_message, $resolved['base_url'], $resolved['model'] );
	}

	/**
	 * Resolves which provider, (decrypted) key, and optional endpoint/model
	 * override a request should use. A site-wide call (`$game_slug ===
	 * null`) always uses the site-wide key for the site-wide provider
	 * setting. A chronicle-scoped call returns null outright when that
	 * chronicle hasn't opted in (`ai_assist_enabled`), otherwise prefers
	 * the chronicle's own key for its own chosen provider and falls back
	 * to the site-wide key for that same provider when the chronicle has
	 * enabled the feature but supplied no key of its own. base_url/model
	 * always come from whichever level actually supplied the key - a
	 * chronicle using the site-wide key also gets the site-wide endpoint/
	 * model override, never a mismatched pairing of one level's key with
	 * another level's endpoint.
	 *
	 * @param string|null $game_slug
	 * @return array{provider:string,key:string,base_url:string,model:string}|null
	 */
	public static function resolve_key( ?string $game_slug ): ?array {
		$site_provider = (string) get_option( self::SITE_PROVIDER_OPTION, self::DEFAULT_PROVIDER );

		if ( $game_slug === null ) {
			$key = self::decrypt( (string) get_option( self::site_key_option( $site_provider ), '' ) );
			return $key !== null ? array_merge( [ 'provider' => $site_provider, 'key' => $key ], self::site_overrides( $site_provider ) ) : null;
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
				];
			}
		}

		$site_key = self::decrypt( (string) get_option( self::site_key_option( $provider ), '' ) );
		return $site_key !== null ? array_merge( [ 'provider' => $provider, 'key' => $site_key ], self::site_overrides( $provider ) ) : null;
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
	 * Merges a settings write's incoming AI key fields into an existing
	 * settings array: encrypts a real value before storage, and treats an
	 * explicit empty string/null as "clear this key" (removed entirely,
	 * rather than stored as an encrypted empty string) so resolve_key()'s
	 * fallback to the site-wide key naturally applies again. Every other
	 * field in $incoming/$existing is left untouched.
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
	 * Redacts a decoded settings object in place for an API response: never
	 * echoes a stored key (encrypted or not) back to any client, replacing
	 * each with a plain `has_own_{provider}_key` boolean the UI can render a
	 * "configured" state from without ever seeing the value itself.
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

	/** Truncates input text to MAX_INPUT_CHARS, on a whitespace boundary where possible. */
	private static function truncate( string $text ): string {
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) <= self::MAX_INPUT_CHARS : strlen( $text ) <= self::MAX_INPUT_CHARS ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_INPUT_CHARS ) : substr( $text, 0, self::MAX_INPUT_CHARS );
		$space = strrpos( $cut, ' ' );
		return $space !== false ? substr( $cut, 0, $space ) : $cut;
	}

	/**
	 * Encrypts a plaintext API key for storage. AES-256-CBC with a key
	 * derived from `wp_salt( 'auth' )` (a real secret every WordPress
	 * install already has, never transmitted, rotated only if the site
	 * owner rotates their own salts) via SHA-256 to get a proper 32-byte
	 * key. A fresh random IV is generated per call and stored alongside the
	 * ciphertext (IVs are not secret), base64-encoded as one string so it
	 * fits an ordinary text column/option value.
	 *
	 * @param string $plaintext
	 * @return string
	 */
	public static function encrypt( string $plaintext ): string {
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( $ciphertext === false ) {
			return '';
		}
		return base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypts a value produced by encrypt(). Returns null (not an empty
	 * string) for anything that fails to decode or decrypt, so a caller can
	 * tell "no key configured" apart from "a key is configured but
	 * corrupt/unreadable" if it ever needs to - both are currently treated
	 * as "not available" by resolve_key(), but the distinction is preserved
	 * here rather than collapsed early.
	 *
	 * @param string $encoded
	 * @return string|null
	 */
	private static function decrypt( string $encoded ): ?string {
		if ( $encoded === '' ) {
			return null;
		}
		$raw = base64_decode( $encoded, true );
		if ( $raw === false || strlen( $raw ) <= openssl_cipher_iv_length( 'aes-256-cbc' ) ) {
			return null;
		}
		$iv_length  = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv         = substr( $raw, 0, $iv_length );
		$ciphertext = substr( $raw, $iv_length );
		$key        = hash( 'sha256', wp_salt( 'auth' ), true );
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return $plaintext === false ? null : $plaintext;
	}

	/**
	 * Calls an OpenAI-compatible Chat Completions endpoint - the real
	 * OpenAI API by default, or a self-hosted/otherwise-compatible server
	 * (Ollama, LM Studio, vLLM, LocalAI, ...) at an admin/HST-configured
	 * `$base_url`. `wp_remote_post()`, not `wp_safe_remote_post()` -
	 * deliberately, even though `$base_url` is no longer a fixed literal
	 * once an override is set: the real SSRF invariant is "never
	 * attacker/player-controlled input," not "never variable" - this value
	 * only ever comes from `be_manage_games`/`be_manage_apr`-gated
	 * settings a trusted admin or HST configured, the same trust level
	 * `BE_PDF_SIGNING_CERT`'s own file path already carries, and a
	 * self-hosted LLM's whole point is usually a local-network address
	 * (`wp_safe_remote_post()` blocks exactly that).
	 *
	 * @param string $key
	 * @param string $system
	 * @param string $user_message
	 * @param string $base_url Empty string uses DEFAULT_OPENAI_URL.
	 * @param string $model    Empty string uses OPENAI_MODEL.
	 * @return array{ok:bool,suggestion?:string,code?:string,message?:string}
	 */
	private static function call_openai( string $key, string $system, string $user_message, string $base_url = '', string $model = '' ): array {
		$response = wp_remote_post( $base_url !== '' ? $base_url : self::DEFAULT_OPENAI_URL, [
			'timeout' => 30,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $key,
			],
			'body' => wp_json_encode( [
				'model'       => $model !== '' ? $model : self::OPENAI_MODEL,
				'messages'    => [
					[ 'role' => 'system', 'content' => $system ],
					[ 'role' => 'user', 'content' => $user_message ],
				],
				'max_tokens'  => 1000,
				'temperature' => 0.8,
			] ),
		] );

		return self::parse_response( $response, static function ( array $body ) {
			return $body['choices'][0]['message']['content'] ?? null;
		} );
	}

	/**
	 * Calls a Claude-compatible Messages endpoint - see call_openai()'s own
	 * docblock for why `$base_url` being admin/HST-configured rather than a
	 * fixed literal doesn't change the `wp_remote_post()` vs.
	 * `wp_safe_remote_post()` call.
	 *
	 * @param string $key
	 * @param string $system
	 * @param string $user_message
	 * @param string $base_url Empty string uses DEFAULT_CLAUDE_URL.
	 * @param string $model    Empty string uses CLAUDE_MODEL.
	 * @return array{ok:bool,suggestion?:string,code?:string,message?:string}
	 */
	private static function call_claude( string $key, string $system, string $user_message, string $base_url = '', string $model = '' ): array {
		$response = wp_remote_post( $base_url !== '' ? $base_url : self::DEFAULT_CLAUDE_URL, [
			'timeout' => 30,
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
			],
			'body' => wp_json_encode( [
				'model'      => $model !== '' ? $model : self::CLAUDE_MODEL,
				'system'     => $system,
				'messages'   => [
					[ 'role' => 'user', 'content' => $user_message ],
				],
				'max_tokens' => 1000,
			] ),
		] );

		return self::parse_response( $response, static function ( array $body ) {
			return $body['content'][0]['text'] ?? null;
		} );
	}

	/**
	 * Shared response handling for both providers: a WP_Error (network
	 * failure), a non-2xx HTTP status, or an unparseable body all become
	 * the same `ai_provider_error` code rather than a raw exception - never
	 * a partial or malformed suggestion reaches the caller.
	 *
	 * @param \WP_Error|array $response      wp_remote_post()'s own return shape.
	 * @param callable        $extract_text  (array $decoded_body): ?string
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
