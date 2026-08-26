# PAM Browser

`pushinbr/pam-browser` is a typed Chrome DevTools Protocol (CDP) client for
PHP applications running on PAM. It is the browser boundary used by the PAM
WhatsApp Web client and does not depend on Node.js, npm, Puppeteer, or
Playwright.

## Start here

Install the PAM Runtime first, confirm its environment, and install the browser
client with PAM's verified Composer integration:

```bash
curl --proto '=https' --proto-redir '=https' --tlsv1.2 \
    --connect-timeout 15 --max-time 60 --max-filesize 1048576 -fsSL \
    https://github.com/push-in/pam/releases/latest/download/install.sh | sh

pam doctor
mkdir pam-browser-app && cd pam-browser-app
pam composer init --no-interaction
pam composer require pushinbr/pam-browser:^1.0
```

Install Chrome or Chromium separately on the host. PAM Browser supervises that
external process and speaks CDP directly; Node.js and browser automation
wrappers are not installed.

The package is being built in layers:

1. protocol codec and event routing;
2. PAM-native WebSocket client transport;
3. supervised Chrome/Chromium lifecycle;
4. browser contexts, pages, JavaScript bindings, and persistent profiles.

The browser itself remains an external Chrome/Chromium process. JavaScript may
be evaluated inside a page, while the application API and orchestration remain
strictly typed PHP.
