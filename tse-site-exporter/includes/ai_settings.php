<?php
/**
 * TSE Site Exporter — AI settings + key/model resolution helpers (V2.5.0).
 *
 * Key & model are resolved in this order:
 *   1. PHP constant (TSE_OPENAI_KEY, TSE_ANTHROPIC_KEY, TSE_GEMINI_KEY,
 *      TSE_OPENAI_MODEL, TSE_ANTHROPIC_MODEL, TSE_GEMINI_MODEL).
 *   2. WP option `tse_ai_settings`.
 *
 * The option also stores the selected default provider used by the
 * "Run AI Analysis" button.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TSE_AI_OPTION', 'tse_ai_settings' );

function tse_ai_get_settings() {
    $defaults = array(
        'provider'        => 'openai',
        'openai_key'      => '',
        'anthropic_key'   => '',
        'gemini_key'      => '',
        'openai_model'    => 'gpt-5.2',
        'anthropic_model' => 'claude-sonnet-4-5',
        'gemini_model'    => 'gemini-3-pro',
    );
    $stored = get_option( TSE_AI_OPTION, array() );
    if ( ! is_array( $stored ) ) $stored = array();
    return array_merge( $defaults, $stored );
}

function tse_ai_save_settings( $data ) {
    $current = tse_ai_get_settings();
    $clean   = $current;

    if ( isset( $data['provider'] ) && in_array( $data['provider'], array( 'openai', 'anthropic', 'gemini' ), true ) ) {
        $clean['provider'] = $data['provider'];
    }
    foreach ( array( 'openai', 'anthropic', 'gemini' ) as $p ) {
        // Only overwrite key if user actually submitted a non-empty value
        // (avoids clearing the stored key when the masked field is left blank).
        if ( isset( $data[ $p . '_key' ] ) && '' !== trim( (string) $data[ $p . '_key' ] ) ) {
            $clean[ $p . '_key' ] = sanitize_text_field( (string) $data[ $p . '_key' ] );
        }
        if ( isset( $data[ $p . '_model' ] ) ) {
            $val = trim( sanitize_text_field( (string) $data[ $p . '_model' ] ) );
            if ( '' !== $val ) $clean[ $p . '_model' ] = $val;
        }
    }
    update_option( TSE_AI_OPTION, $clean );
    return $clean;
}

function tse_ai_resolve_key( $provider ) {
    $const_map = array(
        'openai'    => 'TSE_OPENAI_KEY',
        'anthropic' => 'TSE_ANTHROPIC_KEY',
        'gemini'    => 'TSE_GEMINI_KEY',
    );
    if ( isset( $const_map[ $provider ] ) && defined( $const_map[ $provider ] ) ) {
        $val = constant( $const_map[ $provider ] );
        if ( is_string( $val ) && '' !== trim( $val ) ) return trim( $val );
    }
    $s = tse_ai_get_settings();
    $key = isset( $s[ $provider . '_key' ] ) ? trim( $s[ $provider . '_key' ] ) : '';
    if ( '' === $key ) {
        return new WP_Error( 'tse_ai_missing_key', sprintf( 'No API key configured for %s. Set TSE_%s_KEY constant or add a key on the TSE Site Exporter admin page.', $provider, strtoupper( $provider ) ) );
    }
    return $key;
}

function tse_ai_resolve_model( $provider, $default ) {
    $const_map = array(
        'openai'    => 'TSE_OPENAI_MODEL',
        'anthropic' => 'TSE_ANTHROPIC_MODEL',
        'gemini'    => 'TSE_GEMINI_MODEL',
    );
    if ( isset( $const_map[ $provider ] ) && defined( $const_map[ $provider ] ) ) {
        $val = constant( $const_map[ $provider ] );
        if ( is_string( $val ) && '' !== trim( $val ) ) return trim( $val );
    }
    $s = tse_ai_get_settings();
    $model = isset( $s[ $provider . '_model' ] ) ? trim( $s[ $provider . '_model' ] ) : '';
    return '' !== $model ? $model : $default;
}

/**
 * Mask an API key for display (preserves prefix + last 4).
 */
function tse_ai_mask_key( $key ) {
    $key = (string) $key;
    if ( '' === $key ) return '';
    if ( strlen( $key ) <= 12 ) return str_repeat( '•', strlen( $key ) );
    return substr( $key, 0, 6 ) . '••••••••' . substr( $key, -4 );
}

/**
 * Is the given provider actually configured (key resolvable)?
 */
function tse_ai_provider_configured( $provider ) {
    $k = tse_ai_resolve_key( $provider );
    return ! is_wp_error( $k );
}
