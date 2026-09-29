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
		array( '0.009', null ),
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
check( 'own price below 0.01 falls back to the rules (free here)', null === P2Flux_AP_Rules::price_for_post( $none, '0.001', 'post', array() ) );
check( 'no valid default: nothing is paid by type', null === P2Flux_AP_Rules::price_for_post( array( 'default_price' => 'x' ) + $s, '', 'post', array() ) );
check( 'no valid default: own price still applies', '0.3' === P2Flux_AP_Rules::price_for_post( array( 'default_price' => 'x' ) + $s, '0.3', 'post', array() ) );
check( 'missing settings keys: free, no warning', null === P2Flux_AP_Rules::price_for_post( array(), '', 'post', array() ) );

echo "price of a route\n";
check( 'listed prefix: default', '0.05' === P2Flux_AP_Rules::price_for_route( $s, '/shop/v1/items' ) );
check( 'other route: free', null === P2Flux_AP_Rules::price_for_route( $s, '/other/v1/items' ) );
check( 'prefix is a prefix, not a substring', null === P2Flux_AP_Rules::price_for_route( $s, '/x/shop/v1/' ) );
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

echo "\n{$checks} checks, {$failures} failed\n";
exit( $failures ? 1 : 0 );
