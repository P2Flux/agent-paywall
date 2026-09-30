<?php
/**
 * Who is asking. Pure: tested without WordPress.
 *
 * @package P2Flux_Agent_Paywall
 */

defined( 'ABSPATH' ) || exit;

/**
 * Agent detection.
 *
 * An agent is a request that carries an x402 payment, or whose user agent names an AI crawler or a
 * program rather than a browser. Search engines are NOT agents here: blocking Googlebot or Bingbot
 * would take a site out of search. A bot that pretends to be a browser is not detected - it reads
 * the page as a human would, as it does without this plugin.
 */
class P2Flux_AP_Detector {

	/**
	 * AI crawlers and assistants, and HTTP libraries agents are built on. Matched case-insensitively
	 * as substrings. Filter: `p2flux_ap_agent_signatures`.
	 */
	const SIGNATURES = array(
		// AI crawlers and assistants.
		'GPTBot',
		'ChatGPT-User',
		'OAI-SearchBot',
		'ClaudeBot',
		'Claude-User',
		'Claude-SearchBot',
		'anthropic-ai',
		'PerplexityBot',
		'Perplexity-User',
		'CCBot',
		'Bytespider',
		'Amazonbot',
		'meta-externalagent',
		'meta-externalfetcher',
		'cohere-ai',
		'cohere-training-data-crawler',
		'Diffbot',
		'YouBot',
		'DuckAssistBot',
		'MistralAI-User',
		'AI2Bot',
		'Timpibot',
		'ImagesiftBot',
		'Omgilibot',
		'Google-CloudVertexBot',
		'Kangaroo Bot',
		'PanguBot',
		'Novellum',
		// Agents that pay: they say who they are.
		'P2Flux-MCP',
		'x402',
		// Programs, not browsers: agent frameworks send these.
		'python-requests',
		'python-httpx',
		'aiohttp',
		'axios/',
		'node-fetch',
		'undici',
		'Go-http-client',
		'okhttp',
		'curl/',
		'Wget/',
		'Scrapy',
		'libwww-perl',
	);

	/**
	 * Crawlers that must never be asked to pay: search engines and link previews.
	 */
	const NEVER = array( 'Googlebot', 'bingbot', 'DuckDuckBot', 'Applebot', 'YandexBot', 'Baiduspider', 'Slackbot', 'facebookexternalhit', 'Twitterbot', 'LinkedInBot', 'Discordbot', 'WhatsApp', 'TelegramBot' );

	/**
	 * Is this request an agent?
	 *
	 * @param string   $user_agent  The User-Agent header, '' when absent.
	 * @param bool     $has_payment Whether an x402 payment header is present.
	 * @param string[] $signatures  Agent signatures (SIGNATURES, filtered).
	 * @return bool
	 */
	public static function is_agent( $user_agent, $has_payment, array $signatures = self::SIGNATURES ) {
		if ( $has_payment ) {
			return true;
		}
		$user_agent = is_string( $user_agent ) ? trim( $user_agent ) : '';
		// No user agent at all: a program. Node's own fetch sends exactly "node".
		if ( '' === $user_agent || 'node' === strtolower( $user_agent ) ) {
			return true;
		}
		foreach ( self::NEVER as $never ) {
			if ( false !== stripos( $user_agent, $never ) ) {
				return false;
			}
		}
		foreach ( $signatures as $signature ) {
			if ( is_string( $signature ) && '' !== $signature && false !== stripos( $user_agent, $signature ) ) {
				return true;
			}
		}
		return false;
	}
}
