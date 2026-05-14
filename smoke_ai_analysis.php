<?php
/**
 * Smoke test: V2.5.0 AI Analysis Execution Layer.
 *
 * Validates:
 *  - Provider abstraction (a fake provider returning canned items).
 *  - Runner produces 4 expected output files with the documented schema.
 *  - Error path (WP_Error from provider) is wrapped as status='error'.
 *  - Real provider classes implement the slug/default_model/complete contract.
 *  - HTTP request shape: with wp_remote_post mocked, each provider posts to the
 *    right URL with the right headers + a JSON body containing the right model.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'TSE_SITE_EXPORTER_VERSION', '2.5.0-test' );

// -- Minimal WP stubs --------------------------------------------------------
function home_url()       { return 'https://example.com'; }
function get_bloginfo($k) { return $k === 'name' ? 'Example' : '6.5'; }
function wp_json_encode($d, $f = 0) { return json_encode( $d, $f ); }
function wp_parse_url($u) { return parse_url( $u ); }
function wp_strip_all_tags($s) { return strip_tags( (string) $s ); }
function get_option($k, $d = false) { return $d; }
function update_option($k, $v) { return true; }
function sanitize_text_field($s) { return is_string($s) ? trim( strip_tags( $s ) ) : ''; }

class WP_Error {
    public $code; public $message; public $data;
    function __construct( $code = '', $message = '', $data = null ) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    function get_error_message() { return $this->message; }
    function get_error_code()    { return $this->code; }
    function get_error_data()    { return $this->data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

// Captures the last wp_remote_post call so we can assert on URL/headers/body.
$GLOBALS['tse_test_last_post']  = null;
$GLOBALS['tse_test_canned_resp']= null;
function wp_remote_post( $url, $args = array() ) {
    $GLOBALS['tse_test_last_post'] = array( 'url' => $url, 'args' => $args );
    return $GLOBALS['tse_test_canned_resp'];
}
function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r )          { return isset( $r['body'] ) ? $r['body'] : ''; }

require_once __DIR__ . '/tse-site-exporter/includes/ai_settings.php';
require_once __DIR__ . '/tse-site-exporter/includes/ai_provider.php';
require_once __DIR__ . '/tse-site-exporter/includes/ai_runner.php';

// ---------------------------------------------------------------------------
// Fake provider for runner-level tests (returns canned JSON).
// ---------------------------------------------------------------------------
class TSE_AI_Provider_Fake extends TSE_AI_Provider_Base {
    public $canned = array();
    public $calls  = array();
    public $force_error = null;
    public function slug() { return 'fake'; }
    public function default_model() { return 'fake-model-1'; }
    public function get_key() { return 'test-key'; }
    public function complete( $system, $user_payload, $opts = array() ) {
        $this->calls[] = array( 'system' => $system, 'payload' => $user_payload, 'opts' => $opts );
        if ( $this->force_error ) return $this->force_error;
        $canned = array_shift( $this->canned );
        if ( null === $canned ) $canned = array( 'items' => array() );
        return $canned;
    }
}

$fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $fail;
    $status = $cond ? 'PASS' : 'FAIL';
    if ( ! $cond ) $fail++;
    echo "[$status] $label" . ( $detail !== '' ? "  -- $detail" : '' ) . "\n";
}

echo "=== V2.5.0 AI Analysis Execution Layer smoke test ===\n";

// ---------------------------------------------------------------------------
// 1. Provider factory + supported list
// ---------------------------------------------------------------------------
$supported = tse_ai_supported_providers();
check( 'factory: 3 providers supported', count( $supported ) === 3 );
check( 'factory: openai returns OpenAI instance', tse_ai_get_provider( 'openai' ) instanceof TSE_AI_Provider_OpenAI );
check( 'factory: anthropic returns Anthropic instance', tse_ai_get_provider( 'anthropic' ) instanceof TSE_AI_Provider_Anthropic );
check( 'factory: gemini returns Gemini instance', tse_ai_get_provider( 'gemini' ) instanceof TSE_AI_Provider_Gemini );
check( 'factory: unknown returns WP_Error', is_wp_error( tse_ai_get_provider( 'bogus' ) ) );

// ---------------------------------------------------------------------------
// 2. Default models match the spec
// ---------------------------------------------------------------------------
define( 'TSE_OPENAI_KEY', 'sk-test-openai' );
define( 'TSE_ANTHROPIC_KEY', 'sk-test-anthropic' );
define( 'TSE_GEMINI_KEY', 'gemini-test-key' );

$op = tse_ai_get_provider( 'openai' );
$an = tse_ai_get_provider( 'anthropic' );
$ge = tse_ai_get_provider( 'gemini' );
check( 'openai default_model is gpt-5.2', $op->default_model() === 'gpt-5.2' );
check( 'anthropic default_model is claude-sonnet-4-5', $an->default_model() === 'claude-sonnet-4-5' );
check( 'gemini default_model is gemini-3-pro', $ge->default_model() === 'gemini-3-pro' );

// ---------------------------------------------------------------------------
// 3. HTTP request shape per provider
// ---------------------------------------------------------------------------
// OpenAI
$GLOBALS['tse_test_canned_resp'] = array(
    'response' => array( 'code' => 200 ),
    'body'     => json_encode( array( 'choices' => array( array( 'message' => array( 'content' => '{"items":[{"issue":"x","recommendation":"y","priority":"high","affected_pages":["u"],"confidence_score":0.8}]}' ) ) ) ) ),
);
$out = $op->complete( 'sys', array( 'k' => 'v' ) );
$last = $GLOBALS['tse_test_last_post'];
check( 'openai POST to /v1/chat/completions', strpos( $last['url'], 'api.openai.com/v1/chat/completions' ) !== false, $last['url'] );
check( 'openai Authorization Bearer header set', isset( $last['args']['headers']['Authorization'] ) && strpos( $last['args']['headers']['Authorization'], 'Bearer sk-test-openai' ) === 0 );
check( 'openai body contains model=gpt-5.2', strpos( $last['args']['body'], '"model":"gpt-5.2"' ) !== false );
check( 'openai body contains response_format=json_object', strpos( $last['args']['body'], '"response_format":{"type":"json_object"}' ) !== false );
check( 'openai parsed items present', is_array( $out ) && isset( $out['items'] ) && $out['items'][0]['issue'] === 'x' );

// Anthropic
$GLOBALS['tse_test_canned_resp'] = array(
    'response' => array( 'code' => 200 ),
    'body'     => json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => '{"items":[{"issue":"a"}]}' ) ) ) ),
);
$an->complete( 'sys', array( 'k' => 'v' ) );
$last = $GLOBALS['tse_test_last_post'];
check( 'anthropic POST to /v1/messages', strpos( $last['url'], 'api.anthropic.com/v1/messages' ) !== false );
check( 'anthropic x-api-key header set', isset( $last['args']['headers']['x-api-key'] ) && $last['args']['headers']['x-api-key'] === 'sk-test-anthropic' );
check( 'anthropic-version header is 2023-06-01', isset( $last['args']['headers']['anthropic-version'] ) && $last['args']['headers']['anthropic-version'] === '2023-06-01' );
check( 'anthropic body contains model=claude-sonnet-4-5', strpos( $last['args']['body'], '"model":"claude-sonnet-4-5"' ) !== false );

// Gemini
$GLOBALS['tse_test_canned_resp'] = array(
    'response' => array( 'code' => 200 ),
    'body'     => json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => '{"items":[]}' ) ) ) ) ) ) ),
);
$ge->complete( 'sys', array( 'k' => 'v' ) );
$last = $GLOBALS['tse_test_last_post'];
check( 'gemini POST to generateContent', strpos( $last['url'], 'generativelanguage.googleapis.com/v1beta/models/gemini-3-pro:generateContent' ) !== false, $last['url'] );
check( 'gemini x-goog-api-key set', isset( $last['args']['headers']['x-goog-api-key'] ) && $last['args']['headers']['x-goog-api-key'] === 'gemini-test-key' );
check( 'gemini body has response_mime_type=application/json', strpos( $last['args']['body'], '"response_mime_type":"application\/json"' ) !== false || strpos( $last['args']['body'], '"response_mime_type":"application/json"' ) !== false );

// ---------------------------------------------------------------------------
// 4. parse_json: handles markdown fences
// ---------------------------------------------------------------------------
$GLOBALS['tse_test_canned_resp'] = array(
    'response' => array( 'code' => 200 ),
    'body'     => json_encode( array( 'choices' => array( array( 'message' => array( 'content' => "```json\n{\"items\":[{\"issue\":\"fenced\"}]}\n```" ) ) ) ) ),
);
$out = $op->complete( 'sys', array() );
check( 'markdown-fenced JSON parsed', is_array( $out ) && isset( $out['items'][0]['issue'] ) && $out['items'][0]['issue'] === 'fenced' );

// ---------------------------------------------------------------------------
// 5. HTTP error path returns WP_Error
// ---------------------------------------------------------------------------
$GLOBALS['tse_test_canned_resp'] = array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"bad key"}' );
$out = $op->complete( 'sys', array() );
check( 'HTTP 401 maps to WP_Error', is_wp_error( $out ) );

// ---------------------------------------------------------------------------
// 6. Runner: 4 outputs, schema, error wrapping
// ---------------------------------------------------------------------------
$fake = new TSE_AI_Provider_Fake();
$fake->canned = array(
    array( 'items' => array( array( 'priority' => 'high', 'issue' => 'Authority gap', 'affected_pages' => array( 'https://example.com/services/seo/' ), 'recommendation' => 'Link homepage → SEO with descriptive anchor', 'confidence_score' => 0.9, 'category' => 'linking' ) ) ),
    array( 'items' => array( array( 'priority' => 'high', 'issue' => 'Web design under-supported', 'affected_pages' => array( 'https://example.com/services/seo/', 'https://example.com/services/web-design/' ), 'recommendation' => 'Add a link in SEO page', 'confidence_score' => 0.85, 'source_url' => 'https://example.com/services/seo/', 'target_url' => 'https://example.com/services/web-design/', 'suggested_anchor' => 'custom web design services', 'reason' => 'raises authority' ) ) ),
    array( 'items' => array( array( 'priority' => 'medium', 'cluster_id' => 2, 'issue' => 'FAQ isolated', 'affected_pages' => array( 'https://example.com/help/faq/' ), 'recommendation' => 'Bridge from homepage', 'confidence_score' => 0.95, 'finding_type' => 'isolated' ) ) ),
    array( 'items' => array( array( 'priority' => 'low', 'issue' => 'No comparison content', 'affected_pages' => array( 'https://example.com/services/seo/' ), 'recommendation' => 'Add SEO vs PPC support article', 'confidence_score' => 0.6, 'gap_type' => 'missing_support' ) ) ),
);
$inputs = array(
    'site'    => array( 'totals' => array( 'pages' => 7 ), 'distribution' => array( 'by_strategic_type' => array( 'money' => 2 ) ), 'top_authorities' => array() ),
    'pages'   => array(
        array( 'url' => 'https://example.com/services/seo/', 'title' => 'SEO', 'meta_title' => 'SEO', 'meta_description' => '', 'strategic_type' => 'service', 'classification' => 'money', 'h1' => 'SEO', 'h2' => array(), 'word_count' => 1200, 'internal_authority_score' => 70, 'incoming_link_count' => 2, 'outgoing_link_count' => 1, 'top_inbound_anchors' => array(), 'issues' => array() ),
        array( 'url' => 'https://example.com/services/web-design/', 'title' => 'Web Design', 'meta_title' => 'Web Design', 'meta_description' => '', 'strategic_type' => 'service', 'classification' => 'money', 'h1' => 'Web Design', 'h2' => array(), 'word_count' => 100, 'internal_authority_score' => 20, 'incoming_link_count' => 1, 'outgoing_link_count' => 1, 'top_inbound_anchors' => array(), 'issues' => array( 'thin_content' ) ),
    ),
    'linking' => array(
        'linking_opportunities' => array( array( 'source_url' => 'https://example.com/services/seo/', 'target_url' => 'https://example.com/services/web-design/' ) ),
        'weak_money_pages'      => array(),
        'orphan_pages'          => array(),
        'near_orphan_pages'     => array(),
        'duplicate_meta_titles' => array(),
        'duplicate_meta_descriptions' => array(),
    ),
    'cluster' => array( 'totals' => array( 'clusters' => 2 ), 'clusters' => array() ),
);
$out = tse_ai_runner_execute( $fake, $inputs );

check( 'runner: manifest.json present', isset( $out['manifest.json'] ) );
check( 'runner: manifest has provider+model', isset( $out['manifest.json']['provider'] ) && isset( $out['manifest.json']['model'] ) && $out['manifest.json']['provider'] === 'fake' );
foreach ( array( 'ai-recommendations.json', 'ai-internal-link-opportunities.json', 'ai-cluster-analysis.json', 'ai-content-gap-signals.json' ) as $f ) {
    check( "runner: $f present", isset( $out[ $f ] ) );
    check( "runner: $f status=ok", isset( $out[ $f ]['status'] ) && $out[ $f ]['status'] === 'ok' );
    check( "runner: $f items array present + count>=0", isset( $out[ $f ]['items'] ) && is_array( $out[ $f ]['items'] ) );
}
check( 'runner: recommendations item carries required fields',
    isset( $out['ai-recommendations.json']['items'][0]['priority'], $out['ai-recommendations.json']['items'][0]['issue'], $out['ai-recommendations.json']['items'][0]['affected_pages'], $out['ai-recommendations.json']['items'][0]['recommendation'], $out['ai-recommendations.json']['items'][0]['confidence_score'] ) );
check( 'runner: 4 LLM calls executed', count( $fake->calls ) === 4 );

// Verify each prompt mentions the strict JSON schema directive.
foreach ( $fake->calls as $i => $c ) {
    check( "runner: call $i system prompt forbids prose", stripos( $c['system'], 'no prose' ) !== false );
}

// Verify link-opportunities prompt slimmed page context (no plain_text leak).
$lo_payload = $fake->calls[1]['payload'];
check( 'link opps: payload has pre_computed_opportunities', isset( $lo_payload['pre_computed_opportunities'] ) );
check( 'link opps: page_context entries have no plain_text', isset( $lo_payload['page_context'][0] ) && ! array_key_exists( 'plain_text', $lo_payload['page_context'][0] ) );

// ---------------------------------------------------------------------------
// 7. Error path wrapping
// ---------------------------------------------------------------------------
$fake2 = new TSE_AI_Provider_Fake();
$fake2->force_error = new WP_Error( 'tse_ai_http_429', 'rate limited' );
$out2 = tse_ai_runner_execute( $fake2, $inputs );
check( 'runner: error path returns status=error', $out2['ai-recommendations.json']['status'] === 'error' );
check( 'runner: error path carries error message', $out2['ai-recommendations.json']['error'] === 'rate limited' );

echo "\n";
if ( $fail === 0 ) { echo "ALL ASSERTIONS PASS\n"; exit( 0 ); }
echo "FAILED: $fail assertion(s)\n"; exit( 1 );
