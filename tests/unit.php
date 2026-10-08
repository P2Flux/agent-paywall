<?php
/**
 * The offline suite: the pure parts - what a request costs and who is an agent.
 *
 *   php tests/unit.php
 *
 * @package P2Flux_Agent_Paywall
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-p2flux-ap-rules.php';
require __DIR__ . '/../includes/class-p2flux-ap-detector.php';
require __DIR__ . '/../includes/class-p2flux-ap-botauth.php';
require __DIR__ . '/../includes/class-p2flux-ap-files.php';

$failures = 0;
$checks   = 0;

/**
 * Assert one thing.
 *
 * @param string $label     What is proven.
 * @param bool   $condition The proof.
 * @param string $detail    Shown on failure.
 * @return void
 */
function check( $label, $condition, $detail = '' ) {
	global $failures, $checks;
	$checks++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$failures++;
	echo "  FAIL  {$label}" . ( '' !== $detail ? "  -- {$detail}" : '' ) . "\n";
}

echo "prices\n";
foreach (
	array(
		array( '0.05', '0.05' ),
		array( '0.050000', '0.05' ),
		array( ' 1 ', '1' ),
		array( '0,25', '0.25' ),
		array( '2.5', '2.5' ),
		array( '0.01', '0.01' ),
		array( '1000', '1000' ),
		array( '007.10', '7.1' ),
		array( 3, '3' ),
		array( '0.009', '0.009' ),
		array( '0.001', '0.001' ),
		array( '0.0009', null ),
		array( '0', null ),
		array( '0.00', null ),
		array( '1000.000001', null ),
		array( '-1', null ),
		array( '1e2', null ),
		array( '0.1234567', null ),
		array( '.5', null ),
		array( '5.', null ),
		array( 'abc', null ),
		array( '', null ),
		array( 0.05, null ),
		array( null, null ),
		array( array( '1' ), null ),
		array( '12345', null ),
		array( '1 000', null ),
	) as $case
) {
	$got = P2Flux_AP_Rules::normalise_price( $case[0] );
	check( 'normalise_price(' . var_export( $case[0], true ) . ') = ' . var_export( $case[1], true ), $got === $case[1], 'got ' . var_export( $got, true ) );
}
check( 'units: 0.05 is 50000', 50000 === P2Flux_AP_Rules::units( '0.05' ) );
check( 'units: 1000 is 1e9', 1000000000 === P2Flux_AP_Rules::units( '1000' ) );
check( 'units: 0.000001 is 1', 1 === P2Flux_AP_Rules::units( '0.000001' ) );
check( 'units: 7.1 is 7100000', 7100000 === P2Flux_AP_Rules::units( '7.1' ) );
check( 'format: 50000 is 0.05', '0.05' === P2Flux_AP_Rules::format_units( 50000 ) );
check( 'format: 0 is 0.00', '0.00' === P2Flux_AP_Rules::format_units( 0 ) );
check( 'format: 1 is 0.000001', '0.000001' === P2Flux_AP_Rules::format_units( 1 ) );
check( 'format: 12500000 is 12.50', '12.50' === P2Flux_AP_Rules::format_units( 12500000 ) );
check( 'format: 1000000 is 1.00', '1.00' === P2Flux_AP_Rules::format_units( 1000000 ) );

echo "price of a post\n";
$s = array(
	'default_price'   => '0.05',
	'paid_post_types' => array( 'post' ),
	'paid_categories' => array( 7 ),
	'paid_routes'     => array( '/shop/v1/' ),
);
$none = array( 'default_price' => '0.05', 'paid_post_types' => array(), 'paid_categories' => array(), 'paid_routes' => array() );
check( 'own price wins over the type', '0.2' === P2Flux_AP_Rules::price_for_post( $s, '0.20', 'post', array() ) );
check( 'own 0 makes a paid type free', null === P2Flux_AP_Rules::price_for_post( $s, '0', 'post', array( 7 ) ) );
check( 'own price makes a free type paid', '1' === P2Flux_AP_Rules::price_for_post( $none, '1', 'page', array() ) );
check( 'paid type, no own price: default', '0.05' === P2Flux_AP_Rules::price_for_post( $s, '', 'post', array() ) );
check( 'unpaid type: free', null === P2Flux_AP_Rules::price_for_post( $s, '', 'page', array() ) );
$cat = array( 'default_price' => '0.05', 'paid_post_types' => array(), 'paid_categories' => array( 7 ), 'paid_routes' => array() );
check( 'paid category: default', '0.05' === P2Flux_AP_Rules::price_for_post( $cat, '', 'post', array( 3, 7 ) ) );
check( 'other category: free', null === P2Flux_AP_Rules::price_for_post( $cat, '', 'post', array( 3 ) ) );
check( 'category ids as strings still match', '0.05' === P2Flux_AP_Rules::price_for_post( array( 'paid_categories' => array( '7' ) ) + $cat, '', 'post', array( '7' ) ) );
check( 'broken own price falls back to the rules', '0.05' === P2Flux_AP_Rules::price_for_post( $s, 'abc', 'post', array() ) );
check( 'own price below 0.001 falls back to the rules (free here)', null === P2Flux_AP_Rules::price_for_post( $none, '0.0001', 'post', array() ) );
check( 'a sub-cent own price is kept', '0.001' === P2Flux_AP_Rules::price_for_post( $none, '0.001', 'post', array() ) );
check( 'no valid default: nothing is paid by type', null === P2Flux_AP_Rules::price_for_post( array( 'default_price' => 'x' ) + $s, '', 'post', array() ) );
check( 'no valid default: own price still applies', '0.3' === P2Flux_AP_Rules::price_for_post( array( 'default_price' => 'x' ) + $s, '0.3', 'post', array() ) );
check( 'missing settings keys: free, no warning', null === P2Flux_AP_Rules::price_for_post( array(), '', 'post', array() ) );

echo "price of a route\n";
check( 'listed prefix: default', '0.05' === P2Flux_AP_Rules::price_for_route( $s, '/shop/v1/items' ) );
check( 'other route: free', null === P2Flux_AP_Rules::price_for_route( $s, '/other/v1/items' ) );
check( 'prefix is a prefix, not a substring', null === P2Flux_AP_Rules::price_for_route( $s, '/x/shop/v1/' ) );
// WordPress matches REST routes case-insensitively; a paid route must not be free in capitals.
check( 'a listed prefix is matched whatever the case', '0.05' === P2Flux_AP_Rules::price_for_route( $s, '/SHOP/v1/items' ) );
check( '...and a prefix saved in capitals still matches', '0.05' === P2Flux_AP_Rules::price_for_route( array( 'paid_routes' => array( '/Shop/V1/' ) ) + $s, '/shop/v1/items' ) );
check( 'a listed /wp/ prefix is ignored', null === P2Flux_AP_Rules::price_for_route( array( 'paid_routes' => array( '/wp/v2/' ) ) + $s, '/wp/v2/posts' ) );
foreach ( array( '/wp/v2/', '/wp', '/wp/', '/oembed/1.0/', '/wp-site-health/v1/', 'shop/v1', '/sh op/', '/../x', '/shop/./v1', '', '/' ) as $bad ) {
	check( "route_allowed rejects '{$bad}'", ! P2Flux_AP_Rules::route_allowed( $bad ) );
}
foreach ( array( '/shop/v1/', '/shop/v1', '/my-api/v2/data', '/wpx/v1/' ) as $good ) {
	check( "route_allowed accepts '{$good}'", P2Flux_AP_Rules::route_allowed( $good ) );
}

echo "who is an agent\n";
$agents = array(
	'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
	'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
	'Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)',
	'Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
	'CCBot/2.0 (https://commoncrawl.org/faq/)',
	'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
	'P2Flux-MCP/0.1 (+https://p2flux.com)',
	'python-requests/2.32.3',
	'python-httpx/0.27.0',
	'axios/1.7.2',
	'curl/8.5.0',
	'Go-http-client/2.0',
	'node',
	'',
	'   ',
);
foreach ( $agents as $ua ) {
	check( 'agent: ' . ( '' === trim( $ua ) ? '(empty)' : substr( $ua, 0, 60 ) ), P2Flux_AP_Detector::is_agent( $ua, false ) );
}
$people = array(
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
	'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
	'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
	'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)',
	'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
	'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
	'Twitterbot/1.0',
	'nodejs-monitor',
);
foreach ( $people as $ua ) {
	check( 'not an agent: ' . substr( $ua, 0, 60 ), ! P2Flux_AP_Detector::is_agent( $ua, false ) );
}
check( 'a browser that sends a payment is an agent', P2Flux_AP_Detector::is_agent( $people[0], true ) );
check( 'a search engine that sends a payment is an agent', P2Flux_AP_Detector::is_agent( $people[3], true ) );
check( 'a crawler name inside a search engine UA is still not asked to pay', ! P2Flux_AP_Detector::is_agent( 'Googlebot GPTBot', false ) );
check( 'custom signatures replace the list', P2Flux_AP_Detector::is_agent( 'MyAgent/1', false, array( 'myagent' ) ) && ! P2Flux_AP_Detector::is_agent( 'GPTBot', false, array( 'myagent' ) ) );
check( 'junk in the signature list is ignored', ! P2Flux_AP_Detector::is_agent( 'Mozilla/5.0 Firefox', false, array( '', null, 5, array() ) ) );
check( 'a non-string user agent is treated as none', P2Flux_AP_Detector::is_agent( null, false ) );

check( 'a request signed as a bot is an agent, whatever its user agent says', P2Flux_AP_Detector::is_agent( $people[0], false, P2Flux_AP_Detector::SIGNATURES, true ) );
check( 'a search engine that signs its requests is still not asked to pay', ! P2Flux_AP_Detector::is_agent( $people[3], false, P2Flux_AP_Detector::SIGNATURES, true ) );

echo "web server rule for paid files\n";
$pattern = P2Flux_AP_Detector::pattern( array( 'GPTBot', 'curl/', 'Kangaroo Bot', 'a.b', 'x(y)|.*', '', null ) );
check( 'signatures become one expression; dots and spaces escaped; anything that could change the rule dropped', 'GPTBot|curl/|Kangaroo\\ Bot|a\\.b' === $pattern, $pattern );
check( 'every built-in signature is in the rule', count( explode( '|', P2Flux_AP_Detector::pattern() ) ) === count( P2Flux_AP_Detector::SIGNATURES ) );
$rules = implode( "\n", P2Flux_AP_Files::rules( '/blog/index.php', 'GPTBot', array( '2026/09/fish-prices.csv', 'photo.jpg' ) ) );
check( 'the rule sends agents, empty user agents, signed and paying requests for the PRICED files through WordPress', false !== strpos( $rules, '(GPTBot) [NC,OR]' ) && false !== strpos( $rules, '^$ [OR]' ) && false !== strpos( $rules, 'Signature-Agent' ) && false !== strpos( $rules, ')$ /blog/index.php?p2flux_ap_file=$1 [L,QSA]' ), $rules );
preg_match( '#^RewriteRule \^(.+)\$ #m', $rules, $rule );
$hits = static fn( $path ) => 1 === preg_match( '#^' . $rule[1] . '$#', $path );
check( 'a priced file and the image sizes made of it match the rule', $hits( '2026/09/fish-prices.csv' ) && $hits( 'photo.jpg' ) && $hits( 'photo-300x200.jpg' ) && $hits( 'photo-scaled.jpg' ) );
check( 'no other file does: another name, another folder, a longer name, another plugin\'s files', ! $hits( '2026/09/other.csv' ) && ! $hits( '2026/10/fish-prices.csv' ) && ! $hits( 'photo.jpg.php' ) && ! $hits( 'xphoto.jpg' ) && ! $hits( 'woocommerce_uploads/secret.pdf' ) && ! $hits( '2026/09/fish-pricesXcsv' ) );
check( 'no priced files, no rule at all', array() === P2Flux_AP_Files::rules( '/index.php', 'GPTBot', array() ) );
check( 'a path that could change the rule is left out', array() === P2Flux_AP_Files::rules( '/index.php', 'GPTBot', array( 'a b.pdf', '../x.pdf', 'a(b|c).pdf', "a\n.pdf", 'noextension', null ) ) );
$many = P2Flux_AP_Files::rules( '/index.php', 'GPTBot', array_map( static fn( $i ) => "2026/09/file-{$i}.pdf", range( 1, 60 ) ) );
check( 'many files: several rules, each with its conditions, no line too long for Apache', 3 === count( preg_grep( '/^RewriteRule/', $many ) ) && 3 === count( preg_grep( '/Payment-Signature/', $many ) ) && max( array_map( 'strlen', $many ) ) < 8000 );

echo "web bot auth\n";
check( 'key name: the RFC 8037 thumbprint example', 'kPrK_qmxVWaYVA9wwBF6Iuo3vVzz7TxHCTwXBygrS4k' === P2Flux_AP_Botauth::thumbprint( '11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo' ) );
$pair   = sodium_crypto_sign_keypair();
$x      = rtrim( strtr( base64_encode( sodium_crypto_sign_publickey( $pair ) ), '+/', '-_' ), '=' ); // phpcs:ignore
$jwks   = array( array( 'kty' => 'RSA' ), 'junk', array( 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $x ) );
$now    = 1790000000;
$agent  = '"https://agent.test"';
$signed = static function ( $components, $params, $lines, $label = 'sig1' ) use ( $pair ) {
	$inner = '(' . $components . ')' . $params;
	$base  = implode( "\n", $lines ) . "\n\"@signature-params\": " . $inner;
	return array( $label . '=' . $inner, $label . '=:' . base64_encode( sodium_crypto_sign_detached( $base, sodium_crypto_sign_secretkey( $pair ) ) ) . ':' ); // phpcs:ignore
};
$params = ';created=' . $now . ';keyid="' . P2Flux_AP_Botauth::thumbprint( $x ) . '";alg="ed25519";expires=' . ( $now + 300 ) . ';nonce="abc";tag="web-bot-auth"';
$req    = array( 'authority' => 'Shop.example', 'target_uri' => 'https://shop.example/a?b=1', 'method' => 'get', 'path' => '/a', 'signature_agent' => $agent );
list( $in, $sg ) = $signed( '"@authority" "signature-agent"', $params, array( '"@authority": shop.example', '"signature-agent": ' . $agent ) );
$sig             = P2Flux_AP_Botauth::parse( $in, $sg );
check( 'a signed request is read', is_array( $sig ) && 'sig1' === $sig['label'] && array( '"@authority"', '"signature-agent"' ) === $sig['components'] && $now === $sig['created'] );
$base = P2Flux_AP_Botauth::base( $sig, $req );
check( 'a valid signature by a published key is accepted', P2Flux_AP_Botauth::check( $sig, $base, $jwks, $now + 10 ) );
check( 'the same signature for another site is refused', ! P2Flux_AP_Botauth::check( $sig, P2Flux_AP_Botauth::base( $sig, array( 'authority' => 'evil.example' ) + $req ), $jwks, $now + 10 ) );
check( 'claiming to be another agent is refused', ! P2Flux_AP_Botauth::check( $sig, P2Flux_AP_Botauth::base( $sig, array( 'signature_agent' => '"https://chatgpt.com"' ) + $req ), $jwks, $now + 10 ) );
check( 'expired is refused', ! P2Flux_AP_Botauth::check( $sig, $base, $jwks, $now + 400 ) );
check( 'not yet valid is refused', ! P2Flux_AP_Botauth::check( $sig, $base, $jwks, $now - 120 ) );
check( 'a key that is not published is refused', ! P2Flux_AP_Botauth::check( $sig, $base, array( array( 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => '11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo' ) ), $now ) );
check( 'no keys: refused', ! P2Flux_AP_Botauth::check( $sig, $base, array(), $now ) );
$long            = str_replace( 'expires=' . ( $now + 300 ), 'expires=' . ( $now + 90000 ), $params );
list( $in, $sg ) = $signed( '"@authority"', $long, array( '"@authority": shop.example' ) );
$sig2            = P2Flux_AP_Botauth::parse( $in, $sg );
check( 'a signature valid for more than a day is refused', is_array( $sig2 ) && ! P2Flux_AP_Botauth::check( $sig2, P2Flux_AP_Botauth::base( $sig2, $req ), $jwks, $now ) );
list( $in, $sg ) = $signed( '"@target-uri" "@method" "@path" "signature-agent";key="sig1"', $params, array( '"@target-uri": https://shop.example/a?b=1', '"@method": GET', '"@path": /a', '"signature-agent";key="sig1": "https://agent.test"' ), 'sig2' );
$sig3            = P2Flux_AP_Botauth::parse( 'other=("@authority");created=1;keyid="k";tag="something-else", ' . $in, 'other=:AAAA:, ' . $sg );
check( 'the web-bot-auth signature is found among others; target, method, path and a named agent entry are covered', is_array( $sig3 ) && 'sig2' === $sig3['label'] && P2Flux_AP_Botauth::check( $sig3, P2Flux_AP_Botauth::base( $sig3, array( 'signature_agent' => 'sig1="https://agent.test"' ) + $req ), $jwks, $now ) );
list( $in, $sg ) = $signed( '"@method"', $params, array( '"@method": GET' ) );
check( 'a signature that does not name this site is not used', null === P2Flux_AP_Botauth::base( P2Flux_AP_Botauth::parse( $in, $sg ), $req ) );
list( $in, $sg ) = $signed( '"@authority" "content-digest"', $params, array( '"@authority": shop.example' ) );
check( 'a signature over something unknown is not used', null === P2Flux_AP_Botauth::base( P2Flux_AP_Botauth::parse( $in, $sg ), $req ) );
foreach (
	array(
		'another tag'       => array( str_replace( 'web-bot-auth', 'other', $in ), $sg ),
		'another algorithm' => array( str_replace( 'ed25519', 'rsa-pss-sha512', $in ), $sg ),
		'no expiry'         => array( preg_replace( '/;expires=\d+/', '', $in ), $sg ),
		'no signature'      => array( $in, '' ),
		'a short signature' => array( $in, 'sig1=:AAAA:' ),
		'another label'     => array( $in, str_replace( 'sig1=', 'sig9=', $sg ) ),
		'not a string'      => array( null, $sg ),
		'far too long'      => array( $in . str_repeat( ' ', 3000 ), $sg ),
	) as $what => $pair_of
) {
	check( 'not read: ' . $what, null === P2Flux_AP_Botauth::parse( $pair_of[0], $pair_of[1] ) );
}
check( 'agent host: a plain string', 'chatgpt.com' === P2Flux_AP_Botauth::agent_host( '"https://chatgpt.com"' ) );
check( 'agent host: a named entry', 'agent.bot.goog' === P2Flux_AP_Botauth::agent_host( 'sig1="https://Agent.Bot.goog/"' ) );
check( 'agent host: http, a path, an address with a user name - none', '' === P2Flux_AP_Botauth::agent_host( '"http://a.test"' ) . P2Flux_AP_Botauth::agent_host( '"https://a.test/x"' ) . P2Flux_AP_Botauth::agent_host( '"https://u@a.test"' ) . P2Flux_AP_Botauth::agent_host( null ) );

echo "access tokens\n";
$t1 = str_repeat( 'A', 21 ) . '-' . str_repeat( 'b', 20 ) . '_';
$t2 = str_repeat( 'z', 43 );
check( 'one token', array( $t1 ) === P2Flux_AP_Rules::access_tokens( $t1 ) );
check( 'a list with spaces, duplicates once', array( $t1, $t2 ) === P2Flux_AP_Rules::access_tokens( " {$t1} ,{$t2}, {$t1}" ) );
check( 'at most ten', 10 === count( P2Flux_AP_Rules::access_tokens( implode( ',', array_map( static fn( $i ) => str_pad( (string) $i, 43, 'x' ), range( 1, 12 ) ) ) ) ) );
foreach (
	array(
		'too short'           => substr( $t1, 1 ),
		'too long'            => $t1 . 'a',
		'base64 not base64url' => str_replace( '-', '+', $t1 ),
		'padding'             => substr( $t1, 1 ) . '=',
		'not a string'        => null,
		'empty'               => '',
		'a huge header'       => str_repeat( $t1 . ',', 30 ),
	) as $what => $header
) {
	check( 'no token: ' . $what, array() === P2Flux_AP_Rules::access_tokens( $header ) );
}
check( 'the good one survives junk around it', array( $t2 ) === P2Flux_AP_Rules::access_tokens( "junk, {$t2}, <script>" ) );

echo "\n{$checks} checks, {$failures} failed\n";
exit( $failures ? 1 : 0 );
