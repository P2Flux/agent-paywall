// Store images for WordPress.org (.wordpress-org/): icon, banner, screenshots of the local test site
// in fake-API mode. Development only.
//
//   dev/local-wp.sh &   PLAYWRIGHT=/path/to/node_modules/playwright/index.mjs node dev/assets/make.mjs
import { execFileSync } from 'node:child_process'
import { createHash, generateKeyPairSync, sign } from 'node:crypto'
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const out = join(here, '../../.wordpress-org')
const SITE = 'http://localhost:8082'
const PATH = `${process.env.HOME}/projects/p2flux_wp_paywall`
const wp = (...a) => execFileSync('wp', [`--path=${PATH}`, ...a], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim()
const { chromium } = await import(process.env.PLAYWRIGHT)
const browser = await chromium.launch()

// --- icon and banner ---------------------------------------------------------------------------------
const shot = async (html, width, height, file, scale = 1) => {
  const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: scale })
  await page.setContent(html)
  await page.screenshot({ path: join(out, file), omitBackground: true })
  await page.close()
}
const icon = readFileSync(join(out, 'icon.svg'), 'utf8')
for (const size of [128, 256]) await shot(`<style>html,body{margin:0;background:transparent}svg{display:block;width:${size}px;height:${size}px}</style>${icon}`, size, size, `icon-${size}x${size}.png`)
const banner = readFileSync(join(here, 'banner.html'), 'utf8')
await shot(banner, 1544, 500, 'banner-1544x500.png')
await shot(`<style>html{zoom:.5}</style>${banner}`, 772, 250, 'banner-772x250.png')

// --- a site with something to show -------------------------------------------------------------------
wp('option', 'update', 'p2flux_ap_fake', '{}', '--format=json')
wp('transient', 'delete', '--all')
wp('eval', 'global $wpdb; $wpdb->query("TRUNCATE {$wpdb->prefix}p2flux_ap_payments"); $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'p2flux\\\\_ap\\\\_fake\\\\_used\\\\_%\'");')
wp('option', 'update', 'p2flux_ap_settings', JSON.stringify({ wallet: '0xb4e43f3fBa5Add75395adAD366627E7d74141Fa9', environment: 'test', default_price: '0.05', paid_post_types: ['post'], paid_categories: [], paid_routes: [], api_down: 'refuse', prepaid: 'yes', directory: 'yes' }), '--format=json')
wp('option', 'update', 'blogname', 'Recipes of the North')
for (const id of wp('post', 'list', '--post_type=post,page,attachment', '--format=ids').split(' ').filter(Boolean)) wp('post', 'delete', id, '--force')
const posts = [['Fish soup with saffron', ''], ['Rye bread, three days', '0.1'], ['Smoked trout at home', ''], ['Cloudberry jam', '']].map(([title, price]) => {
  const id = wp('post', 'create', '--post_type=post', `--post_title=${title}`, '--post_content=A tested recipe, step by step.', '--post_status=publish', '--porcelain')
  if (price) wp('post', 'meta', 'update', id, '_p2flux_ap_price', price)
  return { id, url: wp('post', 'url', id), price: price || '0.05' }
})
const dir = mkdtempSync(join(tmpdir(), 'p2flux-ap-'))
writeFileSync(join(dir, 'nordic-fish-prices-2026.csv'), 'species,region,price\ncod,north,12.4\n')
const file = wp('media', 'import', join(dir, 'nordic-fish-prices-2026.csv'), '--porcelain')
wp('post', 'meta', 'update', file, '_p2flux_ap_price', '0.5')

const { publicKey, privateKey } = generateKeyPairSync('ed25519')
const x = publicKey.export({ format: 'jwk' }).x
wp('option', 'update', 'p2flux_ap_fake_jwks', JSON.stringify({ keys: [{ kty: 'OKP', crv: 'Ed25519', x }] }))
const keyid = createHash('sha256').update(`{"crv":"Ed25519","kty":"OKP","x":"${x}"}`).digest('base64url')
const signed = () => {
  const now = Math.floor(Date.now() / 1000)
  const inner = `("@authority" "signature-agent");created=${now};keyid="${keyid}";alg="ed25519";expires=${now + 120};tag="web-bot-auth"`
  const base = `"@authority": localhost:8082\n"signature-agent": "https://agent.test"\n"@signature-params": ${inner}`
  return { 'signature-agent': '"https://agent.test"', 'signature-input': `sig1=${inner}`, signature: `sig1=:${sign(null, Buffer.from(base), privateKey).toString('base64')}:` }
}
const GPT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)'
const pay = (url, id, price, extra = {}) => fetch(url, { headers: { 'user-agent': GPT, 'payment-signature': Buffer.from(JSON.stringify({ id, price })).toString('base64'), ...extra } })
let n = 0
for (const p of [posts[0], posts[1], posts[0], posts[2], posts[3], posts[1], posts[0]]) await pay(p.url, `${n % 3 === 0 ? 'batch-' : ''}shot-${n++}`, p.price, n % 2 ? signed() : {})

// --- screenshots -------------------------------------------------------------------------------------
wp('user', 'update', '1', '--user_pass=screenshots-only', '--skip-email')
const login = wp('user', 'get', '1', '--field=user_login')
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } })
await page.goto(`${SITE}/wp-login.php`)
await page.fill('#user_login', login)
await page.fill('#user_pass', 'screenshots-only')
await page.click('#wp-submit')
await page.goto(`${SITE}/wp-admin/options-general.php?page=p2flux-agent-paywall`)
await page.addStyleTag({ content: '#wpfooter,.notice:not(.inline),.update-nag{display:none!important}' })
await page.screenshot({ path: join(out, 'screenshot-1.png') })
await page.locator('table.widefat').scrollIntoViewIfNeeded()
await page.evaluate(() => window.scrollBy(0, 200))
await page.screenshot({ path: join(out, 'screenshot-2.png') })

await page.goto(`${SITE}/wp-admin/post.php?post=${posts[1].id}&action=edit`)
await page.waitForSelector('#p2flux-ap-price', { state: 'attached', timeout: 30_000 })
await page.evaluate(() => window.wp?.data?.dispatch('core/preferences')?.set('core/edit-post', 'welcomeGuide', false))
await page.waitForTimeout(800)
const box = page.locator('#p2flux-ap-price')
await box.scrollIntoViewIfNeeded()
await page.screenshot({ path: join(out, 'screenshot-3.png') })

await page.goto(`${SITE}/wp-admin/post.php?post=${file}&action=edit`)
await page.locator('[name="attachments[' + file + '][p2flux_ap_price]"]').scrollIntoViewIfNeeded()
await page.screenshot({ path: join(out, 'screenshot-4.png') })

const res = await fetch(posts[0].url, { headers: { 'user-agent': GPT } })
const required = JSON.parse(Buffer.from(res.headers.get('payment-required'), 'base64').toString())
// The fake API's address is not a real one; show the seller's real P2Flux address on Base Sepolia.
required.accepts[0].payTo = '0xeF7184Bcbd6A95b2dd98389cDb54b26Eaf04578F'
const term = `$ curl -i -A "GPTBot/1.2" ${posts[0].url}\n\nHTTP/1.1 402 Payment Required\nCache-Control: no-store, private\nPAYMENT-REQUIRED: eyJ4NDAyVmVyc2lvbiI6Miwi…\nContent-Type: application/json\n\n${JSON.stringify({ ...required, accepts: required.accepts.slice(0, 1) }, null, 2)}`
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;')
const t = await browser.newPage({ viewport: { width: 1280, height: 900 } })
await t.setContent(`<body style="margin:0;background:#0B0C12"><pre style="margin:0;padding:40px 48px;color:#d7dae6;font:17px/1.55 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-all">${esc(term)}</pre>`)
await t.screenshot({ path: join(out, 'screenshot-5.png') })
await browser.close()
wp('user', 'update', '1', `--user_pass=${createHash('sha256').update(String(Math.random())).digest('hex')}`, '--skip-email')
console.log('written to', out)
