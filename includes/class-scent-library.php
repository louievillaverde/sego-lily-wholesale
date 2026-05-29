<?php
/**
 * Scent Library
 *
 * Hardcoded source-of-truth scent descriptions for the order form +
 * Price List hover popups. Holly's master scent library page on
 * segolilyskincare.com is the canonical reference; this mirror exists
 * so the wholesale order form is never blank when WordPress taxonomy
 * descriptions, attribute term meta, and variation meta are all
 * missing (which is the normal state -- nobody fills those in).
 *
 * Order: lookup by scent name (case-insensitive, diacritic-stripped)
 * against the keys here. If found, return; otherwise fall back to
 * the legacy taxonomy/meta chain in templates/order-form.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Scent_Library {

    /**
     * Scents Holly actually carries. Sourced from the master scent library
     * page on segolilyskincare.com. If a variation label hits one of these
     * keys (case + diacritic + punctuation normalized), the hover popup on
     * the order form uses this description. If not, the fallback chain
     * (variation description, taxonomy term meta, etc.) runs.
     *
     * To add a scent: append a new entry below using the lowercase,
     * accent-stripped key (e.g. "honey crème" -> "honey creme").
     */
    private static $scents = array(
        'honey creme' => array(
            'intensity'   => 'Light',
            'description' => 'Subtle sweetness of raw honeycomb and oat milk. Gentle, sweet, comforting.',
        ),
        'mandarin orange' => array(
            'intensity'   => 'Medium',
            'description' => 'Fresh peeled citrus zest. Sweet and tangy. Uplifting, fresh, citrus.',
        ),
        'citrus breeze' => array(
            'intensity'   => 'Medium',
            'description' => 'Crisp, clean lemon and bergamot. Energizing, clean, invigorating, bright.',
        ),
        'cardamom primrose' => array(
            'intensity'   => 'Medium',
            'description' => 'Warming spice meets delicate floral notes. Warm, spicy, floral.',
        ),
        'rosewood lavender' => array(
            'intensity'   => 'Medium',
            'description' => 'Deep woody florals. Calming and grounding. Relaxing, woody, soothing.',
        ),
        'cherry' => array(
            'intensity'   => 'Medium',
            'description' => 'Tart and sweet stone fruit. Summery. Fruity, sweet, playful.',
        ),
        'mango' => array(
            'intensity'   => 'Medium',
            'description' => 'Juicy, ripe tropical fruit. Bright and cheerful. Tropical, juicy, vibrant.',
        ),
        'vanilla spice' => array(
            'intensity'   => 'Strong',
            'description' => 'Warm vanilla bean with hints of cinnamon. Cozy, rich, comforting.',
        ),
        'eucalyptus' => array(
            'intensity'   => 'Strong',
            'description' => 'Medicinal and cooling. Opens the sinuses. Therapeutic, crisp, minty.',
        ),
        'bourbon coffee' => array(
            'intensity'   => 'Very Strong',
            'description' => 'Rich roasted coffee with deep oak undertones. Bold, earthy, awakening.',
        ),
        'unscented' => array(
            'intensity'   => 'None',
            'description' => 'Pure rendered suet. No added fragrance. Pure, natural, safe for sensitive skin.',
        ),
        'cedarwood sage' => array(
            'intensity'   => 'Medium',
            'description' => 'Dry cedar wood with herbal sage. Woody, grounding, quietly ceremonial.',
        ),
        'coconut' => array(
            'intensity'   => 'Medium',
            'description' => 'Fresh-cracked coconut meat. Creamy, tropical, beach-vacation in a tin.',
        ),
        'huckleberry' => array(
            'intensity'   => 'Medium',
            'description' => 'Wild mountain berry. Tart, juicy, slightly woodsy. A taste of Big Sky country.',
        ),
    );

    /**
     * Look up a scent by its name. Normalizes the key to handle
     * "Honey Crème" vs "Honey Creme" vs "honey-creme" etc.
     *
     * @param string $name Scent name from the variation label or attribute term.
     * @return string|null Description, or null if not found.
     */
    public static function get_description( $name ) {
        $key = self::normalize_key( $name );
        if ( $key === '' ) return null;
        if ( isset( self::$scents[ $key ] ) ) {
            return self::$scents[ $key ]['description'];
        }
        // Allow partial / contains-match for labels like "Renewal Tallow Butter - Mango"
        foreach ( self::$scents as $scent_key => $entry ) {
            if ( strpos( $key, $scent_key ) !== false || strpos( $scent_key, $key ) !== false ) {
                return $entry['description'];
            }
        }
        return null;
    }

    /**
     * Normalize a free-text scent name into a library key:
     *   - Lowercased
     *   - Diacritics removed (Crème -> creme)
     *   - Punctuation stripped
     *   - Multiple spaces collapsed
     */
    public static function normalize_key( $name ) {
        if ( ! is_string( $name ) || $name === '' ) return '';
        // Strip diacritics: most reliable on WP via remove_accents.
        if ( function_exists( 'remove_accents' ) ) {
            $name = remove_accents( $name );
        }
        $name = strtolower( $name );
        // Strip everything that isn't a-z, 0-9, space or hyphen
        $name = preg_replace( '/[^a-z0-9\s-]/', ' ', $name );
        // Hyphens -> space
        $name = str_replace( '-', ' ', $name );
        // Collapse whitespace
        $name = trim( preg_replace( '/\s+/', ' ', $name ) );
        return $name;
    }

    /**
     * Full scent list for the master library page (future / template use).
     */
    public static function all() {
        return self::$scents;
    }
}
