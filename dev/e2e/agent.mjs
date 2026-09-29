// Live on Base Sepolia: the official, unmodified @x402/fetch agent pays a local WordPress running this
// plugin, settled by api-test. Spends test USDC and relayer test ETH.
//
//   dev/local-wp.sh &
//   cd dev/e2e && npm install && set -a && . ~/projects/p2flux_payment/.env && set +a && node agent.mjs
//   (SELLER_WALLET, P2FLUX_E2E_WALLET_PRIVATE_KEY with test USDC)
import { execFileSync } from 'node:child_process'
import { wrapFetchWithPaymentFromConfig, decodePaymentResponseHeader } from '@x402/fetch'
import { ExactEvmScheme, toClientEvmSigner } from '@x402/evm'
import { createPublicClient, http, parseAbi, parseEventLogs } from 'viem'
import { privateKeyToAccount } from 'viem/accounts'
import { baseSepolia } from 'viem/chains'

const SITE = process.env.WP_URL || 'http://localhost:8082'
const PATH = `${process.env.HOME}/projects/p2flux_wp_paywall`
const API = 'https://api-test.p2flux.com'
const SELLER = process.env.SELLER_WALLET
const OTHER_SELLER = '0x00000000000000000000000000000000000c0ffe'
const USDC = '0x036CbD53842c5426634e7929541eC2318f3dCF7e'
const RELAYER = '0xC6bec1306F4FA2a81e41945f739829DAfD5908EC'
const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'

const wp = (...a) => execFileSync('wp', [`--path=${PATH}`, ...a], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim()
const chain = createPublicClient({ chain: baseSepolia, transport: http('https://sepolia.base.org') })
const usdc = (a) => chain.readContract({ address: USDC, abi: parseAbi(['function balanceOf(address) view returns (uint256)']), functionName: 'balanceOf', args: [a] })
const relayerNonce = () => chain.getTransactionCount({ address: RELAYER, blockTag: 'pending' })
const paidAbi = parseAbi(['event Paid(bytes32 indexed ref, address indexed recipient, address indexed token, uint256 net, uint256 fee)'])
const decode = (h) => JSON.parse(Buffer.from(h, 'base64').toString())
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

// --- the site: real API, wallet, posts are paid ----------------------------------------------------
try { wp('option', 'delete', 'p2flux_ap_fake') } catch {}
wp('transient', 'delete', '--all')
wp('option', 'update', 'p2flux_ap_settings', JSON.stringify({ wallet: SELLER, environment: 'test', default_price: '0.05', paid_post_types: ['post'], paid_categories: [], paid_routes: [], api_down: 'refuse' }), '--format=json')
const mk = (title, content) => {
  const id = wp('post', 'create', '--post_type=post', `--post_title=${title}`, `--post_content=${content}`, '--post_status=publish', '--porcelain')
  return { id, url: wp('post', 'url', id) }
}
const posts = [mk('E2E paid 1', 'E2E-SECRET-1'), mk('E2E paid 2', 'E2E-SECRET-2'), mk('E2E paid 3', 'E2E-SECRET-3'), mk('E2E paid 4', 'E2E-SECRET-4')]

// --- the agent: official client, nothing P2Flux-specific --------------------------------------------
const account = privateKeyToAccount(process.env.P2FLUX_E2E_WALLET_PRIVATE_KEY)
const scheme = { network: 'eip155:84532', client: new ExactEvmScheme(toClientEvmSigner(account, chain)) }
let lastSent = null
const recordingFetch = async (input, init) => {
  const h = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined))
  if (h.get('payment-signature')) lastSent = h.get('payment-signature')
  return fetch(input, init)
}
const agent = wrapFetchWithPaymentFromConfig(recordingFetch, { schemes: [scheme] })
// Signs a payment for a URL and returns the header WITHOUT sending it to the site.
const signOnly = async (url) => {
  let captured = null
  const intercept = async (input, init) => {
    const h = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined))
    if (h.get('payment-signature')) {
      captured = h.get('payment-signature')
      return new Response('{}', { status: 200 })
    }
    return fetch(input, init)
  }
  await wrapFetchWithPaymentFromConfig(intercept, { schemes: [scheme] })(url)
  return captured
}
const raw = (url, header, ua = 'node') => fetch(url, { headers: { 'user-agent': ua, ...(header ? { 'payment-signature': header } : {}) }, redirect: 'manual' })

const results = []
const check = (name, ok, detail) => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  -- ' + detail : ''}`) }

// 1. Pay for a post, read it; the money arrived on chain, less the fee; the site logged it.
{
  const [sellerBefore] = await Promise.all([usdc(SELLER)])
  const res = await agent(posts[0].url)
  const body = await res.text()
  const settlement = res.headers.get('payment-response') ? decodePaymentResponseHeader(res.headers.get('payment-response')) : null
  let paid = null
  if (settlement?.transaction) {
    const receipt = await chain.waitForTransactionReceipt({ hash: settlement.transaction })
    ;[paid] = parseEventLogs({ abi: paidAbi, eventName: 'Paid', logs: receipt.logs })
  }
  await sleep(3000)
  const sellerAfter = await usdc(SELLER)
  const logged = wp('db', 'query', `SELECT tx FROM wp_p2flux_ap_payments WHERE post_id = ${posts[0].id}`, '--skip-column-names')
  check('1  agent pays 0.05, reads the post; seller +0.047, fee 0.003 on chain; site logged the transaction',
    res.status === 200 && body.includes('E2E-SECRET-1') && paid?.args.net === 47000n && paid?.args.fee === 3000n &&
      paid?.args.recipient.toLowerCase() === SELLER.toLowerCase() && sellerAfter - sellerBefore === 47000n && logged === settlement?.transaction,
    `HTTP ${res.status} net=${paid?.args.net} fee=${paid?.args.fee} seller +${sellerAfter - sellerBefore} logged=${logged.slice(0, 12)}`)
}

// 2. The same payment header again: same page served from the site's record, another page refused.
{
  const n0 = await relayerNonce()
  const same = await raw(posts[0].url, lastSent)
  const other = await raw(posts[1].url, lastSent)
  const otherText = await other.text()
  const n1 = await relayerNonce()
  check('2  the same payment again: same page served, another page refused, nothing sent',
    same.status === 200 && other.status === 402 && !otherText.includes('E2E-SECRET-2') && decode(other.headers.get('payment-required')).error === 'invalid_transaction_state' && n0 === n1,
    `same=${same.status} other=${other.status} relayer txs=${n1 - n0}`)
}

// 3. One payment on two requests at the same moment.
{
  const header = await signOnly(posts[1].url)
  const n0 = await relayerNonce()
  const [a, b] = await Promise.all([raw(posts[1].url, header), raw(posts[1].url, header)])
  const texts = await Promise.all([a.text(), b.text()])
  await sleep(4000)
  const n1 = await relayerNonce()
  check('3  one payment, two requests at once: one served, one refused, one transaction',
    [a.status, b.status].sort().join() === '200,402' && texts.filter((t) => t.includes('E2E-SECRET-2')).length === 1 && n1 - n0 === 1,
    `HTTP ${a.status}+${b.status} relayer txs=${n1 - n0}`)
}

// 4. A payment made for another site (another seller) is refused; nothing is sent.
{
  const challenge = await (await fetch(`${API}/x402/paywall/challenge`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ recipient: OTHER_SELLER, price: '0.05' }) })).json()
  // A site that sells the same page for another wallet: serve its 402 to the agent, keep the header.
  let captured = null
  const otherSite = async (input, init) => {
    const h = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined))
    if (h.get('payment-signature')) { captured = h.get('payment-signature'); return new Response('{}', { status: 200 }) }
    const required = { x402Version: 2, resource: { url: posts[2].url, mimeType: 'text/html' }, accepts: challenge.accepts }
    return new Response('{}', { status: 402, headers: { 'PAYMENT-REQUIRED': Buffer.from(JSON.stringify(required)).toString('base64') } })
  }
  await wrapFetchWithPaymentFromConfig(otherSite, { schemes: [scheme] })(posts[2].url)
  const n0 = await relayerNonce()
  const r = await raw(posts[2].url, captured)
  const text = await r.text()
  const n1 = await relayerNonce()
  const reason = r.headers.get('payment-required') ? decode(r.headers.get('payment-required')).error : null
  check('4  a payment signed for another seller is refused here, nothing sent',
    r.status === 402 && !text.includes('E2E-SECRET-3') && reason === 'invalid_payment_requirements' && n0 === n1,
    `HTTP ${r.status} reason=${reason} relayer txs=${n1 - n0}`)
}

// 5. The price changed between the 402 and the payment: the payment for the old price is refused.
{
  const header = await signOnly(posts[3].url) // signed for 0.05
  wp('option', 'patch', 'update', 'p2flux_ap_settings', 'default_price', '0.07')
  const n0 = await relayerNonce()
  const r = await raw(posts[3].url, header)
  const n1 = await relayerNonce()
  const required = decode(r.headers.get('payment-required'))
  check('5  price raised after the 402: the old payment is refused and the new price asked, nothing sent',
    r.status === 402 && required.error === 'invalid_payment_requirements' && required.accepts[0].amount === '70000' && n0 === n1,
    `HTTP ${r.status} reason=${required.error} asks=${required.accepts[0].amount}`)
  wp('option', 'patch', 'update', 'p2flux_ap_settings', 'default_price', '0.05')
}

// 6. The REST item is paid the same way.
{
  const res = await agent(`${SITE}/wp-json/wp/v2/posts/${posts[3].id}`)
  const text = await res.text()
  check('6  the REST item of a paid post: agent pays and reads it', res.status === 200 && text.includes('E2E-SECRET-4') && !!res.headers.get('payment-response'), `HTTP ${res.status}`)
}

// 7. A person with a browser never pays.
{
  const r = await raw(posts[3].url, null, CHROME)
  check('7  a browser reads a paid post as always', r.status === 200 && (await r.text()).includes('E2E-SECRET-4'), `HTTP ${r.status}`)
}

for (const p of posts) wp('post', 'delete', p.id, '--force')
console.log(`\n${results.filter(Boolean).length}/${results.length} passed`)
process.exit(results.every(Boolean) ? 0 : 1)
