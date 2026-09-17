<?php

declare(strict_types=1);

namespace Perimetre\WpTools\RemoteLogin;

/**
 * Registers this site with the Helm portal using a portal-wide **enrollment
 * key**, and stores the per-site API key the portal hands back.
 *
 * This is the short path, and the only one an admin should normally need: copy
 * one key out of the portal, paste it here, save. The site describes itself —
 * `home_url()` and `blogname` — so there is nothing to create in the portal
 * beforehand and no per-site secret to copy.
 *
 * Pasting the same enrollment key on a site the portal already knows (matched
 * on `home_url()`) **re-registers** it: same record, fresh API key. That is the
 * recovery path for a site that has lost its credentials — plugin reinstalled,
 * database restored from backup, settings wiped — and it is why the enrollment
 * key box stays available after the first connection rather than disappearing.
 *
 * `Connect` is unchanged and still runs for a site that already holds an API
 * key; enrollment is additive.
 */
final class Enroll
{
    /**
     * Posts the enrollment key plus this site's own identity to the portal.
     *
     * @param string $enrollment_key The key copied from Helm's Portal settings.
     *
     * @return array{code: string, message: string} `code` is one of
     *         'enrolled', 'reconnected' or 'enroll_failed'.
     */
    public static function do_enroll(string $enrollment_key): array
    {
        $portal_url = Settings::get_portal_url();
        if ($portal_url === '' || $enrollment_key === '') {
            return [
                'code'    => 'enroll_failed',
                'message' => __('No enrollment key was provided.', 'perimetre-wp-tools'),
            ];
        }

        $body = wp_json_encode([
            'siteUrl'  => home_url('/'),
            'siteName' => get_bloginfo('name'),
        ]);
        if (! is_string($body)) {
            return [
                'code'    => 'enroll_failed',
                'message' => __('Could not build the request.', 'perimetre-wp-tools'),
            ];
        }

        $response = wp_remote_post(
            $portal_url . '/api/sites/enroll',
            [
                'method'      => 'POST',
                'headers'     => [
                    'Authorization' => 'Bearer ' . $enrollment_key,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body'        => $body,
                'timeout'     => 15,
                'redirection' => 0,
                'blocking'    => true,
            ]
        );

        if (is_wp_error($response)) {
            // The portal URL and the key are never logged; the message is the
            // transport error only.
            error_log('[perimetre-wp-tools/remote-login] enroll failed: ' . $response->get_error_message());
            return [
                'code'    => 'enroll_failed',
                'message' => __('Could not reach the Helm portal.', 'perimetre-wp-tools'),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($code !== 200) {
            // The portal writes these messages for the admin reading this
            // screen ("must use https", "not a WordPress site"), so they are
            // surfaced verbatim rather than remapped. NOTE the body of a
            // *successful* response carries the API key and must never be
            // logged; only this failure branch logs anything.
            $message = isset($decoded['message']) && is_string($decoded['message'])
                ? $decoded['message']
                : __('The portal rejected the enrollment key.', 'perimetre-wp-tools');
            error_log(sprintf('[perimetre-wp-tools/remote-login] enroll failed: HTTP %d', $code));
            return ['code' => 'enroll_failed', 'message' => $message];
        }

        $api_key = isset($decoded['apiKey']) && is_string($decoded['apiKey']) ? $decoded['apiKey'] : '';
        if ($api_key === '') {
            return [
                'code'    => 'enroll_failed',
                'message' => __('The portal did not return an API key.', 'perimetre-wp-tools'),
            ];
        }

        // This is the credential every later request uses — the Connect
        // handshake, the single-use token callback and the health endpoint all
        // authenticate with it, never with the enrollment key.
        update_option(Settings::OPTION_API_KEY, $api_key);
        Settings::mark_connected();

        $re_registered = ! empty($decoded['reRegistered']);
        $site_name = isset($decoded['siteName']) && is_string($decoded['siteName'])
            ? $decoded['siteName']
            : get_bloginfo('name');

        return [
            'code'    => $re_registered ? 'reconnected' : 'enrolled',
            'message' => $site_name,
        ];
    }
}
