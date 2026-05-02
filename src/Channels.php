<?php

declare(strict_types=1);

namespace Newss;

final class Channels
{
    private const PAGE_SLUG = 'newss-channels';
    private const OPTION_KEY = 'newss_channels';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu'], 11);
        add_action('admin_post_newss_channel_save', [self::class, 'handleSave']);
        add_action('admin_post_newss_channel_delete', [self::class, 'handleDelete']);
    }

    public static function addMenu(): void
    {
        add_submenu_page(
            'newss-settings',
            'Newss – Kanäle',
            'Kanäle',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $channels = self::all();
        ?>
        <div class="wrap">
            <h1>Newss – Kanäle</h1>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible"><p>Kanal gespeichert.</p></div>
            <?php elseif (isset($_GET['deleted'])): ?>
                <div class="notice notice-success is-dismissible"><p>Kanal gelöscht.</p></div>
            <?php elseif (isset($_GET['error'])): ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html((string) $_GET['error']); ?></p></div>
            <?php endif; ?>

            <h2>Hinzufügen / Bearbeiten</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="newss_channel_save">
                <?php wp_nonce_field('newss_channel_save'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ch_id">YouTube Channel-ID</label></th>
                        <td>
                            <input type="text" id="ch_id" name="id" class="regular-text" required pattern="UC[A-Za-z0-9_-]{22}" placeholder="UCxxxxxxxxxxxxxxxxxxxxxx">
                            <p class="description">Format <code>UC…</code> (24 Zeichen). Findest du auf der Kanalseite via „Teilen → Kanal-URL kopieren".</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ch_name">Anzeigename</label></th>
                        <td><input type="text" id="ch_name" name="name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ch_cat">Kategorie</label></th>
                        <td><?php wp_dropdown_categories([
                            'show_option_none'  => '— Default verwenden —',
                            'option_none_value' => 0,
                            'name'              => 'category',
                            'selected'          => 0,
                            'hide_empty'        => false,
                            'id'                => 'ch_cat',
                        ]); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Aktiv</th>
                        <td><label><input type="checkbox" name="enabled" value="1" checked> Beim nächsten Polling berücksichtigen</label></td>
                    </tr>
                </table>
                <?php submit_button('Kanal speichern'); ?>
            </form>

            <h2>Vorhandene Kanäle (<?php echo count($channels); ?>)</h2>
            <?php if (!$channels): ?>
                <p>Noch keine Kanäle konfiguriert.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr><th>Name</th><th>Channel-ID</th><th>Kategorie</th><th>Aktiv</th><th>Aktion</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($channels as $ch):
                        $catName = $ch['category'] ? get_cat_name((int) $ch['category']) : '— Default —';
                        $delUrl = wp_nonce_url(
                            admin_url('admin-post.php?action=newss_channel_delete&id=' . rawurlencode($ch['id'])),
                            'newss_channel_delete_' . $ch['id']
                        );
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html((string) $ch['name']); ?></strong></td>
                            <td><code><?php echo esc_html((string) $ch['id']); ?></code></td>
                            <td><?php echo esc_html((string) $catName); ?></td>
                            <td><?php echo !empty($ch['enabled']) ? '✔' : '—'; ?></td>
                            <td><a href="<?php echo esc_url($delUrl); ?>" class="button button-small" onclick="return confirm('Kanal wirklich löschen?')">Löschen</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handleSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('newss_channel_save');

        $id = sanitize_text_field((string) ($_POST['id'] ?? ''));
        if (!preg_match('/^UC[A-Za-z0-9_-]{22}$/', $id)) {
            self::redirect(['error' => 'Ungültige Channel-ID']);
            return;
        }
        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            self::redirect(['error' => 'Name fehlt']);
            return;
        }

        $entry = [
            'id'       => $id,
            'name'     => $name,
            'category' => (int) ($_POST['category'] ?? 0),
            'enabled'  => !empty($_POST['enabled']) ? 1 : 0,
        ];

        $channels = self::all();
        $found = false;
        foreach ($channels as &$ch) {
            if ($ch['id'] === $id) {
                $ch = $entry;
                $found = true;
                break;
            }
        }
        unset($ch);
        if (!$found) {
            $channels[] = $entry;
        }
        update_option(self::OPTION_KEY, $channels, false);

        self::redirect(['saved' => '1']);
    }

    public static function handleDelete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        $id = (string) ($_GET['id'] ?? '');
        check_admin_referer('newss_channel_delete_' . $id);

        $channels = array_values(array_filter(
            self::all(),
            static fn(array $ch): bool => ($ch['id'] ?? '') !== $id
        ));
        update_option(self::OPTION_KEY, $channels, false);

        self::redirect(['deleted' => '1']);
    }

    private static function all(): array
    {
        $value = get_option(self::OPTION_KEY, []);
        return is_array($value) ? $value : [];
    }

    private static function redirect(array $args): void
    {
        wp_safe_redirect(add_query_arg(
            $args,
            admin_url('admin.php?page=' . self::PAGE_SLUG)
        ));
        exit;
    }
}
