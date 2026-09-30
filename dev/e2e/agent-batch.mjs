// Live on Base Sepolia: prepaid (x402 batch settlement). The official agent with the batch client
// deposits once into the standard escrow, reads 50 paid posts with no transaction per read; the
// api-test worker claims, pays out and flushes the seller's batch vault (97% seller / 3% P2Flux).
//
//   cd dev/e2e && npm install && set -a && . ~/projects/p2flux_payment/.env && set +a && node agent-batch.mjs
//   (P2FLUX_E2E_WALLET_PRIVATE_KEY needs ~3.2 test USDC; waits up to 20 min for the worker)
import { execFileSync } from 'node:child_process'
import { x402Client, wrapFetchWithPayment } from '@x402/fetch'
import { ExactEvmScheme, toClientEvmSigner } from '@x402/evm'
import { BatchSettlementEvmScheme } from '@x402/evm/batch-settlement/client'
import { createPublicClient, http, parseAbi } from 'viem'
import { privateKeyToAccount } from 'viem/accounts'
import { baseSepolia } from 'viem/chains'

const PATH = `${process.env.HOME}/projects/p2flux_wp_paywall`
const SELLER = process.env.SELLER_WALLET
const USDC = '0x036CbD53842c5426634e7929541eC2318f3dCF7e'
const RELAYER = '0xC6bec1306F4FA2a81e41945f739829DAfD5908EC'
const PAGES = Number(process.env.PAGES || 50)

const wp = (...a) => execFileSync('wp', [`--path=${PATH}`, ...a], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim()
const chain = createPublicClient({ chain: baseSepolia, transport: http('https://sepolia.base.org') })
const bal = (a) => chain.readContract({ address: USDC, abi: parseAbi(['function balanceOf(address) view returns (uint256)']), functionName: 'balanceOf', args: [a] })
const relayerNonce = () => chain.getTransactionCount({ address: RELAYER, blockTag: 'pending' })
const decode = (h) => JSON.parse(Buffer.from(h, 'base64').toString())
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

try { wp('option', 'delete', 'p2flux_ap_fake') } catch {}
wp('transient', 'delete', '--all')
wp('option', 'update', 'p2flux_ap_settings', JSON.stringify({ wallet: SELLER, environment: 'test', default_price: '0.05', paid_post_types: ['post'], paid_categories: [], paid_routes: [], api_down: 'refuse', prepaid: 'yes' }), '--format=json')
const posts = Array.from({ length: PAGES }, (_, i) => {
  const id = wp('post', 'create', '--post_type=post', `--post_title=Batch ${i}`, `--post_content=BATCH-SECRET-${i}`, '--post_status=publish', '--porcelain')
  return { id, url: wp('post', 'url', id) }
})

const account = privateKeyToAccount(process.env.P2FLUX_E2E_WALLET_PRIVATE_KEY)
const signer = toClientEvmSigner(account, chain)
const results = []
const check = (name, ok, detail) => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  -- ' + detail : ''}`) }

// 1. Prepaid: one deposit, then pages with no transaction each.
const batch = new x402Client()
batch.register('eip155:*', new BatchSettlementEvmScheme(signer, { depositPolicy: { depositMultiplier: 60 } }))
let lastHeader = null
const recording = async (input, init) => {
  const h = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined))
  if (h.get('payment-signature')) lastHeader = h.get('payment-signature')
  return fetch(input, init)
}
const agent = wrapFetchWithPayment(recording, batch)
const [agent0, startBlock] = await Promise.all([bal(account.address), chain.getBlockNumber()])
const n0 = await relayerNonce()
let read = 0
for (const p of posts) {
  const r = await agent(p.url)
  if (r.status === 200 && (await r.text()).includes('BATCH-SECRET')) read++
  else console.log('page', p.url, r.status)
}
const n1 = await relayerNonce()
const agent1 = await bal(account.address)
const rows = wp('db', 'query', "SELECT COUNT(*) FROM wp_p2flux_ap_payments WHERE scheme = 'batch-settlement'", '--skip-column-names')
// The official client deposits (and tops up) in steps of at least the $1 minimum: every relayer
// transaction here is a deposit that moved at least $1 of the agent's money - none is per page.
const txs = n1 - n0
check(`1  ${PAGES} pages prepaid: only deposits (>= $1 each), no transaction per page`,
  read === PAGES && txs >= 1 && BigInt(txs) * 1_000_000n <= agent0 - agent1 && txs < PAGES / 10 && Number(rows) >= PAGES,
  `read ${read}/${PAGES}, relayer txs ${txs}, agent deposited ${agent0 - agent1}, logged ${rows}`)

// 2. The same voucher again is refused; nothing is sent.
{
  const r = await fetch(posts[0].url, { headers: { 'user-agent': 'node', 'payment-signature': lastHeader } })
  const text = await r.text()
  check('2  the same voucher again is refused', r.status === 402 && !text.includes('BATCH-SECRET'), `HTTP ${r.status} ${r.headers.get('payment-required') ? decode(r.headers.get('payment-required')).error : ''}`)
}

// 3. An agent that only knows pay-per-page still pays per page on the same site.
{
  const exactOnly = new x402Client()
  exactOnly.register('eip155:*', new ExactEvmScheme(signer))
  const r = await wrapFetchWithPayment(fetch, exactOnly)(posts[1].url)
  const settlement = r.headers.get('payment-response') ? decode(r.headers.get('payment-response')) : null
  check('3  an exact-only agent pays per page', r.status === 200 && !!settlement?.transaction, `HTTP ${r.status} tx ${settlement?.transaction?.slice(0, 12)}`)
}

// 4. The worker pays the seller out: claim, settle, flush - read from the Flushed event, which is
//    what the vault actually paid (it may also hold earlier payments to the same seller).
{
  const VAULTS = '0x08EbEb85c53895F752bdAc9C115aF33FCff04F3E'
  const flushed = parseAbi(['event Flushed(address indexed recipient, uint256 net, uint256 fee)'])
  const owed = BigInt(PAGES) * 50_000n
  const deadline = Date.now() + 20 * 60_000
  let payout = null
  while (Date.now() < deadline && !payout) {
    await sleep(30_000)
    const logs = await chain.getLogs({ address: VAULTS, event: flushed[0], args: { recipient: SELLER }, fromBlock: startBlock })
    if (logs.length) payout = logs.at(-1).args
  }
  const total = payout ? payout.net + payout.fee : 0n
  check('4  worker payout: seller 97%, P2Flux 3% of everything in the vault, at least what was owed',
    !!payout && total >= owed && payout.fee === (total * 300n) / 10_000n,
    payout ? `paid out ${total}: seller ${payout.net}, fee ${payout.fee} (owed ${owed})` : 'no payout within 20 min')
}

for (const p of posts) wp('post', 'delete', p.id, '--force')
console.log(`\n${results.filter(Boolean).length}/${results.length} passed`)
process.exit(results.every(Boolean) ? 0 : 1)
