=== P2Flux Agent Paywall ===
Contributors: p2flux
Tags: ai, paywall, x402, usdc, crypto
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
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

The plugin is free. P2Flux keeps 1% of each payment, at least 0.003 USDC. For a 0.05 USDC page you receive 0.047. The fee is taken by a smart contract in the same transaction; P2Flux never holds your money.

**What you get**

* A price for all posts, for chosen categories, or per post (a box in the editor). 0 makes a post free.
* Your own REST API routes can be paid too, for sites that sell data.
* In lists, feeds, search and the REST API, agents see the price instead of the text of paid posts.
* Earnings on the settings page: today, this month, all time, and the last payments with a link to each transaction.
* A test mode on Base Sepolia with test USDC, to see it work before real money moves.

== Frequently Asked Questions ==

= Do my visitors notice anything? =

No. People with a browser, logged-in users and search engines (Google, Bing, Apple, DuckDuckGo) read everything as before.

= How does the plugin know a request comes from an AI agent? =

The request carries an x402 payment, or its user agent names an AI crawler (GPTBot, ClaudeBot, PerplexityBot and others) or a program rather than a browser. The list can be changed with the `p2flux_ap_agent_signatures` filter. A bot that pretends to be a normal browser is not detected; it reads your site as it does today.

= Which wallet do I need? =

Any wallet that can receive USDC on the Base network - for example Coinbase Wallet, MetaMask, or an exchange account that supports Base deposits. Check that your exchange accepts USDC on Base before you use its address.

= Can someone pay once and read many pages? =

No. Each payment opens one page once. The same payment sent again - by the same agent or by anyone it was passed to - is refused, as the x402 standard requires.

= What happens if P2Flux is down? =

You choose: agents are asked to come back later (default), or they read for free until it is back. Your visitors are never affected.

= I use a page cache or a CDN. =

A cache that stores whole pages may serve a paid post to an agent before WordPress runs. The plugin marks every answer to an agent as not cacheable, but a CDN that caches by URL only must be told to bypass requests from AI crawlers, or paid posts must be excluded from its cache.

= Are images and files protected? =

Not in this version. WordPress serves uploaded files directly, without PHP; protecting them needs web server rules.

= Is this a payment service? Who holds the money? =

No one holds it. The agent's payment is a signed USDC transfer to a contract address that belongs to your wallet; the P2Flux contract pays you and the fee in the same transaction. P2Flux cannot move the payment anywhere else.

== External services ==

This plugin connects to the P2Flux API (https://api.p2flux.com, or https://api-test.p2flux.com in test mode) to ask what an agent must pay and to settle payments.

* When an AI agent opens a paid page: your wallet address and the price are sent, to get the payment requirement. It is cached for up to an hour.
* When an agent sends a payment: your wallet address, the price, the address of the page and the agent's payment are sent, and P2Flux settles it on the Base network.
* When you save the settings: your wallet address is sent, to check it.

Nothing about your visitors is sent. P2Flux terms: https://p2flux.com/terms.html - privacy: https://p2flux.com/privacy.html

== Screenshots ==

1. The settings page: wallet, price, what agents pay for, earnings.
2. The price box in the editor.

== Changelog ==

= 0.1.0 =
* First version: pay-per-page for AI agents with x402, posts, pages, categories, REST routes, earnings.
