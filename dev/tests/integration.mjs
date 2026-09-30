// The gate over real HTTP, inside a real WordPress, against the fake API (no network, no chain).
//
//   dev/local-wp.sh &            # serves http://localhost:8082
//   node dev/tests/integration.mjs
import { execFileSync } from 'node:child_process'
import assert from 'node:assert/strict'

const SITE = process.env.WP_URL || 'http://localhost:8082'
const PATH = `${process.env.HOME}/projects/p2flux_wp_paywall`
const WALLET = '0xb4e43f3fBa5Add75395adAD366627E7d74141Fa9'
const GPT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)'
const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'
const GOOGLE = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'

const wp = (...args) => execFileSync('wp', [`--path=${PATH}`, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim()
const b64 = (v) => Buffer.from(JSON.stringify(v)).toString('base64')
const pay = (id, extra = {}) => b64({ id, ...extra })
const get = async (path, { ua = GPT, payment, method = 'GET' } = {}) => {
  const headers = { 'user-agent': ua }
  if (payment !== undefined) headers['payment-signature'] = payment
  const res = await fetch(path.startsWith('http') ? path : SITE + path, { method, headers, redirect: 'manual' })
  return { status: res.status, headers: res.headers, text: await res.text() }
}
const decode = (h) => JSON.parse(Buffer.from(h, 'base64').toString())
const calls = () => { try { return JSON.parse(wp('option', 'get', 'p2flux_ap_fake_calls', '--format=json')).length } catch { return 0 } }
const clearTransients = () => wp('transient', 'delete', '--all')

// --- fixture ---------------------------------------------------------------------------------------
wp('option', 'update', 'p2flux_ap_fake', '{}', '--format=json')
try { wp('option', 'delete', 'p2flux_ap_fake_calls') } catch {}
wp('db', 'query', "DELETE FROM wp_options WHERE option_name LIKE 'p2flux\\_ap\\_fake\\_used\\_%'")
clearTransients()
wp('eval', `global $wpdb; $wpdb->query("TRUNCATE {$wpdb->prefix}p2flux_ap_payments");`)
const settings = { wallet: WALLET, environment: 'test', default_price: '0.05', paid_post_types: ['post'], paid_categories: [], paid_routes: ['/paid/v1/'], api_down: 'refuse', prepaid: 'yes' }
const setSettings = (over = {}) => wp('option', 'update', 'p2flux_ap_settings', JSON.stringify({ ...settings, ...over }), '--format=json')
setSettings()
for (const id of wp('post', 'list', '--post_type=post,page', '--format=ids').split(' ').filter(Boolean)) wp('post', 'delete', id, '--force')
const mk = (type, title, content, meta) => {
  const id = wp('post', 'create', `--post_type=${type}`, `--post_title=${title}`, `--post_content=${content}`, '--post_status=publish', '--porcelain')
  if (meta !== undefined) wp('post', 'meta', 'update', id, '_p2flux_ap_price', meta)
  return { id, url: wp('post', 'url', id) }
}
const paid = mk('post', 'Paid post', 'POST-SECRET-1')
const paid2 = mk('post', 'Second paid post', 'POST-SECRET-2')
const freePost = mk('post', 'Free post', 'FREE-POST-TEXT', '0')
const freePage = mk('page', 'Free page', 'FREE-PAGE-TEXT')
const pricedPage = mk('page', 'Priced page', 'PAGE-SECRET-3', '0.20')
const rel = (u) => u.replace(SITE, '')

const results = []
const check = async (name, fn) => {
  try { await fn(); results.push([name, true]); console.log(`PASS  ${name}`) } catch (e) { results.push([name, false]); console.log(`FAIL  ${name}\n      ${e.message.split('\n').slice(0, 4).join('\n      ')}`) }
}

// --- people ------------------------------------------------------------------------------------------
await check('a person reads a paid post as always, nothing is asked', async () => {
  const r = await get(rel(paid.url), { ua: CHROME })
  assert.equal(r.status, 200)
  assert.match(r.text, /POST-SECRET-1/)
  assert.equal(r.headers.get('payment-required'), null)
})
await check('a search engine reads a paid post as always', async () => {
  const r = await get(rel(paid.url), { ua: GOOGLE })
  assert.equal(r.status, 200)
  assert.match(r.text, /POST-SECRET-1/)
})

// --- agents, single posts ----------------------------------------------------------------------------
await check('an agent without payment gets 402 with the x402 requirement, not the text', async () => {
  const r = await get(rel(paid.url))
  assert.equal(r.status, 402)
  assert.doesNotMatch(r.text, /POST-SECRET-1/)
  const req = decode(r.headers.get('payment-required'))
  assert.equal(req.x402Version, 2)
  assert.equal(req.resource.url, paid.url)
  assert.equal(req.accepts[0].amount, '50000')
  assert.equal(req.accepts[0].extra.p2flux.recipient, WALLET)
  assert.match(r.headers.get('cache-control'), /no-store/)
})
await check('an agent that pays reads the post, gets the receipt header, and the payment is logged', async () => {
  const before = calls()
  const r = await get(rel(paid.url), { payment: pay('p1') })
  assert.equal(r.status, 200)
  assert.match(r.text, /POST-SECRET-1/)
  assert.equal(decode(r.headers.get('payment-response')).success, true)
  assert.equal(calls(), before + 1)
  const row = wp('db', 'query', 'SELECT post_id, amount, tx FROM wp_p2flux_ap_payments', '--skip-column-names').split('\t')
  assert.deepEqual(row.slice(0, 2), [paid.id, '50000'])
  assert.match(row[2], /^0x[0-9a-f]{64}$/)
})
await check('the same payment again, even for the same post, is refused - and P2Flux is not asked', async () => {
  const before = calls()
  const r = await get(rel(paid.url), { payment: pay('p1') })
  assert.equal(r.status, 402)
  assert.doesNotMatch(r.text, /POST-SECRET-1/)
  assert.equal(decode(r.headers.get('payment-required')).error, 'invalid_transaction_state')
  assert.equal(calls(), before)
  assert.equal(wp('db', 'query', 'SELECT COUNT(*) FROM wp_p2flux_ap_payments', '--skip-column-names'), '1')
})
await check('the same payment for another post is refused', async () => {
  const r = await get(rel(paid2.url), { payment: pay('p1') })
  assert.equal(r.status, 402)
  assert.doesNotMatch(r.text, /POST-SECRET-2/)
  assert.equal(decode(r.headers.get('payment-required')).error, 'invalid_transaction_state')
})
await check('a payment P2Flux refuses gets 402 with the reason', async () => {
  const r = await get(rel(paid2.url), { payment: pay('bad-1') })
  assert.equal(r.status, 402)
  assert.equal(decode(r.headers.get('payment-required')).error, 'invalid_exact_evm_insufficient_balance')
})
await check('a header that is not base64, or too long, is refused without asking P2Flux', async () => {
  const before = calls()
  for (const h of ['not base64 !!', 'A'.repeat(9000), '{"id":"x"}']) {
    const r = await get(rel(paid2.url), { payment: h })
    assert.equal(r.status, 402, h.slice(0, 20))
    assert.equal(decode(r.headers.get('payment-required')).error, 'invalid_payload')
  }
  assert.equal(calls(), before)
})
await check('a free page and a post priced 0 are free for agents', async () => {
  assert.match((await get(rel(freePage.url))).text, /FREE-PAGE-TEXT/)
  assert.match((await get(rel(freePost.url))).text, /FREE-POST-TEXT/)
})
await check("a page with its own price asks that price, though pages are not paid", async () => {
  const r = await get(rel(pricedPage.url))
  assert.equal(r.status, 402)
  assert.equal(decode(r.headers.get('payment-required')).accepts[0].amount, '200000')
})
await check('the price is the one set now: a payment made for an old price is refused', async () => {
  setSettings({ default_price: '0.07' })
  const r = await get(rel(paid2.url), { payment: pay('old-price', { price: '0.05' }) })
  assert.equal(r.status, 402)
  assert.equal(decode(r.headers.get('payment-required')).error, 'invalid_payment_requirements')
  assert.equal(decode(r.headers.get('payment-required')).accepts[0].amount, '70000')
  setSettings()
})

// --- prepaid (batch settlement) ------------------------------------------------------------------
await check('the 402 offers pay-per-page and prepaid; switched off, pay-per-page only', async () => {
  clearTransients()
  const both = decode((await get(rel(paid.url))).headers.get('payment-required')).accepts.map((a) => a.scheme)
  assert.deepEqual(both, ['exact', 'batch-settlement'])
  setSettings({ prepaid: 'no' })
  const one = decode((await get(rel(paid.url))).headers.get('payment-required')).accepts.map((a) => a.scheme)
  assert.deepEqual(one, ['exact'])
  setSettings()
})
await check('a prepaid payment reads the post and is logged as prepaid, with its receipt', async () => {
  const r = await get(rel(paid2.url), { payment: pay('batch-1') })
  assert.equal(r.status, 200)
  assert.match(r.text, /POST-SECRET-2/)
  const row = wp('db', 'query', "SELECT scheme, tx FROM wp_p2flux_ap_payments WHERE scheme = 'batch-settlement'", '--skip-column-names').split('\t')
  assert.equal(row[0], 'batch-settlement')
  assert.match(row[1], /^0x[0-9a-f]{64}$/)
})
await check('a prepaid refusal forwards P2Flux\'s own 402 - the channel state the agent resyncs to', async () => {
  const r = await get(rel(paid2.url), { payment: pay('batchbad-1') })
  assert.equal(r.status, 402)
  const req = decode(r.headers.get('payment-required'))
  assert.equal(req.error, 'batch_settlement_stale_cumulative_amount')
  assert.equal(req.accepts[0].extra.channelState.chargedCumulativeAmount, '150000')
  assert.doesNotMatch(r.text, /POST-SECRET-2/)
})
await check('the settings page shows prepaid rows without a transaction link', async () => {
  const html = wp('eval', 'wp_set_current_user(1); ob_start(); P2Flux_AP_Settings::render(); echo ob_get_clean();')
  assert.match(html, /prepaid/)
})

// --- lists, feeds, REST ------------------------------------------------------------------------------
await check('in the home page, archive, search and feed an agent sees the price, not the text', async () => {
  for (const path of ['/', '/?s=post', '/feed/', `/${new Date().getFullYear()}/`]) {
    const r = await get(path)
    assert.equal(r.status, 200, path)
    assert.doesNotMatch(r.text, /POST-SECRET-1|POST-SECRET-2/, path)
  }
  assert.match((await get('/feed/')).text, /FREE-POST-TEXT/)
  assert.match((await get('/feed/', { ua: CHROME })).text, /POST-SECRET-1/, 'people still get the full feed')
})
await check('the REST collection hides paid text from agents; the paid item asks for payment, then serves', async () => {
  const list = await get('/wp-json/wp/v2/posts')
  assert.equal(list.status, 200)
  assert.doesNotMatch(list.text, /POST-SECRET/)
  const item = await get(`/wp-json/wp/v2/posts/${paid2.id}`)
  assert.equal(item.status, 402)
  assert.equal(decode(item.headers.get('payment-required')).resource.mimeType, 'application/json')
  const paidItem = await get(`/wp-json/wp/v2/posts/${paid2.id}`, { payment: pay('rest-1') })
  assert.equal(paidItem.status, 200)
  assert.match(paidItem.text, /POST-SECRET-2/)
  assert.ok(paidItem.headers.get('payment-response'))
  const viaQuery = await get(`/?rest_route=/wp/v2/posts/${paid.id}`)
  assert.equal(viaQuery.status, 402, 'the ?rest_route= form is gated too')
})
await check('a paid API route asks everyone who is not logged in, and serves after payment', async () => {
  const person = await get('/wp-json/paid/v1/data', { ua: CHROME })
  assert.equal(person.status, 402)
  assert.doesNotMatch(person.text, /ROUTE-SECRET/)
  const r = await get('/wp-json/paid/v1/data', { ua: CHROME, payment: pay('route-1') })
  assert.equal(r.status, 200)
  assert.match(r.text, /ROUTE-SECRET-42/)
})
await check('WordPress routes stay free: listing /wp/v2/ as paid is not possible', async () => {
  setSettings({ paid_routes: ['/wp/v2/'] })
  assert.equal((await get('/wp-json/wp/v2/pages', { ua: CHROME })).status, 200)
  setSettings()
})

// --- concurrency, outages ----------------------------------------------------------------------------
await check('one payment on two requests at the same moment: one is served, one is refused', async () => {
  const h = pay('slow-race')
  const [a, b] = await Promise.all([get(rel(paid2.url), { payment: h }), get(rel(paid2.url), { payment: h })])
  const statuses = [a.status, b.status].sort()
  assert.deepEqual(statuses, [200, 402])
  assert.equal([a, b].filter((r) => /POST-SECRET-2/.test(r.text)).length, 1)
})
await check('P2Flux unreachable: agents get 503 and Retry-After; people never notice', async () => {
  clearTransients()
  wp('option', 'update', 'p2flux_ap_fake', '{"down":true}', '--format=json')
  const r = await get(rel(paid.url))
  assert.equal(r.status, 503)
  assert.equal(r.headers.get('retry-after'), '60')
  assert.doesNotMatch(r.text, /POST-SECRET/)
  assert.equal((await get(rel(paid.url), { ua: CHROME })).status, 200)
})
await check('P2Flux unreachable and the owner chose "free": agents read', async () => {
  setSettings({ api_down: 'free' })
  const r = await get(rel(paid.url))
  assert.equal(r.status, 200)
  assert.match(r.text, /POST-SECRET-1/)
  setSettings()
  wp('option', 'update', 'p2flux_ap_fake', '{}', '--format=json')
})
await check('a cached challenge still answers 402 while P2Flux is briefly unreachable', async () => {
  assert.equal((await get(rel(paid.url))).status, 402)
  wp('option', 'update', 'p2flux_ap_fake', '{"down":true}', '--format=json')
  const r = await get(rel(paid.url))
  assert.equal(r.status, 402)
  wp('option', 'update', 'p2flux_ap_fake', '{}', '--format=json')
})

// --- discovery, settings, uninstall ------------------------------------------------------------------
await check('/.well-known/x402 says what is paid and for how much', async () => {
  const r = await get('/.well-known/x402', { ua: CHROME })
  assert.equal(r.status, 200)
  const d = JSON.parse(r.text)
  assert.equal(d.price, '0.05')
  assert.deepEqual(d.paid.post_types, ['post'])
  assert.equal(d.accepts[0].amount, '50000')
})
await check('settings refuse a wallet P2Flux rejects and keep the old one', async () => {
  const out = wp('eval', `$c = P2Flux_AP_Settings::sanitize( array( 'wallet' => '0x000000000000000000000000000000000000dEaD', 'default_price' => '0.05', 'environment' => 'test' ) ); echo $c['wallet'];`)
  assert.equal(out, WALLET)
  const bad = wp('eval', `$c = P2Flux_AP_Settings::sanitize( array( 'wallet' => 'hello', 'default_price' => '0.001', 'environment' => 'mainnet', 'paid_post_types' => array( 'post', 'attachment', 'nope' ), 'paid_routes' => "/shop/v1/\\n/wp/v2/" ) ); echo wp_json_encode( $c );`)
  const c = JSON.parse(bad)
  assert.equal(c.wallet, WALLET)
  assert.equal(c.default_price, '0.05')
  assert.equal(c.environment, 'test')
  assert.deepEqual(c.paid_post_types, ['post'])
  assert.deepEqual(c.paid_routes, ['/shop/v1/'])
})
await check('uninstall removes settings, prices, log and cache; payments stay on chain', async () => {
  wp('eval', `define( 'WP_UNINSTALL_PLUGIN', true ); include WP_PLUGIN_DIR . '/p2flux-agent-paywall/uninstall.php';`)
  assert.equal(wp('db', 'query', "SHOW TABLES LIKE 'wp_p2flux_ap_payments'", '--skip-column-names'), '')
  assert.equal(wp('db', 'query', "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '%p2flux_ap_settings%' OR option_name LIKE '%transient%p2flux_ap_%'", '--skip-column-names'), '0')
  assert.equal(wp('db', 'query', "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_p2flux_ap_price'", '--skip-column-names'), '0')
  wp('plugin', 'deactivate', 'p2flux-agent-paywall')
  wp('plugin', 'activate', 'p2flux-agent-paywall')
})

const failed = results.filter(([, ok]) => !ok).length
console.log(`\n${results.length - failed}/${results.length} passed`)
const log = `${PATH}/wp-content/debug.log`
try { const t = execFileSync('grep', ['-c', 'p2flux', log], { encoding: 'utf8' }).trim(); if (t !== '0') console.log(`debug.log mentions p2flux ${t} times - read it`) } catch {}
process.exit(failed ? 1 : 0)
