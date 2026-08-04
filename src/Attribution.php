<?php
declare(strict_types=1);

namespace PayPalHK;

/**
 * Acquisition attribution.
 *
 * Captures where a customer came from (Google, Meta, organic, referral…) at the
 * moment they subscribe, so the future dashboard can break LTV / retention /
 * cohorts / churn down by acquisition source alongside country and product.
 *
 * The checkout page should collect FIRST-TOUCH attribution — the UTM params /
 * click ids from the very first landing, persisted in a cookie or localStorage
 * — and send it as `attribution` on finalize / create-wallet-order. First-touch
 * is the right basis for acquisition-cost and LTV analysis (it credits the
 * channel that actually acquired the customer, not the last click before pay).
 *
 * We store the full normalised object AND flat `acq_source` / `acq_channel` /
 * `acq_medium` fields on the record so the metrics helpers can group by them
 * directly.
 */
final class Attribution
{
    /** Normalise raw attribution input into a stable, storable shape. */
    public static function fromInput(array $in): array
    {
        $a = is_array($in['attribution'] ?? null) ? $in['attribution'] : [];

        $clean = static function ($v): ?string {
            if (!is_string($v)) return null;
            $v = trim($v);
            return $v === '' ? null : substr($v, 0, 255);
        };

        $source   = $clean($a['source']   ?? $a['utm_source']   ?? null);
        $medium   = $clean($a['medium']   ?? $a['utm_medium']   ?? null);
        $campaign = $clean($a['campaign'] ?? $a['utm_campaign'] ?? null);
        $content  = $clean($a['content']  ?? $a['utm_content']  ?? null);
        $term     = $clean($a['term']     ?? $a['utm_term']     ?? null);
        $gclid    = $clean($a['gclid']    ?? null);
        $fbclid   = $clean($a['fbclid']   ?? null);
        $referrer = $clean($a['referrer'] ?? null);
        $landing  = $clean($a['landing_page'] ?? $a['landing'] ?? null);

        $channel = self::channel($source, $gclid, $fbclid, $referrer);

        return [
            'source'       => $source,
            'medium'       => $medium,
            'campaign'     => $campaign,
            'content'      => $content,
            'term'         => $term,
            'channel'      => $channel,          // coarse bucket for grouping
            'gclid'        => $gclid,
            'fbclid'       => $fbclid,
            'referrer'     => $referrer,
            'landing_page' => $landing,
        ];
    }

    /**
     * Coarse acquisition channel bucket (Google / Meta / Microsoft / TikTok /
     * referral / organic / direct). Kept deliberately simple; the raw source +
     * medium are stored too so the dashboard can refine paid-vs-organic splits.
     */
    public static function channel(?string $source, ?string $gclid, ?string $fbclid, ?string $referrer): string
    {
        $s = strtolower((string) $source);
        if ($gclid || in_array($s, ['google', 'adwords', 'google_ads', 'googleads'], true)) return 'google';
        if ($fbclid || in_array($s, ['facebook', 'fb', 'instagram', 'ig', 'meta'], true))   return 'meta';
        if (in_array($s, ['bing', 'microsoft', 'msn'], true))                                 return 'microsoft';
        if (in_array($s, ['tiktok', 'tt'], true))                                             return 'tiktok';
        if ($s !== '') return $s;                        // any other tagged source
        if ($referrer) {
            $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
            if ($host === '') return 'referral';
            if (strpos($host, 'google.') !== false)   return 'google';
            if (strpos($host, 'facebook.') !== false || strpos($host, 'instagram.') !== false) return 'meta';
            if (strpos($host, 'bing.') !== false)     return 'microsoft';
            return 'referral';
        }
        return 'direct';
    }
}
