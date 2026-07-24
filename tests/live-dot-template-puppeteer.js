const { execFileSync } = require('child_process');

const puppeteer = require(process.env.PUPPETEER_MODULE || 'puppeteer');

const wpPath = process.env.WP_PATH;
const domain = process.env.WP_DOMAIN;
const postId = process.env.POST_ID;
const articleUrl = process.env.ARTICLE_URL;
const executablePath = process.env.CHROMIUM_PATH;
const adminScreenshot = process.env.ADMIN_SCREENSHOT || '/tmp/smp-tts-dot-admin.png';
const frontendScreenshot = process.env.FRONTEND_SCREENSHOT || '/tmp/smp-tts-dot-frontend.png';

for (const [name, value] of Object.entries({ wpPath, domain, postId, articleUrl, executablePath })) {
  if (!value) throw new Error(`Missing required environment variable for ${name}.`);
}

const adminUrl = `https://${domain}/wp-admin/options-general.php?page=smp-wp-text-to-speech&tab=display`;

function wpEval(code) {
  return execFileSync('wp', ['--allow-root', `--path=${wpPath}`, 'eval', code], {
    encoding: 'utf8',
    maxBuffer: 8 * 1024 * 1024,
  }).trim();
}

function adminCookies() {
  return JSON.parse(wpEval(`
    $admins = get_users(["role" => "administrator", "number" => 1, "fields" => "ID"]);
    $user_id = (int) ($admins[0] ?? 1);
    $expiration = time() + 3600;
    $token = WP_Session_Tokens::get_instance($user_id)->create($expiration);
    $values = [
      [LOGGED_IN_COOKIE, wp_generate_auth_cookie($user_id, $expiration, "logged_in", $token)],
      [AUTH_COOKIE, wp_generate_auth_cookie($user_id, $expiration, "auth", $token)],
      [SECURE_AUTH_COOKIE, wp_generate_auth_cookie($user_id, $expiration, "secure_auth", $token)],
    ];
    $paths = array_values(array_unique(array_filter(["/", COOKIEPATH, SITECOOKIEPATH, ADMIN_COOKIE_PATH, PLUGINS_COOKIE_PATH])));
    $cookies = [];
    foreach ($paths as $path) {
      foreach ($values as $value) {
        $cookies[] = ["name" => $value[0], "value" => $value[1], "domain" => "${domain}", "path" => $path, "expires" => $expiration, "httpOnly" => true, "secure" => true];
      }
    }
    echo wp_json_encode($cookies);
  `));
}

async function cacheProbe(url) {
  const response = await fetch(url, { redirect: 'follow' });
  await response.arrayBuffer();
  return {
    status: response.status,
    cache: response.headers.get('x-litespeed-cache') || '',
    cloudflare: response.headers.get('cf-cache-status') || '',
  };
}

function playerState(selector) {
  const player = document.querySelector(selector);
  if (!player) return null;
  const style = getComputedStyle(player);
  const label = player.querySelector('.hexa-tts-player__label');
  const labelStyle = label ? getComputedStyle(label) : null;
  const dotStyle = label ? getComputedStyle(label, '::before') : null;
  const audio = player.querySelector('audio');
  return {
    classes: player.className,
    borderTopWidth: style.borderTopWidth,
    borderRightWidth: style.borderRightWidth,
    borderBottomWidth: style.borderBottomWidth,
    borderLeftWidth: style.borderLeftWidth,
    borderRadius: style.borderRadius,
    backgroundColor: style.backgroundColor,
    boxShadow: style.boxShadow,
    padding: style.padding,
    maxWidth: style.maxWidth,
    labelDisplay: labelStyle?.display || '',
    dotContent: dotStyle?.content || '',
    dotWidth: dotStyle?.width || '',
    dotHeight: dotStyle?.height || '',
    dotRadius: dotStyle?.borderRadius || '',
    dotColor: dotStyle?.backgroundColor || '',
    audioSrc: audio?.currentSrc || audio?.src || '',
  };
}

function transparentColor(value) {
  if (value === 'transparent') return true;
  const match = value.match(/^rgba\([^,]+,[^,]+,[^,]+,\s*([0-9.]+)\)$/);
  return Boolean(match && Number(match[1]) === 0);
}

function validDot(state) {
  return Boolean(
    state
    && state.classes.includes('hexa-tts-player--dot')
    && state.borderTopWidth === '0px'
    && state.borderRightWidth === '0px'
    && state.borderBottomWidth === '0px'
    && state.borderLeftWidth === '0px'
    && state.borderRadius === '0px'
    && transparentColor(state.backgroundColor)
    && state.boxShadow === 'none'
    && state.labelDisplay === 'flex'
    && state.dotContent === '""'
    && state.dotWidth === '8px'
    && state.dotHeight === '8px'
    && state.dotRadius === '50%'
  );
}

async function main() {
  const beforePrime = await cacheProbe(articleUrl);
  const beforeSave = await cacheProbe(articleUrl);

  const browser = await puppeteer.launch({
    executablePath,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    defaultViewport: { width: 1700, height: 1200 },
  });
  const page = await browser.newPage();
  page.setDefaultTimeout(70000);
  page.setDefaultNavigationTimeout(90000);
  const errors = [];
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.stack || error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') {
      const location = message.location();
      if (!location.url.includes('/cdn-cgi/rum')) errors.push(`console: ${message.text()} @ ${location.url}`);
    }
  });

  try {
    await page.setCookie(...adminCookies());
    await page.goto(adminUrl, { waitUntil: 'networkidle2' });
    if (page.url().includes('wp-login.php')) throw new Error('Admin authentication failed.');
    await page.waitForSelector('.hexa-tts-display-live-form input[name="hexa_tts[player_template]"][value="dot"]', { visible: true });

    const selectedBefore = await page.$eval('input[name="hexa_tts[player_template]"][value="dot"]', (input) => input.checked);
    const adminBefore = await page.evaluate(playerState, '.hexa-tts-template-live-row.is-selected .hexa-tts-player--dot');
    await page.screenshot({ path: adminScreenshot, fullPage: true });

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2' }),
      page.click('.hexa-tts-display-live-form button[type="submit"]'),
    ]);
    if (!page.url().includes('hexa_tts_saved=1')) throw new Error(`Display save did not complete: ${page.url()}`);
    await page.waitForSelector('.hexa-tts-display-live-form input[name="hexa_tts[player_template]"][value="dot"]', { visible: true });
    const selectedAfter = await page.$eval('input[name="hexa_tts[player_template]"][value="dot"]', (input) => input.checked);

    const afterSave = await cacheProbe(articleUrl);
    const frontendResponse = await page.goto(articleUrl, { waitUntil: 'networkidle2' });
    await page.waitForSelector('.hexa-tts-player--dot', { visible: true });
    const frontend = await page.evaluate(playerState, '.hexa-tts-player--dot');
    const playerCount = await page.$$eval('.hexa-tts-player', (players) => players.length);
    const dotCount = await page.$$eval('.hexa-tts-player--dot', (players) => players.length);
    const styleRules = await page.evaluate(() => Array.from(document.styleSheets).flatMap((sheet) => {
      try {
        return Array.from(sheet.cssRules || []).map((rule) => rule.cssText).filter((text) => text.includes('.hexa-tts-player--dot'));
      } catch (_) {
        return [];
      }
    }));
    await page.screenshot({ path: frontendScreenshot, fullPage: true });

    const audioResponse = await fetch(frontend.audioSrc, { method: 'HEAD', redirect: 'follow' });
    const audio = {
      status: audioResponse.status,
      ok: audioResponse.ok,
      contentType: audioResponse.headers.get('content-type') || '',
      contentLength: audioResponse.headers.get('content-length') || '',
    };
    const selectedSetting = wpEval(`echo (string) (get_option("hexa_tts_settings")["player_template"] ?? "");`);
    const pluginErrors = errors.filter((error) => error.includes('/wp-content/plugins/smp-wp-text-to-speech/'));
    const sameDesign = validDot(adminBefore) && validDot(frontend)
      && adminBefore.dotWidth === frontend.dotWidth
      && adminBefore.dotHeight === frontend.dotHeight
      && adminBefore.dotRadius === frontend.dotRadius
      && adminBefore.dotColor === frontend.dotColor;

    const output = {
      ok: selectedBefore
        && selectedAfter
        && selectedSetting === 'dot'
        && beforePrime.status === 200
        && beforeSave.status === 200
        && afterSave.status === 200
        && afterSave.cache !== 'hit'
        && sameDesign
        && playerCount === 1
        && dotCount === 1
        && styleRules.length >= 3
        && audio.ok
        && audio.contentType.includes('audio/mpeg')
        && pluginErrors.length === 0,
      adminUrl,
      articleUrl,
      selectedBefore,
      selectedAfter,
      selectedSetting,
      cache: { beforePrime, beforeSave, afterSave, browser: frontendResponse?.headers()['x-litespeed-cache'] || '' },
      adminBefore,
      frontend,
      sameDesign,
      playerCount,
      dotCount,
      styleRuleCount: styleRules.length,
      audio,
      pluginErrors,
      errors,
      screenshots: { adminScreenshot, frontendScreenshot },
    };

    console.log(JSON.stringify(output, null, 2));
    if (!output.ok) process.exitCode = 1;
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  console.error(JSON.stringify({ ok: false, error: error.message, stack: error.stack }, null, 2));
  process.exit(1);
});
