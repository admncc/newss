<?php

declare(strict_types=1);

namespace Newss;

final class Channels
{
    private const OPTION_KEY = 'newss_channels';

    public static function register(): void
    {
        add_action('admin_post_newss_channel_save', [self::class, 'handleSave']);
        add_action('admin_post_newss_channel_delete', [self::class, 'handleDelete']);
        add_action('admin_post_newss_channel_toggle', [self::class, 'handleToggle']);
        add_action('admin_post_newss_channel_bulk', [self::class, 'handleBulk']);
        add_action('admin_post_newss_channel_test', [self::class, 'handleTest']);
    }

    public static function handleTest(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        $id = sanitize_text_field(wp_unslash((string) ($_GET['id'] ?? '')));
        check_admin_referer('newss_channel_test_' . $id);

        $channels = self::all();
        $target = null;
        foreach ($channels as $ch) {
            if (($ch['id'] ?? '') === $id) {
                $target = $ch;
                break;
            }
        }
        if ($target === null) {
            self::flash('error', 'Kanal nicht gefunden.');
            self::redirect();
            return;
        }

        $result = RssPoller::testChannel($target);
        if (!$result['ok']) {
            self::flash('error', sprintf(
                'Test für „%s" fehlgeschlagen: %s',
                (string) $target['name'],
                $result['error']
            ));
            self::redirect();
            return;
        }

        $msg = sprintf(
            'Test „%s" OK: %d Videos gefunden (%d neu, %d bereits verarbeitet).',
            (string) $target['name'],
            $result['total'],
            $result['new'],
            $result['skipped']
        );
        if (!empty($result['samples'])) {
            $titles = array_map(static fn(array $s): string => (string) $s['title'], $result['samples']);
            $msg .= ' Neueste: „' . implode('", „', $titles) . '"';
        }
        self::flash('success', $msg);
        self::redirect();
    }

    public static function handleBulk(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('newss_channel_bulk');

        $action = sanitize_key(wp_unslash((string) ($_POST['bulk_action'] ?? '')));
        $ids    = array_map('strval', (array) ($_POST['ids'] ?? []));
        $ids    = array_values(array_filter($ids, static fn(string $i): bool => preg_match('/^UC[A-Za-z0-9_-]{22}$/', $i) === 1));
        if ($action === '' || $ids === []) {
            self::flash('error', 'Keine Aktion oder keine Kanäle ausgewählt.');
            self::redirect();
        }

        $channels = self::all();
        $changed = 0;
        if ($action === 'delete') {
            $before = count($channels);
            $channels = array_values(array_filter($channels, static fn(array $c): bool => !in_array((string) ($c['id'] ?? ''), $ids, true)));
            $changed = $before - count($channels);
        } elseif (in_array($action, ['enable', 'disable'], true)) {
            foreach ($channels as $i => $ch) {
                if (in_array((string) ($ch['id'] ?? ''), $ids, true)) {
                    $newVal = $action === 'enable' ? 1 : 0;
                    if ((int) ($ch['enabled'] ?? 0) !== $newVal) {
                        $channels[$i]['enabled'] = $newVal;
                        $changed++;
                    }
                }
            }
        }
        update_option(self::OPTION_KEY, $channels, false);
        self::flash('success', sprintf('Bulk-Aktion „%s" auf %d Kanäle angewendet.', $action, $changed));
        self::redirect();
    }

    public static function renderSection(): void
    {
        $channels = self::all();
        $notice   = get_transient('newss_channel_notice');
        if ($notice) {
            delete_transient('newss_channel_notice');
        }

        $editId = isset($_GET['edit']) ? sanitize_text_field(wp_unslash((string) $_GET['edit'])) : '';
        $editing = null;
        if ($editId !== '') {
            foreach ($channels as $ch) {
                if (($ch['id'] ?? '') === $editId) {
                    $editing = $ch;
                    break;
                }
            }
        }
        $isEdit = $editing !== null;
        $cancelUrl = admin_url('admin.php?page=newss-channels');
        ?>
        <h2>Kanäle</h2>

        <?php if (is_array($notice)): ?>
            <div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible">
                <p><?php echo esc_html((string) $notice['message']); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#f6f7f7;padding:12px 16px;border:1px solid #dcdcde;max-width:880px;margin-bottom:16px">
            <input type="hidden" name="action" value="newss_channel_save">
            <input type="hidden" name="original_id" value="<?php echo esc_attr($isEdit ? (string) $editing['id'] : ''); ?>">
            <?php wp_nonce_field('newss_channel_save'); ?>

            <h3 style="margin-top:0">
                <?php echo $isEdit ? 'Kanal bearbeiten' : 'Hinzufügen'; ?>
                <?php if ($isEdit): ?>
                    — <code style="font-size:13px"><?php echo esc_html((string) $editing['id']); ?></code>
                    &nbsp;<a href="<?php echo esc_url($cancelUrl); ?>" style="font-size:12px;font-weight:normal">[Abbrechen]</a>
                <?php endif; ?>
            </h3>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row" style="width:160px"><label for="newss_ch_input">Kanal-Link / Handle / ID</label></th>
                    <td>
                        <input type="text" id="newss_ch_input" name="input" class="large-text" required
                               value="<?php echo esc_attr($isEdit ? (string) $editing['id'] : ''); ?>"
                               placeholder="https://www.youtube.com/@phoenix  oder  @phoenix  oder  UCwyiPnNlT8UABRmGmU0T9jg">
                        <p class="description">URL, <code>@handle</code> oder direkte Channel-ID. Wird neu aufgelöst — du kannst hier z.&nbsp;B. eine veraltete ID durch das aktuelle Handle ersetzen.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="newss_ch_name">Anzeigename</label></th>
                    <td>
                        <input type="text" id="newss_ch_name" name="name" class="regular-text"
                               value="<?php echo esc_attr($isEdit ? (string) $editing['name'] : ''); ?>"
                               placeholder="leer = automatisch ermitteln">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="newss_ch_cat">Kategorie</label></th>
                    <td><?php wp_dropdown_categories([
                        'show_option_none'  => '— Default verwenden —',
                        'option_none_value' => 0,
                        'name'              => 'category',
                        'selected'          => $isEdit ? (int) ($editing['category'] ?? 0) : 0,
                        'hide_empty'        => false,
                        'id'                => 'newss_ch_cat',
                    ]); ?></td>
                </tr>
                <tr>
                    <th scope="row">Aktiv</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked($isEdit ? (int) ($editing['enabled'] ?? 0) : 1, 1); ?>>
                            Beim nächsten Polling berücksichtigen
                        </label>
                    </td>
                </tr>
            </table>
            <p>
                <button type="submit" class="button button-primary"><?php echo $isEdit ? 'Änderungen speichern' : 'Kanal hinzufügen'; ?></button>
                <?php if ($isEdit): ?>
                    <a href="<?php echo esc_url($cancelUrl); ?>" class="button">Abbrechen</a>
                <?php endif; ?>
            </p>
        </form>

        <h3 style="margin-top:24px">Aktive Kanäle (<?php echo count($channels); ?>)</h3>
        <?php if (!$channels): ?>
            <div style="padding:14px 18px;border:1px solid #dcdcde;background:#f6f7f7;max-width:880px">
                <p style="margin:0">Noch keine Kanäle konfiguriert. Oben einen Kanal hinzufügen — entweder per <code>@handle</code>, vollständiger URL oder direkter Channel-ID.</p>
            </div>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:880px">
                <input type="hidden" name="action" value="newss_channel_bulk">
                <?php wp_nonce_field('newss_channel_bulk'); ?>

                <div style="margin:8px 0;display:flex;gap:8px;align-items:center">
                    <label for="newss_bulk_action" style="font-weight:600">Bulk-Aktion:</label>
                    <select name="bulk_action" id="newss_bulk_action">
                        <option value="">— wählen —</option>
                        <option value="enable">Aktivieren</option>
                        <option value="disable">Deaktivieren</option>
                        <option value="delete">Löschen</option>
                    </select>
                    <button type="submit" class="button" onclick="if(document.getElementById('newss_bulk_action').value === 'delete') return confirm('Ausgewählte Kanäle wirklich löschen?'); return true;">Anwenden</button>
                    <span class="description">— erst Auswahl unten setzen, dann Aktion wählen + Anwenden</span>
                </div>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:30px"><input type="checkbox" onclick="document.querySelectorAll('input[name=\'ids[]\']').forEach(function(c){ c.checked = this.checked; }.bind(this))"></th>
                            <th style="width:25%">Name</th>
                            <th>Channel-ID</th>
                            <th>Kategorie</th>
                            <th style="width:80px">Status</th>
                            <th style="width:280px">Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($channels as $ch):
                    $catName = $ch['category'] ? get_cat_name((int) $ch['category']) : '— Default —';
                    $editUrl = add_query_arg(
                        ['page' => 'newss-channels', 'edit' => rawurlencode((string) $ch['id'])],
                        admin_url('admin.php')
                    );
                    $delUrl = wp_nonce_url(
                        admin_url('admin-post.php?action=newss_channel_delete&id=' . rawurlencode((string) $ch['id'])),
                        'newss_channel_delete_' . $ch['id']
                    );
                    $toggleUrl = wp_nonce_url(
                        admin_url('admin-post.php?action=newss_channel_toggle&id=' . rawurlencode((string) $ch['id'])),
                        'newss_channel_toggle_' . $ch['id']
                    );
                    $testUrl = wp_nonce_url(
                        admin_url('admin-post.php?action=newss_channel_test&id=' . rawurlencode((string) $ch['id'])),
                        'newss_channel_test_' . $ch['id']
                    );
                    $isActive = !empty($ch['enabled']);
                    $isCurrentlyEdited = $isEdit && $editing['id'] === $ch['id'];
                    ?>
                    <tr<?php echo $isCurrentlyEdited ? ' style="background:#fff8e1"' : ''; ?>>
                        <td><input type="checkbox" name="ids[]" value="<?php echo esc_attr((string) $ch['id']); ?>"></td>
                        <td>
                            <strong><?php echo esc_html((string) $ch['name']); ?></strong><br>
                            <a href="https://www.youtube.com/channel/<?php echo esc_attr((string) $ch['id']); ?>" target="_blank" rel="noopener" style="font-size:11px">→ YouTube</a>
                        </td>
                        <td><code style="font-size:11px"><?php echo esc_html((string) $ch['id']); ?></code></td>
                        <td><?php echo esc_html((string) $catName); ?></td>
                        <td>
                            <a href="<?php echo esc_url($toggleUrl); ?>" title="Status umschalten" style="text-decoration:none">
                                <?php echo $isActive ? '<span style="color:#0a7">● aktiv</span>' : '<span style="color:#999">○ aus</span>'; ?>
                            </a>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($testUrl); ?>" class="button button-small" title="Letzte Videos abfragen ohne Enqueue">Testen</a>
                            <a href="<?php echo esc_url($editUrl); ?>" class="button button-small">Bearbeiten</a>
                            <a href="<?php echo esc_url($delUrl); ?>" class="button button-small button-link-delete newss-delete-link" data-channel-name="<?php echo esc_attr((string) $ch['name']); ?>">Löschen</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        <?php endif; ?>
        <script>
        document.addEventListener('click', function(e){
            var link = e.target.closest('.newss-delete-link');
            if (!link) return;
            var name = link.getAttribute('data-channel-name') || 'diesen Kanal';
            if (!confirm('Kanal „' + name + '" wirklich löschen?')) {
                e.preventDefault();
            }
        });
        </script>
        <hr style="margin:32px 0">
        <?php
    }

    public static function handleSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('newss_channel_save');

        $originalId = sanitize_text_field(wp_unslash((string) ($_POST['original_id'] ?? '')));
        $input      = sanitize_text_field(wp_unslash((string) ($_POST['input'] ?? '')));

        $resolved = (new ChannelResolver())->resolve($input);
        if ($resolved === null) {
            self::flash('error', 'Konnte den Kanal nicht auflösen. URL/Handle/ID prüfen.');
            self::redirect();
            return;
        }

        $overrideName = sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')));
        $name = $overrideName !== '' ? $overrideName : (string) $resolved['name'];

        $entry = [
            'id'       => (string) $resolved['id'],
            'name'     => $name,
            'category' => absint($_POST['category'] ?? 0),
            'enabled'  => !empty($_POST['enabled']) ? 1 : 0,
        ];

        $channels = self::all();

        if ($originalId !== '') {
            $found = false;
            foreach ($channels as $i => $ch) {
                if (($ch['id'] ?? '') === $originalId) {
                    $channels[$i] = $entry;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $channels[] = $entry;
            }
            self::flash('success', sprintf('Kanal „%s" aktualisiert (%s).', $name, $entry['id']));
        } else {
            $duplicate = false;
            foreach ($channels as $i => $ch) {
                if (($ch['id'] ?? '') === $entry['id']) {
                    $channels[$i] = $entry;
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $channels[] = $entry;
            }
            self::flash('success', $duplicate
                ? sprintf('Kanal „%s" aktualisiert.', $name)
                : sprintf('Kanal „%s" hinzugefügt (%s).', $name, $entry['id'])
            );
        }

        update_option(self::OPTION_KEY, $channels, false);
        self::redirect();
    }

    public static function handleDelete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        $id = sanitize_text_field(wp_unslash((string) ($_GET['id'] ?? '')));
        check_admin_referer('newss_channel_delete_' . $id);

        $channels = array_values(array_filter(
            self::all(),
            static fn(array $ch): bool => ($ch['id'] ?? '') !== $id
        ));
        update_option(self::OPTION_KEY, $channels, false);

        self::flash('success', 'Kanal gelöscht.');
        self::redirect();
    }

    public static function handleToggle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        $id = sanitize_text_field(wp_unslash((string) ($_GET['id'] ?? '')));
        check_admin_referer('newss_channel_toggle_' . $id);

        $channels = self::all();
        foreach ($channels as &$ch) {
            if (($ch['id'] ?? '') === $id) {
                $ch['enabled'] = empty($ch['enabled']) ? 1 : 0;
                break;
            }
        }
        unset($ch);
        update_option(self::OPTION_KEY, $channels, false);

        self::flash('success', 'Status umgeschaltet.');
        self::redirect();
    }

    private static function all(): array
    {
        $value = get_option(self::OPTION_KEY, []);
        return is_array($value) ? $value : [];
    }

    private static function flash(string $type, string $message): void
    {
        set_transient('newss_channel_notice', ['type' => $type, 'message' => $message], 30);
    }

    private static function redirect(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=newss-channels'));
        exit;
    }
}
