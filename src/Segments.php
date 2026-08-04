<?php
declare(strict_types=1);

namespace PayPalHK;

/**
 * Market / product / brand segmentation.
 *
 * Every payment, subscription and analytics record is stamped with the four
 * dimensions below so that data is kept cleanly separated from day one — by
 * country, by product/niche, and (as we add sibling brands and PayPal REST
 * apps) by brand. When the metrics dashboard is built later it can therefore
 * slice accurate numbers per market without any back-filling or guesswork.
 *
 * A "segment" bundles everything that differs between markets:
 *   - the analytics dimensions (country / product / brand)
 *   - the price points and currency for that market
 *   - the human label and the bank statement (soft) descriptor
 *
 * The first live segment targets the French market for vehicle history reports
 * (TheSmartLookup). New markets/niches are added by dropping another entry into
 * all() — e.g. uk-/au- vehicle-history-report, or a Globalrecharge brand that
 * runs against its own, fully isolated PayPal REST app.
 */
final class Segments
{
    /** The registry. Keyed by segment code (also stored on every record). */
    public static function all(): array
    {
        return [
            'fr-vehicle-history-report' => [
                'code'            => 'fr-vehicle-history-report',
                'label'           => 'FR Vehicle History Report',
                'country'         => 'FR',
                'product'         => 'vehicle-history-report',
                'brand'           => 'thesmartlookup',
                'currency'        => 'EUR',
                'trial_amount'    => '2.90',
                'monthly_amount'  => '49.50',
                'trial_hours'     => 48,
                // Shown on the customer's bank statement (<= 22 chars). Client
                // uses Thesmartlookup for all TheSmartLookup products (brand-level).
                'soft_descriptor' => 'Thesmartlookup',
            ],

            // --- Ready to switch on as we expand. Same website / brand /
            // descriptor (SMARTLOOKUP), one entry per product x country, each
            // with its own confirmed price. The data model already separates by
            // product, so metrics stay clean regardless of how many launch: ---
            // 'fr-people-lookup'         => [ 'product'=>'people-lookup',        'country'=>'FR', ... ],
            // 'fr-reverse-phone-lookup'  => [ 'product'=>'reverse-phone-lookup', 'country'=>'FR', ... ],
            // 'fr-ai-tools'              => [ 'product'=>'ai-tools',             'country'=>'FR', ... ],
            // 'uk-vehicle-history-report'=> [ 'country'=>'GB','currency'=>'GBP', ... ],
            // 'au-vehicle-history-report'=> [ 'country'=>'AU','currency'=>'AUD', ... ],
            //
            // A different brand (e.g. Globalrecharge) runs on its OWN PayPal REST
            // app / webhook / descriptor; give it its own config array + segments
            // so no transaction or metric can ever be mixed with TheSmartLookup.
        ];
    }

    /**
     * Resolve a segment by code, falling back to the configured default
     * (DEFAULT_SEGMENT env, else FR Vehicle History Report). An unknown code
     * never silently mis-tags data — it resolves to the default and the caller
     * can compare $seg['code'] to detect the fallback if it needs to.
     */
    public static function resolve(?string $code): array
    {
        $all     = self::all();
        $default = getenv('DEFAULT_SEGMENT') ?: 'fr-vehicle-history-report';
        if ($code !== null && $code !== '' && isset($all[$code])) {
            return $all[$code];
        }
        return $all[$default] ?? reset($all);
    }

    /** The analytics dimensions to stamp on every stored record. */
    public static function tag(array $seg): array
    {
        return [
            'segment' => $seg['code'],
            'country' => $seg['country'],
            'product' => $seg['product'],
            'brand'   => $seg['brand'],
        ];
    }

    /** Human label for a charge description, e.g. "FR Vehicle History Report". */
    public static function label(array $seg): string
    {
        return $seg['label'] ?? $seg['code'];
    }
}
