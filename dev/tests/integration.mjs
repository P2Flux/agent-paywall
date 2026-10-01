// The gate over real HTTP, inside a real WordPress, against the fake API (no network, no chain).
//
//   dev/local-wp.sh &            # serves http://localhost:8082
//   node dev/tests/integration.mjs
import { execFileSync } from 'node:child_process'
import assert from 'node:assert/strict'
import { createHash, generateKeyPairSync, sign as edSign } from 'node:crypto'
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const SITE = process.env.WP_URL || 'http://localhost:8082'
const PATH = `${process.env.HOME}/projects/p2flux_wp_paywall`
const WALLET = '0xb4e43f3fBa5Add75395adAD366627E7d74141Fa9'
const GPT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)'
const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'
const GOOGLE = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'

const wp = (...args) => execFileSync('wp', [`--path=${PATH}`, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim()
const b64 = (v) => Buffer.from(JSON.stringify(v)).toString('base64')
const pay = (id, extra = {}) => b64({ id, ...extra })
const get = async (path, { ua = GPT, payment, method = 'GET', extra = {} } = {}) => {
  const headers = { 'user-agent': ua, ...extra }
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
const settings = { wallet: WALLET, environment: 'test', default_price: '0.05', paid_post_types: ['post'], paid_categories: [], paid_routes: ['/paid/v1/'], api_down: 'refuse', prepaid: 'yes', directory: 'yes' }
const setSettings = (over = {}) => wp('option', 'update', 'p2flux_ap_settings', JSON.stringify({ ...settings, ...over }), '--format=json')
setSettings()
for (const id of wp('post', 'list', '--post_type=post,page,attachment', '--post_status=any', '--format=ids').split(' ').filter(Boolean)) wp('post', 'delete', id, '--force')
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
  // P2Flux is back. The plugin would find out within 30 s on its own; the test does not wait for that.
  wp('transient', 'delete', 'p2flux_ap_ch_down_test')
})
await check('a cached challenge still answers 402 while P2Flux is briefly unreachable', async () => {
  assert.equal((await get(rel(paid.url))).status, 402)
  wp('option', 'update', 'p2flux_ap_fake', '{"down":true}', '--format=json')
  const r = await get(rel(paid.url))
  assert.equal(r.status, 402)
  wp('option', 'update', 'p2flux_ap_fake', '{}', '--format=json')
})

// --- discovery, settings, uninstall ------------------------------------------------------------------
await check('directory: the document lists the latest paid posts; saving the settings tells P2Flux to read it', async () => {
  try { wp('option', 'delete', 'p2flux_ap_fake_refreshes') } catch {}
  setSettings({ default_price: '0.06' })
  setSettings()
  const seen = JSON.parse(wp('option', 'get', 'p2flux_ap_fake_refreshes', '--format=json'))
  assert.ok(seen.length >= 1 && seen.every((s) => s === SITE), JSON.stringify(seen))
  const d = JSON.parse((await get('/.well-known/x402', { ua: CHROME })).text)
  assert.equal(d.directory, true)
  assert.ok(d.samples.length >= 2)
  const titles = d.samples.map((s) => s.title)
  assert.ok(titles.includes('Paid post') && !titles.includes('Free post') && !titles.includes('Free page'), JSON.stringify(titles))
  assert.ok(d.samples.every((s) => s.url.startsWith(SITE) && /^\d+(\.\d+)?$/.test(s.price)))
  assert.doesNotMatch(JSON.stringify(d), /POST-SECRET/, 'titles and prices only, never the text')
})
await check('directory: unticked, the document says so and lists no posts - P2Flux removes the site', async () => {
  try { wp('option', 'delete', 'p2flux_ap_fake_refreshes') } catch {}
  setSettings({ directory: 'no' })
  const d = JSON.parse((await get('/.well-known/x402', { ua: CHROME })).text)
  assert.equal(d.directory, false)
  assert.deepEqual(d.samples, [])
  assert.ok(JSON.parse(wp('option', 'get', 'p2flux_ap_fake_refreshes', '--format=json')).length >= 1, 'P2Flux is told to re-read')
  setSettings()
})
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
// --- prepaid balance taken back ----------------------------------------------------------------------
await check('a refund of the prepaid balance: the receipt, no content, nothing logged', async () => {
  const before = wp('db', 'query', 'SELECT COUNT(*) FROM wp_p2flux_ap_payments', '--skip-column-names')
  const r = await get(rel(paid.url), { payment: pay('refund-1') })
  assert.equal(r.status, 200)
  assert.doesNotMatch(r.text, /POST-SECRET/)
  assert.deepEqual(JSON.parse(r.text), { refunded: true })
  assert.equal(decode(r.headers.get('payment-response')).amount, '900000')
  assert.equal(wp('db', 'query', 'SELECT COUNT(*) FROM wp_p2flux_ap_payments', '--skip-column-names'), before)
})

// --- paid files --------------------------------------------------------------------------------------
const dir = mkdtempSync(join(tmpdir(), 'p2flux-ap-'))
writeFileSync(join(dir, 'paid-data.csv'), 'FILE-SECRET-7,1,2,3\n')
writeFileSync(join(dir, 'free-data.csv'), 'FREE-FILE,1\n')
const media = (name) => { const id = wp('media', 'import', join(dir, name), '--porcelain'); return { id, url: wp('eval', `echo wp_get_attachment_url(${id});`) } }
const paidFile = media('paid-data.csv')
const freeFile = media('free-data.csv')
const HTACCESS = `${PATH}/wp-content/uploads/.htaccess`
await check('files: a price on a file writes the web server rule; the price is set as the owner would', async () => {
  const out = wp('eval', `wp_set_current_user(1); P2Flux_AP_Files::save( array( 'ID' => ${paidFile.id} ), array( 'p2flux_ap_price' => '0,30' ) ); echo get_post_meta( ${paidFile.id}, '_p2flux_ap_price', true );`)
  assert.equal(out, '0.3')
  const rule = readFileSync(HTACCESS, 'utf8')
  assert.match(rule, /# BEGIN P2Flux Agent Paywall[\s\S]*GPTBot[\s\S]*RewriteRule \^\(\(\?:.*paid\\-data.*\)\)\$ \/index\.php\?p2flux_ap_file=\$1 \[L,QSA\][\s\S]*# END P2Flux Agent Paywall/)
  assert.doesNotMatch(rule, /free\\?-data/, 'only priced files are in the rule')
})
await check('files: an agent asking for a paid file gets 402 with its price, not the file', async () => {
  const r = await get(rel(paidFile.url))
  assert.equal(r.status, 402)
  assert.doesNotMatch(r.text, /FILE-SECRET/)
  const req = decode(r.headers.get('payment-required'))
  assert.equal(req.accepts[0].amount, '300000')
  assert.equal(req.resource.url, paidFile.url)
})
await check('files: an agent that pays gets the file, byte for byte, never cached', async () => {
  const r = await get(rel(paidFile.url), { payment: pay('file-1', { price: '0.3' }) })
  assert.equal(r.status, 200)
  assert.equal(r.text, 'FILE-SECRET-7,1,2,3\n')
  assert.match(r.headers.get('content-type'), /text\/csv/)
  assert.match(r.headers.get('cache-control'), /no-store/)
  assert.equal(wp('db', 'query', `SELECT amount FROM wp_p2flux_ap_payments WHERE post_id = ${paidFile.id}`, '--skip-column-names'), '300000')
})
await check('files: the same by its number - /?p2flux_ap_file=<id>', async () => {
  assert.equal((await get(`/?p2flux_ap_file=${paidFile.id}`)).status, 402)
})
await check('files: a person downloads a paid file as always; an agent a file without a price', async () => {
  const person = await get(rel(paidFile.url), { ua: CHROME })
  assert.equal(person.status, 200)
  assert.match(person.text, /FILE-SECRET-7/)
  const agent = await get(rel(freeFile.url))
  assert.equal(agent.status, 200)
  assert.equal(agent.text, 'FREE-FILE,1\n')
})
await check('files: only priced Media Library files are ever sent - nothing outside uploads, no PHP, no file of another plugin, no file without a price', async () => {
  // A file another plugin keeps in uploads and protects itself: this plugin must not become a way around that.
  mkdirSync(`${PATH}/wp-content/uploads/woocommerce_uploads`, { recursive: true })
  writeFileSync(`${PATH}/wp-content/uploads/woocommerce_uploads/secret.csv`, 'OTHER-PLUGIN-SECRET\n')
  for (const p of ['../../wp-config.php', '..%2F..%2Fwp-config.php', '../index.php', '.htaccess', '/etc/passwd', 'nope.csv', '99999999', 'woocommerce_uploads/secret.csv', freeFile.id, freeFile.url.split('/uploads/')[1]]) {
    const r = await get(`/?p2flux_ap_file=${p}`)
    assert.equal(r.status, 404, p)
    assert.equal(r.text, '', p)
  }
})
await check('files: the price removed, the rule is removed', async () => {
  wp('eval', `wp_set_current_user(1); P2Flux_AP_Files::save( array( 'ID' => ${paidFile.id} ), array( 'p2flux_ap_price' => '' ) );`)
  assert.doesNotMatch(readFileSync(HTACCESS, 'utf8'), /RewriteRule/)
  const r = await get(rel(paidFile.url))
  assert.equal(r.status, 200)
})

// --- agents that sign their requests (Web Bot Auth) --------------------------------------------------
const { publicKey, privateKey } = generateKeyPairSync('ed25519')
const jwk = publicKey.export({ format: 'jwk' })
const keyid = createHash('sha256').update(`{"crv":"Ed25519","kty":"OKP","x":"${jwk.x}"}`).digest('base64url')
wp('option', 'update', 'p2flux_ap_fake_jwks', JSON.stringify({ keys: [{ kty: 'OKP', crv: 'Ed25519', x: jwk.x }] }))
const signedHeaders = (authority, agent = '"https://agent.test"') => {
  const now = Math.floor(Date.now() / 1000)
  const inner = `("@authority" "signature-agent");created=${now};keyid="${keyid}";alg="ed25519";expires=${now + 120};tag="web-bot-auth"`
  const base = `"@authority": ${authority}\n"signature-agent": ${agent}\n"@signature-params": ${inner}`
  return { 'signature-agent': agent, 'signature-input': `sig1=${inner}`, signature: `sig1=:${edSign(null, Buffer.from(base), privateKey).toString('base64')}:` }
}
const HOST = new URL(SITE).host
const agentOf = (id) => wp('db', 'query', `SELECT agent FROM wp_p2flux_ap_payments WHERE tx = '0x${createHash('sha256').update(id).digest('hex')}'`, '--skip-column-names')
await check('web bot auth: a request signed as a bot is asked to pay even with a browser user agent', async () => {
  const r = await get(rel(paid.url), { ua: CHROME, extra: signedHeaders(HOST) })
  assert.equal(r.status, 402)
  assert.doesNotMatch(r.text, /POST-SECRET/)
})
await check('web bot auth: a verified agent is named in the payment log', async () => {
  const r = await get(rel(paid.url), { payment: pay('signed-1'), extra: signedHeaders(HOST) })
  assert.equal(r.status, 200)
  assert.equal(agentOf('signed-1'), 'agent.test')
})
await check('web bot auth: a signature made for another site, or by an unknown key, pays but is not named', async () => {
  assert.equal((await get(rel(paid.url), { payment: pay('signed-2'), extra: signedHeaders('other.example') })).status, 200)
  assert.equal(agentOf('signed-2'), '')
  wp('option', 'update', 'p2flux_ap_fake_jwks', JSON.stringify({ keys: [] }))
  clearTransients()
  assert.equal((await get(rel(paid.url), { payment: pay('signed-3'), extra: signedHeaders(HOST) })).status, 200)
  assert.equal(agentOf('signed-3'), '')
})

// --- the owner's own assistant (Abilities API) and the setup check ------------------------------------
const ability = (name, input, user = ['--user=1']) => JSON.parse(wp(...user, 'eval', `$a = wp_get_ability( 'p2flux-agent-paywall/${name}' ); $r = $a ? $a->execute( ${input} ) : 'missing'; echo wp_json_encode( is_wp_error( $r ) ? array( 'error' => $r->get_error_code() ) : $r );`))
await check('abilities: earnings and settings for an administrator', async () => {
  const e = ability('get-earnings', 'null')
  assert.equal(e.currency, 'USDC')
  assert.ok(e.all.payments >= 3 && Number(e.all.amount) > 0, JSON.stringify(e.all))
  assert.ok(e.latest.some((p) => p.agent === 'agent.test'))
  assert.equal(ability('get-settings', 'null').wallet, WALLET)
})
await check('abilities: set-post-price changes what an agent is asked', async () => {
  const out = ability('set-post-price', `array( 'post_id' => ${paid2.id}, 'price' => '0.75' )`)
  assert.equal(out.price, '0.75')
  assert.equal(decode((await get(rel(paid2.url))).headers.get('payment-required')).accepts[0].amount, '750000')
  assert.equal(ability('set-post-price', `array( 'post_id' => ${paid2.id}, 'price' => 'lots' )`).error, 'p2flux_ap_price')
  assert.equal(ability('set-post-price', `array( 'post_id' => ${paid2.id}, 'price' => '' )`).price, '0.05')
})
await check('abilities: nobody but the owner - a visitor is refused', async () => {
  for (const [name, input] of [['get-earnings', 'null'], ['get-settings', 'null'], ['set-post-price', `array( 'post_id' => ${paid2.id}, 'price' => '0' )`]]) {
    assert.ok(ability(name, input, []).error, name)
  }
  assert.equal((await get(rel(paid2.url))).status, 402)
})
await check('setup check: an agent is asked to pay - and it says so when a cache answers instead', async () => {
  const ok = JSON.parse(wp('eval', 'echo wp_json_encode( P2Flux_AP_Settings::cache_check() );'))
  assert.equal(ok.ok, true, ok.message)
  // A cache in front of the site: every request for the page gets the full page.
  wp('option', 'update', 'p2flux_ap_fake', JSON.stringify({ cached: true }), '--format=json')
  const cached = JSON.parse(wp('eval', 'echo wp_json_encode( P2Flux_AP_Settings::cache_check() );'))
  wp('option', 'update', 'p2flux_ap_fake', '{}', '--format=json')
  assert.equal(cached.ok, false, cached.message)
  assert.match(cached.message, /cache/i)
})

// --- what comes back from P2Flux is checked too -------------------------------------------------------
await check('an answer header that is not base64 never reaches the agent', async () => {
  const r = await get(rel(paid.url), { payment: pay('junkheader-1') })
  assert.equal(r.status, 200)
  assert.equal(r.headers.get('payment-response'), null)
  assert.equal(r.headers.get('set-cookie'), null)
})
await check('a LIVE site paid on the test network serves nothing - real content is never sold for test money', async () => {
  setSettings({ environment: 'live' })
  clearTransients()
  const r = await get(rel(paid.url), { payment: pay('wrongnet-1') })
  setSettings()
  clearTransients()
  assert.equal(r.status, 503)
  assert.doesNotMatch(r.text, /POST-SECRET/)
})
await check('P2Flux down: one request asks, the next ones within 30 s do not queue behind it', async () => {
  wp('option', 'update', 'p2flux_ap_fake', JSON.stringify({ down: true }), '--format=json')
  clearTransients()
  try { wp('option', 'delete', 'p2flux_ap_fake_calls') } catch {}
  assert.equal((await get(rel(paid.url))).status, 503)
  const asked = calls()
  for (let i = 0; i < 3; i++) assert.equal((await get(rel(paid2.url))).status, 503)
  assert.equal(calls(), asked, 'no new call to P2Flux while it is marked down')
  wp('option', 'update', 'p2flux_ap_fake', '{}', '--format=json')
  clearTransients()
})
await check('files: clearing the wallet removes the uploads rule; setting it again restores it', async () => {
  wp('eval', `wp_set_current_user(1); P2Flux_AP_Files::save( array( 'ID' => ${paidFile.id} ), array( 'p2flux_ap_price' => '0.3' ) );`)
  assert.match(readFileSync(HTACCESS, 'utf8'), /RewriteRule/)
  wp('eval', `$s = get_option( 'p2flux_ap_settings' ); $s['wallet'] = ''; update_option( 'p2flux_ap_settings', $s );`)
  assert.doesNotMatch(readFileSync(HTACCESS, 'utf8'), /RewriteRule/)
  setSettings()
  assert.match(readFileSync(HTACCESS, 'utf8'), /RewriteRule/)
  wp('eval', `wp_set_current_user(1); P2Flux_AP_Files::save( array( 'ID' => ${paidFile.id} ), array( 'p2flux_ap_price' => '' ) );`)
})

// --- hooks for membership plugins (dev/mu-plugins/p2flux-ap-membership-fixture.php) ------------------
const member = mk('page', 'Member page', 'MEMBER-SECRET')
wp('post', 'meta', 'update', member.id, '_ap_fixture_member', '1')
try { wp('option', 'delete', 'p2flux_ap_fixture_tokens') } catch {}
let memberToken = ''
await check('membership: the price filter prices a page the rules leave free, and the 402 says what it buys', async () => {
  const r = await get(rel(member.url))
  assert.equal(r.status, 402)
  const req = decode(r.headers.get('payment-required'))
  assert.equal(req.accepts[0].amount, '300000')
  assert.match(req.resource.description, /MEMBERSHIP-OFFER/)
  assert.ok(req.accepts.length > 0, 'a plugin cannot empty "accepts"')
  assert.match(r.headers.get('vary') ?? '', /P2Flux-Access-Token/)
})
await check('membership: the payment fires p2flux_ap_paid with the payer, and the plugin answers with a token', async () => {
  const r = await get(rel(member.url), { payment: pay('member-1') })
  assert.equal(r.status, 200)
  assert.match(r.text, /MEMBER-SECRET/)
  memberToken = r.headers.get('p2flux-access-token') ?? ''
  assert.match(memberToken, /^[A-Za-z0-9_-]{43}$/)
  const p = JSON.parse(wp('option', 'get', 'p2flux_ap_fixture_last_payment', '--format=json'))
  assert.equal(p.price, '0.3')
  assert.equal(p.units, 300000)
  assert.equal(p.post_id, Number(member.id))
  assert.match(p.payer, /^0x[0-9a-fA-F]{40}$/)
  assert.match(p.tx, /^0x[0-9a-f]{64}$/)
})
await check('membership: with the token the page is served, nothing is asked of P2Flux and nothing is cached', async () => {
  const asked = calls()
  const r = await get(rel(member.url), { extra: { 'p2flux-access-token': `junk, ${memberToken}` } })
  assert.equal(r.status, 200)
  assert.match(r.text, /MEMBER-SECRET/)
  assert.equal(calls(), asked, 'no call to P2Flux')
  assert.match(r.headers.get('cache-control') ?? '', /no-store|no-cache/)
})
await check('membership: a token does not open posts it was not issued for', async () => {
  const r = await get(rel(paid.url), { extra: { 'p2flux-access-token': memberToken } })
  assert.equal(r.status, 402)
})
await check('membership: an expired or unknown token is asked to pay again', async () => {
  assert.equal((await get(rel(member.url), { extra: { 'p2flux-access-token': 'x'.repeat(43) } })).status, 402)
  wp('eval', `$t = get_option( 'p2flux_ap_fixture_tokens' ); foreach ( $t as $k => $v ) { $t[ $k ] = time() - 1; } update_option( 'p2flux_ap_fixture_tokens', $t );`)
  assert.equal((await get(rel(member.url), { extra: { 'p2flux-access-token': memberToken } })).status, 402)
})
await check('an agent is never answered from a page cache, lists included; people keep their caching', async () => {
  const agent = await get('/')
  assert.match(agent.headers.get('cache-control') ?? '', /no-store|no-cache/)
  const person = await get('/', { ua: CHROME })
  assert.doesNotMatch(person.headers.get('cache-control') ?? '', /no-store/)
})
await check('membership: a person never sees any of it', async () => {
  const r = await get(rel(member.url), { ua: CHROME })
  assert.equal(r.status, 200)
  assert.match(r.text, /MEMBER-SECRET/)
})
wp('post', 'delete', member.id, '--force')

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
