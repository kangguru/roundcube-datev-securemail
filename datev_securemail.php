<?php

/**
 * DATEV / SEPPmail secure mail opener
 *
 * DATEV encrypted mails carry a "secure-email.html" attachment containing a
 * form with the encrypted payload in hidden fields. Clicking "OK" posts it to
 * the DATEV decryption portal. Roundcube strips forms from HTML, so the
 * attachment cannot be used from the webmailer directly.
 *
 * This plugin adds a button to such messages that extracts the form on the
 * server side and re-posts it to the (allow-listed) portal in a new window.
 */
class datev_securemail extends rcube_plugin
{
    public $task = 'mail';

    /** @var array List of [uid, folder, mime_id] for the currently shown message */
    private $parts = [];

    public function init()
    {
        $this->load_config('config.inc.php.dist');
        $this->load_config();

        $this->add_hook('message_load', [$this, 'message_load']);
        $this->add_hook('template_object_messagebody', [$this, 'messagebody']);
        $this->register_action('plugin.datev_open', [$this, 'open']);
    }

    /**
     * Remember secure mail attachments of the message being displayed
     */
    public function message_load($args)
    {
        $rcmail  = rcmail::get_instance();
        $message = $args['object'];
        $pattern = $rcmail->config->get('datev_securemail_filename', '/^secure-email\.html?$/i');

        $this->parts = [];

        foreach ((array) $message->attachments as $part) {
            if (preg_match($pattern, (string) $part->filename)) {
                $this->parts[] = [
                    'uid'    => $message->uid,
                    'folder' => $message->folder,
                    'part'   => $part->mime_id,
                ];
            }
        }

        return $args;
    }

    /**
     * Prepend the "open in portal" notice to the message body
     */
    public function messagebody($args)
    {
        if (empty($this->parts)) {
            return $args;
        }

        $this->add_texts('localization/');

        $rcmail  = rcmail::get_instance();
        $buttons = '';

        foreach ($this->parts as $p) {
            $url = $rcmail->url([
                '_action' => 'plugin.datev_open',
                '_uid'    => $p['uid'],
                '_mbox'   => $p['folder'],
                '_part'   => $p['part'],
                '_token'  => $rcmail->get_request_token(),
            ], false, true);

            $buttons .= html::a([
                'href'   => $url,
                'target' => '_blank',
                'rel'    => 'noopener noreferrer',
                'class'  => 'button btn btn-primary mainaction',
            ], rcube::Q($this->gettext('openportal')));
        }

        $notice = html::div(
            ['class' => 'boxinformation datev-securemail', 'style' => 'margin-bottom: 1em'],
            html::p(null, rcube::Q($this->gettext('notice'))) . $buttons
        );

        $args['content'] = $notice . $args['content'];

        return $args;
    }

    /**
     * Output an auto-submitting form that posts the payload to the portal
     */
    public function open()
    {
        $rcmail = rcmail::get_instance();
        $this->add_texts('localization/');

        $uid    = rcube_utils::get_input_string('_uid', rcube_utils::INPUT_GET);
        $folder = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_GET, true);
        $mime   = rcube_utils::get_input_string('_part', rcube_utils::INPUT_GET);

        if (!$rcmail->check_request(rcube_utils::INPUT_GET)) {
            $this->fail(403, 'Invalid request');
        }

        $message = new rcube_message($uid, $folder);
        $part    = $message->mime_parts[$mime] ?? null;
        $pattern = $rcmail->config->get('datev_securemail_filename', '/^secure-email\.html?$/i');

        if (!$part || !preg_match($pattern, (string) $part->filename)) {
            $this->fail(404, $this->gettext('notfound'));
        }

        $form = self::parse_form((string) $message->get_part_body($mime));

        if (!$form || !self::host_allowed($form['action'], (array) $rcmail->config->get('datev_securemail_hosts'))) {
            $this->fail(400, $this->gettext('invalidform'));
        }

        $inputs = '';
        foreach ($form['fields'] as $f) {
            $inputs .= html::tag('input', ['type' => 'hidden', 'name' => $f[0], 'value' => $f[1]]) . "\n";
        }

        $title = rcube::Q($this->gettext('redirecting'));
        $note  = rcube::Q($this->gettext('redirectnote'));
        $btn   = rcube::Q($this->gettext('continue'));

        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');

        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="referrer" content="no-referrer">'
            . "<title>$title</title></head>"
            . '<body style="font-family: sans-serif; margin: 3em; color: #333">'
            . html::tag('form', ['id' => 'f', 'method' => 'post', 'action' => $form['action']],
                $inputs . "<p>$note</p>" . html::tag('button', ['type' => 'submit'], $btn))
            . '<script>document.getElementById("f").submit();</script>'
            . '</body></html>';
        exit;
    }

    /**
     * Extract form action and hidden fields from the attachment HTML
     *
     * @return array|null ['action' => string, 'fields' => [[name, value], ...]]
     */
    public static function parse_form($html)
    {
        if ($html === '') {
            return null;
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $form = $doc->getElementsByTagName('form')->item(0);
        if (!$form || strtolower($form->getAttribute('method')) !== 'post') {
            return null;
        }

        $fields = [];
        foreach ($form->getElementsByTagName('input') as $input) {
            $name = $input->getAttribute('name');
            $type = strtolower($input->getAttribute('type'));

            // Submit what a browser would: hidden fields and checked radios/checkboxes
            if ($name !== '' && ($type === 'hidden'
                || (($type === 'radio' || $type === 'checkbox') && $input->hasAttribute('checked')))
            ) {
                $fields[] = [$name, $input->getAttribute('value')];
            }
        }

        if (empty($fields)) {
            return null;
        }

        return ['action' => $form->getAttribute('action'), 'fields' => $fields];
    }

    /**
     * Check that the URL is https and its host is in the allow list.
     * Entries starting with a dot match any subdomain (".datevnet.de").
     */
    public static function host_allowed($url, array $hosts)
    {
        $p = parse_url($url);

        if (empty($p['scheme']) || strtolower($p['scheme']) !== 'https' || empty($p['host']) || isset($p['user'])) {
            return false;
        }

        $host = strtolower($p['host']);

        foreach ($hosts as $allowed) {
            $allowed = strtolower($allowed);
            if ($allowed === $host
                || ($allowed[0] === '.' && substr($host, -strlen($allowed)) === $allowed)
            ) {
                return true;
            }
        }

        return false;
    }

    private function fail($code, $msg)
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
        exit;
    }
}
