<?php
/**
 * TSE Site Exporter — AI Provider abstraction (V2.5.0).
 *
 * Modular LLM provider layer. Three concrete providers ship today:
 *  - OpenAI            (POST /v1/chat/completions, response_format=json_object)
 *  - Anthropic Claude  (POST /v1/messages, anthropic-version header)
 *  - Google Gemini     (POST /v1beta/models/{model}:generateContent,
 *                       generationConfig.response_mime_type=application/json)
 *
 * Every provider implements `complete( $system, $user_payload, $opts )`
 * returning either an associative array decoded from the LLM JSON output,
 * or a WP_Error.
 *
 * Auth keys are resolved per-provider in this order:
 *   PHP constant (e.g. TSE_OPENAI_KEY)  →  wp_options['tse_ai_settings'][..._key].
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * Factory
 * ---------------------------------------------------------------------- */

function tse_ai_get_provider( $name ) {
    $name = strtolower( (string) $name );
    switch ( $name ) {
        case 'openai':    return new TSE_AI_Provider_OpenAI();
        case 'anthropic': return new TSE_AI_Provider_Anthropic();
        case 'gemini':    return new TSE_AI_Provider_Gemini();
    }
    return new WP_Error( 'tse_ai_unknown_provider', sprintf( 'Unknown LLM provider: %s', $name ) );
}

function tse_ai_supported_providers() {
    return array(
        'openai'    => 'OpenAI (GPT-5.2)',
        'anthropic' => 'Anthropic (Claude Sonnet 4.5)',
        'gemini'    => 'Google (Gemini 3 Pro)',
    );
}

/* -------------------------------------------------------------------------
 * Base
 * ---------------------------------------------------------------------- */

abstract class TSE_AI_Provider_Base {
    /** @return string provider slug, e.g. "openai" */
    abstract public function slug();

    /** @return string default model id */
    abstract public function default_model();

    /** @return string|WP_Error api key */
    public function get_key() {
        return tse_ai_resolve_key( $this->slug() );
    }

    /** @return string model id */
    public function get_model() {
        return tse_ai_resolve_model( $this->slug(), $this->default_model() );
    }

    /**
     * @param string $system System prompt.
     * @param array  $user_payload JSON-encodable payload sent as the user message.
     * @param array  $opts max_tokens, temperature, timeout.
     * @return array|WP_Error decoded JSON object on success; WP_Error otherwise.
     */
    abstract public function complete( $system, $user_payload, $opts = array() );

    /**
     * Try to decode JSON; if model wrapped it in markdown fences, strip them.
     */
    protected function parse_json( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) return new WP_Error( 'tse_ai_empty_response', 'Empty LLM response.' );

        // Strip ```json fences if present.
        if ( 0 === strpos( $raw, '```' ) ) {
            $raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
            $raw = preg_replace( '/\s*```\s*$/', '', $raw );
        }

        $decoded = json_decode( $raw, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return new WP_Error( 'tse_ai_invalid_json', 'LLM returned non-JSON content', array( 'raw' => $raw, 'json_error' => json_last_error_msg() ) );
        }
        return $decoded;
    }
}

/* -------------------------------------------------------------------------
 * OpenAI
 * ---------------------------------------------------------------------- */

class TSE_AI_Provider_OpenAI extends TSE_AI_Provider_Base {
    public function slug() { return 'openai'; }
    public function default_model() { return 'gpt-5.2'; }

    public function complete( $system, $user_payload, $opts = array() ) {
        $key = $this->get_key();
        if ( is_wp_error( $key ) ) return $key;

        $body = array(
            'model'           => $this->get_model(),
            'messages'        => array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user',   'content' => wp_json_encode( $user_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            ),
            'response_format' => array( 'type' => 'json_object' ),
            'max_tokens'      => isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : 4096,
        );
        if ( isset( $opts['temperature'] ) ) $body['temperature'] = (float) $opts['temperature'];

        $resp = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 60,
        ) );
        if ( is_wp_error( $resp ) ) return $resp;

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $raw  = wp_remote_retrieve_body( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'tse_ai_http_' . $code, 'OpenAI HTTP ' . $code, array( 'body' => $raw ) );
        }
        $json = json_decode( $raw, true );
        $content = isset( $json['choices'][0]['message']['content'] ) ? $json['choices'][0]['message']['content'] : '';
        return $this->parse_json( $content );
    }
}

/* -------------------------------------------------------------------------
 * Anthropic Claude
 * ---------------------------------------------------------------------- */

class TSE_AI_Provider_Anthropic extends TSE_AI_Provider_Base {
    public function slug() { return 'anthropic'; }
    public function default_model() { return 'claude-sonnet-4-5'; }

    public function complete( $system, $user_payload, $opts = array() ) {
        $key = $this->get_key();
        if ( is_wp_error( $key ) ) return $key;

        $body = array(
            'model'      => $this->get_model(),
            'max_tokens' => isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : 4096,
            'system'     => $system . "\n\nReturn ONLY a JSON object (no prose, no markdown fences).",
            'messages'   => array(
                array( 'role' => 'user', 'content' => wp_json_encode( $user_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            ),
        );
        if ( isset( $opts['temperature'] ) ) $body['temperature'] = (float) $opts['temperature'];

        $resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 60,
        ) );
        if ( is_wp_error( $resp ) ) return $resp;

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $raw  = wp_remote_retrieve_body( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'tse_ai_http_' . $code, 'Anthropic HTTP ' . $code, array( 'body' => $raw ) );
        }
        $json = json_decode( $raw, true );
        $content = '';
        if ( isset( $json['content'] ) && is_array( $json['content'] ) ) {
            foreach ( $json['content'] as $b ) {
                if ( isset( $b['type'] ) && 'text' === $b['type'] ) $content .= (string) $b['text'];
            }
        }
        return $this->parse_json( $content );
    }
}

/* -------------------------------------------------------------------------
 * Google Gemini
 * ---------------------------------------------------------------------- */

class TSE_AI_Provider_Gemini extends TSE_AI_Provider_Base {
    public function slug() { return 'gemini'; }
    public function default_model() { return 'gemini-3-pro'; }

    public function complete( $system, $user_payload, $opts = array() ) {
        $key = $this->get_key();
        if ( is_wp_error( $key ) ) return $key;

        $model = rawurlencode( $this->get_model() );
        $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $body = array(
            'system_instruction' => array(
                'parts' => array( array( 'text' => $system . "\n\nReturn ONLY a JSON object." ) ),
            ),
            'contents' => array(
                array(
                    'parts' => array(
                        array( 'text' => wp_json_encode( $user_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
                    ),
                ),
            ),
            'generationConfig' => array(
                'response_mime_type' => 'application/json',
                'temperature'        => isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.2,
                'maxOutputTokens'    => isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : 4096,
            ),
        );

        $resp = wp_remote_post( $url, array(
            'headers' => array(
                'x-goog-api-key' => $key,
                'Content-Type'   => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 60,
        ) );
        if ( is_wp_error( $resp ) ) return $resp;

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $raw  = wp_remote_retrieve_body( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'tse_ai_http_' . $code, 'Gemini HTTP ' . $code, array( 'body' => $raw ) );
        }
        $json = json_decode( $raw, true );
        $content = '';
        if ( isset( $json['candidates'][0]['content']['parts'] ) && is_array( $json['candidates'][0]['content']['parts'] ) ) {
            foreach ( $json['candidates'][0]['content']['parts'] as $p ) {
                if ( isset( $p['text'] ) ) $content .= (string) $p['text'];
            }
        }
        return $this->parse_json( $content );
    }
}
