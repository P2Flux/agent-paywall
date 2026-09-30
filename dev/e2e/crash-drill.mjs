// Crash drill on Base Sepolia: prepaid pages, the API process killed with SIGKILL in the middle.
// After it, every page the agent was served for must still be on the API's disk (the seller's money).
import { execFileSync, execSync } from 'node:child_process'
import { x402Client, wrapFetchWithPayment } from '@x402/fetch'
import { toClientEvmSigner } from '@x402/evm'
import { BatchSettlementEvmScheme } from '@x402/evm/batch-settlement/client'
import { createPublicClient, http } from 'viem'
import { privateKeyToAccount } from 'viem/accounts'
import { baseSepolia } from 'viem/chains'

const PATH = `${process.env.HOME}/projects/p2flux_wp_paywall`
const wp = (...a) => execFileSync('wp', [`--path=${PATH}`, ...a], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim()
const ssh = (cmd) => execSync(`ssh p2flux-test "${cmd}"`, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim()
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const chain = createPublicClient({ chain: baseSepolia, transport: http('https://sepolia.base.org') })

try { wp('option', 'delete', 'p2flux_ap_fake') } catch {}
wp('transient', 'delete', '--all')
wp('option', 'update', 'p2flux_ap_settings', JSON.stringify({ wallet: process.env.SELLER_WALLET, environment: 'test', default_price: '0.05', paid_post_types: ['post'], paid_categories: [], paid_routes: [], api_down: 'refuse', prepaid: 'yes' }), '--format=json')
const posts = Array.from({ length: 20 }, (_, i) => {
  const id = wp('post', 'create', '--post_type=post', `--post_title=Crash ${i}`, `--post_content=CRASH-SECRET-${i}`, '--post_status=publish', '--porcelain')
  return { id, url: wp('post', 'url', id) }
})
const account = privateKeyToAccount(process.env.P2FLUX_E2E_WALLET_PRIVATE_KEY)
const client = new x402Client()
client.register('eip155:*', new BatchSettlementEvmScheme(toClientEvmSigner(account, chain)))
const agent = wrapFetchWithPayment(fetch, client)
const charged = () => {
  // One file per line: channel files carry no trailing newline, and there is more than one.
  const out = ssh(`sudo sh -c 'for f in /var/lib/p2flux/x402-batch/*/server/*.json; do cat \\$f; echo; done'`)
  const mine = out.split(/\n(?=\{)/).map((j) => { try { return JSON.parse(j) } catch { return null } })
    .filter((c) => c?.channelConfig?.payer?.toLowerCase() === account.address.toLowerCase())
  return mine.reduce((sum, c) => sum + BigInt(c.chargedCumulativeAmount), 0n)
}
const read = async (list) => { let n = 0; for (const p of list) { const r = await agent(p.url); if (r.status === 200 && (await r.text()).includes('CRASH-SECRET')) n++ } return n }

const before = charged()
const first = await read(posts.slice(0, 10))
ssh('sudo systemctl kill --kill-whom=main -s KILL p2flux-api || true')
let up = false
for (let i = 0; i < 40 && !up; i++) { await sleep(3000); up = await fetch('https://api-test.p2flux.com/x402/supported').then((r) => r.ok).catch(() => false) }
await sleep(8000)
const afterCrash = charged()
const second = await read(posts.slice(10))
const after = charged()
for (const p of posts) wp('post', 'delete', p.id, '--force')

const ok1 = afterCrash - before === BigInt(first) * 50_000n
const ok2 = after - before === BigInt(first + second) * 50_000n
console.log(`${ok1 ? 'PASS' : 'FAIL'}  after SIGKILL every served page is on disk  -- served ${first}, recorded ${(afterCrash - before) / 50_000n}`)
console.log(`${ok2 && second === 10 ? 'PASS' : 'FAIL'}  the agent carries on after the restart  -- served ${second} more, recorded ${(after - before) / 50_000n} in all`)
process.exit(ok1 && ok2 && second === 10 ? 0 : 1)
