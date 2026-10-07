<?php
/**
 * لُقيا AI — Deployment credentials template
 * =========================================
 *
 * HOW TO USE (free hosting that has no .env support, e.g. 000webhost / InfinityFree):
 *   1. Copy this file to  config/config.local.php
 *   2. Fill in the real values below
 *   3. Upload ONLY config/config.local.php to the server
 *   4. DO NOT upload .env  (it stays local-only and is git-ignored)
 *
 * config/config.local.php is git-ignored AND blocked by .htaccess, so it can
 * never leak through version control or through a direct web request.
 *
 * ── RESOLUTION ORDER (first source that provides a value wins) ──
 *   1. Real server environment variables  (set in the hosting control panel)
 *   2. The local `.env` file               (local development only)
 *   3. THIS file, config/config.local.php (deployment path)
 *
 * Because order 1 and 2 use putenv(), values already present are never
 * overwritten by this file. Keep the keys below stable across both systems.
 *
 * ── NEVER COMMIT THE REAL config.local.php ──
 * Only this `config.example.php` template belongs in the repository.
 */

declare(strict_types=1);

return [

    // ─── Database ───────────────────────────────────────────────
    // Free hosting almost never gives you a database named `luqya_ai`.
    // It is usually prefixed, e.g. `epiz_25129345_luqya_ai`.
    // Copy the EXACT name shown in your hosting control panel (phpMyAdmin).
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_USER' => 'YOUR_DATABASE_USER',
    'DB_PASS' => 'YOUR_DATABASE_PASSWORD',
    'DB_NAME' => 'YOUR_DATABASE_NAME',

    // ─── Gemini API ─────────────────────────────────────────────
    // Leave GEMINI_API_KEY empty ('') to run fully offline.
    // Every AI feature (semantic matching, vision OCR, smart search, AI chat)
    // then degrades gracefully to the local engine instead of erroring.
    'GEMINI_API_KEY' => '',
    'GEMINI_MODEL'   => 'gemini-3-flash-preview',

    // ─── AI semantic matching ───────────────────────────────────
    // 1 = let Gemini re-rank the local shortlist, 0 = local engine only.
    // Set to 0 if you have no API key or no billing enabled, otherwise the
    // first page load each minute may hit a 429 and fall back automatically.
    'AI_MATCHING_ENABLED' => '1',

    // ─── Quick demo login panel (login.php) ─────────────────────
    // 1 = render the panel, 0 = hide it.
    //
    // SECURITY: this flag only controls WHETHER THE PANEL IS RENDERED. The
    // button fills the two form fields and the form is submitted normally to
    // api/auth.php?action=login, so CSRF validation, rate limiting
    // (10 attempts / 5 min / IP) and password_verify() all still apply.
    // There is no authentication bypass anywhere in this feature.
    //
    // The demo passwords are NOT hardcoded in any PHP or JS file. They are read
    // from this array only, so you control them here and can delete them
    // without touching code. Leave empty to hide the panel entirely.
    'LUQYA_DEMO_LOGIN' => '0',
    'LUQYA_DEMO_ACCOUNTS' => [
        // [
        //     'label'          => 'Security officer — full dashboard',
        //     'university_id'  => 'SEC-USR-1',
        //     'password'       => 'choose-a-password',
        // ],
        // [
        //     'label'          => 'Student — report a lost item',
        //     'university_id'  => 'S202403400',
        //     'password'       => 'choose-a-password',
        // ],
    ],

    // ─── Password recovery ──────────────────────────────────────
    // There is no mailer in this build, so a password reset is delivered by
    // writing the raw token into the PHP error log.
    //
    // LEAVE THIS AT 0 ON ANY REAL HOSTING. With it enabled, anyone who can
    // read the error log (or trigger an error) obtains a token that grants a
    // full password change for 30 minutes.
    'LUQYA_EXPOSE_RESET_TOKEN' => '0',

    // ─── Maintenance ────────────────────────────────────────────
    // Set to 0 in production. While 1, setup_db.php is allowed to re-seed an
    // existing database over the web.
    'ALLOW_DB_RESET' => '0',
];