<?php

declare(strict_types=1);

namespace Newss;

final class Updater
{
    public static function register(): void
    {
        add_action('admin_post_newss_git_update', [self::class, 'handleUpdate']);
    }

    public static function renderSection(): void
    {
        $info   = self::repoInfo();
        $notice = get_transient('newss_update_notice');
        if ($notice) {
            delete_transient('newss_update_notice');
        }
        ?>
        <h2>Plugin-Update</h2>

        <?php if (is_array($notice)): ?>
            <div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible">
                <p><strong><?php echo esc_html((string) $notice['message']); ?></strong></p>
                <?php if (!empty($notice['output'])): ?>
                    <details><summary>Log anzeigen</summary>
                    <pre style="max-height:280px;overflow:auto;background:#1d1f21;color:#c5c8c6;padding:12px;font-size:11px;line-height:1.4"><?php echo esc_html((string) $notice['output']); ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($info === null): ?>
            <p style="background:#fff8e1;border-left:4px solid #ffb900;padding:8px 12px;max-width:780px">
                Plugin-Verzeichnis ist kein Git-Repository (oder <code>shell_exec</code> deaktiviert).
                Update via Button funktioniert nur, wenn das Plugin per <code>git clone</code> installiert wurde.
            </p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:880px">
                <tbody>
                    <tr><th style="width:160px">Branch</th><td><code><?php echo esc_html((string) $info['branch']); ?></code></td></tr>
                    <tr><th>Aktueller Stand</th><td><code><?php echo esc_html((string) $info['hash_short']); ?></code> &middot; <?php echo esc_html((string) $info['commit_msg']); ?></td></tr>
                    <tr><th>Commit-Datum</th><td><?php echo esc_html((string) $info['commit_date']); ?></td></tr>
                    <tr><th>Remote</th><td><code><?php echo esc_html((string) $info['remote']); ?></code></td></tr>
                    <tr><th>Lokale Änderungen</th><td><?php echo $info['dirty']
                            ? '<span style="color:#c00">⚠ uncommitted Änderungen vorhanden – werden beim Update verworfen</span>'
                            : '<span style="color:#0a7">sauber</span>'; ?></td></tr>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px">
                <input type="hidden" name="action" value="newss_git_update">
                <?php wp_nonce_field('newss_git_update'); ?>
                <button type="submit" class="button button-primary"
                        onclick="return confirm('Wirklich auf den neuesten Stand aktualisieren?\nLokale Änderungen am Plugin werden überschrieben.')">
                    Aus Git aktualisieren
                </button>
                <p class="description">
                    Führt <code>git fetch &amp;&amp; git reset --hard origin/<?php echo esc_html((string) $info['branch']); ?></code> aus.
                    Wenn sich <code>composer.json</code> oder <code>composer.lock</code> geändert haben, läuft anschließend <code>composer install --no-dev -o</code>.
                    OPcache wird zurückgesetzt.
                </p>
            </form>
        <?php endif; ?>
        <hr style="margin:32px 0">
        <?php
    }

    public static function handleUpdate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('newss_git_update');

        if (!function_exists('shell_exec')) {
            self::flash('error', 'shell_exec ist deaktiviert.');
            self::redirect();
        }
        if (!is_dir(NEWSS_DIR . '/.git')) {
            self::flash('error', 'Plugin-Verzeichnis ist kein Git-Repository.');
            self::redirect();
        }

        @set_time_limit(180);

        $log = '';

        $branch = self::git('rev-parse --abbrev-ref HEAD');
        if ($branch === '' || $branch === 'HEAD') {
            self::flash('error', 'Konnte den aktuellen Branch nicht ermitteln.');
            self::redirect();
        }

        $hashBefore = self::git('rev-parse HEAD');
        $log .= "BEFORE: {$hashBefore} ({$branch})\n\n";

        $log .= "$ git fetch --prune\n";
        $log .= self::run('git -C %s fetch --prune', NEWSS_DIR) . "\n";

        $log .= "\n$ git reset --hard origin/{$branch}\n";
        $log .= self::run('git -C %s reset --hard origin/%s', NEWSS_DIR, $branch) . "\n";

        $hashAfter = self::git('rev-parse HEAD');
        $log .= "\nAFTER:  {$hashAfter}\n";

        if ($hashBefore === $hashAfter) {
            self::flash('info', 'Bereits auf dem neuesten Stand (' . substr($hashAfter, 0, 8) . ').', $log);
            self::redirect();
        }

        $changed = self::git(sprintf(
            'diff --name-only %s %s',
            escapeshellarg($hashBefore),
            escapeshellarg($hashAfter)
        ));
        $log .= "\nGeänderte Dateien:\n" . $changed . "\n";

        if (preg_match('/^composer\.(json|lock)$/m', $changed)) {
            $composerBin = self::findComposer();
            if ($composerBin === null) {
                $log .= "\n[!] composer nicht gefunden – Dependencies könnten veraltet sein. Bitte manuell ausführen.\n";
            } else {
                $log .= "\n$ {$composerBin} install --no-dev --optimize-autoloader\n";
                $log .= self::run(
                    'cd %s && %s install --no-dev --optimize-autoloader 2>&1',
                    NEWSS_DIR,
                    $composerBin
                ) . "\n";
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
            $log .= "\nOPcache reset.\n";
        }

        self::flash(
            'success',
            sprintf('Update %s → %s erfolgreich.', substr($hashBefore, 0, 8), substr($hashAfter, 0, 8)),
            $log
        );
        self::redirect();
    }

    private static function repoInfo(): ?array
    {
        if (!function_exists('shell_exec') || !is_dir(NEWSS_DIR . '/.git')) {
            return null;
        }
        $branch = self::git('rev-parse --abbrev-ref HEAD');
        if ($branch === '') {
            return null;
        }
        $dirty = self::git('status --porcelain');
        return [
            'branch'      => $branch,
            'hash'        => self::git('rev-parse HEAD'),
            'hash_short'  => self::git('rev-parse --short HEAD'),
            'commit_msg'  => self::git('log -1 --pretty=%s'),
            'commit_date' => self::git('log -1 --pretty=%cd --date=format:%Y-%m-%d\\ %H:%M'),
            'remote'      => self::git('config --get remote.origin.url'),
            'dirty'       => $dirty !== '',
        ];
    }

    private static function git(string $args): string
    {
        return trim((string) @shell_exec(sprintf(
            'git -C %s %s 2>&1',
            escapeshellarg(NEWSS_DIR),
            $args
        )));
    }

    private static function run(string $template, string ...$args): string
    {
        $escaped = array_map('escapeshellarg', $args);
        $cmd = vsprintf($template, $escaped);
        return trim((string) @shell_exec($cmd . ' 2>&1'));
    }

    private static function findComposer(): ?string
    {
        foreach (['composer', '/usr/local/bin/composer', '/usr/bin/composer'] as $candidate) {
            $check = trim((string) @shell_exec(
                sprintf('command -v %s 2>/dev/null', escapeshellarg($candidate))
            ));
            if ($check !== '') {
                return $candidate;
            }
        }
        return null;
    }

    private static function flash(string $type, string $message, string $output = ''): void
    {
        set_transient('newss_update_notice', [
            'type'    => $type,
            'message' => $message,
            'output'  => $output,
        ], 60);
    }

    private static function redirect(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=newss-settings'));
        exit;
    }
}
