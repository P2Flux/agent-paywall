=== P2Flux Agent Paywall ===
Contributors: p2flux
Tags: ai, paywall, x402, usdc, crypto
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Get paid in USDC when AI agents read your site. People see your site as always.

== Description ==

AI agents - assistants, research tools, crawlers - read websites for their users. With this plugin they pay you for it.

When an AI agent opens a post you made paid, your site answers **402 Payment Required** with a price. The agent pays in USDC and gets the post. The money goes straight to your wallet, in the same second. This is the open **x402** standard, supported by agents built on Coinbase, Cloudflare, AWS and others.

People visiting your site see nothing different. Search engines are never asked to pay.

**Setup takes a minute**

1. Install and activate.
2. Paste your wallet address (a wallet on the Base network that receives USDC).
3. Choose what agents pay for - all posts, some categories, single posts - and the price.
4. Save.

No account, no API key, no WooCommerce. Nothing to install on your server beyond this plugin.

**What it costs**

The plugin is free. Agents pay in one of two ways, and you do not have to choose:

* **Pay per page**: P2Flux keeps 1% of each payment, at least 0.003 USDC, and you receive the rest at once. For a 0.05 USDC page you receive 0.047.
* **Prepaid balance**: the agent puts at least 1 USDC aside once, in the standard x402 escrow, and then pays each page with a signature - no transaction per page. You receive the money in one payout when it reaches 2 USDC, or weekly. P2Flux keeps 3%. For a 0.05 USDC page you receive 0.0485.

Fees are taken by smart contracts on the way to your wallet; P2Flux never holds your money. An agent's unused prepaid balance stays its own and can always be withdrawn.

**What you get**

* A price for all posts, for chosen categories, or per post (a box in the editor). 0 makes a post free.
* Files in the Media Library can have a price too - a PDF, a dataset, an image.
* Your own REST API routes can be paid too, for sites that sell data.
* In lists, feeds, search and the REST API, agents see the price instead of the text of paid posts.
* Earnings on the settings page: today, this month, all time, and the last payments with a link to each transaction.
* A listing in the P2Flux directory, so AI agents looking for content to buy can find your site.
* "Check my setup": one button that opens your newest paid post as an AI agent would, and tells you if a page cache or CDN is giving it away.
* Agents that sign their requests (Web Bot Auth, used by ChatGPT agent and others) are recognised whatever their user agent says, and named in your payment list.
* For your own AI assistant (WordPress 6.9+, Abilities API): "what did agents pay me this month?", "make this post cost 0.10".
* A test mode on Base Sepolia with test USDC, to see it work before real money moves.

== Frequently Asked Questions ==

= Do my visitors notice anything? =

No. People with a browser, logged-in users and search engines (Google, Bing, Apple, DuckDuckGo) read everything as before.

= How does the plugin know a request comes from an AI agent? =

The request carries an x402 payment, signs itself as a bot (Web Bot Auth), or its user agent names an AI crawler (GPTBot, ClaudeBot, PerplexityBot and others) or a program rather than a browser. The list can be changed with the `p2flux_ap_agent_signatures` filter. A bot that pretends to be a normal browser is not detected; it reads your site as it does today.

= Which wallet do I need? =

Any wallet that can receive USDC on the Base network - for example Coinbase Wallet, MetaMask, or an exchange account that supports Base deposits. Check that your exchange accepts USDC on Base before you use its address.

= Can someone pay once and read many pages? =

An agent can pay once into a prepaid balance and then read many pages from it, each with a signature instead of a transaction. Each page is still paid at its price, from that balance. Each payment opens one page once: the same payment sent again - by the same agent or by anyone it was passed to - is refused, as the x402 standard requires. Prepaid can be switched off in the settings; then every page is paid on its own.

A membership plugin can sell access for a period instead (for example a subscription): it uses the hooks below to price the page, answers the payment with an access token, and lets later requests that carry the token through. Tipster Script does this for its predictions.

= For plugin developers: hooks =

* `p2flux_ap_price` (filter) - `( string|null $price, WP_Post $post, string $url )`: what a request costs an agent. Return a price such as "0.05", or null for free or already entitled.
* `p2flux_ap_requirement` (filter) - `( array $required, string $price, string $url )`: the HTTP 402 before it is sent. Only `resource` (for example `description`, what the payment buys) can be changed; what is settled cannot.
* `p2flux_ap_paid` (action) - `( array $payment )`: a payment settled, before the content is served. Keys: `price`, `units`, `url`, `post_id`, `payer`, `tx`, `network`, `scheme`, `agent`.
* `P2Flux_AP_Gate::access_tokens()` - the tokens the agent sent in the `P2Flux-Access-Token` request header (base64url, 43 characters, up to 10, comma separated).
* `p2flux_ap_agent_signatures` (filter) - the user-agent substrings treated as AI agents.

Single pages answered to agents are never cached, so a page opened by a token is not served to others.

= What happens if P2Flux is down? =

You choose: agents are asked to come back later (default), or they read for free until it is back. Your visitors are never affected.

= I use a page cache or a CDN. =

A cache that stores whole pages may serve a paid post to an agent before WordPress runs. The plugin marks every answer to an agent as not cacheable and tells WP Rocket to skip AI agents. Other page caches and CDNs that cache whole pages must be told to bypass requests from AI crawlers. Press "Check my setup" on the settings page: it tells you whether an agent is asked to pay. The steps for common caches and CDNs: https://p2flux.com/docs/agent-paywall.html#caches

= Are images and files protected? =

Files you give a price are. Open the file in the Media Library and fill in "Price for AI agents". WordPress does not see requests for uploaded files, so the plugin writes a rule into the uploads folder (.htaccess, for Apache and LiteSpeed) that sends AI agents' requests for the priced files - and no others - through WordPress. On nginx, add the rule from the guide to your server configuration; until then the paid address of a file is `/?p2flux_ap_file=<id>`. Images inside a paid post are not paid unless you give them a price.

= Can an agent get its unused prepaid balance back? =

Yes. Once it has used at least 0.10 USDC of it, or after 24 hours without use, it can ask for it back (the x402 refund request); your site passes the request to P2Flux, which returns the balance to the agent's wallet. Unused balances also come back on their own after 7 days, and an agent can always withdraw from the escrow directly. What the agent already spent is yours.

= What is Web Bot Auth? =

A way for an AI agent to prove who it is: it signs each request, and publishes its keys. The plugin checks the signature (Ed25519) when the agent pays and shows the agent's name next to the payment. For this it reads the agent's public key list from the agent's own site. It never lets anyone read for free.

= Is this a payment service? Who holds the money? =

No one holds it. The agent's payment is a signed USDC transfer to a contract address that belongs to your wallet; the P2Flux contract pays you and the fee in the same transaction. P2Flux cannot move the payment anywhere else.

== External services ==

This plugin connects to the P2Flux API (https://api.p2flux.com, or https://api-test.p2flux.com in test mode) to ask what an agent must pay and to settle payments.

* When an AI agent opens a paid page: your wallet address and the price are sent, to get the payment requirement. It is cached for up to an hour.
* When an agent sends a payment: your wallet address, the price, the address of the page and the agent's payment are sent, and P2Flux settles it on the Base network.
* When you save the settings: your wallet address is sent, to check it.
* If "List my site in the P2Flux directory" is ticked (it is by default): your site address is sent, and P2Flux then reads the public document your site serves at /.well-known/x402 - site name, tagline, price, what is paid and the titles of up to ten latest paid posts - and lists it so AI agents can find you. Untick the box and the listing is removed.

When an AI agent that signs its requests (Web Bot Auth) pays, the plugin reads that agent's public keys from the address the agent names (https://<agent>/.well-known/http-message-signatures-directory), to check the signature. Nothing is sent there but the request itself; the keys are cached for an hour.

Nothing about your visitors is sent. P2Flux terms: https://p2flux.com/terms.html - privacy: https://p2flux.com/privacy.html

== Screenshots ==

1. The settings page: wallet, price, what agents pay for, earnings.
2. Earnings and the last payments.
3. The price box in the editor.
4. A price on a file in the Media Library.
5. What an AI agent gets: 402 Payment Required with the price.

== Changelog ==

= 0.6.0 =
* Prices from 0.001 USDC. Under 0.01 an agent pays exactly that from a prepaid balance, or 0.01 when it pays request by request.

= 0.5.1 =
* Plugin URI points to the plugin's documentation page.

= 0.5.0 =
* Hooks for membership plugins: price any page for agents, say what a payment buys, act on a settled payment, read the agent's access tokens.
* Single pages answered to agents are never cached.

= 0.4.0 =
* Paid files: a price for AI agents on any file in the Media Library.
* "Check my setup": see at once whether a page cache or CDN serves paid posts to agents. WP Rocket is told to skip agents.
* Web Bot Auth: agents that sign their requests are recognised and named in the payment list.
* Abilities (WordPress 6.9+): earnings, settings and post prices for the owner's own AI assistant.
* An agent's request to take back its unused prepaid balance is passed on and answered.

= 0.3.0 =
* P2Flux directory: AI agents can find your site (on by default, one checkbox to leave).

= 0.2.0 =
* Prepaid balance (x402 batch settlement): agents pay each page without a transaction; payouts at 2 USDC or weekly, 3%.

= 0.1.0 =
* First version: pay-per-page for AI agents with x402, posts, pages, categories, REST routes, earnings.
