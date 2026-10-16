# datev_securemail – Roundcube plugin

Open DATEV (SEPPmail) encrypted e-mails directly from Roundcube.

DATEV's e-mail encryption delivers messages with a `secure-email.html`
attachment. You normally have to download that file, open it locally and
click **OK**, which posts the encrypted payload to the DATEV decryption
portal. Roundcube strips `<form>` elements from HTML, so the button does
nothing when the attachment is opened inside the webmailer.

This plugin adds an **"Open in DATEV portal"** button above such messages.
It extracts the form from the attachment on the server and re-posts it to
the portal in a new tab, so you land straight on the DATEV sign-in page.

## How it works

1. Messages with an attachment matching `secure-email.html` get a notice box
   with the button.
2. The button opens `?_action=plugin.datev_open` (protected by Roundcube's
   request token). The plugin parses the attachment, takes the form's hidden
   fields (plus checked radios/checkboxes) and outputs a minimal page that
   auto-submits them to the form's target.
3. The target must be an `https://` URL on an allow-listed host
   (default: `*.datevnet.de`). Nothing else from the attachment – scripts,
   markup, styles – is passed through. The page is sent with
   `Referrer-Policy: no-referrer`.

## Installation

Copy (or `git clone`) this repository to `plugins/datev_securemail` in your
Roundcube installation and enable it:

```php
$config['plugins'][] = 'datev_securemail';
```

With the official Docker image, mount it at
`/var/www/html/plugins/datev_securemail` and add `datev_securemail` to
`ROUNDCUBEMAIL_PLUGINS`.

## Configuration

Defaults are in `config.inc.php.dist`. To override, create
`plugins/datev_securemail/config.inc.php` or set the options in Roundcube's
main config:

```php
// Hosts the form may be posted to (https only); a leading dot matches subdomains
$config['datev_securemail_hosts'] = ['.datevnet.de'];

// Attachment filename pattern identifying a secure mail
$config['datev_securemail_filename'] = '/^secure-email\.html?$/i';
```

## License

MIT
